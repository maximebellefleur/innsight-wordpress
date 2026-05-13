# Innsight (WordPress plugin)

WordPress integration for the [Innsight](https://github.com/maximebellefleur/innsight) map engine. Renders interactive Leaflet/Mapbox maps via a `[innsight_map]` shortcode, reading the same database structures the legacy `yuna-innsight` plugin used (POI taxonomy, portfolio activities, event posts, ACF options) - zero migration.

The plugin is intentionally lean: it produces a v1 JSON config and hands rendering off to the Innsight engine. All visual layout is owned by skins (HTML + CSS) so the same map can be re-styled per brand without touching PHP.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Optional: ACF or ACF Pro (the plugin falls back to plain post/term meta if ACF is not present)
- Optional: Transposh (translation hooks are detected at runtime and skipped if absent)

## Installation

1. Drop the `innsight-wordpress` folder into `wp-content/plugins/` (or upload the zip).
2. Activate `Innsight` from the Plugins screen.
3. Visit `Settings > Innsight` to confirm the engine source, default provider, and (if used) Mapbox/Google keys.
4. Add `[innsight_map]` to any post or page. The current post's ACF map fields drive the configuration.

The legacy `yuna-innsight` plugin can be deactivated once `Innsight` is in place; the data structures it created remain in the database and continue to power the new plugin.

## Migrating from `yuna-innsight`

The new plugin is a drop-in replacement. Here is the exact path:

1. **Activate Innsight side-by-side with `yuna-innsight`.** Both plugins read from the same WordPress structures (POI taxonomy terms, portfolio activity post meta, ACF options). They do not fight over data. The `[custom_map]` shortcode keeps working - Innsight registers it as an alias of `[innsight_map]`. The map renders identically because the data source is the same.
2. **Verify your existing maps still render** under Innsight - load any page that uses `[custom_map]` and confirm pins / popups appear.
3. **Take a backup.** `Settings > Innsight Import > Download backup (JSON)`. Keep the file.
4. **Deactivate `yuna-innsight`.** Innsight's `LegacyCompat` re-registers the `point_of_interest` taxonomy at `init` priority 11 so the legacy POI terms keep being readable after the legacy plugin is gone.
5. **Edit the site-wide hostel defaults at `Settings > Innsight Defaults`.** This page reads from and writes to the exact same `wp_options` keys ACF used (`options_maps_titre`, `options_maps_latitude`, `options_maps_longitude`, `options_maps_text`, `options_maps_more_info_url`, `options_maps_bg_img`). No data is duplicated; deactivating Innsight in the future and re-activating yuna-innsight would surface the exact same values in the legacy admin.

What survives deactivation:

| Legacy structure | Survives | Notes |
|---|---|---|
| `point_of_interest` taxonomy terms + their meta | Yes | `LegacyCompat` re-registers the taxonomy if no other code does. |
| `portfolio` post type + activity meta (`latitude`, `longitude`, `activity_main_slogan`) | Yes | Theme owns the post type, not the legacy plugin. |
| Hostel default options (`options_maps_*` in `wp_options`) | Yes | Innsight reads via `get_option`; admin edits via `Settings > Innsight Defaults`. |
| Per-post map config (`map_base_location`, `map_zoom_level`, `maps_add_markers`, `maps_existing_act_marker_id`, `maps_paths_box`) | Data: yes. Edit UI: only with ACF re-defined. | Innsight reads via `get_post_meta`. To edit on new posts, either keep an ACF group around or use the Custom Fields meta box. |
| Event posts (`event_poi`, `single_event_gallery_description`, `latitude`, `longitude`) | Yes | Pure post meta. |
| `[custom_map]` shortcode | Yes | Innsight registers it as an alias for `[innsight_map]`. |
| `wp_ajax_generate_kml` AJAX endpoint | Yes | Innsight registers a server-side KML handler at the same action name. |
| Page template `page-map.php` | Falls back to default theme template. | The template file lives in the legacy plugin folder. Pages assigned to it render with the theme's default template; the shortcode in the content still renders the map. |

A migration smoke test in this repo (`includes/class-legacy-compat.php`) verifies the round-trip: render the same POIs while both plugins are active, deactivate the legacy plugin, render again - identical output.

## Importing POIs

`Settings > Innsight Import` opens a guided three-step workflow:

1. **Back up first.** Click "Download backup (JSON)" at the top of the page. The download is a self-describing JSON file containing every existing `poi` post and every legacy `point_of_interest` taxonomy term. Keep it - you can re-import it through the same screen.
2. **Upload a CSV or JSON file.** A bundled `sample-data/frankfurt-pois.csv` (116 OpenStreetMap POIs) and an equivalent `frankfurt-pois.json` ship with the plugin and are linked from the page. Encoding is auto-normalized (UTF-8 / Windows-1252 / ISO-8859-1).
3. **Map fields and preview.** Auto-suggested mappings appear in a table; override any row from the dropdown. The first 5 rows are rendered as a preview so the mapping is visually verifiable. Click "Import N rows" when the preview looks right.

Re-running the same import is safe: existing posts are matched by `osm_id` (or, if absent, by exact title + lat/lon within ~11 m) and updated rather than duplicated.

`mapcategory` values like `bars_and_pubs`, `cafes`, `german_restaurants` are auto-normalized into the design's 5 buckets (drinks/eats/sights/shops/events). Override the mapping via the `innsight/poi/category_map` filter.

## Shortcode

The plugin registers **two shortcode names that behave identically**:

- `[custom_map]` - the legacy yuna-innsight tag. Existing pages already using this keep working without any edit. Recommended for migration.
- `[innsight_map]` - the new canonical tag. Use it in fresh content if you want to be explicit about which engine renders the map.

Both accept the same attributes, both call the same `Shortcode::render()` method. Switching one to the other is a no-op.

```text
[custom_map]                                          <- no args, drives everything from
                                                          Settings + the page's ACF fields
[custom_map post_id="123" viewmode="single"]          <- override the source post / mode
[innsight_map height="80vh"]                          <- new canonical tag, same behavior
```

| Attribute | Default | Description |
|---|---|---|
| `post_id` | current post ID | Source post for ACF fields. |
| `location` | ACF `map_base_location` | Place-name string sent to Nominatim if no explicit center. |
| `zoom` | ACF `map_zoom_level` or 12 | Initial zoom (1 - 20). |
| `viewmode` | `multi` | `event`, `single`, `act`, `multi`, `blogs` - matches legacy plugin behavior. |
| `height` | `70vh` | CSS length applied to the map container. Validated against an allowlist. |
| `provider` | `Settings > Innsight > Default provider` | Per-render override. One of `osm`, `mapbox`, `mapbox-gl`, `google` (google is a v0.2 stub). |
| `skin` | `Settings > Innsight > Design` | Per-render override. One of `solike2025`, `innsight2026`. |
| `render_mode` | `Settings > Innsight > Render mode` | `inline` (no extra HTTP) or `fetch` (REST). |
| `taxonomy_slug`, `taxonomy_id` | empty | Optional filter for the `multi` viewmode. |

**For migration, you don't have to touch any shortcode argument.** Set `Design` in Settings -> Innsight to choose `solike2025` (legacy look) or `innsight2026` (new design); paste your Mapbox token when picking innsight2026; save; reload your existing `[custom_map]` pages.

## REST endpoints

- `GET /wp-json/innsight/v1/map?post_id=123&viewmode=multi` - returns the v1 JSON config.
- `GET /wp-json/innsight/v1/kml?post_id=123&viewmode=multi` - returns a KML file.
- `GET /wp-admin/admin-ajax.php?action=generate_kml&post_id=123` - legacy AJAX KML, kept for backward compatibility.

The REST permission check is filterable via `innsight/rest/permission`; default is public.

## Filters & actions

```php
// Modify the intermediate marker / path data right before JSON shaping.
add_filter( 'innsight/data/intermediate', function ( $data, $args ) { /* ... */ return $data; }, 10, 2 );

// Modify the final v1 JSON config.
add_filter( 'innsight/data/config', function ( $config, $intermediate, $atts ) { /* ... */ return $config; }, 10, 3 );

// Wire a different translation engine (Polylang, WPML, custom).
add_filter( 'innsight/translator/text', function ( $translated, $original ) { /* ... */ return $translated; }, 10, 2 );
add_filter( 'innsight/translator/url',  function ( $localized, $original ) { /* ... */ return $localized; }, 10, 2 );

// Lock the REST endpoint.
add_filter( 'innsight/rest/permission', function () { return current_user_can( 'edit_posts' ); } );

// Swap any service.
add_filter( 'innsight/services', function ( $services, $plugin ) { /* ... */ return $services; }, 10, 2 );

// Render-time hooks.
add_action( 'innsight/before_render', function ( $dom_id, $config, $atts ) { /* ... */ }, 10, 3 );
add_action( 'innsight/after_render',  function ( $dom_id, $config, $atts ) { /* ... */ }, 10, 3 );
```

## DB fields read by the plugin

### Options (`get_field('...', 'option')`)

| Field | Used for |
|---|---|
| `maps_latitude`, `maps_longitude` | Default map center fallback. |
| `maps_titre`, `maps_text`, `maps_bg_img` | Default hostel marker. |
| `maps_more_info_url` | Default hostel marker button. |

### Post-level (`get_field('...', $post_id)`)

| Field | Used for |
|---|---|
| `map_base_location` | Place-name to geocode for map center. |
| `map_zoom_level` | Initial zoom. |
| `maps_add_markers`, `maps_add_paths` | Toggle POI / path layers per post. |
| `maps_existing_act_marker_id` | Portfolio post IDs to include as activities. |
| `maps_paths_box` | Repeater of paths with `maps_path_title`, `maps_path_color`, `maps_path_points_name`. |

### POI taxonomy term meta (`point_of_interest`)

| Term meta | Used for |
|---|---|
| `poi_latitude`, `poi_longitude` | POI location. |
| `poi_type` | Cluster group + per-type styling. |
| `poi_category` | Icon override (`md-*` or `map-*`). |
| `poi_image` | Popup image. |
| `poi_url_link`, `main_more_info_url` | Popup button. |

### Portfolio post meta

| Post meta | Used for |
|---|---|
| `latitude`, `longitude` | Activity location. |
| `activity_main_slogan` | Activity description. |

### Event post meta (posts in `event` category)

| Post meta | Used for |
|---|---|
| `event_poi` | Include the post as a map marker if truthy. |
| `single_event_gallery_description` | Event description. |
| `latitude`, `longitude` | Event location. |

## Out of scope

The legacy `yuna-innsight` plugin also handled PWA (manifest, service worker, iOS install prompts), an admin term-creation modal, and a custom page template. Those are intentionally not in this plugin - they belong to a separate site-specific plugin or to the active theme.

## License

MIT - see [LICENSE](./LICENSE).
