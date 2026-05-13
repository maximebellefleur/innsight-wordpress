# Changelog

## 0.4.1 - 2026-05-12

- Shortcode bootstrap: poll for `Innsight.init` specifically rather than the
  namespace existing. Each engine module sets `window.Innsight = { _utils: ... }`
  before `innsight.js` attaches `.init`; the loose `typeof Innsight === "undefined"`
  check passed too early and threw `Innsight.init is not a function` in the
  console.
- Settings page header now shows the plugin version next to the "Innsight"
  title so you can confirm at a glance which build is loaded.

## 0.4.0 - 2026-05-12

- **Drop-in replacement for `yuna-innsight`.** New `LegacyCompat` class
  re-registers the `point_of_interest` taxonomy at `init` priority 11 if no
  other code does, so existing POI terms keep being readable after the
  legacy plugin is deactivated. Verified end-to-end with a migration smoke
  test: same POI count, same hostel center, same titles before vs after
  legacy deactivation.
- **`Settings > Innsight Defaults`** admin page edits the legacy
  `options_maps_titre`, `options_maps_latitude`, `options_maps_longitude`,
  `options_maps_text`, `options_maps_more_info_url`, `options_maps_bg_img`
  values directly via the WP Settings API. No data duplication: writes hit
  the exact same `wp_options` rows ACF wrote to when yuna-innsight was
  active, so reactivating the legacy plugin surfaces the same values.
- **`poi` custom post type** registered with full meta map (lat, lon, fclass,
  mapcategory, mapcategory_normalized, description_de, description_en,
  website, website2, maps_url, osm_id, osm_code) and REST support.
- **POI Importer** (`Settings > Innsight Import`) with a guided three-step
  flow:
  1. **Backup first.** A "Download backup (JSON)" button at the top of the
     page exports every existing POI post + every legacy
     `point_of_interest` taxonomy term as a self-describing JSON file.
  2. **Upload.** Accepts CSV (semicolon or comma separated, header row
     required) or JSON (array of POI objects, or `{ "pois": [...] }`).
     Encoding is auto-normalized (UTF-8 / Windows-1252 / ISO-8859-1).
  3. **Map fields and preview.** Auto-suggests source-column to POI-field
     mappings using a regex catalog (handles common variants including the
     typo `webiste2 -> website2`); the admin can override every row from a
     dropdown. The first 5 rows are rendered as a preview table so the
     mapping is visually verifiable before executing.
- **Idempotent imports.** Re-running the same import updates existing posts
  rather than creating duplicates. Match priority: `osm_id` first; falls
  back to exact title + lat/lon proximity (~11 m).
- **Category normalization.** `mapcategory` values like `bars_and_pubs` are
  auto-normalized into the design's 5 buckets (drinks/eats/sights/shops/
  events) when `mapcategory_normalized` is left blank. Mapping is filterable
  via `innsight/poi/category_map`.
- **Bundled sample.** `sample-data/frankfurt-pois.csv` (116 POIs across
  bars, cafes, restaurants, nightlife, stores, activities) and the equivalent
  `sample-data/frankfurt-pois.json` ship with the plugin and are linked
  from the import page.
- **DataSource extended.** Multi viewmode now also reads from the new `poi`
  post type and emits its markers alongside legacy taxonomy terms +
  portfolio activities.
- **JsonBuilder extended.** Adds a `categories[]` field to the v1 JSON
  output for skins (innsight2026) that render category chips. Filterable
  via `innsight/data/categories`.

## 0.1.0 - 2026-05-08

Initial release.

- Replaces the map functionality of the legacy `yuna-innsight` plugin while reading the existing DB structures (POI taxonomy, portfolio activities, event posts, ACF options) without modification.
- `[innsight_map]` shortcode (and `[custom_map]` alias) renders the Innsight JS engine inline (default) or via REST fetch.
- `/wp-json/innsight/v1/map` REST endpoint returns the v1 JSON config for headless / SPA use.
- `/wp-json/innsight/v1/kml` and the legacy `wp_ajax_generate_kml` action both serve a server-side KML file (the engine also generates KML client-side).
- All five legacy viewmodes preserved: `event`, `single`, `act`, `multi`, `blogs`.
- Translation hooks (`translated_by_yuna`, `get_lang_url`) preserved through a `Translator` facade.
- Nominatim geocoder with transient caching and a configurable politeness email.
- Settings page at `Settings > Innsight` for engine source, default provider, Mapbox token, Google Places enrichment, render mode, geocoder cache TTL.
- Bundled solike2025 skin (HTML + CSS) for zero-fetch inline rendering.
- Bundled engine + Leaflet vendor; selectable CDN or custom-URL sources.
- Filters: `innsight/data/intermediate`, `innsight/data/config`, `innsight/translator/text`, `innsight/translator/url`, `innsight/services`, `innsight/rest/permission`.
- Actions: `innsight/before_render`, `innsight/after_render`.
