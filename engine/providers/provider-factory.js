/* Innsight - provider factory. Picks a concrete provider by config.provider.type. */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function create(type, targetEl, opts) {
        opts = opts || {};
        switch (type) {
            case 'osm':
                return new Innsight._LeafletProvider(targetEl, opts);
            case 'mapbox':
                return new Innsight._MapboxProvider(targetEl, opts);
            case 'mapbox-gl':
                return new Innsight._MapboxGLProvider(targetEl, opts);
            case 'google':
                return new Innsight._GoogleProvider(targetEl, opts);
            default:
                throw new Error('[innsight] unknown provider type: ' + type);
        }
    }

    Innsight._providerFactory = { create: create };
})(typeof window !== 'undefined' ? window : this);
