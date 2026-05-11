<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * PoiImporter - parses a CSV or JSON file of POIs and imports them as `poi`
 * custom post type entries.
 *
 * Pipeline:
 *   1. parse_file()           detect format + encoding, normalize to a list of
 *                             associative arrays (one per source row)
 *   2. detect_columns()       return the column names + a sample of the first
 *                             N rows so the admin UI can render a preview
 *   3. suggest_mapping()      auto-suggest source->target mappings based on
 *                             column-name heuristics; admin can override
 *   4. preview()              apply a candidate mapping to the first N rows
 *                             to show what the import would look like
 *   5. execute()              run the import; idempotent by osm_id (or by
 *                             lat+lon+title fallback). Returns counts.
 *
 * Idempotency: re-running an import with the same osm_id values UPDATES the
 * existing posts rather than creating duplicates. This makes the importer
 * safe to run after every CSV refresh.
 *
 * Translator hook: titles + descriptions pass through the translator on the
 * way back out (when DataSource reads them later); the importer itself
 * stores raw German + English text in description_de / description_en.
 */
final class PoiImporter {

    public const TARGET_FIELDS = array(
        'title'                  => 'Post title (POI name)',
        'lat'                    => 'Latitude',
        'lon'                    => 'Longitude',
        'fclass'                 => 'Type (bar, cafe, restaurant, ...)',
        'mapcategory'            => 'Source map category (bars_and_pubs, ...)',
        'mapcategory_normalized' => 'Normalized category (drinks/eats/sights/shops/events) - auto-derived if blank',
        'description_de'         => 'Description (German)',
        'description_en'         => 'Description (English)',
        'website'                => 'Website',
        'website2'               => 'Website (secondary, e.g. Instagram)',
        'maps_url'               => 'Google Maps URL',
        'osm_id'                 => 'OSM id (used for idempotent updates)',
        'osm_code'               => 'OSM code',
        'image_url'              => 'Image URL (will be sideloaded as featured image)',
    );

    /**
     * Auto-suggest mapping. Each entry: regex pattern => target field.
     * Order matters - first match wins.
     */
    public const SUGGESTION_PATTERNS = array(
        '/^name$|^title$/i'                          => 'title',
        '/^lat$|^latitude$|^y$/i'                    => 'lat',
        '/^lon$|^lng$|^long$|^longitude$|^x$/i'      => 'lon',
        '/^fclass$|^type$|^kind$/i'                  => 'fclass',
        '/^mapcategory$|^category$|^cat$/i'          => 'mapcategory',
        '/^cat[_ ]?normalized$|^bucket$/i'           => 'mapcategory_normalized',
        '/^descr[_ ]?ger$|^description[_ ]?de$|^de[_ ]?description$/i' => 'description_de',
        '/^descr[_ ]?eng$|^description[_ ]?en$|^en[_ ]?description$|^description$/i' => 'description_en',
        '/^website$|^url$|^homepage$/i'              => 'website',
        '/^website2$|^webiste2$|^website[_ ]?2$|^instagram$|^social$/i' => 'website2',
        '/^maps$|^maps[_ ]?url$|^google[_ ]?maps$/i' => 'maps_url',
        '/^osm[_ ]?id$/i'                            => 'osm_id',
        '/^osm[_ ]?code$|^code$/i'                   => 'osm_code',
        '/^image$|^image[_ ]?url$|^photo$|^picture$/i' => 'image_url',
    );

    /* ------------------------------------------------------------------ *
     *   Parse                                                            *
     * ------------------------------------------------------------------ */

    /**
     * Parse a file from the upload tmp_name. Returns:
     *   [ 'rows' => array<int, array<string, string>>, 'columns' => string[], 'format' => 'csv'|'json' ]
     *
     * @throws \RuntimeException on unparseable input.
     */
    public function parse_file( string $tmp_path, string $client_name = '' ): array {
        if ( ! is_readable( $tmp_path ) ) {
            throw new \RuntimeException( __( 'Uploaded file is not readable.', 'innsight' ) );
        }
        $raw = (string) file_get_contents( $tmp_path );
        if ( $raw === '' ) {
            throw new \RuntimeException( __( 'Uploaded file is empty.', 'innsight' ) );
        }
        $raw = $this->normalize_encoding( $raw );

        $is_json = strpos( ltrim( $raw ), '[' ) === 0 || strpos( ltrim( $raw ), '{' ) === 0;
        if ( $is_json || $this->ends_with( strtolower( $client_name ), '.json' ) ) {
            return $this->parse_json( $raw );
        }
        return $this->parse_csv( $raw );
    }

