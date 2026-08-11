<?php
/**
 * Template Name: Map Page (Innsight fullscreen)
 *
 * Fullscreen map page template. Same shape as the yuna-innsight
 * `page-map.php` so any WP page that previously used that template
 * (stored in `_wp_page_template` meta) renders identically after
 * yuna-innsight is deleted.
 *
 * Deliberately minimal:
 *   - <html>, <head>, wp_head() so themes/plugins can enqueue.
 *   - body.app-loading + <div class="loader"> so the legacy loading
 *     spinner CSS still fires until the new skin removes it.
 *   - The page's OWN content is rendered via the loop; if empty, the
 *     legacy [custom_map] fallback fires so an empty map page still
 *     shows something.
 *   - get_footer() so the theme's footer scripts still run.
 *
 * @package Innsight
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'app-loading innsight-map-page' ); ?>>
	<div class="loader"></div>
	<div class="map-wrap">
		<div class="container main-content">
			<div class="row">
				<?php
				// Prefer the page's own content when present (usually
				// includes the [innsight_map] or [custom_map] shortcode
				// with real params). Fall back to a sensible default
				// so an empty page still renders a map.
				if ( have_posts() ) {
					while ( have_posts() ) {
						the_post();
						$content = trim( (string) get_the_content() );
						if ( $content !== '' ) {
							the_content();
						} else {
							echo do_shortcode( '[innsight_map height="100dvh"]' );
						}
					}
				} else {
					echo do_shortcode( '[innsight_map height="100dvh"]' );
				}
				?>
			</div>
		</div>
	</div>
<?php get_footer(); ?>
