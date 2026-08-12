# Changelog
## 0.7.30 - 2026-08-12

- **Walk rings: opacity gradient.** Innermost ring paints at
  fill-opacity 0.38 + line-opacity 1.0; outermost fades to 0.08
  fill + 0.35 line, linearly interpolated across the ring count
  (works for 2 / 3 / 4 rings). Source order reversed so smaller
  rings visually sit on top of larger ones — the reachable-zone
  reading gets stronger as you approach the base.
- **Base polaroid hover lift.** Mouseover the polaroid → it
  straightens out of its tilt, scales to 1.18×, punches the
  drop shadow, holds for ~2.5s, then eases back to its resting
  pose. z-index jumps to 999 (above every pin) for the duration
  of the animation, then reverts. Delegated `mouseover` handler
  on the app root; guard on `.is-lifted` prevents re-triggering
  mid-animation.
- Files: `engine/providers/mapbox-gl-provider.js`,
  `skins/innsight2026/skin.css`,
  `skins/innsight2026/skin.js`.

## 0.7.29 - 2026-08-12

- **Fix PHP fatal introduced in 0.7.28.** `render_icon_class()`'s
  docblock contained the literal `md-*/map-*` — the `*/` terminated
  the docblock early, leaving `map- codepoints)` as bare PHP that
  PHP rejected with "syntax error, unexpected identifier map".
  Whole admin page white-screened. Rephrased the comment to
  "md- and map- codepoints".
- Files: `includes/class-admin.php`.

## 0.7.28 - 2026-08-12

- **Activities / Events icon picker is a real `<select>` now**, not
  a text input. Populated by parsing `skins/innsight2026/assets/icons.css`
  at admin load, grouped into two `<optgroup>`s (Material Icons /
  Map Icons), sorted alphabetically. Live preview still updates to
  the glyph on change. Custom values already saved that aren't in
  the parsed list survive as a "(custom)" option so nothing silently
  wipes.
- Files: `includes/class-admin.php`.

## 0.7.27 - 2026-08-12

- **Live icon preview** next to the Activities / Events icon-class
  inputs in Settings → Design. Type `md-restaurant` → the actual
  glyph renders in a 36×36 square next to the field, updating on
  every keystroke. Uses the same `icons.css` codepoint mapping the
  frontend uses, so what you see in Settings is exactly what renders
  on the map.
- Only enqueued on the Innsight admin pages (checked via `$hook`
  prefix) to avoid bloating unrelated WP admin screens.
- Files: `includes/class-admin.php`.

## 0.7.26 - 2026-08-12

- **Per-post-type icon in Settings → Design**. Two new fields:
  `Activities icon class` (default `md-directions-run`) and
  `Events icon class` (default `md-event`). Any `md-*`/`map-*` class
  shipped in `assets/icons.css` works. `portfolio_post_to_marker()`
  and `event_post_to_marker()` now read those settings instead of
  the previous hardcoded `stabilization-action` / empty string.
