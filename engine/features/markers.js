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

    function buildIconHtml(poi) {
        return '<icon class="fa ' + resolveIconClass(poi) + '"></icon>';
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
            var marker = provider.addMarker({
                lat: poi.lat,
                lon: poi.lon,
                iconHtml: buildIconHtml(poi),
                iconClassName: 'mapIcon',
                popupHtml: popupHtml,
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
