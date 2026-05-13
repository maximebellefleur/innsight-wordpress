/* Innsight - public entry point. Wires together core, providers, features, skin loader.
 *
 * Usage:
 *   Innsight.init({ target: '#map', config: {...} })             -> instance
 *   Innsight.init({ target: '#map', configUrl: '/api/map.json' }) -> instance
 *   instance.destroy() / .refresh(cfg) / .filter({...}) / .exportKml() / .on(evt, fn)
 *
 * jQuery wrapper exposed when window.jQuery is present:  $('#map').innsight(config)
 *
 * Load order (engine sub-files must precede this one):
 *   utils.js, events.js, template.js, state.js, config.js,
 *   base-provider.js, leaflet-provider.js, mapbox-provider.js, google-provider.js, provider-factory.js,
 *   clustering.js, popup.js, markers.js, paths.js, layer-control.js, filtering.js, kml-export.js,
 *   skin-loader.js, enrichment/google-places.js, innsight.js
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function resolveTarget(t) {
        if (typeof t === 'string') return document.querySelector(t);
        if (t && t.nodeType === 1) return t;
        if (root.jQuery && t instanceof root.jQuery && t[0]) return t[0];
        return null;
    }

    function fetchConfig(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('[innsight] failed to load config: ' + r.status);
            return r.json();
        });
    }

    function buildInstance(state) {
        var instance = {
            id: state.id,
            target: state.target,
            on: function (event, fn) { state.events.on(event, fn); return instance; },
            off: function (event, fn) { state.events.off(event, fn); return instance; },
            filter: function (opts) { Innsight._filtering.setFilter(state, opts); return instance; },
            exportKml: function (filename) { return Innsight._kmlExport.download(state, filename); },
            getKml: function () { return Innsight._kmlExport.buildKml(state); },
            // Fetch Google Places enrichment for a single POI. Used by the
            // skin when the user opens the bottom sheet - on-demand, cached
            // in localStorage. Resolves with the shaped Places payload or
            // null (disabled / not found / error).
            enrichPoi: function (poi) {
                if (!Innsight._enrichment || !Innsight._enrichment.enrichPoi) return Promise.resolve(null);
                return Innsight._enrichment.enrichPoi(poi, state.normalized).then(function (data) {
                    if (data) state.events.emit('poi:enriched', { poi: poi, data: data });
                    return data;
                });
            },
            refresh: function (newConfig) {
                if (state.destroyed) return instance;
                state.normalized = Innsight._config.normalize(newConfig);
                if (state.provider && state.provider.destroy) state.provider.destroy();
                state.groupTypes = {};
                state.markers = [];
                state.paths = [];
                bootstrap(state);
                return instance;
            },
            destroy: function () {
                if (state.destroyed) return;
                state.destroyed = true;
                if (state.provider && state.provider.destroy) state.provider.destroy();
                if (state.target) state.target.innerHTML = '';
                state.events.removeAll();
                Innsight._state.remove(state.id);
            },
            getProvider: function () { return state.provider; },
            getState: function () { return state; }
        };
        state.instance = instance;
        return instance;
    }

    function bootstrap(state) {
        var cfg = state.normalized;
        var target = state.target;

        // 1) Skin: load partials, mount layout, get DOM hooks.
        return Innsight._skinLoader.load(cfg.skin).then(function (partials) {
            state.partials = partials;
            var hooks = Innsight._skinLoader.mount(target, partials);
            state.hooks = hooks;

            // 2) Provider on the map element. Provider-specific options are
            // pulled from cfg.provider[type] so each provider configures itself
            // (Mapbox GL needs accessToken + style; Leaflet needs nothing extra).
            var providerCfg = Object.assign({}, cfg.provider[cfg.provider.type] || {});
            var providerOpts = Object.assign({
                fullscreen: cfg.map.fullscreen,
                mapOptions: { minZoom: cfg.map.minZoom, maxZoom: cfg.map.maxZoom },
                mapbox: cfg.provider.mapbox
            }, providerCfg);
            var provider = Innsight._providerFactory.create(cfg.provider.type, hooks.map, providerOpts);
            state.provider = provider;
            provider.createMap(cfg.map.center, cfg.map.zoom);

            // 3) Tile layer for the chosen provider (no-op for mapbox-gl, the
            // style itself defines the tiles).
            providerCfg.maxZoom = cfg.map.maxZoom;
            provider.addTileLayer(providerCfg);

            // 4) Markers, clustering, paths, layer control.
            Innsight._markers.build(state);
            Innsight._paths.build(state);
            Innsight._layerControl.build(state);

            // 5) Solo-mode toggle wiring.
            Innsight._filtering.bindEvents(state);
            if (cfg.filters && cfg.filters.soloMode === false) state.soloMode = false;

            // 5a) Skin-specific setup hook: lets a skin add custom controls / DOM after
            // the provider exists. Registered globally as Innsight._skins[<name>].setup.
            var skinName = cfg.skin && cfg.skin.name;
            if (skinName && Innsight._skins && Innsight._skins[skinName] && typeof Innsight._skins[skinName].setup === 'function') {
                try { Innsight._skins[skinName].setup(state); } catch (e) {
                    if (root.console) root.console.warn('[innsight] skin setup failed for ' + skinName + ':', e);
                }
            }

            // 6) Branding CSS variables.
            applyBranding(target, cfg.branding);

            // 7) KML download button.
            if (hooks.kmlButton && cfg.ui && cfg.ui.kmlExport) {
                hooks.kmlButton.addEventListener('click', function (e) {
                    e.preventDefault();
                    Innsight._kmlExport.download(state);
                });
            }

            // 8) Fullscreen body class hook.
            provider.on('enterFullscreen', function () {
                state.events.emit('fullscreen:enter');
                if (hooks.fullscreenTarget) hooks.fullscreenTarget.classList.add('fullscreen');
            });
            provider.on('exitFullscreen', function () {
                state.events.emit('fullscreen:exit');
                if (hooks.fullscreenTarget) hooks.fullscreenTarget.classList.remove('fullscreen');
            });

            // 9) Initial bounds fit to all visible markers.
            if (cfg.map.fitToBounds) {
                var b = provider.getVisibleBounds();
                if (b && b.isValid && b.isValid()) provider.fitBounds(b);
            }

            // 10) Loader removal.
            if (hooks.loader) {
                setTimeout(function () { hooks.loader.classList.remove('app-loading'); hooks.loader.style.display = 'none'; }, 300);
            }

            // 10b) Legacy yuna-innsight cleanup. The legacy page-map.php
            // template puts class="app-loading" on <body> + a top-level
            // <div class="loader"> and relies on its own JS to strip them
            // on window.load. When the new plugin handles the shortcode the
            // legacy JS never enqueues, so the body keeps the class and the
            // .loader animates the slide keyframe forever (looks like a
            // stuck PWA splash). Defensive cleanup, no-op on non-legacy
            // installs.
            cleanupLegacyLoader(target);

            state.ready = true;
            state.events.emit('ready', state.instance);

            // Google Places enrichment is on-demand now (per-POI when the
            // sheet opens), wired via instance.enrichPoi. No bulk init pass.

            return state.instance;
        }).catch(function (err) {
            state.events.emit('error', err);
            if (root.console) root.console.error('[innsight] bootstrap failed:', err);
            throw err;
        });
    }

    function cleanupLegacyLoader(targetEl) {
        try {
            if (root.document && root.document.body) {
                root.document.body.classList.remove('app-loading');
            }
            if (!root.document || !targetEl) return;
            var loaders = root.document.querySelectorAll('.loader');
            for (var i = 0; i < loaders.length; i++) {
                // Only hide loaders OUTSIDE our shortcode target so we don't
                // touch a host-page element that happens to share the class.
                if (!targetEl.contains(loaders[i])) {
                    loaders[i].style.display = 'none';
                }
            }
        } catch (e) {
            if (root.console) root.console.warn('[innsight] legacy loader cleanup:', e);
        }
    }

    function applyBranding(target, branding) {
        if (!branding) return;
        var colors = branding.colors || {};
        var styles = [];
        if (colors.rouge) styles.push('--rouge:' + colors.rouge);
        if (colors.noir) styles.push('--noir:' + colors.noir);
        if (colors.bleu) styles.push('--bleu:' + colors.bleu);
        if (branding.logoUrl) styles.push('--innsight-hostel-icon:url(' + JSON.stringify(branding.logoUrl) + ')');
        if (styles.length) target.setAttribute('style', (target.getAttribute('style') || '') + ';' + styles.join(';'));
    }

    function init(opts) {
        opts = opts || {};
        var target = resolveTarget(opts.target);
        if (!target) throw new Error('[innsight] target element not found: ' + opts.target);

        var state = Innsight._state.create({ target: target });
        state.events = new Innsight._Emitter();

        var loadConfig = opts.config
            ? Promise.resolve(opts.config)
            : (opts.configUrl ? fetchConfig(opts.configUrl) : Promise.reject(new Error('[innsight] init requires `config` or `configUrl`')));

        var instance = buildInstance(state);

        loadConfig.then(function (raw) {
            state.config = raw;
            state.normalized = Innsight._config.normalize(raw);
            if (opts.skinPath) state.normalized.skin.basePath = opts.skinPath;
            return bootstrap(state);
        }).catch(function (err) {
            state.events.emit('error', err);
            if (root.console) root.console.error('[innsight] init failed:', err);
        });

        return instance;
    }

    Innsight.init = init;
    Innsight.version = '0.1.0';

    // jQuery wrapper.
    if (root.jQuery) {
        root.jQuery.fn.innsight = function (config) {
            var first = null;
            this.each(function () {
                var inst = init({ target: this, config: typeof config === 'object' ? config : undefined, configUrl: typeof config === 'string' ? config : undefined });
                if (!first) first = inst;
            });
            return first;
        };
    }
})(typeof window !== 'undefined' ? window : this);
