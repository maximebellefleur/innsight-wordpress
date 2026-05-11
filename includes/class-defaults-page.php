<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * DefaultsPage - admin editor for the legacy "Yuna InnSight Settings"
 * options-page values, exposed under Settings > Innsight Defaults.
 *
 * The legacy plugin stored these values via ACF in `wp_options` under
 * `options_<field_name>`. Once the legacy plugin is deactivated, ACF's
 * field-group registration disappears and so does the admin UI - but the
 * underlying option rows persist. This class provides a small editor that
 * reads from + writes back to the exact same keys, so a migrated site can
 * keep editing the hostel defaults from a UI the new plugin owns.
 *
 * Each field is registered with the WP Settings API (`register_setting`) so
 * sanitization, capability checks, and the standard "Settings saved" notice
 * all work for free.
 */
final class DefaultsPage {

    public const PAGE_SLUG  = 'innsight-defaults';
    public const OPT_GROUP  = 'innsight_defaults_group';

    /**
     * Map of option_name => field spec. Option names are the literal keys in
     * `wp_options`, matching what ACF wrote when legacy was installed.
     */
    private const FIELDS = array(
        'options_maps_titre'         => array( 'label' => 'Default site / hostel name',  'type' => 'text',     'sanitize' => 'sanitize_text_field' ),
        'options_maps_latitude'      => array( 'label' => 'Default latitude',            'type' => 'number',   'sanitize' => '\\Innsight\\DefaultsPage::sanitize_float' ),
        'options_maps_longitude'     => array( 'label' => 'Default longitude',           'type' => 'number',   'sanitize' => '\\Innsight\\DefaultsPage::sanitize_float' ),
        'options_maps_text'          => array( 'label' => 'Default description (HTML)',  'type' => 'textarea', 'sanitize' => 'wp_kses_post' ),
        'options_maps_more_info_url' => array( 'label' => 'Default action button URL',   'type' => 'url',      'sanitize' => 'esc_url_raw' ),
        'options_maps_bg_img'        => array( 'label' => 'Default image (URL or attachment ID)', 'type' => 'text', 'sanitize' => 'sanitize_text_field' ),
    );

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_menu(): void {
        add_submenu_page(
            'options-general.php',
            __( 'Innsight Defaults', 'innsight' ),
            __( 'Innsight Defaults', 'innsight' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function register_settings(): void {
        foreach ( self::FIELDS as $option_name => $spec ) {
            register_setting( self::OPT_GROUP, $option_name, array(
                'type'              => 'string',
                'sanitize_callback' => $spec['sanitize'],
                'default'           => '',
            ) );
        }

        add_settings_section(
            'innsight_defaults_main',
            __( 'Site-wide map defaults', 'innsight' ),
            function () {
                echo '<p>' . esc_html__( 'These values feed the map\'s default center and the always-appended "default hostel" marker. They share the same wp_options keys ACF used when the legacy yuna-innsight plugin was active, so editing here writes to exactly the same place.', 'innsight' ) . '</p>';
            },
            self::PAGE_SLUG
        );

        foreach ( self::FIELDS as $option_name => $spec ) {
            add_settings_field(
                $option_name,
                esc_html( $spec['label'] ),
                function () use ( $option_name, $spec ) { $this->render_input( $option_name, $spec ); },
                self::PAGE_SLUG,
                'innsight_defaults_main'
            );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<div class="wrap"><h1>' . esc_html__( 'Innsight - Map defaults', 'innsight' ) . '</h1>';

        if ( LegacyCompat::looks_like_legacy_install() ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Detected legacy yuna-innsight data (POI terms or hostel defaults). All values below are read from and written to the same wp_options keys ACF used. Editing here is safe even if the legacy plugin is currently active - the plugins read the same data.', 'innsight' ) . '</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields( self::OPT_GROUP );
        do_settings_sections( self::PAGE_SLUG );
        submit_button();
        echo '</form></div>';
    }

    private function render_input( string $option_name, array $spec ): void {
        $value = get_option( $option_name, '' );
        $name = esc_attr( $option_name );
        switch ( $spec['type'] ) {
            case 'textarea':
                echo '<textarea name="' . $name . '" rows="4" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
                break;
            case 'number':
                echo '<input type="text" inputmode="decimal" class="regular-text" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
                break;
            case 'url':
                echo '<input type="url" class="regular-text" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
                break;
            default:
                echo '<input type="text" class="regular-text" name="' . $name . '" value="' . esc_attr( (string) $value ) . '" />';
        }
    }

    public static function sanitize_float( $value ): string {
        if ( $value === null || $value === '' ) return '';
        $clean = str_replace( array( ' ', ',' ), array( '', '.' ), trim( (string) $value ) );
        return is_numeric( $clean ) ? (string) (float) $clean : '';
    }
}
