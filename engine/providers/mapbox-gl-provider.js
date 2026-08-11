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
        } else {
            // No click handler = display-only marker (e.g. the base
            // hostel print). Kill pointer events so it doesn't steal
            // clicks from the map underneath.
            el.style.pointerEvents = 'none';
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

    /**
     * Walk-time rings around the base marker. Renders one GeoJSON
     * source with two circle polygons (5-min + 10-min, or whatever
     * minute array the caller passes) at 80 m per walking minute.
     * Two line layers (outer/inner) draw dashed borders; the inner
     * ring gets a subtle accent-tinted fill. A pair of DOM marker
     * chips at bearing 135° label each ring in minutes.
     *
     * All layers/labels hide below zoom 13 - at city scale they
     * cover the whole viewport and mean nothing.
     */
    MapboxGLProvider.prototype.addWalkRings = function (lat, lon, minutes) {
        if (!minutes || !minutes.length) return;
        // Deterministic id so re-calls (e.g. after a filter re-render)
        // replace rather than stack.
        var self = this;
        var sourceId = 'innsight-base-rings';
        var METRES_PER_MIN = 80;
        var MIN_ZOOM = 11;  // was 13 - too aggressive; visitors landing at zoom 10-12 saw nothing.
        if (root.console && root.console.info) {
            root.console.info('[innsight] walk rings around', lat.toFixed(4), lon.toFixed(4), '- minutes:', minutes, '- visible at zoom ≥', MIN_ZOOM);
        }

        function metersToPolygon(centerLat, centerLon, radiusM, steps) {
            // Simple equirectangular projection - good enough at pedestrian
            // radii (< 1 km) for a decoratively-drawn ring. Angular offsets
            // shrink with latitude for the longitude axis.
            steps = steps || 64;
            var pts = [];
            var latRad = centerLat * Math.PI / 180;
            var mPerDegLat = 111320;
            var mPerDegLon = 111320 * Math.cos(latRad);
            for (var i = 0; i <= steps; i++) {
                var t = (i / steps) * Math.PI * 2;
                var dx = Math.cos(t) * radiusM;
                var dy = Math.sin(t) * radiusM;
                pts.push([ centerLon + dx / mPerDegLon, centerLat + dy / mPerDegLat ]);
            }
            return pts;
        }

        // Sorted so the outer ring paints first (fill only on inner).
        var minsSorted = minutes.slice().sort(function (a, b) { return a - b; });
        var features = minsSorted.map(function (min, idx) {
            return {
                type: 'Feature',
                geometry: { type: 'Polygon', coordinates: [ metersToPolygon(lat, lon, min * METRES_PER_MIN) ] },
                properties: { minutes: min, order: idx }
            };
        });
        var geojson = { type: 'FeatureCollection', features: features };

        var addLayers = function () {
            // Clean up any prior render (e.g. after a config refresh).
            if (self.native.getLayer('innsight-base-rings-fill'))    self.native.removeLayer('innsight-base-rings-fill');
            if (self.native.getLayer('innsight-base-rings-line-in')) self.native.removeLayer('innsight-base-rings-line-in');
            if (self.native.getLayer('innsight-base-rings-line-out'))self.native.removeLayer('innsight-base-rings-line-out');
            if (self.native.getSource(sourceId))                     self.native.removeSource(sourceId);
            self.native.addSource(sourceId, { type: 'geojson', data: geojson });
            // Fill on the innermost ring only. Bumped from 14% -> 24%
            // so the accent tint is actually visible on the cream map
            // background at zoom 11-13.
            self.native.addLayer({
                id: 'innsight-base-rings-fill', type: 'fill', source: sourceId,
                minzoom: MIN_ZOOM,
                filter: ['==', ['get', 'order'], 0],
                paint: { 'fill-color': 'rgba(201,247,63,0.24)' }
            });
            // Outer stroke (all rings). Bumped from 2px @ 32% opacity
            // to 3px @ 55% so the dashed circles read at first glance
            // instead of blending into the map.
            self.native.addLayer({
                id: 'innsight-base-rings-line-out', type: 'line', source: sourceId,
                minzoom: MIN_ZOOM,
                paint: {
                    'line-color': 'rgba(15,15,15,0.55)',
                    'line-width': 3,
                    'line-dasharray': [3, 3]
                }
            });
            // Inner stroke (innermost only) - darker, so the "5 min"
            // core reads a touch stronger than the "10 min" outer.
            self.native.addLayer({
                id: 'innsight-base-rings-line-in', type: 'line', source: sourceId,
                minzoom: MIN_ZOOM,
                filter: ['==', ['get', 'order'], 0],
                paint: {
                    'line-color': 'rgba(15,15,15,0.70)',
                    'line-width': 3,
                    'line-dasharray': [3, 3]
                }
            });
        };

        if (this.native.isStyleLoaded()) addLayers();
        else this.native.once('style.load', addLayers);

        // Minute-label chips - one DOM marker per ring at bearing 135°.
        // Kept in this._ringChips so hideRings/re-render can clean up.
        this._ringChips = this._ringChips || [];
        for (var i = 0; i < this._ringChips.length; i++) this._ringChips[i].remove();
        this._ringChips = [];

        var bearingRad = 135 * Math.PI / 180;
        var latRad2 = lat * Math.PI / 180;
        var mPerDegLat2 = 111320;
        var mPerDegLon2 = 111320 * Math.cos(latRad2);
        var glRef = this._gl;
        var mapRef = this.native;

        minsSorted.forEach(function (min) {
            var r = min * METRES_PER_MIN;
            var dx = Math.sin(bearingRad) * r;   // east component
            var dy = Math.cos(bearingRad) * r;   // north; sin/cos flipped for compass bearing (0=N)
            var chipLat = lat + dy / mPerDegLat2;
            var chipLon = lon + dx / mPerDegLon2;
            var el = document.createElement('div');
            el.className = 'in-base__ring innsight-base-ring-host';
            el.textContent = min + ' MIN';
            el.style.pointerEvents = 'none';
            var m = new glRef.Marker({ element: el, anchor: 'center' })
                .setLngLat([chipLon, chipLat]);
            // Show/hide chip in sync with the ring layers.
            var syncChip = function () {
                var z = mapRef.getZoom();
                if (z >= MIN_ZOOM && !m._addedToMap) { m.addTo(mapRef); m._addedToMap = true; }
                else if (z < MIN_ZOOM && m._addedToMap) { m.remove(); m._addedToMap = false; }
            };
            syncChip();
            mapRef.on('zoom', syncChip);
            self._ringChips.push(m);
        });
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
