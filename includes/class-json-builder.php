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
        return array(
            'id'            => isset( $poi['id'] ) ? (string) $poi['id'] : '',
            'title'         => isset( $poi['title'] ) ? (string) $poi['title'] : '',
            'name'          => isset( $poi['name'] ) ? (string) $poi['name'] : ( isset( $poi['title'] ) ? (string) $poi['title'] : '' ),
            'lat'           => (float) $poi['lat'],
            'lon'           => (float) $poi['lon'],
            'description'   => isset( $poi['description'] ) ? (string) $poi['description'] : '',
            'type'          => isset( $poi['type'] ) ? (string) $poi['type'] : 'place',
            'cat'           => isset( $poi['cat'] ) ? (string) $poi['cat'] : '',
            'category'      => isset( $poi['category'] ) ? (string) $poi['category'] : '',
            'icon'          => isset( $poi['icon'] ) ? (string) $poi['icon'] : '',
            'image'         => isset( $poi['image'] ) ? (string) $poi['image'] : '',
            'button'        => array(
                'url'  => isset( $poi['button']['url'] ) ? (string) $poi['button']['url'] : '',
                'text' => isset( $poi['button']['text'] ) ? (string) $poi['button']['text'] : '',
            ),
            'pinned'        => ! empty( $poi['pinned'] ),
            'googlePlaceId' => isset( $poi['googlePlaceId'] ) ? (string) $poi['googlePlaceId'] : '',
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
