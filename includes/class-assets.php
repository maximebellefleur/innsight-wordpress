<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Assets - registers and enqueues the engine + skin assets.
 *
 * Three sourcing modes (settings.engine_source):
 *
 *   - "bundled" (default): plugin ships everything under /skins and the engine
 *     repo lives next to this plugin's tree. URLs resolve via plugin_dir_url.
 *   - "cdn": engine and vendor libs come from jsDelivr against the
 *     maximebellefleur/innsight repo. The skin is still bundled locally so it
 *     can be customized per-site.
 *   - "custom": URLs are taken directly from settings.engine_url etc.
 *
 * Scripts only enqueue when the shortcode is actually on the page. The
 * `enqueue()` call in Shortcode::render flips a flag this class respects.
 */
final class Assets {

    private const HANDLE_LEAFLET           = 'innsight-leaflet';
    private const HANDLE_LEAFLET_CSS       = 'innsight-leaflet-css';
    private const HANDLE_MARKERCLUSTER     = 'innsight-markercluster';
    private const HANDLE_MARKERCLUSTER_CSS = 'innsight-markercluster-css';
    private const HANDLE_MC_LAYERSUPPORT   = 'innsight-markercluster-layersupport';
    private const HANDLE_SUBGROUP          = 'innsight-subgroup';
    private const HANDLE_FULLSCREEN        = 'innsight-fullscreen';
    private const HANDLE_FULLSCREEN_CSS    = 'innsight-fullscreen-css';
    private const HANDLE_MAPBOX_GL         = 'innsight-mapbox-gl';
    private const HANDLE_MAPBOX_GL_CSS     = 'innsight-mapbox-gl-css';
    private const HANDLE_ENGINE            = 'innsight-engine';
    private const HANDLE_SKIN_CSS          = 'innsight-skin-css';
    private const HANDLE_SKIN_ICONS_CSS    = 'innsight-skin-icons-css';
    private const HANDLE_SKIN_JS           = 'innsight-skin-js';

    private const MAPBOX_GL_VERSION = '3.5.1';
    private const MAPBOX_GL_JS_URL  = 'https://api.mapbox.com/mapbox-gl-js/v3.5.1/mapbox-gl.js';
    private const MAPBOX_GL_CSS_URL = 'https://api.mapbox.com/mapbox-gl-js/v3.5.1/mapbox-gl.css';

    private const CDN_BASE = 'https://cdn.jsdelivr.net/gh/maximebellefleur/innsight@main';

    /** @var bool */
    private $enqueued = false;

