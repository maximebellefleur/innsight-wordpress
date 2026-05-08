<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * KmlExport - server-side KML download.
 *
 * The Innsight engine generates KML client-side from the same JSON config it
 * renders the map with, so most users never hit the server. This endpoint
 * exists for two reasons:
 *
 *   1. Backward compatibility with the legacy yuna-innsight `generate_kml`
 *      AJAX action - existing bookmarks like
 *      `/wp-admin/admin-ajax.php?action=generate_kml&post_id=123` keep working.
 *   2. Headless integrations that want a KML file URL without booting the JS
 *      engine (e.g. server-side email signatures, automated reports).
 *
 * Both `wp_ajax_generate_kml` and `wp_ajax_nopriv_generate_kml` route here.
 */
final class KmlExport {

    /** @var DataSource */
    private $data_source;

    public function __construct( DataSource $data_source ) {
        $this->data_source = $data_source;
    }

    public function register(): void {
        add_action( 'wp_ajax_generate_kml', array( $this, 'handle' ) );
        add_action( 'wp_ajax_nopriv_generate_kml', array( $this, 'handle' ) );

        add_action( 'rest_api_init', function () {
            register_rest_route( 'innsight/v1', '/kml', array(
                'methods'             => \WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => array( $this, 'handle_rest' ),
                'args'                => array(
                    'post_id'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                    'viewmode' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'multi' ),
                ),
            ) );
        } );
    }

    public function handle(): void {
        $post_id  = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $viewmode = isset( $_REQUEST['viewmode'] ) ? sanitize_key( wp_unslash( $_REQUEST['viewmode'] ) ) : 'multi'; // phpcs:ignore
        $kml      = $this->build_kml( $post_id, $viewmode );

        nocache_headers();
        header( 'Content-Type: application/vnd.google-earth.kml+xml; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="innsight-map-' . $post_id . '.kml"' );
        echo $kml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- KML is XML; entities already encoded.
        wp_die();
    }

    public function handle_rest( \WP_REST_Request $request ): \WP_REST_Response {
        $kml = $this->build_kml( (int) $request->get_param( 'post_id' ), (string) $request->get_param( 'viewmode' ) );
        $response = new \WP_REST_Response( $kml, 200 );
        $response->header( 'Content-Type', 'application/vnd.google-earth.kml+xml; charset=UTF-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="innsight-map-' . (int) $request->get_param( 'post_id' ) . '.kml"' );
        return $response;
    }

    private function build_kml( int $post_id, string $viewmode ): string {
        $intermediate = $this->data_source->build( array(
            'post_id'  => $post_id,
            'viewmode' => $viewmode,
        ) );

        $kml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $kml .= '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>';
        $kml .= '<name>' . esc_html( get_the_title( $post_id ) !== '' ? get_the_title( $post_id ) : get_bloginfo( 'name' ) ) . '</name>';

        foreach ( $intermediate['pois'] as $poi ) {
            $kml .= '<Placemark>';
            $kml .= '<name>' . esc_html( (string) $poi['title'] ) . '</name>';
            if ( ! empty( $poi['description'] ) ) {
                $kml .= '<description><![CDATA[' . (string) $poi['description'] . ']]></description>';
            }
            $kml .= '<Point><coordinates>' . (float) $poi['lon'] . ',' . (float) $poi['lat'] . ',0</coordinates></Point>';
            $kml .= '</Placemark>';
        }

        foreach ( $intermediate['paths'] as $path ) {
            $kml .= '<Placemark>';
            $kml .= '<name>' . esc_html( (string) $path['title'] ) . '</name>';
            $kml .= '<LineString><coordinates>';
            foreach ( $path['coordinates'] as $coord ) {
                $kml .= (float) $coord[1] . ',' . (float) $coord[0] . ',0 ';
            }
            $kml .= '</coordinates></LineString>';
            $kml .= '</Placemark>';
        }

        $kml .= '</Document></kml>';
        return $kml;
    }
}
