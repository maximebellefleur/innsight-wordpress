<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Places - server-side Google Places (New) integration with a persistent
 * per-POI cache. The API key stays on the server so it's never exposed
 * to visitors; browsers request enrichment through /wp-json/innsight/v1/places
 * which reads the cache and (asynchronously) refreshes stale rows.
 *
 * Cache lifetime: 30 days. Reasoning:
 *   - Google Places facts (hours, rating, phone) move slowly.
 *   - 30 days keeps API costs bounded even on a large POI catalogue.
 *   - Fresh enough that visitors don't get seriously outdated data.
 *
 * Refresh strategy:
 *   1. Sync request (visitor opens POI, cache < 30d)   -> return cached instantly.
 *   2. Sync request, cache stale (>= 30d)              -> return cached + flag
 *                                                          `stale:true, refreshing:true`,
 *                                                          schedule single-shot cron
 *                                                          to refetch in the background.
 *   3. Sync request, no cache                          -> schedule single-shot cron,
 *                                                          return `data:null, refreshing:true`.
 *                                                          Client polls until data lands.
 *   4. Nightly cron (opt-in)                           -> proactive sweep of every
 *                                                          POI whose row is missing or
 *                                                          older than 30 days. Bounded
 *                                                          batch (25/run) so we don't
 *                                                          exhaust an admin's Google
 *                                                          quota in a single tick.
 */
final class Places {

    private const TABLE     = 'innsight_places';
    private const CRON_ONE  = 'innsight_places_refresh_one';
    private const CRON_ALL  = 'innsight_places_daily';
    private const TTL_DAYS  = 30;
    private const BATCH     = 25;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Install / upgrade the places cache table. Called from the plugin
     * activation hook; dbDelta handles the delta.
     */
    public static function install(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            poi_id VARCHAR(96) NOT NULL,
            place_id VARCHAR(160) NOT NULL DEFAULT '',
            data LONGTEXT NULL,
            fetched_at DATETIME NULL,
            error TEXT NULL,
            PRIMARY KEY  (poi_id),
            KEY fetched_at (fetched_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function register(): void {
        add_action( self::CRON_ONE, array( $this, 'cron_refresh_one' ), 10, 2 );
        add_action( self::CRON_ALL, array( $this, 'cron_refresh_all' ) );
        add_action( 'init', array( $this, 'maybe_schedule_daily' ) );
        add_action( 'admin_post_innsight_places_refresh', array( $this, 'handle_admin_refresh' ) );
    }

    /**
     * Enrichment status snapshot for the Settings page. Uses the
     * current DataSource POI list as the "expected" universe so
     * "cached X of Y" is accurate against what visitors actually see
     * on the map.
     *
     * @return array{total:int,cached:int,fresh:int,stale:int,errored:int,last_fetch:?string}
     */
    public function status(): array {
        $plugin = \Innsight\Plugin::instance();
        try {
            $intermediate = $plugin->data_source()->build( array( 'post_id' => 0, 'viewmode' => 'multi' ) );
        } catch ( \Throwable $e ) {
            return array( 'total' => 0, 'cached' => 0, 'fresh' => 0, 'stale' => 0, 'errored' => 0, 'last_fetch' => null );
        }
        $ids = array_filter( array_map( static function ( $p ) { return isset( $p['id'] ) ? (string) $p['id'] : ''; }, (array) ( $intermediate['pois'] ?? array() ) ) );
        $total = count( $ids );
        if ( $total === 0 ) {
            return array( 'total' => 0, 'cached' => 0, 'fresh' => 0, 'stale' => 0, 'errored' => 0, 'last_fetch' => null );
        }

        global $wpdb;
        $table = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT poi_id, fetched_at, error, data IS NOT NULL AS has_data FROM {$table} WHERE poi_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A );

        $now       = time();
        $ttl       = self::TTL_DAYS * DAY_IN_SECONDS;
        $cached    = 0;
        $fresh     = 0;
        $stale     = 0;
        $errored   = 0;
        $last_ts   = 0;
        foreach ( $rows as $r ) {
            $ts = strtotime( ( $r['fetched_at'] ?? '' ) . ' UTC' );
            if ( ! empty( $r['error'] ) && empty( $r['has_data'] ) ) { $errored++; continue; }
            $cached++;
            if ( $ts && $now - $ts < $ttl ) $fresh++; else $stale++;
            if ( $ts > $last_ts ) $last_ts = $ts;
        }
        return array(
            'total'      => $total,
            'cached'     => $cached,
            'fresh'      => $fresh,
            'stale'      => $stale,
            'errored'    => $errored,
            'last_fetch' => $last_ts ? gmdate( 'Y-m-d H:i:s', $last_ts ) : null,
        );
    }

    /**
     * Synchronously refresh the next N POIs that are missing or older
     * than 30 days. Called from the "Refresh next 25 stale POIs" admin
     * button. Returns the number of rows refreshed so the notice can
     * tell the admin exactly how far they got.
     */
    public function refresh_batch( int $size = 25 ): int {
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        if ( $api_key === '' ) return 0;

        $plugin = \Innsight\Plugin::instance();
        try {
            $intermediate = $plugin->data_source()->build( array( 'post_id' => 0, 'viewmode' => 'multi' ) );
        } catch ( \Throwable $e ) {
            return 0;
        }
        $pois = array();
        foreach ( (array) ( $intermediate['pois'] ?? array() ) as $p ) {
            if ( ! empty( $p['id'] ) ) $pois[ (string) $p['id'] ] = $p;
        }
        if ( empty( $pois ) ) return 0;

        global $wpdb;
        $table = self::table_name();
        $ids   = array_keys( $pois );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT poi_id, fetched_at FROM {$table} WHERE poi_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A );
        $cache_map = array();
        foreach ( $rows as $r ) $cache_map[ $r['poi_id'] ] = $r['fetched_at'];

        $threshold = time() - ( self::TTL_DAYS * DAY_IN_SECONDS );
        $todo = array();
        foreach ( $ids as $id ) {
            $ts = isset( $cache_map[ $id ] ) ? strtotime( $cache_map[ $id ] . ' UTC' ) : 0;
            if ( ! $ts || $ts < $threshold ) $todo[] = $id;
            if ( count( $todo ) >= $size ) break;
        }

        $done = 0;
        foreach ( $todo as $id ) {
            $this->refresh( $id, $pois[ $id ] );
            $done++;
            usleep( 200 * 1000 );
        }
        return $done;
    }

