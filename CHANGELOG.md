# Changelog
## 0.5.0 - 2026-05-14

Big release: share-wishlist, personal notes, swipe-by-context, in-view
Google Places, plus a critical PWA cleanup that finally evicts the old
yuna service worker that was serving stale assets to returning visitors.

### Saved tab

- **Swipe context.** When the bottom sheet opens from the Saved tab,
  swipe-left / swipe-right now cycles through the user's saved POIs
  only (not the category siblings). The same context model also drives
  swipe inside the List tab (cycles the filtered list) and the new
  Friend's-picks mode (cycles the friend's selection). Default behavior
  on map clicks is unchanged.
- **Share Wishlist button** in the top-right of the Saved header.
  Opens a bottom-sheet menu with Native (Web Share API where
  available), WhatsApp, Facebook, Email, and Copy Link tiles. The link
  carries a URL-safe base64 token of the user's saved POIs (id, title,
  lat/lon, image, cat/type, tag, button) so recipients see every spot
  even on a different site / different POI ids.

### Receive flow (recipient)

- **Receive popup** auto-pops on page load when `?innsight_share=...`
  is present in the URL. Two choices: **Just preview** keeps the picks
  in `sessionStorage` and reveals a dancing **Friend's picks** chip in
  the filter bar; **Save them all** writes each pick into the user's
  localStorage saved list. The URL param is stripped after consumption
  so a refresh doesn't re-pop the modal.
- **Dancing 'Friend's picks' chip** appears as the first chip in the
  filter strip. Tapping it routes to List view and scopes the visible
  POIs to the friend's selection. The chip carries an inline X to
  dismiss the temporary selection (clears `sessionStorage`).
- **All copy is editable** in plugin Settings → Share Wishlist (popup
  title, body, button labels, dancing chip label, invite message).

### Personal notes

- **Pull-up notes panel** on every POI sheet. The peek strip at the
  bottom of the sheet shows a downward arrow + "Add a personal note" /
  "Your note" label + a hint of the textarea's top edge. Tap or
  drag-up to expand a full-screen notes panel; drag-down on the panel
  header collapses back to the sheet.
- **Auto-save** (350ms debounce) to `localStorage[innsight.notes]`.
  Status indicator goes "Auto-saving as you type" → "Saving…" → "Saved"
  so the user is never anxious about losing input. Saves on blur too.
- **Keyboard-aware**: a `--in-notes-h` CSS variable updated from
  `visualViewport.resize` keeps the textarea visible above the on-
  screen keyboard on iOS / Android.

### Google Places (in-view)

- **Triggered on every "in-view" event**, not just sheet open.
  Swiping prev/next inside the sheet now also fires enrichment for
  the newly-shown POI. The engine's localStorage cache short-circuits
  any repeat network call, so swipes through previously-seen POIs
  remain free.
- **Loading placeholders** while the request is in flight: a
  "Looking up live details…" pulsing chip in the meta row, a
  shimmer-bar where the hours line will appear, and a placeholder
  rating pill in the vibe wrap. Disappear cleanly once the request
  resolves (or when the POI already has a server-side rating/hours).

### PWA & cache

- **Stale yuna service worker** (the predecessor plugin's SW scoped
  to `/wp-content/plugins/yuna-innsight/js/`) is now unregistered on
  every Innsight boot, with any cache name containing "yuna" purged
  via `caches.delete()`. This was the root cause of "I cleared cache
  but the old map still shows" reports — the SW intercepts every
  request inside its scope.
- **Plugin version stamp** in the v1 JSON (`pluginVersion`). The
  skin compares against `localStorage[innsight.version]` and, on a
  mismatch, wipes transient caches (swipe-hint counters, Google
  Places negative-cache entries) without touching the user's saved
  POIs / notes. A `console.info('[innsight] vX')` line confirms
  which build is running.
- **Mapbox style fixed**: the bundled `mapbox-style.json` was
  referencing `road_label` (mapbox-streets-v7) instead of `road`
  (v8); also swapped `DIN Pro` → `Open Sans` so the glyph fetch no
  longer 404s for accounts without the Mapbox-only DIN Pro fonts.

### Settings

- New **Share wishlist** section in Settings → Innsight with seven
  fields: enable toggle, invite message, popup title/body/buttons,
  Friend's-picks chip label.

## 0.4.11 - 2026-05-12

