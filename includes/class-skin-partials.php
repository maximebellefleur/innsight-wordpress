<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * SkinPartials - reads the bundled skin's HTML partials so the shortcode can
 * inline them as `window.INNSIGHT_SKIN_PARTIALS`. This avoids the runtime
 * fetch() roundtrips when a page renders the map.
 *
 * Cached in-memory per-request and as a transient across requests; the
 * transient is keyed on the skin's last-modified time so editing a skin file
 * during development takes effect on the next page load.
 */
final class SkinPartials {

    /** @var array<string,array> */
    private $cache = array();

    /**
     * Read the partials for a given skin name. Returns null if the skin folder
     * is missing or unreadable.
     *
     * @return array{layout:string,popup:string,cluster:string,listItem:string}|null
     */
    public function read( string $skin_name ): ?array {
        if ( isset( $this->cache[ $skin_name ] ) ) {
            return $this->cache[ $skin_name ];
        }
        $base = INNSIGHT_PATH . 'skins/' . $skin_name . '/';
        if ( ! is_dir( $base ) ) {
            return null;
        }
        $manifest_path = $base . 'skin.json';
        if ( ! is_readable( $manifest_path ) ) {
            return null;
        }

        // Cache key MUST include INNSIGHT_VERSION because some upload paths
        // (rsync -t, FTP with preserve-timestamp, certain managed hosts)
        // keep the original file mtimes when extracting an updated plugin
        // zip. Without the version in the key, the transient stays stale
        // forever and visitors load the old layout / sheet / pin partials
        // even though the on-disk files are new. With the version in the
        // key, every release forces a fresh cache entry guaranteed.
        $signature = (string) filemtime( $manifest_path );
        $watched = array( 'layout.html', 'popup.html', 'cluster.html', 'pin.html', 'base.html', 'sheet.html', 'list-row.html', 'list-item.html', 'empty-state.html', 'skin.css', 'skin.js' );
        foreach ( $watched as $f ) {
            if ( is_readable( $base . $f ) ) {
                $signature .= '|' . filemtime( $base . $f );
            }
        }
        $version = defined( 'INNSIGHT_VERSION' ) ? INNSIGHT_VERSION : '0';
        $cache_key = 'innsight_partials_' . md5( $skin_name . '|' . $version . '|' . $signature );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $this->cache[ $skin_name ] = $cached;
            return $cached;
        }

        $manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
        if ( ! is_array( $manifest ) || empty( $manifest['files'] ) ) {
            return null;
        }
        $files    = $manifest['files'];
        $partials = array(
            'layout'     => $this->slurp( $base, $files['layout']     ?? null ),
            'popup'      => $this->slurp( $base, $files['popup']      ?? null ),
            'cluster'    => $this->slurp( $base, $files['cluster']    ?? null ),
            'pin'        => $this->slurp( $base, $files['pin']        ?? null ),
            'base'       => $this->slurp( $base, $files['base']       ?? null ),
            'sheet'      => $this->slurp( $base, $files['sheet']      ?? null ),
            'listRow'    => $this->slurp( $base, $files['listRow']    ?? null ),
            'listItem'   => $this->slurp( $base, $files['listItem']   ?? null ),
            'emptyState' => $this->slurp( $base, $files['emptyState'] ?? null ),
        );

        set_transient( $cache_key, $partials, HOUR_IN_SECONDS * 12 );
        $this->cache[ $skin_name ] = $partials;
        return $partials;
    }

    private function slurp( string $base, ?string $rel ): string {
        if ( $rel === null || $rel === '' ) {
            return '';
        }
        $path = $base . ltrim( $rel, '/' );
        if ( ! is_readable( $path ) ) {
            return '';
        }
        return (string) file_get_contents( $path );
    }
}
