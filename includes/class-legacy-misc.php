<?php
/**
 * @package Innsight
 */

namespace Innsight;

defined( 'ABSPATH' ) || exit;

/**
 * LegacyMisc - odds and ends the yuna-innsight plugin used to own
 * that don't fit into the other Legacy* classes but that admins /
 * visitors would notice if we didn't port them.
 *
 * Three shims:
 *
 * 1. **Main More Info URL** field on the POI (`point_of_interest`)
 *    term edit form. Legacy plugin let admins pick which post's
 *    permalink becomes the primary "More info" link in the sheet.
 *    Value stored as `main_more_info_url` term meta - my
 *    Featured-in popover picks it up when present.
 *
 * 2. **Portfolio -> Activities** post-type label override. Legacy
 *    plugin renamed the `portfolio` CPT to "Activities" in the
 *    admin menu. Cosmetic-only but familiar to the admin team.
 *
 * 3. **iOS install banner**. Legacy plugin printed a small "Add to
 *    Home Screen" install prompt in the footer for PWA-capable
 *    browsers. Simplified: shows on the map template page only,
 *    listens for `beforeinstallprompt`, hides itself on dismiss +
 *    remembers via localStorage.
 *
 * Sentinel: skipped when `add_custom_map_shortcode()` is defined
 * (= legacy plugin still active). No double-registration during a
 * transitional install.
 */
final class LegacyMisc {

    public function register(): void {
        add_action( 'point_of_interest_edit_form_fields', array( $this, 'render_main_more_info_field' ), 20 );
        add_action( 'edited_point_of_interest',           array( $this, 'save_main_more_info_field' ) );
        add_filter( 'register_post_type_args',            array( $this, 'rename_portfolio_labels' ), 10, 2 );
        add_action( 'wp_footer',                          array( $this, 'render_install_banner' ), 99 );
    }

    private function legacy_active(): bool {
        return function_exists( 'add_custom_map_shortcode' );
    }

    /* ─── 1. Main More Info URL field ────────────────────────────────────── */

    public function render_main_more_info_field( $term ): void {
        if ( $this->legacy_active() ) return;
        if ( ! $term || ! isset( $term->term_id ) ) return;
        $current = get_term_meta( (int) $term->term_id, 'main_more_info_url', true );
        $posts   = get_posts( array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'numberposts'    => 40,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'tax_query'      => array(
                array(
                    'taxonomy' => 'point_of_interest',
                    'field'    => 'term_id',
                    'terms'    => (int) $term->term_id,
                ),
            ),
        ) );

