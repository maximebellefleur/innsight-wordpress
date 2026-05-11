<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * PoiExporter - downloads a JSON snapshot of every POI the plugin knows about.
 *
 * Two sources are bundled:
 *   - `poi` custom post type (the new path; the importer writes here)
 *   - `point_of_interest` taxonomy terms (the legacy yuna-innsight path)
 *
 * The output is a self-describing JSON document the importer can read back
 * (round-trip), so an admin can take a backup before running an import and
 * restore from it if anything goes wrong.
 *
 * Triggered from the admin import page; routes through `init` so it can emit
 * download headers without "headers already sent" issues.
 */
final class PoiExporter {

    public const NONCE_ACTION = 'innsight_poi_export';

    public function register(): void {
        add_action( 'admin_init', array( $this, 'maybe_handle_download' ) );
    }

    public function maybe_handle_download(): void {
        if ( empty( $_GET['innsight_export'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to export POIs.', 'innsight' ) );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'Expired or invalid export link. Reload the import page and click Backup again.', 'innsight' ) );
        }
        $this->download_json();
    }

    public function download_json(): void {
        $payload = $this->build_snapshot();
        $filename = 'innsight-pois-' . gmdate( 'Y-m-d-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public function build_snapshot(): array {
        return array(
            'innsight_export_version' => 1,
            'exported_at'             => gmdate( 'c' ),
            'site'                    => home_url(),
            'plugin_version'          => INNSIGHT_VERSION,
            'pois'                    => $this->export_poi_posts(),
            'legacy_taxonomy_terms'   => $this->export_legacy_taxonomy(),
        );
    }

    private function export_poi_posts(): array {
        if ( ! post_type_exists( PoiPostType::POST_TYPE ) ) return array();
        $posts = get_posts( array(
            'post_type'      => PoiPostType::POST_TYPE,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ) );
        $out = array();
        $meta_keys = array_keys( PoiPostType::META );
        foreach ( $posts as $post ) {
            $row = array(
                'id'      => (int) $post->ID,
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'status'  => $post->post_status,
                'image'   => (string) get_the_post_thumbnail_url( $post->ID, 'large' ),
            );
            foreach ( $meta_keys as $key ) {
                $row[ $key ] = get_post_meta( $post->ID, $key, true );
            }
            $out[] = $row;
        }
        return $out;
    }

    private function export_legacy_taxonomy(): array {
        if ( ! taxonomy_exists( 'point_of_interest' ) ) return array();
        $terms = get_terms( array(
            'taxonomy'   => 'point_of_interest',
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) ) return array();
        $out = array();
        foreach ( $terms as $term ) {
            $out[] = array(
                'term_id'             => (int) $term->term_id,
                'name'                => $term->name,
                'slug'                => $term->slug,
                'description'         => $term->description,
                'poi_latitude'        => get_term_meta( $term->term_id, 'poi_latitude', true ),
                'poi_longitude'       => get_term_meta( $term->term_id, 'poi_longitude', true ),
                'poi_type'            => get_term_meta( $term->term_id, 'poi_type', true ),
                'poi_category'        => get_term_meta( $term->term_id, 'poi_category', true ),
                'poi_image'           => get_term_meta( $term->term_id, 'poi_image', true ),
                'poi_url_link'        => get_term_meta( $term->term_id, 'poi_url_link', true ),
                'main_more_info_url'  => get_term_meta( $term->term_id, 'main_more_info_url', true ),
            );
        }
        return $out;
    }
}