    private function parse_csv( string $raw ): array {
        // Detect separator: prefer ; (common in European exports) when it
        // appears more than , in the header row.
        $first_line = strtok( $raw, "\r\n" );
        $semi = substr_count( (string) $first_line, ';' );
        $comma = substr_count( (string) $first_line, ',' );
        $sep = $semi > $comma ? ';' : ',';

        // Iterate via str_getcsv so we don't need fopen/fseek on a string.
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        $columns = array();
        $rows = array();
        foreach ( $lines as $idx => $line ) {
            if ( $line === '' ) continue;
            $cells = str_getcsv( $line, $sep, '"' );
            if ( $idx === 0 ) {
                $columns = array_map( static function ( $c ) { return trim( (string) $c ); }, $cells );
                continue;
            }
            if ( count( $cells ) === 1 && $cells[0] === '' ) continue;
            $row = array();
            foreach ( $columns as $i => $col ) {
                $val = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
                if ( $val === 'NA' ) $val = '';   // common convention in this dataset
                $row[ $col ] = $val;
            }
            $rows[] = $row;
        }
        return array( 'rows' => $rows, 'columns' => $columns, 'format' => 'csv' );
    }

    private function parse_json( string $raw ): array {
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( __( 'Could not parse JSON.', 'innsight' ) );
        }
        if ( isset( $decoded['pois'] ) && is_array( $decoded['pois'] ) ) {
            $decoded = $decoded['pois'];
        }
        $rows = array();
        $columns = array();
        foreach ( $decoded as $row ) {
            if ( ! is_array( $row ) ) continue;
            foreach ( array_keys( $row ) as $k ) {
                if ( ! in_array( $k, $columns, true ) ) $columns[] = $k;
            }
            // Stringify scalars; nested objects/arrays are serialised so the
            // mapping UI can still display them.
            $flat = array();
            foreach ( $row as $k => $v ) {
                $flat[ $k ] = is_scalar( $v ) ? (string) $v : json_encode( $v );
            }
            $rows[] = $flat;
        }
        return array( 'rows' => $rows, 'columns' => $columns, 'format' => 'json' );
    }

    private function normalize_encoding( string $raw ): string {
        if ( function_exists( 'mb_detect_encoding' ) ) {
            $encoding = mb_detect_encoding( $raw, array( 'UTF-8', 'Windows-1252', 'ISO-8859-1' ), true );
            if ( $encoding && $encoding !== 'UTF-8' ) {
                return (string) mb_convert_encoding( $raw, 'UTF-8', $encoding );
            }
        }
        // Strip BOM if present.
        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }
        return $raw;
    }

    private function ends_with( string $haystack, string $needle ): bool {
        $len = strlen( $needle );
        return $len === 0 || substr( $haystack, -$len ) === $needle;
    }

    /* ------------------------------------------------------------------ *
     *   Suggest mapping                                                  *
     * ------------------------------------------------------------------ */

    /**
     * @param string[] $columns
     * @return array<string,string>  source column => target field (or '' to skip)
     */
    public function suggest_mapping( array $columns ): array {
        $mapping = array();
        foreach ( $columns as $col ) {
            $target = '';
            foreach ( self::SUGGESTION_PATTERNS as $pattern => $candidate ) {
                if ( preg_match( $pattern, $col ) ) {
                    $target = $candidate;
                    break;
                }
            }
            $mapping[ $col ] = $target;
        }
        return $mapping;
    }

    /* ------------------------------------------------------------------ *
     *   Preview                                                          *
     * ------------------------------------------------------------------ */

    /**
     * Apply a mapping to up to $limit rows and return them in normalized form
     * so the admin UI can show what the import will look like.
     *
     * @param array<int,array<string,string>> $rows
     * @param array<string,string>            $mapping  source col => target field
     * @param int                             $limit
     * @return array<int,array<string,mixed>>
     */
    public function preview( array $rows, array $mapping, int $limit = 5 ): array {
        $out = array();
        $count = min( $limit, count( $rows ) );
        for ( $i = 0; $i < $count; $i++ ) {
            $out[] = $this->apply_mapping( $rows[ $i ], $mapping );
        }
        return $out;
    }

    private function apply_mapping( array $row, array $mapping ): array {
        $out = array_fill_keys( array_keys( self::TARGET_FIELDS ), '' );
        foreach ( $mapping as $source_col => $target_field ) {
            if ( $target_field === '' || ! array_key_exists( $target_field, $out ) ) continue;
            if ( ! array_key_exists( $source_col, $row ) ) continue;
            $out[ $target_field ] = $row[ $source_col ];
        }
        // Auto-derive normalized category from mapcategory if not explicitly mapped.
        if ( $out['mapcategory_normalized'] === '' && $out['mapcategory'] !== '' ) {
            $out['mapcategory_normalized'] = PoiPostType::normalize_category( (string) $out['mapcategory'] );
        }
        // Coerce numeric fields.
        if ( $out['lat'] !== '' ) $out['lat'] = (float) str_replace( ',', '.', (string) $out['lat'] );
        if ( $out['lon'] !== '' ) $out['lon'] = (float) str_replace( ',', '.', (string) $out['lon'] );
        if ( $out['osm_code'] !== '' && is_numeric( $out['osm_code'] ) ) $out['osm_code'] = (int) $out['osm_code'];
        return $out;
    }

    /* ------------------------------------------------------------------ *
     *   Execute                                                          *
     * ------------------------------------------------------------------ */

    /**
     * @param array<int,array<string,string>> $rows
     * @param array<string,string>            $mapping
     * @return array{created:int,updated:int,skipped:int,errors:array<int,string>}
     */
    public function execute( array $rows, array $mapping ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = array();

        foreach ( $rows as $i => $raw ) {
            $row = $this->apply_mapping( $raw, $mapping );

            // Need at least lat + lon + a title, otherwise the marker would be
            // unplaceable on the map. Skipping is the right default; the admin
            // sees the count in the result page and can re-export the dropped
            // rows for manual fixup.
            if ( $row['lat'] === '' || $row['lon'] === '' || $row['title'] === '' ) {
                $skipped++;
                continue;
            }

            try {
                $existing_id = $this->find_existing_post( $row );
                if ( $existing_id ) {
                    $this->update_post( $existing_id, $row );
                    $updated++;
                } else {
                    $this->create_post( $row );
                    $created++;
                }
            } catch ( \Throwable $e ) {
                $errors[] = sprintf( 'Row %d (%s): %s', $i + 1, $row['title'] ?: '?', $e->getMessage() );
                $skipped++;
            }
        }

        return array( 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors );
    }

    private function find_existing_post( array $row ): ?int {
        // Primary key: osm_id (when present). Direct, unambiguous.
        if ( ! empty( $row['osm_id'] ) ) {
            $found = get_posts( array(
                'post_type'      => PoiPostType::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array( 'key' => 'osm_id', 'value' => (string) $row['osm_id'] ),
                ),
            ) );
            if ( ! empty( $found ) ) return (int) $found[0];
        }

        // Fallback for rows without osm_id: match by exact title + lat/lon
        // proximity (~11 m at 4 decimals). This covers the very common case
        // where the same dataset is re-imported and rows happen to lack an
        // OSM id. Querying by title gets us a small candidate set; lat/lon is
        // verified in PHP because WP serializes float meta in ways that defeat
        // a meta_query equality test.
        $title = trim( (string) $row['title'] );
        if ( $title === '' ) return null;
        $candidates = get_posts( array(
            'post_type'      => PoiPostType::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 25,
            'no_found_rows'  => true,
            'title'          => $title,
        ) );
        if ( empty( $candidates ) ) {
            // The 'title' parameter is exact-match only on modern WP; older
            // installs need the broader 's' fallback.
            $candidates = get_posts( array(
                'post_type'      => PoiPostType::POST_TYPE,
                'post_status'    => 'any',
                'posts_per_page' => 25,
                'no_found_rows'  => true,
                's'              => $title,
            ) );
        }
        $target_lat = (float) $row['lat'];
        $target_lon = (float) $row['lon'];
        foreach ( $candidates as $candidate ) {
            $cand_id  = is_object( $candidate ) ? (int) $candidate->ID : (int) $candidate;
            $cand_lat = (float) get_post_meta( $cand_id, 'lat', true );
            $cand_lon = (float) get_post_meta( $cand_id, 'lon', true );
            if ( abs( $cand_lat - $target_lat ) < 0.0001 && abs( $cand_lon - $target_lon ) < 0.0001 ) {
                return $cand_id;
            }
        }
        return null;
    }

    private function create_post( array $row ): int {
        $description_post = (string) ( $row['description_en'] !== '' ? $row['description_en'] : $row['description_de'] );
        $post_id = wp_insert_post( array(
            'post_type'    => PoiPostType::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field( (string) $row['title'] ),
            'post_content' => wp_kses_post( $description_post ),
        ), true );
        if ( is_wp_error( $post_id ) ) {
            throw new \RuntimeException( $post_id->get_error_message() );
        }
        $this->write_meta( (int) $post_id, $row );
        $this->maybe_sideload_image( (int) $post_id, $row );
        return (int) $post_id;
    }

    private function update_post( int $post_id, array $row ): void {
        $description_post = (string) ( $row['description_en'] !== '' ? $row['description_en'] : $row['description_de'] );
        wp_update_post( array(
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field( (string) $row['title'] ),
            'post_content' => wp_kses_post( $description_post ),
        ) );
        $this->write_meta( $post_id, $row );
        $this->maybe_sideload_image( $post_id, $row );
    }

    private function write_meta( int $post_id, array $row ): void {
        $meta_keys = array_keys( PoiPostType::META );
        foreach ( $meta_keys as $key ) {
            if ( ! array_key_exists( $key, $row ) ) continue;
            $value = $row[ $key ];
            if ( $value === '' ) {
                delete_post_meta( $post_id, $key );
                continue;
            }
            update_post_meta( $post_id, $key, $value );
        }
        // Mark as imported so we can audit later.
        update_post_meta( $post_id, '_innsight_imported_at', time() );
    }

    /**
     * Sideload an image URL as the post's featured image. Only runs when the
     * admin mapped a column to image_url AND the post has no current thumbnail.
     */
    private function maybe_sideload_image( int $post_id, array $row ): void {
        $url = isset( $row['image_url'] ) ? trim( (string) $row['image_url'] ) : '';
        if ( $url === '' || has_post_thumbnail( $post_id ) ) return;
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, (int) $attachment_id );
        }
    }
}
