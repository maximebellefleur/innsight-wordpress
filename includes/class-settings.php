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

            // Nightly cron that pre-fetches Google Places data for every
            // POI whose cache is missing or older than 30 days. When off,
            // enrichment still lazy-loads on the first sheet open per
            // POI (with stale-while-revalidate on repeat opens). When
            // on, most sheet opens hit a hot cache.
            'places_cron_enabled'  => 0,

            // UI defaults the engine respects.
            'kml_export'           => 1,
            'solo_mode'            => 1,

            // Default zoom level - used when neither the shortcode
            // `zoom` attribute nor the per-post `map_zoom_level` ACF
            // field is set. Range 1-20; typical: 10-14 for a city.
            'default_zoom'         => 12,

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

            // Base marker (hostel). Rendered as a taped-photo print on
            // the map for the pinned POI - the same one findHostelRef()
            // resolves for "distance from base" labels. photo = attachment
            // ID (WP media picker); label overrides the auto-derived
            // caption; rings = minutes for the walk-time circles (empty
            // array hides them).
            'base_photo'           => 0,
            'base_label'           => '',
            'base_rings'           => '5,10',
            // Unit for the walk-ring numbers above.
            //   'min' - 80 m per walking minute (default; matches
            //           original spec "5-min walk, 10-min walk").
            //   'km'  - each entry is a kilometre radius.
            //   'm'   - each entry is a metre radius.
            // Only affects geometry + chip label; the CSV shape stays
            // the same so admins don't have to re-enter numbers.
            'base_ring_unit'       => 'min',
            // Explicit base coordinates. When set, override the
            // pinned/hostel-POI resolution AND the map.center fallback.
            // Empty = fall through to the priority chain in
            // engine/features/markers.js#pickBasePoi.
            'base_lat'             => '',
            'base_lon'             => '',

            // Per-post-type icon class. The plugin ingests three kinds
            // of markers: POIs from the `point_of_interest` taxonomy
            // (icon comes from term meta), portfolio activities, and
            // event posts. Activities + events don't have per-post icon
            // fields in the legacy yuna schema, so the icon is a plugin
            // setting. Any md-* / map-* class shipped in assets/icons.css
            // works; blank hides the glyph and the letter tile stands
            // alone.
            'activities_icon'      => 'md-directions-run',
            'events_icon'          => 'md-event',

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

            // PWA: manifest + service worker + head tags. Ports the
            // yuna-innsight PWA plumbing so installed home-screen apps
            // keep working. Icons default to bundled Balmers-tinted
            // PNGs in assets/pwa/img/; admins override each via URL.
            'pwa_enabled'             => 1,
            'pwa_name'                => '',
            'pwa_short_name'          => '',
            'pwa_description'         => '',
            'pwa_start_url'           => '',
            'pwa_scope'               => '',
            'pwa_theme_color'         => '#FFFFFF',
            'pwa_bg_color'            => '#FFFFFF',
            'pwa_icon_192'            => '',
            'pwa_icon_512'            => '',
            'pwa_icon_192m'           => '',
            'pwa_icon_512m'           => '',
            'pwa_apple_touch'         => '',
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
        $clean['places_cron_enabled']  = ! empty( $raw['places_cron_enabled'] ) ? 1 : 0;
        $clean['kml_export']           = ! empty( $raw['kml_export'] ) ? 1 : 0;
        $clean['solo_mode']            = ! empty( $raw['solo_mode'] ) ? 1 : 0;
        // Default zoom - clamped to Mapbox's usable range.
        $clean['default_zoom']         = isset( $raw['default_zoom'] ) ? max( 1, min( 20, (int) $raw['default_zoom'] ) ) : (int) $defaults['default_zoom'];
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

        // Base marker fields.
        $clean['base_photo']           = isset( $raw['base_photo'] ) ? (int) $raw['base_photo'] : 0;
        $clean['base_label']           = isset( $raw['base_label'] ) ? mb_substr( wp_strip_all_tags( (string) $raw['base_label'] ), 0, 40 ) : '';
        // Rings: comma / space separated positive numbers. "5, 10" or
        // "2, 5" or empty for none. Fractional km values allowed
        // (e.g. "0.5, 1, 2") so the ring unit switcher isn't forced
        // to whole-integer inputs.
        $rings_raw = isset( $raw['base_rings'] ) ? (string) $raw['base_rings'] : '';
        $rings = array_filter( array_map( 'floatval', preg_split( '/[,\s]+/', trim( $rings_raw ) ) ), function ( $n ) { return $n > 0 && $n <= 200; } );
        // Format: preserve fractional part only when non-zero, else
        // emit the plain integer. "5.0, 10.0" reads worse than "5, 10".
        $rings = array_map( function ( $n ) { return rtrim( rtrim( sprintf( '%.3f', $n ), '0' ), '.' ); }, $rings );
        $clean['base_rings']           = implode( ',', array_slice( array_values( array_unique( $rings ) ), 0, 4 ) );

        $clean['base_ring_unit']       = in_array( $raw['base_ring_unit'] ?? '', array( 'min', 'km', 'm' ), true ) ? $raw['base_ring_unit'] : 'min';

        // Base coordinates - kept as strings so an empty value stays
        // truly empty (not 0.0 which would silently anchor at null-
        // island in the Atlantic). Validation happens on read.
        foreach ( array( 'base_lat', 'base_lon' ) as $ck ) {
            $v = isset( $raw[ $ck ] ) ? trim( (string) $raw[ $ck ] ) : '';
            $clean[ $ck ] = is_numeric( $v ) ? $v : '';
        }

        // Per-post-type icon class. Free-text (any md-*/map-*/fa-*
        // class shipped by the skin) - strip tags so no injection.
        foreach ( array( 'activities_icon', 'events_icon' ) as $ck ) {
            $v = isset( $raw[ $ck ] ) ? trim( wp_strip_all_tags( (string) $raw[ $ck ] ) ) : '';
            // Class-name chars only: letters, digits, dash, underscore.
            $clean[ $ck ] = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $v );
        }

        // Share-wishlist copy. Plain-text fields the recipient sees in the
        // receive popup + the dancing chip label. Stripped to avoid HTML
        // injection inside the bottom sheet.
        $clean['share_enabled']        = ! empty( $raw['share_enabled'] ) ? 1 : 0;
        $share_text_keys = array( 'share_invite_message', 'share_popup_title', 'share_popup_body', 'share_preview_label', 'share_save_all_label', 'share_chip_label' );
        foreach ( $share_text_keys as $sk ) {
            $clean[ $sk ] = isset( $raw[ $sk ] ) ? wp_strip_all_tags( (string) $raw[ $sk ] ) : (string) ( $defaults[ $sk ] ?? '' );
        }

        $clean['analytics_enabled']    = ! empty( $raw['analytics_enabled'] ) ? 1 : 0;

        // PWA fields. Text stripped, URLs cleaned via esc_url_raw,
        // colors normalised to #RRGGBB.
        $clean['pwa_enabled']          = ! empty( $raw['pwa_enabled'] ) ? 1 : 0;
        foreach ( array( 'pwa_name', 'pwa_short_name', 'pwa_description' ) as $k ) {
            $clean[ $k ] = isset( $raw[ $k ] ) ? wp_strip_all_tags( (string) $raw[ $k ] ) : '';
        }
        foreach ( array( 'pwa_start_url', 'pwa_scope', 'pwa_icon_192', 'pwa_icon_512', 'pwa_icon_192m', 'pwa_icon_512m', 'pwa_apple_touch' ) as $k ) {
            $clean[ $k ] = isset( $raw[ $k ] ) ? esc_url_raw( (string) $raw[ $k ] ) : '';
        }
        foreach ( array( 'pwa_theme_color', 'pwa_bg_color' ) as $k ) {
            $v = isset( $raw[ $k ] ) ? sanitize_hex_color( (string) $raw[ $k ] ) : null;
            $clean[ $k ] = $v ?: (string) ( $defaults[ $k ] ?? '#FFFFFF' );
        }

        return $clean;
    }
}
