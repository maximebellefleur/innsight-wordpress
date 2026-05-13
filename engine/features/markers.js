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

    function buildIconHtml(poi, partials, config) {
        // Skin can supply a per-pin template (the 'pin' partial). If present,
        // it owns the entire marker DOM - the engine no longer wraps the icon
        // in <icon class="fa ..."> chrome. This is what the innsight2026 skin
        // uses to render its sticker treatment (photo + multiply wash + initial).
        if (partials && partials.pin) {
            var ctx = buildPinContext(poi, config);
            return Innsight._template.render(partials.pin, ctx);
        }
        return '<icon class="fa ' + resolveIconClass(poi) + '"></icon>';
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

        config.pois.forEach(function (poi) {
            if (!groupTypes[poi.type]) {
                groupTypes[poi.type] = provider.createCluster({
                    groupName: poi.type,
                    iconRenderer: Innsight._clustering.buildIconCreator(poi.type, partials.cluster)
                });
            }
            var popupHtml = Innsight._popup.render(poi, config, partials.popup);
            var iconHtml = buildIconHtml(poi, partials, config);
            var marker = provider.addMarker({
                lat: poi.lat,
                lon: poi.lon,
                iconHtml: iconHtml,
                iconClassName: partials.pin ? 'innsight-pin-host' : 'mapIcon',
                popupHtml: partials.pin ? '' : popupHtml,
                data: poi,
                onClick: function () { state.events.emit('marker:click', poi); },
                onPopupOpen: function () { state.events.emit('popup:open', poi); }
            });
            provider.addToCluster(groupTypes[poi.type], marker);
            bounds.extend([poi.lat, poi.lon]);
            allMarkers.push({ poi: poi, marker: marker, group: poi.type });
        });

        Object.keys(groupTypes).forEach(function (type) {
            provider.showCluster(groupTypes[type]);
        });

        state.groupTypes = groupTypes;
        state.markers = allMarkers;
        state.allBounds = bounds;
        return { groupTypes: groupTypes, markers: allMarkers, bounds: bounds };
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
