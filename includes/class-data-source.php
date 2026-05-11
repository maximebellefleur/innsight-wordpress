<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * DataSource - the brains. Reads the host site's existing DB structures (POI
 * taxonomy, portfolio activities, events, ACF options) and produces a stable
 * intermediate shape that the JsonBuilder turns into v1 JSON.
 *
 * The intermediate shape mirrors the existing yuna-innsight `$leaflet_markers`
 * array so the porting risk is minimal:
 *
 *   [
 *     'center' => [ 'lat' => float, 'lon' => float ],
 *     'zoom'   => int,
 *     'pois'   => [
 *        [ 'id', 'title', 'lat', 'lon', 'description', 'type', 'category',
 *          'image', 'button' => ['url','text'], 'pinned', 'googlePlaceId' ],
 *        ...
 *     ],
 *     'paths'  => [
 *        [ 'id', 'title', 'color', 'coordinates' => [[lat,lon], ...] ],
 *        ...
 *     ],
 *     'branding' => [ 'logoUrl' => string ],
 *   ]
 *
 * Viewmodes mirror the legacy plugin (event / single / act / multi). Each
 * branch is a small private method so the dispatcher in build() stays linear.
 */
final class DataSource {

    /** @var Translator */
    private $translator;
    /** @var Geocoder */
    private $geocoder;

    public function __construct( Translator $translator, Geocoder $geocoder ) {
        $this->translator = $translator;
        $this->geocoder   = $geocoder;
    }

    /**
     * @param array $args {
     *   @type int    $post_id        Required. Source post for ACF fields.
     *   @type string $location       Place-name string to geocode (overrides ACF).
     *   @type int    $zoom           Zoom override (overrides ACF).
     *   @type string $viewmode       'event' | 'single' | 'act' | 'multi' | 'blogs'.
     *   @type string $taxonomy_slug  Optional taxonomy filter for multi viewmode.
     *   @type string $taxonomy_id    Optional term filter for multi viewmode.
     * }
     * @return array
     */
    public function build( array $args ): array {
        $post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
        $viewmode = isset( $args['viewmode'] ) ? sanitize_key( (string) $args['viewmode'] ) : 'multi';

        $center = $this->resolve_center( $args );
        $zoom   = $this->resolve_zoom( $args, $post_id );

        $pois  = array();
        $paths = array();

        switch ( $viewmode ) {
            case 'event':
                $pois = $this->collect_event_marker( $post_id );
                break;
            case 'single':
                list( $pois, $paths ) = $this->collect_single_post( $post_id, $args );
                break;
            case 'act':
                $pois = $this->collect_activities_excluding_current( $post_id );
                break;
            case 'multi':
            case 'blogs':
            default:
                $pois = $this->collect_all_pois( $args );
                break;
        }

        // Always append the default hostel marker last - matches the legacy plugin's behavior.
        $hostel = $this->collect_default_hostel();
        if ( $hostel !== null ) {
            $pois[] = $hostel;
        }

        $pois = $this->dedupe_pois( $pois );

        $branding = array(
            'logoUrl' => $this->resolve_logo_url(),
        );

        $result = array(
            'center'   => $center,
            'zoom'     => $zoom,
            'pois'     => $pois,
            'paths'    => $paths,
            'branding' => $branding,
            'viewmode' => $viewmode,
            'post_id'  => $post_id,
        );

        /**
         * Filter the intermediate data shape before it is passed to the JsonBuilder.
         * Use this to inject custom POIs, dedupe further, or override branding.
         *
         * @param array $result   Intermediate shape.
         * @param array $args     Original arguments.
         */
        return (array) apply_filters( 'innsight/data/intermediate', $result, $args );
    }

    private function resolve_center( array $args ): array {
        $location = isset( $args['location'] ) ? trim( (string) $args['location'] ) : '';
        if ( $location === '' ) {
            $location = (string) innsight_get_field( 'map_base_location', isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );
        }
        if ( $location !== '' ) {
            $hit = $this->geocoder->locate( $location );
            if ( $hit !== null ) {
                return array( 'lat' => $hit['lat'], 'lon' => $hit['lon'] );
            }
        }

        $opt_lat = innsight_to_float( innsight_get_field( 'maps_latitude', 'option' ) );
        $opt_lon = innsight_to_float( innsight_get_field( 'maps_longitude', 'option' ) );
        if ( $opt_lat !== null && $opt_lon !== null ) {
            return array( 'lat' => $opt_lat, 'lon' => $opt_lon );
        }

        // Final fallback: world view.
        return array( 'lat' => 0.0, 'lon' => 0.0 );
    }

