<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * Translator - facade over the existing site's translation helpers.
 *
 * The yuna-innsight plugin (the predecessor) exposed two functions:
 *   - translated_by_yuna( string ): string  -- Transposh translation lookup
 *   - get_lang_url( string ): string        -- appends ?lang=<currentlang>
 *
 * Multilingual sites depend on those wrappers being applied to every title /
 * description / button URL surfaced on the map. This class preserves those
 * call points without hard-depending on Transposh: if the helpers exist, we
 * use them; otherwise we pass values through unchanged.
 *
 * The `innsight/translator/text` and `innsight/translator/url` filters give
 * sites a clean extension point if they want to wire a different translation
 * engine (Polylang, WPML, etc.).
 */
final class Translator {

    /**
     * Translate a piece of user-facing text.
     */
    public function text( string $text ): string {
        $translated = $text;
        if ( function_exists( 'translated_by_yuna' ) ) {
            $value = \translated_by_yuna( $text );
            if ( is_string( $value ) && $value !== '' ) {
                $translated = $value;
            }
        }
        return (string) apply_filters( 'innsight/translator/text', $translated, $text );
    }

    /**
     * Translate a CMS HTML blob - same semantics as text() but kept separate so
     * future implementations can apply different sanitization rules.
     */
    public function html( string $html ): string {
        return $this->text( $html );
    }

    /**
     * Localize a URL (e.g. append ?lang=fr) for cross-language navigation.
     */
    public function url( string $url ): string {
        if ( $url === '' ) {
            return '';
        }
        $localized = $url;
        if ( function_exists( 'get_lang_url' ) ) {
            $value = \get_lang_url( $url );
            if ( is_string( $value ) && $value !== '' ) {
                $localized = $value;
            }
        }
        return (string) apply_filters( 'innsight/translator/url', $localized, $url );
    }
}
