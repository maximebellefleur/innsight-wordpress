<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Admin - Settings > Innsight page.
 *
 * Single page covering: engine source, skin, default provider + tokens,
 * Google Places enrichment, geocoder politeness, KML/Solo defaults, render
 * mode. Uses the WordPress Settings API end-to-end (sanitize callback,
 * settings_fields(), do_settings_sections()) so it plays nicely with
 * multisite, capability checks, and WP-CLI option imports.
 */
final class Admin {

    private const OPTION_NAME = 'innsight_settings';
    private const PAGE_SLUG   = 'innsight';

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_menu(): void {
        // Top-level "Innsight" menu with a compass SVG icon. Other classes
        // (DefaultsPage, ImportPage) and the `poi` post type all hang their
        // sub-pages off this parent slug ('innsight'), giving the admin a
        // single branded section instead of three Settings sub-items.
        add_menu_page(
            __( 'Innsight', 'innsight' ),
            __( 'Innsight', 'innsight' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' ),
            $this->menu_icon_data_uri(),
            25
        );
        // First sub-page must reuse the parent slug so it becomes the
        // landing page when the user clicks the top-level item.
        add_submenu_page(
            self::PAGE_SLUG,
            __( 'Settings', 'innsight' ),
            __( 'Settings', 'innsight' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Compass SVG inlined as a data URI for the WP admin menu icon.
     * Uses currentColor so WP's icon-color CSS (light/dark admin themes)
     * paints it correctly. 20x20 viewBox per WP's icon convention.
     */
    private function menu_icon_data_uri(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
             . '<circle cx="10" cy="10" r="7.4" fill="none" stroke="black" stroke-width="1.4"/>'
             . '<path d="M10 3.6 L7.5 10.5 L10 9.2 L12.5 10.5 Z" fill="black"/>'
             . '<path d="M10 16.4 L7.5 9.5 L10 10.8 L12.5 9.5 Z" fill="black" opacity=".4"/>'
             . '<circle cx="10" cy="10" r="0.9" fill="white" stroke="black" stroke-width=".5"/>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    public function register_settings(): void {
        register_setting(
            'innsight_settings_group',
            self::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( Settings::class, 'sanitize' ),
                'default'           => Settings::defaults(),
            )
        );

        add_settings_section( 'innsight_engine', __( 'Engine source', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_provider', __( 'Map provider', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_design', __( 'Design', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_enrichment', __( 'Google Places enrichment', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_render', __( 'Render mode & UI', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_share', __( 'Share wishlist', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_geocoder', __( 'Geocoder', 'innsight' ), '__return_false', self::PAGE_SLUG );

        $this->add_field( 'engine_source', __( 'Engine source', 'innsight' ), 'innsight_engine', 'render_engine_source' );
        $this->add_field( 'engine_url', __( 'Custom engine base URL', 'innsight' ), 'innsight_engine', 'render_text', __( 'Required only when "Custom" is selected. The URL must serve the engine directory tree (e.g. /engine/innsight.js).', 'innsight' ) );
        $this->add_field( 'skin_url', __( 'Custom skin base URL', 'innsight' ), 'innsight_engine', 'render_text', __( 'Optional override - if empty, the bundled skin is used.', 'innsight' ) );

        $this->add_field( 'skin_name', __( 'Design', 'innsight' ), 'innsight_design', 'render_skin_radio',
            __( 'Choose which skin renders the map. The new 2026 design needs a Mapbox access token (see below).', 'innsight' ) );

        $this->add_field( 'provider_default', __( 'Default provider', 'innsight' ), 'innsight_provider', 'render_provider_default' );
        $this->add_field( 'mapbox_access_token', __( 'Mapbox access token', 'innsight' ), 'innsight_provider', 'render_text' );
        $this->add_field( 'mapbox_style_id', __( 'Mapbox style ID', 'innsight' ), 'innsight_provider', 'render_text' );
        $this->add_field( 'google_maps_api_key', __( 'Google Maps API key (v0.2)', 'innsight' ), 'innsight_provider', 'render_text', __( 'Reserved for the Google Maps provider, which lands in v0.2. Save now to skip the second trip.', 'innsight' ) );

        $this->add_field( 'google_places_enable', __( 'Enable Google Places enrichment', 'innsight' ), 'innsight_enrichment', 'render_checkbox' );
        $this->add_field( 'google_places_key', __( 'Google Places API key', 'innsight' ), 'innsight_enrichment', 'render_text' );

        $this->add_field( 'render_mode', __( 'Render mode', 'innsight' ), 'innsight_render', 'render_render_mode' );
        $this->add_field( 'kml_export', __( 'Show KML download button', 'innsight' ), 'innsight_render', 'render_checkbox' );
        $this->add_field( 'solo_mode', __( 'Solo Mode toggle available', 'innsight' ), 'innsight_render', 'render_checkbox' );
        $this->add_field( 'live_location', __( 'Show user\'s live location', 'innsight' ), 'innsight_render', 'render_checkbox', __( 'Asks the browser for the user\'s coordinates and drops a pulsing marker on the map.', 'innsight' ) );
        $this->add_field( 'live_location_icon', __( 'Live location icon', 'innsight' ), 'innsight_render', 'render_text', __( 'Single character (emoji works best). Default: 🎒. Leave empty for a colored dot.', 'innsight' ) );
        $this->add_field( 'live_location_countries', __( 'Allowed countries', 'innsight' ), 'innsight_render', 'render_text', __( 'Comma-separated ISO country codes (e.g. CH, FR, DE). The geolocation prompt only fires when the visitor\'s detected country is in this list. Detection uses the CDN-provided header (Cloudflare CF-IPCountry, etc). Leave empty to prompt every visitor. Default: CH (Switzerland only).', 'innsight' ) );

        $this->add_field( 'share_enabled', __( 'Enable Share Wishlist button', 'innsight' ), 'innsight_share', 'render_checkbox', __( 'Adds a top-right Share button on the Saved tab. The visitor can ship their saved spots through native share / WhatsApp / Facebook / Email / copy-link.', 'innsight' ) );
        $this->add_field( 'share_invite_message', __( 'Invite message', 'innsight' ), 'innsight_share', 'render_text', __( 'Pre-filled message body when the visitor shares (WhatsApp / Email).', 'innsight' ) );
        $this->add_field( 'share_popup_title', __( 'Receive popup - title', 'innsight' ), 'innsight_share', 'render_text', __( 'Headline shown to recipients when they land via a share link.', 'innsight' ) );
        $this->add_field( 'share_popup_body', __( 'Receive popup - body', 'innsight' ), 'innsight_share', 'render_textarea' );
        $this->add_field( 'share_preview_label', __( 'Receive popup - preview button', 'innsight' ), 'innsight_share', 'render_text' );
        $this->add_field( 'share_save_all_label', __( 'Receive popup - save-all button', 'innsight' ), 'innsight_share', 'render_text' );
        $this->add_field( 'share_chip_label', __( "Friend's-picks chip label", 'innsight' ), 'innsight_share', 'render_text', __( 'Label shown on the dancing chip in the filter bar after the recipient picks "Just preview".', 'innsight' ) );

        $this->add_field( 'geocoder_email', __( 'Nominatim contact email', 'innsight' ), 'innsight_geocoder', 'render_text', __( 'Sent in the User-Agent header per Nominatim usage policy.', 'innsight' ) );
        $this->add_field( 'geocoder_cache_hours', __( 'Geocoder cache TTL (hours)', 'innsight' ), 'innsight_geocoder', 'render_number' );
    }

    private function add_field( string $key, string $label, string $section, string $render, string $description = '' ): void {
        add_settings_field(
            $key,
            esc_html( $label ),
            function () use ( $key, $render, $description ) {
                $values = innsight_settings();
                call_user_func( array( $this, $render ), $key, $values[ $key ] ?? '' );
                if ( $description !== '' ) {
                    echo '<p class="description">' . esc_html( $description ) . '</p>';
                }
            },
            self::PAGE_SLUG,
            $section
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html__( 'Innsight', 'innsight' ) . ' <span style="font-size:13px;font-weight:400;color:#646970;background:#f0f0f1;padding:2px 8px;border-radius:3px;vertical-align:middle">v' . esc_html( INNSIGHT_VERSION ) . '</span></h1>';

        // Post-purge success notice (after the redirect from
        // admin-post.php?action=innsight_purge_caches).
        if ( ! empty( $_GET['innsight_purged'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Innsight caches purged. Reload the front-end to confirm fresh assets.', 'innsight' ) . '</p></div>';
        }

        echo '<p>' . esc_html__( 'Configure how the Innsight map engine renders inside this site. The plugin reads existing yuna-innsight DB structures (POI taxonomy, portfolio activities, ACF options) without modification.', 'innsight' ) . '</p>';

        // Cache-purge call-to-action. Bridges the gap when a host's
        // page cache (WP Engine NGINX, Kinsta edge, Cloudflare APO,
        // FastCGI cache) keeps serving the old HTML to non-logged-in
        // visitors. Triggers our CacheManager which loops every
        // page-cache plugin / managed-host API we know about.
        $purge_url = wp_nonce_url( admin_url( 'admin-post.php?action=innsight_purge_caches' ), 'innsight_purge_caches' );
        echo '<div style="background:#fffbe5;border-left:4px solid #f0b849;padding:12px 14px;margin:14px 0;max-width:780px"><p style="margin:0 0 6px"><strong>' . esc_html__( 'Visitors seeing old layouts after an upgrade?', 'innsight' ) . '</strong></p><p style="margin:0 0 8px">' . esc_html__( 'Click below to flush the Innsight partial cache plus every page-cache plugin we recognise (WP Rocket, LiteSpeed, W3TC, WP Super Cache, SG Optimizer, Hummingbird, Breeze, Cache Enabler, WP Fastest Cache, WP Engine, Kinsta, Pantheon, NGINX Helper).', 'innsight' ) . '</p><a href="' . esc_url( $purge_url ) . '" class="button button-primary">' . esc_html__( 'Purge Innsight caches now', 'innsight' ) . '</a></div>';

        echo '<form method="post" action="options.php">';
        settings_fields( 'innsight_settings_group' );
        do_settings_sections( self::PAGE_SLUG );
        submit_button();
        echo '</form></div>';
    }

    /* ----------------------------- field renderers ----------------------------- */

    public function render_engine_source( string $key, $value ): void {
        $opts = array(
            'bundled' => __( 'Bundled (use the engine shipped with this plugin)', 'innsight' ),
            'cdn'     => __( 'CDN (jsDelivr against maximebellefleur/innsight)', 'innsight' ),
            'custom'  => __( 'Custom URL', 'innsight' ),
        );
        echo '<select name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '">';
        foreach ( $opts as $k => $label ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_skin_radio( string $key, $value ): void {
        $value = (string) $value !== '' ? (string) $value : 'solike2025';
        $opts = array(
            'solike2025'  => array(
                'label' => __( 'Legacy (solike2025)', 'innsight' ),
                'desc'  => __( 'Cluster + popup card. Matches the look the yuna-innsight plugin shipped. No Mapbox token required.', 'innsight' ),
            ),
            'innsight2026' => array(
                'label' => __( 'New 2026 design (innsight2026)', 'innsight' ),
                'desc'  => __( 'Cream / sticker pins / bottom sheet / list / tab bar. Requires a Mapbox access token (free tier covers 50k loads/month).', 'innsight' ),
            ),
        );
        echo '<fieldset>';
        foreach ( $opts as $id => $spec ) {
            printf(
                '<label style="display:block;margin-bottom:8px"><input type="radio" name="%s" value="%s" %s /> <strong>%s</strong> &mdash; <span class="description">%s</span></label>',
                esc_attr( self::OPTION_NAME . '[' . $key . ']' ),
                esc_attr( $id ),
                checked( $value, $id, false ),
                esc_html( $spec['label'] ),
                esc_html( $spec['desc'] )
            );
        }
        echo '</fieldset>';
    }

    public function render_provider_default( string $key, $value ): void {
        $opts = array(
            'osm'       => __( 'OpenStreetMap (Leaflet)', 'innsight' ),
            'mapbox'    => __( 'Mapbox raster (Leaflet + Mapbox tiles)', 'innsight' ),
            'mapbox-gl' => __( 'Mapbox GL JS (vector - used by innsight2026 design)', 'innsight' ),
            'google'    => __( 'Google Maps (v0.2 stub)', 'innsight' ),
        );
        echo '<select name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '">';
        foreach ( $opts as $k => $label ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_render_mode( string $key, $value ): void {
        $opts = array(
            'inline' => __( 'Inline (faster: no extra HTTP roundtrip)', 'innsight' ),
            'fetch'  => __( 'Fetch (engine fetches /wp-json/innsight/v1/map)', 'innsight' ),
        );
        echo '<select name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '">';
        foreach ( $opts as $k => $label ) {
            echo '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public function render_text( string $key, $value ): void {
        echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $value ) . '" />';
    }

    public function render_number( string $key, $value ): void {
        echo '<input type="number" min="1" name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $value ) . '" />';
    }

    public function render_checkbox( string $key, $value ): void {
        echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . ' /> ' . esc_html__( 'Enabled', 'innsight' ) . '</label>';
    }

    public function render_textarea( string $key, $value ): void {
        echo '<textarea class="large-text" rows="3" name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
    }
}
