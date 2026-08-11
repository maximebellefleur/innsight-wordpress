<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * LegacyAcf - re-registers the ACF field groups that yuna-innsight
 * used to own, so the admin editing UI keeps working after the legacy
 * plugin is deactivated / deleted.
 *
 * Three groups mirror the yuna-innsight definitions verbatim:
 *   1. POI Fields (attached to point_of_interest taxonomy terms) -
 *      poi_latitude, poi_longitude, distance_from_hostel_km, poi_type,
 *      poi_category, poi_image, poi_url_link.
 *   2. GENERAL FIELDS (attached to yuna_innsight options page) -
 *      maps_bg_img, maps_titre, maps_text, maps_more_info_url,
 *      maps_latitude, maps_longitude. Kept as-is so any ACF option
 *      pages built by third parties still see the group.
 *   3. Maps Shortcode Fields (attached to Portfolio post types) -
 *      map_to_post, map_base_location, map_zoom_level, map_quick_name,
 *      maps_add_markers, maps_add_paths, maps_existing_act_marker_id,
 *      maps_poi_markers, maps_paths_box + nested repeater rows.
 *
 * Behaviour:
 *   - Runs on acf/init - no-ops if ACF isn't installed.
 *   - Skips when the legacy plugin's `add_custom_map_shortcode` function
 *     exists (= legacy still active), so we never double-register on a
 *     transitional install.
 *   - Uses the same field-group keys as the legacy plugin so existing
 *     ACF meta values keep resolving.
 */
final class LegacyAcf {

    public function register(): void {
        add_action( 'acf/init', array( $this, 'register_field_groups' ), 20 );
        // Options page - the legacy plugin registers "Yuna InnSight" under
        // Settings via acf_add_options_page. Only register it when the
        // legacy plugin isn't active AND ACF Pro is available so the
        // GENERAL FIELDS group still has somewhere to render.
        add_action( 'acf/init', array( $this, 'register_options_page' ), 15 );
    }

    /**
     * Legacy plugin sentinel. `add_custom_map_shortcode` is a global
     * function only yuna-innsight defines; when it's absent we know the
     * legacy plugin is deactivated / deleted.
     */
    private function legacy_active(): bool {
        return function_exists( 'add_custom_map_shortcode' );
    }

    public function register_options_page(): void {
        if ( $this->legacy_active() ) return;
        if ( ! function_exists( 'acf_add_options_page' ) ) return;
        // Same slug the legacy plugin used so the GENERAL FIELDS group's
        // location rule (`options_page == yuna_innsight`) still matches.
        acf_add_options_page( array(
            'page_title'  => __( 'Innsight - Site defaults (legacy ACF)', 'innsight' ),
            'menu_title'  => __( 'Site defaults', 'innsight' ),
            'menu_slug'   => 'yuna_innsight',
            'capability'  => 'manage_options',
            'parent_slug' => 'innsight',
            'redirect'    => false,
        ) );
    }

