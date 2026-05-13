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

        // Type / category filtering.
        if (Array.isArray(opts.types) || typeof opts.cat === 'string') {
            var allowed = Array.isArray(opts.types)
                ? opts.types
                : (opts.cat === 'all' || opts.cat === '' ? Object.keys(state.groupTypes) : [opts.cat]);
            Object.keys(state.groupTypes).forEach(function (type) {
                if (allowed.indexOf(type) !== -1) state.provider.showCluster(state.groupTypes[type]);
                else state.provider.hideCluster(state.groupTypes[type]);
            });
        }

        // Substring query - applies to titles. Hides individual markers (not
        // entire cluster groups) so cross-category search still works.
        if (typeof opts.query === 'string') {
            state.query = opts.query;
            applyQuery(state);
        }

        state.events.emit('filter:change', {
            soloMode: state.soloMode,
            types:    Array.isArray(opts.types) ? opts.types : null,
            cat:      typeof opts.cat === 'string' ? opts.cat : null,
            query:    state.query || ''
        });
    }

    /**
     * Apply the current state.query against every marker. Markers whose title
     * (case-insensitive) contains the query stay visible; others are detached.
     * The engine's marker handle is provider-specific - the LeafletProvider
     * exposes .remove() / re-add via the cluster; the MapboxGLProvider toggles
     * the underlying DOM element. Both work via the marker handle's own
     * .remove() method when needed.
     */
    function applyQuery(state) {
        var q = (state.query || '').trim().toLowerCase();
        state.markers.forEach(function (entry) {
            var hit = q === '' || (entry.poi.title || entry.poi.name || '').toLowerCase().indexOf(q) !== -1;
            // For Mapbox GL markers the DOM element exists on the marker handle.
            if (entry.marker && entry.marker._innsightEl) {
                entry.marker._innsightEl.style.display = hit ? '' : 'none';
                return;
            }
            // For Leaflet markers (clustered), removing/adding to the cluster is
            // expensive; instead toggle the underlying icon element.
            if (entry.marker && entry.marker._icon) {
                entry.marker._icon.style.display = hit ? '' : 'none';
            }
        });
    }

    Innsight._filtering = { bindEvents: bindEvents, applySolo: applySolo, setFilter: setFilter };
})(typeof window !== 'undefined' ? window : this);
