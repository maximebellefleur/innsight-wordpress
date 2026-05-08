<?php
/**
 * Innsight - functional helpers. Loaded eagerly; the rest of the plugin is autoloaded.
 *
 * @package Innsight
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'innsight_settings' ) ) {
    /**
     * Read the Innsight settings option, merged over defaults.
     *
     * @param string|null $key     Optional dot-path into the settings array.
     * @param mixed       $default Fallback if the key is absent.
     * @return mixed
     */
    function innsight_settings( $key = null, $default = null ) {
        static $cache = null;
        if ( $cache === null ) {
            $stored = get_option( 'innsight_settings', array() );
            $cache  = wp_parse_args( is_array( $stored ) ? $stored : array(), \Innsight\Settings::defaults() );
        }
        if ( $key === null ) {
            return $cache;
        }
        $value = $cache;
        foreach ( explode( '.', (string) $key ) as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return $default;
            }
            $value = $value[ $segment ];
        }
        return $value;
    }
}

if ( ! function_exists( 'innsight_get_field' ) ) {
    /**
     * Wrapper around get_field() that falls back to get_post_meta when ACF is absent.
     * Matches ACF's "auto-format" behavior for simple text/number/select fields.
     *
     * @param string     $key
     * @param int|string $object_id Post ID, term ID, or 'option'.
     * @param mixed      $default
     * @return mixed
     */
    function innsight_get_field( $key, $object_id = false, $default = '' ) {
        if ( function_exists( 'get_field' ) ) {
            $value = get_field( $key, $object_id );
            return $value !== null && $value !== false && $value !== '' ? $value : $default;
        }
        if ( $object_id === 'option' ) {
            $value = get_option( 'options_' . $key );
            return $value !== false && $value !== '' ? $value : $default;
        }
        if ( is_int( $object_id ) || ctype_digit( (string) $object_id ) ) {
            $value = get_post_meta( (int) $object_id, $key, true );
            return $value !== '' ? $value : $default;
        }
        return $default;
    }
}

if ( ! function_exists( 'innsight_get_term_field' ) ) {
    /**
     * Read a term meta field, preferring ACF's resolution if available.
     *
     * @param string $key
     * @param int    $term_id
     * @param mixed  $default
     * @return mixed
     */
    function innsight_get_term_field( $key, $term_id, $default = '' ) {
        if ( function_exists( 'get_field' ) ) {
            $value = get_field( $key, 'term_' . (int) $term_id );
            if ( $value !== null && $value !== false && $value !== '' ) {
                return $value;
            }
        }
        $value = get_term_meta( (int) $term_id, $key, true );
        return $value !== '' ? $value : $default;
    }
}

if ( ! function_exists( 'innsight_attachment_url' ) ) {
    /**
     * Resolve an attachment ID, attachment array (ACF image return-format), or URL string into a URL.
     *
     * @param mixed  $value
     * @param string $size
     * @return string
     */
    function innsight_attachment_url( $value, $size = 'large' ) {
        if ( empty( $value ) ) {
            return '';
        }
        if ( is_numeric( $value ) ) {
            $url = wp_get_attachment_image_url( (int) $value, $size );
            return $url ? $url : '';
        }
        if ( is_array( $value ) ) {
            if ( ! empty( $value['sizes'][ $size ] ) ) {
                return (string) $value['sizes'][ $size ];
            }
            if ( ! empty( $value['url'] ) ) {
                return (string) $value['url'];
            }
            if ( ! empty( $value['ID'] ) ) {
                $url = wp_get_attachment_image_url( (int) $value['ID'], $size );
                return $url ? $url : '';
            }
        }
        if ( is_string( $value ) ) {
            return esc_url_raw( $value );
        }
        return '';
    }
}

if ( ! function_exists( 'innsight_link_field' ) ) {
    /**
     * Normalize an ACF link field (array with url/title/target) to ['url' => ..., 'text' => ...].
     *
     * @param mixed  $value
     * @param string $default_text
     * @return array{url:string, text:string}
     */
    function innsight_link_field( $value, $default_text = '' ) {
        if ( empty( $value ) ) {
            return array( 'url' => '', 'text' => $default_text );
        }
        if ( is_array( $value ) ) {
            return array(
                'url'  => isset( $value['url'] ) ? esc_url_raw( $value['url'] ) : '',
                'text' => isset( $value['title'] ) && $value['title'] !== '' ? (string) $value['title'] : $default_text,
            );
        }
        if ( is_string( $value ) ) {
            return array( 'url' => esc_url_raw( $value ), 'text' => $default_text );
        }
        return array( 'url' => '', 'text' => $default_text );
    }
}

if ( ! function_exists( 'innsight_to_float' ) ) {
    /**
     * Strict float coercion: accepts numbers, comma-decimal strings, etc.
     *
     * @param mixed $value
     * @return float|null
     */
    function innsight_to_float( $value ) {
        if ( $value === null || $value === '' ) {
            return null;
        }
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }
        if ( is_string( $value ) ) {
            $clean = str_replace( array( ' ', ',' ), array( '', '.' ), trim( $value ) );
            if ( is_numeric( $clean ) ) {
                return (float) $clean;
            }
        }
        return null;
    }
}

if ( ! function_exists( 'innsight_strip_paragraph_tags' ) ) {
    /**
     * Match the existing plugin's `preg_replace('/<\/?p\b[^>]*>/i', '', $value)` behavior
     * (strip outer p-tags from CMS WYSIWYG output for the hostel description).
     *
     * @param string $html
     * @return string
     */
    function innsight_strip_paragraph_tags( $html ) {
        if ( ! is_string( $html ) ) {
            return '';
        }
        return preg_replace( '/<\/?p\b[^>]*>/i', '', $html );
    }
}
