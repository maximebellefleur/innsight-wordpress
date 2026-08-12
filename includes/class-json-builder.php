<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * JsonBuilder - turns the DataSource's intermediate shape into a v1 JSON config
 * matching the schema documented at innsight/docs/JSON-SCHEMA.md.
 *
 * This is a pure transformer: no DB reads, no side effects. All data comes from
 * the caller (DataSource) plus the persisted plugin Settings.
 */
final class JsonBuilder {

    /**
     * @param array $intermediate  Output of DataSource::build()
     * @param array $shortcode_atts Shortcode attributes (provider, skin overrides, height...)
     * @return array Encoded as JSON for window.INNSIGHT_DATA / REST response.
     */
    public function build( array $intermediate, array $shortcode_atts = array() ): array {
        $settings = innsight_settings();

        $requested_type = isset( $shortcode_atts['provider'] ) && in_array( $shortcode_atts['provider'], array( 'osm', 'mapbox', 'mapbox-gl', 'google' ), true )
            ? $shortcode_atts['provider']
            : (string) $settings['provider_default'];

        // Defensive: if the chosen provider would cause the engine to throw
        // (mapbox without a token, google before v0.2 ships), fall back to
        // OpenStreetMap so the page still renders. The admin sees the active
        // provider type in the JSON config and can investigate.
        $provider_type = $requested_type;
        if ( $requested_type === 'mapbox' && empty( $settings['mapbox_access_token'] ) ) {
            $provider_type = 'osm';
        }
        if ( $requested_type === 'mapbox-gl' && empty( $settings['mapbox_access_token'] ) ) {
            $provider_type = 'osm';
        }
        if ( $requested_type === 'google' ) {
            $provider_type = 'osm'; // v0.2 stub - engine throws today.
        }

        $skin_name = isset( $shortcode_atts['skin'] ) && $shortcode_atts['skin'] !== ''
            ? sanitize_key( (string) $shortcode_atts['skin'] )
            : (string) $settings['skin_name'];

        $skin_url = $this->resolve_skin_base_url( $skin_name );

        $config = array(
            'version'      => 1,
            // Plugin release version - the engine's checkVersion() compares
            // it to localStorage on every boot so a new release wipes any
            // stale transient cache (without touching saved POIs / notes).
            'pluginVersion' => defined( 'INNSIGHT_VERSION' ) ? INNSIGHT_VERSION : '0',
            'map'          => array(
                'center'       => array(
                    'lat' => (float) $intermediate['center']['lat'],
                    'lon' => (float) $intermediate['center']['lon'],
                ),
                'zoom'         => (int) $intermediate['zoom'],
                'minZoom'      => 2,
                'maxZoom'      => 19,
                'fitToBounds'  => true,
                'fullscreen'   => true,
            ),
            'provider'     => $this->build_provider_config( $provider_type, $settings ),
            'enrichment'   => $this->build_enrichment_config( $settings ),
            'skin'         => array(
                'name'     => $skin_name,
                'basePath' => $skin_url,
            ),
            'branding'     => array(
                'colors'  => array(
                    'rouge' => '#da011a',
                    'noir'  => '#3d3c3c',
                    'bleu'  => '#1a73e8',
                ),
                'logoUrl' => isset( $intermediate['branding']['logoUrl'] ) ? (string) $intermediate['branding']['logoUrl'] : '',
                // Client-name prefix (e.g. "Balmers"). Skin rewrites each
                // .in-wordmark element to "<prefix> → Innsight." when set.
                'wordmarkPrefix' => (string) ( $settings['wordmark_prefix'] ?? '' ),
                'base'    => $this->build_base_config( $settings ),
            ),
            'actionLinks'  => $this->default_action_links(),
            'categories'   => $this->build_categories( $skin_name, $intermediate['pois'] ),
            'filters'      => array(
                'types'    => $this->derive_types( $intermediate['pois'] ),
                'soloMode' => ! empty( $settings['solo_mode'] ),
            ),
            'ui'           => array(
                'kmlExport'    => ! empty( $settings['kml_export'] ),
                'layerControl' => array( 'position' => 'bottomright', 'collapsed' => false ),
                'liveLocation' => $this->build_live_location_config( $settings ),
                'share'        => $this->build_share_config( $settings ),
                // Analytics beacon URL. Empty string when disabled so
                // the skin's beacon() early-returns without a network
                // call. When enabled, the skin fires anonymous event
                // counts into the innsight_stats table (map_load,
                // poi_open, poi_save, etc).
                'analyticsUrl' => ! empty( $settings['analytics_enabled'] ?? 1 )
                    ? esc_url_raw( rest_url( 'innsight/v1/stat' ) )
                    : '',
                // Server-side Places endpoint. Empty when Places
                // enrichment is disabled (no API key) so the skin
                // simply doesn't try to fetch enrichment.
                'placesUrl'    => ( ! empty( $settings['google_places_enable'] ) && ! empty( $settings['google_places_key'] ) )
                    ? esc_url_raw( rest_url( 'innsight/v1/places' ) )
                    : '',
            ),
            'pois'         => array_map( array( $this, 'shape_poi' ), $intermediate['pois'] ),
            'paths'        => array_map( array( $this, 'shape_path' ), $intermediate['paths'] ),
        );

        /**
         * Filter the final v1 JSON config before it's emitted.
         *
         * @param array $config         Final config array.
         * @param array $intermediate   DataSource output.
         * @param array $shortcode_atts Shortcode attributes.
         */
        return (array) apply_filters( 'innsight/data/config', $config, $intermediate, $shortcode_atts );
    }