    private function resolve_zoom( array $args, int $post_id ): int {
        if ( isset( $args['zoom'] ) && is_numeric( $args['zoom'] ) ) {
            return max( 1, min( 20, (int) $args['zoom'] ) );
        }
        $field = innsight_get_field( 'map_zoom_level', $post_id );
        if ( is_numeric( $field ) ) {
            return max( 1, min( 20, (int) $field ) );
        }
        return 12;
    }

    private function resolve_logo_url(): string {
        $field = innsight_get_field( 'maps_bg_img', 'option' );
        $url   = innsight_attachment_url( $field, 'medium' );
        if ( $url !== '' ) {
            return $url;
        }
        return (string) get_site_icon_url( 512 );
    }

    /* ------------------------------------------------------------------ *
     *   Default hostel marker (always appended)                          *
     * ------------------------------------------------------------------ */

    private function collect_default_hostel(): ?array {
        $lat = innsight_to_float( innsight_get_field( 'maps_latitude', 'option' ) );
        $lon = innsight_to_float( innsight_get_field( 'maps_longitude', 'option' ) );
        if ( $lat === null || $lon === null ) {
            return null;
        }
        $maps_link = innsight_link_field( innsight_get_field( 'maps_more_info_url', 'option' ) );
        $title     = (string) innsight_get_field( 'maps_titre', 'option', get_bloginfo( 'name' ) );
        $desc_html = innsight_strip_paragraph_tags( (string) innsight_get_field( 'maps_text', 'option' ) );
        $image     = innsight_attachment_url( innsight_get_field( 'maps_bg_img', 'option' ), 'medium' );

        return array(
            'id'          => 'default-hostel',
            'title'       => $this->translator->text( $title ),
            'lat'         => $lat,
            'lon'         => $lon,
            'description' => $this->translator->html( $desc_html ),
            'type'        => 'hostel',
            'category'    => '',
            'icon'        => 'fa-hostel',
            'image'       => $image,
            'button'      => array(
                'url'  => $this->translator->url( $maps_link['url'] ),
                'text' => $this->translator->text( $maps_link['text'] !== '' ? $maps_link['text'] : __( 'More info', 'innsight' ) ),
            ),
            'pinned'      => true,
        );
    }

    /* ------------------------------------------------------------------ *
     *   viewmode: event                                                  *
     * ------------------------------------------------------------------ */

