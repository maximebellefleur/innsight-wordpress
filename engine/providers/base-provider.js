/* Innsight - MapProvider interface contract.
 * A provider wraps a concrete map library (Leaflet, Mapbox-via-Leaflet, Google) so the
 * rest of the engine works in terms of these methods only.
 *
 * Every method is overridden by concrete providers. The base implementation throws
 * so a missing override fails loudly.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function BaseProvider(targetEl, opts) {
        this.targetEl = targetEl;
        this.opts = opts || {};
        this.native = null;
    }

    function notImpl(name) {
        return function () { throw new Error('[innsight] provider does not implement ' + name); };
    }

    BaseProvider.prototype.createMap = notImpl('createMap');
    BaseProvider.prototype.setView = notImpl('setView');
    BaseProvider.prototype.fitBounds = notImpl('fitBounds');
    BaseProvider.prototype.addTileLayer = notImpl('addTileLayer');
    BaseProvider.prototype.addMarker = notImpl('addMarker');
    BaseProvider.prototype.removeMarker = notImpl('removeMarker');
    BaseProvider.prototype.addPolyline = notImpl('addPolyline');
    BaseProvider.prototype.createCluster = notImpl('createCluster');
    BaseProvider.prototype.addToCluster = notImpl('addToCluster');
    BaseProvider.prototype.showCluster = notImpl('showCluster');
    BaseProvider.prototype.hideCluster = notImpl('hideCluster');
    BaseProvider.prototype.addLayerControl = notImpl('addLayerControl');
    BaseProvider.prototype.addCustomControl = notImpl('addCustomControl');
    BaseProvider.prototype.on = notImpl('on');
    BaseProvider.prototype.destroy = notImpl('destroy');

    Innsight._BaseProvider = BaseProvider;
})(typeof window !== 'undefined' ? window : this);