    public function register_field_groups(): void {
        if ( $this->legacy_active() ) return;
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        // ── Group 1 · POI Fields (point_of_interest term) ────────────────
        acf_add_local_field_group( array(
            'key'    => 'group_6634df1b21de1',
            'title'  => 'POI Fields',
            'fields' => array(
                array(
                    'key'   => 'field_6634df6a1e329',
                    'label' => 'POI Latitude',
                    'name'  => 'poi_latitude',
                    'type'  => 'text',
                    'required' => 1,
                ),
                array(
                    'key'   => 'field_6634df8b1e32a',
                    'label' => 'POI Longitude',
                    'name'  => 'poi_longitude',
                    'type'  => 'text',
                    'required' => 1,
                ),
                array(
                    'key'   => 'field_6634e0141e32c',
                    'label' => 'Distance from Hostel (km)',
                    'name'  => 'distance_from_hostel_km',
                    'type'  => 'text',
                    'required' => 0,
                ),
                array(
                    'key'   => 'field_6634df921e32b',
                    'label' => 'POI Type',
                    'name'  => 'poi_type',
                    'type'  => 'select',
                    'choices' => array(
                        'food'      => 'Restaurant',
                        'bar'       => 'Bar',
                        'city'      => 'City / Village / Town',
                        'place'     => 'Place',
                        'transport' => 'Transport',
                        'hike'      => 'Hike Start/End Point',
                        'shop'      => 'Grocery or Shopping',
                        'public'    => 'Police, Hospital, Public Buildings',
                        'land'      => 'Landscape',
                    ),
                    'return_format' => 'value',
                ),
                array(
                    'key'   => 'field_664fdd683bcc5',
                    'label' => 'POI Category (Icon)',
                    'name'  => 'poi_category',
                    'type'  => 'text',
                    'instructions' => 'Icon slug from Material Design / map icon set (e.g. "md-local-cafe", "map-boat-tour"). See legacy poi_category select for the full list.',
                    'required' => 0,
                ),
                array(
                    'key'   => 'field_6634e02b1e32d',
                    'label' => 'POI Image',
                    'name'  => 'poi_image',
                    'type'  => 'image',
                    'return_format' => 'url',
                    'preview_size'  => 'medium',
                    'library'       => 'all',
                ),
                array(
                    'key'   => 'field_6634e0421e32e',
                    'label' => 'POI URL / Link',
                    'name'  => 'poi_url_link',
                    'type'  => 'link',
                    'return_format' => 'array',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param'    => 'taxonomy',
                        'operator' => '==',
                        'value'    => 'point_of_interest',
                    ),
                ),
            ),
            'menu_order'    => 0,
            'position'      => 'normal',
            'style'         => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active' => true,
        ) );

        // ── Group 2 · GENERAL FIELDS (options page) ──────────────────────
        acf_add_local_field_group( array(
            'key'    => 'group_55797d5741471',
            'title'  => 'GENERAL FIELDS',
            'fields' => array(
                array(
                    'key' => 'field_663d481f06496', 'label' => 'Maps Bg Img',       'name' => 'maps_bg_img',       'type' => 'image', 'required' => 1, 'return_format' => 'id', 'library' => 'all', 'preview_size' => 'medium',
                ),
                array(
                    'key' => 'field_663d485306497', 'label' => 'Maps Titre',        'name' => 'maps_titre',        'type' => 'text', 'required' => 1,
                ),
                array(
                    'key' => 'field_663d486806498', 'label' => 'Maps Text',         'name' => 'maps_text',         'type' => 'wysiwyg', 'required' => 1, 'toolbar' => 'basic', 'media_upload' => 0,
                ),
                array(
                    'key' => 'field_663d488006499', 'label' => 'Maps More Info URL','name' => 'maps_more_info_url','type' => 'link', 'required' => 1, 'return_format' => 'array',
                ),
                array(
                    'key' => 'field_456d575306497', 'label' => 'Default Latitude',  'name' => 'maps_latitude',     'type' => 'text', 'required' => 1,
                ),
                array(
                    'key' => 'field_456f575301234', 'label' => 'Default Longitude', 'name' => 'maps_longitude',    'type' => 'text', 'required' => 1,
                ),
            ),
            'location' => array(
                array(
                    array( 'param' => 'options_page', 'operator' => '==', 'value' => 'yuna_innsight' ),
                ),
            ),
            'menu_order' => 0,
            'position'   => 'acf_after_title',
            'style'      => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active' => true,
        ) );

