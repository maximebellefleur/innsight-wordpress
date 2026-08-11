<?php
/**
 * Plugin Name:       Innsight
 * Plugin URI:        https://github.com/maximebellefleur/innsight-wordpress
 * Description:       Renders the Innsight map engine inside WordPress. Reads the existing yuna-innsight DB structures (POI taxonomy, portfolio activities, event posts, options-page defaults) and emits a v1 JSON config for the Innsight JS engine. Provides a [innsight_map] shortcode (and a [custom_map] alias) plus a /wp-json/innsight/v1/map REST endpoint.
 * Version:           0.7.11
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Maxime Bellefleur / Solike Group
 * Author URI:        https://solikegroup.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       innsight
 * Domain Path:       /languages
 *
 * @package Innsight
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'INNSIGHT_VERSION' ) ) {
    return;
}

define( 'INNSIGHT_VERSION', '0.7.11' );
define( 'INNSIGHT_FILE', __FILE__ );
define( 'INNSIGHT_PATH', plugin_dir_path( __FILE__ ) );
define( 'INNSIGHT_URL', plugin_dir_url( __FILE__ ) );
define( 'INNSIGHT_MIN_PHP', '7.4' );
define( 'INNSIGHT_MIN_WP', '6.0' );

if ( version_compare( PHP_VERSION, INNSIGHT_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', static function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html( sprintf(
                /* translators: %1$s required PHP version, %2$s actual PHP version */
                __( 'Innsight requires PHP %1$s or newer. Your server is running %2$s.', 'innsight' ),
                INNSIGHT_MIN_PHP,
                PHP_VERSION
            ) )
        );
    } );
    return;
}

require_once INNSIGHT_PATH . 'includes/helpers.php';

spl_autoload_register( static function ( $class ) {
    if ( strpos( $class, 'Innsight\\' ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( 'Innsight\\' ) );
    $relative = str_replace( '\\', '/', $relative );
    $file     = INNSIGHT_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', preg_replace( '/(?<!^)([A-Z])/', '-$1', $relative ) ) ) . '.php';
    if ( is_readable( $file ) ) {
        require_once $file;
    }
} );

add_action( 'plugins_loaded', static function () {
    \Innsight\Plugin::instance()->boot();
} );

register_activation_hook( __FILE__, static function () {
    add_option( 'innsight_settings', \Innsight\Settings::defaults(), '', 'no' );
    // Install / upgrade the analytics table (dbDelta handles both).
    if ( class_exists( '\\Innsight\\Stats' ) ) {
        \Innsight\Stats::install();
    }
    // Install / upgrade the Google Places cache table.
    if ( class_exists( '\\Innsight\\Places' ) ) {
        \Innsight\Places::install();
    }
    // Register the PWA rewrite rules THEN flush so
    // /innsight-manifest.webmanifest + /innsight-sw.js resolve
    // without the admin having to visit Settings > Permalinks.
    if ( class_exists( '\\Innsight\\Pwa' ) ) {
        \Innsight\Pwa::activate();
    } else {
        flush_rewrite_rules();
    }
    // Purge our partial cache transients on activation so a manual
    // re-zip / FTP upload that preserves file mtimes doesn't leave
    // visitors looking at stale partials. The CacheManager class also
    // hooks `upgrader_process_complete`, but activation is the catch-
    // all for paths the WP upgrader didn't run.
    if ( class_exists( '\\Innsight\\CacheManager' ) ) {
        ( new \Innsight\CacheManager() )->purge_now();
    }
} );

register_deactivation_hook( __FILE__, static function () {
    flush_rewrite_rules();
    // Clear any scheduled Places refresh events so a deactivated
    // plugin doesn't keep firing empty cron ticks.
    wp_clear_scheduled_hook( 'innsight_places_daily' );
    wp_clear_scheduled_hook( 'innsight_places_refresh_one' );
} );
