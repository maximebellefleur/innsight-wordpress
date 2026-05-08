/* Innsight - draws polyline paths from config.paths. */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function build(state) {
        var provider = state.provider;
        var paths = state.normalized.paths || [];
        state.paths = paths.map(function (p) {
            if (!p.coordinates || p.coordinates.length < 2) return null;
            var line = provider.addPolyline(p.coordinates, { color: p.color });
            return { spec: p, line: line };
        }).filter(Boolean);
        return state.paths;
    }

    Innsight._paths = { build: build };
})(typeof window !== 'undefined' ? window : this);
