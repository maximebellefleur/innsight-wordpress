/* Innsight - Mapbox provider. Extends LeafletProvider, swaps the tile layer to Mapbox raster.
 * No vector / GL — keeps the entire render pipeline identical to the OSM path so clustering,
 * popups and controls behave the same way.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var LeafletProvider = Innsight._LeafletProvider;

    function MapboxProvider(targetEl, opts) {
        LeafletProvider.call(this, targetEl, opts);
    }
    MapboxProvider.prototype = Object.create(LeafletProvider.prototype);
    MapboxProvider.prototype.constructor = MapboxProvider;

    MapboxProvider.prototype.addTileLayer = function (spec) {
        var token = spec.accessToken || (this.opts.mapbox && this.opts.mapbox.accessToken);
        var styleId = spec.styleId || (this.opts.mapbox && this.opts.mapbox.styleId) || 'mapbox/streets-v12';
        if (!token) throw new Error('[innsight] mapbox provider requires accessToken');
        var url = 'https://api.mapbox.com/styles/v1/' + styleId + '/tiles/{z}/{x}/{y}?access_token=' + encodeURIComponent(token);
        var layer = L.tileLayer(url, {
            maxZoom: spec.maxZoom || 19,
            tileSize: 512,
            zoomOffset: -1,
            attribution: spec.attribution || '© Mapbox © OpenStreetMap'
        }).addTo(this.native);
        this._tileLayer = layer;
        return layer;
    };

    Innsight._MapboxProvider = MapboxProvider;
})(typeof window !== 'undefined' ? window : this);