    public function register(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 9 );
    }

    /**
     * Mark the page as needing Innsight assets. Called from Shortcode::render.
     */
    public function ensure_enqueued(): void {
        if ( $this->enqueued ) {
            return;
        }
        $this->enqueued = true;
        $this->do_enqueue();
    }

    public function register_assets(): void {
        $sources = $this->resolve_sources();

        wp_register_style( self::HANDLE_LEAFLET_CSS, $sources['leaflet']['css'], array(), $sources['leaflet']['ver'] );
        wp_register_style( self::HANDLE_MARKERCLUSTER_CSS, $sources['markercluster']['css'], array(), $sources['markercluster']['ver'] );
        wp_register_style( self::HANDLE_FULLSCREEN_CSS, $sources['fullscreen']['css'], array(), $sources['fullscreen']['ver'] );
        wp_register_style( self::HANDLE_MAPBOX_GL_CSS, self::MAPBOX_GL_CSS_URL, array(), self::MAPBOX_GL_VERSION );
        wp_register_style( self::HANDLE_SKIN_CSS, $sources['skin']['css'], array(), $sources['skin']['ver'] );

        wp_register_script( self::HANDLE_LEAFLET, $sources['leaflet']['js'], array(), $sources['leaflet']['ver'], true );
        wp_register_script( self::HANDLE_MARKERCLUSTER, $sources['markercluster']['js'], array( self::HANDLE_LEAFLET ), $sources['markercluster']['ver'], true );
        wp_register_script( self::HANDLE_MC_LAYERSUPPORT, $sources['markercluster_ls']['js'], array( self::HANDLE_MARKERCLUSTER ), $sources['markercluster_ls']['ver'], true );
        wp_register_script( self::HANDLE_SUBGROUP, $sources['subgroup']['js'], array( self::HANDLE_MARKERCLUSTER ), $sources['subgroup']['ver'], true );
        wp_register_script( self::HANDLE_FULLSCREEN, $sources['fullscreen']['js'], array( self::HANDLE_LEAFLET ), $sources['fullscreen']['ver'], true );
        wp_register_script( self::HANDLE_MAPBOX_GL, self::MAPBOX_GL_JS_URL, array(), self::MAPBOX_GL_VERSION, true );

        // Engine: load all engine modules through a single registered handle whose
        // src points at innsight.js, with the subordinate modules listed as inline
        // <script> tags emitted before innsight.js. We keep them as separate
        // registered scripts so existing handles remain debuggable.
        $modules = $sources['engine']['modules'];
        $previous_dep = self::HANDLE_FULLSCREEN;
        foreach ( $modules as $key => $url ) {
            $handle = 'innsight-mod-' . $key;
            wp_register_script( $handle, $url, array( $previous_dep ), $sources['engine']['ver'], true );
            $previous_dep = $handle;
        }
        wp_register_script( self::HANDLE_ENGINE, $sources['engine']['main'], array( $previous_dep ), $sources['engine']['ver'], true );

        wp_register_script( self::HANDLE_SKIN_JS, $sources['skin']['js'], array( self::HANDLE_ENGINE ), $sources['skin']['ver'], true );
    }

    private function do_enqueue(): void {
        $sources = $this->resolve_sources();
        $skin    = (string) innsight_settings( 'skin_name', 'solike2025' );
        $needs_mapbox_gl = ( $skin === 'innsight2026' );

        if ( ! wp_script_is( 'jquery', 'registered' ) ) {
            // jQuery is normally already there; if a theme dequeued it, do nothing,
            // engine works without jQuery (graceful no-op for the jQuery wrapper).
        }

        wp_enqueue_style( self::HANDLE_LEAFLET_CSS );
        wp_enqueue_style( self::HANDLE_MARKERCLUSTER_CSS );
        wp_enqueue_style( self::HANDLE_FULLSCREEN_CSS );
        if ( $needs_mapbox_gl ) {
            wp_enqueue_style( self::HANDLE_MAPBOX_GL_CSS );
        }
        wp_enqueue_style( self::HANDLE_SKIN_CSS );

        // Icon-font @font-face + legacy yuna codepoint mappings
        // (baseline.css md-* + map.css map-*). Enqueued as a separate
        // stylesheet so its URL is absolute (a CSS-optimiser plugin
        // that moves skin.css into /wp-content/cache/ won't break the
        // codepoint rules), AND we emit the @font-face inline with
        // absolute font URLs so those cannot 404 either.
        if ( $skin === 'innsight2026' ) {
            $icons_css_url  = INNSIGHT_URL  . 'skins/' . $skin . '/assets/icons.css';
            $icons_css_path = INNSIGHT_PATH . 'skins/' . $skin . '/assets/icons.css';
            $icons_ver = file_exists( $icons_css_path ) ? (string) filemtime( $icons_css_path ) : INNSIGHT_VERSION;
            wp_register_style( self::HANDLE_SKIN_ICONS_CSS, $icons_css_url, array( self::HANDLE_SKIN_CSS ), $icons_ver );
            wp_enqueue_style( self::HANDLE_SKIN_ICONS_CSS );

            $fonts_url = INNSIGHT_URL . 'skins/' . $skin . '/assets/fonts/';
            $font_face = sprintf(
                '@font-face{font-family:"Innsight Material Icons";font-style:normal;font-weight:400;font-display:block;'
                . 'src:url(%1$sMaterialIcons.woff2) format("woff2"),'
                . 'url(%1$sMaterialIcons.woff) format("woff"),'
                . 'url(%1$sMaterialIcons.ttf) format("truetype")}'
                . '@font-face{font-family:"Innsight Map Icons";font-style:normal;font-weight:400;font-display:block;'
                . 'src:url(%1$smap.ttf) format("truetype")}',
                esc_url( $fonts_url )
            );
            wp_add_inline_style( self::HANDLE_SKIN_ICONS_CSS, $font_face );
        }

        // Re-enqueue every engine module handle so they all print.
        wp_enqueue_script( self::HANDLE_LEAFLET );
        wp_enqueue_script( self::HANDLE_MARKERCLUSTER );
        wp_enqueue_script( self::HANDLE_MC_LAYERSUPPORT );
        wp_enqueue_script( self::HANDLE_SUBGROUP );
        wp_enqueue_script( self::HANDLE_FULLSCREEN );
        if ( $needs_mapbox_gl ) {
            wp_enqueue_script( self::HANDLE_MAPBOX_GL );
        }

        foreach ( $sources['engine']['modules'] as $key => $_url ) {
            wp_enqueue_script( 'innsight-mod-' . $key );
        }
        wp_enqueue_script( self::HANDLE_ENGINE );
        wp_enqueue_script( self::HANDLE_SKIN_JS );
    }

    /**
     * Resolve all asset URLs based on settings.engine_source.
     *
     * @return array
     */
    private function resolve_sources(): array {
        $settings = innsight_settings();
        $source   = $settings['engine_source'];
        $skin     = $settings['skin_name'];

        // The plugin ships its own copy of the engine + vendor libraries under
        // /engine. "bundled" mode serves them from the plugin URL, "cdn" mode
        // from jsDelivr against the maximebellefleur/innsight GitHub repo,
        // "custom" mode from admin-supplied URLs.
        $engine_base = $source === 'custom' && ! empty( $settings['engine_url'] )
            ? trailingslashit( $settings['engine_url'] )
            : ( $source === 'cdn' ? self::CDN_BASE . '/' : INNSIGHT_URL );

        $vendor_base = $source === 'custom' && ! empty( $settings['engine_vendor_url'] )
            ? trailingslashit( $settings['engine_vendor_url'] )
            : $engine_base . 'engine/vendor/';

        $skin_local_url  = trailingslashit( INNSIGHT_URL . 'skins/' . $skin );
        $skin_local_path = trailingslashit( INNSIGHT_PATH . 'skins/' . $skin );

        $version = INNSIGHT_VERSION;

        return array(
            'leaflet'          => array(
                'js'  => $vendor_base . 'leaflet/leaflet.js',
                'css' => $vendor_base . 'leaflet/leaflet.css',
                'ver' => '1.9.4',
            ),
            'markercluster'    => array(
                'js'  => $vendor_base . 'leaflet-markercluster/leaflet.markercluster.js',
                'css' => $vendor_base . 'leaflet-markercluster/MarkerCluster.css',
                'ver' => '1.5.3',
            ),
            'markercluster_ls' => array(
                'js'  => $vendor_base . 'leaflet-subgroup/leaflet.markercluster.layersupport.js',
                'ver' => '1.0',
            ),
            'subgroup'         => array(
                'js'  => $vendor_base . 'leaflet-subgroup/subgroup.js',
                'ver' => '1.0',
            ),
            'fullscreen'       => array(
                'js'  => $vendor_base . 'leaflet-fullscreen/Control.FullScreen.js',
                'css' => $vendor_base . 'leaflet-fullscreen/Control.FullScreen.css',
                'ver' => '1.0',
            ),
            'engine'           => array(
                'modules' => array(
                    'utils'           => $engine_base . 'engine/core/utils.js',
                    'events'          => $engine_base . 'engine/core/events.js',
                    'template'        => $engine_base . 'engine/core/template.js',
                    'state'           => $engine_base . 'engine/core/state.js',
                    'config'          => $engine_base . 'engine/core/config.js',
                    'base-provider'   => $engine_base . 'engine/providers/base-provider.js',
                    'leaflet-provider'=> $engine_base . 'engine/providers/leaflet-provider.js',
                    'mapbox-provider' => $engine_base . 'engine/providers/mapbox-provider.js',
                    'mapbox-gl-provider' => $engine_base . 'engine/providers/mapbox-gl-provider.js',
                    'google-provider' => $engine_base . 'engine/providers/google-provider.js',
                    'provider-factory'=> $engine_base . 'engine/providers/provider-factory.js',
                    'clustering'      => $engine_base . 'engine/features/clustering.js',
                    'popup'           => $engine_base . 'engine/features/popup.js',
                    'markers'         => $engine_base . 'engine/features/markers.js',
                    'paths'           => $engine_base . 'engine/features/paths.js',
                    'layer-control'   => $engine_base . 'engine/features/layer-control.js',
                    'filtering'       => $engine_base . 'engine/features/filtering.js',
                    'kml-export'      => $engine_base . 'engine/features/kml-export.js',
                    'skin-loader'     => $engine_base . 'engine/core/skin-loader.js',
                    'enrichment'      => $engine_base . 'engine/enrichment/google-places.js',
                ),
                'main' => $engine_base . 'engine/innsight.js',
                'ver'  => $version,
            ),
            'skin'             => array(
                'css' => $skin_local_url . 'skin.css',
                'js'  => $skin_local_url . 'skin.js',
                'ver' => file_exists( $skin_local_path . 'skin.css' ) ? (string) filemtime( $skin_local_path . 'skin.css' ) : $version,
            ),
        );
    }
}
