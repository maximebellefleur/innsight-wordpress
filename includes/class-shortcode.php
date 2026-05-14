<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode - registers [innsight_map] and the [custom_map] backward-compat alias.
 *
 * Two render modes (settings.render_mode):
 *
 *   "inline" (default):
 *     <div id="innsight-map-XYZ"></div>
 *     <script>
 *       window.INNSIGHT_DATA = { ... v1 JSON ... };
 *       window.INNSIGHT_SKIN_PARTIALS = { layout: '...', popup: '...', ... };
 *       Innsight.init({ target: '#innsight-map-XYZ', config: window.INNSIGHT_DATA });
 *     </script>
 *
 *   "fetch":
 *     <div id="innsight-map-XYZ"></div>
 *     <script>
 *       Innsight.init({ target: '#innsight-map-XYZ', configUrl: '/wp-json/innsight/v1/map?post_id=...' });
 *     </script>
 *
 * Inline mode keeps the page render to a single roundtrip (no extra fetches);
 * fetch mode is friendlier to caching and headless setups.
 *
 * Multiple shortcode instances on the same page each get their own DOM id and
 * Innsight.init call. INNSIGHT_SKIN_PARTIALS is emitted once per page.
 */
final class Shortcode {

    /** @var DataSource */
    private $data_source;
    /** @var JsonBuilder */
    private $json_builder;
    /** @var SkinPartials */
    private $skin_partials;
    /** @var Assets */
    private $assets;
    /** @var int */
    private $instance_count = 0;
    /** @var bool */
    private $partials_emitted = false;

    public function __construct( DataSource $data_source, JsonBuilder $json_builder, SkinPartials $skin_partials, Assets $assets ) {
        $this->data_source   = $data_source;
        $this->json_builder  = $json_builder;
        $this->skin_partials = $skin_partials;
        $this->assets        = $assets;
    }

    public function register(): void {
        add_shortcode( 'innsight_map', array( $this, 'render' ) );
        add_shortcode( 'custom_map', array( $this, 'render' ) ); // legacy alias from yuna-innsight.
    }

    /**
     * Render a map. Shortcode attributes:
     *
     *   post_id        - source post for ACF fields (default: current loop ID)
     *   location       - place name to geocode (overrides ACF map_base_location)
     *   zoom           - integer (overrides ACF map_zoom_level)
     *   viewmode       - event | single | act | multi | blogs (default: multi)
     *   height         - CSS height (default: 70vh)
     *   provider       - osm | mapbox | google (overrides settings.provider_default)
     *   skin           - skin name (overrides settings.skin_name)
     *   render_mode    - inline | fetch (overrides settings.render_mode)
     *   taxonomy_slug  - filter for multi viewmode
     *   taxonomy_id    - filter for multi viewmode
     */
    public function render( $atts, $content = null, $tag = '' ): string {
        $atts = shortcode_atts(
            array(
                'post_id'       => get_the_ID() ? (string) get_the_ID() : '',
                'location'      => '',
                'zoom'          => '',
                'viewmode'      => 'multi',
                'height'        => '70vh',
                'provider'      => '',
                'skin'          => '',
                'render_mode'   => '',
                'taxonomy_slug' => '',
                'taxonomy_id'   => '',
            ),
            (array) $atts,
            'innsight_map'
        );

        $this->instance_count++;
        $dom_id = 'innsight-map-' . $this->instance_count;

        $this->assets->ensure_enqueued();

        $args = array(
            'post_id'       => (int) $atts['post_id'],
            'location'      => trim( (string) $atts['location'] ),
            'viewmode'      => sanitize_key( (string) $atts['viewmode'] ),
            'taxonomy_slug' => trim( (string) $atts['taxonomy_slug'] ),
            'taxonomy_id'   => trim( (string) $atts['taxonomy_id'] ),
        );
        if ( $atts['zoom'] !== '' && is_numeric( $atts['zoom'] ) ) {
            $args['zoom'] = (int) $atts['zoom'];
        }

        $intermediate = $this->data_source->build( $args );
        $config       = $this->json_builder->build( $intermediate, $atts );

        $render_mode = $atts['render_mode'] !== '' ? sanitize_key( (string) $atts['render_mode'] ) : (string) innsight_settings( 'render_mode', 'inline' );
        $render_mode = in_array( $render_mode, array( 'inline', 'fetch' ), true ) ? $render_mode : 'inline';

        $height = $this->sanitize_height( (string) $atts['height'] );

        do_action( 'innsight/before_render', $dom_id, $config, $atts );

        $html  = '<div class="innsight-map-wrap" data-innsight-instance="' . esc_attr( $dom_id ) . '">';
        $html .= '<div id="' . esc_attr( $dom_id ) . '" class="innsight-map-target" style="' . esc_attr( 'width:100%;height:' . $height ) . '"></div>';
        $html .= '</div>';

        if ( $render_mode === 'inline' ) {
            $html .= $this->render_inline_bootstrap( $dom_id, $config );
        } else {
            $html .= $this->render_fetch_bootstrap( $dom_id, $atts );
        }

        do_action( 'innsight/after_render', $dom_id, $config, $atts );
        return $html;
    }