    private function build_provider_config( string $type, array $settings ): array {
        // The bundled innsight2026 Mapbox style lives at
        // /skins/innsight2026/mapbox-style.json. We expose its absolute URL so
        // the engine's MapboxGLProvider can load it without an extra config.
        $bundled_mapbox_style = INNSIGHT_URL . 'skins/innsight2026/mapbox-style.json';

        $provider = array(
            'type'   => $type,
            'osm'    => array(
                'tileUrl'     => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'attribution' => '© OpenStreetMap contributors',
            ),
            'mapbox' => array(
                'accessToken' => '',
                'styleId'     => (string) $settings['mapbox_style_id'],
            ),
            'mapbox-gl' => array(
                'accessToken' => '',
                'style'       => $bundled_mapbox_style,
                'fitPadding'  => array( 'top' => 240, 'bottom' => 110, 'left' => 28, 'right' => 70 ),
            ),
            'google' => array(
                'apiKey' => '',
                'mapId'  => '',
            ),
        );

        // Only inject sensitive tokens for the active provider so we never leak
        // a Mapbox token in JSON when the active provider is OSM.
        if ( $type === 'mapbox' && ! empty( $settings['mapbox_access_token'] ) ) {
            $provider['mapbox']['accessToken'] = (string) $settings['mapbox_access_token'];
        }
        if ( $type === 'mapbox-gl' && ! empty( $settings['mapbox_access_token'] ) ) {
            $provider['mapbox-gl']['accessToken'] = (string) $settings['mapbox_access_token'];
        }
        if ( $type === 'google' && ! empty( $settings['google_maps_api_key'] ) ) {
            $provider['google']['apiKey'] = (string) $settings['google_maps_api_key'];
        }

        return $provider;
    }

    /**
     * Build the live-location config block. Resolves whether this specific
     * visitor's geolocation prompt should fire based on:
     *   - the global enabled toggle
     *   - the country allowlist + the CDN-provided country header
     *
     * Detection priority:
     *   1. CF-IPCountry  (Cloudflare)
     *   2. X-Country-Code / X-Geo-Country (other CDNs)
     *   3. innsight/visitor_country filter (sites can plug their own
     *      detection - MaxMind, ipapi, hardcoded for staging, etc.)
     *
     * Privacy default: when the allowlist is non-empty AND we cannot
     * detect a country, we DO NOT prompt. Sites that need different
     * behavior should hook the filter to return their own value.
     */
    private function build_live_location_config( array $settings ): array {
        $enabled  = ! empty( $settings['live_location'] );
        $icon     = (string) ( $settings['live_location_icon'] ?? '🎒' );
        $allowlist = trim( (string) ( $settings['live_location_countries'] ?? '' ) );

        $allowed = $enabled;
        if ( $enabled && $allowlist !== '' ) {
            $codes  = array_filter( array_map( 'trim', explode( ',', strtoupper( $allowlist ) ) ) );
            $detected = $this->detect_visitor_country();
            // Strict default: no detection + non-empty allowlist = no prompt.
            $allowed = ( $detected !== '' && in_array( $detected, $codes, true ) );
        }

        return array(
            'enabled'      => $enabled,
            'allowed'      => (bool) $allowed,
            'icon'         => $icon,
            'allowlist'    => $allowlist,
        );
    }

