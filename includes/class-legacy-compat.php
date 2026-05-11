<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * LegacyCompat - keeps yuna-innsight DB structures readable after the legacy
 * plugin is deactivated.
 *
 * Background. The legacy plugin registers a `point_of_interest` hierarchical
 * taxonomy at `init` priority 10 and reads it everywhere. The terms + their
 * meta survive deactivation in `wp_terms` / `wp_termmeta`, but `get_the_terms`
 * (which the new plugin's DataSource calls) returns nothing when the taxonomy
 * is not registered. Every legacy POI would silently vanish from the map.
 *
 * What this class does. At `init` priority 11 (after the legacy plugin if
 * it is still active), it checks whether the taxonomy exists. If yes, it
 * leaves it alone (legacy's labels / slug win - no fight). If no, it
 * registers it with a sensible default so the new plugin can keep reading the
 * existing data.
 *
 * What it does NOT do. It does not register the legacy ACF field groups
 * (Yuna InnSight settings page + per-post map fields). Those define the
 * admin EDITING UI, not the data. The values themselves stay in `wp_options`
 * and `wp_postmeta` regardless. The new plugin's "Map defaults" admin page
 * provides an editor for the site-wide defaults; per-post ACF fields can be
 * re-defined by any ACF plugin or edited via WordPress's Custom Fields box.
 */
final class LegacyCompat {

    public const TAXONOMY = 'point_of_interest';

    public function register(): void {
        add_action( 'init', array( $this, 'maybe_register_taxonomy' ), 11 );
    }

    public function maybe_register_taxonomy(): void {
        if ( taxonomy_exists( self::TAXONOMY ) ) {
            // Legacy plugin (or anything else) already registered it. Don't
            // fight - their labels and slug win.
            return;
        }
        register_taxonomy( self::TAXONOMY, array( 'post' ), array(
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'point-of-interest' ),
            'labels'            => array(
                'name'              => __( 'Points of Interest', 'innsight' ),
                'singular_name'     => __( 'Point of Interest', 'innsight' ),
                'menu_name'         => __( 'Points of Interest', 'innsight' ),
                'all_items'         => __( 'All Points of Interest', 'innsight' ),
                'parent_item'       => __( 'Parent Point of Interest', 'innsight' ),
                'parent_item_colon' => __( 'Parent Point of Interest:', 'innsight' ),
                'new_item_name'     => __( 'New Point of Interest', 'innsight' ),
                'add_new_item'      => __( 'Add New Point of Interest', 'innsight' ),
                'edit_item'         => __( 'Edit Point of Interest', 'innsight' ),
                'update_item'       => __( 'Update Point of Interest', 'innsight' ),
                'search_items'      => __( 'Search Points of Interest', 'innsight' ),
            ),
        ) );
    }

    /**
     * Returns true if the active site looks like a yuna-innsight install
     * (i.e. has POI terms or default-hostel option values present in the DB).
     * Used to inform the migration notice on the import / settings pages.
     */
    public static function looks_like_legacy_install(): bool {
        if ( get_option( 'options_maps_titre' ) || get_option( 'options_maps_latitude' ) ) {
            return true;
        }
        $terms = get_terms( array(
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'number'     => 1,
            'fields'     => 'ids',
        ) );
        return ! is_wp_error( $terms ) && ! empty( $terms );
    }
}