- **X "remove from saved" affordance on every saved row.** Tap the X on
  the right edge to unsave without opening the sheet. Wired through
  `toggleSavedPoi` so the row disappears immediately (re-render fires
  because we're on route='save'). The X is a `<span role="button">`
  to avoid invalid nested-button HTML (the row itself is a button); a
  `stopPropagation` on its click handler keeps the row tap from also
  firing, so tapping the X never opens the sheet.

## 0.4.10 - 2026-05-12

- **Sheet button text truncates with ellipsis** when too long. The ACF
  `poi_url_link` title (e.g. "The most beautiful lakes around
  Interlaken") used to wrap onto two lines and overlap the Save button.
  `.in-sheet__act` now renders single-line, centered, with
  `text-overflow: ellipsis` so any custom button text gets clipped to
  a clean "...".


## 0.4.9 - 2026-05-12

Saved tab is real now.

- **Tab "Saved" renders the kept POIs as a list**, sorted most-recent-
  first. Same row template as the List tab so the visual stays
  consistent. Tap a row -> bottom sheet opens for that POI.
- **30-day TTL with auto-purge.** Each saved entry carries a `savedAt`
  timestamp. On every read of the Saved list, entries older than 30 days
  are filtered out AND the storage object is rewritten to hygiene-purge
  them. So the tab never shows stale rows and localStorage doesn't grow
  forever.
- **Self-contained snapshots.** When you save a POI we now store
  enough fields (lat, lon, image, imageThumb, cat, type, tag, blurb,
  rating, button) to render the saved row + re-open the sheet WITHOUT
  needing the original POI in the live data. Removing the POI from the
  WP DB doesn't break what you already saved.
- **Distance-from-base on saved rows** when a hostel reference exists.
- **Saving / unsaving from the sheet auto-refreshes the Saved tab** if
  it's the active route.
- **PWA-friendly.** localStorage persists across PWA relaunches and
  tab restarts. Key: `innsight.savedPois` (one JSON object keyed by
  POI id). Survives until the user clears site data or hits
  localStorage quota (~5-10 MB; we'll never get close).

## 0.4.8 - 2026-05-12

- **Fullscreen now fullscreens the .in-app element only**, not the host
  document. Previously the JS requested `requestFullscreen()` on the top
  document so the entire WordPress page (theme header, footer, sidebar)
  went into the FS view. Now only the Innsight UI fills the viewport;
  the WP page is hidden behind it. All chrome (search, chips, sheet,
  tab bar) remains visible and interactive in fullscreen.
  - CSS: added `.in-app:fullscreen` / `:-webkit-full-screen` pseudo-class
    rules forcing 100vw × 100vh with no max constraints.
  - JS: removed the `is-fullscreen` class chrome-hiding rules; the class
    is now just a state marker.
  - JS: listens to `fullscreenchange` so pressing Escape syncs the
    local fullscreen flag (button press will then re-enter cleanly).
- **Geolocation prompt is country-gated.** New Settings field "Allowed
  countries" (default: `CH`). The plugin reads the visitor's country
  from the CDN-provided header on every shortcode render
  (`CF-IPCountry`, `X-Country-Code`, `X-Geo-Country`,
  `GEOIP_COUNTRY_CODE`, `Cloudfront-Viewer-Country`) and emits
  `ui.liveLocation.allowed = true|false` in the JSON. The skin only
  calls `navigator.geolocation.watchPosition` when allowed.
  - Privacy default: when the allowlist is non-empty AND no header
    detected, we DO NOT prompt (strict).
  - Empty allowlist = always prompt (no gating).
  - Filter `innsight/visitor_country` lets sites plug in their own
    detection (MaxMind, ipapi, hardcoded for staging).

## 0.4.7 - 2026-05-12

Google Places enrichment, on-demand only.

- **Refactored from "enrich every POI at init" to "enrich one POI when its
  sheet opens".** The previous pass walked the entire POI list during
  bootstrap; if the user opened just one card we were paying for dozens of
  Place Details requests. Now the engine exposes a single
  `instance.enrichPoi(poi)` API which the skin calls from `openSheet(poi)`.
- **Resolves Place ID by text + location when the POI doesn't have one.**
  Legacy yuna terms never carried `googlePlaceId`. The enrichment now
  calls `places:searchText` first (biased to a 500m circle around the
  POI), takes the top match, then fetches Place Details. POIs that
  already have a `googlePlaceId` skip the search and go straight to
  Details.
- **Two-tier localStorage cache.** Found places get a 30-day TTL, "not
  found" gets a 7-day TTL so transient Google failures or genuinely-
  unmatchable POIs don't re-pay the search cost on every sheet open.
  Cache key uses the placeId when known, else the POI's stable id.
- **Sheet surfaces the new fields:**
  - Rating chip becomes "★ 4.5 (123)" once the review count lands
    (count is compact-formatted: 1.2k / 12k).
  - `openNow` from `currentOpeningHours` flips the green/red status.
  - `todaysHours` populates the hours line below the title.
  - `googleMapsUri` becomes a subtle "View on Google Maps →" link below
    the action buttons.
  - `photoUrl` fills `poi.image` when the POI had no photo.
  - `websiteUri` fills the primary button URL when missing.
- **Field mask is tight** - only `id, displayName, rating,
  userRatingCount, currentOpeningHours, regularOpeningHours, photos,
  googleMapsUri, websiteUri, nationalPhoneNumber, reviews` are requested.
  Reviews are sliced to 3.

Setup:
1. Get a Google Cloud Places API key, restrict it to your HTTP referrer.
2. Settings -> Innsight -> Google Places enrichment -> tick "Enable" +
   paste the key. Save.
3. Open any POI on the front-end. Within ~300ms you'll see the rating /
   review-count / hours / Maps link populate. Subsequent opens read from
   localStorage instantly.

Cost shape (Places API v1, May 2026): searchText ~$0.017/req +
places.details ~$0.005/req = ~$0.022 per unique POI per 30 days per
visitor browser.

## 0.4.6 - 2026-05-12

Five things in one batch.

- **Sheet drag-down-to-close + no more pull-to-refresh.** Rewrote the
  sheet gesture handler to coexist three modes decided on the first
  move past 8px: horizontal swipe (prev/next POI), vertical drag down
  from the top zone (close), native scroll below the top zone. Past 110px
  the sheet closes; otherwise it snaps back. While the sheet is open the
  document body gets `body.innsight-sheet-locked` which disables overflow
  and overscroll - kills iOS Safari + Chrome Android pull-to-refresh
  inside the sheet.
- **Sort dropdown on the list.** "SORTED BY" now opens a small menu
  (Nearest first / A → Z / Z → A). Picking one re-renders the list.
- **"X km from base" on list rows.** Haversine in JS against the
  reference point: pinned POI first, then any type='hostel', then the
  map center. Formatted as `240 m` under 1 km, `1.2 km` under 10 km,
  `15 km` above. The reference POI itself doesn't show the label.
- **Thumbnail image variant.** DataSource emits `image_thumb`
  (WordPress's `thumbnail` size) for every POI source alongside `image`
  (`medium`/`large`). JsonBuilder exposes both. The list sticker + map
  pin templates now use `imageThumb`; the sheet hero stays on the
  full-size `image`. Cuts list-view bandwidth dramatically once WP has
  the smaller intermediate generated. Falls back to the full size when
  no thumbnail was generated.
- **Live location with a pulsing 🎒.** New Settings -> Innsight options
  ("Show user's live location" + "Live location icon", default 🎒).
  Engine emits config.ui.liveLocation; the skin asks `navigator.geolocation.watchPosition`,
  drops a 44px pulsing marker (accent halo + ink-bordered core with the
  configured icon), and recenters the map on the first fix only.
  Subsequent fixes just move the dot.

Other small wins included: search box bg forced white now also stays
white when focused; list row image gets `decoding="async"` for slightly
faster paint.

## 0.4.5 - 2026-05-12

Visual + UX batch:

- **Sheet description renders HTML again.** The blurb template was using
  `{{blurb}}` (HTML-escaped), so POIs whose ACF description contains
  `<b>...</b>` or `<p>...</p>` showed the markup as text. Switched to
  `{{{blurb}}}` (raw) inside a `<div class="in-sheet__blurb">` so block
  tags are valid.
- **Hero initial is white again** over the photo + multiply wash, with a
  layered dark shadow for legibility on any underlying hue.
- **Directions button text is pure white** for both the `<button>` and
  `<a>` forms (was reading cream on ink, slightly too dim).
- **Search box forced to white.** Themes that inject form styling were
  turning the wrapper light grey.
- **Map controls have breathing room.** Fullscreen / + / - tiles now
  stack with 8px gap and each carries its own offset shadow, so they
  read as three distinct controls rather than a segmented column.
- **"You" tab + profile circle hidden.** They are reserved for a future
  user-account feature; visible-but-inert was misleading testers. Both
  re-enable cleanly from a child theme.
- **Filter snaps the map to the remaining POIs.** Tapping a chip used to
  hide markers without moving the camera; you'd lose the visible result
  set if it lived at the edge or off-screen. The engine now fits bounds
  to whatever stays visible after a cat/query filter (single-point case
  uses setView at zoom 15 to avoid maxZoom collapse).
- **Save button is real.** Toggles a POI in `localStorage`
  (`innsight.savedPois`), flips its label to `Saved ✓` with an accent
  fill, shows a "Saved!" / "Removed from saved" toast above the tab
  bar, and re-opening the same POI reflects the persisted state. Hosts
  can still listen to the `sheet:save` event for remote sync.
- **Fullscreen tries the real Fullscreen API.** When the shortcode is
  inside a page-builder iframe (Elementor / Divi / etc.), the previous
  CSS-only fullscreen only filled the iframe rect. Now we request the
  top document's `requestFullscreen()` first (same-origin), falling back
  silently to the CSS class on cross-origin or denial.

Known deferred (next batch):
- Sort dropdown (A->Z / Z->A) on the list view
- "X km from the hostel" on list rows
- List thumbnail size + cache headers
- Live location pulse + editable backpack-emoji icon
- Surfacing Google Places fields (rating / open / reviews) in the sheet
  - the engine already enriches when a POI has a `googlePlaceId`; legacy
  yuna data does not, so a lookup step is needed first.

## 0.4.4 - 2026-05-12

- **Fix: stale legacy loader looping forever in PWA / on map pages.** The
  legacy yuna-innsight `page-map.php` template adds `class="app-loading"`
  to `<body>` and a `<div class="loader">` near the top, then relies on
  the legacy plugin's JS to strip them on `window.load`. With Innsight
  overriding the `[custom_map]` shortcode, the legacy JS no longer
  enqueues, so the body class persists and the loader animates the
  `slide` keyframe forever - the page looks like it never finishes
  loading. The engine now defensively strips `body.app-loading` and
  hides any standalone `.loader` element outside its shortcode container
  on the `ready` event. No-op on non-legacy installs.

## 0.4.3 - 2026-05-12

- **Categories now derived dynamically from actual POI types** (matches the
  legacy yuna behavior of building filters from `poi_type`). Previously the
  innsight2026 chip strip was hardcoded to the design's 5 buckets
  (eats/drinks/sights/shops/events), which never matched legacy yuna
  installs whose POIs use types like `hostel`, `food`, `bar`, `activities`,
  `place`, `transport`, `hike`, `shop`. Now `JsonBuilder` walks the POI
  list, collects unique types, and assigns label + color from a known map
  covering both vocabularies. Unknown types get a hashed-palette color.
  Filterable via `innsight/data/categories`.
- **Mobile: list view caps at 100dvh + scroll containment.** The
  `.in-list` rule gains `max-height: 100dvh`, `overscroll-behavior: contain`
  (prevents iOS Safari scroll-chaining to the page), and a 110px
  `padding-bottom` so the tab bar stays reachable above the last row.

## 0.4.2 - 2026-05-12

- **Top-level "Innsight" admin menu** with a custom compass SVG icon.
  Previously the plugin spread three sub-pages under Settings (Innsight,
  Innsight Defaults, Innsight Import). Now there is one branded section in
  the WP sidebar with sub-items: Settings, Map Defaults, POIs, Add POI,
  Import. The POIs post type's menu is collapsed in here too via
  `show_in_menu => 'innsight'`.
- **Bug fix: `mapbox-gl` is now selectable from the Default provider
  dropdown.** Symptom: picking the new innsight2026 design + saving made
  the provider field "snap back" to OpenStreetMap on reload. Cause: the
  sanitizer auto-forces provider to `mapbox-gl` when the design is
  innsight2026, but `mapbox-gl` was missing from the dropdown's option
  list, so the saved value couldn't be selected and the browser
  fell back to the first option (osm). The value WAS saved correctly;
  only the display was wrong. Fixed by adding `mapbox-gl` to the dropdown.

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