        ?>
        <tr class="form-field">
            <th scope="row" valign="top">
                <label for="main_more_info_url"><?php esc_html_e( 'Main More Info URL', 'innsight' ); ?></label>
            </th>
            <td>
                <select name="main_more_info_url" id="main_more_info_url" style="min-width:320px">
                    <option value=""><?php esc_html_e( '— none (use the first Featured-in post) —', 'innsight' ); ?></option>
                    <?php foreach ( $posts as $i => $p ) :
                        $url = get_permalink( $p->ID );
                        $sel = selected( $current, $url, false );
                        ?>
                        <option value="<?php echo esc_url( $url ); ?>" <?php echo $sel; // phpcs:ignore ?>>
                            <?php echo esc_html( get_the_title( $p ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e( 'Optional. When set, the POI sheet\'s primary action links here directly. Otherwise the Featured-in popover shows every post referencing this POI.', 'innsight' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_main_more_info_field( int $term_id ): void {
        if ( $this->legacy_active() ) return;
        if ( ! current_user_can( 'edit_term', $term_id ) ) return;
        if ( isset( $_POST['main_more_info_url'] ) ) {
            $url = esc_url_raw( wp_unslash( (string) $_POST['main_more_info_url'] ) );
            if ( $url === '' ) delete_term_meta( $term_id, 'main_more_info_url' );
            else                update_term_meta( $term_id, 'main_more_info_url', $url );
        }
    }

    /* ─── 2. Portfolio -> Activities ─────────────────────────────────────── */

    public function rename_portfolio_labels( array $args, string $post_type ): array {
        if ( $this->legacy_active() ) return $args;
        if ( $post_type !== 'portfolio' ) return $args;
        $args['labels']['name']               = __( 'Activities', 'innsight' );
        $args['labels']['singular_name']      = __( 'Activity',   'innsight' );
        $args['labels']['add_new']            = __( 'Add New Activity', 'innsight' );
        $args['labels']['add_new_item']       = __( 'Add New Activity', 'innsight' );
        $args['labels']['edit_item']          = __( 'Edit Activity',    'innsight' );
        $args['labels']['new_item']           = __( 'New Activity',     'innsight' );
        $args['labels']['view_item']          = __( 'View Activity',    'innsight' );
        $args['labels']['search_items']       = __( 'Search Activities', 'innsight' );
        $args['labels']['not_found']          = __( 'No Activities found', 'innsight' );
        $args['labels']['not_found_in_trash'] = __( 'No Activities found in Trash', 'innsight' );
        $args['labels']['all_items']          = __( 'All Activities', 'innsight' );
        $args['labels']['menu_name']          = __( 'Activities',     'innsight' );
        if ( empty( $args['menu_icon'] ) ) $args['menu_icon'] = 'dashicons-location-alt';
        return $args;
    }

    /* ─── 3. iOS / PWA install banner ────────────────────────────────────── */

    /**
     * Simple install banner: only prints on pages using our map
     * template. Beforeinstallprompt on Android/Chrome fires our own
     * install button; iOS Safari doesn't fire it, so we detect iOS
     * separately and show a small "Add to Home Screen" hint pointing
     * at the Share sheet.
     *
     * Dismissible; remembered in localStorage so returning visitors
     * don't see it again after saying no.
     */
    public function render_install_banner(): void {
        if ( $this->legacy_active() ) return;
        if ( empty( innsight_settings( 'pwa_enabled', 1 ) ) ) return;
        // Only render on pages that use the Innsight map template -
        // other pages don't need the install nag.
        if ( ! is_singular() ) return;
        $slug = get_page_template_slug( get_queried_object_id() );
        if ( strpos( (string) $slug, 'page-map.php' ) === false ) return;

        $short = (string) ( innsight_settings( 'pwa_short_name', '' ) ) ?: (string) get_bloginfo( 'name' );
        ?>
<div id="innsight-install" hidden style="position:fixed;left:12px;right:12px;bottom:12px;z-index:99999;background:#0F0F0F;color:#fff;border-radius:14px;padding:12px 14px;display:flex;align-items:center;gap:10px;box-shadow:0 6px 22px rgba(0,0,0,.35);font:14px/1.35 system-ui">
    <span style="flex:1"><strong><?php echo esc_html( $short ); ?></strong> — <span data-innsight-install-copy>add to your home screen</span></span>
    <button type="button" id="innsight-install-btn" style="background:#C9F73F;color:#0F0F0F;border:0;border-radius:999px;padding:8px 14px;font-weight:600;cursor:pointer" hidden>Install</button>
    <button type="button" id="innsight-install-close" aria-label="Dismiss" style="background:transparent;color:#fff;border:0;font-size:20px;cursor:pointer;padding:0 4px">×</button>
</div>
<script>
(function(){
    var el = document.getElementById('innsight-install');
    if (!el) return;
    var btn = document.getElementById('innsight-install-btn');
    var close = document.getElementById('innsight-install-close');
    var copy = el.querySelector('[data-innsight-install-copy]');
    var STORAGE_KEY = 'innsight.installDismissed';
    if (localStorage.getItem(STORAGE_KEY)) return;
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isStandalone) return;
    var ua = window.navigator.userAgent.toLowerCase();
    var isIos = /iphone|ipad|ipod/.test(ua) && !window.MSStream;
    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e){
        e.preventDefault(); deferredPrompt = e;
        el.hidden = false; btn.hidden = false;
    });
    btn.addEventListener('click', function(){
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function(){ el.hidden = true; localStorage.setItem(STORAGE_KEY, '1'); });
    });
    close.addEventListener('click', function(){ el.hidden = true; localStorage.setItem(STORAGE_KEY, '1'); });
    if (isIos) {
        el.hidden = false; btn.hidden = true;
        copy.innerHTML = 'tap <b style="font-size:16px">&#x2191;</b> then <b>Add to Home Screen</b>';
    }
})();
</script>
        <?php
    }
}