    /**
     * Admin-post handler for the "Refresh next 25 stale POIs" button
     * in Settings. Verifies nonce + cap, runs one batch, redirects
     * back with a query flag the admin page picks up to show a notice.
     */
    public function handle_admin_refresh(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        }
        check_admin_referer( 'innsight_places_refresh' );
        // 25 = same batch as the nightly cron. Long-running enough to
        // catch progress, short enough to complete inside PHP's default
        // max_execution_time even on modest hosts.
        $done = $this->refresh_batch( 25 );
        wp_safe_redirect( add_query_arg( 'innsight_places_done', (int) $done, wp_get_referer() ?: admin_url( 'admin.php?page=innsight' ) ) );
        exit;
    }

    /**
     * Schedule (or clear) the nightly refresh event based on the
     * admin setting. Idempotent on every request.
     */
    public function maybe_schedule_daily(): void {
        $on = ! empty( innsight_settings( 'places_cron_enabled', 0 ) );
        $ts = wp_next_scheduled( self::CRON_ALL );
        if ( $on && ! $ts ) {
            wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::CRON_ALL );
        }
        if ( ! $on && $ts ) {
            wp_unschedule_event( $ts, self::CRON_ALL );
        }
    }

    /**
     * Public entry-point for the REST controller. Returns the payload the
     * skin renders. `$poi_data` (from the current JSON build) supplies
     * lat/lon + title so we can search Google when the POI doesn't
     * already carry a googlePlaceId.
     *
     * @return array{data: ?array, fetchedAt: ?string, stale: bool, refreshing: bool}
     */
    public function get_for_visitor( string $poi_id, array $poi_data = array() ): array {
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        $enabled = ! empty( innsight_settings( 'google_places_enable', 0 ) ) && $api_key !== '';
        if ( ! $enabled || $poi_id === '' ) {
            return array( 'data' => null, 'fetchedAt' => null, 'stale' => false, 'refreshing' => false );
        }

        $row = $this->get_cached( $poi_id );
        $now = time();
        $ttl = self::TTL_DAYS * DAY_IN_SECONDS;

        // Fresh cache -> return instantly, no refresh.
        if ( $row && ! empty( $row['fetched_at'] ) ) {
            $age = $now - strtotime( $row['fetched_at'] . ' UTC' );
            if ( $age < $ttl && ! empty( $row['data'] ) ) {
                return array(
                    'data'       => $row['data'],
                    'fetchedAt'  => $row['fetched_at'],
                    'stale'      => false,
                    'refreshing' => false,
                );
            }
        }

        // Stale or missing -> schedule background refetch. wp_cron with
        // 0-delay means "run on the next admin-ajax / heartbeat tick"
        // which is usually seconds after this response ships. The client
        // polls to pick up the fresh row.
        $args = array( $poi_id, $poi_data );
        if ( ! wp_next_scheduled( self::CRON_ONE, $args ) ) {
            wp_schedule_single_event( time() + 1, self::CRON_ONE, $args );
        }

        // Stale-while-revalidate: hand the visitor the old row so the
        // sheet doesn't blank out, plus flags so the client can pulse
        // + poll for the new one.
        if ( $row && ! empty( $row['data'] ) ) {
            return array(
                'data'       => $row['data'],
                'fetchedAt'  => $row['fetched_at'],
                'stale'      => true,
                'refreshing' => true,
            );
        }

        return array( 'data' => null, 'fetchedAt' => null, 'stale' => false, 'refreshing' => true );
    }

    /* ─── Cache reads / writes ───────────────────────────────────────────── */

    /**
     * Return the raw row (or null) for a POI. Data comes back JSON-decoded.
     */
    public function get_cached( string $poi_id ): ?array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE poi_id = %s", $poi_id ), ARRAY_A );
        if ( ! $row ) return null;
        $row['data'] = ! empty( $row['data'] ) ? json_decode( (string) $row['data'], true ) : null;
        return $row;
    }

    private function write_row( string $poi_id, string $place_id, ?array $data, ?string $error ): void {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->replace( $table, array(
            'poi_id'     => $poi_id,
            'place_id'   => $place_id,
            'data'       => $data ? wp_json_encode( $data ) : null,
            'fetched_at' => gmdate( 'Y-m-d H:i:s' ),
            'error'      => $error,
        ), array( '%s', '%s', '%s', '%s', '%s' ) );
    }

    /* ─── Cron handlers ───────────────────────────────────────────────────── */

    /**
     * Single-shot cron: refresh one POI. Invoked from
     * `get_for_visitor()` when the cache is stale/missing.
     *
     * @param string $poi_id
     * @param array  $poi_data lat/lon/title from the current JSON build.
     */
    public function cron_refresh_one( string $poi_id, array $poi_data ): void {
        $this->refresh( $poi_id, $poi_data );
    }

    /**
     * Nightly cron: refresh a bounded batch of POIs whose cache is
     * missing or older than 30 days. Uses the current
     * JsonBuilder to source the POI list so it always reflects live
     * WP data.
     */
    public function cron_refresh_all(): void {
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        if ( $api_key === '' ) return;

        // Pull the current POI list. Use viewmode=multi + post_id=0
        // which the DataSource treats as "all POIs, site-wide".
        $plugin = \Innsight\Plugin::instance();
        try {
            $intermediate = $plugin->data_source()->build( array( 'post_id' => 0, 'viewmode' => 'multi' ) );
        } catch ( \Throwable $e ) {
            error_log( '[innsight/places] cron POI build failed: ' . $e->getMessage() );
            return;
        }

        $pois = array();
        foreach ( (array) ( $intermediate['pois'] ?? array() ) as $p ) {
            if ( ! empty( $p['id'] ) ) $pois[ (string) $p['id'] ] = $p;
        }
        if ( empty( $pois ) ) return;

        // Cached rows -> know which are stale.
        global $wpdb;
        $table = self::table_name();
        $ids   = array_keys( $pois );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT poi_id, fetched_at FROM {$table} WHERE poi_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A );
        $cache_map = array();
        foreach ( $rows as $r ) $cache_map[ $r['poi_id'] ] = $r['fetched_at'];

        $threshold = time() - ( self::TTL_DAYS * DAY_IN_SECONDS );
        $stale_ids = array();
        foreach ( $ids as $id ) {
            $ts = isset( $cache_map[ $id ] ) ? strtotime( $cache_map[ $id ] . ' UTC' ) : 0;
            if ( ! $ts || $ts < $threshold ) $stale_ids[] = $id;
            if ( count( $stale_ids ) >= self::BATCH ) break;
        }

        foreach ( $stale_ids as $id ) {
            $this->refresh( $id, $pois[ $id ] );
            // Politeness pause so we don't hammer Google in a tight loop.
            usleep( 200 * 1000 );
        }
    }

    /* ─── Google API ──────────────────────────────────────────────────────── */

    /**
     * Do the actual Google Places lookup + write the cache row. Resolves
     * the placeId (searchText when the POI doesn't carry one), fetches
     * Place Details, reshapes, stores. Failures write an `error` row
     * with a null data payload so the client doesn't hammer a broken
     * POI repeatedly.
     */
    public function refresh( string $poi_id, array $poi_data ): void {
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        if ( $api_key === '' ) return;

        try {
            $place_id = isset( $poi_data['googlePlaceId'] ) && $poi_data['googlePlaceId'] !== ''
                ? (string) $poi_data['googlePlaceId']
                : $this->search_place_id( $poi_data, $api_key );

            if ( $place_id === '' ) {
                $this->write_row( $poi_id, '', null, 'no_match' );
                return;
            }

            $details = $this->fetch_details( $place_id, $api_key );
            $shaped  = $this->shape( $details, $api_key, $poi_data );
            $this->write_row( $poi_id, $place_id, $shaped, null );
        } catch ( \Throwable $e ) {
            $this->write_row( $poi_id, isset( $place_id ) ? $place_id : '', null, mb_substr( $e->getMessage(), 0, 240 ) );
        }
    }

    private function search_place_id( array $poi, string $api_key ): string {
        $query = trim( (string) ( $poi['title'] ?? '' ) );
        if ( $query === '' ) return '';
        $lat = (float) ( $poi['lat'] ?? 0 );
        $lon = (float) ( $poi['lon'] ?? 0 );

        $body = array( 'textQuery' => $query, 'maxResultCount' => 1 );
        if ( $lat && $lon ) {
            $body['locationBias'] = array(
                'circle' => array(
                    'center' => array( 'latitude' => $lat, 'longitude' => $lon ),
                    'radius' => 500,
                ),
            );
        }

        $res = wp_remote_post( 'https://places.googleapis.com/v1/places:searchText', array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'X-Goog-Api-Key'    => $api_key,
                'X-Goog-FieldMask'  => 'places.id,places.displayName',
            ),
            'body'    => wp_json_encode( $body ),
        ) );
        if ( is_wp_error( $res ) ) throw new \RuntimeException( 'searchText: ' . $res->get_error_message() );
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) throw new \RuntimeException( 'searchText HTTP ' . $code );
        $json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        return isset( $json['places'][0]['id'] ) ? (string) $json['places'][0]['id'] : '';
    }

    private function fetch_details( string $place_id, string $api_key ): array {
        $url = 'https://places.googleapis.com/v1/places/' . rawurlencode( $place_id );
        $fields = implode( ',', array(
            'id', 'displayName',
            'rating', 'userRatingCount',
            'currentOpeningHours', 'regularOpeningHours',
            'photos',
            'googleMapsUri', 'websiteUri',
            'nationalPhoneNumber',
            'reviews',
        ) );
        $res = wp_remote_get( $url, array(
            'timeout' => 8,
            'headers' => array(
                'X-Goog-Api-Key'   => $api_key,
                'X-Goog-FieldMask' => $fields,
            ),
        ) );
        if ( is_wp_error( $res ) ) throw new \RuntimeException( 'details: ' . $res->get_error_message() );
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) throw new \RuntimeException( 'details HTTP ' . $code );
        return (array) ( json_decode( (string) wp_remote_retrieve_body( $res ), true ) ?: array() );
    }

    /**
     * Reshape the Places response into the flat shape our skin templates
     * expect. Same keys as the pre-0.7 client-side google-places.js so the
     * skin needs no rewrites.
     */
    private function shape( array $details, string $api_key, array $poi_data ): array {
        $open    = $details['currentOpeningHours'] ?? $details['regularOpeningHours'] ?? null;
        $weekday = ( $open && isset( $open['weekdayDescriptions'] ) ) ? $open['weekdayDescriptions'] : array();
        $today_idx = (int) current_time( 'w' );  // 0 = Sun, matches Places order in most locales
        $todays = isset( $weekday[ $today_idx ] ) ? (string) $weekday[ $today_idx ] : '';
        // weekdayDescriptions come like "Monday: 6:00 AM – 6:30 PM"; strip
        // the day-of-week prefix so we can show a clean "6:00 AM – 6:30 PM".
        if ( $todays && strpos( $todays, ':' ) !== false ) {
            $parts = explode( ':', $todays, 2 );
            $todays = trim( $parts[1] );
        }

        $photo_url = '';
        if ( ! empty( $details['photos'][0]['name'] ) ) {
            $photo_url = 'https://places.googleapis.com/v1/' . $details['photos'][0]['name']
                . '/media?maxHeightPx=720&key=' . rawurlencode( $api_key );
        }

        // Directions URL: uses lat/lon from the POI (not from Google) so
        // even if Places drift the coordinate slightly, the direction
        // matches what's on the map. Fallback to placeId when we have it
        // (survives lat/lon inaccuracy in the POI).
        $lat = (float) ( $poi_data['lat'] ?? 0 );
        $lon = (float) ( $poi_data['lon'] ?? 0 );
        $directions_uri = ( $lat && $lon )
            ? sprintf( 'https://www.google.com/maps/dir/?api=1&destination=%s,%s', $lat, $lon )
            : (string) ( $details['googleMapsUri'] ?? '' );
        if ( ! empty( $details['id'] ) && $lat && $lon ) {
            $directions_uri .= '&destination_place_id=' . rawurlencode( $details['id'] );
        }

        return array(
            'placeId'         => (string) ( $details['id'] ?? '' ),
            'rating'          => isset( $details['rating'] ) ? (float) $details['rating'] : null,
            'userRatingCount' => isset( $details['userRatingCount'] ) ? (int) $details['userRatingCount'] : null,
            'openNow'         => $open ? (bool) ( $open['openNow'] ?? false ) : null,
            'todaysHours'     => $todays,
            'weekdayHours'    => array_values( $weekday ),
            'googleMapsUri'   => (string) ( $details['googleMapsUri'] ?? '' ),
            'reviewsUri'      => ! empty( $details['googleMapsUri'] ) ? $details['googleMapsUri'] . '&hl=en' : '',
            'directionsUri'   => $directions_uri,
            'websiteUri'      => (string) ( $details['websiteUri'] ?? '' ),
            'phone'           => (string) ( $details['nationalPhoneNumber'] ?? '' ),
            'photoUrl'        => $photo_url,
            'reviews'         => array_map( static function ( $r ) {
                return array(
                    'author' => (string) ( $r['authorAttribution']['displayName'] ?? '' ),
                    'rating' => isset( $r['rating'] ) ? (float) $r['rating'] : null,
                    'text'   => (string) ( $r['text']['text'] ?? '' ),
                    'when'   => (string) ( $r['relativePublishTimeDescription'] ?? '' ),
                    'uri'    => (string) ( $r['authorAttribution']['uri'] ?? '' ),
                );
            }, array_slice( (array) ( $details['reviews'] ?? array() ), 0, 5 ) ),
        );
    }
}
