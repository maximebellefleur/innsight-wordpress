/* Innsight - Google Maps provider stub.
 * Interface-complete but every method throws. v0.2 will implement against the Google Maps JS API
 * + @googlemaps/markerclusterer. Architecturally proven that the swap is one file.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var Base = Innsight._BaseProvider;

    function GoogleProvider(targetEl, opts) {
        Base.call(this, targetEl, opts);
        throw new Error('[innsight] GoogleProvider arrives in v0.2 — use provider.type "osm" or "mapbox" for now.');
    }
    GoogleProvider.prototype = Object.create(Base.prototype);
    GoogleProvider.prototype.constructor = GoogleProvider;

    Innsight._GoogleProvider = GoogleProvider;
})(typeof window !== 'undefined' ? window : this);