    private function render_inline_bootstrap( string $dom_id, array $config ): string {
        $partials_block = '';
        if ( ! $this->partials_emitted ) {
            $partials = $this->skin_partials->read( (string) $config['skin']['name'] );
            if ( is_array( $partials ) ) {
                // Stamp the partials with the current plugin version. The
                // bootstrap below uses this to detect "page HTML cached at
                // version A, JS+CSS asset URLs at version B" mismatches and
                // force a one-time clean reload that bypasses the page cache.
                $partials_block = '<script>window.INNSIGHT_SKIN_PARTIALS=' . wp_json_encode( $partials ) . ';window.INNSIGHT_PARTIALS_VERSION=' . wp_json_encode( INNSIGHT_VERSION ) . ';</script>';
                $this->partials_emitted = true;
            }
        }

        $config_var = 'INNSIGHT_DATA_' . $this->instance_count;
        $config_js  = 'window.' . $config_var . '=' . wp_json_encode( $config ) . ';';
        $init_js    = 'Innsight.init({target:' . wp_json_encode( '#' . $dom_id ) . ',config:window.' . $config_var . '});';

        // The engine loads as ~20 individual <script> tags; each earlier
        // module creates `window.Innsight = { _utils: ... }` before innsight.js
        // attaches `.init`. A `typeof Innsight === "undefined"` check passes
        // too early (Innsight is "object", not "undefined") and we'd call a
        // function that doesn't exist yet. Wait for `.init` specifically.
        //
        // ALSO wait for the chosen skin to be registered on
        // `Innsight._skins[name]`. On slow networks skin.js (loaded async
        // after the engine) can still be downloading when the bootstrap
        // fires - the engine then renders pins from its own data but
        // silently no-ops the skin setup, leaving the user with a map full
        // of pins but no interactive chrome / chips / count / tap-to-open.
        // The polling continues until both are ready; max 30s safety
        // ceiling so we don't loop forever on a broken deploy.
        $skin_name = isset( $config['skin']['name'] ) ? (string) $config['skin']['name'] : 'innsight2026';
        $ready_check =
            '(function(){function _go(){var I=window.Innsight;'
            . 'if(!I||typeof I.init!=="function"){return _retry();}'
            . 'if(!I._skins||!I._skins[' . wp_json_encode( $skin_name ) . ']){return _retry();}'
            . $config_js . $init_js
            . '}'
            . 'var _tries=0;'
            . 'function _retry(){if(_tries++>1000){'
            . '  if(window.console)console.error("[innsight] init timeout - engine or skin failed to load. Check enqueued scripts.");'
            . '  return;'
            . '}setTimeout(_go,30);}'
            . '_go();})();';
        return $partials_block . '<script>' . $ready_check . '</script>';
    }

    private function render_fetch_bootstrap( string $dom_id, array $atts ): string {
        $url = add_query_arg(
            array(
                'post_id'  => (int) $atts['post_id'],
                'viewmode' => sanitize_key( (string) $atts['viewmode'] ),
            ),
            rest_url( 'innsight/v1/map' )
        );
        return '<script>(function(){function _go(){if(!window.Innsight||typeof window.Innsight.init!=="function"){return setTimeout(_go,30);}Innsight.init({target:' . wp_json_encode( '#' . $dom_id ) . ',configUrl:' . wp_json_encode( $url ) . '});}_go();})();</script>';
    }

    /**
     * Allow only safe CSS lengths in the height attribute. Defends against
     * injection through the shortcode parameter.
     */
    private function sanitize_height( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '70vh';
        }
        if ( preg_match( '/^\d+(?:\.\d+)?(?:px|em|rem|vh|vw|%)$/', $value ) ) {
            return $value;
        }
        return '70vh';
    }
}
