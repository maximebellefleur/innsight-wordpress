/* Innsight - layer control (per-type overlays, plus bottom-right placement). */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function build(state) {
        var ui = state.normalized.ui || {};
        var lcOpts = ui.layerControl || {};
        state.provider.addLayerControl(null, {
            position: lcOpts.position || 'bottomright',
            collapsed: !!lcOpts.collapsed
        });
        Object.keys(state.groupTypes).forEach(function (type) {
            state.provider.addLayerOverlay(state.groupTypes[type], type);
        });
    }

    Innsight._layerControl = { build: build };
})(typeof window !== 'undefined' ? window : this);
