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
     * Mirrors the priority order the skin's findHostelRef() uses, so
     * the marker and the "distance from base" list labels can never
     * disagree:
     *   1. First POI with pinned === true
     *   2. Else first POI with type/cat === 'hostel'
     *   3. Else null (no base marker; map.center is NOT auto-promoted)
     */
    function pickBasePoi(pois) {
        for (var i = 0; i < pois.length; i++) if (pois[i].pinned) return pois[i];
        for (var j = 0; j < pois.length; j++) {
            if (pois[j].type === 'hostel' || pois[j].cat === 'hostel') return pois[j];
        }
        return null;
    }

    /**
     * Pin-template render context. Adds two computed fields on top of the POI:
     *   - initial: first character of name (uppercase)
     *   - stickerColor: hashed-from-id color from config.branding.stickerColors
     *     (or a sensible default palette).
     * The skin can use {{stickerColor}} as a CSS custom property, etc.
     */
    function buildPinContext(poi, config) {
        var palette = (config && config.branding && config.branding.stickerColors) ||
            ['#FFB85C', '#FF6B3D', '#C9F73F', '#6BB7FF', '#FF4D8F', '#B07AFF', '#FFD93D', '#5EE2A8'];
        var hash = 0;
        var id = String(poi.id || poi.title || '');
        for (var i = 0; i < id.length; i++) hash = (hash + id.charCodeAt(i)) % 1e9;
        var color = palette[hash % palette.length];
        var ctx = {};
        for (var k in poi) if (Object.prototype.hasOwnProperty.call(poi, k)) ctx[k] = poi[k];
        ctx.initial = (poi.title || poi.name || '·').charAt(0).toUpperCase();
        ctx.stickerColor = color;
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
        var basePoi = partials.base ? pickBasePoi(config.pois) : null;

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
