/* Innsight - Solo Mode + filter logic.
 *
 * Solo Mode: the user picks a single type from the layer-control radios; all other types are hidden.
 * Default (multi) mode: any combination of types can be on; behavior matches a normal Leaflet layer control.
 *
 * Ports yuna-innsight-settings.js:171-236 plus the overlayadd/overlayremove fitBounds behavior.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var $ = root.jQuery;

    function bindEvents(state) {
        var provider = state.provider;

        provider.on('overlayadd overlayremove', function () {
            var bounds = provider.getVisibleBounds();
            if (bounds && bounds.isValid && bounds.isValid()) {
                provider.fitBounds(bounds);
            }
        });

        provider.on('click', function () {
            // Collapse the control on map click (matches existing behavior).
            var el = state.target.querySelector('.leaflet-control-layers');
            if (el) el.classList.remove('leaflet-control-layers-expanded');
        });

        if ($) {
            $(state.target).on('change', '#filterToggle', function () {
                state.soloMode = this.checked;
                state.events.emit('solo:toggle', state.soloMode);
                applySolo(state);
            });

            $(state.target).on('change', 'input.leaflet-control-layers-selector', function () {
                if (!state.soloMode) return;
                var label = $(this).next('span').text().trim();
                applySolo(state, label);
            });
        }
    }

    function applySolo(state, selectedType) {
        if (!state.soloMode) return;
        if (!selectedType) {
            // First checked overlay
            var first = state.target.querySelector('input.leaflet-control-layers-selector:checked');
            if (first) {
                var span = first.parentNode.querySelector('span');
                if (span) selectedType = span.textContent.trim();
            }
        }
        Object.keys(state.groupTypes).forEach(function (type) {
            if (type === selectedType) state.provider.showCluster(state.groupTypes[type]);
            else state.provider.hideCluster(state.groupTypes[type]);
        });
        state.events.emit('filter:change', { soloMode: true, types: [selectedType] });
    }

    function setFilter(state, opts) {
        opts = opts || {};
        if (typeof opts.soloMode === 'boolean') state.soloMode = opts.soloMode;
        if (Array.isArray(opts.types)) {
            Object.keys(state.groupTypes).forEach(function (type) {
                if (opts.types.indexOf(type) !== -1) state.provider.showCluster(state.groupTypes[type]);
                else state.provider.hideCluster(state.groupTypes[type]);
            });
            state.events.emit('filter:change', { soloMode: state.soloMode, types: opts.types });
        }
    }

    Innsight._filtering = { bindEvents: bindEvents, applySolo: applySolo, setFilter: setFilter };
})(typeof window !== 'undefined' ? window : this);
