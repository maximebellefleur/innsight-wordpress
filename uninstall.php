<?php
/**
 * Innsight - uninstall script. Removes plugin options and transients.
 *
 * Runs only when the user explicitly deletes the plugin from the WordPress UI
 * (not on plugin deactivation). All Innsight-specific data is cleaned up; the
 * existing yuna-innsight DB structures (POI taxonomy, portfolio posts, ACF
 * options) are left intact - they belong to the host site, not to this plugin.
 *
 * @package Innsight
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'innsight_settings' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_innsight\\_%' OR option_name LIKE '_transient_timeout_innsight\\_%'" );
