/* solike2025 skin script.
 *
 * Registers a setup function the engine calls during bootstrap, after markers + layer-control
 * are built and before initial fitBounds. This is where we add the "Solo mode" toggle as a
 * Leaflet custom control - matching the original yuna-innsight UX 1:1.
 *
 * Loaded by the host page BEFORE Innsight.init runs (the engine looks up
 * Innsight._skins[<name>] during bootstrap, so this just needs to be on the page).
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    Innsight._skins = Innsight._skins || {};

    Innsight._skins.solike2025 = {
        setup: function (state) {
            var provider = state.provider;
            if (!provider || !provider.addCustomControl) return;
            var container = document.createElement('div');
            container.className = 'filter-toggle-container';
            container.innerHTML = ''
                + '<label class="switch">'
                + '  <input type="checkbox" id="filterToggle" data-innsight-solo-toggle>'
                + '  <span class="slider round"></span>'
                + '</label>'
                + '<span>Solo mode</span>';
            provider.addCustomControl(container, 'bottomright');
            state.hooks = state.hooks || {};
            state.hooks.soloToggle = container.querySelector('#filterToggle');

            // Bind the change handler now that the DOM exists. The engine's filtering module
            // also listens via event delegation, so this is just a safety net for direct binding.
            if (root.jQuery && state.target) {
                root.jQuery(state.target).on('change', '#filterToggle', function () {
                    state.soloMode = this.checked;
                    state.events.emit('solo:toggle', state.soloMode);
                    Innsight._filtering.applySolo(state);
                });
            }
        }
    };
})(typeof window !== 'undefined' ? window : this);
