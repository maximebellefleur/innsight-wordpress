/* Innsight - skin loader.
 * Two modes, auto-detected:
 *   1. Inline: window.INNSIGHT_SKIN_PARTIALS = { layout, popup, cluster, listItem } -> use directly.
 *      The host (e.g. WordPress PHP) injects these into the page; engine never fetches.
 *   2. Fetched: load skin.basePath + 'skin.json' and the referenced HTML files at runtime.
 * In both modes, the engine mounts layout HTML into the target element, injects CSS+font links,
 * and discovers data-innsight-* hooks.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function injectStylesheet(href) {
        var existing = document.querySelector('link[data-innsight-skin][href="' + href + '"]');
        if (existing) return existing;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-innsight-skin', '1');
        document.head.appendChild(link);
        return link;
    }

    function fetchText(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('[innsight] failed to fetch ' + url + ': ' + r.status);
            return r.text();
        });
    }

    function loadFromInline(skinConfig) {
        var p = root.INNSIGHT_SKIN_PARTIALS || {};
        return Promise.resolve({
            manifest: { name: skinConfig.name, files: {} },
            layout:    p.layout    || '',
            popup:     p.popup     || '',
            cluster:   p.cluster   || '',
            pin:       p.pin       || '',
            sheet:     p.sheet     || '',
            listRow:   p.listRow   || p.listItem || '',
            listItem:  p.listItem  || '',
            emptyState:p.emptyState|| ''
        });
    }

    function loadFromFetch(skinConfig) {
        var base = skinConfig.basePath || '';
        if (base && base.charAt(base.length - 1) !== '/') base += '/';
        return fetchText(base + 'skin.json').then(function (txt) {
            var manifest = JSON.parse(txt);
            (manifest.externalCss || []).forEach(injectStylesheet);
            if (manifest.files && manifest.files.css) injectStylesheet(base + manifest.files.css);

            var files = manifest.files || {};
            var partKeys = ['layout', 'popup', 'cluster', 'pin', 'sheet', 'listRow', 'listItem', 'emptyState'];
            var jobs = partKeys.map(function (key) {
                var fname = files[key];
                return fname ? fetchText(base + fname).then(function (t) { return [key, t]; })
                             : Promise.resolve([key, '']);
            });
            return Promise.all(jobs).then(function (entries) {
                var partials = { manifest: manifest };
                entries.forEach(function (e) { partials[e[0]] = e[1]; });
                // Skip the script load if either (a) the host page already
                // preloaded the skin.js (so the skin module is already
                // registered) or (b) a script element with the same src is on
                // the page. Avoids a second IIFE execution.
                if (manifest.files && manifest.files.js) {
                    var skinName = manifest.name || (skinConfig && skinConfig.name);
                    var alreadyRegistered = skinName && Innsight._skins && Innsight._skins[skinName];
                    if (alreadyRegistered) return partials;
                    return injectScript(base + manifest.files.js).then(function () { return partials; });
                }
                return partials;
            });
        });
    }

    function injectScript(src) {
        return new Promise(function (resolve, reject) {
            // Match by src alone so a host-page preload (which lacks the
            // data-innsight-skin marker) is still picked up as already loaded.
            var existing = document.querySelector('script[src="' + src + '"]');
            if (existing) return resolve(existing);
            var s = document.createElement('script');
            s.src = src;
            s.async = false;
            s.setAttribute('data-innsight-skin', '1');
            s.onload = function () { resolve(s); };
            s.onerror = function () { reject(new Error('[innsight] failed to load skin script: ' + src)); };
            document.head.appendChild(s);
        });
    }

    function load(skinConfig) {
        if (root.INNSIGHT_SKIN_PARTIALS) return loadFromInline(skinConfig);
        return loadFromFetch(skinConfig);
    }

    function mount(targetEl, partials) {
        if (partials.layout) {
            targetEl.innerHTML = partials.layout;
        }
        var hooks = {
            map:              targetEl.querySelector('[data-innsight-map]'),
            loader:           targetEl.querySelector('[data-innsight-loader]'),
            fullscreenTarget: targetEl.querySelector('[data-innsight-fullscreen-target]'),
            kmlButton:        targetEl.querySelector('[data-innsight-kml-button]'),
            soloToggle:       targetEl.querySelector('[data-innsight-solo-toggle]'),
            list:             targetEl.querySelector('[data-innsight-list]'),
            sheet:            targetEl.querySelector('[data-innsight-sheet]'),
            search:           targetEl.querySelector('[data-innsight-search]'),
            chips:            targetEl.querySelector('[data-innsight-chips]'),
            tabs:             targetEl.querySelector('[data-innsight-tabs]'),
            countBadge:       targetEl.querySelector('[data-innsight-count]')
        };
        if (!hooks.map) {
            var div = document.createElement('div');
            div.setAttribute('data-innsight-map', '');
            div.style.width = '100%';
            div.style.height = '100%';
            targetEl.appendChild(div);
            hooks.map = div;
        }
        return hooks;
    }

    Innsight._skinLoader = { load: load, mount: mount, injectStylesheet: injectStylesheet };
})(typeof window !== 'undefined' ? window : this);
