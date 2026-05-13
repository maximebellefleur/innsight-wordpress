/* Innsight - Mapbox GL JS provider.
 *
 * Implements the MapProvider interface against Mapbox GL JS (or MapLibre GL,
 * which is API-compatible). Required when the active skin needs vector tile
 * styling (custom Mapbox style JSON, the cream-paper aesthetic), HTML markers
 * via mapboxgl.Marker, and disabled rotation/pitch.
 *
 * Compared to LeafletProvider this provider:
 *   - Renders HTML markers directly via mapboxgl.Marker (no marker clustering).
 *   - Treats `iconHtml` as the marker's full DOM (the skin owns it; no <icon>
 *     wrapper). The marker is anchored at 'bottom' so the geographic point is
 *     the bottom-center of the sticker - matches the design's pin tip.
 *   - Disables rotation and pitch by default. Two-finger rotate/tilt is off.
 *   - Implements addPolyline as a GeoJSON line layer added to the map.
 *
 * Loading: this provider expects window.mapboxgl (or window.maplibregl) to be
 * available. The skin's example HTML loads it from the official CDN; when
 * absent, the constructor throws with a clear hint.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var Base = Innsight._BaseProvider;

    function getGl() {
        return root.mapboxgl || root.maplibregl;
    }

    function MapboxGLProvider(targetEl, opts) {
        Base.call(this, targetEl, opts);
        var gl = getGl();
        if (!gl) {
            throw new Error('[innsight] MapboxGLProvider requires window.mapboxgl (or maplibregl). Load it via <script src="https://api.mapbox.com/mapbox-gl-js/v3.5.1/mapbox-gl.js"> first.');
        }
        this._gl = gl;
        this._markers = [];
        this._lineCounter = 0;
    }
    MapboxGLProvider.prototype = Object.create(Base.prototype);
    MapboxGLProvider.prototype.constructor = MapboxGLProvider;

    MapboxGLProvider.prototype.createMap = function (center, zoom) {
        var gl = this._gl;
        var opts = this.opts || {};
        if (opts.accessToken && gl.accessToken !== undefined) {
            gl.accessToken = opts.accessToken;
        }
        // Caller-provided style takes precedence; otherwise fall back to a sensible
        // streets-light style. The skin can supply either a Mapbox style URL
        // (mapbox://styles/...) or an inline JSON style.
        var style = opts.style || 'mapbox://styles/mapbox/light-v11';
        this.native = new gl.Map({
            container: this.targetEl,
            style: style,
            center: [center.lon, center.lat],
            zoom: zoom,
            minZoom: opts.minZoom || 0,
            maxZoom: opts.maxZoom || 22,
            bearing: 0,
            pitch: 0,
            attributionControl: false,
            doubleClickZoom: true,
            dragRotate: false,
            pitchWithRotate: false,
            touchPitch: false
        });
        if (this.native.touchZoomRotate && this.native.touchZoomRotate.disableRotation) {
            this.native.touchZoomRotate.disableRotation();
        }
        this.native.addControl(new gl.AttributionControl({ compact: true }), 'bottom-left');
        return this.native;
    };

    MapboxGLProvider.prototype.setView = function (center, zoom) {
        this.native.jumpTo({ center: [center.lon, center.lat], zoom: zoom });
    };

    MapboxGLProvider.prototype.fitBounds = function (bounds) {
        var gl = this._gl;
        var b;
        if (bounds && typeof bounds.getSouthWest === 'function') {
            b = bounds;
        } else if (Array.isArray(bounds) && bounds.length === 2) {
            // [[s,w],[n,e]] -> Mapbox LngLatBounds
            b = new gl.LngLatBounds([bounds[0][1], bounds[0][0]], [bounds[1][1], bounds[1][0]]);
        } else {
            return;
        }
        var pad = (this.opts && this.opts.fitPadding) || { top: 64, bottom: 96, left: 32, right: 32 };
        this.native.fitBounds(b, { padding: pad, duration: 0 });
    };

    MapboxGLProvider.prototype.addTileLayer = function () {
        // No-op: Mapbox styles include their own tile sources. The active style
        // (set in createMap) is the only "tile layer".
    };

    MapboxGLProvider.prototype.addMarker = function (spec) {
        var el = document.createElement('div');
        el.className = spec.iconClassName || 'innsight-marker';
        el.innerHTML = spec.iconHtml || '';
        if (spec.onClick) {
            el.style.cursor = 'pointer';
            el.style.touchAction = 'manipulation';
            el.addEventListener('click', function (ev) {
                ev.stopPropagation();
                spec.onClick(ev);
            });
        }
        var marker = new this._gl.Marker({ element: el, anchor: 'bottom' })
            .setLngLat([spec.lon, spec.lat])
            .addTo(this.native);
        marker._innsightData = spec.data || {};
        marker._innsightEl = el;
        if (spec.popupHtml) {
            marker.setPopup(new this._gl.Popup({ offset: 24, closeButton: false }).setHTML(spec.popupHtml));
            if (spec.onPopupOpen) {
                marker.getPopup().on('open', spec.onPopupOpen);
            }
        }
        this._markers.push(marker);
        return marker;
    };

    MapboxGLProvider.prototype.removeMarker = function (handle) {
        if (!handle) return;
        if (handle.remove) handle.remove();
        var idx = this._markers.indexOf(handle);
        if (idx !== -1) this._markers.splice(idx, 1);
    };

    MapboxGLProvider.prototype.addPolyline = function (coords, opts) {
        var id = 'innsight-line-' + (++this._lineCounter);
        var geojson = {
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: coords.map(function (c) { return [c[1], c[0]]; }) },
            properties: {}
        };
        var add = function () {
            this.native.addSource(id, { type: 'geojson', data: geojson });
            this.native.addLayer({
                id: id, type: 'line', source: id,
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': (opts && opts.color) || '#0F0F0F',
                    'line-width': (opts && opts.weight) || 3,
                    'line-opacity': 0.85
                }
            });
        }.bind(this);
        if (this.native.isStyleLoaded()) {
            add();
        } else {
            this.native.once('style.load', add);
        }
        return { id: id, geojson: geojson };
    };

    /* Clustering is intentionally not implemented in the GL provider in v0.1.
     * The sticker pin treatment is per-POI; clustering breaks the per-POI
     * photo + initial. Skins that need clustering at low zoom should add
     * it via setData on their own GeoJSON source. */
    MapboxGLProvider.prototype.createCluster = function (spec) {
        var pseudo = { _innsightGroupName: spec.groupName, _markers: [], _visible: true };
        return pseudo;
    };
    MapboxGLProvider.prototype.addToCluster = function (pseudo, marker) {
        pseudo._markers.push(marker);
    };
    MapboxGLProvider.prototype.showCluster = function (pseudo) {
        if (pseudo._visible) return;
        pseudo._visible = true;
        for (var i = 0; i < pseudo._markers.length; i++) pseudo._markers[i].addTo(this.native);
    };
    MapboxGLProvider.prototype.hideCluster = function (pseudo) {
        if (!pseudo._visible) return;
        pseudo._visible = false;
        for (var i = 0; i < pseudo._markers.length; i++) pseudo._markers[i].remove();
    };

    /* Layer control is a Leaflet-shaped concept; in GL the skin owns the
     * filter chips so we expose no-ops here. */
    MapboxGLProvider.prototype.addLayerControl = function () { return null; };
    MapboxGLProvider.prototype.addLayerOverlay = function () {};

    MapboxGLProvider.prototype.addCustomControl = function (htmlEl, position) {
        // Mapbox GL controls require an object with onAdd/onRemove. Wrap.
        var ctrl = {
            onAdd: function () { return htmlEl; },
            onRemove: function () { if (htmlEl.parentNode) htmlEl.parentNode.removeChild(htmlEl); }
        };
        var pos = position || 'top-right';
        // Translate Leaflet-style positions to Mapbox conventions if needed.
        var translated = {
            topleft: 'top-left', topright: 'top-right',
            bottomleft: 'bottom-left', bottomright: 'bottom-right'
        }[pos] || pos;
        this.native.addControl(ctrl, translated);
        return ctrl;
    };

    MapboxGLProvider.prototype.on = function (event, handler) {
        // Event names borrowed from Leaflet land here too. Translate the few
        // we know about; pass everything else through to Mapbox.
        var translated = {
            'overlayadd overlayremove': null, // No layer-control overlays in GL.
            'overlayadd':                null,
            'overlayremove':             null,
            'enterFullscreen':           null,
            'exitFullscreen':            null
        };
        if (Object.prototype.hasOwnProperty.call(translated, event)) {
            return; // Skin manages these directly via DOM.
        }
        this.native.on(event, handler);
    };

    MapboxGLProvider.prototype.eachLayer = function () {};

    MapboxGLProvider.prototype.invalidateSize = function () {
        if (this.native) this.native.resize();
    };

    MapboxGLProvider.prototype.getVisibleBounds = function () {
        // Compute the union of marker locations rather than the visible viewport
        // (matches Leaflet's existing semantics in the engine).
        if (!this._markers.length) return null;
        var gl = this._gl;
        var b = new gl.LngLatBounds();
        for (var i = 0; i < this._markers.length; i++) {
            b.extend(this._markers[i].getLngLat());
        }
        return b;
    };

    MapboxGLProvider.prototype.destroy = function () {
        if (this._markers) {
            for (var i = 0; i < this._markers.length; i++) {
                if (this._markers[i].remove) this._markers[i].remove();
            }
            this._markers = [];
        }
        if (this.native) {
            try { this.native.remove(); } catch (e) {}
            this.native = null;
        }
    };

    Innsight._MapboxGLProvider = MapboxGLProvider;
})(typeof window !== 'undefined' ? window : this);
