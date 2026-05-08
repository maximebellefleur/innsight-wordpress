<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Geocoder - thin Nominatim wrapper with transient caching.
 *
 * Mirrors the existing yuna-innsight `convert_name_to_lat_lon()` behavior, with
 * three improvements:
 *   1. Transient caching keyed on the normalized query string so we don't
 *      hammer Nominatim on repeated shortcode renders.
 *   2. Politeness: a configurable email shows up in the User-Agent header per
 *      Nominatim's usage policy.
 *   3. Returns a typed array (lat, lon, display_name) or null on failure -
 *      callers no longer need to remember to read $result[0]['lat'].
 *
 * Nominatim is rate-limited to ~1 request/second; the transient TTL defaults
 * to 30 days because place lookups are essentially static.
 */
final class Geocoder {

    private const NOMINATIM_BASE = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return array{lat:float,lon:float,display_name:string}|null
     */
    public function locate( string $query ) {
        $query = trim( $query );
        if ( $query === '' ) {
            return null;
        }
        $cache_key = 'innsight_geo_' . md5( strtolower( $query ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached === array() ? null : $cached;
        }

        $email = (string) innsight_settings( 'geocoder_email', get_option( 'admin_email' ) );
        $url   = add_query_arg(
            array(
                'format'         => 'json',
                'addressdetails' => 1,
                'limit'          => 1,
                'q'              => $query,
                'email'          => $email,
            ),
            self::NOMINATIM_BASE
        );

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => sprintf( 'Innsight/%s (%s)', INNSIGHT_VERSION, $email ?: home_url() ),
                'Accept'     => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $this->cache_negative( $cache_key );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body[0]['lat'] ) || empty( $body[0]['lon'] ) ) {
            $this->cache_negative( $cache_key );
            return null;
        }

        $result = array(
            'lat'          => (float) $body[0]['lat'],
            'lon'          => (float) $body[0]['lon'],
            'display_name' => isset( $body[0]['display_name'] ) ? (string) $body[0]['display_name'] : '',
        );
        $hours  = max( 1, (int) innsight_settings( 'geocoder_cache_hours', 720 ) );
        set_transient( $cache_key, $result, $hours * HOUR_IN_SECONDS );

        return $result;
    }

    /**
     * Geocode a list of "lat,lon" or place-name strings into [[lat,lon], ...] coordinates.
     *
     * Used by paths: each path point can be either an explicit coordinate pair
     * or a place name that needs Nominatim resolution. Mirrors the existing
     * `maps_paths_box[].maps_path_points_name` behavior.
     *
     * @param string[] $points
     * @return array<int,array{0:float,1:float}>
     */
    public function locate_many( array $points ): array {
        $coords = array();
        foreach ( $points as $point ) {
            $point = is_string( $point ) ? trim( $point ) : '';
            if ( $point === '' ) {
                continue;
            }
            // Already a coordinate pair?
            if ( preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $point, $m ) ) {
                $coords[] = array( (float) $m[1], (float) $m[2] );
                continue;
            }
            $hit = $this->locate( $point );
            if ( $hit !== null ) {
                $coords[] = array( $hit['lat'], $hit['lon'] );
            }
        }
        return $coords;
    }

    private function cache_negative( string $key ): void {
        // Cache the empty result for a shorter window so transient typos don't lock us out for 30 days.
        set_transient( $key, array(), HOUR_IN_SECONDS );
    }
}