- **Walk-ring unit selector**. New `Ring unit` setting: `Minutes
  walking (80 m / min)` (default, matches the old behaviour), `Kilometres`,
  or `Metres`. `base_rings` now accepts fractional values ("0.5, 1,
  2" for km). `build_base_config()` converts server-side to metres
  and emits `ringRadii` + `ringUnit`; `addWalkRings()` takes radii
  in metres directly and labels chips with the raw value + unit
  ("5 MIN" / "2 KM" / "500 M").
- **Base marker sits behind POI pins**. `.innsight-base-host` z-index
  1, `.innsight-pin-host` z-index 5, `.innsight-base-ring-host` z-index
  2. Previously the polaroid rendered on top of nearby pins because
  it was added last to the DOM.
- Files: `includes/class-settings.php`, `includes/class-admin.php`,
  `includes/class-data-source.php`, `includes/class-json-builder.php`,
  `engine/features/markers.js`,
  `engine/providers/mapbox-gl-provider.js`,
  `skins/innsight2026/skin.css`.

## 0.7.25 - 2026-08-12

- **Icon rendering: switched from ligatures to legacy yuna codepoint
  mapping.** Verbatim port of the working `yuna-innsight/css/baseline.css`
  (md-* rules) and `yuna-innsight/css/map.css` (map-* rules) into
  `skins/innsight2026/assets/icons.css`. Every rule scoped with
  `.in-pin__glyph` so a theme/plugin that also defines `.md-restaurant`
  can't collide. Icons render via `:after { content: '\eXXX' }` —
  no ligature magic, no font-detection, no runtime fallback logic:
  the browser paints the glyph or nothing. `pin.html` back to
  `<i class="in-pin__glyph {{iconClass}}">` (empty element, class-only).
  Dead `iconMetaForPoi()` / `iconFontClass` / `iconLigature` removed
  from `markers.js`. `icons.css` enqueued as a separate WP stylesheet
  (dependent on skin.css) so its URL is absolute; the @font-face still
  emits inline with absolute font URLs so CSS optimisers can't 404 them.
- Files: `includes/class-assets.php`, `engine/features/markers.js`,
  `skins/innsight2026/pin.html`, `skins/innsight2026/skin.css`,
  `skins/innsight2026/assets/icons.css` (new, 199 lines).

## 0.7.24 - 2026-08-12

- **Icon-font @font-face now uses absolute URLs.** The relative
  `url('assets/fonts/MaterialIcons.woff2')` in `skin.css` breaks the
  moment any CSS-optimiser plugin (Autoptimize, WP Rocket, LiteSpeed
  Cache, Perfmatters, ...) moves `skin.css` into `/wp-content/cache/...`
  — the relative path then resolves under the cache directory and 404s,
  so the browser falls back to `sans-serif` and renders the ligature
  text ("restaurant", "hiking") as plain text in every pin. Fixed by
  emitting the `@font-face` inline via `wp_add_inline_style` in
  `class-assets.php` with absolute plugin URLs. The relative version
  stays in `skin.css` as a fallback for standalone / non-WP uses.
- **Glyph CSS hardened with `!important`** on font-family, size,
  line-height, letter-spacing, text-transform, and ligature settings
  so no theme override can shadow it. Added `font-variant-ligatures`
  explicitly. Unknown-category pins (no `--md` or `--map` modifier)
  hide their text via `font-size:0` so nothing leaks if the fontClass
  is blank.

## 0.7.23 - 2026-08-12

- **Base marker + walk rings actually render now** (the fix in 0.7.21
  was only half the fix). The 0.7.21 patch added `base` to the JS
  skin-loader's partKeys, but on WordPress the shortcode inlines
  partials via `window.INNSIGHT_SKIN_PARTIALS` and never fetches —
  so the inline path in `class-skin-partials.php` had to include
  `base` too, and it didn't. `SkinPartials::read()` now slurps
  `base.html` (also added to `$watched` so its filemtime busts the
  transient cache). Result: `partials.base` is non-empty →
  `pickBasePoi()` runs → base marker + walk rings both draw.
- **Pin icon glyphs bundled + rendered via ligatures.** Copied
  MaterialIcons.woff2/woff/ttf + map.ttf from the solike2025 skin
  into `skins/innsight2026/assets/fonts/`. Added `@font-face` for
  'Innsight Material Icons' and 'Innsight Map Icons' (namespaced
  so a host theme's own Material Icons stylesheet can't shadow
  ours). `pin.html` now renders
  `<i class="in-pin__glyph {{iconFontClass}}">{{iconLigature}}</i>`.
  `iconMetaForPoi()` in `engine/features/markers.js` splits
  `poi.category` into the font-family class + ligature text:
    `md-restaurant`     → `in-pin__glyph--md`  + `restaurant`
    `md-local-cafe`     → `in-pin__glyph--md`  + `local_cafe`
    `map-swimming`      → `in-pin__glyph--map` + `swimming`
    `map-natural-feature` → `in-pin__glyph--map` + `natural-feature`
    unknown / fa-*      → no glyph (letter tile carries the pin)
- Files: `includes/class-skin-partials.php`,
  `engine/features/markers.js`,
  `skins/innsight2026/pin.html`,
  `skins/innsight2026/skin.css`,
  `skins/innsight2026/assets/fonts/*` (new).

## 0.7.22 - 2026-08-12

- **Pin colour = filter chip colour.** The chip dots use each
  category's `color` from the emitted `categories` list, but pin
  sticker colour was hashed from the POI id — no relationship. A
  Food pin got a random palette pick regardless of the orange dot
  on the Food chip. Now `buildPinContext()` in
  `engine/features/markers.js` looks up the POI's `cat`/`type` in
  `config.categories` and uses that colour; falls back to the
  hashed palette only when the type isn't in the list. Sheet, list
  and Saved-row stickers also prefer the category colour (still fall
  back to the hash for unknown types).
- **Tighter top-chrome ↔ map spacing.** The chip row had 14px of
  bottom padding and the "spots nearby" pill sat 14px further down
  at `top: 222px`, so ~28px of dead cream sat between the filters
  and the pill. Cut chip padding to `10px 16px 6px`, moved
  `.in-nearby` to `top: 198px`, and moved `.in-mapctl` (zoom +
  fullscreen column) to `top: 208px` — same relative offset from
  the pill as before, just 24px higher overall.
- Files: `engine/features/markers.js`,
  `skins/innsight2026/skin.js`,
  `skins/innsight2026/skin.css`.

## 0.7.21 - 2026-08-12

- **Walk rings + base marker now actually render.** Root cause: the
  skin loader's `partKeys` array in `engine/core/skin-loader.js` never
  listed `base`, so `partials.base` was always `undefined` — which
  short-circuited `pickBasePoi()` (guarded by
  `partials.base ? pickBasePoi(...) : null`), which in turn skipped
  the ring draw. `skin.json` had `base: "base.html"` all along, but
  the loader silently dropped it. Now `base` is a first-class
  partial in both fetch and inline modes.
- **Pin category icons use Material Icons + Map Icons fonts** instead
  of the inline-SVG library. The host page already loads Materialize,
  and every POI in the yuna schema stores its category as a font
  class (`md-restaurant`, `md-hiking`, `map-swimming`, `map-bakery`,
  etc.). `pin.html` now renders `<i class="{{iconClass}}">`; the
  engine's existing `resolveIconClass()` picks `md-*` / `map-*` /
  `fa-<type>` per the legacy rule. Inline SVG library stays as a
  fallback for skins that opted into `{{{iconSvg}}}`.
- Files touched: `engine/core/skin-loader.js`,
  `engine/features/markers.js`,
  `skins/innsight2026/pin.html`,
  `skins/innsight2026/skin.css`.

## 0.7.20 - 2026-05-14

- **Base latitude / longitude fields in Settings → Design**. Direct
  admin escape hatch: type the coords for your base (e.g.
  `46.6822` / `7.8585` for Balmers) and the marker + walk rings
  anchor there regardless of what's in the POI list. Overrides the
  pinned/hostel/map-center resolution chain. Empty falls through to
  the previous priority order.
- `pickBasePoi()` priority chain now:
  1. `branding.base.lat/lon` (Settings) ← NEW top priority
  2. First POI with `pinned:true`
  3. First POI with `type/cat === 'hostel'`
  4. `map.center` fallback when Base config exists
  5. Null (no base)

## 0.7.19 - 2026-05-14

- **Distinct hike / activities / land icons** (were nearly identical
  triangle silhouettes). hike = hiker + sun · activities = compass
  with directional arrow · land = hills + sun.
- **Base marker + walk rings fall back to `map.center`** when no POI
  qualifies as the base. Previously `pickBasePoi()` returned null
  unless a POI had `pinned:true` or `type/cat === 'hostel'`, so a
  site with a base photo in Settings but no matching POI got no
  marker + no rings (log said `base POI: none`). Now: if
  `branding.base` has any photo/label/rings AND `map.center` is
  valid, a synthetic base POI at the map centre is used as the
  anchor. The base marker renders + walk rings draw around it.
- Icon library gained inline comments grouping entries by purpose
  (Eating / Movement / Getting-around / Shopping / Stay / Info /
  Time-bound) so future edits are easier to navigate.
- File to edit: `engine/features/markers.js` → `TYPE_SVGS`.

## 0.7.18 - 2026-05-14

- **Hike, activities, and land now have visually distinct icons**.
  Previously activities was the same triangle as hike (just missing
  the sun dot), and land was a very similar mountain silhouette. Now:
  - **hike**: hiker figure with a small sun (upper right).
  - **activities**: compass with a directional arrow.
  - **land**: hills with a small sun.
- Cosmetic: added inline comments in `TYPE_SVGS` grouping icons by
  purpose (Eating, Movement, Getting-around, Shopping, Stay, Info,
  Time-bound) so future edits are easier to navigate.
- **Where to edit**: `engine/features/markers.js`, the `TYPE_SVGS`
  object. Key = POI type slug (`hike`, `food`, `bar`, …), value =
  raw 14x14 inline SVG stroked with `currentColor`. Add new entries
  for any new POI type.

## 0.7.17 - 2026-05-14

- **Default zoom level is now a Setting**. Settings → Innsight →
  Render mode & UI → "Default zoom level" (int 1-20, default 12).
  Used as the fallback when neither the `[innsight_map zoom="X"]`
  shortcode attribute nor a per-post `map_zoom_level` ACF value is
  set. DataSource's hardcoded `return 12` replaced with
  `innsight_settings('default_zoom', 12)`.

## 0.7.16 - 2026-05-14

Three fixes on the map layer:

### Two-slot luggage tag pin with category icon

Pin rewritten as a horizontal tag divided into two slots:
- **Left**: colored slot with the POI's initial letter, striped
  diagonal pattern in white at 22% opacity for the artisan feel.
- **Right**: white slot with an inline-SVG category glyph tinted
  to `--in-ink`.

`markers.js` gained a tiny inline-SVG icon library (fresh clean
monoline glyphs — no old fonts, no external dependencies) keyed on
the normalised POI type:
- food/eats → fork + knife
- bar/drinks → cocktail glass
- hike/activities → mountain triangle
- transport → bus
- shop/shops → shopping bag
- hostel → house
- place → pin
- city → skyline
- sights → eye
- land → hills
- public → building
- event/events → calendar
- Fallback: neutral dot

Rendered via `{{{iconSvg}}}` (raw) in the pin template.

### Base photo more robust

`JsonBuilder::build_base_config` used to require BOTH the `medium`
AND `thumbnail` sizes to resolve, or it dropped the whole photo key.
For attachments smaller than 300 px OR sites that never registered
those intermediate sizes, this yielded no photo even though the
admin had uploaded one — the base marker fell back to the striped
placeholder. Now: try medium → fall back to full; try thumbnail
→ fall back to medium/full. Any resolvable URL means the photo
renders.

### Walk rings visible for real

Bumped from subtle to prominent:
- Fill: `14% accent` → `32% accent`
- Outer stroke: `2px @ 32% opacity dashed` → **`3.5px @ 85% solid`**
  (reads as a real boundary line)
- Inner stroke: `2px @ 45% dashed` → **`3px @ 100% dashed`**
  (crisp 4-3 dash pattern)

Plus `console.info('[innsight] base POI: … | branding.base: … | has photo: true/false')` on every build so it's obvious when the config is missing the photo you uploaded.

## 0.7.15 - 2026-05-14

- **Luggage-tag pin flipped**: tag now sits on TOP; string dangles
  DOWN and ends in the reinforced eyelet, which lands exactly on
  the coordinate (Mapbox anchor is bottom, so bottom of wrapper =
  lat/lon). Previously the eyelet was at the top of the wrapper,
  floating uselessly above.
- **Walk rings visibility**:
  - `MIN_ZOOM` lowered from 13 → 11 so visitors landing at the
    default city-scale zoom already see the circles.
  - Line strokes bumped from `2px @ 32% opacity` → `3px @ 55%`
    (outer) and `70%` (inner) so the dashed rings actually read
    against the cream map.
  - Inner-ring fill bumped from `14% accent` → `24% accent`.
  - Chip visibility sync uses the new `MIN_ZOOM`.
  - `console.info` on first render logs the base lat/lon + minutes
    array so you can verify the call fired.

## 0.7.14 - 2026-05-14

- **Install banner removed** from LegacyMisc. Was appearing even
  inside the installed PWA on some display modes (my
  `matchMedia('(display-mode: standalone)')` + `navigator.standalone`
  detection didn't cover every browser/OS combo). Nag popups fighting
  the tab bar + BOOK NOW button are a net negative anyway. Native
  browser install prompts (Chrome menu → Install app, iOS Share →
  Add to Home Screen) do the same job cleanly.
- **Sheet quick-info icon visibility bumped** — 35% opacity was too
  faint to read on the cream sheet. Now 60% opacity + stroke-width
  1.5 (was 1.2) so glyphs are legible without shouting. Same
  outline-only, muted-grey palette; just readable now.

## 0.7.13 - 2026-05-14

Map pin redesign for innsight2026 skin: luggage-tag POI pins + taped-photo
base marker + walk rings.

### POI pins - luggage tag

`pin.html` + `.in-pin` block rewritten:
- Reinforced eyelet (`.in-pin__hole`) + short string (`.in-pin__string`)
  at the top - dangles from the anchor point.
- Coloured slot (`.in-pin__slot`) with the POI's `imageThumb` washed to
  the sticker colour + initial overlay. Rotated -4°.

### Base marker (hostel) - taped photo print

New `base.html` renders instead of `pin.html` for the resolved base POI:
`.in-base__dot` (accent chip on the coordinate) + `.in-base__print`
(white photo frame, rotated -6°, drop shadow) + `.in-base__tape` (accent
tape at -24°) + `.in-base__frame` (striped-cardboard placeholder that
shows when no photo is uploaded; hidden by the `<img>` when present) +
`.in-base__caption` with `label` + `HOME` kicker.

### Engine changes

- `markers.js` now picks `partials.base` for the POI that
  `findHostelRef` would resolve (`pinned:true` wins, else first
  `type/cat === 'hostel'`) and renders that partial instead of
  `pin.html`. Base marker is non-interactive: no `onClick`, no
  `onPopupOpen`, `pointer-events: none` on the marker DOM.
- `mapbox-gl-provider.addMarker` gained a `pointer-events: none`
  fallback for spec objects without an `onClick` (base marker).
- `mapbox-gl-provider.addWalkRings(lat, lon, minutes)` renders a
  GeoJSON source with two circle polygons at 80 m/min. Two line
  layers (outer/inner) with dashed strokes + a subtle accent-tinted
  fill on the innermost. Minute-label chips (`.in-base__ring` DOM
  markers) placed at bearing 135°. Everything auto-hidden below
  zoom 13 (both the layers and the chips sync on `zoom` events).

### Settings

New Design fields:
- **Base photo** (WP Media Library picker; stores attachment ID).
  Resolves at build time to two URLs (medium + thumbnail); a
  deleted attachment yields no photo key (never a broken URL). New
  `render_attachment` helper enqueues `wp_media` and wires
  Choose/Change/Clear.
- **Base label** (text, ≤ 40 chars). Falls back to wordmark prefix
  then POI title.
- **Walk-time rings (min)** (comma-separated ints; empty = no rings).
  Sanitiser caps each at 120, dedupes, keeps at most 4.

### JSON contract

`config.branding.base = { photo, photoThumb, alt, label, rings[] }`
added by JsonBuilder. Base key only appears when the attachment
resolves + at least one setting is present.

### Nothing else moved

`:root` tokens (skin.css lines 9-34) untouched. `layout.html`,
`sheet.html`, `list-row.html`, `empty-state.html`, `mapbox-style.json`,
`skin.js` unchanged. Diff-check clean.

## 0.7.12 - 2026-05-14

Full standalone recovery. Audit of yuna-innsight.php surfaced four
more things I hadn't ported. All added.

### `page-map.php` template

Legacy plugin registered a "Map Page" template. The Balmers map page
had `_wp_page_template` meta pointing at
`/full/path/to/wp-content/plugins/yuna-innsight/page-map.php`. When
that file disappeared, WP fell back to the theme's default template
which doesn't include the map shortcode → visitors saw an empty
page (this is why the PWA looked broken).

- **New `templates/page-map.php`** — same shape as yuna's: minimal
  <html>/<head>/<body> with `body.app-loading` + `<div class="loader">`
  so the legacy loader CSS still fires; renders the page's own
  content (falls back to `[innsight_map height="100dvh"]` when
  empty); calls `get_footer()` so theme footer scripts run.
- **New `LegacyTemplate` service** that:
  - Registers our template in `theme_page_templates` under both
    the new slug (`templates/page-map.php`) AND the legacy slug
    (`yuna-innsight/page-map.php`).
  - Intercepts `template_include` (priority 99) so pages whose
    stored meta is EITHER slug load our file. No manual admin
    action needed — the map page just starts rendering again.
  - Exposes a one-click migrator (admin-post
    `innsight_migrate_map_template`) that re-points every
    `_wp_page_template` meta ending in `yuna-innsight/page-map.php`
    to our new slug. Optional cleanup.

### Main More Info URL field on POI term edit

Legacy plugin added a "Main More Info Button URL" select on each
POI term edit screen, storing `main_more_info_url` term meta.
`LegacyMisc::render_main_more_info_field()` puts it back with the
same field name so existing meta keeps resolving — populated from
posts tagged with the POI, with a "— none —" option that falls back
to the Featured-in popover.

### Portfolio → Activities labels

Cosmetic-only override the legacy plugin applied to the `portfolio`
custom post type via `register_post_type_args`. `LegacyMisc::rename_portfolio_labels()`
mirrors it (name, singular, add-new, edit-item, all-items, menu-name)
so the admin sees the familiar "Activities" menu after yuna is
gone.

### PWA install banner in footer

Legacy plugin printed an "Add to Home Screen" install prompt in
`wp_footer` with `beforeinstallprompt` handling for Chrome/Android
and a manual iOS instruction card. Ported into `LegacyMisc::render_install_banner()`
in a simplified but functional form: only appears on pages using
the map template, dismissible, remembered in localStorage.

### Bundled extra icons

Copied the remaining legacy icons the yuna manifest/CSS referenced:
- `balmers-hostel-map-interlaken-switzerland-192x192.png`
- `balmers-hostel-map-interlaken-switzerland-512x512.png`
- `google-maps-balmers.png`
- `apple-maps-balmers.png`
- `maps-me-balmers.png`

Now if any cached HTML/CSS anywhere still points at
`/wp-content/plugins/yuna-innsight/img/<any-of-these>`, the shim in
0.7.11 serves the correct bundled PNG.

## 0.7.11 - 2026-05-14

- **Legacy yuna-innsight PWA URL shim** so installed PWAs on
  visitors' phones don't break when the old plugin folder is
  deleted. Hooked on `plugins_loaded` priority 0 (fires super-early),
  fires only when `WP_PLUGIN_DIR/yuna-innsight/` no longer exists.
  Serves:
  - `/wp-content/plugins/yuna-innsight/manifest.json`
    → our new dynamic manifest (same JSON body as
    `/innsight-manifest.webmanifest`).
  - `/wp-content/plugins/yuna-innsight/js/sw.js`
    → our new service-worker body (with
    `Service-Worker-Allowed: /` header).
  - `/wp-content/plugins/yuna-innsight/img/<file>`
    → the bundled equivalent in `assets/pwa/img/` (falls back to
    icon-192.png when the filename doesn't match).
  Browsers re-poll SW URLs every ~24h (or on every navigation), so
  installed PWAs will auto-swap to the new SW body without any user
  action - no reinstall needed.

## 0.7.10 - 2026-05-14

PWA support - the missing piece from the standalone-ready promise.

### What was missing

yuna-innsight owned the entire PWA stack: `manifest.json`, service
worker, `<link rel="manifest">` in the head, apple-touch-icon +
splash-screen tags, icons. Deleting the old plugin blanked out
every installed PWA on visitors' phones.

### New Pwa service

- **Rewrite URLs**: `/innsight-manifest.webmanifest` and
  `/innsight-sw.js`. Both are dynamic PHP handlers so we can send
  the right response headers (esp. `Service-Worker-Allowed: /` so
  the SW claims scope over the whole site, not just the
  plugin-assets subpath).
- **Manifest** built from admin settings: name, short_name,
  description, start_url, scope, theme_color, background_color,
  four icon URLs (192/512 + 192/512 maskable). Defaults use the
  bundled icons in `assets/pwa/img/` and fall back to `bloginfo`
  values when the admin leaves things empty.
- **Service worker** (`assets/pwa/sw.js`) with two strategies:
  network-first for HTML (fresh content wins online, cached wins
  offline), cache-first for static assets. Cache versioned so a
  plugin update evicts stale entries on the next `activate`.
- **`<head>` tags** on every page: manifest link, theme-color,
  mobile-web-app-capable, apple-mobile-web-app-capable + status-bar
  style, apple-touch-icon (bundled defaults for 152/167/180 sizes),
  seven apple-touch-startup-image splash screens for common iOS
  viewports.
- **Inline SW registrar** in the footer, scoped `/`, silent on
  failure so a locked-down host doesn't break anything else.

### Bundled icons

Copied from the yuna-innsight `img/` folder into
`assets/pwa/img/`: icon-192 / icon-512 (+ maskable variants),
apple-touch-icon, hostel-icon iOS sizes, seven apple_splash sizes,
plus screenshot-desktop / screenshot-mobile / screenshot-mobile-wide
for the manifest's screenshots field.

### Settings

New "PWA (installable web app)" section on Settings → Innsight with
13 fields: enable toggle, name, short_name, description, start_url,
scope, theme + bg colors, five icon URLs. Sensible defaults so
zero-config installs just work.

### Migration note for the current site

Existing installed PWAs on visitors' phones still point to
`/wp-content/plugins/yuna-innsight/manifest.json`. Two options:

1. **Keep yuna-innsight files on disk** (deactivate is enough - the
   static files still serve). Installed PWAs continue working.
2. **Delete yuna-innsight entirely**. Installed PWAs 404 and need
   reinstall from the new manifest URL:
   `https://www.balmers.com/innsight-manifest.webmanifest`
   (add-to-home-screen from the map page after upgrade).

Activation hook flushes rewrite rules so the two pretty URLs
resolve immediately, no manual permalink save needed.

## 0.7.9 - 2026-05-14

Standalone-ready. Once you delete/deactivate the legacy `yuna-innsight`
plugin, everything the admin editing UI needs stays wired up.

### What was already covered

- `point_of_interest` taxonomy re-registered by `LegacyCompat` at
  init:11 when the legacy plugin isn't there.
- `[custom_map]` shortcode registered as an alias next to
  `[innsight_map]`, so existing embedded shortcodes keep working.
- Site defaults (maps_titre / maps_latitude / maps_longitude /
  maps_text / maps_bg_img / maps_more_info_url) editable via
  Innsight → Map defaults.
- `innsight_get_field()` reads through ACF when present, falls back
  to raw options/postmeta/termmeta when not.
- Stale yuna service-worker auto-unregistered.

### What 0.7.9 adds

- **New `LegacyAcf` service** registers the three ACF field groups
  yuna-innsight used to own, verbatim (same keys so all existing meta
  keeps resolving):
  - **POI Fields** on `point_of_interest` terms: poi_latitude,
    poi_longitude, distance_from_hostel_km, poi_type (select), 
    poi_category (icon slug), poi_image, poi_url_link.
  - **GENERAL FIELDS** on the `yuna_innsight` options page:
    maps_bg_img, maps_titre, maps_text, maps_more_info_url,
    maps_latitude, maps_longitude.
  - **Maps Shortcode Fields** on `acts`/`event`/`post`/`page` (auto-
    detected): map_to_post, map_base_location, map_zoom_level,
    map_quick_name, maps_add_markers, maps_add_paths,
    maps_existing_act_marker_id (repeater), maps_paths_box (repeater
    with color + coord list).
- **`yuna_innsight` options page re-registered as an Innsight
  submenu** ("Site defaults") when ACF Pro is available so the
  GENERAL FIELDS group has somewhere to render.
- **Sentinel check**: skips both registrations when
  `add_custom_map_shortcode()` still exists (= legacy plugin still
  active), so no double-registration during a transitional install.

### After you delete yuna-innsight

1. Deactivate + delete `yuna-innsight` in Plugins.
2. Reload any POI term edit page → the POI Fields metabox stays.
3. Reload any post that used `[custom_map]` → the Maps Shortcode
   Fields box stays.
4. Innsight → Site defaults renders the GENERAL FIELDS options page.
5. Every stored value survives because we reuse the same field keys.

## 0.7.8 - 2026-05-14

- **`&amp;` in POI titles fixed.** WP's `get_the_title()` returns
  titles with HTML entities already encoded ("Hotel &amp; Spa").
  Our client template then escaped again on output, so visitors
  saw the literal `&amp;`. `JsonBuilder::shape_poi()` now runs
  `html_entity_decode(ENT_QUOTES | ENT_HTML5)` on title + name once
  server-side; the template escape runs on plain text and produces
  clean `&`.
- **Secondary row now inline** — price, phone, and address/directions
  sit on ONE horizontal line as chip-like items. The old
  full-width dashed "Get directions" block is gone. Address text
  truncates with ellipsis (max 260px) so long street addresses
  don't force a wrap.
- **Address chip = "📍 <address>"** clicking anywhere on it opens
  Google Maps directions to the POI's lat/lon. Address text shows
  when Places returned one, otherwise fallback label "Get directions"
  (same click behaviour).

## 0.7.7 - 2026-05-14

- **All Google-strip icons are outline-only, muted grey now** (no
  amber star, no filled shapes). `.in-sheet__gicon` forces
  `fill: none !important; color: rgba(15,15,15,0.35) !important;
  stroke-width: 1.2` on every icon in the strip and the reviews
  panel. Star icon rewritten as an outline path. The 0.7.6 chunky
  amber-star look is gone.

## 0.7.6 - 2026-05-14

Google info strip redesign + backend additions.

### Backend

- **Price** (`priceLevel` symbol `$` / `$$` / `$$$` / `$$$$` + optional
  localized `priceRange` text like `€10-20`) added to the Places fetch
  + cached row.
- **Formatted address** (prefers `shortFormattedAddress`) fetched and
  stored alongside the POI.
- **`utcOffsetMinutes`** fetched from Google. `openNow` is now
  **recomputed on every build** from the venue's UTC offset + the
  current UTC time + the `periods` array - so a cached row fetched
  hours ago still shows the correct "Open now / Closed" state, and
  cross-timezone POIs display accurately.
- **`internationalPhoneNumber`** fetched so we can build a proper
  `tel:` URI while still displaying the local-format number.

### Sheet

- **Rating chip is subtle now**: no accent bg, no border - just bold
  text with an amber star. Clicking it expands the top 3 reviews
  inline (author + rating + text truncated) with a **"See all
  reviews on Google →"** link.
- **Icons on every quick-info row**: star (rating), clock (hours),
  currency (price), phone (phone), pin (address). Subtle grey SVGs
  so they read as glyphs, not decoration.
- **Price row** shows below the rating/hours when Google returned
  price info.
- **Phone row** with `tel:` link; hover underlines with the accent.
- **Address row** with the formatted address as the visible label
  and a trailing "Directions →" chip - the whole row links to
  Google Maps directions. Replaces the previous "Directions" link
  with the misread arrow icon.
- **Full-week schedule dropdown** rebuilt: sectioned card, "Full
  week" eyebrow label, right-aligned times with dashed dividers.
  No longer the plain bulleted list.

## 0.7.5 - 2026-05-14

Two real fixes chased down from your Eiger POI screenshot.

### Template engine nested-else bug

The screenshot showed raw `{{#if ratingLabel}}` on the sheet even
though the Places data was clearly cached. Root cause: my
`{{#if}}{{else}}{{/if}}` matcher was a plain regex that grabbed the
FIRST `{{else}}` in the body - including ones belonging to nested
child blocks. When the outer block had no else of its own but
contained a nested `{{#if}}{{else}}{{/if}}`, the parser split at the
nested else and left the outer if-branch truncated. Result:
half-parsed template + raw `{{tokens}}` on screen.

New `splitOnElse()` walks the body depth-aware (tracking
`#if/#unless/#each` opens and closes) and only returns an else that
belongs to THIS block's own scope. The Eiger sheet should now render
the accent rating pill + hours + directions properly.

### Save + More info full-width lock

Regression from 0.7.4: because the template misparse (above) left
the `.in-sheet__gstrip` div unclosed, the actions row ended up
nested inside a flex-wrap container and the buttons collapsed to
content width. Belt-and-braces CSS: `.in-sheet__actions` now has
`display: flex !important; width: 100%` and `.in-sheet__act` has
`flex: 1 1 0 !important`, so even if a future template bug or a
theme override tries to squeeze them, the buttons stay full-width.

### POI edit page Places panel

New "Innsight - Google Places data" metabox on both the POI term
edit screen (`term.php?taxonomy=point_of_interest&tag_ID=X`) AND the
POI post edit screen (if POIs are posts). Shows:
- Cached poi_id + Google place id
- Fetched-at timestamp (with human-readable "N days ago")
- Rating + review count, today's hours, full weekday schedule
- Phone, photo thumbnail
- Last error message if any
- **"Refresh this POI now"** button - one click, per-POI, no need
  to leave the edit screen or find the poi_id in a debug page.

Empty-state also lists the poi_id variants we searched (raw numeric,
`term-N`, `poi-N`) so the mismatch is visible when it happens.

## 0.7.4 - 2026-05-14

Three fixes chased down from your DevTools session.

### Analytics beacon fix - stats were silently dropped

`navigator.sendBeacon()` with an `application/json` Blob triggers a
CORS preflight, but `sendBeacon` can't perform preflights - so the
request silently drops. Every beacon since 0.6.0 was going nowhere.
Beacon now sends `URLSearchParams` (form-encoded, "simple request",
no preflight) which WP REST parses natively. Analytics widgets +
Analytics page should start climbing on the next page load.

### Always-visible Google info strip with pending pills

The strip used to only render fields that had data. It now ALWAYS
renders when Places is configured for the site (`ui.placesUrl`
non-empty), with per-item placeholder pills that pulse + blur until
the real data lands:
- Rating pill shows `★ —` blurred until Places returns.
- Hours span shows "Fetching today's hours…" blurred until Places
  returns.
- Directions link is always accurate (built from POI lat/lon).

### Raw-table debuggers

Added table-level diagnostics that bypass every aggregate reader:

- **Places debug page**: total row count, rows with data, rows with
  error, fresh count via a direct COUNT query, last wpdb error, last
  10 rows (poi_id, place_id, fetched_at, byte length, error), plus a
  **POI id comparison** table (DataSource ids vs cached ids). If the
  ids don't overlap, the "Refresh reports 25 succeeded but status
  shows 0" bug is the ID mismatch - now visible instead of hidden.
- **Analytics page "Raw table debugger"** (collapsible): row count,
  total events summed, last wpdb error, last 20 rows. If the table
  has data but the widgets show 0, we can see it here.

## 0.7.3 - 2026-05-14

- **New standalone "Places debug" submenu** (Innsight → Places debug).
  No status card, no transients, no redirect dance. Shows:
  - Current config (enrichment on/off, API key present + masked +
    length, cron on/off, REST endpoint URL).
  - **The exact curl command** to run from the server terminal to
    bypass WordPress entirely.
  - An in-form text-query test that runs `wp_remote_post` against
    Google Places and prints the RAW response - HTTP code,
    headers, pretty-printed JSON body, elapsed ms.
  - Per-POI refresh test: enter a POI id, see the DataSource
    input, the refresh() return status, and the full cached row
    (data or error).

## 0.7.2 - 2026-05-14

Places debugger. The refresh button used to lie ("25 done") even when
every single Google request failed - because it counted attempts, not
successes. Now it tells the truth AND shows you why:

- **`refresh_batch()` returns a report** `{attempted, succeeded,
  failed, no_match}` so the post-refresh notice reads "attempted 25,
  succeeded 3, no-match 20, failed 2" - immediately obvious when the
  API is rejecting requests.
- **Colour-coded count tiles** on the status card: Total / Fresh /
  Stale / Never fetched / Errored - all visible at a glance instead
  of buried in a sentence.
- **Recent activity table** (last 20 rows) with POI id, when fetched,
  OK / No match / ERROR status, and the raw error message from
  Google. Auto-expands when there are errors. This is the
  "debugger" you needed.
- **"Test API key" button** — pings the Places API with a known
  query (Eiffel Tower) and reports the outcome inline. If your key
  is wrong / billing isn't set up / referrer restrictions are
  blocking, this shows the exact HTTP error before you touch a
  single POI.
- **Red callout when Places is off** — clearer than nothing at all
  when the admin hasn't enabled + keyed the integration yet.

## 0.7.1 - 2026-05-14

- **Google Places status card** on the Settings page: progress bar +
  "X of Y POIs cached with fresh data (< 30 days). N stale, N
  errored" + last-fetch timestamp + next-cron ETA.
- **"Refresh next 25 POIs now"** button — synchronously refreshes a
  batch of stale/missing POIs so admins don't have to wait for the
  nightly cron or for visitors to lazy-load them. Safe to click
  multiple times to walk through a large catalogue.
- The card is hidden when Places enrichment is disabled or no API
  key is configured (no clutter for sites that don't use it).

## 0.7.0 - 2026-05-14

Google Places moved server-side + Google info strip on every POI sheet.

### Server-side Places

- **New `Places` service + `innsight_places` custom table** (`poi_id`,
  `place_id`, `data JSON`, `fetched_at`, `error`). API key stays on
  the server — never touches a visitor's browser.
- **`/wp-json/innsight/v1/places`** returns cached data with
  stale-while-revalidate flags: fresh cache → instant; stale (>30d)
  cache → return old data + kick off async refresh (single-shot
  `wp_cron` event, runs seconds later); missing cache → return
  `data:null, refreshing:true` + kick off refresh.
- **Client polls once at +5 s** when the server says `refreshing:true`.
  If the second poll's `fetchedAt` is newer, the skin merges the fresh
  data and toasts "Info updated" so the visitor understands why the
  hours / rating just changed.
- **30-day TTL.** Old cache still shown while the fresh one is being
  fetched — no blank sheet.

### Nightly cron (opt-in)

- New setting **"Nightly Places cron"** (default off). When enabled,
  a daily 03:00 site-time event iterates POIs and refreshes up to 25
  stale/missing rows per night (bounded batch, 200 ms politeness
  pause). Most sheet opens hit a hot cache; per-visit Google requests
  drop to near zero.

### Google info strip in the sheet

Sits directly under the POI title, above the description. Compact and
subtle, one row when possible:

- **Rating pill** (accent bg) linking to the Google reviews page.
- **Today's hours** with open/closed status badge and a **"see all"**
  toggle that expands a 7-day weekday schedule inline.
- **Directions link** underneath — always present (built from POI
  lat/lon even without Places enrichment), opens Google Maps
  directions to the POI's coordinates.

Loading state: when the strip is present but data hasn't landed yet,
CSS **blurs + pulses** the whole strip so the visitor sees "something
is loading here". When there's no cached data at all, a skeleton
shimmer variant shows the shape before data lands.

### Cleanup

- Removed the redundant "View on Google Maps →" link at the bottom
  of the sheet (Directions in the new strip replaces it).
- Removed the meta-row open badge + vibe rating chip (now consolidated
  in the Google strip).
- Old client-side `engine/enrichment/google-places.js` rewritten as a
  thin REST client (~90 lines, no more Places API calls from the
  browser).

## 0.6.0 - 2026-05-14

Analytics module. Privacy-friendly aggregate usage counts + a
Dashboard widget + a full Analytics page under Innsight menu.

### Data model

- **New custom table `{prefix}innsight_stats`** installed by dbDelta on
  activation. Schema `(event_key, poi_id, day, count)` with unique key
  `(event_key, poi_id, day)`. Every event is an atomic
  `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1` so writes are
  O(1) under any traffic load.
- **No visitor identity, no IP stored, no cookies.** Just anonymous
  day-bucketed aggregate counts. Nothing that would trigger GDPR
  analysis for the average tourism-map deployment.

### Events tracked

- `map_load` - sheet controller booted (one per page load).
- `poi_open` - a POI sheet was opened.
- `poi_save` / `poi_unsave` - visitor's local saved list changed.
- `share_send` - Share Wishlist channel dispatched (channel stashed
  as `ch:whatsapp`, `ch:email`, etc for a channel breakdown).
- `share_received` - visitor landed via an #innsight_share URL.

### Frontend cost

- Single `navigator.sendBeacon()` call per event with fetch keepalive
  fallback. Fire-and-forget; never blocks the pointer handler that
  queued it. Wrapped in try/catch so a beacon failure can never
  affect visitor UX.
- Skin skips the beacon entirely when `cfg.ui.analyticsUrl` is empty
  (set to empty by JsonBuilder when the admin flips off the
  "Collect anonymous usage stats" setting).

### REST endpoint

- `POST /wp-json/innsight/v1/stat` accepts `{event, poi_id?}`. Public
  (no auth) but per-IP throttled at 60 events/minute via a hashed
  transient key so runaway loops can't flood the table.
- Sites needing auth can hook `innsight/rest/stat_permission`.

### Admin surfaces

- **wp-admin Dashboard widget** "Innsight - map activity": three
  metric tiles (loads today + this week, saves today + this week,
  shares this week) + top 5 most-saved POIs with edit links + a
  link to the full Analytics page.
- **Analytics submenu page** (Innsight → Analytics): six event-total
  tiles (last 30 days + today for each), an inline SVG polyline
  chart of daily map loads for the last 30 days (no JS chart lib,
  ~1KB of markup), full 50-row sortable table of most-saved POIs
  for the last 90 days (with net = saves - unsaves), and a shares-
  by-channel breakdown.

### Settings

- New **Analytics** section in Settings → Innsight with a single
  "Collect anonymous usage stats" checkbox (default on). Off = skin
  skips all beacon calls; nothing about analytics touches the
  visitor's session.

## 0.5.13 - 2026-05-14

- **Modal z-index stack reordered above the tab bar.** When the tab
  bar was raised to z-index 9999 in 0.5.5 (to defeat theme overlays
  like the Balmers "BOOK NOW" sticky button), it started covering
  the bottom sheet's actions row + the notes editor. Modals now
  layer cleanly above the tab bar:
  - sheet backdrop: 10000
  - sheet: 10001
  - notes panel: 10005
  - share backdrop / menu: 10010 / 10011
  - receive popup: 10020

## 0.5.12 - 2026-05-14

- **"More info" replaces "Directions"** as the sheet's fallback
  primary action when a POI has no custom `button.url`. Tap opens a
  scrollable "Featured in" popover showing every blog post / activity
  / event that references this POI via the legacy
  `maps_existing_act_marker_id` ACF repeater. Max 3 rows visible
  before scroll. Empty state: "No related pages yet."
- **DataSource builds a reverse-index** in one query (cached 5 min in
  a transient) so the per-POI lookup is O(1) regardless of how many
  POIs the page renders. Current page excluded from each list (visitor
  is already there).
- **Hover state on every primary button** (sheet primary action,
  Saved-tab Share, receive popup Save-them-all) now swaps to
  **accent bg + ink text** instead of the default dark bg with
  invisible text. Theme overrides like `a:hover { background: #fff }`
  can no longer wash out the white label.

## 0.5.11 - 2026-05-14

Slow-network race fix + theme-overlay z-index + visible boot errors.

### Root cause of "sometimes loads, sometimes inert"

The engine and skin load as separate `<script>` tags. The inline
bootstrap waited for `Innsight.init` to exist (= engine main script
loaded) but NOT for the skin to register on `Innsight._skins[name]`.
On slow networks skin.js (loaded async after the engine) was still
downloading when the bootstrap fired. The engine then:

1. Rendered the map.
2. Rendered the pins (from its own config copy).
3. Reached "call `_skins[name].setup()`".
4. Skin not yet registered → silently no-op'd.
5. skin.js finished loading 200ms later → registered itself, but
   nothing was waiting for it → setup never ran.

User sees pins, no chrome interactions, no errors. Refresh → if the
cache makes skin.js arrive faster, it works.

### Fix

- **Bootstrap polls for both** `Innsight.init` AND
  `Innsight._skins[chosenSkinName]` before calling `init()`. The poll
  has a 30-second ceiling (1000 × 30ms) with a console.error if it
  times out so a broken deploy is loud, not silent.
- **`Innsight._skins.innsight2026.setup` wrapped in try/catch.** Any
  thrown error now shows a red notice inside the map container and
  logs to `console.error` — a partially-rendered inert UI never
  happens silently again.
- **Tab bar z-index raised to 9999** (was 70) with explicit
  `pointer-events: auto`. The Balmers theme's sticky "BOOK NOW"
  button uses z-index 9999 and was covering the Saved tab in the
  screenshot you sent. Tab bar lives inside `.in-app` so this
  doesn't pollute the page stacking context.

## 0.5.10 - 2026-05-14

Sheet swipe stuck-card fix.

### Root cause

`bindSheetSwipe(inner)` was called on every `renderSheet` (which fires
on every POI navigation, every enrichment-applied refresh, every
filter change while a sheet is open). The `inner` DOM node is the
same fixed element across renders, so each call STACKED another set
of pointer listeners. After 5 renders, one swipe fired
`navigateSheet(±1)` 5 times in rapid succession; the visual
`translateX` from the last swipe got overwritten by render-races and
the card ended up stuck mid-screen with no way back.

### Fix

- **Bind once per controller lifetime.** `bindSheetSwipe` early-
  returns on its second call. Gesture state lives on
  `this._swipe` instead of in per-call closures.
- **Always reset visuals.** `endGesture()` clears `transform`,
  `opacity`, and `transition` on every gesture end - regardless of
  mode (horizontal / vertical / native), regardless of how it ended
  (pointerup / pointercancel / lostpointercapture). New
  `lostpointercapture` listener catches the cases where the OS or
  browser steals the pointer mid-drag.
- **Force-cancel mid-render.** `renderSheet` and `closeSheet` now
  call `_cancelSwipe()` before swapping content, so a fast double-
  swipe or a render triggered by enrichment landing during a drag
  can't leave the inner card stuck with leftover inline styles.
- **Snapshot before navigate.** `onUp` snapshots `mode/dx/dy` BEFORE
  calling `navigateSheet/closeSheet` (which triggers the cascading
  re-render). Without the snapshot, the re-render's `_cancelSwipe`
  would clear state before we read it, making the swipe a no-op.

## 0.5.9 - 2026-05-14

WhatsApp Desktop URL fix.

### Why share links broke

WhatsApp Desktop visually wraps long URLs onto multiple lines but
only the first line stays clickable - the recipient's tap takes them
to `…/#innsight_share/` with the base64 token chopped off. Mobile
WhatsApp handles this better, but the fix had to be the same: ship a
shorter URL.

### What changed

- **Share token slimmed by ~80%.** We now encode only what the
  recipient can't reconstruct: `[id, title, cat, lat, lon, note]` per
  POI as an array-of-arrays (no key names). Previously we shipped
  image URL, thumbnail URL, rating, tag, button URL + text - all of
  which the recipient's site already has cached locally for any POI
  with a matching id. For 5 POIs the URL drops from ~3 KB to ~500
  bytes.
- **Live POI overlay in preview mode.** When the recipient is on the
  same site as the sharer, `visiblePois` returns the live POI (with
  full image/rating/button data) and shallow-copies in the friend's
  note. So previewing on the same site looks visually identical to
  browsing your own saved list.
- **Backwards compatibility for old links.** `decodeSharedPicks`
  detects the old object format `{i,t,c,...}` and converts it - links
  shared from 0.5.7/0.5.8 keep working.
- **WhatsApp length guard.** If the encoded URL exceeds 1800
  characters (the WhatsApp Desktop wrap threshold), the WhatsApp tile
  toasts "Wishlist too long for WhatsApp - use Copy link or Email"
  instead of opening a doomed share. Other channels still work.

## 0.5.8 - 2026-05-14

Personal notes are now first-class: they ride along with shares and
surface everywhere a POI does.

### Notes carry with shares

- `encodeSharedPicks` includes the friend's note alongside each pick.
- `handleReceiveChoice('save')` writes notes into the recipient's
  `localStorage[innsight.notes]` keyed by POI id - so the friend's
  commentary stays with each spot after Save Them All.
- We DON'T overwrite a recipient's existing note for a POI - their own
  text wins over a friend's; we only fill in when blank.
- In "Just preview" mode, the synthesized POI carries `poi.note` so
  the friend's note still renders in the list rows + sheet during the
  preview session (no persistence).

### Notes visible in Saved + List rows

- New `.in-row__note` block on every list-row that has a note.
  Accent background, ink text, dashed-border-friendly.
- **Mobile** (< 768px): stacks on a new row below the meta line, aligned
  with the body content; clamped to 2 lines with ellipsis.
- **Desktop** (≥ 768px): floats to the right of the row body as a
  fixed-width column (240px), clamped to 3 lines.

### Notes visible in the sheet

- New `.in-sheet__note-preview` block sits **above** the Save / More
  info actions. Accent background, ink text, 3-line clamp with
  ellipsis. The entire block is a button - tap it to open the full
  notes editor for inline editing.

## 0.5.7 - 2026-05-14

- **Share links survive WhatsApp / iMessage / SMS auto-linking.**
  WhatsApp's URL detector truncates at `=` (and a few other special
  characters), so the recipient was clicking on a link that ended at
  `…/?innsight_share` with the token chopped off. The share URL now
  uses a hash fragment with `/` as the separator instead of a query
  parameter with `=`:

  Before: `https://site.com/page/?innsight_share=<base64token>`
  After:  `https://site.com/page/#innsight_share/<base64token>`

  Nothing after `#` is "special" to messenger URL detection and `/`
  reads as part of the path, so the full link survives. `consumeShareUrl`
  parses BOTH formats so any share links already in the wild keep
  working.

## 0.5.6 - 2026-05-14

- **Wordmark prefix (client name).** New Settings → Design field
  "Wordmark prefix (client name)". When set (e.g. `Balmers`), every
  `.in-wordmark` element (chrome header, list view, saved view) is
  rewritten to:

  `Balmers <span class="innsight-mode-header">→ Innsight</span><span class="in-accent-dot">.</span>`

  The `.innsight-mode-header` chip renders at half size with the
  accent background and the accent dot tucks under it (`margin-left:
  -13px`). Empty prefix leaves the original "Innsight." wordmark
  untouched. Prefix is escaped via `textContent` so admins can't
  inject HTML through the field; capped at 40 characters in
  sanitize.

## 0.5.5 - 2026-05-14

Theme-hardening + layout fix.

### Button text was invisible on dark buttons

Host themes routinely override `a, button { color }` site-wide, which
defeated our white-on-black design (Share button, Save Them All, sheet
primary action, notes Done, toast, active tab). Every dark-background
button now declares `background` + `color` + `border-color` with
`!important`, plus child `span` / `svg` inherit color explicitly.
Bulletproof against the most aggressive theme overrides.

Affected:
- `.in-saved__share` (Saved tab Share button)
- `.in-receive__btn--primary` ("Save them all" in the receive popup)
- `.in-receive__btn` (general receive popup buttons)
- `.in-sheet__act--primary` (sheet's primary action - "Book now",
  "More info", etc.)
- `.in-sheet__act` (sheet's secondary "Save" button)
- `.in-tab[aria-selected="true"]` (active tab pill)
- `.in-notes__close` (notes Done button)
- `.in-toast` (save/unsave toast)

### `.in-app` no longer forces 100dvh

`min-height: 100dvh` on `.in-app` was making the shortcode taller than
its container on pages where authors set `height="70vh"` (the default)
or where the theme template constrains the embed. The result: the tab
bar escaped the shortcode footprint and overflowed into surrounding
theme content (visible in the "different experience you might like"
text bleed-through). `.in-app` now uses `height: 100%` (= parent's
`height` attribute) with a `min-height: 360px` safety floor. Authors
who want a full-viewport map should pass `height="100dvh"` in the
shortcode.

### Tab bar visibility behaviour, documented

The tab bar lives inside `.in-app` and scrolls with it - this is
correct "embedded shortcode UI" behaviour. On pages where the
shortcode is shorter than the viewport, the tab bar is at the bottom
of the visible shortcode footprint and disappears when the user
scrolls past the shortcode. To keep the tab bar always visible, set
`height="100dvh"` in the shortcode so the map covers the full
viewport.

## 0.5.4 - 2026-05-14

- **Personal-note peek strip is now save-gated.** Unsaved POIs no
  longer show the "Add a personal note" peek at the bottom of the
  sheet - it was visually noisy for casual browsing. The strip only
  appears after the user taps Save (and disappears immediately on
  Unsave). Implemented via a `.is-poi-saved` class on `.in-app` that
  the JS toggles on every `openSheet` + `toggleSavedPoi` call; CSS
  hides the peek by default and reveals it only when the class is
  on. No sheet re-render needed when the user toggles save - the
  peek slides in/out from class change alone.

## 0.5.3 - 2026-05-14

Notes panel rewrite. Three reported bugs in one fix:

- **Panel was appearing without the user tapping anything** on desktop -
  the panel was eagerly created on every sheet open AND
  CSS-transform-only hidden, so any race in style application left it
  visible. **Fix**: lazy-create on first peek interaction; hidden via
  HTML `hidden` attribute + CSS `display:none` (belt-and-braces).
- **Panel escaped the shortcode container** and pinned itself to the
  page, scrolling visitors out of the map area. **Fix**: append the
  panel to `.in-app` (positioned ancestor with `overflow: hidden`)
  instead of `.innsight-map-target` (static positioning).
- **Done button silently failed to close** on certain Safari builds.
  **Fix**: bind both `click` and `pointerup`; tap-to-close on the head
  + Done button always reach the same singleton handler regardless of
  how many sheets have opened.

Other improvements:

- Drag-up threshold raised from 60 → 100px so a sheet body scroll
  starting over the peek can't accidentally open notes.
- Panel now fills the full `.in-app` footprint on every viewport
  size (removed the desktop "centered floating card" override that
  was overlapping the sheet hero in the screenshot you sent).
- Slide-up animation switched from CSS `transition: transform` to
  a `@keyframes` animation triggered on display change, so the
  first-open-per-session no longer skips the animation.
- Panel head ignores pointerdown when the touch starts on the Done
  button, preventing the drag-down handler from eating the tap.

## 0.5.2 - 2026-05-14

Critical cache-coherency fix. After upload, visitors (especially
non-logged-in ones, but logged-in too on hosts with object cache)
were getting a mix of old HTML and new JS, breaking dynamic features
like Save / tab clicks / count updates with no console error.

### Root cause

`SkinPartials` cached the inlined layout / sheet / pin partials in a
transient keyed only by file `mtime`s. When the plugin zip was
extracted with preserved timestamps (FTP, rsync -t, certain managed
hosts, Git checkouts) the cache key didn't change → server kept
serving the OLD partials transient → page HTML had old DOM hooks
while the freshly-fetched skin.js looked for new ones. Symptom: tab
buttons did nothing, Save was inert, no console error because every
`querySelector` for new hooks just returned `null` and the new code
no-op'd on missing elements.

### Fix

- **Partials cache key now includes `INNSIGHT_VERSION`** so every
  release force-invalidates regardless of file mtimes. Single line
  change with massive impact: stale partials can no longer survive
  a version bump.
- **New `CacheManager` service** that runs on `upgrader_process_complete`,
  on `activated_plugin`, and from a manual admin button. It:
  - Wipes our own `_transient_innsight_partials_*` and
    `_transient_innsight_geocode_*` rows from the options table.
  - Calls `wp_cache_flush_group('innsight')` for object caches.
  - Flushes every major WordPress page cache plugin we recognise:
    WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed, Hummingbird,
    SG Optimizer, Cache Enabler, WP Fastest Cache, Breeze, Autoptimize.
  - Hits managed-host edge caches: WP Engine (Varnish + memcached),
    Kinsta, Pantheon, NGINX Helper.
  - Emits `do_action('innsight/cache_purged')` so sites with custom
    Cloudflare / Varnish layers can hook the purge.
- **"Purge Innsight caches now" button** on the Settings page
  (yellow callout at the top) for the post-upload click-once fix
  when a host's auto-purge missed something.
- **Activation hook also calls purge_now()** so a manual re-zip /
  WP-CLI install path that doesn't fire `upgrader_process_complete`
  still gets a clean cache state.

### Why visitors saw a "mix of old and new"

Logged-in users normally bypass HTTP page caches but still read from
WordPress object cache (Redis / Memcached) where the partials
transient lives. Logged-out users hit BOTH the page cache AND the
transient cache. Before this release: bumping the plugin updated the
files on disk but neither cache layer noticed. After this release:
both layers get explicitly evicted on every upgrade.

## 0.5.1 - 2026-05-14

- **Desktop sheet sizing.** On viewports ≥ 900px the bottom sheet,
  notes panel, and share menu now constrain to ~56% width (max 720px)
  centered horizontally, with a taller hero (240px) and bigger
  display title. On ≥ 1280px we tighten further to 50% / 760px max.
  Fixes the edge-to-edge hero-image bloat reported on desktop.

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
