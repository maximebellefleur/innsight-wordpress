<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * RestController - exposes /wp-json/innsight/v1/map for the engine to fetch when
 * the shortcode is rendered in "fetch" mode, and for headless / SPA consumers.
 *
 * The endpoint is read-only and public (no auth) by default - the data it
 * returns is also rendered into shortcode output, so there's no privilege
 * escalation. Sites that need it locked down can use the
 * `innsight/rest/permission` filter to require a logged-in user, capability,
 * or signed request.
 */
final class RestController {

    /** @var DataSource */
    private $data_source;
    /** @var JsonBuilder */
    private $json_builder;

    public function __construct( DataSource $data_source, JsonBuilder $json_builder ) {
        $this->data_source  = $data_source;
        $this->json_builder = $json_builder;
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route(
            'innsight/v1',
            '/map',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'permission_callback' => array( $this, 'permission_check' ),
                'callback'            => array( $this, 'handle_map' ),
                'args'                => array(
                    'post_id'       => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                    ),
                    'viewmode'      => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => 'multi',
                    ),
                    'location'      => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                    'zoom'          => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'provider'      => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => '',
                    ),
                    'skin'          => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => '',
                    ),
                    'taxonomy_slug' => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => '',
                    ),
                    'taxonomy_id'   => array(
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ),
                ),
            )
        );
    }

    /**
     * @return bool|\WP_Error
     */
    public function permission_check( \WP_REST_Request $request ) {
        /**
         * Filter the REST permission. Default true (public).
         *
         * @param bool             $allow
         * @param \WP_REST_Request $request
         */
        return apply_filters( 'innsight/rest/permission', true, $request );
    }

    public function handle_map( \WP_REST_Request $request ): \WP_REST_Response {
        $args = array(
            'post_id'       => (int) $request->get_param( 'post_id' ),
            'viewmode'      => (string) $request->get_param( 'viewmode' ),
            'location'      => (string) $request->get_param( 'location' ),
            'taxonomy_slug' => (string) $request->get_param( 'taxonomy_slug' ),
            'taxonomy_id'   => (string) $request->get_param( 'taxonomy_id' ),
        );
        $zoom = $request->get_param( 'zoom' );
        if ( $zoom !== null && $zoom !== '' ) {
            $args['zoom'] = (int) $zoom;
        }

        $intermediate = $this->data_source->build( $args );
        $config       = $this->json_builder->build( $intermediate, array(
            'provider' => (string) $request->get_param( 'provider' ),
            'skin'     => (string) $request->get_param( 'skin' ),
        ) );

        $response = new \WP_REST_Response( $config, 200 );
        $response->header( 'Cache-Control', 'public, max-age=60' );
        return $response;
    }
}
