<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Pwa - progressive web app support (manifest + service worker + head tags).
 *
 * Replaces the PWA plumbing yuna-innsight used to own. Serves a
 * DYNAMIC manifest.json from a pretty URL (`/innsight-manifest.webmanifest`)
 * built from admin-configurable settings, plus a scope-broadened
 * service worker at `/innsight-sw.js`. Both go through WP rewrites so
 * we can attach the right response headers (Service-Worker-Allowed: /
 * is required to let the SW installed from /wp-content/... claim
 * scope over the whole site).
 *
 * Icons: sensible defaults ship in `assets/pwa/img/`; admins can
 * override each icon size via WP Media Library uploads on the
 * Settings page.
 */
final class Pwa {

    private const MANIFEST_QV = 'innsight_manifest';
    private const SW_QV       = 'innsight_sw';

    public function register(): void {
        add_action( 'init', array( $this, 'add_rewrites' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        // template_redirect fires AFTER WP parses the request but
        // BEFORE any template is loaded - perfect for short-circuiting
        // into our raw manifest / SW output.
        add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );
        add_action( 'wp_head', array( $this, 'render_head_tags' ), 2 );
        add_action( 'wp_footer', array( $this, 'render_sw_registrar' ), 99 );

        // Legacy-URL shim for installed PWAs. After yuna-innsight is
        // deleted the browser still fetches its cached SW URL etc; we
        // intercept those paths and serve the same output as our new
        // rewrite endpoints so installed PWAs auto-swap the SW next
        // time the browser polls (browsers re-check SW URLs every
        // ~24h or on every navigation, whichever comes first).
        add_action( 'plugins_loaded', array( $this, 'maybe_serve_legacy_pwa' ), 0 );
    }

    /* ─── URL routing ─────────────────────────────────────────────────────── */

    public function add_rewrites(): void {
        add_rewrite_rule( '^innsight-manifest\\.webmanifest$', 'index.php?' . self::MANIFEST_QV . '=1', 'top' );
        add_rewrite_rule( '^innsight-sw\\.js$',               'index.php?' . self::SW_QV . '=1',       'top' );
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = self::MANIFEST_QV;
        $vars[] = self::SW_QV;
        return $vars;
    }

    public function maybe_serve(): void {
        if ( get_query_var( self::MANIFEST_QV ) ) $this->serve_manifest();
        if ( get_query_var( self::SW_QV ) )       $this->serve_sw();
    }

    /* ─── Manifest ────────────────────────────────────────────────────────── */

    public function serve_manifest(): void {
        $s = innsight_settings();
        $data = array(
            'name'             => (string) ( $s['pwa_name']       ?? '' ) ?: (string) get_bloginfo( 'name' ),
            'short_name'       => (string) ( $s['pwa_short_name'] ?? '' ) ?: mb_substr( (string) get_bloginfo( 'name' ), 0, 12 ),
            'description'      => (string) ( $s['pwa_description'] ?? '' ),
            'start_url'        => (string) ( $s['pwa_start_url']  ?? '' ) ?: home_url( '/' ),
            'scope'            => (string) ( $s['pwa_scope']      ?? '' ) ?: home_url( '/' ),
            'display'          => 'standalone',
            'display_override' => array( 'fullscreen', 'minimal-ui' ),
            'orientation'      => 'portrait',
            'theme_color'      => (string) ( $s['pwa_theme_color'] ?? '' ) ?: '#FFFFFF',
            'background_color' => (string) ( $s['pwa_bg_color']    ?? '' ) ?: '#FFFFFF',
            'id'               => 'innsight-' . md5( home_url( '/' ) ),
            'icons'            => $this->build_icons(),
        );

        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Icon set. Each entry: { src, sizes, type, purpose? }. Prefers
     * the admin-uploaded URL for each size, falls back to the bundled
     * defaults in assets/pwa/img/.
     */
    private function build_icons(): array {
        $s = innsight_settings();
        $base = trailingslashit( INNSIGHT_URL . 'assets/pwa/img' );
        $icons = array();
        $icons[] = array( 'src' => (string) ( $s['pwa_icon_192']  ?? '' ) ?: $base . 'icon-192.png',          'sizes' => '192x192', 'type' => 'image/png' );
        $icons[] = array( 'src' => (string) ( $s['pwa_icon_512']  ?? '' ) ?: $base . 'icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' );
        $icons[] = array( 'src' => (string) ( $s['pwa_icon_192m'] ?? '' ) ?: $base . 'icon-192-maskable.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable' );
        $icons[] = array( 'src' => (string) ( $s['pwa_icon_512m'] ?? '' ) ?: $base . 'icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' );
        return $icons;
    }

    /* ─── Service worker ──────────────────────────────────────────────────── */

    public function serve_sw(): void {
        $sw = INNSIGHT_PATH . 'assets/pwa/sw.js';
        if ( ! is_readable( $sw ) ) {
            status_header( 404 );
            exit;
        }
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Service-Worker-Allowed: /' );          // MUST match the scope: '/' in the registrar
        header( 'Cache-Control: no-cache, must-revalidate' );
        readfile( $sw );
        exit;
    }

    /* ─── HTML head + footer ─────────────────────────────────────────────── */

    public function render_head_tags(): void {
        if ( empty( innsight_settings( 'pwa_enabled', 1 ) ) ) return;

        $manifest_url = home_url( '/innsight-manifest.webmanifest' );
        $theme_color  = (string) ( innsight_settings( 'pwa_theme_color', '#FFFFFF' ) );

        printf( '<link rel="manifest" href="%s">' . "\n", esc_url( $manifest_url ) );
        printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( $theme_color ) );
        printf( '<meta name="mobile-web-app-capable" content="yes">' . "\n" );
        printf( '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n" );
        printf( '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n" );

        // Apple touch icons. Prefer admin-configured URLs; fall back
        // to the bundled defaults.
        $base = trailingslashit( INNSIGHT_URL . 'assets/pwa/img' );
        $apple = (string) ( innsight_settings( 'pwa_apple_touch', '' ) ) ?: $base . 'apple-touch-icon.png';
        printf( '<link rel="apple-touch-icon" href="%s">' . "\n", esc_url( $apple ) );
        printf( '<link rel="apple-touch-icon" sizes="152x152" href="%s">' . "\n", esc_url( $base . 'hostel-icon-ipad.png' ) );
        printf( '<link rel="apple-touch-icon" sizes="167x167" href="%s">' . "\n", esc_url( $base . 'hostel-icon-ipad-retina.png' ) );
        printf( '<link rel="apple-touch-icon" sizes="180x180" href="%s">' . "\n", esc_url( $base . 'hostel-icon-iphone-retina.png' ) );

        // iOS splash screens (bundled portrait sizes for common iPhone/iPad viewports).
        $splashes = array(
            'apple_splash_640.png'  => '640x1136',
            'apple_splash_750.png'  => '750x1334',
            'apple_splash_1125.png' => '1125x2436',
            'apple_splash_1242.png' => '1242x2208',
            'apple_splash_1536.png' => '1536x2048',
            'apple_splash_1668.png' => '1668x2224',
            'apple_splash_2048.png' => '2048x2732',
        );
        foreach ( $splashes as $file => $sizes ) {
            printf( '<link rel="apple-touch-startup-image" sizes="%s" href="%s">' . "\n",
                esc_attr( $sizes ), esc_url( $base . $file ) );
        }
    }

    /**
     * Tiny inline registrar that installs / updates the SW. Prints in
     * the footer so it runs after the page's assets have started
     * loading. Registers with scope: '/' - allowed because our SW
     * response sends the Service-Worker-Allowed: / header.
     */
    public function render_sw_registrar(): void {
        if ( empty( innsight_settings( 'pwa_enabled', 1 ) ) ) return;
        $url = home_url( '/innsight-sw.js' );
        echo '<script>' .
             '(function(){if(!("serviceWorker" in navigator))return;' .
             'window.addEventListener("load",function(){' .
             'navigator.serviceWorker.register(' . wp_json_encode( $url ) . ',{scope:"/"}).catch(function(e){' .
             'if(window.console)console.info("[innsight] sw register skipped:",e&&e.message);' .
             '});});})();' .
             '</script>' . "\n";
    }

    /* ─── Legacy yuna-innsight URL shim ──────────────────────────────────── */

    /**
     * Serve the yuna-innsight PWA URLs from our new plugin so installed
     * PWAs on visitors' phones don't break when the old plugin folder
     * is deleted. Hooked super-early (plugins_loaded priority 0) so we
     * can short-circuit before WP does any heavy loading.
     *
     * Only fires when:
     *   - the yuna-innsight plugin folder is truly gone (we don't want
     *     to duplicate-serve when it's still installed);
     *   - the request path starts with the old plugin's URL prefix.
     *
     * Handles three URL shapes:
     *   /wp-content/plugins/yuna-innsight/manifest.json     -> dynamic manifest
     *   /wp-content/plugins/yuna-innsight/js/sw.js          -> new SW body
     *   /wp-content/plugins/yuna-innsight/img/<file>        -> 302 to bundled icon
     */
    public function maybe_serve_legacy_pwa(): void {
        $uri = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' );
        if ( strpos( $uri, '/wp-content/plugins/yuna-innsight/' ) === false ) return;
        // If the old plugin folder still exists on disk, we let the
        // webserver serve those files directly - no interception.
        if ( is_dir( WP_PLUGIN_DIR . '/yuna-innsight' ) ) return;

        if ( preg_match( '#/wp-content/plugins/yuna-innsight/manifest\.json$#', $uri ) ) {
            $this->serve_manifest();
            return;
        }
        if ( preg_match( '#/wp-content/plugins/yuna-innsight/js/sw\.js$#', $uri ) ) {
            $this->serve_sw();
            return;
        }
        if ( preg_match( '#/wp-content/plugins/yuna-innsight/img/([A-Za-z0-9_\-.]+)$#', $uri, $m ) ) {
            $file    = $m[1];
            $local   = INNSIGHT_PATH . 'assets/pwa/img/' . $file;
            $fallback = INNSIGHT_PATH . 'assets/pwa/img/icon-192.png';
            $path    = is_readable( $local ) ? $local : ( is_readable( $fallback ) ? $fallback : '' );
            if ( $path === '' ) { status_header( 404 ); exit; }
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ( $ext === 'svg' ? 'image/svg+xml' : 'image/png' );
            header( 'Content-Type: ' . $mime );
            header( 'Cache-Control: public, max-age=86400' );
            readfile( $path );
            exit;
        }
    }

    /* ─── Activation / deactivation ──────────────────────────────────────── */

    /**
     * Called from the plugin activation hook. Flushes rewrite rules so
     * our new manifest / SW routes work without the admin having to
     * visit Settings > Permalinks manually.
     */
    public static function activate(): void {
        $pwa = new self();
        $pwa->add_rewrites();
        flush_rewrite_rules();
    }
}
