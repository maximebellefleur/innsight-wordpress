# Changelog

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
