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

## Shortcode

```text
[innsight_map post_id="123" location="Interlaken, Switzerland" zoom="13" viewmode="multi" height="80vh" provider="mapbox" skin="solike2025"]
```

| Attribute | Default | Description |
|---|---|---|
| `post_id` | current post ID | Source post for ACF fields. |
| `location` | ACF `map_base_location` | Place-name string sent to Nominatim if no explicit center. |
| `zoom` | ACF `map_zoom_level` or 12 | Initial zoom (1 - 20). |
| `viewmode` | `multi` | `event`, `single`, `act`, `multi`, `blogs` - matches legacy plugin behavior. |
| `height` | `70vh` | CSS length applied to the map container. Validated against an allowlist. |
| `provider` | settings.provider_default | `osm`, `mapbox`, or `google` (stub). |
| `skin` | settings.skin_name | Skin folder name under `skins/`. |
| `render_mode` | settings.render_mode | `inline` (no extra HTTP) or `fetch` (REST). |
| `taxonomy_slug`, `taxonomy_id` | empty | Optional filter for the `multi` viewmode. |

`[custom_map ...]` is registered as an alias of `[innsight_map ...]` so existing pages keep working.

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
