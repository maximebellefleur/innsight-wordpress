/* Innsight - JSON config validator + normalizer. Produces a stable internal shape. */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    var DEFAULTS = {
        version: 1,
        map: { center: { lat: 0, lon: 0 }, zoom: 13, minZoom: 2, maxZoom: 19, fitToBounds: true, fullscreen: true, gestureHandling: false },
        provider: { type: 'osm', osm: { tileUrl: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '© OpenStreetMap contributors' }, mapbox: { accessToken: '', styleId: 'mapbox/streets-v12' }, google: { apiKey: '', mapId: '' } },
        enrichment: { google: { apiKey: '', fields: [], cacheTtlHours: 24 } },
        skin: { name: 'solike2025', basePath: 'skins/solike2025/' },
        branding: { colors: { rouge: '#da011a', noir: '#3d3c3c', bleu: '#1a73e8' }, logoUrl: '' },
        actionLinks: {
            google: { label: 'GMAPS', urlTemplate: 'https://www.google.com/maps/dir/?api=1&destination={{lat}},{{lon}}' },
            apple: { label: 'iOS', urlTemplate: 'https://maps.apple.com/?daddr={{lat}},{{lon}}' },
            mapsme: { label: 'Maps.me', urlTemplate: 'mapsme://map?ll={{lat}},{{lon}}', mobileOnly: true }
        },
        pois: [],
        paths: [],
        filters: { types: [], soloMode: true },
        ui: { kmlExport: true, layerControl: { position: 'bottomright', collapsed: false } }
    };

    function deepMerge(target, source) {
        if (!source) return target;
        Object.keys(source).forEach(function (k) {
            var sv = source[k];
            if (sv && typeof sv === 'object' && !Array.isArray(sv)) {
                target[k] = deepMerge(typeof target[k] === 'object' && target[k] ? Object.assign({}, target[k]) : {}, sv);
            } else if (sv !== undefined) {
                target[k] = sv;
            }
        });
        return target;
    }

    function clone(obj) { return JSON.parse(JSON.stringify(obj)); }

    function normalizePoi(poi, idx) {
        var lat = Number(poi.lat);
        var lon = Number(poi.lon != null ? poi.lon : poi.lng);
        if (!isFinite(lat) || !isFinite(lon)) return null;
        var btn = poi.button || {};
        if (poi.btn_url || poi.btn_text) {
            btn = { url: poi.btn_url || btn.url, text: poi.btn_text || btn.text };
        }
        return {
            id: poi.id || ('poi-' + idx),
            title: poi.title || '',
            lat: lat,
            lon: lon,
            description: poi.description != null ? poi.description : (poi.desc || ''),
            type: poi.type || 'place',
            category: poi.category || '',
            icon: poi.icon || '',
            image: poi.image || poi.img || '',
            button: { url: btn.url || '', text: btn.text || '' },
            pinned: !!poi.pinned,
            googlePlaceId: poi.googlePlaceId || poi.google_place_id || ''
        };
    }

    function normalizePath(path, idx) {
        var coords = (path.coordinates || []).map(function (c) {
            if (Array.isArray(c)) return [Number(c[0]), Number(c[1])];
            if (typeof c === 'string' && c.indexOf(',') !== -1) {
                var p = c.split(',').map(function (n) { return Number(n.trim()); });
                return [p[0], p[1]];
            }
            return null;
        }).filter(Boolean);
        return {
            id: path.id || ('path-' + idx),
            title: path.title || '',
            color: path.color || '#3d3c3c',
            coordinates: coords
        };
    }

    function normalize(input) {
        if (!input || typeof input !== 'object') {
            throw new Error('[innsight] config must be an object');
        }
        var cfg = deepMerge(clone(DEFAULTS), input);

        // Legacy adapter: if top-level lat/lon/zoom present, push into map.
        if (input.lat != null) cfg.map.center.lat = Number(input.lat);
        if (input.lon != null) cfg.map.center.lon = Number(input.lon);
        if (input.zoom != null) cfg.map.zoom = Number(input.zoom);

        // POIs / legacy add_markers
        var rawPois = input.pois || input.add_markers || [];
        cfg.pois = rawPois.map(normalizePoi).filter(Boolean);

        // Paths / legacy add_paths
        var rawPaths = input.paths || input.add_paths || [];
        cfg.paths = rawPaths.map(normalizePath);

        // Derive types if not provided
        if (!cfg.filters.types || cfg.filters.types.length === 0) {
            var seen = {};
            cfg.pois.forEach(function (p) { if (p.type) seen[p.type] = true; });
            cfg.filters.types = Object.keys(seen);
        }

        // Validate provider type
        var ok = ['osm', 'mapbox', 'google'];
        if (ok.indexOf(cfg.provider.type) === -1) {
            throw new Error('[innsight] unknown provider.type: ' + cfg.provider.type);
        }
        if (cfg.provider.type === 'mapbox' && !cfg.provider.mapbox.accessToken) {
            throw new Error('[innsight] mapbox provider requires provider.mapbox.accessToken');
        }

        return cfg;
    }

    Innsight._config = { normalize: normalize, DEFAULTS: DEFAULTS };
})(typeof window !== 'undefined' ? window : this);
