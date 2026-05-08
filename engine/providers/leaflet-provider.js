/* Innsight - Leaflet (OSM) provider. Implements MapProvider on top of L.map.
 * Carries roughly 90% of the original yuna-innsight rendering logic.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var Base = Innsight._BaseProvider;

    function LeafletProvider(targetEl, opts) {
        Base.call(this, targetEl, opts);
        this._tileLayer = null;
        this._clusters = {};
        this._layerControl = null;
    }

    LeafletProvider.prototype = Object.create(Base.prototype);
    LeafletProvider.prototype.constructor = LeafletProvider;

    LeafletProvider.prototype.createMap = function (center, zoom) {
        if (typeof L === 'undefined') throw new Error('[innsight] Leaflet (L) is not loaded');
        var mapOpts = Object.assign({
            center: [center.lat, center.lon],
            zoom: zoom,
            keyboard: false,
            fullscreenControl: this.opts.fullscreen !== false,
            fullscreenControlOptions: { position: 'topleft' }
        }, this.opts.mapOptions || {});
        this.native = L.map(this.targetEl, mapOpts);
        return this.native;
    };

    LeafletProvider.prototype.setView = function (center, zoom) {
        this.native.setView([center.lat, center.lon], zoom);
    };

    LeafletProvider.prototype.fitBounds = function (bounds) {
        if (!bounds) return;
        var b;
        if (bounds && typeof bounds.isValid === 'function') {
            b = bounds;
        } else if (Array.isArray(bounds) && bounds.length === 2) {
            b = L.latLngBounds(bounds[0], bounds[1]);
        } else {
            return;
        }
        if (b.isValid()) this.native.fitBounds(b);
    };

    LeafletProvider.prototype.addTileLayer = function (spec) {
        if (this._tileLayer) this.native.removeLayer(this._tileLayer);
        this._tileLayer = L.tileLayer(spec.tileUrl, {
            maxZoom: spec.maxZoom || 19,
            attribution: spec.attribution || ''
        }).addTo(this.native);
        return this._tileLayer;
    };

    LeafletProvider.prototype.addMarker = function (spec) {
        var icon = L.divIcon({
            html: spec.iconHtml,
            iconSize: spec.iconSize || [20, 20],
            className: spec.iconClassName || 'mapIcon'
        });
        var marker = L.marker([spec.lat, spec.lon], { icon: icon });
        if (spec.popupHtml) marker.bindPopup(spec.popupHtml, spec.popupOptions || {});
        if (spec.onClick) marker.on('click', spec.onClick);
        if (spec.onPopupOpen) marker.on('popupopen', spec.onPopupOpen);
        marker._innsightData = spec.data || {};
        return marker;
    };

    LeafletProvider.prototype.removeMarker = function (handle) {
        if (handle && handle.remove) handle.remove();
    };

    LeafletProvider.prototype.addPolyline = function (coords, opts) {
        return L.polyline(coords, opts || {}).addTo(this.native);
    };

    LeafletProvider.prototype.createCluster = function (spec) {
        var cluster = L.markerClusterGroup({
            iconCreateFunction: spec.iconRenderer || function (c) {
                return L.divIcon({ html: '<div class="number">' + c.getChildCount() + '</div>', className: 'clusterui', iconSize: L.point(32, 32) });
            }
        });
        cluster._innsightGroupName = spec.groupName;
        this._clusters[spec.groupName] = cluster;
        return cluster;
    };

    LeafletProvider.prototype.addToCluster = function (cluster, marker) {
        marker.addTo(cluster);
    };

    LeafletProvider.prototype.showCluster = function (cluster) {
        if (!this.native.hasLayer(cluster)) cluster.addTo(this.native);
    };

    LeafletProvider.prototype.hideCluster = function (cluster) {
        if (this.native.hasLayer(cluster)) this.native.removeLayer(cluster);
    };

    LeafletProvider.prototype.addLayerControl = function (overlays, opts) {
        var o = Object.assign({ collapsed: false, sortLayers: true, hideSingleBase: true, position: 'bottomright' }, opts || {});
        this._layerControl = L.control.layers(null, overlays || null, o).addTo(this.native);
        return this._layerControl;
    };

    LeafletProvider.prototype.addLayerOverlay = function (group, name) {
        if (this._layerControl) this._layerControl.addOverlay(group, name);
    };

    LeafletProvider.prototype.addCustomControl = function (htmlEl, position) {
        var Control = L.Control.extend({
            options: { position: position || 'bottomright' },
            onAdd: function () {
                L.DomEvent.disableClickPropagation(htmlEl);
                return htmlEl;
            }
        });
        var ctrl = new Control();
        this.native.addControl(ctrl);
        return ctrl;
    };

    LeafletProvider.prototype.on = function (event, handler) {
        this.native.on(event, handler);
    };

    LeafletProvider.prototype.eachLayer = function (fn) {
        this.native.eachLayer(fn);
    };

    LeafletProvider.prototype.invalidateSize = function () {
        if (this.native) this.native.invalidateSize();
    };

    LeafletProvider.prototype.getVisibleBounds = function () {
        var bounds = L.latLngBounds([]);
        this.native.eachLayer(function (layer) {
            if (layer instanceof L.FeatureGroup && this.native.hasLayer(layer)) {
                var b = layer.getBounds();
                if (b && b.isValid()) bounds.extend(b);
            }
        }.bind(this));
        return bounds;
    };

    LeafletProvider.prototype.destroy = function () {
        if (this.native) {
            try { this.native.remove(); } catch (e) {}
            this.native = null;
        }
        this._clusters = {};
        this._layerControl = null;
    };

    Innsight._LeafletProvider = LeafletProvider;
})(typeof window !== 'undefined' ? window : this);