    private function detect_visitor_country(): string {
        $candidates = array(
            'HTTP_CF_IPCOUNTRY',     // Cloudflare
            'HTTP_X_COUNTRY_CODE',   // generic CDN
            'HTTP_X_GEO_COUNTRY',    // other CDNs
            'GEOIP_COUNTRY_CODE',    // mod_geoip
            'HTTP_CLOUDFRONT_VIEWER_COUNTRY', // AWS CloudFront
        );
        $detected = '';
        foreach ( $candidates as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $detected = strtoupper( substr( (string) $_SERVER[ $key ], 0, 2 ) );
                if ( preg_match( '/^[A-Z]{2}$/', $detected ) ) break;
                $detected = '';
            }
        }
        /**
         * Override / supply visitor country detection.
         *
         * @param string $detected 2-letter ISO code, or empty if undetected.
         * @return string
         */
        return (string) apply_filters( 'innsight/visitor_country', $detected );
    }

    /**
     * Base-marker branding block. Resolves the attachment ID to two
     * live URLs (medium + thumbnail) so the pin template's srcset
     * fires on retina; drops the whole block when the attachment is
     * missing so a deleted media item never yields a broken image on
     * the map. Rings is a plain int array; empty array hides the
     * dashed circles altogether.
     */
    private function build_base_config( array $settings ): array {
        $out = array();
        $att = (int) ( $settings['base_photo'] ?? 0 );
        if ( $att > 0 ) {
            // Try medium + thumbnail; fall back to full so a small
            // upload (< 300 px, no intermediate sizes registered)
            // still resolves to SOMETHING instead of dropping the
            // whole key. Both slots default to whichever URL we
            // managed to get so the <img srcset> works.
            $medium = wp_get_attachment_image_url( $att, 'medium' );
            $thumb  = wp_get_attachment_image_url( $att, 'thumbnail' );
            $full   = wp_get_attachment_url( $att );
            $medium_url = $medium ?: $full ?: '';
            $thumb_url  = $thumb  ?: $medium_url;
            if ( $medium_url !== '' ) {
                $post = get_post( $att );
                $out['photo']      = (string) $medium_url;
                $out['photoThumb'] = (string) $thumb_url;
                $out['alt']        = $post ? ( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ?: (string) $post->post_title ) : '';
            }
        }
        $label = trim( (string) ( $settings['base_label'] ?? '' ) );
        if ( $label !== '' ) $out['label'] = $label;

        $rings = array();
        $raw = trim( (string) ( $settings['base_rings'] ?? '' ) );
        if ( $raw !== '' ) {
            foreach ( preg_split( '/[,\s]+/', $raw ) as $n ) {
                $n = (int) $n;
                if ( $n > 0 && $n <= 120 ) $rings[] = $n;
            }
        }
        $out['rings'] = array_values( array_unique( $rings ) );

        // Explicit coordinates take priority over the POI-list scan.
        $lat = (string) ( $settings['base_lat'] ?? '' );
        $lon = (string) ( $settings['base_lon'] ?? '' );
        if ( is_numeric( $lat ) && is_numeric( $lon ) ) {
            $out['lat'] = (float) $lat;
            $out['lon'] = (float) $lon;
        }
        return $out;
    }

    /**
     * Share-wishlist UI config block. Carries the editable copy strings
     * (popup title/body/button labels, dancing chip label) plus the
     * "feature enabled" toggle. Skin reads it via cfg.ui.share.
     */
    private function build_share_config( array $settings ): array {
        return array(
            'enabled'       => ! empty( $settings['share_enabled'] ?? 1 ),
            'inviteMessage' => (string) ( $settings['share_invite_message'] ?? '' ),
            'chipLabel'     => (string) ( $settings['share_chip_label'] ?? "Friend's picks" ),
            'popup'         => array(
                'title'        => (string) ( $settings['share_popup_title']    ?? 'A friend is sharing travel tips' ),
                'body'         => (string) ( $settings['share_popup_body']     ?? "They've curated a wishlist just for you. Want a peek?" ),
                'previewLabel' => (string) ( $settings['share_preview_label']  ?? 'Just preview' ),
                'saveAllLabel' => (string) ( $settings['share_save_all_label'] ?? 'Save them all' ),
            ),
        );
    }

    private function build_enrichment_config( array $settings ): array {
        $enabled = ! empty( $settings['google_places_enable'] ) && ! empty( $settings['google_places_key'] );
        return array(
            'google' => array(
                'apiKey'        => $enabled ? (string) $settings['google_places_key'] : '',
                'fields'        => is_array( $settings['google_places_fields'] ) ? array_values( $settings['google_places_fields'] ) : array(),
                'cacheTtlHours' => 24,
            ),
        );
    }

    private function default_action_links(): array {
        return array(
            'google' => array( 'label' => 'GMAPS',   'urlTemplate' => 'https://www.google.com/maps/dir/?api=1&destination={{lat}},{{lon}}' ),
            'apple'  => array( 'label' => 'iOS',     'urlTemplate' => 'https://maps.apple.com/?daddr={{lat}},{{lon}}' ),
            'mapsme' => array( 'label' => 'Maps.me', 'urlTemplate' => 'mapsme://map?ll={{lat}},{{lon}}', 'mobileOnly' => true ),
        );
    }

    /**
     * Derive the unique set of types from the POI list - feeds the layer control.
     *
     * @param array $pois
     * @return array
     */
    private function derive_types( array $pois ): array {
        $seen = array();
        foreach ( $pois as $poi ) {
            if ( ! empty( $poi['type'] ) ) {
                $seen[ (string) $poi['type'] ] = true;
            }
        }
        return array_keys( $seen );
    }

    private function shape_poi( array $poi ): array {
        // Titles from WP's get_the_title() come back with HTML entities
        // already encoded ("Hotel &amp; Spa"). Our client template
        // then escapes again on output ("Hotel &amp;amp; Spa") which
        // shows the literal "&amp;" on the page. Decode once here so
        // the string that reaches the browser is plain text.
        $title = isset( $poi['title'] ) ? html_entity_decode( (string) $poi['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
        $name  = isset( $poi['name'] )  ? html_entity_decode( (string) $poi['name'],  ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $title;
        return array(
            'id'            => isset( $poi['id'] ) ? (string) $poi['id'] : '',
            'title'         => $title,
            'name'          => $name,
            'lat'           => (float) $poi['lat'],
            'lon'           => (float) $poi['lon'],
            'description'   => isset( $poi['description'] ) ? (string) $poi['description'] : '',
            'type'          => isset( $poi['type'] ) ? (string) $poi['type'] : 'place',
            'cat'           => isset( $poi['cat'] ) ? (string) $poi['cat'] : '',
            'category'      => isset( $poi['category'] ) ? (string) $poi['category'] : '',
            'icon'          => isset( $poi['icon'] ) ? (string) $poi['icon'] : '',
            // `image` = medium (sheet hero); `imageThumb` = thumbnail (list
            // sticker + pin). Cuts list-view bandwidth dramatically when WP
            // has generated the smaller intermediate size.
            'image'         => isset( $poi['image'] ) ? (string) $poi['image'] : '',
            'imageThumb'    => isset( $poi['image_thumb'] ) ? (string) $poi['image_thumb'] : ( isset( $poi['image'] ) ? (string) $poi['image'] : '' ),
            'button'        => array(
                'url'  => isset( $poi['button']['url'] ) ? (string) $poi['button']['url'] : '',
                'text' => isset( $poi['button']['text'] ) ? (string) $poi['button']['text'] : '',
            ),
            'pinned'        => ! empty( $poi['pinned'] ),
            'googlePlaceId' => isset( $poi['googlePlaceId'] ) ? (string) $poi['googlePlaceId'] : '',
            // Posts that reference this POI via the legacy
            // maps_existing_act_marker_id ACF repeater. Surfaces in the
            // sheet's "Featured in" popover under the More-info button.
            'referencedBy'  => isset( $poi['referencedBy'] ) && is_array( $poi['referencedBy'] )
                ? array_values( array_map( static function ( $r ) {
                    return array(
                        'title' => isset( $r['title'] ) ? (string) $r['title'] : '',
                        'url'   => isset( $r['url'] )   ? (string) $r['url']   : '',
                    );
                }, $poi['referencedBy'] ) )
                : array(),
        );
    }

    /**
     * Build the category list the skin uses for its chip filter.
     *
     * Strategy: derive the categories from the unique `cat` / `type` values
     * actually present in the POI list (matches legacy yuna behavior of
     * rendering filters dynamically from poi_type). For each derived id we
     * pick a label + color from a known map covering both the legacy types
     * (hostel, food, bar, ...) and the design's 5-bucket vocabulary
     * (eats, drinks, ...). Anything unknown gets a hashed-palette color so
     * unfamiliar custom types still render distinctly.
     *
     * Filterable via `innsight/data/categories` so sites can override
     * label/color/order without touching plugin code.
     *
     * @param string $skin_name
     * @param array<int,array> $pois
     * @return array<int,array{id:string,label:string,color:string}>
     */
    private function build_categories( string $skin_name, array $pois ): array {
        // Known type -> { label, color }. Legacy yuna types first, then the
        // innsight2026 design palette. The chip color is what shows in the
        // filter dot AND in the popup meta row.
        $known = array(
            'hostel'     => array( 'label' => 'Hostel',     'color' => '#da011a' ),
            'food'       => array( 'label' => 'Food',       'color' => '#FF6B3D' ),
            'bar'        => array( 'label' => 'Bar',        'color' => '#C9F73F' ),
            'activities' => array( 'label' => 'Activities', 'color' => '#B07AFF' ),
            'place'      => array( 'label' => 'Place',      'color' => '#6BB7FF' ),
            'transport'  => array( 'label' => 'Transport',  'color' => '#FFD93D' ),
            'hike'       => array( 'label' => 'Hike',       'color' => '#5EE2A8' ),
            'shop'       => array( 'label' => 'Shop',       'color' => '#FF4D8F' ),
            'public'     => array( 'label' => 'Public',     'color' => '#FFB85C' ),
            'land'       => array( 'label' => 'Land',       'color' => '#8B8164' ),
            'event'      => array( 'label' => 'Events',     'color' => '#B07AFF' ),
            'city'       => array( 'label' => 'City',       'color' => '#6BB7FF' ),
            // Innsight 2026 design vocabulary (used by the importer's
            // mapcategory_normalized and by sites starting fresh).
            'eats'       => array( 'label' => 'Eats',       'color' => '#FF6B3D' ),
            'drinks'     => array( 'label' => 'Drinks',     'color' => '#C9F73F' ),
            'sights'     => array( 'label' => 'Sights',     'color' => '#6BB7FF' ),
            'shops'      => array( 'label' => 'Shops',      'color' => '#FF4D8F' ),
            'events'     => array( 'label' => 'Events',     'color' => '#B07AFF' ),
        );
        $palette = array( '#FF6B3D', '#C9F73F', '#6BB7FF', '#FF4D8F', '#B07AFF', '#FFD93D', '#5EE2A8', '#FFB85C' );

        // Unique types seen in the POI list, preserving insertion order.
        $seen = array();
        foreach ( $pois as $poi ) {
            $id = isset( $poi['cat'] ) && $poi['cat'] !== '' ? (string) $poi['cat'] : ( isset( $poi['type'] ) ? (string) $poi['type'] : '' );
            if ( $id === '' || isset( $seen[ $id ] ) ) continue;
            $seen[ $id ] = true;
        }

        $cats = array();
        $palette_idx = 0;
        foreach ( array_keys( $seen ) as $id ) {
            if ( isset( $known[ $id ] ) ) {
                $cats[] = array( 'id' => $id, 'label' => $known[ $id ]['label'], 'color' => $known[ $id ]['color'] );
            } else {
                $cats[] = array( 'id' => $id, 'label' => ucfirst( $id ), 'color' => $palette[ $palette_idx % count( $palette ) ] );
                $palette_idx++;
            }
        }

        // No POIs at all -> emit the design's 5-bucket fallback so the chip
        // strip is not empty in the empty state.
        if ( empty( $cats ) && $skin_name === 'innsight2026' ) {
            $cats = array(
                array( 'id' => 'eats',   'label' => 'Eats',   'color' => '#FF6B3D' ),
                array( 'id' => 'drinks', 'label' => 'Drinks', 'color' => '#C9F73F' ),
                array( 'id' => 'sights', 'label' => 'Sights', 'color' => '#6BB7FF' ),
                array( 'id' => 'shops',  'label' => 'Shops',  'color' => '#FF4D8F' ),
                array( 'id' => 'events', 'label' => 'Events', 'color' => '#B07AFF' ),
            );
        }

        return (array) apply_filters( 'innsight/data/categories', $cats, $skin_name, $pois );
    }

    private function shape_path( array $path ): array {
        $coords = array();
        if ( ! empty( $path['coordinates'] ) && is_array( $path['coordinates'] ) ) {
            foreach ( $path['coordinates'] as $pair ) {
                if ( is_array( $pair ) && count( $pair ) >= 2 ) {
                    $coords[] = array( (float) $pair[0], (float) $pair[1] );
                }
            }
        }
        return array(
            'id'          => isset( $path['id'] ) ? (string) $path['id'] : '',
            'title'       => isset( $path['title'] ) ? (string) $path['title'] : '',
            'color'       => isset( $path['color'] ) ? (string) $path['color'] : '#3d3c3c',
            'coordinates' => $coords,
        );
    }

    private function resolve_skin_base_url( string $skin_name ): string {
        $settings = innsight_settings();
        if ( $settings['engine_source'] === 'custom' && ! empty( $settings['skin_url'] ) ) {
            return trailingslashit( (string) $settings['skin_url'] );
        }
        // Default to the bundled skin folder.
        return trailingslashit( INNSIGHT_URL . 'skins/' . $skin_name );
    }
}