    private function collect_event_marker( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return array();
        }
        $lat = innsight_to_float( get_post_meta( $post_id, 'latitude', true ) );
        $lon = innsight_to_float( get_post_meta( $post_id, 'longitude', true ) );
        if ( $lat === null || $lon === null ) {
            return array();
        }
        $desc = (string) get_post_meta( $post_id, 'single_event_gallery_description', true );
        return array(
            array(
                'id'          => 'event-' . $post_id,
                'title'       => $this->translator->text( get_the_title( $post_id ) ),
                'lat'         => $lat,
                'lon'         => $lon,
                'description' => $this->translator->html( wp_trim_words( $desc, 20 ) ),
                'type'        => 'event',
                'category'    => '',
                'icon'        => '',
                'image'       => (string) get_the_post_thumbnail_url( $post_id, 'large' ),
                'button'      => array(
                    'url'  => $this->translator->url( get_permalink( $post_id ) ),
                    'text' => $this->translator->text( __( 'More info', 'innsight' ) ),
                ),
                'pinned'      => false,
            ),
        );
    }

    /* ------------------------------------------------------------------ *
     *   viewmode: single (POI terms + activity refs + paths on a post)   *
     * ------------------------------------------------------------------ */

    private function collect_single_post( int $post_id, array $args ): array {
        if ( $post_id <= 0 ) {
            return array( array(), array() );
        }
        $pois  = array();
        $paths = array();

        if ( ! empty( innsight_get_field( 'maps_add_markers', $post_id ) ) ) {
            $terms = get_the_terms( $post_id, 'point_of_interest' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    $marker = $this->poi_term_to_marker( $term );
                    if ( $marker !== null ) {
                        $pois[] = $marker;
                    }
                }
            }
            $activity_ids = innsight_get_field( 'maps_existing_act_marker_id', $post_id );
            if ( is_array( $activity_ids ) ) {
                foreach ( $activity_ids as $activity ) {
                    $activity_id = is_object( $activity ) && isset( $activity->ID ) ? (int) $activity->ID : (int) $activity;
                    $marker      = $this->portfolio_post_to_marker( $activity_id );
                    if ( $marker !== null ) {
                        $pois[] = $marker;
                    }
                }
            }
        }

        if ( ! empty( innsight_get_field( 'maps_add_paths', $post_id ) ) ) {
            $rows = innsight_get_field( 'maps_paths_box', $post_id );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $idx => $row ) {
                    $path = $this->path_row_to_path( $row, $post_id, (int) $idx );
                    if ( $path !== null ) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return array( $pois, $paths );
    }

    /* ------------------------------------------------------------------ *
     *   viewmode: act (all activities; current post becomes a "current") *
     * ------------------------------------------------------------------ */

    private function collect_activities_excluding_current( int $post_id ): array {
        $pois = array();

        $portfolio_query = new \WP_Query( array(
            'post_type'      => 'portfolio',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'latitude', 'compare' => 'EXISTS' ),
                array( 'key' => 'longitude', 'compare' => 'EXISTS' ),
            ),
        ) );

        foreach ( $portfolio_query->posts as $post ) {
            if ( $post_id > 0 && (int) $post->ID === $post_id ) {
                continue;
            }
            $marker = $this->portfolio_post_to_marker( (int) $post->ID );
            if ( $marker !== null ) {
                $pois[] = $marker;
            }
        }

        if ( $post_id > 0 ) {
            $current = $this->portfolio_post_to_marker( $post_id, 'current' );
            if ( $current !== null ) {
                $pois[] = $current;
            }
        }

        return $pois;
    }

    /* ------------------------------------------------------------------ *
     *   viewmode: multi / blogs (all POIs + all activities + events)     *
     * ------------------------------------------------------------------ */

    private function collect_all_pois( array $args ): array {
        $pois = array();

        $tax_query = array();
        if ( ! empty( $args['taxonomy_slug'] ) && ! empty( $args['taxonomy_id'] ) ) {
            $tax_query[] = array(
                'taxonomy' => sanitize_key( (string) $args['taxonomy_slug'] ),
                'field'    => is_numeric( $args['taxonomy_id'] ) ? 'term_id' : 'slug',
                'terms'    => is_numeric( $args['taxonomy_id'] ) ? (int) $args['taxonomy_id'] : sanitize_title( (string) $args['taxonomy_id'] ),
            );
        }

        $post_query = new \WP_Query( array(
            'post_type'      => 'post',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'tax_query'      => array_merge(
                array(
                    array(
                        'taxonomy' => 'point_of_interest',
                        'field'    => 'term_id',
                        'operator' => 'EXISTS',
                    ),
                ),
                $tax_query
            ),
        ) );

        foreach ( $post_query->posts as $post ) {
            $terms = get_the_terms( $post->ID, 'point_of_interest' );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $term ) {
                    $marker = $this->poi_term_to_marker( $term );
                    if ( $marker !== null ) {
                        $pois[] = $marker;
                    }
                }
            }
            // Events: include if post is in 'event' category and has event_poi flag.
            if ( has_category( 'event', $post ) && ! empty( get_post_meta( $post->ID, 'event_poi', true ) ) ) {
                $event_marker = $this->event_post_to_marker( (int) $post->ID );
                if ( $event_marker !== null ) {
                    $pois[] = $event_marker;
                }
            }
        }

        $portfolio_query = new \WP_Query( array(
            'post_type'      => 'portfolio',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'latitude', 'compare' => 'EXISTS' ),
                array( 'key' => 'longitude', 'compare' => 'EXISTS' ),
            ),
        ) );
        foreach ( $portfolio_query->posts as $post ) {
            $marker = $this->portfolio_post_to_marker( (int) $post->ID );
            if ( $marker !== null ) {
                $pois[] = $marker;
            }
        }

        // POIs from the new `poi` post type (created by the importer).
        if ( post_type_exists( PoiPostType::POST_TYPE ) ) {
            $poi_query = new \WP_Query( array(
                'post_type'      => PoiPostType::POST_TYPE,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    'relation' => 'AND',
                    array( 'key' => 'lat', 'compare' => 'EXISTS' ),
                    array( 'key' => 'lon', 'compare' => 'EXISTS' ),
                ),
            ) );
            foreach ( $poi_query->posts as $post ) {
                $marker = $this->poi_post_to_marker( (int) $post->ID );
                if ( $marker !== null ) {
                    $pois[] = $marker;
                }
            }
        }

        return $pois;
    }

    /* ------------------------------------------------------------------ *
     *   Per-source builders                                              *
     * ------------------------------------------------------------------ */

    /**
     * Convert a `poi` custom post (created via the importer) into a marker.
     * Picks the locale-appropriate description (English on en_* sites, German
     * on de_* sites, English elsewhere). The Translator facade is still
     * applied at the end so Transposh-style runtime translations can layer on
     * top.
     */
    private function poi_post_to_marker( int $post_id ): ?array {
        $lat = innsight_to_float( get_post_meta( $post_id, 'lat', true ) );
        $lon = innsight_to_float( get_post_meta( $post_id, 'lon', true ) );
        if ( $lat === null || $lon === null ) {
            return null;
        }
        $de = (string) get_post_meta( $post_id, 'description_de', true );
        $en = (string) get_post_meta( $post_id, 'description_en', true );
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        $description = strpos( (string) $locale, 'de' ) === 0 && $de !== '' ? $de : ( $en !== '' ? $en : $de );
        if ( $description === '' ) {
            $description = (string) get_post_field( 'post_content', $post_id );
        }
        $type = (string) get_post_meta( $post_id, 'fclass', true );
        $cat  = (string) get_post_meta( $post_id, 'mapcategory_normalized', true );
        $btn_url = (string) ( get_post_meta( $post_id, 'website', true )
                              ?: get_post_meta( $post_id, 'maps_url', true )
                              ?: get_permalink( $post_id ) );

        return array(
            'id'          => 'poi-post-' . $post_id,
            'title'       => $this->translator->text( get_the_title( $post_id ) ),
            'lat'         => $lat,
            'lon'         => $lon,
            'description' => $this->translator->html( $description ),
            'type'        => $type !== '' ? sanitize_key( $type ) : 'place',
            'category'    => '',
            'cat'         => $cat,
            'icon'        => '',
            'image'       => (string) get_the_post_thumbnail_url( $post_id, 'large' ),
            'button'      => array(
                'url'  => $this->translator->url( $btn_url ),
                'text' => $this->translator->text( __( 'More info', 'innsight' ) ),
            ),
            'pinned'      => false,
        );
    }

    /**
     * Convert a point_of_interest taxonomy term into a marker.
     */
    private function poi_term_to_marker( $term ): ?array {
        if ( ! is_object( $term ) || empty( $term->term_id ) ) {
            return null;
        }
        $term_id = (int) $term->term_id;
        $lat     = innsight_to_float( innsight_get_term_field( 'poi_latitude', $term_id ) );
        $lon     = innsight_to_float( innsight_get_term_field( 'poi_longitude', $term_id ) );
        if ( $lat === null || $lon === null ) {
            return null;
        }
        $type     = (string) innsight_get_term_field( 'poi_type', $term_id, 'place' );
        $category = (string) innsight_get_term_field( 'poi_category', $term_id );
        $image    = innsight_attachment_url( innsight_get_term_field( 'poi_image', $term_id ), 'medium' );

        $custom_link  = innsight_link_field( innsight_get_term_field( 'poi_url_link', $term_id ) );
        $main_link    = trim( (string) innsight_get_term_field( 'main_more_info_url', $term_id ) );
        $button_url   = $main_link !== '' ? $main_link : $custom_link['url'];
        $button_text  = $custom_link['text'] !== '' ? $custom_link['text'] : __( 'More info', 'innsight' );

        return array(
            'id'          => 'poi-' . $term_id,
            'title'       => $this->translator->text( (string) $term->name ),
            'lat'         => $lat,
            'lon'         => $lon,
            'description' => $this->translator->html( (string) $term->description ),
            'type'        => sanitize_key( $type ),
            'category'    => $category,
            'icon'        => '',
            'image'       => $image,
            'button'      => array(
                'url'  => $this->translator->url( $button_url ),
                'text' => $this->translator->text( $button_text ),
            ),
            'pinned'      => false,
        );
    }

    /**
     * Convert a portfolio (activity) post into a marker.
     */
    private function portfolio_post_to_marker( int $post_id, string $type = 'activities' ): ?array {
        if ( $post_id <= 0 ) {
            return null;
        }
        $lat = innsight_to_float( get_post_meta( $post_id, 'latitude', true ) );
        $lon = innsight_to_float( get_post_meta( $post_id, 'longitude', true ) );
        if ( $lat === null || $lon === null ) {
            return null;
        }
        $description = (string) get_post_meta( $post_id, 'activity_main_slogan', true );
        return array(
            'id'          => 'activity-' . $post_id,
            'title'       => $this->translator->text( get_the_title( $post_id ) ),
            'lat'         => $lat,
            'lon'         => $lon,
            'description' => $this->translator->html( $description ),
            'type'        => $type,
            'category'    => 'stabilization-action',
            'icon'        => '',
            'image'       => (string) get_the_post_thumbnail_url( $post_id, 'large' ),
            'button'      => array(
                'url'  => $this->translator->url( get_permalink( $post_id ) ),
                'text' => $this->translator->text( __( 'More info', 'innsight' ) ),
            ),
            'pinned'      => $type === 'current',
        );
    }

    /**
     * Convert an event post (post in `event` category with event_poi=true) into a marker.
     */
    private function event_post_to_marker( int $post_id ): ?array {
        if ( $post_id <= 0 ) {
            return null;
        }
        $lat = innsight_to_float( get_post_meta( $post_id, 'latitude', true ) );
        $lon = innsight_to_float( get_post_meta( $post_id, 'longitude', true ) );
        if ( $lat === null || $lon === null ) {
            return null;
        }
        $desc = (string) get_post_meta( $post_id, 'single_event_gallery_description', true );
        return array(
            'id'          => 'event-' . $post_id,
            'title'       => $this->translator->text( get_the_title( $post_id ) ),
            'lat'         => $lat,
            'lon'         => $lon,
            'description' => $this->translator->html( wp_trim_words( $desc, 20 ) ),
            'type'        => 'event',
            'category'    => '',
            'icon'        => '',
            'image'       => (string) get_the_post_thumbnail_url( $post_id, 'large' ),
            'button'      => array(
                'url'  => $this->translator->url( get_permalink( $post_id ) ),
                'text' => $this->translator->text( __( 'More info', 'innsight' ) ),
            ),
            'pinned'      => false,
        );
    }

    /**
     * Convert a maps_paths_box ACF repeater row into a path.
     *
     * Row shape (from the legacy plugin):
     *   maps_path_title:        string
     *   maps_path_color:        string (hex)
     *   maps_path_points_name:  string of //- separated locations (place names or lat,lon)
     */
    private function path_row_to_path( $row, int $post_id, int $idx ): ?array {
        if ( ! is_array( $row ) ) {
            return null;
        }
        $title  = isset( $row['maps_path_title'] ) ? (string) $row['maps_path_title'] : '';
        $color  = isset( $row['maps_path_color'] ) ? (string) $row['maps_path_color'] : '#3d3c3c';
        $points = isset( $row['maps_path_points_name'] ) ? (string) $row['maps_path_points_name'] : '';
        if ( $points === '' ) {
            return null;
        }
        // The legacy plugin used semicolons or "//-" as separators. Be permissive.
        $tokens = preg_split( '/\s*(?:\/\/-|;|\n)\s*/', $points, -1, PREG_SPLIT_NO_EMPTY );
        $coords = $this->geocoder->locate_many( is_array( $tokens ) ? $tokens : array() );
        if ( count( $coords ) < 2 ) {
            return null;
        }
        return array(
            'id'          => 'path-' . $post_id . '-' . $idx,
            'title'       => $this->translator->text( $title ),
            'color'       => $color,
            'coordinates' => $coords,
        );
    }

    /**
     * Deduplicate POIs by lat/lon/title (matches the legacy is_duplicate_marker logic).
     *
     * @param array<int,array> $pois
     * @return array<int,array>
     */
    private function dedupe_pois( array $pois ): array {
        $seen = array();
        $out  = array();
        foreach ( $pois as $poi ) {
            $key = sprintf( '%.6f|%.6f|%s', (float) $poi['lat'], (float) $poi['lon'], strtolower( (string) $poi['title'] ) );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $poi;
        }
        return $out;
    }
}
