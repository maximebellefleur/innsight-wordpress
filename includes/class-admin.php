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
        add_options_page(
            __( 'Innsight', 'innsight' ),
            __( 'Innsight', 'innsight' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
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
        add_settings_section( 'innsight_enrichment', __( 'Google Places enrichment', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_render', __( 'Render mode & UI', 'innsight' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'innsight_geocoder', __( 'Geocoder', 'innsight' ), '__return_false', self::PAGE_SLUG );

        $this->add_field( 'engine_source', __( 'Engine source', 'innsight' ), 'innsight_engine', 'render_engine_source' );
        $this->add_field( 'engine_url', __( 'Custom engine base URL', 'innsight' ), 'innsight_engine', 'render_text', __( 'Required only when "Custom" is selected. The URL must serve the engine directory tree (e.g. /engine/innsight.js).', 'innsight' ) );
        $this->add_field( 'skin_url', __( 'Custom skin base URL', 'innsight' ), 'innsight_engine', 'render_text', __( 'Optional override - if empty, the bundled skin is used.', 'innsight' ) );
        $this->add_field( 'skin_name', __( 'Skin name', 'innsight' ), 'innsight_engine', 'render_text' );

        $this->add_field( 'provider_default', __( 'Default provider', 'innsight' ), 'innsight_provider', 'render_provider_default' );
        $this->add_field( 'mapbox_access_token', __( 'Mapbox access token', 'innsight' ), 'innsight_provider', 'render_text' );
        $this->add_field( 'mapbox_style_id', __( 'Mapbox style ID', 'innsight' ), 'innsight_provider', 'render_text' );
        $this->add_field( 'google_maps_api_key', __( 'Google Maps API key (v0.2)', 'innsight' ), 'innsight_provider', 'render_text', __( 'Reserved for the Google Maps provider, which lands in v0.2. Save now to skip the second trip.', 'innsight' ) );

        $this->add_field( 'google_places_enable', __( 'Enable Google Places enrichment', 'innsight' ), 'innsight_enrichment', 'render_checkbox' );
        $this->add_field( 'google_places_key', __( 'Google Places API key', 'innsight' ), 'innsight_enrichment', 'render_text' );

        $this->add_field( 'render_mode', __( 'Render mode', 'innsight' ), 'innsight_render', 'render_render_mode' );
        $this->add_field( 'kml_export', __( 'Show KML download button', 'innsight' ), 'innsight_render', 'render_checkbox' );
        $this->add_field( 'solo_mode', __( 'Solo Mode toggle available', 'innsight' ), 'innsight_render', 'render_checkbox' );

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
        echo '<div class="wrap"><h1>' . esc_html__( 'Innsight', 'innsight' ) . '</h1>';
        echo '<p>' . esc_html__( 'Configure how the Innsight map engine renders inside this site. The plugin reads existing yuna-innsight DB structures (POI taxonomy, portfolio activities, ACF options) without modification.', 'innsight' ) . '</p>';
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

    public function render_provider_default( string $key, $value ): void {
        $opts = array(
            'osm'    => __( 'OpenStreetMap', 'innsight' ),
            'mapbox' => __( 'Mapbox', 'innsight' ),
            'google' => __( 'Google Maps (v0.2 stub)', 'innsight' ),
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
}
