<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * ImportPage - admin page wiring for the POI import + backup workflow.
 *
 * Three-step state machine, with parsed source rows kept in a transient
 * between steps so the actual file only needs to be uploaded once:
 *
 *   step=upload  (default)  - file upload form + "Backup current POIs" link
 *   step=map                - field-mapping table + first-5-rows preview
 *   step=done               - import results (created/updated/skipped/errors)
 *
 * State machine guards:
 *   - manage_options capability on every action.
 *   - WP nonce on every POST + on the export GET link.
 *   - Transient TTL of 30 minutes - if the admin walks away, the cache
 *     expires and they have to re-upload (no stale state).
 *
 * The page is small enough that the UI is rendered inline (no template
 * partials). Keeping it in one file makes the flow easy to read.
 */
final class ImportPage {

    public const PAGE_SLUG       = 'innsight-import';
    public const NONCE_UPLOAD    = 'innsight_import_upload';
    public const NONCE_EXECUTE   = 'innsight_import_execute';
    public const TRANSIENT_PREFIX = 'innsight_import_';
    public const TRANSIENT_TTL   = 1800; // 30 minutes

    /** @var PoiImporter */
    private $importer;
    /** @var PoiExporter */
    private $exporter;

    public function __construct( PoiImporter $importer, PoiExporter $exporter ) {
        $this->importer = $importer;
        $this->exporter = $exporter;
    }

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_innsight_import_upload', array( $this, 'handle_upload' ) );
        add_action( 'admin_post_innsight_import_execute', array( $this, 'handle_execute' ) );
    }

    public function register_menu(): void {
        // Hangs off the top-level "Innsight" menu (registered by Admin).
        add_submenu_page(
            'innsight',
            __( 'Import POIs', 'innsight' ),
            __( 'Import', 'innsight' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /* ------------------------------------------------------------------ *
     *   Render dispatcher                                                *
     * ------------------------------------------------------------------ */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'upload'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap"><h1>' . esc_html__( 'Innsight - POI Import', 'innsight' ) . '</h1>';
        $this->render_backup_card();

        switch ( $step ) {
            case 'map':
                $this->render_map_step( $token );
                break;
            case 'done':
                $this->render_done_step( $token );
                break;
            case 'upload':
            default:
                $this->render_upload_step();
                break;
        }
        echo '</div>';
    }

    /* ------------------------------------------------------------------ *
     *   Backup card (always visible at top)                              *
     * ------------------------------------------------------------------ */

    private function render_backup_card(): void {
        $url = wp_nonce_url(
            add_query_arg( array( 'innsight_export' => 1 ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
            PoiExporter::NONCE_ACTION
        );
        $existing_count = wp_count_posts( PoiPostType::POST_TYPE );
        $total = $existing_count ? array_sum( (array) $existing_count ) : 0;
        ?>
        <div class="card" style="max-width:760px;padding:14px 18px;border-left:4px solid #135e96;">
            <h2 style="margin-top:0"><?php esc_html_e( 'Step 0 - Back up first', 'innsight' ); ?></h2>
            <p><?php esc_html_e( 'Always export a snapshot before importing. The download is a self-describing JSON file containing every POI post and every legacy point_of_interest taxonomy term. You can re-import it through this same screen if anything goes wrong.', 'innsight' ); ?></p>
            <p>
                <strong><?php echo (int) $total; ?></strong>
                <?php esc_html_e( 'POI posts currently in the database.', 'innsight' ); ?>
            </p>
            <p>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php esc_html_e( 'Download backup (JSON)', 'innsight' ); ?></a>
            </p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     *   Step 1 - upload                                                  *
     * ------------------------------------------------------------------ */

    private function render_upload_step(): void {
        $sample_csv = INNSIGHT_URL . 'sample-data/frankfurt-pois.csv';
        ?>
        <div class="card" style="max-width:760px;padding:14px 18px;margin-top:18px;">
            <h2 style="margin-top:0"><?php esc_html_e( 'Step 1 - Upload a CSV or JSON file', 'innsight' ); ?></h2>
            <p><?php esc_html_e( 'Accepted: CSV (semicolon or comma separated, header row required) or JSON (array of POI objects, or { "pois": [...] }).', 'innsight' ); ?></p>
            <p>
                <a href="<?php echo esc_url( $sample_csv ); ?>" download><?php esc_html_e( 'Download the bundled Frankfurt sample (frankfurt-pois.csv)', 'innsight' ); ?></a>
                <?php esc_html_e( ' - 116 POIs across bars, cafes, restaurants, nightlife, stores and activities.', 'innsight' ); ?>
            </p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_UPLOAD ); ?>
                <input type="hidden" name="action" value="innsight_import_upload">
                <p>
                    <input type="file" name="innsight_import_file" accept=".csv,.json,text/csv,application/json" required>
                </p>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Upload + parse', 'innsight' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_upload(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        check_admin_referer( self::NONCE_UPLOAD );

        if ( empty( $_FILES['innsight_import_file']['tmp_name'] ) ) {
            $this->bail_with_error( __( 'No file received.', 'innsight' ) );
        }
        if ( ! empty( $_FILES['innsight_import_file']['error'] ) ) {
            $this->bail_with_error( sprintf( __( 'Upload error code %d.', 'innsight' ), (int) $_FILES['innsight_import_file']['error'] ) );
        }

        $tmp_path = (string) $_FILES['innsight_import_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $client_name = isset( $_FILES['innsight_import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['innsight_import_file']['name'] ) ) : '';

        try {
            $parsed = $this->importer->parse_file( $tmp_path, $client_name );
        } catch ( \Throwable $e ) {
            $this->bail_with_error( $e->getMessage() );
        }

        if ( empty( $parsed['rows'] ) ) {
            $this->bail_with_error( __( 'File parsed but contains no rows.', 'innsight' ) );
        }

        $token = wp_generate_password( 16, false, false );
        set_transient( self::TRANSIENT_PREFIX . $token, array(
            'name'    => $client_name,
            'format'  => $parsed['format'],
            'columns' => $parsed['columns'],
            'rows'    => $parsed['rows'],
            'mapping' => $this->importer->suggest_mapping( $parsed['columns'] ),
        ), self::TRANSIENT_TTL );

        wp_safe_redirect( add_query_arg( array(
            'page'  => self::PAGE_SLUG,
            'step'  => 'map',
            'token' => $token,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ------------------------------------------------------------------ *
     *   Step 2 - map + preview                                           *
     * ------------------------------------------------------------------ */

    private function render_map_step( string $token ): void {
        $cache = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( ! is_array( $cache ) ) {
            $this->render_token_expired();
            return;
        }
        $columns = (array) $cache['columns'];
        $rows    = (array) $cache['rows'];
        $mapping = (array) $cache['mapping'];
        $name    = (string) ( $cache['name'] ?? '' );
        $count   = count( $rows );
        $preview = $this->importer->preview( $rows, $mapping, 5 );
        ?>
        <div class="card" style="max-width:none;padding:14px 18px;margin-top:18px;">
            <h2 style="margin-top:0"><?php esc_html_e( 'Step 2 - Map fields and preview', 'innsight' ); ?></h2>
            <p>
                <?php
                /* translators: 1: file name, 2: count of rows */
                printf( esc_html__( 'Parsed %1$s - %2$d rows detected. Set the destination POI field for each source column. Auto-suggestions are pre-filled where the column name was recognized.', 'innsight' ),
                    '<code>' . esc_html( $name ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    (int) $count
                );
                ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_EXECUTE ); ?>
                <input type="hidden" name="action" value="innsight_import_execute">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

                <h3><?php esc_html_e( 'Field mapping', 'innsight' ); ?></h3>
                <table class="widefat striped" style="max-width:760px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Source column', 'innsight' ); ?></th>
                            <th><?php esc_html_e( 'Imported as', 'innsight' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $columns as $col ) :
                            $current = isset( $mapping[ $col ] ) ? (string) $mapping[ $col ] : '';
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $col ); ?></code></td>
                                <td>
                                    <select name="mapping[<?php echo esc_attr( $col ); ?>]">
                                        <option value=""<?php selected( $current, '' ); ?>><?php esc_html_e( '— Skip this column —', 'innsight' ); ?></option>
                                        <?php foreach ( PoiImporter::TARGET_FIELDS as $key => $label ) : ?>
                                            <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?> (<?php echo esc_html( $key ); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3 style="margin-top:24px"><?php esc_html_e( 'Preview - first 5 rows after mapping', 'innsight' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Mapping changes are reflected when you re-submit the form. Categories like "bars_and_pubs" are auto-normalized into the design\'s 5 buckets (drinks/eats/sights/shops/events) when mapcategory_normalized is left blank.', 'innsight' ); ?></p>
                <div style="overflow-x:auto">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <?php foreach ( PoiImporter::TARGET_FIELDS as $key => $label ) : ?>
                                    <th><?php echo esc_html( $key ); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $preview as $row ) : ?>
                                <tr>
                                    <?php foreach ( PoiImporter::TARGET_FIELDS as $key => $label ) :
                                        $val = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
                                        $val = strlen( $val ) > 90 ? substr( $val, 0, 90 ) . '…' : $val;
                                    ?>
                                        <td><?php echo esc_html( $val ); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p style="margin-top:18px">
                    <button type="submit" name="op" value="re-preview" class="button"><?php esc_html_e( 'Update preview', 'innsight' ); ?></button>
                    <button type="submit" name="op" value="execute" class="button button-primary"><?php
                        /* translators: %d: number of rows */
                        printf( esc_html__( 'Import %d rows', 'innsight' ), (int) $count );
                    ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button-link"><?php esc_html_e( 'Cancel', 'innsight' ); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    public function handle_execute(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Forbidden.', 'innsight' ) );
        check_admin_referer( self::NONCE_EXECUTE );

        $token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $cache = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( ! is_array( $cache ) ) {
            $this->bail_with_error( __( 'Import session expired. Please re-upload your file.', 'innsight' ) );
        }
        $mapping_in = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $mapping = array();
        foreach ( $cache['columns'] as $col ) {
            $value = isset( $mapping_in[ $col ] ) ? sanitize_key( (string) $mapping_in[ $col ] ) : '';
            if ( $value && ! array_key_exists( $value, PoiImporter::TARGET_FIELDS ) ) $value = '';
            $mapping[ $col ] = $value;
        }
        // Persist user's mapping back to the transient so the preview reflects it.
        $cache['mapping'] = $mapping;
        set_transient( self::TRANSIENT_PREFIX . $token, $cache, self::TRANSIENT_TTL );

        $op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'execute';
        if ( $op !== 'execute' ) {
            wp_safe_redirect( add_query_arg( array(
                'page' => self::PAGE_SLUG, 'step' => 'map', 'token' => $token,
            ), admin_url( 'admin.php' ) ) );
            exit;
        }

        $result = $this->importer->execute( (array) $cache['rows'], $mapping );
        // Stash the result on the same transient so the done page can show it.
        $cache['result'] = $result;
        set_transient( self::TRANSIENT_PREFIX . $token, $cache, self::TRANSIENT_TTL );

        wp_safe_redirect( add_query_arg( array(
            'page' => self::PAGE_SLUG, 'step' => 'done', 'token' => $token,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ------------------------------------------------------------------ *
     *   Step 3 - done                                                    *
     * ------------------------------------------------------------------ */

    private function render_done_step( string $token ): void {
        $cache = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( ! is_array( $cache ) || empty( $cache['result'] ) ) {
            $this->render_token_expired();
            return;
        }
        $r = $cache['result'];
        ?>
        <div class="card" style="max-width:760px;padding:14px 18px;margin-top:18px;border-left:4px solid #46b450;">
            <h2 style="margin-top:0"><?php esc_html_e( 'Step 3 - Done', 'innsight' ); ?></h2>
            <ul>
                <li><strong><?php echo (int) $r['created']; ?></strong> <?php esc_html_e( 'created', 'innsight' ); ?></li>
                <li><strong><?php echo (int) $r['updated']; ?></strong> <?php esc_html_e( 'updated (matched by osm_id or lat+lon+title)', 'innsight' ); ?></li>
                <li><strong><?php echo (int) $r['skipped']; ?></strong> <?php esc_html_e( 'skipped (missing lat / lon / title or write error)', 'innsight' ); ?></li>
            </ul>
            <?php if ( ! empty( $r['errors'] ) ) : ?>
                <h3><?php esc_html_e( 'Errors', 'innsight' ); ?></h3>
                <ul>
                    <?php foreach ( array_slice( (array) $r['errors'], 0, 20 ) as $e ) : ?>
                        <li><code><?php echo esc_html( $e ); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PoiPostType::POST_TYPE ) ); ?>" class="button button-primary"><?php esc_html_e( 'Open POI list', 'innsight' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Import another file', 'innsight' ); ?></a>
            </p>
        </div>
        <?php
        // Free the transient now that we showed the result.
        delete_transient( self::TRANSIENT_PREFIX . $token );
    }

    /* ------------------------------------------------------------------ *
     *   Helpers                                                          *
     * ------------------------------------------------------------------ */

    private function render_token_expired(): void {
        ?>
        <div class="notice notice-error"><p>
            <?php esc_html_e( 'Your import session has expired or the link is invalid. Please re-upload your file.', 'innsight' ); ?>
        </p></div>
        <?php
        $this->render_upload_step();
    }

    /**
     * Set a transient flash + redirect to the upload step. Used as the error
     * exit path from POST handlers so the user always lands on a page.
     */
    private function bail_with_error( string $message ): void {
        wp_die( esc_html( $message ), esc_html__( 'Innsight import error', 'innsight' ), array( 'back_link' => true ) );
    }
}
