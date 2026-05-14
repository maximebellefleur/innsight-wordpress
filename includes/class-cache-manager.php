<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * CacheManager - centralizes "make stale assets disappear" across every cache
 * layer we know how to talk to.
 *
 * Why this exists: the partials (layout.html, sheet.html, pin.html, etc.) are
 * inlined into the page HTML. When the host has a page cache that bypasses
 * for logged-in users only - WP Engine / Kinsta NGINX, FastCGI cache, edge
 * caches behind Cloudflare APO - logged-out visitors get HTML that was
 * generated before the upgrade. Our skin.js (always served fresh thanks to
 * filemtime on the asset URL) then runs against an older DOM hook contract
 * and features misfire ("Save doesn't work, half the buttons missing").
 *
 * On every plugin update we:
 *   - delete our own partials transients
 *   - call the major page-cache plugins' flush APIs (no hard dependency)
 *   - call host-specific flush APIs when present (WP Engine, Kinsta, SiteGround)
 *   - bust Cloudflare via CF_API_KEY env or the cf_credentials filter (opt-in)
 *
 * Also exposes purge_now() for the admin "Purge caches now" button.
 */
final class CacheManager {

    public function register(): void {
        // Auto-purge whenever the plugin is upgraded through the WP updater
        // (zip upload, WP-CLI plugin install --force, GitHub Updater, etc).
        add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
        // Also purge on activation in case the upgrader hook didn't fire
        // (manual re-zip, WP-CLI plugin activate after manual upload).
        add_action( 'activated_plugin', array( $this, 'on_plugin_change' ) );
        add_action( 'admin_post_innsight_purge_caches', array( $this, 'handle_admin_purge' ) );
    }

    /**
     * upgrader_process_complete fires for ALL upgrades. We narrow to ours so
     * a Yoast update (e.g.) doesn't blow our work cache.
     */
    public function on_upgrader_complete( $upgrader, array $hook_extra ): void {
        if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
            return;
        }
        $plugins = (array) ( $hook_extra['plugins'] ?? array() );
        $self    = plugin_basename( INNSIGHT_FILE );
        if ( ! in_array( $self, $plugins, true ) ) {
            return;
        }
        $this->purge_now();
    }

    public function on_plugin_change( string $plugin ): void {
        if ( $plugin === plugin_basename( INNSIGHT_FILE ) ) {
            $this->purge_now();
        }
    }

    /**
     * Admin handler for the "Purge caches now" button. Verifies nonce + cap
     * then redirects back with a success notice.
     */
    public function handle_admin_purge(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        }
        check_admin_referer( 'innsight_purge_caches' );
        $this->purge_now();
        wp_safe_redirect( add_query_arg( 'innsight_purged', '1', wp_get_referer() ?: admin_url( 'admin.php?page=innsight' ) ) );
        exit;
    }

    /**
     * Drop EVERY innsight transient + ask any cache layer we recognise to
     * flush. Safe to call repeatedly. Does NOT touch WordPress core caches
     * unrelated to our content.
     */
    public function purge_now(): void {
        global $wpdb;

        // Nuke our own partial cache transients (keyed by skin + version +
        // file mtimes). We don't know all the keys, so wildcard-delete from
        // the options table.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_innsight_partials_%' OR option_name LIKE '_transient_timeout_innsight_partials_%' OR option_name LIKE '_transient_innsight_geocode_%' OR option_name LIKE '_transient_timeout_innsight_geocode_%'" );

        // Object cache: if Redis/Memcached is in use, delete by group.
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'innsight' );
        }

        // -------- WordPress page-cache plugins --------
        // Each block is guarded so missing plugins don't fatal.
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();           // WP Rocket
        }
        if ( function_exists( 'w3tc_pgcache_flush' ) ) {
            w3tc_pgcache_flush();            // W3 Total Cache
        }
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();          // WP Super Cache
        }
        if ( has_action( 'litespeed_purge_all' ) ) {
            do_action( 'litespeed_purge_all' );  // LiteSpeed Cache
        }
        if ( class_exists( '\\Hummingbird\\WP_Hummingbird' ) ) {
            do_action( 'wphb_clear_page_cache' );
        }
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache();     // SG Optimizer (SiteGround)
        }
        if ( class_exists( '\\Cache_Enabler' ) && method_exists( '\\Cache_Enabler', 'clear_complete_cache' ) ) {
            \Cache_Enabler::clear_complete_cache();
        }
        if ( class_exists( '\\WpFastestCache' ) ) {
            $wpfc = new \WpFastestCache();
            if ( method_exists( $wpfc, 'deleteCache' ) ) $wpfc->deleteCache( true );
        }
        if ( function_exists( 'breeze_clear_all_cache' ) ) {
            breeze_clear_all_cache();        // Breeze (Cloudways)
        }
        if ( function_exists( 'autoptimize_flush_pagecache' ) ) {
            autoptimize_flush_pagecache();   // Autoptimize bridge
        }

        // -------- Managed-host edge caches --------
        if ( class_exists( '\\WpeCommon' ) && method_exists( '\\WpeCommon', 'purge_varnish_cache' ) ) {
            // WP Engine - both their NGINX page cache and front Varnish.
            \WpeCommon::purge_varnish_cache();
            if ( method_exists( '\\WpeCommon', 'purge_memcached' ) ) {
                \WpeCommon::purge_memcached();
            }
        }
        if ( class_exists( '\\Kinsta\\Cache' ) ) {
            // Kinsta MU plugin - hitting their internal purge action.
            do_action( 'kinsta_cache_purge_full' );
        }
        if ( has_action( 'pantheon-clear-page-cache' ) ) {
            do_action( 'pantheon-clear-page-cache' );  // Pantheon
        }

        // -------- NGINX FastCGI helper plugins --------
        if ( has_action( 'nginx_helper_purge_all' ) ) {
            do_action( 'nginx_helper_purge_all' );
        }

        /**
         * Fires after Innsight clears its own + known page caches. Hosts
         * with custom edge caches (Cloudflare Workers, custom Varnish)
         * can hook here to flush their own layer.
         *
         * @since 0.5.2
         */
        do_action( 'innsight/cache_purged' );
    }
}
