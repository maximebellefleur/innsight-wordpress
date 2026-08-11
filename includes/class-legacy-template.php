<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * LegacyTemplate - registers the Map Page template + intercepts the
 * yuna-innsight template path so existing WP pages that stored
 * `_wp_page_template = /wp-content/plugins/yuna-innsight/page-map.php`
 * keep rendering after the old plugin is deleted.
 *
 * Three hooks:
 *   1. `theme_page_templates` (admin dropdown) - adds our template
 *      as a selectable option on the Page Attributes > Template
 *      menu.
 *   2. `template_include` - intercepts BEFORE WP tries to include a
 *      template. If the current page's stored template is either our
 *      new plugin path OR the legacy yuna-innsight path, we return
 *      our own file. Prevents the "template file doesn't exist ->
 *      fall back to page.php" collapse that showed the visitor an
 *      empty WP page.
 *   3. Optional migration action `admin_post_innsight_migrate_map_template`
 *      re-points every page whose meta references the legacy path
 *      to our new path. One-click cleanup for admins.
 */
final class LegacyTemplate {

    private const TEMPLATE_REL   = 'templates/page-map.php';
    private const LABEL          = 'Map Page (Innsight fullscreen)';
    private const LEGACY_SUFFIX  = '/yuna-innsight/page-map.php';

    public function register(): void {
        add_filter( 'theme_page_templates', array( $this, 'add_template_option' ) );
        add_filter( 'template_include',     array( $this, 'maybe_load_template' ), 99 );
        add_action( 'admin_post_innsight_migrate_map_template', array( $this, 'handle_migrate' ) );
    }

    public function template_path(): string {
        return INNSIGHT_PATH . self::TEMPLATE_REL;
    }

    public function template_slug(): string {
        return self::TEMPLATE_REL;
    }

    /**
     * Add our template to the Page Attributes > Template dropdown so
     * new pages can pick it via the admin UI.
     */
    public function add_template_option( array $templates ): array {
        $templates[ self::TEMPLATE_REL ] = self::LABEL;
        // Also register the legacy path as a synonym so if an admin
        // has an existing page pointing at the yuna path, our option
        // appears highlighted rather than "template missing".
        $templates[ 'yuna-innsight/page-map.php' ] = self::LABEL . ' (legacy path)';
        return $templates;
    }

    /**
     * Intercept template selection. Runs at priority 99 so we win over
     * theme filters. Matches BOTH the new plugin path AND the legacy
     * yuna-innsight path pattern.
     */
    public function maybe_load_template( $template ) {
        if ( ! is_singular() ) return $template;

        $slug = get_page_template_slug( get_queried_object_id() );
        if ( ! $slug ) return $template;

        // New plugin path.
        if ( $slug === self::TEMPLATE_REL ) {
            return $this->template_path();
        }
        // Legacy yuna path - either the full server path
        // ("/full/wp-content/plugins/yuna-innsight/page-map.php") or
        // the plugin-relative slug.
        if ( substr( (string) $slug, -strlen( self::LEGACY_SUFFIX ) ) === self::LEGACY_SUFFIX
             || $slug === 'yuna-innsight/page-map.php' ) {
            return $this->template_path();
        }
        return $template;
    }

    /**
     * One-click migration button for the admin: re-point every page
     * whose _wp_page_template meta references the legacy path to our
     * new relative slug. Returns count via the flash redirect.
     */
    public function handle_migrate(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        check_admin_referer( 'innsight_migrate_map_template' );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND meta_value LIKE '%yuna-innsight/page-map.php'",
            ARRAY_A
        );
        $done = 0;
        foreach ( (array) $rows as $r ) {
            update_post_meta( (int) $r['post_id'], '_wp_page_template', self::TEMPLATE_REL );
            $done++;
        }
        wp_safe_redirect( add_query_arg( 'innsight_migrated', (int) $done, wp_get_referer() ?: admin_url( 'admin.php?page=innsight' ) ) );
        exit;
    }
}
