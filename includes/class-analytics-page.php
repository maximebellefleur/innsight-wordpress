<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * AnalyticsPage - admin UI for the Stats service.
 *
 * Two surfaces:
 *   1. Dashboard widget on wp-admin's Dashboard: totals + top 10 saved.
 *      Small, glanceable, non-intrusive.
 *   2. Innsight → Analytics submenu: full breakdown, sortable table,
 *      inline SVG time-series chart for the last 30 days of map loads.
 *
 * Deliberately zero JS. Zero external chart library. The chart is a
 * hand-drawn SVG polyline sized off the returned data. Keeps admin
 * page weight under 30kb and never asks the browser to parse a giant
 * dependency for a two-column dashboard.
 */
final class AnalyticsPage {

    /** @var Stats */
    private $stats;

    public function __construct( Stats $stats ) {
        $this->stats = $stats;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
    }

    public function register_menu(): void {
        add_submenu_page(
            'innsight',
            __( 'Analytics', 'innsight' ),
            __( 'Analytics', 'innsight' ),
            'manage_options',
            'innsight-analytics',
            array( $this, 'render_page' )
        );
    }

    public function register_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        wp_add_dashboard_widget(
            'innsight_dashboard_stats',
            __( 'Innsight - map activity', 'innsight' ),
            array( $this, 'render_dashboard_widget' )
        );
    }

    /* ─── Dashboard widget ─────────────────────────────────────────────── */

    public function render_dashboard_widget(): void {
        if ( ! $this->stats->enabled() ) {
            echo '<p>' . esc_html__( 'Analytics are disabled. Enable them in Settings → Innsight to start collecting map usage counts.', 'innsight' ) . '</p>';
            return;
        }

        $loads_today   = $this->stats->total( 'map_load', 0 );
        $loads_week    = $this->stats->total( 'map_load', 7 );
        $saves_today   = $this->stats->total( 'poi_save', 0 );
        $saves_week    = $this->stats->total( 'poi_save', 7 );
        $shares_week   = $this->stats->total( 'share_send', 7 );

        $top      = $this->stats->top_saved( 5, 90 );
        $resolved = $this->stats->resolve_pois( array_column( $top, 'poi_id' ) );

        // Metric grid.
        echo '<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;margin-bottom:14px">';
        $this->render_metric_tile( __( 'Loads today', 'innsight' ), $loads_today, $loads_week . ' this week' );
        $this->render_metric_tile( __( 'Saves today', 'innsight' ), $saves_today, $saves_week . ' this week' );
        $this->render_metric_tile( __( 'Shares (7d)', 'innsight' ), $shares_week, '' );
        echo '</div>';

        // Top-saved table.
        echo '<h3 style="margin:14px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#646970">'
            . esc_html__( 'Most saved (last 90 days)', 'innsight' ) . '</h3>';
        if ( empty( $top ) ) {
            echo '<p style="color:#646970">' . esc_html__( 'No saves yet.', 'innsight' ) . '</p>';
        } else {
            echo '<table style="width:100%;border-collapse:collapse">';
            foreach ( $top as $row ) {
                $meta  = $resolved[ $row['poi_id'] ] ?? array( 'title' => $row['poi_id'], 'edit_url' => '' );
                $title = $meta['title'];
                $edit  = $meta['edit_url'];
                echo '<tr style="border-bottom:1px solid #e5e5e5">';
                echo '<td style="padding:6px 4px">';
                if ( $edit ) {
                    echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>';
                } else {
                    echo esc_html( $title );
                }
                echo '</td>';
                echo '<td style="padding:6px 4px;text-align:right;font-variant-numeric:tabular-nums"><strong>' . (int) $row['net'] . '</strong>';
                if ( $row['unsaves'] > 0 ) {
                    echo ' <span style="color:#646970;font-size:11px">(' . (int) $row['saves'] . ' - ' . (int) $row['unsaves'] . ')</span>';
                }
                echo '</td></tr>';
            }
            echo '</table>';
        }

        echo '<p style="margin-top:10px"><a href="' . esc_url( admin_url( 'admin.php?page=innsight-analytics' ) ) . '">'
            . esc_html__( 'Open full Analytics →', 'innsight' ) . '</a></p>';
    }

    private function render_metric_tile( string $label, int $value, string $sublabel ): void {
        echo '<div style="background:#f6f7f7;border:1px solid #e5e5e5;border-radius:6px;padding:10px 12px">';
        echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#646970">' . esc_html( $label ) . '</div>';
        echo '<div style="font-size:22px;font-weight:600;line-height:1.1;margin-top:2px;font-variant-numeric:tabular-nums">' . (int) $value . '</div>';
        if ( $sublabel !== '' ) {
            echo '<div style="font-size:11px;color:#646970;margin-top:2px">' . esc_html( $sublabel ) . '</div>';
        }
        echo '</div>';
    }

    /* ─── Analytics submenu page ─────────────────────────────────────────── */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<div class="wrap"><h1>' . esc_html__( 'Innsight Analytics', 'innsight' ) . '</h1>';

        if ( ! $this->stats->enabled() ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Analytics are currently disabled. Enable in Settings → Innsight to start collecting counts.', 'innsight' ) . '</p></div>';
            echo '</div>';
            return;
        }

        // ─ Totals row ─
        $totals = array(
            'map_load'       => __( 'Map loads', 'innsight' ),
            'poi_open'       => __( 'POI opens', 'innsight' ),
            'poi_save'       => __( 'Saves', 'innsight' ),
            'poi_unsave'     => __( 'Unsaves', 'innsight' ),
            'share_send'     => __( 'Shares sent', 'innsight' ),
            'share_received' => __( 'Shares received', 'innsight' ),
        );
        echo '<h2 style="margin-top:20px">' . esc_html__( 'Last 30 days', 'innsight' ) . '</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));gap:12px;margin-bottom:24px">';
        foreach ( $totals as $ev => $label ) {
            $today = $this->stats->total( $ev, 0 );
            $month = $this->stats->total( $ev, 30 );
            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px">';
            echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#646970">' . esc_html( $label ) . '</div>';
            echo '<div style="font-size:26px;font-weight:600;margin-top:2px;font-variant-numeric:tabular-nums">' . (int) $month . '</div>';
            echo '<div style="font-size:11px;color:#646970;margin-top:2px">' . sprintf( esc_html__( '%d today', 'innsight' ), $today ) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // ─ Map-load chart (SVG polyline, no JS lib) ─
        $series = $this->stats->timeseries( 'map_load', 30 );
        echo '<h2 style="margin-top:24px">' . esc_html__( 'Daily map loads (30 days)', 'innsight' ) . '</h2>';
        echo $this->render_svg_chart( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_svg_chart escapes its own values

        // ─ Top-saved table (last 90 days) ─
        $top      = $this->stats->top_saved( 50, 90 );
        $resolved = $this->stats->resolve_pois( array_column( $top, 'poi_id' ) );
        echo '<h2 style="margin-top:32px">' . esc_html__( 'Most saved POIs (last 90 days)', 'innsight' ) . '</h2>';
        if ( empty( $top ) ) {
            echo '<p>' . esc_html__( 'No saves recorded yet.', 'innsight' ) . '</p>';
        } else {
            echo '<table class="wp-list-table widefat striped" style="max-width:820px">';
            echo '<thead><tr>';
            echo '<th>#</th>';
            echo '<th>' . esc_html__( 'POI', 'innsight' ) . '</th>';
            echo '<th style="text-align:right">' . esc_html__( 'Saves', 'innsight' ) . '</th>';
            echo '<th style="text-align:right">' . esc_html__( 'Unsaves', 'innsight' ) . '</th>';
            echo '<th style="text-align:right">' . esc_html__( 'Net kept', 'innsight' ) . '</th>';
            echo '</tr></thead><tbody>';
            $i = 1;
            foreach ( $top as $row ) {
                $meta  = $resolved[ $row['poi_id'] ] ?? array( 'title' => $row['poi_id'], 'edit_url' => '' );
                $title = $meta['title'];
                $edit  = $meta['edit_url'];
                echo '<tr>';
                echo '<td>' . $i++ . '</td>';
                echo '<td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) )
                    . ' <code style="color:#646970;font-size:11px">' . esc_html( $row['poi_id'] ) . '</code></td>';
                echo '<td style="text-align:right;font-variant-numeric:tabular-nums">' . (int) $row['saves'] . '</td>';
                echo '<td style="text-align:right;font-variant-numeric:tabular-nums;color:#646970">' . (int) $row['unsaves'] . '</td>';
                echo '<td style="text-align:right;font-variant-numeric:tabular-nums"><strong>' . (int) $row['net'] . '</strong></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // ─ Share-channel breakdown ─
        $channels = $this->stats->top_saved_by_prefix( 'ch:', 'share_send', 6, 90 );
        if ( ! empty( $channels ) ) {
            echo '<h2 style="margin-top:32px">' . esc_html__( 'Shares by channel (last 90 days)', 'innsight' ) . '</h2>';
            echo '<table class="wp-list-table widefat striped" style="max-width:520px">';
            echo '<thead><tr><th>' . esc_html__( 'Channel', 'innsight' ) . '</th><th style="text-align:right">' . esc_html__( 'Count', 'innsight' ) . '</th></tr></thead><tbody>';
            foreach ( $channels as $row ) {
                $ch = substr( $row['poi_id'], 3 );
                echo '<tr><td>' . esc_html( ucfirst( $ch ) ) . '</td>';
                echo '<td style="text-align:right;font-variant-numeric:tabular-nums">' . (int) $row['count'] . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<p style="color:#646970;font-size:12px;margin-top:26px">' . esc_html__( 'Counts are aggregated anonymously - no visitor identity, no IP, no cookies. Analytics can be turned off at any time in Settings → Innsight.', 'innsight' ) . '</p>';

        // ─ Raw-table debugger. Bypasses every aggregate reader so if
        //   the widgets above show 0 but the table has rows, you'll see
        //   it here.
        global $wpdb;
        $table = \Innsight\Stats::table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total_events = (int) $wpdb->get_var( "SELECT COALESCE(SUM(count), 0) FROM {$table}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $recent = $wpdb->get_results( "SELECT event_key, poi_id, day, count FROM {$table} ORDER BY day DESC, count DESC LIMIT 20", ARRAY_A );

        echo '<details style="margin-top:26px">';
        echo '<summary style="cursor:pointer;font-weight:600">' . esc_html__( 'Raw table debugger', 'innsight' ) . '</summary>';
        echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;border-radius:6px;margin-top:8px;max-width:820px">';
        echo '<p style="margin:0 0 8px"><strong>' . esc_html( $table ) . '</strong></p>';
        echo '<p style="margin:0 0 8px">' . sprintf( esc_html__( 'Rows: %1$d - Total counted events: %2$d', 'innsight' ), $total_rows, $total_events ) . '</p>';
        if ( $wpdb->last_error !== '' ) {
            echo '<p style="color:#d63638"><strong>wpdb last_error:</strong> <code>' . esc_html( $wpdb->last_error ) . '</code></p>';
        }
        if ( $total_rows === 0 ) {
            echo '<p style="color:#d63638"><strong>' . esc_html__( 'Table is empty.', 'innsight' ) . '</strong> ' . esc_html__( 'Beacons are not landing. Check browser DevTools > Network for POST calls to /wp-json/innsight/v1/stat when opening a POI.', 'innsight' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>event</th><th>poi_id</th><th>day</th><th>count</th></tr></thead><tbody>';
            foreach ( $recent as $r ) {
                echo '<tr>';
                echo '<td><code>' . esc_html( (string) $r['event_key'] ) . '</code></td>';
                echo '<td>' . ( $r['poi_id'] !== '' ? '<code>' . esc_html( (string) $r['poi_id'] ) . '</code>' : '—' ) . '</td>';
                echo '<td>' . esc_html( (string) $r['day'] ) . '</td>';
                echo '<td style="text-align:right;font-variant-numeric:tabular-nums">' . (int) $r['count'] . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p style="margin:12px 0 0;font-size:12px;color:#646970">' . esc_html__( 'Beacon endpoint:', 'innsight' ) . ' <code>' . esc_html( rest_url( 'innsight/v1/stat' ) ) . '</code></p>';
        echo '</div></details>';

        echo '</div>';
    }

    /**
     * Inline SVG polyline chart. No JS, no external lib. Zero-fill
     * from Stats::timeseries means the polyline is always continuous.
     */
    private function render_svg_chart( array $series ): string {
        $width  = 820;
        $height = 180;
        $pad    = 24;
        $days   = count( $series );
        if ( $days < 2 ) {
            return '<p style="color:#646970">' . esc_html__( 'Not enough data yet.', 'innsight' ) . '</p>';
        }
        $max = max( 1, max( $series ) );
        $step = ( $width - 2 * $pad ) / ( $days - 1 );

        $points = array();
        $i = 0;
        foreach ( $series as $d => $c ) {
            $x = $pad + $i * $step;
            $y = $height - $pad - ( $c / $max ) * ( $height - 2 * $pad );
            $points[] = number_format( $x, 1, '.', '' ) . ',' . number_format( $y, 1, '.', '' );
            $i++;
        }

        $poly = esc_attr( implode( ' ', $points ) );
        $last = end( $series );
        $first_date = array_key_first( $series );
        $last_date  = array_key_last( $series );

        ob_start(); ?>
<svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>" style="width:100%;max-width:<?php echo (int) $width; ?>px;height:auto;background:#fff;border:1px solid #dcdcde;border-radius:6px" xmlns="http://www.w3.org/2000/svg">
    <!-- Y-axis max label -->
    <text x="6" y="18" style="font:11px/1 system-ui;fill:#646970"><?php echo (int) $max; ?></text>
    <text x="6" y="<?php echo (int) ( $height - 6 ); ?>" style="font:11px/1 system-ui;fill:#646970">0</text>
    <!-- Baseline -->
    <line x1="<?php echo (int) $pad; ?>" y1="<?php echo (int) ( $height - $pad ); ?>" x2="<?php echo (int) ( $width - $pad ); ?>" y2="<?php echo (int) ( $height - $pad ); ?>" stroke="#dcdcde" stroke-width="1"/>
    <!-- Polyline -->
    <polyline points="<?php echo $poly; ?>" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
    <!-- Endpoint dot -->
    <circle cx="<?php echo (int) ( $width - $pad ); ?>" cy="<?php echo (int) ( $height - $pad - ( $last / $max ) * ( $height - 2 * $pad ) ); ?>" r="3" fill="#2271b1"/>
    <!-- Date range -->
    <text x="<?php echo (int) $pad; ?>" y="<?php echo (int) ( $height - 4 ); ?>" style="font:10px/1 system-ui;fill:#646970"><?php echo esc_html( $first_date ); ?></text>
    <text x="<?php echo (int) ( $width - $pad ); ?>" y="<?php echo (int) ( $height - 4 ); ?>" text-anchor="end" style="font:10px/1 system-ui;fill:#646970"><?php echo esc_html( $last_date ); ?></text>
</svg>
<?php
        return (string) ob_get_clean();
    }
}
