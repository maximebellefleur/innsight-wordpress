/* Innsight - converts normalized POIs into markers, groups them by type with clustering,
 * binds popups, and exposes the per-type cluster groups so filtering / layer-control can drive them.
 *
 * The icon-class resolution preserves the existing yuna-innsight rule:
 *   if poi.category contains "md-" or "map-"  ->  use category as the icon class
 *   else                                       ->  use "fa-" + poi.type
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function resolveIconClass(poi) {
        if (poi.category && (poi.category.indexOf('md-') !== -1 || poi.category.indexOf('map-') !== -1)) {
            return poi.category;
        }
        if (poi.icon) return poi.icon;
        return 'fa-' + (poi.type || 'place');
    }

    /**
     * Tiny inline-SVG icon library for the pin's right-hand slot.
     * Keyed on the normalised POI type (or category alias). Simple
     * monoline glyphs in currentColor so CSS can tint them uniformly
     * with the ink colour. Falls back to a dot when the type is
     * unknown. Deliberately small (14x14) - the icon slot is only
     * ~22px wide.
     */
    // Icon library. Every POI type gets a distinct 14x14 monoline
    // glyph, currentColor stroked. Edit these paths to change what
    // shows in a pin's right-hand slot. Add new entries for new
    // types (matched against poi.type, then poi.cat).
    var TYPE_SVGS = {
        // Eating / drinking
        food:       '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 1v5c0 .55.45 1 1 1v5.5M3.5 1v3.5M3.5 1L2.5 4v1.5c0 .3.2.5.5.5M9.5 1v5.5c0 .3.2.5.5.5v4.5M9.5 1c1.2.4 2 2 2 3.5 0 1.2-.6 2.3-1.5 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        eats:       '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 1v5c0 .55.45 1 1 1v5.5M3.5 1v3.5M3.5 1L2.5 4v1.5c0 .3.2.5.5.5M9.5 1v5.5c0 .3.2.5.5.5v4.5M9.5 1c1.2.4 2 2 2 3.5 0 1.2-.6 2.3-1.5 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        bar:        '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 2.5h10L8 7.5v3.5M4.5 11h7M4.5 2.5l3.5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        drinks:     '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 2.5h10L8 7.5v3.5M4.5 11h7M4.5 2.5l3.5 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        // Movement / activities - each visually distinct
        hike:       '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="9.5" cy="2.5" r="1.2" stroke="currentColor" stroke-width="1.3"/><path d="M5 12l1.5-3-2-1.5 3-2 2 3 2 1.5M3 8l1 1M8 4.5L6 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        activities: '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="6" cy="8" r="4.5" stroke="currentColor" stroke-width="1.3"/><path d="M8.5 8L12 4.5M9 4l3-.5-.5 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        land:       '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 12l3.5-6.5L7 9.5l2-4 4 6.5H1z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="11" cy="3" r="1.3" stroke="currentColor" stroke-width="1.3"/></svg>',

        // Getting around
        transport:  '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="2.5" width="9" height="7.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M2.5 8h9M4 10v1.5M10 10v1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="4.5" cy="6.5" r=".7" fill="currentColor"/><circle cx="9.5" cy="6.5" r=".7" fill="currentColor"/></svg>',

        // Shopping
        shop:       '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.5 4.5h9l-.7 7.5H3.2l-.7-7.5zM5 4.5V3a2 2 0 0 1 4 0v1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        shops:      '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.5 4.5h9l-.7 7.5H3.2l-.7-7.5zM5 4.5V3a2 2 0 0 1 4 0v1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        // Stay / places
        hostel:     '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 12V6l5-3.5L12 6v6M5.5 12V8h3v4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        place:      '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 1.5c-2.2 0-4 1.7-4 3.9C3 8.4 7 12.5 7 12.5s4-4.1 4-7.1c0-2.2-1.8-3.9-4-3.9z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="7" cy="5.3" r="1.3" stroke="currentColor" stroke-width="1.3"/></svg>',
        city:       '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 12V5l3-2 3 2v7M8 12V7l3-2v7M2 12h10M4 7h1M4 9h1M9.5 8v1M9.5 10v1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        // Info / civic
        sights:     '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 7s2.5-4 6-4 6 4 6 4-2.5 4-6 4-6-4-6-4z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><circle cx="7" cy="7" r="2" stroke="currentColor" stroke-width="1.3"/></svg>',
        public:     '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 12V6h10v6M2 6l5-4 5 4M6 12V9h2v3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        // Time-bound
        event:      '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="3" width="9" height="8.5" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M2.5 5.5h9M4.5 2v2M9.5 2v2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        events:     '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.5" y="3" width="9" height="8.5" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M2.5 5.5h9M4.5 2v2M9.5 2v2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',

        // Fallback - neutral dot when the type isn't in the library.
        _default:   '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="7" cy="7" r="2" fill="currentColor"/></svg>'
    };

    function iconSvgForPoi(poi) {
        var key = (poi.type || poi.cat || '').toLowerCase();
        return TYPE_SVGS[key] || TYPE_SVGS._default;
    }

    /**
     * Colour for the POI, sourced from the same categories list the
     * filter chips render. Guarantees the pin's letter tile matches
     * the colour of its filter chip's dot — same input, same lookup.
     * Falls back to a hashed sticker-palette pick when the POI's
     * type/cat isn't in the categories list.
     */
    function resolveCategoryColor(poi, config) {
        var cats = (config && config.categories) || [];
        var key = String(poi.cat || poi.type || '').toLowerCase();
        for (var i = 0; i < cats.length; i++) {
            var c = cats[i];
            if (c && String(c.id).toLowerCase() === key && c.color) return c.color;
        }
        var palette = (config && config.branding && config.branding.stickerColors) ||
            ['#FFB85C', '#FF6B3D', '#C9F73F', '#6BB7FF', '#FF4D8F', '#B07AFF', '#FFD93D', '#5EE2A8'];
        var hash = 0;
        var id = String(poi.id || poi.title || key);
        for (var j = 0; j < id.length; j++) hash = (hash + id.charCodeAt(j)) % 1e9;
        return palette[hash % palette.length];
    }

    function buildIconHtml(poi, partials, config, isBase) {
        // Skin can supply a per-pin template (the 'pin' partial). If present,
        // it owns the entire marker DOM - the engine no longer wraps the icon
        // in <icon class="fa ..."> chrome. This is what the innsight2026 skin
        // uses to render its sticker treatment (photo + multiply wash + initial).
        //
        // When `isBase` is true AND the skin provides a `base` partial, we
        // render THAT instead - the "home base" hostel marker has its own
        // taped-photo treatment separate from every other POI's tag.
        if (isBase && partials && partials.base) {
            return Innsight._template.render(partials.base, buildBaseContext(poi, config));
        }
        if (partials && partials.pin) {
            var ctx = buildPinContext(poi, config);
            return Innsight._template.render(partials.pin, ctx);
        }
        return '<icon class="fa ' + resolveIconClass(poi) + '"></icon>';
    }

    /**
     * Base-marker render context. Adds four keys on top of the POI's
     * own fields:
     *   - basePhoto, basePhotoThumb, baseAlt: from config.branding.base
     *     (medium + thumbnail sizes emitted by JsonBuilder). Falls back
     *     to the POI's own image / imageThumb when the admin left the
     *     Settings > Base photo field empty.
     *   - baseLabel: config.branding.base.label, then wordmarkPrefix,
     *     then poi.title.
     */
    function buildBaseContext(poi, config) {
        var b = (config && config.branding && config.branding.base) || {};
        var wm = (config && config.branding && config.branding.wordmarkPrefix) || '';
        var ctx = {};
        for (var k in poi) if (Object.prototype.hasOwnProperty.call(poi, k)) ctx[k] = poi[k];
        ctx.basePhoto      = b.photo      || poi.image      || '';
        ctx.basePhotoThumb = b.photoThumb || poi.imageThumb || poi.image || '';
        ctx.baseAlt        = b.alt        || poi.title      || 'Home base';
        ctx.baseLabel      = b.label      || wm             || poi.title || 'Base';
        return ctx;
    }

    /**
     * Resolve the POI that should be rendered as the "home base".
     * Priority (highest wins):
     *   1. Explicit branding.base.lat/lon in Settings → synthetic POI
     *      at those exact coords. This is the admin's escape hatch:
     *      "put the base marker HERE, ignore the POI list."
     *   2. First POI with pinned === true.
     *   3. Else first POI with type/cat === 'hostel'.
     *   4. Else - if the admin configured Base photo/label/rings AND
     *      map.center is valid - a synthetic POI at map.center.
     *   5. Else null (no base marker).
     */
    function pickBasePoi(pois, config) {
        var b = (config && config.branding && config.branding.base) || {};
        // Priority 1: explicit coords from Settings.
        if (isFinite(b.lat) && isFinite(b.lon)) {
            return {
                id: '__innsight_base_synthetic',
                title: b.label || 'Base',
                lat: Number(b.lat),
                lon: Number(b.lon),
                type: 'hostel',
                cat: 'hostel',
                pinned: true,
                __synthetic: true
            };
        }
        for (var i = 0; i < pois.length; i++) if (pois[i].pinned) return pois[i];
        for (var j = 0; j < pois.length; j++) {
            if (pois[j].type === 'hostel' || pois[j].cat === 'hostel') return pois[j];
        }
        // Fallback: use map.center as anchor when Base settings exist.
        var hasBaseConfig = !!(b.photo || b.label || (b.rings && b.rings.length));
        var c = config && config.map && config.map.center;
        if (hasBaseConfig && c && isFinite(c.lat) && isFinite(c.lon)) {
            return {
                id: '__innsight_base_synthetic',
                title: b.label || 'Base',
                lat: Number(c.lat),
                lon: Number(c.lon),
                type: 'hostel',
                cat: 'hostel',
                pinned: true,
                __synthetic: true
            };
        }
        return null;
    }

    /**
     * Pin-template render context. Adds two computed fields on top of the POI:
     *   - initial: first character of name (uppercase)
     *   - stickerColor: color of the POI's category (matches the filter
     *     chip dot exactly). Falls back to a hashed-from-id color when
     *     the POI's cat/type isn't in the categories list (unknown type).
     * The skin can use {{stickerColor}} as a CSS custom property, etc.
     */
    function buildPinContext(poi, config) {
        var color = resolveCategoryColor(poi, config);
        var ctx = {};
        for (var k in poi) if (Object.prototype.hasOwnProperty.call(poi, k)) ctx[k] = poi[k];
        ctx.initial = (poi.title || poi.name || '·').charAt(0).toUpperCase();
        ctx.stickerColor = color;
        // Icon font class for the pin's right-hand slot. The host page
        // already loads Materialize (md-*) + Map Icons (map-*), so a
        // POI whose `category` is e.g. "md-restaurant" or "map-swimming"
        // becomes a real font glyph. Falls back to "fa-<type>" for POIs
        // that don't set a category (mirrors the legacy yuna-innsight
        // rule).
        ctx.iconClass = resolveIconClass(poi);
        // Inline SVG kept for downstream skins that opted into it via
        // {{{iconSvg}}}. innsight2026 no longer uses it.
        ctx.iconSvg = iconSvgForPoi(poi);
        return ctx;
    }

    function build(state) {
        var provider = state.provider;
        var config = state.normalized;
        var partials = state.partials || {};
        var groupTypes = {};
        var allMarkers = [];
        var bounds = L.latLngBounds([]);

        // Which POI (if any) is the "home base"? Rendered as base.html
        // instead of pin.html, non-interactive, and pushed to a special
        // group so filters can't hide it.
        var basePoi = partials.base ? pickBasePoi(config.pois, config) : null;
        // Debug: log whether the base was found + what branding.base
        // carries, so it's obvious when the admin uploaded a photo in
        // Settings but it never made it into the JSON config.
        if (typeof console !== 'undefined' && console.info) {
            var b = (config.branding && config.branding.base) || {};
            console.info('[innsight] base POI:', basePoi ? (basePoi.id + ' (' + basePoi.title + ')') : 'none',
                '| branding.base:', b, '| has photo:', !!b.photo);
        }

        config.pois.forEach(function (poi) {
            var isBase = basePoi && poi === basePoi;
            if (!groupTypes[poi.type]) {
                groupTypes[poi.type] = provider.createCluster({
                    groupName: poi.type,
                    iconRenderer: Innsight._clustering.buildIconCreator(poi.type, partials.cluster)
                });
            }
            var popupHtml = Innsight._popup.render(poi, config, partials.popup);
            var iconHtml = buildIconHtml(poi, partials, config, isBase);
            var marker = provider.addMarker({
                lat: poi.lat,
                lon: poi.lon,
                iconHtml: iconHtml,
                iconClassName: isBase
                    ? 'innsight-base-host'
                    : (partials.pin ? 'innsight-pin-host' : 'mapIcon'),
                // Base marker is a display-only badge - no popup, no
                // click handler, no bottom sheet open.
                popupHtml: (isBase || partials.pin) ? '' : popupHtml,
                data: poi,
                onClick: isBase ? null : function () { state.events.emit('marker:click', poi); },
                onPopupOpen: isBase ? null : function () { state.events.emit('popup:open', poi); }
            });
            if (!isBase) provider.addToCluster(groupTypes[poi.type], marker);
            bounds.extend([poi.lat, poi.lon]);
            allMarkers.push({ poi: poi, marker: marker, group: poi.type, isBase: isBase });
        });

        Object.keys(groupTypes).forEach(function (type) {
            provider.showCluster(groupTypes[type]);
        });

        // Synthetic base (map.center fallback) - the forEach above
        // didn't render it because it's not in config.pois. Add it
        // now as a plain non-interactive marker.
        if (basePoi && basePoi.__synthetic) {
            var iconHtml2 = buildIconHtml(basePoi, partials, config, true);
            provider.addMarker({
                lat: basePoi.lat, lon: basePoi.lon,
                iconHtml: iconHtml2,
                iconClassName: 'innsight-base-host',
                popupHtml: '',
                data: basePoi,
                onClick: null, onPopupOpen: null
            });
        }

        // Walk rings around the base. Rendered as GeoJSON circle
        // polygons + minute-label DOM chips at bearing 135°. Both
        // hide below zoom 13 (see mapbox-gl-provider.addWalkRings).
        if (basePoi && provider.addWalkRings) {
            var rings = (config.branding && config.branding.base && config.branding.base.rings) || [];
            if (rings && rings.length) {
                provider.addWalkRings(basePoi.lat, basePoi.lon, rings);
            }
        }

        state.groupTypes = groupTypes;
        state.markers = allMarkers;
        state.allBounds = bounds;
        state.basePoi = basePoi;
        return { groupTypes: groupTypes, markers: allMarkers, bounds: bounds, basePoi: basePoi };
    }

    function rebuildPopup(state, poi) {
        var entry = state.markers.find(function (m) { return m.poi === poi || m.poi.id === poi.id; });
        if (!entry) return;
        var partials = state.partials || {};
        var html = Innsight._popup.render(entry.poi, state.normalized, partials.popup);
        if (entry.marker.setPopupContent) entry.marker.setPopupContent(html);
        else if (entry.marker.bindPopup) entry.marker.bindPopup(html);
    }

    Innsight._markers = { build: build, resolveIconClass: resolveIconClass, rebuildPopup: rebuildPopup };
})(typeof window !== 'undefined' ? window : this);