        // ── Group 3 · Maps Shortcode Fields (per-post) ───────────────────
        // Legacy plugin targets the Portfolio post types "acts" and
        // "events" (yuna-portfolio companion). We register against those
        // when they exist and fall back to "post" so admins with a plain
        // WP setup still get the fields.
        $post_type_rules = array();
        foreach ( array( 'acts', 'event', 'post', 'page' ) as $pt ) {
            if ( post_type_exists( $pt ) ) {
                $post_type_rules[] = array( array( 'param' => 'post_type', 'operator' => '==', 'value' => $pt ) );
            }
        }
        if ( empty( $post_type_rules ) ) {
            $post_type_rules[] = array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) );
        }

        acf_add_local_field_group( array(
            'key'    => 'group_5680d5b7986f01',
            'title'  => 'Maps Shortcode Fields',
            'fields' => array(
                array(
                    'key' => 'field_map_to_post_001',
                    'label' => 'Map to Post',
                    'name'  => 'map_to_post',
                    'type'  => 'true_false',
                    'ui'    => 1,
                    'default_value' => 0,
                ),
                array(
                    'key' => 'field_map_base_location_002',
                    'label' => 'Map Base Location',
                    'name'  => 'map_base_location',
                    'type'  => 'text',
                    'instructions' => 'Location name to geocode (overrides the site default). Leave empty to use the hostel default.',
                    'conditional_logic' => array( array( array( 'field' => 'field_map_to_post_001', 'operator' => '==', 'value' => '1' ) ) ),
                ),
                array(
                    'key' => 'field_map_zoom_level_003',
                    'label' => 'Map Zoom Level',
                    'name'  => 'map_zoom_level',
                    'type'  => 'number',
                    'min'   => 2, 'max' => 19,
                    'default_value' => 13,
                    'conditional_logic' => array( array( array( 'field' => 'field_map_to_post_001', 'operator' => '==', 'value' => '1' ) ) ),
                ),
                array(
                    'key' => 'field_map_quick_name_004',
                    'label' => 'Map Quick Name',
                    'name'  => 'map_quick_name',
                    'type'  => 'text',
                    'conditional_logic' => array( array( array( 'field' => 'field_map_to_post_001', 'operator' => '==', 'value' => '1' ) ) ),
                ),
                array(
                    'key' => 'field_maps_add_markers_005',
                    'label' => 'Add Existing Activity Markers',
                    'name'  => 'maps_add_markers',
                    'type'  => 'true_false',
                    'ui'    => 1,
                    'default_value' => 0,
                    'conditional_logic' => array( array( array( 'field' => 'field_map_to_post_001', 'operator' => '==', 'value' => '1' ) ) ),
                ),
                array(
                    'key' => 'field_maps_add_paths_006',
                    'label' => 'Add Path(s)',
                    'name'  => 'maps_add_paths',
                    'type'  => 'true_false',
                    'ui'    => 1,
                    'default_value' => 0,
                    'conditional_logic' => array( array( array( 'field' => 'field_map_to_post_001', 'operator' => '==', 'value' => '1' ) ) ),
                ),
                array(
                    'key' => 'field_maps_existing_act_007',
                    'label' => 'Existing Activity Markers',
                    'name'  => 'maps_existing_act_marker_id',
                    'type'  => 'repeater',
                    'button_label' => 'Add marker',
                    'layout' => 'row',
                    'sub_fields' => array(
                        array(
                            'key' => 'sub_field_act_marker_id',
                            'label' => 'Activity',
                            'name'  => 'act_marker_id',
                            'type'  => 'post_object',
                            'post_type' => array( 'acts', 'event', 'post' ),
                            'return_format' => 'id',
                            'ui' => 1,
                        ),
                    ),
                    'conditional_logic' => array( array( array( 'field' => 'field_maps_add_markers_005', 'operator' => '==', 'value' => '1' ) ) ),
                ),
                array(
                    'key' => 'field_maps_paths_box_009',
                    'label' => 'Paths',
                    'name'  => 'maps_paths_box',
                    'type'  => 'repeater',
                    'button_label' => 'Add path',
                    'layout' => 'block',
                    'sub_fields' => array(
                        array(
                            'key' => 'sub_field_maps_path_title',
                            'label' => 'Path Title',
                            'name'  => 'maps_path_title',
                            'type'  => 'text',
                        ),
                        array(
                            'key' => 'sub_field_maps_path_color',
                            'label' => 'Path Color',
                            'name'  => 'maps_path_color',
                            'type'  => 'color_picker',
                            'default_value' => '#da011a',
                        ),
                        array(
                            'key' => 'sub_field_maps_path_points',
                            'label' => 'Coordinates (lat,lon per line)',
                            'name'  => 'maps_path_points_name',
                            'type'  => 'textarea',
                            'instructions' => 'One "lat,lon" per line, e.g. "46.6863,7.8632".',
                            'rows'  => 6,
                        ),
                    ),
                    'conditional_logic' => array( array( array( 'field' => 'field_maps_add_paths_006', 'operator' => '==', 'value' => '1' ) ) ),
                ),
            ),
            'location' => $post_type_rules,
            'menu_order' => 0,
            'position'   => 'normal',
            'style'      => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active' => true,
        ) );
    }
}
