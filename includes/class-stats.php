<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Stats - lightweight, privacy-friendly telemetry for map usage.
 *
 * Design:
 *   - One custom table with (event_key, poi_id, day, count). Every event
 *     is an atomic INSERT ... ON DUPLICATE KEY UPDATE so writes are O(1)
 *     even under high frontend traffic.
 *   - No visitor identity, no session, no IP stored. Just anonymous
 *     aggregate counts bucketed by day. Nothing that would trigger GDPR
 *     analysis for the average tourism-map deployment.
 *   - Frontend sends events via navigator.sendBeacon() so the round-trip
 *     is fire-and-forget and doesn't block page rendering.
 *
 * Events tracked (all optional per admin toggle):
 *   - map_load        Sheet controller booted (one per page load, per instance).
 *   - poi_open        A POI sheet was opened.
 *   - poi_save        A POI was saved to a visitor's local list.
 *   - poi_unsave      Reverse of save.
 *   - share_send      Visitor triggered the Share Wishlist menu.
 *   - share_received  Visitor landed via an #innsight_share URL.
 */
final class Stats {

    private const TABLE = 'innsight_stats';

    /** @var string[] */
    public const EVENTS = array( 'map_load', 'poi_open', 'poi_save', 'poi_unsave', 'share_send', 'share_received' );

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Install / upgrade the stats table. Called from the plugin's
     * activation hook so a fresh install is ready before the first
     * beacon lands. dbDelta handles upgrades in place.
     */
    public static function install(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // event_key    : one of self::EVENTS (kept as VARCHAR for future
        //                extension without a migration)
        // poi_id       : nullable string id (poi-slug, numeric, etc). '' for
        //                global events like map_load. Kept as VARCHAR so the
        //                UNIQUE key never has to deal with NULL semantics.
        // day          : DATE in site timezone. Bucket size.
        // count        : monotonic counter for this bucket.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(32) NOT NULL,
            poi_id VARCHAR(96) NOT NULL DEFAULT '',
            day DATE NOT NULL,
            count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY event_poi_day (event_key, poi_id, day),
            KEY event_key (event_key),
            KEY poi_id (poi_id),
            KEY day (day)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Increment a single event. Atomic upsert; safe to call from
     * concurrent requests without race-conditions. Returns true on
     * success. Silently no-ops when analytics are disabled in
     * settings so callers don't need to check.
     */
    public function increment( string $event, string $poi_id = '' ): bool {
        if ( ! in_array( $event, self::EVENTS, true ) ) return false;
        if ( ! $this->enabled() ) return false;

        global $wpdb;
        $table  = self::table_name();
        $poi_id = mb_substr( sanitize_text_field( $poi_id ), 0, 96 );
        $day    = current_time( 'Y-m-d' );

        // ON DUPLICATE KEY UPDATE turns the write into an atomic
        // increment when the (event, poi, day) row already exists.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} (event_key, poi_id, day, count) VALUES (%s, %s, %s, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            $event, $poi_id, $day
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return false !== $wpdb->query( $sql );
    }

    /**
     * Enabled flag - single point of truth. Reads the admin setting
     * with a filter for programmatic override (e.g. per-site opt-out
     * from a mu-plugin).
     */
    public function enabled(): bool {
        $on = ! empty( innsight_settings( 'analytics_enabled', 1 ) );
        return (bool) apply_filters( 'innsight/analytics_enabled', $on );
    }

    /* ─── Aggregate readers (used by Dashboard widget + Analytics page) ─── */

    /**
     * Top POIs by (save - unsave) count within the window. Returns
     * [{poi_id, saves, unsaves, net}].
     */
    public function top_saved( int $limit = 10, int $days = 90 ): array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d', strtotime( '-' . max( 1, $days ) . ' days' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT poi_id,
                    SUM(CASE WHEN event_key = 'poi_save' THEN count ELSE 0 END) AS saves,
                    SUM(CASE WHEN event_key = 'poi_unsave' THEN count ELSE 0 END) AS unsaves
             FROM {$table}
             WHERE event_key IN ('poi_save', 'poi_unsave')
               AND day >= %s
               AND poi_id <> ''
             GROUP BY poi_id
             ORDER BY (SUM(CASE WHEN event_key = 'poi_save' THEN count ELSE 0 END) -
                       SUM(CASE WHEN event_key = 'poi_unsave' THEN count ELSE 0 END)) DESC
             LIMIT %d",
            $since, max( 1, $limit )
        ), ARRAY_A );

        return array_map( static function ( $r ) {
            $saves   = (int) $r['saves'];
            $unsaves = (int) $r['unsaves'];
            return array(
                'poi_id'  => (string) $r['poi_id'],
                'saves'   => $saves,
                'unsaves' => $unsaves,
                'net'     => $saves - $unsaves,
            );
        }, $rows ?: array() );
    }

    /**
     * Total count for a single event within the window. `$days = 0`
     * means today only.
     */
    public function total( string $event, int $days = 0 ): int {
        if ( ! in_array( $event, self::EVENTS, true ) ) return 0;
        global $wpdb;
        $table = self::table_name();
        $today = current_time( 'Y-m-d' );

        if ( $days <= 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
            $sum = $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(count), 0) FROM {$table} WHERE event_key = %s AND day = %s",
                $event, $today
            ) );
        } else {
            $since = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
            $sum = $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(count), 0) FROM {$table} WHERE event_key = %s AND day >= %s",
                $event, $since
            ) );
        }
        return (int) $sum;
    }

    /**
     * Daily counts for one event across a window. Returns
     * [ 'YYYY-MM-DD' => count, ... ] with zero-fill so the caller can
     * render a continuous chart without gap handling.
     */
    public function timeseries( string $event, int $days = 30 ): array {
        if ( ! in_array( $event, self::EVENTS, true ) ) return array();
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d', strtotime( '-' . max( 1, $days - 1 ) . ' days' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT day, SUM(count) AS c FROM {$table} WHERE event_key = %s AND day >= %s GROUP BY day",
            $event, $since
        ), ARRAY_A );

        $bucket = array();
        foreach ( $rows ?: array() as $r ) {
            $bucket[ (string) $r['day'] ] = (int) $r['c'];
        }
        // Zero-fill so the chart draws a continuous line.
        $out = array();
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $d = gmdate( 'Y-m-d', strtotime( '-' . $i . ' days' ) );
            $out[ $d ] = $bucket[ $d ] ?? 0;
        }
        return $out;
    }

    /**
     * Aggregate counts for a given event where poi_id starts with a
     * given prefix. Used by the share-channel breakdown where events
     * are stored with poi_id "ch:whatsapp", "ch:email", etc.
     * Returns [{poi_id, count}] ordered by count desc.
     */
    public function top_saved_by_prefix( string $prefix, string $event, int $limit = 10, int $days = 90 ): array {
        global $wpdb;
        $table = self::table_name();
        $since = gmdate( 'Y-m-d', strtotime( '-' . max( 1, $days ) . ' days' ) );
        $like  = $wpdb->esc_like( $prefix ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT poi_id, SUM(count) AS c FROM {$table}
             WHERE event_key = %s AND day >= %s AND poi_id LIKE %s
             GROUP BY poi_id
             ORDER BY c DESC
             LIMIT %d",
            $event, $since, $like, max( 1, $limit )
        ), ARRAY_A );
        return array_map( static function ( $r ) {
            return array( 'poi_id' => (string) $r['poi_id'], 'count' => (int) $r['c'] );
        }, $rows ?: array() );
    }

    /**
     * Bulk POI resolver - turn a list of poi_ids into a display map:
     * [ poi_id => ['title' => ..., 'edit_url' => ...] ]. Resolves both
     * post-type POIs (numeric ids) and taxonomy-term POIs.
     */
    public function resolve_pois( array $ids ): array {
        $out = array();
        foreach ( array_unique( array_filter( $ids ) ) as $id ) {
            $sid = (string) $id;
            $title = '';
            $edit  = '';
            // Numeric id -> could be a post or a term. Post takes precedence.
            if ( ctype_digit( $sid ) ) {
                $post = get_post( (int) $sid );
                if ( $post ) {
                    $title = get_the_title( $post ) ?: '(untitled)';
                    $edit  = (string) get_edit_post_link( $post, 'raw' );
                }
            }
            // Prefixed poi ids like "poi-42", "term-17".
            if ( $title === '' && strpos( $sid, 'term-' ) === 0 ) {
                $term = get_term( (int) substr( $sid, 5 ), 'point_of_interest' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $title = $term->name;
                    $edit  = (string) get_edit_term_link( $term->term_id, 'point_of_interest' );
                }
            }
            $out[ $sid ] = array(
                'title'    => $title !== '' ? $title : $sid,
                'edit_url' => $edit,
            );
        }
        return $out;
    }
}
