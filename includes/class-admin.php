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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Load the skin's icons.css + @font-face on the plugin settings
     * page so the icon-class input's live preview renders the actual
     * glyph. Only loaded on our own page (checked via $hook prefix)
     * to avoid bloating unrelated admin screens.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }
        $skin = (string) innsight_settings( 'skin_name', 'innsight2026' );
        if ( $skin !== 'innsight2026' ) {
            return;
        }
        $icons_url  = INNSIGHT_URL  . 'skins/' . $skin . '/assets/icons.css';
        $icons_path = INNSIGHT_PATH . 'skins/' . $skin . '/assets/icons.css';
        $ver = file_exists( $icons_path ) ? (string) filemtime( $icons_path ) : INNSIGHT_VERSION;
        wp_register_style( 'innsight-admin-icons', $icons_url, array(), $ver );
        wp_enqueue_style( 'innsight-admin-icons' );
        $fonts_url = INNSIGHT_URL . 'skins/' . $skin . '/assets/fonts/';
        $font_face = sprintf(
            '@font-face{font-family:"Innsight Material Icons";font-style:normal;font-weight:400;font-display:block;'
            . 'src:url(%1$sMaterialIcons.woff2) format("woff2"),'
            . 'url(%1$sMaterialIcons.woff) format("woff"),'
            . 'url(%1$sMaterialIcons.ttf) format("truetype")}'
            . '@font-face{font-family:"Innsight Map Icons";font-style:normal;font-weight:400;font-display:block;'
            . 'src:url(%1$smap.ttf) format("truetype")}'
            . '.innsight-icon-preview{display:inline-flex;align-items:center;justify-content:center;'
            . 'width:36px;height:36px;margin-left:10px;border:1px solid #c3c4c7;border-radius:6px;'
            . 'background:#fff;vertical-align:middle;color:#1d2327;}'
            . '.innsight-icon-preview .in-pin__glyph{font-size:22px !important;line-height:1 !important;'
            . 'font-style:normal !important;font-weight:normal !important;letter-spacing:normal !important;'
            . 'text-transform:none !important;display:inline-block;color:#1d2327;'
            . '-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;}'
            . '.innsight-icon-preview .in-pin__glyph:after{display:inline-block;line-height:1;}',
            esc_url( $fonts_url )
        );
        wp_add_inline_style( 'innsight-admin-icons', $font_face );
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
        add_settings_section( 'innsight_analytics', __( 'Analytics', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_pwa', __( 'PWA (installable web app)', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_geocoder', __( 'Geocoder', 'innsight' ), '__return_false', self::PAGE_SLUG );

        $this->add_field( 'engine_source', __( 'Engine source', 'innsight' ), 'innsight_engine', 'render_engine_source' );
        $this->add_field( 'engine_url', __( 'Custom engine base URL', 'innsight' ), 'innsight_engine', 'render_text', __( 'Required only when "Custom" is selected. The URL must serve the engine directory tree (e.g. /engine/innsight.js).', 'innsight' ) );
        $this->add_field( 'skin_url', __( 'Custom skin base URL', 'innsight' ), 'innsight_engine', 'render_text', __( 'Optional override - if empty, the bundled skin is used.', 'innsight' ) );

        $this->add_field( 'skin_name', __( 'Design', 'innsight' ), 'innsight_design', 'render_skin_radio',
            __( 'Choose which skin renders the map. The new 2026 design needs a Mapbox access token (see below).', 'innsight' ) );
        $this->add_field( 'wordmark_prefix', __( 'Wordmark prefix (client name)', 'innsight' ), 'innsight_design', 'render_text',
            __( 'Shown before "→ Innsight" in the header/list/saved view wordmarks. Example: "Balmers" renders as "Balmers → Innsight". Leave empty for plain "Innsight".', 'innsight' ) );
        $this->add_field( 'base_photo',     __( 'Base photo', 'innsight' ),        'innsight_design', 'render_attachment', __( 'Photo of the hostel / base location. Shown on the map as a taped-photo print above the base pin. Empty = striped placeholder.', 'innsight' ) );
        $this->add_field( 'base_label',     __( 'Base label', 'innsight' ),        'innsight_design', 'render_text',       __( 'Caption on the base marker (e.g. "Balmers"). Empty falls back to the wordmark prefix, then to the POI title.', 'innsight' ) );
        $this->add_field( 'base_rings',     __( 'Ring distances', 'innsight' ),        'innsight_design', 'render_text',   __( 'Comma-separated numbers for the dashed circles around the base. Interpreted by the unit below. e.g. "5,10" with unit=min → 5-min & 10-min walks (80 m / min); "2,5" with unit=km → 2 km + 5 km radii. Max 4 rings. Empty = no rings.', 'innsight' ) );
        $this->add_field( 'base_ring_unit', __( 'Ring unit', 'innsight' ),             'innsight_design', 'render_ring_unit', __( 'How to interpret the numbers above.', 'innsight' ) );
        $this->add_field( 'activities_icon',__( 'Activities icon class', 'innsight' ), 'innsight_design', 'render_icon_class', __( 'Icon class used for every "portfolio" (activities) post. Any md-* / map-* class from skins/innsight2026/assets/icons.css works. Examples: md-directions-run, md-hiking, md-park, map-natural-feature. Preview updates as you type.', 'innsight' ) );
        $this->add_field( 'events_icon',    __( 'Events icon class', 'innsight' ),     'innsight_design', 'render_icon_class', __( 'Icon class used for every "event" post. Any md-*/map-* class works. Default: md-event.', 'innsight' ) );
        $this->add_field( 'base_lat',       __( 'Base latitude',  'innsight' ), 'innsight_design', 'render_text', __( 'Decimal degrees (e.g. 46.6822 for Balmers Hostel). Overrides the pinned/hostel POI + map-center fallback. Leave empty to auto-detect from the POI list.', 'innsight' ) );
        $this->add_field( 'base_lon',       __( 'Base longitude', 'innsight' ), 'innsight_design', 'render_text', __( 'Decimal degrees (e.g. 7.8585 for Balmers Hostel). Overrides the pinned/hostel POI + map-center fallback.', 'innsight' ) );

        $this->add_field( 'provider_default', __( 'Default provider', 'innsight' ), 'innsight_provider', 'render_provider_default' );
        $this->add_field( 'mapbox_access_token', __( 'Mapbox access token', 'innsight' ), 'innsight_provider', 'render_text' );
        $this->add_field( 'mapbox_style_id', __( 'Mapbox style ID', 'innsight' ), 'innsight_provider', 'render_text' );
        $this->add_field( 'google_maps_api_key', __( 'Google Maps API key (v0.2)', 'innsight' ), 'innsight_provider', 'render_text', __( 'Reserved for the Google Maps provider, which lands in v0.2. Save now to skip the second trip.', 'innsight' ) );

        $this->add_field( 'google_places_enable', __( 'Enable Google Places enrichment', 'innsight' ), 'innsight_enrichment', 'render_checkbox' );
        $this->add_field( 'google_places_key', __( 'Google Places API key', 'innsight' ), 'innsight_enrichment', 'render_text', __( 'Stored server-side and never sent to the browser. Enrichment fetches happen through /wp-json/innsight/v1/places with a 30-day cache in a custom table.', 'innsight' ) );
        $this->add_field( 'places_cron_enabled', __( 'Nightly Places cron', 'innsight' ), 'innsight_enrichment', 'render_checkbox', __( 'Runs at 03:00 site time; refreshes up to 25 stale POIs per night so most sheet opens hit a hot cache. Leave off if API quota matters more than latency on first open.', 'innsight' ) );

        $this->add_field( 'render_mode', __( 'Render mode', 'innsight' ), 'innsight_render', 'render_render_mode' );
        $this->add_field( 'default_zoom', __( 'Default zoom level', 'innsight' ), 'innsight_render', 'render_number', __( 'Used when neither the [innsight_map zoom="X"] shortcode attribute nor a per-post "Map Zoom Level" ACF value is set. Range 1-20; typical: 10 = region, 12 = city, 14 = neighbourhood, 16 = street.', 'innsight' ) );
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

        $this->add_field( 'analytics_enabled', __( 'Collect anonymous usage stats', 'innsight' ), 'innsight_analytics', 'render_checkbox', __( 'Tracks map loads, POI opens/saves, and share activity as day-bucketed aggregate counts. No visitor identity, no IP stored, no cookies. Powers the Dashboard widget + Analytics page.', 'innsight' ) );

        $this->add_field( 'pwa_enabled',     __( 'Enable PWA (installable app)', 'innsight' ), 'innsight_pwa', 'render_checkbox', __( 'Serves a manifest.json + service worker + adds the required <head> tags so the site is installable to home-screen. Manifest URL: /innsight-manifest.webmanifest', 'innsight' ) );
        $this->add_field( 'pwa_name',        __( 'App name', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Full name shown on the install prompt. Empty = site title.', 'innsight' ) );
        $this->add_field( 'pwa_short_name',  __( 'Short name', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Under 12 chars, shown under the home-screen icon. Empty = first 12 of site title.', 'innsight' ) );
        $this->add_field( 'pwa_description', __( 'Description', 'innsight' ), 'innsight_pwa', 'render_text' );
        $this->add_field( 'pwa_start_url',   __( 'Start URL', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Where the PWA opens on launch. Empty = homepage. Typically the map page.', 'innsight' ) );
        $this->add_field( 'pwa_scope',       __( 'Scope', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Which URLs the PWA controls. Empty = site root.', 'innsight' ) );
        $this->add_field( 'pwa_theme_color', __( 'Theme color', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Hex (e.g. #FFFFFF). Colours the phone status bar when installed.', 'innsight' ) );
        $this->add_field( 'pwa_bg_color',    __( 'Background color', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Hex. Shown during launch splash before the first paint.', 'innsight' ) );
        $this->add_field( 'pwa_icon_192',    __( 'Icon 192×192 URL', 'innsight' ), 'innsight_pwa', 'render_text', __( 'Empty = bundled default in assets/pwa/img/icon-192.png', 'innsight' ) );
        $this->add_field( 'pwa_icon_512',    __( 'Icon 512×512 URL', 'innsight' ), 'innsight_pwa', 'render_text' );
        $this->add_field( 'pwa_icon_192m',   __( 'Icon 192×192 (maskable) URL', 'innsight' ), 'innsight_pwa', 'render_text' );
        $this->add_field( 'pwa_icon_512m',   __( 'Icon 512×512 (maskable) URL', 'innsight' ), 'innsight_pwa', 'render_text' );
        $this->add_field( 'pwa_apple_touch', __( 'Apple touch icon URL', 'innsight' ), 'innsight_pwa', 'render_text' );

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

        $this->render_places_status_card();

        echo '<form method="post" action="options.php">';
        settings_fields( 'innsight_settings_group' );
        do_settings_sections( self::PAGE_SLUG );
        submit_button();
        echo '</form></div>';
    }

    /**
     * Places enrichment status + debugger card. Sits above the
     * settings form so admins can see:
     *   - Progress bar (fresh / total)
     *   - Attempted / Succeeded / Failed / No-match after the last refresh
     *   - Recent activity table (last 20 rows with error messages)
     *   - "Refresh now" + "Test API key" buttons
     */
    private function render_places_status_card(): void {
        $settings = innsight_settings();
        $enabled  = ! empty( $settings['google_places_enable'] ) && ! empty( $settings['google_places_key'] );
        if ( ! $enabled ) {
            echo '<div style="background:#fcf0f1;border-left:4px solid #d63638;padding:12px 14px;margin:14px 0;max-width:780px">';
            echo '<p style="margin:0"><strong>' . esc_html__( 'Google Places enrichment is off.', 'innsight' ) . '</strong> ';
            echo esc_html__( 'Enable it + paste an API key in the section below to start caching per-POI ratings, hours and reviews.', 'innsight' ) . '</p>';
            echo '</div>';
            return;
        }
        if ( ! class_exists( '\\Innsight\\Places' ) ) return;

        // Post-refresh detailed report.
        if ( isset( $_GET['innsight_places_attempted'] ) ) {
            $att  = (int) $_GET['innsight_places_attempted'];
            $done = (int) ( $_GET['innsight_places_done'] ?? 0 );
            $fail = (int) ( $_GET['innsight_places_failed'] ?? 0 );
            $nmat = (int) ( $_GET['innsight_places_nomatch'] ?? 0 );
            $level = ( $fail > 0 && $done === 0 ) ? 'error' : ( ( $fail > 0 || $nmat > 0 ) ? 'warning' : 'success' );
            echo '<div class="notice notice-' . esc_attr( $level ) . ' is-dismissible"><p><strong>'
                . esc_html__( 'Places refresh complete', 'innsight' ) . ':</strong> '
                . sprintf(
                    esc_html__( 'attempted %1$d, succeeded %2$d, no-match %3$d, failed %4$d.', 'innsight' ),
                    $att, $done, $nmat, $fail
                );
            if ( $fail > 0 ) {
                echo ' <em>' . esc_html__( 'Scroll down to the debugger table to see the error messages.', 'innsight' ) . '</em>';
            }
            echo '</p></div>';
        }

        // Post-test result banner.
        if ( ! empty( $_GET['innsight_places_test'] ) ) {
            $result = get_transient( 'innsight_places_test_result' );
            delete_transient( 'innsight_places_test_result' );
            if ( is_array( $result ) ) {
                $lvl = ! empty( $result['ok'] ) ? 'success' : 'error';
                echo '<div class="notice notice-' . esc_attr( $lvl ) . ' is-dismissible"><p><strong>'
                    . esc_html__( 'API test:', 'innsight' ) . '</strong> ' . esc_html( (string) $result['message'] ) . '</p></div>';
            }
        }

        $places = \Innsight\Plugin::instance()->places();
        $s      = $places->status();
        $refresh_url = wp_nonce_url( admin_url( 'admin-post.php?action=innsight_places_refresh' ), 'innsight_places_refresh' );
        $test_url    = wp_nonce_url( admin_url( 'admin-post.php?action=innsight_places_test' ), 'innsight_places_test' );
        $cron_on     = ! empty( $settings['places_cron_enabled'] );
        $next_cron   = wp_next_scheduled( 'innsight_places_daily' );

        $pct = $s['total'] > 0 ? min( 100, (int) round( 100 * $s['fresh'] / $s['total'] ) ) : 0;

        echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 14px;margin:14px 0;max-width:920px">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'Google Places enrichment status', 'innsight' ) . '</strong></p>';

        // Progress bar.
        echo '<div style="background:#e0e0e2;border-radius:4px;height:8px;overflow:hidden;margin:8px 0">';
        echo '<div style="background:#2271b1;height:100%;width:' . (int) $pct . '%"></div>';
        echo '</div>';

        // Colour-coded counts.
        $missing = max( 0, $s['total'] - $s['cached'] - $s['errored'] );
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin:8px 0 12px">';
        $this->render_count_tile( __( 'Total POIs', 'innsight' ), (int) $s['total'], '#1e1e1e' );
        $this->render_count_tile( __( 'Fresh (<30d)', 'innsight' ), (int) $s['fresh'], '#00a32a' );
        $this->render_count_tile( __( 'Stale (>30d)', 'innsight' ), (int) $s['stale'], '#dba617' );
        $this->render_count_tile( __( 'Never fetched', 'innsight' ), (int) $missing, '#646970' );
        $this->render_count_tile( __( 'Errored', 'innsight' ), (int) $s['errored'], '#d63638' );
        echo '</div>';

        if ( $s['last_fetch'] ) {
            $last_ago = human_time_diff( strtotime( $s['last_fetch'] . ' UTC' ), time() );
            echo '<p style="margin:0 0 6px;font-size:12px;color:#646970">'
                . sprintf( esc_html__( 'Last successful fetch: %s ago.', 'innsight' ), esc_html( $last_ago ) )
                . '</p>';
        }
        if ( $cron_on ) {
            $next = $next_cron ? human_time_diff( time(), $next_cron ) : __( 'unscheduled', 'innsight' );
            echo '<p style="margin:0 0 8px;font-size:12px;color:#646970">'
                . sprintf( esc_html__( 'Nightly cron on - next run in %s (refreshes up to 25 stale POIs per night).', 'innsight' ), esc_html( $next ) )
                . '</p>';
        } else {
            echo '<p style="margin:0 0 8px;font-size:12px;color:#646970">'
                . esc_html__( 'Nightly cron off - enable in the Google Places section below to auto-refresh in the background.', 'innsight' )
                . '</p>';
        }

        // Buttons.
        $remaining = max( 0, $s['total'] - $s['fresh'] );
        if ( $remaining > 0 ) {
            echo '<a href="' . esc_url( $refresh_url ) . '" class="button button-primary" style="margin-right:6px">'
                . sprintf( esc_html__( 'Refresh next %d POIs now', 'innsight' ), min( 25, $remaining ) )
                . '</a>';
        } else {
            echo '<span style="color:#00a32a;font-weight:600;margin-right:12px">' . esc_html__( 'All POIs fresh. Nothing to refresh.', 'innsight' ) . '</span>';
        }
        echo '<a href="' . esc_url( $test_url ) . '" class="button">' . esc_html__( 'Test API key', 'innsight' ) . '</a>';
        echo ' <span style="color:#646970;font-size:12px;margin-left:8px">' . esc_html__( 'Pings Places API with a known query to verify your key + billing.', 'innsight' ) . '</span>';

        // ─ Recent activity debugger ─
        $activity = $places->recent_activity( 20 );
        if ( ! empty( $activity ) ) {
            echo '<details style="margin-top:16px" ' . ( ( $s['errored'] > 0 || ( isset( $_GET['innsight_places_failed'] ) && (int) $_GET['innsight_places_failed'] > 0 ) ) ? 'open' : '' ) . '>';
            echo '<summary style="cursor:pointer;font-weight:600;font-size:13px">' . esc_html__( 'Recent activity (last 20 rows)', 'innsight' ) . '</summary>';
            echo '<table class="wp-list-table widefat striped" style="margin-top:8px">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'POI id', 'innsight' ) . '</th>';
            echo '<th>' . esc_html__( 'Fetched', 'innsight' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'innsight' ) . '</th>';
            echo '<th>' . esc_html__( 'Google place id / error', 'innsight' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $activity as $row ) {
                $when = $row['fetched_at'] ? human_time_diff( strtotime( $row['fetched_at'] . ' UTC' ), time() ) . ' ago' : '—';
                if ( $row['has_data'] ) {
                    $status_html = '<span style="color:#00a32a;font-weight:600">' . esc_html__( 'OK', 'innsight' ) . '</span>';
                    $detail_html = $row['place_id'] !== '' ? '<code style="font-size:11px">' . esc_html( $row['place_id'] ) . '</code>' : '—';
                } else {
                    $err = $row['error'] ?? 'unknown';
                    if ( $err === 'no_match' ) {
                        $status_html = '<span style="color:#dba617;font-weight:600">' . esc_html__( 'No match', 'innsight' ) . '</span>';
                        $detail_html = '<em style="color:#646970">' . esc_html__( 'Google returned no place for the POI title / location.', 'innsight' ) . '</em>';
                    } else {
                        $status_html = '<span style="color:#d63638;font-weight:600">' . esc_html__( 'ERROR', 'innsight' ) . '</span>';
                        $detail_html = '<code style="font-size:11px;color:#d63638">' . esc_html( $err ) . '</code>';
                    }
                }
                echo '<tr>';
                echo '<td><code style="font-size:11px">' . esc_html( $row['poi_id'] ) . '</code></td>';
                echo '<td>' . esc_html( $when ) . '</td>';
                echo '<td>' . $status_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above
                echo '<td>' . $detail_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</details>';
        }

        echo '</div>';
    }

    private function render_count_tile( string $label, int $value, string $accent ): void {
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 10px">';
        echo '<div style="font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#646970">' . esc_html( $label ) . '</div>';
        echo '<div style="font-size:20px;font-weight:600;line-height:1.1;margin-top:2px;color:' . esc_attr( $accent ) . ';font-variant-numeric:tabular-nums">' . (int) $value . '</div>';
        echo '</div>';
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

    public function render_ring_unit( string $key, $value ): void {
        $opts = array(
            'min' => __( 'Minutes walking (80 m / min)', 'innsight' ),
            'km'  => __( 'Kilometres', 'innsight' ),
            'm'   => __( 'Metres', 'innsight' ),
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

    /**
     * Icon-class input with a live glyph preview + a "browse" link
     * to the icon reference. The preview element mirrors the input
     * value as an added class on `.in-pin__glyph`, so any md-* /
     * map-* class from the enqueued icons.css renders as its actual
     * glyph via the :after codepoint.
     */
    public function render_icon_class( string $key, $value ): void {
        $input_name = self::OPTION_NAME . '[' . $key . ']';
        $input_id   = 'innsight-icon-input-' . $key;
        $preview_id = 'innsight-icon-preview-' . $key;
        $val        = (string) $value;
        printf(
            '<input type="text" class="regular-text innsight-icon-input" id="%1$s" data-preview-target="%2$s" name="%3$s" value="%4$s" placeholder="md-restaurant" />',
            esc_attr( $input_id ),
            esc_attr( $preview_id ),
            esc_attr( $input_name ),
            esc_attr( $val )
        );
        printf(
            '<span class="innsight-icon-preview" id="%1$s" aria-hidden="true"><i class="in-pin__glyph %2$s"></i></span>',
            esc_attr( $preview_id ),
            esc_attr( $val )
        );
        // Inline the mirror-script once per page. Guard with a static
        // flag so multiple icon fields don't emit duplicate scripts.
        static $printed_script = false;
        if ( ! $printed_script ) {
            $printed_script = true;
            echo '<script>(function(){document.addEventListener("input",function(e){var el=e.target;if(!el.classList||!el.classList.contains("innsight-icon-input"))return;var tgt=document.getElementById(el.dataset.previewTarget);if(!tgt)return;var i=tgt.querySelector("i");if(!i)return;i.className="in-pin__glyph "+el.value.trim();});})();</script>';
        }
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

    /**
     * Attachment (image) picker. Uses the WP Media Library popup;
     * stores the numeric attachment ID. Preview + Choose/Change/Clear
     * inline. Enqueues wp.media on the settings page.
     */
    public function render_attachment( string $key, $value ): void {
        wp_enqueue_media();
        $id  = (int) $value;
        $url = $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
        $name_attr = esc_attr( self::OPTION_NAME . '[' . $key . ']' );
        $dom_id    = 'innsight-attach-' . esc_attr( $key );
        ?>
        <div class="innsight-attach-picker" data-picker-id="<?php echo esc_attr( $dom_id ); ?>">
            <input type="hidden" id="<?php echo esc_attr( $dom_id ); ?>" name="<?php echo $name_attr; // phpcs:ignore ?>" value="<?php echo esc_attr( (string) $id ); ?>">
            <div class="innsight-attach-preview" style="margin-bottom:6px">
                <?php if ( $url ) : ?>
                    <img src="<?php echo esc_url( $url ); ?>" style="max-width:180px;max-height:120px;border:1px solid #dcdcde;border-radius:4px;display:block">
                <?php else : ?>
                    <em style="color:#646970">No image selected.</em>
                <?php endif; ?>
            </div>
            <button type="button" class="button innsight-attach-choose"><?php echo $id ? esc_html__( 'Change image', 'innsight' ) : esc_html__( 'Choose image', 'innsight' ); ?></button>
            <button type="button" class="button innsight-attach-clear" <?php disabled( ! $id ); ?>><?php esc_html_e( 'Clear', 'innsight' ); ?></button>
        </div>
        <script>
        (function(){
            var wrap = document.querySelector('.innsight-attach-picker[data-picker-id="<?php echo esc_js( $dom_id ); ?>"]');
            if (!wrap || wrap.__innsightBound) return;
            wrap.__innsightBound = true;
            var input = wrap.querySelector('input[type=hidden]');
            var preview = wrap.querySelector('.innsight-attach-preview');
            var chooseBtn = wrap.querySelector('.innsight-attach-choose');
            var clearBtn  = wrap.querySelector('.innsight-attach-clear');
            var frame;
            chooseBtn.addEventListener('click', function () {
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Choose image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    input.value = att.id;
                    var url = (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url);
                    preview.innerHTML = '<img src="' + url + '" style="max-width:180px;max-height:120px;border:1px solid #dcdcde;border-radius:4px;display:block">';
                    chooseBtn.textContent = 'Change image';
                    clearBtn.disabled = false;
                });
                frame.open();
            });
            clearBtn.addEventListener('click', function () {
                input.value = '';
                preview.innerHTML = '<em style="color:#646970">No image selected.</em>';
                chooseBtn.textContent = 'Choose image';
                clearBtn.disabled = true;
            });
        })();
        </script>
        <?php
    }
}
