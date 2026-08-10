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
        add_action( 'admin_post_innsight_places_test', array( $this, 'handle_admin_test' ) );
        add_action( 'admin_menu', array( $this, 'register_debug_menu' ), 40 );
    }

    /**
     * Standalone debug page under Innsight -> Places debug. Shows the
     * RAW HTTP response from Google - no fancy formatting, no
     * translation, no transient dance. Bypasses every layer of the
     * admin UI so if the previous status card was lying, this page
     * shows the truth: exact URL, exact headers sent, exact bytes
     * received.
     */
    public function register_debug_menu(): void {
        add_submenu_page(
            'innsight',
            __( 'Places debug', 'innsight' ),
            __( 'Places debug', 'innsight' ),
            'manage_options',
            'innsight-places-debug',
            array( $this, 'render_debug_page' )
        );
    }

    public function render_debug_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $api_key   = trim( (string) innsight_settings( 'google_places_key', '' ) );
        $enabled   = ! empty( innsight_settings( 'google_places_enable', 0 ) );
        $query     = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['q'] ) ) : 'Eiffel Tower Paris';
        $poi_id    = isset( $_POST['poi_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['poi_id'] ) ) : '';
        $running   = isset( $_POST['run'] ) && check_admin_referer( 'innsight_places_debug' );

        echo '<div class="wrap"><h1>' . esc_html__( 'Innsight - Places debug', 'innsight' ) . '</h1>';

        // Key + enable status.
        // ─ Raw table stats - bypass status() entirely ─
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row_count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $with_data      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE data IS NOT NULL AND data <> ''" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $with_error     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE error IS NOT NULL AND error <> ''" );
        $fresh_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::TTL_DAYS * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $fresh_count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE data IS NOT NULL AND data <> '' AND fetched_at >= %s", $fresh_cutoff ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $last_wpdb_err  = $wpdb->last_error;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $recent_rows    = $wpdb->get_results( "SELECT poi_id, place_id, fetched_at, error, CHAR_LENGTH(COALESCE(data,'')) AS data_bytes FROM {$table} ORDER BY fetched_at DESC LIMIT 10", ARRAY_A );

        echo '<h2>' . esc_html__( 'Raw table stats (bypasses status())', 'innsight' ) . '</h2>';
        echo '<table class="widefat striped" style="max-width:640px">';
        echo '<tbody>';
        echo '<tr><th style="width:40%">Table</th><td><code>' . esc_html( $table ) . '</code></td></tr>';
        echo '<tr><th>Total rows</th><td><strong>' . (int) $row_count . '</strong></td></tr>';
        echo '<tr><th>Rows with data (blob)</th><td><strong>' . (int) $with_data . '</strong></td></tr>';
        echo '<tr><th>Rows with error text</th><td><strong>' . (int) $with_error . '</strong></td></tr>';
        echo '<tr><th>Fresh rows (data + < 30d)</th><td><strong>' . (int) $fresh_count . '</strong></td></tr>';
        echo '<tr><th>Fresh cutoff (UTC)</th><td><code>' . esc_html( $fresh_cutoff ) . '</code></td></tr>';
        echo '<tr><th>Last wpdb error</th><td>' . ( $last_wpdb_err !== '' ? '<code style="color:#d63638">' . esc_html( $last_wpdb_err ) . '</code>' : '—' ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h3 style="margin-top:14px">' . esc_html__( 'Last 10 rows (newest first)', 'innsight' ) . '</h3>';
        if ( empty( $recent_rows ) ) {
            echo '<p style="color:#d63638"><strong>' . esc_html__( 'Table is empty. If Refresh reports "25 succeeded" but this table stays empty, writes are silently failing (check the last wpdb error above, and PHP error log).', 'innsight' ) . '</strong></p>';
        } else {
            echo '<table class="widefat striped" style="max-width:820px">';
            echo '<thead><tr><th>poi_id</th><th>place_id</th><th>fetched_at (UTC)</th><th>data bytes</th><th>error</th></tr></thead><tbody>';
            foreach ( $recent_rows as $r ) {
                echo '<tr>';
                echo '<td><code>' . esc_html( $r['poi_id'] ) . '</code></td>';
                echo '<td><code style="font-size:11px">' . esc_html( $r['place_id'] ?: '—' ) . '</code></td>';
                echo '<td>' . esc_html( $r['fetched_at'] ?: '—' ) . '</td>';
                echo '<td style="font-variant-numeric:tabular-nums">' . (int) $r['data_bytes'] . '</td>';
                echo '<td>' . ( $r['error'] ? '<code style="color:#d63638">' . esc_html( $r['error'] ) . '</code>' : '—' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // ─ POI ID sanity check ─
        // If DataSource IDs don't match the poi_id column, status() returns 0
        // because its IN () query never matches. Show side-by-side.
        try {
            $intermediate = \Innsight\Plugin::instance()->data_source()->build( array( 'post_id' => 0, 'viewmode' => 'multi' ) );
            $ds_ids = array_slice( array_filter( array_map( static function ( $p ) { return isset( $p['id'] ) ? (string) $p['id'] : ''; }, (array) ( $intermediate['pois'] ?? array() ) ) ), 0, 10 );
        } catch ( \Throwable $e ) {
            $ds_ids = array( 'ERROR: ' . $e->getMessage() );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $cache_ids = $wpdb->get_col( "SELECT poi_id FROM {$table} ORDER BY fetched_at DESC LIMIT 10" );

        echo '<h3 style="margin-top:14px">' . esc_html__( 'POI id comparison (first 10 of each)', 'innsight' ) . '</h3>';
        echo '<p style="color:#646970;font-size:12px">' . esc_html__( 'If these two columns do not overlap, status() will always report 0 fresh - the DataSource is emitting different ids than what refresh() writes.', 'innsight' ) . '</p>';
        echo '<table class="widefat striped" style="max-width:820px">';
        echo '<thead><tr><th>DataSource poi ids</th><th>Cached poi ids</th></tr></thead><tbody>';
        $max = max( count( $ds_ids ), count( $cache_ids ), 1 );
        for ( $i = 0; $i < $max; $i++ ) {
            $ds = $ds_ids[ $i ] ?? '—';
            $ch = $cache_ids[ $i ] ?? '—';
            $match = in_array( $ch, $ds_ids, true ) || in_array( $ds, (array) $cache_ids, true );
            echo '<tr>';
            echo '<td><code>' . esc_html( $ds ) . '</code></td>';
            echo '<td><code>' . esc_html( $ch ) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Overlap summary.
        $overlap = count( array_intersect( $ds_ids, (array) $cache_ids ) );
        if ( $cache_ids && $overlap === 0 ) {
            echo '<p style="color:#d63638;font-weight:600;margin-top:8px">'
                . esc_html__( 'NO OVERLAP between DataSource ids and cached ids. This is the bug: refresh() writes with the id refresh_batch() gives it, and status() queries with DataSource ids - they should be identical but they are not.', 'innsight' )
                . '</p>';
        } elseif ( $cache_ids ) {
            echo '<p style="color:#646970;margin-top:8px;font-size:12px">'
                . sprintf( esc_html__( 'Overlap: %d of %d cached ids appear in the DataSource first-10.', 'innsight' ), (int) $overlap, count( $cache_ids ) )
                . '</p>';
        }

        echo '<h2>' . esc_html__( 'Current configuration', 'innsight' ) . '</h2>';
        echo '<ul style="margin-left:20px;list-style:disc">';
        echo '<li>' . esc_html__( 'Enrichment enabled', 'innsight' ) . ': <strong>' . ( $enabled ? esc_html__( 'YES', 'innsight' ) : esc_html__( 'NO', 'innsight' ) ) . '</strong></li>';
        echo '<li>' . esc_html__( 'API key present', 'innsight' ) . ': <strong>' . ( $api_key !== '' ? esc_html__( 'YES', 'innsight' ) : esc_html__( 'NO', 'innsight' ) ) . '</strong>';
        if ( $api_key !== '' ) {
            $masked = strlen( $api_key ) > 12
                ? substr( $api_key, 0, 4 ) . str_repeat( '·', 6 ) . substr( $api_key, -4 )
                : str_repeat( '·', strlen( $api_key ) );
            echo ' <code>' . esc_html( $masked ) . '</code>';
            echo ' <span style="color:#646970">(length ' . (int) strlen( $api_key ) . ')</span>';
        }
        echo '</li>';
        echo '<li>' . esc_html__( 'Nightly cron on', 'innsight' ) . ': <strong>' . ( ! empty( innsight_settings( 'places_cron_enabled', 0 ) ) ? 'YES' : 'NO' ) . '</strong></li>';
        echo '<li>' . esc_html__( 'REST endpoint', 'innsight' ) . ': <code>' . esc_html( rest_url( 'innsight/v1/places' ) ) . '</code></li>';
        echo '</ul>';

        // Curl command the admin can paste in a terminal.
        echo '<h2>' . esc_html__( 'Bypass everything - run this curl from your server', 'innsight' ) . '</h2>';
        echo '<pre style="background:#1e1e1e;color:#dcdcdc;padding:12px 16px;overflow-x:auto;border-radius:4px;font-size:12px">'
            . "curl -X POST 'https://places.googleapis.com/v1/places:searchText' \\\n"
            . "  -H 'Content-Type: application/json' \\\n"
            . "  -H 'X-Goog-Api-Key: " . esc_html( $api_key ?: 'YOUR_KEY' ) . "' \\\n"
            . "  -H 'X-Goog-FieldMask: places.id,places.displayName' \\\n"
            . "  -d '{\"textQuery\":\"" . esc_html( $query ) . "\",\"maxResultCount\":1}'"
            . '</pre>';

        // Test form.
        echo '<h2>' . esc_html__( 'Run test from inside WordPress', 'innsight' ) . '</h2>';
        echo '<p>' . esc_html__( 'Same HTTP call as above, made from your server via wp_remote_post(). Full raw response printed below.', 'innsight' ) . '</p>';
        echo '<form method="post" style="display:flex;gap:10px;align-items:flex-end;max-width:820px;flex-wrap:wrap">';
        wp_nonce_field( 'innsight_places_debug' );
        echo '<label style="flex:1 1 320px"><span style="display:block;font-weight:600;margin-bottom:4px">' . esc_html__( 'Text query', 'innsight' ) . '</span>';
        echo '<input type="text" name="q" value="' . esc_attr( $query ) . '" class="regular-text" style="width:100%"></label>';
        echo '<label style="flex:1 1 320px"><span style="display:block;font-weight:600;margin-bottom:4px">' . esc_html__( 'Or refresh a specific POI id', 'innsight' ) . '</span>';
        echo '<input type="text" name="poi_id" value="' . esc_attr( $poi_id ) . '" placeholder="e.g. act-123" class="regular-text" style="width:100%"></label>';
        echo '<button type="submit" name="run" value="1" class="button button-primary">' . esc_html__( 'Run test', 'innsight' ) . '</button>';
        echo '</form>';

        if ( ! $running ) { echo '</div>'; return; }

        // ─ Actually run the tests ─
        echo '<h2>' . esc_html__( 'Result', 'innsight' ) . '</h2>';
        if ( $api_key === '' ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'No API key configured. Save one in Settings first.', 'innsight' ) . '</p></div>';
            echo '</div>'; return;
        }

        // Raw Places search.
        $started = microtime( true );
        $res = wp_remote_post( 'https://places.googleapis.com/v1/places:searchText', array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $api_key,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.rating,places.userRatingCount',
            ),
            'body'    => wp_json_encode( array( 'textQuery' => $query, 'maxResultCount' => 3 ) ),
        ) );
        $elapsed = round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $res ) ) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'WP_Error', 'innsight' ) . ':</strong> ' . esc_html( $res->get_error_message() ) . '</p></div>';
        } else {
            $code = (int) wp_remote_retrieve_response_code( $res );
            $body = (string) wp_remote_retrieve_body( $res );
            $lvl  = $code === 200 ? 'success' : 'error';
            echo '<div class="notice notice-' . esc_attr( $lvl ) . '"><p>';
            echo '<strong>HTTP ' . (int) $code . '</strong> - '
                . sprintf( esc_html__( 'response in %dms.', 'innsight' ), $elapsed );
            echo '</p></div>';
            echo '<h3>' . esc_html__( 'Response headers', 'innsight' ) . '</h3>';
            $headers = wp_remote_retrieve_headers( $res );
            echo '<pre style="background:#f6f7f7;padding:10px;border:1px solid #dcdcde;overflow-x:auto;font-size:12px">';
            foreach ( (array) ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers ) as $k => $v ) {
                echo esc_html( $k . ': ' . ( is_array( $v ) ? implode( ', ', $v ) : (string) $v ) ) . "\n";
            }
            echo '</pre>';
            echo '<h3>' . esc_html__( 'Response body', 'innsight' ) . '</h3>';
            $pretty = json_encode( json_decode( $body, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            echo '<pre style="background:#f6f7f7;padding:10px;border:1px solid #dcdcde;overflow-x:auto;font-size:12px;max-height:400px">'
                . esc_html( $pretty !== false ? $pretty : $body )
                . '</pre>';
        }

        // POI refresh test.
        if ( $poi_id !== '' ) {
            echo '<h3>' . esc_html__( 'POI refresh test', 'innsight' ) . '</h3>';
            $plugin = \Innsight\Plugin::instance();
            try {
                $intermediate = $plugin->data_source()->build( array( 'post_id' => 0, 'viewmode' => 'multi' ) );
            } catch ( \Throwable $e ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
                echo '</div>'; return;
            }
            $found = null;
            foreach ( (array) ( $intermediate['pois'] ?? array() ) as $p ) {
                if ( ! empty( $p['id'] ) && (string) $p['id'] === $poi_id ) { $found = $p; break; }
            }
            if ( ! $found ) {
                echo '<div class="notice notice-warning"><p>' . sprintf( esc_html__( 'POI id "%s" not found in current DataSource output. Check the id.', 'innsight' ), esc_html( $poi_id ) ) . '</p></div>';
            } else {
                echo '<pre style="background:#f6f7f7;padding:10px;border:1px solid #dcdcde;overflow-x:auto;font-size:12px">'
                    . 'POI: ' . esc_html( wp_json_encode( array( 'id' => $found['id'], 'title' => $found['title'] ?? '', 'lat' => $found['lat'] ?? '', 'lon' => $found['lon'] ?? '', 'googlePlaceId' => $found['googlePlaceId'] ?? '' ), JSON_UNESCAPED_UNICODE ) )
                    . '</pre>';
                $status = $this->refresh( $poi_id, $found );
                echo '<p>' . esc_html__( 'refresh() status', 'innsight' ) . ': <strong>' . esc_html( $status ) . '</strong></p>';
                $row = $this->get_cached( $poi_id );
                echo '<pre style="background:#f6f7f7;padding:10px;border:1px solid #dcdcde;overflow-x:auto;font-size:12px;max-height:400px">'
                    . esc_html( wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
                    . '</pre>';
            }
        }

        echo '</div>';
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
     * than 30 days. Returns a per-batch report so admins can see how
     * many actually succeeded vs. failed vs. found nothing on Google.
     *
     * @return array{attempted:int, succeeded:int, failed:int, no_match:int}
     */
    public function refresh_batch( int $size = 25 ): array {
        $report = array( 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'no_match' => 0 );
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        if ( $api_key === '' ) return $report;

        $plugin = \Innsight\Plugin::instance();
        try {
            $intermediate = $plugin->data_source()->build( array( 'post_id' => 0, 'viewmode' => 'multi' ) );
        } catch ( \Throwable $e ) {
            return $report;
        }
        $pois = array();
        foreach ( (array) ( $intermediate['pois'] ?? array() ) as $p ) {
            if ( ! empty( $p['id'] ) ) $pois[ (string) $p['id'] ] = $p;
        }
        if ( empty( $pois ) ) return $report;

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

        foreach ( $todo as $id ) {
            $report['attempted']++;
            $result = $this->refresh( $id, $pois[ $id ] );
            if ( $result === 'ok' )        $report['succeeded']++;
            elseif ( $result === 'no_match' ) $report['no_match']++;
            else                           $report['failed']++;
            usleep( 200 * 1000 );
        }
        return $report;
    }

    /**
     * Admin-post handler for the "Refresh next N POIs" button. Returns
     * per-status counts so the admin sees whether the refresh actually
     * pulled data or just wrote error rows.
     */
    public function handle_admin_refresh(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        }
        check_admin_referer( 'innsight_places_refresh' );
        $report = $this->refresh_batch( 25 );
        wp_safe_redirect( add_query_arg( array(
            'innsight_places_done'     => (int) $report['succeeded'],
            'innsight_places_failed'   => (int) $report['failed'],
            'innsight_places_nomatch'  => (int) $report['no_match'],
            'innsight_places_attempted' => (int) $report['attempted'],
        ), wp_get_referer() ?: admin_url( 'admin.php?page=innsight' ) ) );
        exit;
    }

    /**
     * Ping the Places API with a well-known query so an admin can
     * verify their API key + billing setup without waiting for a
     * cron cycle or a visitor to open a sheet. Returns a short
     * status string with a human-readable outcome.
     */
    public function test_api_key(): array {
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        if ( $api_key === '' ) {
            return array( 'ok' => false, 'message' => __( 'No API key configured.', 'innsight' ) );
        }
        try {
            $res = wp_remote_post( 'https://places.googleapis.com/v1/places:searchText', array(
                'timeout' => 8,
                'headers' => array(
                    'Content-Type'      => 'application/json',
                    'X-Goog-Api-Key'    => $api_key,
                    'X-Goog-FieldMask'  => 'places.id,places.displayName',
                ),
                'body'    => wp_json_encode( array( 'textQuery' => 'Eiffel Tower Paris', 'maxResultCount' => 1 ) ),
            ) );
            if ( is_wp_error( $res ) ) {
                return array( 'ok' => false, 'message' => 'HTTP error: ' . $res->get_error_message() );
            }
            $code = (int) wp_remote_retrieve_response_code( $res );
            $body = (string) wp_remote_retrieve_body( $res );
            if ( $code === 200 ) {
                $json = json_decode( $body, true );
                if ( ! empty( $json['places'][0]['id'] ) ) {
                    return array( 'ok' => true, 'message' => sprintf( __( 'OK - resolved "%s" (%s).', 'innsight' ), $json['places'][0]['displayName']['text'] ?? '?', $json['places'][0]['id'] ) );
                }
                return array( 'ok' => false, 'message' => __( 'API returned 200 but no places matched. Unexpected.', 'innsight' ) );
            }
            $err = '';
            $json = json_decode( $body, true );
            if ( isset( $json['error']['message'] ) ) $err = (string) $json['error']['message'];
            return array( 'ok' => false, 'message' => 'HTTP ' . $code . ( $err ? ' - ' . $err : ' - ' . mb_substr( $body, 0, 200 ) ) );
        } catch ( \Throwable $e ) {
            return array( 'ok' => false, 'message' => 'Exception: ' . $e->getMessage() );
        }
    }

    /**
     * Admin-post handler for the "Test API key" button. Runs one
     * Places search and stashes the result in a transient so the
     * Settings page can render it after the redirect.
     */
    public function handle_admin_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        }
        check_admin_referer( 'innsight_places_test' );
        $result = $this->test_api_key();
        set_transient( 'innsight_places_test_result', $result, MINUTE_IN_SECONDS * 5 );
        wp_safe_redirect( add_query_arg( 'innsight_places_test', '1', wp_get_referer() ?: admin_url( 'admin.php?page=innsight' ) ) );
        exit;
    }

    /**
     * Return the most recent N rows for the debugger table on the
     * Settings page. Includes error text so admins can see WHY the
     * refresh isn't landing.
     *
     * @return array<int,array{poi_id:string,place_id:string,fetched_at:string,has_data:bool,error:?string}>
     */
    public function recent_activity( int $limit = 20 ): array {
        global $wpdb;
        $table = self::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT poi_id, place_id, fetched_at, error, data IS NOT NULL AS has_data
             FROM {$table} ORDER BY fetched_at DESC LIMIT %d",
            max( 1, $limit )
        ), ARRAY_A );
        return array_map( static function ( $r ) {
            return array(
                'poi_id'     => (string) ( $r['poi_id'] ?? '' ),
                'place_id'   => (string) ( $r['place_id'] ?? '' ),
                'fetched_at' => (string) ( $r['fetched_at'] ?? '' ),
                'has_data'   => ! empty( $r['has_data'] ),
                'error'      => isset( $r['error'] ) && $r['error'] !== '' ? (string) $r['error'] : null,
            );
        }, (array) $rows );
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
    /**
     * Refresh one POI. Returns a status string so callers can
     * report success/failure without re-querying the DB:
     *   'ok'        - data cached
     *   'no_match'  - Google returned nothing for the query
     *   'no_key'    - admin has no API key configured
     *   'error'     - HTTP / API error (message stored in row)
     */
    public function refresh( string $poi_id, array $poi_data ): string {
        $api_key = trim( (string) innsight_settings( 'google_places_key', '' ) );
        if ( $api_key === '' ) return 'no_key';

        try {
            $place_id = isset( $poi_data['googlePlaceId'] ) && $poi_data['googlePlaceId'] !== ''
                ? (string) $poi_data['googlePlaceId']
                : $this->search_place_id( $poi_data, $api_key );

            if ( $place_id === '' ) {
                $this->write_row( $poi_id, '', null, 'no_match' );
                return 'no_match';
            }

            $details = $this->fetch_details( $place_id, $api_key );
            $shaped  = $this->shape( $details, $api_key, $poi_data );
            $this->write_row( $poi_id, $place_id, $shaped, null );
            return 'ok';
        } catch ( \Throwable $e ) {
            $this->write_row( $poi_id, isset( $place_id ) ? $place_id : '', null, mb_substr( $e->getMessage(), 0, 240 ) );
            return 'error';
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
