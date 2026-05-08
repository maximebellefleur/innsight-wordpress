<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Settings - holds the plugin's saved options shape and defaults.
 *
 * Stored as a single option `innsight_settings` for simplicity. Keys are stable;
 * filters allow runtime overrides without persisting to the DB.
 */
final class Settings {

    /**
     * Default settings - merged with whatever's stored in the DB.
     *
     * @return array
     */
    public static function defaults(): array {
        return array(
            // Engine source - 'bundled' (use plugin's bundled vendor + skin),
            // 'cdn' (jsdelivr against the maximebellefleur/innsight repo),
            // or 'custom' (use the URLs below).
            'engine_source'        => 'bundled',
            'engine_url'           => '',
            'engine_vendor_url'    => '',
            'skin_url'             => '',

            // Skin selection.
            'skin_name'            => 'solike2025',

            // Default map provider - the Innsight engine config will use this when
            // the post-level map config does not override it.
            'provider_default'     => 'osm',

            // Mapbox token (sent inline only when provider is mapbox).
            'mapbox_access_token'  => '',
            'mapbox_style_id'      => 'mapbox/streets-v12',

            // Google API keys (only injected when explicitly enabled).
            'google_maps_api_key'  => '',
            'google_places_enable' => 0,
            'google_places_key'    => '',
            'google_places_fields' => array( 'photos', 'opening_hours', 'rating' ),

            // UI defaults the engine respects.
            'kml_export'           => 1,
            'solo_mode'            => 1,

            // Geocoder.
            'geocoder_email'       => '',                   // Sent in Nominatim User-Agent (politeness header).
            'geocoder_cache_hours' => 24 * 30,              // 30 days; geocodes are stable.

            // Render mode for the shortcode: 'inline' (emit window.INNSIGHT_DATA + skin partials)
            // or 'fetch' (engine fetches /wp-json/innsight/v1/map).
            'render_mode'          => 'inline',
        );
    }

    /**
     * Sanitize a settings array prior to persistence.
     *
     * @param array $raw
     * @return array
     */
    public static function sanitize( array $raw ): array {
        $defaults = self::defaults();
        $clean    = array();

        $clean['engine_source']        = in_array( $raw['engine_source'] ?? '', array( 'bundled', 'cdn', 'custom' ), true ) ? $raw['engine_source'] : $defaults['engine_source'];
        $clean['engine_url']           = isset( $raw['engine_url'] ) ? esc_url_raw( $raw['engine_url'] ) : '';
        $clean['engine_vendor_url']    = isset( $raw['engine_vendor_url'] ) ? esc_url_raw( $raw['engine_vendor_url'] ) : '';
        $clean['skin_url']             = isset( $raw['skin_url'] ) ? esc_url_raw( $raw['skin_url'] ) : '';
        $clean['skin_name']            = isset( $raw['skin_name'] ) ? sanitize_key( $raw['skin_name'] ) : $defaults['skin_name'];
        $clean['provider_default']     = in_array( $raw['provider_default'] ?? '', array( 'osm', 'mapbox', 'google' ), true ) ? $raw['provider_default'] : 'osm';
        $clean['mapbox_access_token']  = isset( $raw['mapbox_access_token'] ) ? sanitize_text_field( $raw['mapbox_access_token'] ) : '';
        $clean['mapbox_style_id']      = isset( $raw['mapbox_style_id'] ) ? sanitize_text_field( $raw['mapbox_style_id'] ) : $defaults['mapbox_style_id'];
        $clean['google_maps_api_key']  = isset( $raw['google_maps_api_key'] ) ? sanitize_text_field( $raw['google_maps_api_key'] ) : '';
        $clean['google_places_enable'] = ! empty( $raw['google_places_enable'] ) ? 1 : 0;
        $clean['google_places_key']    = isset( $raw['google_places_key'] ) ? sanitize_text_field( $raw['google_places_key'] ) : '';
        $clean['google_places_fields'] = isset( $raw['google_places_fields'] ) && is_array( $raw['google_places_fields'] )
            ? array_values( array_intersect( array( 'photos', 'opening_hours', 'rating' ), $raw['google_places_fields'] ) )
            : $defaults['google_places_fields'];
        $clean['kml_export']           = ! empty( $raw['kml_export'] ) ? 1 : 0;
        $clean['solo_mode']            = ! empty( $raw['solo_mode'] ) ? 1 : 0;
        $clean['geocoder_email']       = isset( $raw['geocoder_email'] ) ? sanitize_email( $raw['geocoder_email'] ) : '';
        $clean['geocoder_cache_hours'] = isset( $raw['geocoder_cache_hours'] ) ? max( 1, (int) $raw['geocoder_cache_hours'] ) : $defaults['geocoder_cache_hours'];
        $clean['render_mode']          = in_array( $raw['render_mode'] ?? '', array( 'inline', 'fetch' ), true ) ? $raw['render_mode'] : 'inline';

        return $clean;
    }
}
