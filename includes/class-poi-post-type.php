<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * PoiPostType - registers the `poi` custom post type and its meta fields.
 *
 * The new plugin owns this post type so sites adopting Innsight can manage POIs
 * directly (rather than via the legacy `point_of_interest` taxonomy on `post`).
 * Both data sources continue to flow into the v1 JSON the engine consumes;
 * existing yuna-innsight installs do not need to migrate.
 *
 * Meta keys are deliberately un-prefixed (lat / lon / fclass / mapcategory /
 * etc.) so they match the CSV column names used by typical OpenStreetMap
 * exports and the WP REST endpoint that ACF / Pods generate. Each meta is
 * registered with show_in_rest so the post type round-trips cleanly through
 * the WP REST API too.
 */
final class PoiPostType {

    public const POST_TYPE = 'poi';

    /**
     * Map of meta_key => REST type. Read at registration time; the importer
     * uses this list to know which fields exist.
     */
    public const META = array(
        'lat'                    => 'number',
        'lon'                    => 'number',
        'fclass'                 => 'string',   // "bar", "cafe", "restaurant" etc - matches OSM source.
        'mapcategory'            => 'string',   // "bars_and_pubs", "cafes" etc - source dataset's category.
        'mapcategory_normalized' => 'string',   // "drinks" / "eats" / "sights" / "shops" / "events" - design's 5 buckets.
        'description_de'         => 'string',
        'description_en'         => 'string',
        'website'                => 'string',
        'website2'               => 'string',
        'maps_url'               => 'string',
        'osm_id'                 => 'string',   // String not int - OSM ids overflow PHP ints on 32-bit.
        'osm_code'               => 'integer',
    );

    /**
     * Default mapping from a CSV's `mapcategory` value to the 5-bucket
     * normalized category id used by the innsight2026 skin's chip filter.
     */
    public const DEFAULT_NORMALIZED_CATEGORY_MAP = array(
        'bars_and_pubs'            => 'drinks',
        'cafes'                    => 'drinks',
        'german_restaurants'       => 'eats',
        'international_restaurants'=> 'eats',
        'restaurants'              => 'eats',
        'nightlife'                => 'events',
        'seasonal'                 => 'drinks',
        'stores'                   => 'shops',
        'shops'                    => 'shops',
        'activities'               => 'sights',
        'sights'                   => 'sights',
        'parking'                  => '',       // intentionally excluded from the filter chips.
        'miscellaneous'            => '',
    );

    public function register(): void {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_meta' ) );
    }

    public function register_post_type(): void {
        register_post_type( self::POST_TYPE, array(
            'public'             => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'rest_base'          => 'pois',
            'has_archive'        => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-location',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions' ),
            'rewrite'            => array( 'slug' => 'poi' ),
            'capability_type'    => 'post',
            'labels'             => array(
                'name'               => __( 'Points of Interest', 'innsight' ),
                'singular_name'      => __( 'POI', 'innsight' ),
                'menu_name'          => __( 'POIs', 'innsight' ),
                'add_new'            => __( 'Add POI', 'innsight' ),
                'add_new_item'       => __( 'Add new POI', 'innsight' ),
                'edit_item'          => __( 'Edit POI', 'innsight' ),
                'new_item'           => __( 'New POI', 'innsight' ),
                'view_item'          => __( 'View POI', 'innsight' ),
                'search_items'       => __( 'Search POIs', 'innsight' ),
                'not_found'          => __( 'No POIs found', 'innsight' ),
                'not_found_in_trash' => __( 'No POIs found in trash', 'innsight' ),
            ),
        ) );
    }

    public function register_meta(): void {
        foreach ( self::META as $key => $type ) {
            register_post_meta( self::POST_TYPE, $key, array(
                'type'              => $type,
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => $this->sanitizer_for( $type ),
                'auth_callback'     => static function () { return current_user_can( 'edit_posts' ); },
            ) );
        }
    }

    private function sanitizer_for( string $type ) {
        switch ( $type ) {
            case 'number':  return static function ( $v ) { return is_numeric( $v ) ? (float) $v : 0.0; };
            case 'integer': return static function ( $v ) { return (int) $v; };
            default:        return 'sanitize_text_field';
        }
    }

    /**
     * Normalize a source mapcategory string to one of the 5 design buckets.
     * Filterable so each install can override.
     */
    public static function normalize_category( string $source ): string {
        $map = (array) apply_filters( 'innsight/poi/category_map', self::DEFAULT_NORMALIZED_CATEGORY_MAP );
        $source = strtolower( trim( $source ) );
        return isset( $map[ $source ] ) ? (string) $map[ $source ] : '';
    }
}
