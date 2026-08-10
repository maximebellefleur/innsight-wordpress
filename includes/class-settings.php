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

            // Live location: when enabled, the engine asks the browser for
            // the user's coordinates and drops a pulsing marker on the map.
            // The icon can be any single character (emoji recommended) -
            // 🎒 backpack by default to fit the tourism / "in the know"
            // brand. Set to an empty string to render a colored dot instead.
            'live_location'        => 1,
            'live_location_icon'   => '🎒',
            // Comma-separated ISO 3166-1 alpha-2 country codes. The
            // geolocation prompt is only triggered when the visitor's
            // detected country is in this list (default: just Switzerland).
            // Empty = always prompt (no gating). Detection uses CDN headers
            // (Cloudflare CF-IPCountry etc); when no header is present and
            // the list is non-empty we DON'T prompt (privacy default).
            'live_location_countries' => 'CH',

            // Geocoder.
            'geocoder_email'       => '',                   // Sent in Nominatim User-Agent (politeness header).
            'geocoder_cache_hours' => 24 * 30,              // 30 days; geocodes are stable.

            // Render mode for the shortcode: 'inline' (emit window.INNSIGHT_DATA + skin partials)
            // or 'fetch' (engine fetches /wp-json/innsight/v1/map).
            'render_mode'          => 'inline',

            // Wordmark prefix (e.g. "Balmers") shown before "→ Innsight"
            // in the header / list / saved view wordmarks. Empty = render
            // just "Innsight" as before. Plain text only; the skin injects
            // it through DOM rewrites so HTML in the value is escaped.
            'wordmark_prefix'      => '',

            // Share-wishlist feature (innsight2026 only). When enabled, the
            // Saved tab gets a top-right Share button that lets the visitor
            // ship their saved list as a tokenized URL. The recipient sees
            // a "your friend is sharing travel tips" popup with editable
            // copy below.
            'share_enabled'           => 1,
            'share_invite_message'    => "Hey! I'm sharing my travel wishlist with you 👇",
            'share_popup_title'       => 'A friend is sharing travel tips',
            'share_popup_body'        => "They've curated a wishlist just for you. Want a peek?",
            'share_preview_label'     => 'Just preview',
            'share_save_all_label'    => 'Save them all',
            'share_chip_label'        => "Friend's picks",

            // Analytics: opt-in privacy-friendly aggregate counts of
            // map loads, POI opens/saves/unsaves, shares sent/received.
            // No visitor identity, no IP stored, day-bucketed
            // aggregates only. On by default; admins who want zero
            // telemetry can flip this off in Settings.
            'analytics_enabled'       => 1,
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
        $clean['skin_name']            = in_array( $raw['skin_name'] ?? '', array( 'solike2025', 'innsight2026' ), true ) ? $raw['skin_name'] : $defaults['skin_name'];
        $clean['provider_default']     = in_array( $raw['provider_default'] ?? '', array( 'osm', 'mapbox', 'mapbox-gl', 'google' ), true ) ? $raw['provider_default'] : 'osm';
        // innsight2026 requires Mapbox GL JS. Force the provider so users
        // don't have to know to flip two settings to get the new design.
        if ( $clean['skin_name'] === 'innsight2026' ) {
            $clean['provider_default'] = 'mapbox-gl';
        }
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
        $clean['live_location']        = ! empty( $raw['live_location'] ) ? 1 : 0;
        // Single grapheme allowed - usually one emoji. Strip tags so a
        // theme injection can't sneak in HTML attrs through the field.
        $clean['live_location_icon']   = isset( $raw['live_location_icon'] ) ? trim( wp_strip_all_tags( (string) $raw['live_location_icon'] ) ) : $defaults['live_location_icon'];
        // Cap length so a misuse doesn't blow up the map marker.
        if ( strlen( $clean['live_location_icon'] ) > 16 ) {
            $clean['live_location_icon'] = mb_substr( $clean['live_location_icon'], 0, 4 );
        }
        // Country allowlist: normalize to comma-separated, uppercase,
        // 2-letter codes only. Spaces, lowercase, semicolons all accepted
        // on input.
        $countries_raw = isset( $raw['live_location_countries'] ) ? (string) $raw['live_location_countries'] : '';
        $codes = preg_split( '/[\s,;]+/', strtoupper( $countries_raw ) ) ?: array();
        $codes = array_filter( $codes, static function ( $c ) { return preg_match( '/^[A-Z]{2}$/', $c ); } );
        $clean['live_location_countries'] = implode( ',', array_unique( $codes ) );
        $clean['geocoder_email']       = isset( $raw['geocoder_email'] ) ? sanitize_email( $raw['geocoder_email'] ) : '';
        $clean['geocoder_cache_hours'] = isset( $raw['geocoder_cache_hours'] ) ? max( 1, (int) $raw['geocoder_cache_hours'] ) : $defaults['geocoder_cache_hours'];
        $clean['render_mode']          = in_array( $raw['render_mode'] ?? '', array( 'inline', 'fetch' ), true ) ? $raw['render_mode'] : 'inline';
        // Wordmark prefix: plain text, strip tags, cap at 40 chars so a
        // long brand doesn't overflow the chrome row.
        $wm = isset( $raw['wordmark_prefix'] ) ? wp_strip_all_tags( (string) $raw['wordmark_prefix'] ) : '';
        $clean['wordmark_prefix']      = mb_substr( trim( $wm ), 0, 40 );

        // Share-wishlist copy. Plain-text fields the recipient sees in the
        // receive popup + the dancing chip label. Stripped to avoid HTML
        // injection inside the bottom sheet.
        $clean['share_enabled']        = ! empty( $raw['share_enabled'] ) ? 1 : 0;
        $share_text_keys = array( 'share_invite_message', 'share_popup_title', 'share_popup_body', 'share_preview_label', 'share_save_all_label', 'share_chip_label' );
        foreach ( $share_text_keys as $sk ) {
            $clean[ $sk ] = isset( $raw[ $sk ] ) ? wp_strip_all_tags( (string) $raw[ $sk ] ) : (string) ( $defaults[ $sk ] ?? '' );
        }

        $clean['analytics_enabled']    = ! empty( $raw['analytics_enabled'] ) ? 1 : 0;

        return $clean;
    }
}
