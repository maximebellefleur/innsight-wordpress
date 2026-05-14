/* innsight2026 - skin runtime.
 *
 * Owns ALL UI state (tab routing, search, chip filter, bottom-sheet open/close,
 * sheet swipe + hint, list view rendering, empty states). The engine owns the
 * map, the markers, and the data normalization.
 *
 * Registers itself on Innsight._skins.innsight2026 = { setup }. The engine's
 * bootstrap calls setup(state) right after it builds the markers and before
 * it emits 'ready', so the skin has a fully-rendered map to talk to.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    Innsight._skins = Innsight._skins || {};

    var DEFAULT_CATEGORIES = [
        { id: 'all',    label: 'All',     color: '#0F0F0F' },
        { id: 'eats',   label: 'Eats',    color: '#FF6B3D' },
        { id: 'drinks', label: 'Drinks',  color: '#C9F73F' },
        { id: 'sights', label: 'Sights',  color: '#6BB7FF' },
        { id: 'shops',  label: 'Shops',   color: '#FF4D8F' },
        { id: 'events', label: 'Events',  color: '#B07AFF' }
    ];

    var HINT_KEY      = 'innsight.swipeHint.count';
    var HINT_LAST_KEY = 'innsight.swipeHint.lastSeen';
    var HINT_USED_KEY = 'innsight.swipeHint.usedGesture';
    var HINT_THRESHOLD = 10;
    var HINT_REMINDER_MS = 7 * 24 * 60 * 60 * 1000;

    function safeStorage(key) {
        try { return root.localStorage.getItem(key); } catch (e) { return null; }
    }
    function setStorage(key, value) {
        try { root.localStorage.setItem(key, value); } catch (e) {}
    }
    function shouldShowHint() {
        var used = safeStorage(HINT_USED_KEY) === '1';
        var count = parseInt(safeStorage(HINT_KEY) || '0', 10);
        var last = parseInt(safeStorage(HINT_LAST_KEY) || '0', 10);
        var now = Date.now();
        var longGap = last && (now - last) > HINT_REMINDER_MS;
        if (count < HINT_THRESHOLD) return true;
        if (longGap && !used) return true;
        return false;
    }
    function recordHintShown() {
        var count = parseInt(safeStorage(HINT_KEY) || '0', 10);
        setStorage(HINT_KEY, String(count + 1));
        setStorage(HINT_LAST_KEY, String(Date.now()));
    }
    function recordSwipeUsed() { setStorage(HINT_USED_KEY, '1'); }

    Innsight._skins.innsight2026 = {
        setup: function (state) {
            var ctx = new SkinController(state);
            ctx.boot();
            state.skinController = ctx;
        }
    };

    function SkinController(state) {
        this.state = state;
        this.cfg = state.normalized;
        this.target = state.target;
        this.hooks = state.hooks || {};
        this.categories = (this.cfg.categories && this.cfg.categories.length)
            ? [{ id: 'all', label: 'All', color: '#0F0F0F' }].concat(this.cfg.categories)
            : DEFAULT_CATEGORIES.slice();
        this.catById = {};
        for (var i = 0; i < this.categories.length; i++) this.catById[this.categories[i].id] = this.categories[i];

        this.route = 'map';
        this.cat = 'all';
        this.query = '';
        this.sort = 'nearest';   // 'nearest' | 'az' | 'za'
        this.fullscreen = false;
        this.sheet = { poi: null, hintShown: false, drag: 0, swiping: false, startX: 0, startY: 0, hintTimer: null, context: 'map' };
        // Per-POI enrichment status. Once a POI has been resolved (with or
        // without data) we mark its id here so the sheet's "Looking up live
        // details…" placeholder doesn't flash on re-open. Cleared when the
        // user manually refreshes the page (it's intentionally session-only;
        // localStorage already cached the network result).
        this.enrichSeen = {};
        this.app = this.target.querySelector('.in-app');
        // Cache the hostel POI (or any POI marked pinned=true) so we can
        // compute "distance from base" for every other POI without scanning
        // on every render.
        this.hostelRef = this.findHostelRef();
        if (this.hostelRef) this.annotateDistances();
    }

    /**
     * Find a stable reference point for the "X km from base" label.
     * Priority: POI with pinned===true, then any POI of type/cat 'hostel',
     * then fall back to the map center, then null (skips the label).
     */
    SkinController.prototype.findHostelRef = function () {
        var pois = this.cfg.pois || [];
        for (var i = 0; i < pois.length; i++) {
            if (pois[i].pinned) return { lat: +pois[i].lat, lon: +pois[i].lon, source: 'pinned' };
        }
        for (var j = 0; j < pois.length; j++) {
            if (pois[j].type === 'hostel' || pois[j].cat === 'hostel') return { lat: +pois[j].lat, lon: +pois[j].lon, source: 'hostel-type' };
        }
        if (this.cfg.map && this.cfg.map.center) {
            return { lat: +this.cfg.map.center.lat, lon: +this.cfg.map.center.lon, source: 'map-center' };
        }
        return null;
    };

    /**
     * Annotate every POI with its great-circle distance to the reference
     * point. Result stored in poi.__distMeters so we can sort and format
     * later without recomputing. Haversine, returns metres.
     */
    SkinController.prototype.annotateDistances = function () {
        var ref = this.hostelRef;
        if (!ref) return;
        var R = 6371000; // earth radius in metres
        var toRad = function (d) { return d * Math.PI / 180; };
        var refLat = toRad(ref.lat), refLon = toRad(ref.lon);
        (this.cfg.pois || []).forEach(function (p) {
            var lat = toRad(+p.lat), lon = toRad(+p.lon);
            var dLat = lat - refLat, dLon = lon - refLon;
            var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                    Math.cos(refLat) * Math.cos(lat) *
                    Math.sin(dLon / 2) * Math.sin(dLon / 2);
            var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            p.__distMeters = R * c;
        });
    };

    /**
     * Human-readable "1.2 km" / "240 m" / "" if missing. Skips formatting
     * the hostel POI itself.
     */
    SkinController.prototype.formatDist = function (poi) {
        if (!this.hostelRef || poi.pinned) return '';
        var m = poi.__distMeters;
        if (typeof m !== 'number' || isNaN(m)) return '';
        if (m < 1000) return Math.round(m) + ' m';
        return (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km';
    };

    SkinController.prototype.boot = function () {
        // PWA cleanup runs first so a stale yuna service worker can't keep
        // serving an old engine/skin from cache.
        this.cleanupStaleServiceWorkers();
        this.checkVersion();

        this.applyBranding();
        this.applyWordmark();
        this.renderChips();
        this.bindChrome();
        this.bindMapControls();
        this.bindTabs();
        this.bindSheetBackdrop();
        this.bindSortMenu();
        this.bindFullscreenSync();
        this.bindEngineEvents();
        this.bindShareControls();
        this.bindReceiveControls();
        this.startLiveLocation();
        this.updateCounts();
        // Sync route class on first render so CSS scope variants apply.
        this.setRoute('map');
        // After everything is wired, check for ?innsight_share=... in the URL
        // and pop the receive modal. Also restore the dancing 'Friend's picks'
        // chip if the user previously chose Preview and is mid-session.
        this.consumeShareUrl();
        this.restoreSharedPicksChip();
    };

    /* ── PWA hygiene ─────────────────────────────────────────────────────── */

    /**
     * Unregister any service worker still scoped to /yuna-innsight/ - that
     * was the predecessor plugin and it had a SW that intercepts every
     * request inside its scope, which causes "even after clearing cache I
     * still see the old map" reports on returning visitors. Also prune
     * any caches whose name mentions yuna so installed PWAs get cleaned
     * up on the first visit after the upgrade. Safe to run on every load
     * - the operations are no-ops once cleanup has happened.
     */
    SkinController.prototype.cleanupStaleServiceWorkers = function () {
        try {
            if (root.navigator && root.navigator.serviceWorker && root.navigator.serviceWorker.getRegistrations) {
                root.navigator.serviceWorker.getRegistrations().then(function (regs) {
                    regs.forEach(function (reg) {
                        var scope = (reg.scope || '');
                        if (scope.indexOf('yuna-innsight') >= 0 || scope.indexOf('yuna_innsight') >= 0) {
                            reg.unregister().then(function () {
                                if (root.console) root.console.info('[innsight] unregistered stale SW:', scope);
                            });
                        }
                    });
                });
            }
            if (root.caches && root.caches.keys) {
                root.caches.keys().then(function (names) {
                    names.forEach(function (name) {
                        if (name.indexOf('yuna') >= 0) root.caches.delete(name);
                    });
                });
            }
        } catch (e) { /* old browsers - no SW API */ }
    };

    /**
     * Stamp the running engine version into localStorage. When the version
     * changes from a prior visit we wipe transient caches (swipe-hint
     * counters, Google Places negative cache) so any odd state from the
     * previous build doesn't bleed into the new one. We deliberately
     * preserve `innsight.savedPois` and `innsight.notes` - the user's data
     * is sacred.
     */
    SkinController.prototype.checkVersion = function () {
        // `version` in the v1 JSON is the schema number (1). The plugin
        // release version is `pluginVersion`, set by JsonBuilder.
        var current = String((this.cfg && this.cfg.pluginVersion) || '');
        if (!current) return;
        var stored = '';
        try { stored = root.localStorage.getItem('innsight.version') || ''; } catch (e) {}
        if (root.console) root.console.info('[innsight] v' + current + (stored && stored !== current ? ' (was ' + stored + ')' : ''));
        if (stored && stored !== current) {
            try {
                // Wipe non-user caches.
                root.localStorage.removeItem('innsight.swipeHint.count');
                root.localStorage.removeItem('innsight.swipeHint.lastSeen');
                // Clear negative-cache Google Places entries; positive ones
                // are fine to keep (place facts don't change with engine
                // releases).
                for (var i = root.localStorage.length - 1; i >= 0; i--) {
                    var k = root.localStorage.key(i);
                    if (k && k.indexOf('innsight:gp:') === 0) {
                        try {
                            var entry = JSON.parse(root.localStorage.getItem(k) || '{}');
                            if (entry && entry.notFound) root.localStorage.removeItem(k);
                        } catch (er) {}
                    }
                }
            } catch (e) {}
        }
        try { root.localStorage.setItem('innsight.version', current); } catch (e) {}
    };

    /* ── Live location ───────────────────────────────────────────────────── */

    /**
     * Ask the browser for the user's coordinates (with permission) and place
     * a pulsing marker on the map. The icon is configurable via plugin
     * Settings (default: 🎒 backpack emoji). Skipped when:
     *   - the Geolocation API is unavailable
     *   - cfg.ui.liveLocation.enabled === false (admin opted out)
     *   - the user denies the permission prompt
     * Updates the marker as the watch reports new positions so the dot
     * tracks the user.
     */
    SkinController.prototype.startLiveLocation = function () {
        var liveCfg = (this.cfg.ui && this.cfg.ui.liveLocation) || {};
        if (!liveCfg.enabled) return;
        // Country gate: the plugin computed `allowed` from the visitor's
        // CDN country header against the admin's allowlist. If false, do
        // NOT trigger the geolocation prompt - the visitor is outside the
        // allowlist (or the detection was inconclusive on a strict list).
        if (liveCfg.allowed === false) return;
        if (!root.navigator || !root.navigator.geolocation) return;

        var self = this;
        var icon = liveCfg.icon || '';
        var firstFix = true;
        root.navigator.geolocation.watchPosition(function (pos) {
            self.placeLiveMarker(pos.coords.latitude, pos.coords.longitude, icon);
            // Center the map on the first fix; subsequent updates just move
            // the marker (don't yank the camera).
            if (firstFix) {
                firstFix = false;
                self.recenterOnLiveLocation(pos.coords.latitude, pos.coords.longitude);
            }
        }, function (err) {
            // Denied / unavailable / timeout - silent fallback, the map
            // still works without a live dot.
            if (root.console) root.console.info('[innsight] live location skipped:', err && err.message);
        }, { enableHighAccuracy: false, maximumAge: 30000, timeout: 12000 });
    };

    SkinController.prototype.placeLiveMarker = function (lat, lon, icon) {
        var p = this.state.provider;
        if (!p || !p.native) return;

        // Build the DOM once; subsequent calls just move it.
        if (!this._liveEl) {
            var el = document.createElement('div');
            el.className = 'in-livedot';
            el.innerHTML = '<span class="in-livedot__pulse" aria-hidden="true"></span>'
                         + '<span class="in-livedot__core">' + (icon ? this.escape(icon) : '') + '</span>';
            this._liveEl = el;

            // Mapbox GL provider exposes _gl; Leaflet exposes L globally.
            if (root.mapboxgl && p.native.addControl) {
                this._liveMarker = new root.mapboxgl.Marker({ element: el, anchor: 'center' })
                    .setLngLat([lon, lat])
                    .addTo(p.native);
                return;
            }
            if (root.L && p.native.addLayer) {
                var divIcon = root.L.divIcon({
                    html: el.outerHTML,
                    className: 'in-livedot-host',
                    iconSize: [44, 44],
                    iconAnchor: [22, 22]
                });
                this._liveMarker = root.L.marker([lat, lon], { icon: divIcon, interactive: false, keyboard: false }).addTo(p.native);
                return;
            }
        }
        // Move the existing marker to the new position.
        if (this._liveMarker && this._liveMarker.setLngLat) {
            this._liveMarker.setLngLat([lon, lat]);
        } else if (this._liveMarker && this._liveMarker.setLatLng) {
            this._liveMarker.setLatLng([lat, lon]);
        }
    };

    SkinController.prototype.recenterOnLiveLocation = function (lat, lon) {
        var p = this.state.provider;
        if (!p || !p.native) return;
        // Don't snap if the user already moved the camera deliberately
        // (heuristic: if they have a sheet open, leave them alone).
        if (this.sheet.poi) return;
        if (p.setView) p.setView({ lat: lat, lon: lon }, 14);
    };

    SkinController.prototype.escape = function (str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    /* Compact review count: 1.2k / 12k / 123 */
    SkinController.prototype.formatCount = function (n) {
        n = Number(n);
        if (!isFinite(n)) return '';
        if (n >= 10000) return Math.round(n / 1000) + 'k';
        if (n >= 1000)  return (n / 1000).toFixed(1) + 'k';
        return String(n);
    };

    /* ── Sort dropdown ───────────────────────────────────────────────────── */
    SkinController.prototype.bindSortMenu = function () {
        var self = this;
        var toggle = this.target.querySelector('[data-innsight-sort-toggle]');
        var menu = this.target.querySelector('[data-innsight-sort-menu]');
        var label = this.target.querySelector('[data-innsight-sort-label]');
        if (!toggle || !menu) return;
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !menu.hasAttribute('hidden');
            if (open) { menu.setAttribute('hidden', ''); toggle.setAttribute('aria-expanded', 'false'); }
            else      { menu.removeAttribute('hidden'); toggle.setAttribute('aria-expanded', 'true'); }
        });
        // Close on outside click.
        document.addEventListener('click', function (e) {
            if (!menu.hasAttribute('hidden') && !menu.contains(e.target) && e.target !== toggle && !toggle.contains(e.target)) {
                menu.setAttribute('hidden', '');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
        var items = menu.querySelectorAll('[data-sort]');
        for (var i = 0; i < items.length; i++) {
            (function (item) {
                item.addEventListener('click', function () {
                    var sort = item.getAttribute('data-sort');
                    self.sort = sort;
                    var labels = { 'nearest': 'Nearest first', 'az': 'A → Z', 'za': 'Z → A' };
                    if (label) label.textContent = labels[sort] || 'Nearest first';
                    menu.setAttribute('hidden', '');
                    toggle.setAttribute('aria-expanded', 'false');
                    if (self.route === 'list') self.renderList();
                });
            })(items[i]);
        }
    };

    /* Apply the current sort to a copy of the visible POIs. */
    SkinController.prototype.sortPois = function (pois) {
        var out = pois.slice();
        var s = this.sort || 'nearest';
        if (s === 'az') {
            out.sort(function (a, b) {
                return (a.title || a.name || '').localeCompare(b.title || b.name || '');
            });
        } else if (s === 'za') {
            out.sort(function (a, b) {
                return (b.title || b.name || '').localeCompare(a.title || a.name || '');
            });
        } else {
            // 'nearest' - by __distMeters when available, else by ID.
            out.sort(function (a, b) {
                var da = typeof a.__distMeters === 'number' ? a.__distMeters : Infinity;
                var db = typeof b.__distMeters === 'number' ? b.__distMeters : Infinity;
                return da - db;
            });
        }
        return out;
    };

    SkinController.prototype.applyBranding = function () {
        var b = this.cfg.branding || {};
        var colors = b.colors || {};
        var styles = [];
        if (colors.bg)     styles.push('--in-bg:'     + colors.bg);
        if (colors.ink)    styles.push('--in-ink:'    + colors.ink);
        if (colors.accent) styles.push('--in-accent:' + colors.accent);
        if (b.sheetBg)     styles.push('--in-sheet:'  + b.sheetBg);
        if (styles.length) this.app.setAttribute('style', (this.app.getAttribute('style') || '') + ';' + styles.join(';'));
    };

    /**
     * Rewrite every `.in-wordmark` element (chrome header, list view,
     * saved view) when an admin-supplied `branding.wordmarkPrefix` is
     * set. The output looks like:
     *
     *   <div class="in-wordmark">
     *       Balmers <span class="innsight-mode-header">→ Innsight</span><span class="in-accent-dot">.</span>
     *   </div>
     *
     * If no prefix is set we leave the original markup ("Innsight" plus
     * accent dot) untouched. Plain-text only - the prefix is escaped via
     * textContent so admins can't inject HTML through the field.
     */
    SkinController.prototype.applyWordmark = function () {
        var prefix = ((this.cfg.branding && this.cfg.branding.wordmarkPrefix) || '').toString().trim();
        if (!prefix) return;
        var marks = this.target.querySelectorAll('.in-wordmark');
        for (var i = 0; i < marks.length; i++) {
            var dot = marks[i].querySelector('.in-accent-dot');
            // Wipe existing content, rebuild: "<prefix> <span>→ Innsight</span><span>.</span>"
            marks[i].textContent = prefix + ' ';
            var modeSpan = document.createElement('span');
            modeSpan.className = 'innsight-mode-header';
            modeSpan.textContent = '→ Innsight';   // → Innsight
            marks[i].appendChild(modeSpan);
            var dotSpan = document.createElement('span');
            dotSpan.className = 'in-accent-dot';
            dotSpan.textContent = '.';
            marks[i].appendChild(dotSpan);
        }
    };

    /* ── Chips ────────────────────────────────────────────────────────────── */
    SkinController.prototype.renderChips = function () {
        var hosts = [this.hooks.chips, this.target.querySelector('[data-innsight-list-chips]')].filter(Boolean);
        var self = this;
        hosts.forEach(function (host) {
            host.innerHTML = '';
            self.categories.forEach(function (cat) {
                var btn = document.createElement('button');
                btn.className = 'in-chip';
                btn.type = 'button';
                btn.setAttribute('role', 'tab');
                btn.setAttribute('data-cat', cat.id);
                btn.setAttribute('aria-pressed', cat.id === self.cat ? 'true' : 'false');
                if (cat.id !== 'all') {
                    var dot = document.createElement('span');
                    dot.className = 'in-chip__dot';
                    dot.style.background = cat.color;
                    btn.appendChild(dot);
                }
                btn.appendChild(document.createTextNode(cat.label));
                btn.addEventListener('click', function () { self.setCategory(cat.id); });
                host.appendChild(btn);
            });
        });
    };

    /* ── Chrome wiring (search, profile) ─────────────────────────────────── */
    SkinController.prototype.bindChrome = function () {
        var self = this;
        var inputs = [this.hooks.search, this.target.querySelector('[data-innsight-list-search]')].filter(Boolean);
        var debounced = debounce(function (value) { self.setQuery(value); }, 120);
        inputs.forEach(function (input) {
            input.addEventListener('input', function (e) { debounced(e.target.value || ''); });
        });
    };

    function debounce(fn, ms) {
        var t;
        return function (v) { clearTimeout(t); t = setTimeout(function () { fn(v); }, ms); };
    }

    /* ── Map controls (fullscreen, zoom +/-) ─────────────────────────────── */
    SkinController.prototype.bindMapControls = function () {
        var self = this;
        var fs = this.target.querySelector('[data-innsight-fullscreen]');
        var zin = this.target.querySelector('[data-innsight-zoom-in]');
        var zout = this.target.querySelector('[data-innsight-zoom-out]');
        if (fs) fs.addEventListener('click', function () { self.toggleFullscreen(); });
        if (zin) zin.addEventListener('click', function () { self.nudgeZoom(+1); });
        if (zout) zout.addEventListener('click', function () { self.nudgeZoom(-1); });
    };

    SkinController.prototype.toggleFullscreen = function () {
        this.fullscreen = !this.fullscreen;
        this.app.classList.toggle('is-fullscreen', this.fullscreen);

        // Fullscreen ONLY the .in-app element. The browser hides the host
        // page (WP header, footer, content) behind it; the user sees the
        // entire Innsight UI (chrome + map + chips + sheet + tab bar) at
        // viewport size with no spacing. Previously we requested fullscreen
        // on the top document which dragged the whole site into the FS view.
        try {
            var el = this.app;
            var doc = root.document;
            if (this.fullscreen) {
                var req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
                if (req) req.call(el);
            } else {
                var ex = doc.exitFullscreen || doc.webkitExitFullscreen || doc.msExitFullscreen;
                if (ex && (doc.fullscreenElement || doc.webkitFullscreenElement)) ex.call(doc);
            }
        } catch (e) { /* CSS class still applies */ }

        // Map sometimes needs a resize after the chrome collapses.
        var p = this.state.provider;
        if (p && p.invalidateSize) setTimeout(function () { p.invalidateSize(); }, 240);
    };

    /* Sync local state when the user exits fullscreen via Escape (or the
     * browser's own gesture). Without this the .is-fullscreen class would
     * stay applied and the next click on the FS button would try to
     * re-enter when we're already out. */
    SkinController.prototype.bindFullscreenSync = function () {
        var self = this;
        var handler = function () {
            var doc = root.document;
            var inFs = !!(doc.fullscreenElement || doc.webkitFullscreenElement);
            if (inFs !== self.fullscreen) {
                self.fullscreen = inFs;
                self.app.classList.toggle('is-fullscreen', inFs);
                var p = self.state.provider;
                if (p && p.invalidateSize) setTimeout(function () { p.invalidateSize(); }, 240);
            }
        };
        document.addEventListener('fullscreenchange', handler);
        document.addEventListener('webkitfullscreenchange', handler);
    };

    SkinController.prototype.nudgeZoom = function (dir) {
        var p = this.state.provider;
        if (!p || !p.native) return;
        // Mapbox GL: native.zoomIn / zoomOut. Leaflet: same names.
        if (dir > 0 && p.native.zoomIn) p.native.zoomIn();
        else if (dir < 0 && p.native.zoomOut) p.native.zoomOut();
    };

    /* ── Tabs ─────────────────────────────────────────────────────────────── */
    SkinController.prototype.bindTabs = function () {
        var self = this;
        var tabs = this.target.querySelectorAll('[data-innsight-tabs] .in-tab');
        for (var i = 0; i < tabs.length; i++) {
            (function (tab) {
                tab.addEventListener('click', function () { self.setRoute(tab.getAttribute('data-route')); });
            })(tabs[i]);
        }
    };

    SkinController.prototype.setRoute = function (route) {
        this.route = route;
        var tabs = this.target.querySelectorAll('[data-innsight-tabs] .in-tab');
        for (var i = 0; i < tabs.length; i++) {
            var on = tabs[i].getAttribute('data-route') === route;
            tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
        }
        this.app.classList.remove('is-route-map', 'is-route-list', 'is-route-save', 'is-route-me');
        this.app.classList.add('is-route-' + route);

        if (route === 'list') this.renderList();
        if (route === 'save') this.renderSaved();
        if (route === 'me')   this.renderEmpty('You', "Your in-the-know profile.");

        // Closing the map screen also dismisses any open sheet so the user is
        // not stranded with map content visible behind list/saved/you.
        if (route !== 'map' && this.sheet.poi) this.closeSheet();

        if (route === 'map' && this.state.provider && this.state.provider.invalidateSize) {
            setTimeout(this.state.provider.invalidateSize.bind(this.state.provider), 320);
        }
    };

    /* ── Filter (cat + query) ─────────────────────────────────────────────── */
    SkinController.prototype.setCategory = function (cat) {
        this.cat = cat;
        // Reflect in chip aria-pressed
        var chips = this.target.querySelectorAll('[data-cat]');
        for (var i = 0; i < chips.length; i++) {
            chips[i].setAttribute('aria-pressed', chips[i].getAttribute('data-cat') === cat ? 'true' : 'false');
        }
        // Engine filter: show all clusters and let the engine substring match;
        // for cat we filter the visible markers' types directly. Skip the
        // engine filter for the virtual '__shared' category - we keep the
        // map showing all POIs in that mode and let the list-view scope to
        // the friend's picks.
        if (cat !== '__shared' && this.state.instance && this.state.instance.filter) {
            this.state.instance.filter({ cat: cat, query: this.query });
        }
        if (this.route === 'list') this.renderList();
        this.updateCounts();
    };

    SkinController.prototype.setQuery = function (q) {
        this.query = q;
        if (this.state.instance && this.state.instance.filter) {
            this.state.instance.filter({ cat: this.cat, query: q });
        }
        if (this.route === 'list') this.renderList();
        this.updateCounts();
    };

    SkinController.prototype.visiblePois = function () {
        var pois = this.cfg.pois || [];
        var cat = this.cat;
        var q = (this.query || '').toLowerCase();
        // Virtual '__shared' category: filter to the friend's shared picks
        // when present. Falls back to all POIs if the session has no picks.
        if (cat === '__shared') {
            var picks = this.readSharedPicks();
            if (!picks || !picks.length) return [];
            var liveById = {};
            for (var i = 0; i < pois.length; i++) liveById[ String(pois[i].id) ] = pois[i];
            return picks.map(function (p) {
                var live = liveById[ String(p.id) ];
                if (live) {
                    // Don't mutate the live POI - shallow-copy so we can
                    // overlay the friend's note without affecting cfg.pois.
                    if (!p.note) return live;
                    var copy = {};
                    for (var k in live) if (Object.prototype.hasOwnProperty.call(live, k)) copy[k] = live[k];
                    copy.note = p.note;
                    return copy;
                }
                return {
                    id: p.id, title: p.title, name: p.title,
                    cat: p.cat, type: p.type,
                    lat: p.lat, lon: p.lon,
                    image: p.image || '', imageThumb: p.imageThumb || '',
                    rating: p.rating, tag: p.tag || '',
                    button: p.button || { url: '', text: '' },
                    open: true, __synthetic: true,
                    note: p.note || ''
                };
            }).filter(function (p) {
                return !q || (p.title || p.name || '').toLowerCase().indexOf(q) !== -1;
            });
        }
        return pois.filter(function (p) {
            if (cat !== 'all' && p.cat !== cat && p.type !== cat) return false;
            if (q && (p.title || p.name || '').toLowerCase().indexOf(q) === -1) return false;
            return true;
        });
    };

    SkinController.prototype.updateCounts = function () {
        var n = this.visiblePois().length;
        var counts = this.target.querySelectorAll('[data-innsight-count]');
        for (var i = 0; i < counts.length; i++) counts[i].textContent = n;
        var listCount = this.target.querySelector('[data-innsight-list-count]');
        if (listCount) listCount.textContent = n;
        var listResults = this.target.querySelector('[data-innsight-list-results]');
        if (listResults) listResults.textContent = n + (n === 1 ? ' result' : ' results');
    };

    /* ── Engine events ───────────────────────────────────────────────────── */
    SkinController.prototype.bindEngineEvents = function () {
        var self = this;
        if (!this.state.events) return;
        this.state.events.on('marker:click', function (poi) { self.openSheet(poi); });
    };

    /* ── Bottom sheet ────────────────────────────────────────────────────── */
    /**
     * Opens the bottom sheet for a POI. The optional `opts.context` field
     * tells the sheet which list to cycle through when the user swipes left
     * or right:
     *   - 'map'    (default) - POIs sharing the same category/type
     *   - 'list'   - the currently filtered + sorted list view
     *   - 'saved'  - the user's saved POIs only (kept tab)
     *   - 'shared' - a friend's shared wishlist (after Preview choice)
     * Defaults to 'map' so opening from a marker keeps today's behaviour.
     */
    SkinController.prototype.openSheet = function (poi, opts) {
        this.sheet.poi = poi;
        this.sheet.drag = 0;
        this.sheet.context = (opts && opts.context) || 'map';
        // Class drives the notes-peek visibility (CSS hides it by default,
        // shows it only when .in-app.is-poi-saved is on). Keeps the DOM
        // stable across save/unsave taps - no sheet re-render needed.
        this.app.classList.toggle('is-poi-saved', this.isPoiSaved(poi));
        this.renderSheet();
        this.app.classList.add('is-sheet-open');
        // Lock the document scroll so iOS Safari + Chrome Android don't fire
        // their native pull-to-refresh while the user is dragging the sheet.
        if (root.document && root.document.body) {
            root.document.body.classList.add('innsight-sheet-locked');
        }
        this.maybePlayHint();
        // On-demand Google Places enrichment. Fires only when the user
        // opens this POI's sheet (caches per POI in localStorage so a re-
        // open is free). Re-renders the sheet when the data lands.
        this.requestEnrichment(poi);
    };

    /**
     * Ask the engine to fetch Places data for this POI. Merge selected
     * fields back into the POI in-place so re-renders use the enriched
     * values, then re-render if the sheet is still showing the same POI.
     *
     * "In-view" trigger: called from openSheet (marker / list / saved row
     * click) and from navigateSheet (left/right swipe to prev/next POI).
     * One network request per POI per session - the engine's localStorage
     * cache short-circuits repeat calls. Pending-state UI is owned by
     * renderSheet via the `enrichLoading` context flag.
     */
    SkinController.prototype.requestEnrichment = function (poi) {
        if (!this.state.instance || !this.state.instance.enrichPoi || !poi) return;
        var self = this;
        var id = String(poi.id);
        this.state.instance.enrichPoi(poi).then(function (data) {
            self.enrichSeen[id] = true;
            if (!data) {
                // Still re-render so the placeholder line disappears even
                // when Places had no record for this POI.
                if (self.sheet.poi && String(self.sheet.poi.id) === id) {
                    self.renderSheet();
                }
                return;
            }
            // Map Places fields onto the POI shape the template renders.
            if (data.rating != null)          poi.rating = data.rating;
            if (data.userRatingCount != null) poi.userRatingCount = data.userRatingCount;
            if (data.openNow != null)         poi.open = data.openNow;
            if (data.todaysHours)             poi.hours = data.todaysHours;
            if (data.googleMapsUri)           poi.googleMapsUri = data.googleMapsUri;
            if (data.websiteUri && poi.button && !poi.button.url) {
                poi.button.url = data.websiteUri;
                if (!poi.button.text) poi.button.text = 'Website';
            }
            if (data.photoUrl && !poi.image) {
                poi.image = data.photoUrl;
                poi.imageThumb = data.photoUrl;
            }
            poi.places = data; // full payload available to templates that want it
            // Only re-render if the sheet is still on this POI (user may
            // have already swiped to the next one).
            if (self.sheet.poi && String(self.sheet.poi.id) === id) {
                self.renderSheet();
            }
        });
    };

    /**
     * True when this POI is missing one of the typically-enriched fields
     * (rating, hours, open status) AND enrichment is configured AND we
     * haven't already resolved it this session. Drives the placeholder UI
     * inside the sheet.
     */
    SkinController.prototype.enrichmentPendingFor = function (poi) {
        var conf = this.cfg.enrichment && this.cfg.enrichment.google;
        if (!conf || !conf.apiKey || !poi) return false;
        if (this.enrichSeen[ String(poi.id) ]) return false;
        // If the POI already carries any enriched field we treat it as "no
        // need to ask Google" - the placeholder would flash unnecessarily.
        if (poi.places || poi.rating != null || poi.hours || poi.open != null) return false;
        return true;
    };
    SkinController.prototype.closeSheet = function () {
        // Collapse the notes pull-up first so it doesn't linger behind the
        // sheet while the sheet animates away.
        this.app.classList.remove('is-notes-open');
        var notesPanel = this.target.querySelector('[data-innsight-notes-panel]');
        if (notesPanel) notesPanel.setAttribute('aria-hidden', 'true');

        this.app.classList.remove('is-sheet-open');
        if (root.document && root.document.body) {
            root.document.body.classList.remove('innsight-sheet-locked');
        }
        var self = this;
        setTimeout(function () { self.sheet.poi = null; }, 280);
    };

    SkinController.prototype.renderSheet = function () {
        var poi = this.sheet.poi;
        if (!poi) return;
        var inner = this.target.querySelector('[data-innsight-sheet-inner]');
        if (!inner) return;
        var ctx = this.buildSheetContext(poi);
        inner.innerHTML = Innsight._template.render(this.state.partials.sheet || '', ctx);
        // Wire sheet controls.
        var self = this;
        var closeBtns = inner.querySelectorAll('[data-innsight-sheet-close]');
        for (var i = 0; i < closeBtns.length; i++) closeBtns[i].addEventListener('click', function () { self.closeSheet(); });
        var prev = inner.querySelector('[data-innsight-sheet-prev]');
        var next = inner.querySelector('[data-innsight-sheet-next]');
        if (prev) prev.addEventListener('click', function () { self.navigateSheet(-1); });
        if (next) next.addEventListener('click', function () { self.navigateSheet(+1); });
        // Save button. Click toggles the POI's saved state in localStorage,
        // updates the button visual, and shows a toast. Re-opening the sheet
        // reflects the persisted state. Hosts can listen for 'sheet:save' to
        // sync to a remote backend if needed.
        var saveBtn = inner.querySelector('[data-innsight-sheet-save]');
        if (saveBtn) {
            // Reflect the persisted state on initial render.
            if (this.isPoiSaved(poi)) {
                saveBtn.classList.add('is-saved');
                saveBtn.textContent = 'Saved ✓';
            }
            saveBtn.addEventListener('click', function () { self.toggleSavedPoi(poi, saveBtn); });
        }
        // Tap on the accent-coloured note preview opens the full notes
        // editor. The preview is only rendered when the POI has a note.
        var notePreview = inner.querySelector('[data-innsight-note-preview]');
        if (notePreview) {
            notePreview.addEventListener('click', function () { self.openNotesPanel(); });
        }
        // Bind swipe.
        this.bindSheetSwipe(inner);
        // Bind the personal-note pull-up. Needs the freshly-rendered DOM
        // because the peek strip is inside the sheet template.
        this.bindNotesPanel(inner);
    };

    /* ── Save / localStorage / toast ─────────────────────────────────────── */
    var SAVED_KEY = 'innsight.savedPois';
    var SAVED_TTL_MS = 30 * 24 * 60 * 60 * 1000;  // 30 days

    /**
     * Raw read - returns the storage object as-is. Used by isPoiSaved
     * for a cheap "is this id in there" check; the heavy filtering +
     * auto-purge happens in readSavedList.
     */
    SkinController.prototype.readSaved = function () {
        try { return JSON.parse(root.localStorage.getItem(SAVED_KEY) || '{}') || {}; }
        catch (e) { return {}; }
    };

    SkinController.prototype.writeSaved = function (obj) {
        try { root.localStorage.setItem(SAVED_KEY, JSON.stringify(obj || {})); } catch (e) {}
    };

    SkinController.prototype.isPoiSaved = function (poi) {
        var saved = this.readSaved();
        var entry = poi && saved[ String(poi.id) ];
        if (!entry) return false;
        // Stale entries past TTL are considered "not saved" - they'll be
        // purged the next time the Saved tab opens.
        return (Date.now() - (entry.savedAt || 0)) <= SAVED_TTL_MS;
    };

    /**
     * Returns the saved POIs as a freshly-purged array sorted most-
     * recent-first. Persists the auto-purge so the next read is cheap.
     * Each entry carries enough fields (lat/lon/image/cat/type) to
     * render the saved row WITHOUT looking up the original POI - so
     * deleting a POI from the WP DB doesn't break the saved view.
     */
    SkinController.prototype.readSavedList = function () {
        var saved = this.readSaved();
        var now = Date.now();
        var fresh = {};
        var list = [];
        var purged = false;
        Object.keys(saved).forEach(function (id) {
            var entry = saved[id];
            if (!entry || !entry.savedAt) { purged = true; return; }
            if (now - entry.savedAt > SAVED_TTL_MS) { purged = true; return; }
            fresh[id] = entry;
            list.push(entry);
        });
        if (purged) this.writeSaved(fresh);
        list.sort(function (a, b) { return (b.savedAt || 0) - (a.savedAt || 0); });
        return list;
    };

    SkinController.prototype.toggleSavedPoi = function (poi, btn) {
        var saved = this.readSaved();
        var id = String(poi.id);
        if (saved[id] && (Date.now() - (saved[id].savedAt || 0)) <= SAVED_TTL_MS) {
            delete saved[id];
            this.writeSaved(saved);
            if (btn) { btn.classList.remove('is-saved'); btn.textContent = 'Save'; }
            // Hide the notes peek the moment the POI is unsaved.
            this.app.classList.remove('is-poi-saved');
            this.showToast('Removed from saved');
            this.state.events.emit('sheet:save', { poi: poi, saved: false });
        } else {
            // Self-contained snapshot: enough to render the saved row +
            // re-open the sheet later, even if the POI is later removed
            // from the live data.
            saved[id] = {
                id:         poi.id,
                title:      poi.title || poi.name || '',
                cat:        poi.cat || poi.type || '',
                type:       poi.type || '',
                lat:        Number(poi.lat),
                lon:        Number(poi.lon),
                image:      poi.image || '',
                imageThumb: poi.imageThumb || poi.image || '',
                rating:     poi.rating != null ? Number(poi.rating) : null,
                tag:        poi.tag || '',
                blurb:      poi.blurb || poi.description || '',
                description:poi.description || '',
                button:     poi.button ? { url: poi.button.url || '', text: poi.button.text || '' } : { url: '', text: '' },
                savedAt:    Date.now()
            };
            this.writeSaved(saved);
            if (btn) { btn.classList.add('is-saved'); btn.textContent = 'Saved ✓'; }
            // Reveal the notes peek now that the POI is kept.
            this.app.classList.add('is-poi-saved');
            this.showToast('Saved!');
            this.state.events.emit('sheet:save', { poi: poi, saved: true });
        }
        // If we're currently on the Saved tab, re-render it so the row
        // appears/disappears immediately.
        if (this.route === 'save') this.renderSaved();
    };

    SkinController.prototype.showToast = function (message) {
        // Remove any existing toast so rapid clicks don't stack them.
        var existing = this.target.querySelectorAll('.in-toast');
        for (var i = 0; i < existing.length; i++) existing[i].parentNode.removeChild(existing[i]);

        var toast = document.createElement('div');
        toast.className = 'in-toast';
        toast.textContent = message;
        this.target.appendChild(toast);
        // Force layout so the transition fires (next paint).
        // eslint-disable-next-line no-unused-expressions
        toast.offsetWidth;
        toast.classList.add('is-visible');
        setTimeout(function () {
            toast.classList.remove('is-visible');
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 240);
        }, 1800);
    };

    SkinController.prototype.buildSheetContext = function (poi) {
        var palette = (this.cfg.branding && this.cfg.branding.stickerColors) ||
            ['#FFB85C', '#FF6B3D', '#C9F73F', '#6BB7FF', '#FF4D8F', '#B07AFF', '#FFD93D', '#5EE2A8'];
        var hash = 0;
        var id = String(poi.id || poi.title || '');
        for (var i = 0; i < id.length; i++) hash = (hash + id.charCodeAt(i)) % 1e9;
        var color = palette[hash % palette.length];
        var siblings = this.siblingsFor(poi);
        var idx = siblings.findIndex(function (s) { return s.id === poi.id; });
        var n = siblings.length;
        var prev = n > 1 ? siblings[(idx - 1 + n) % n] : null;
        var next = n > 1 ? siblings[(idx + 1) % n] : null;
        var cat = this.catById[poi.cat] || this.catById[poi.type] || { label: '', color: '#0F0F0F' };
        var tags = (poi.tag || '').split('·').map(function (s) { return s.trim(); }).filter(Boolean);
        var firstTag = tags[0] || '';
        // Format the rating chip: bare "4.5" if no review count yet, else
        // "4.5 (123)" once Places enrichment lands.
        var ratingNum = poi.rating != null ? Number(poi.rating) : null;
        var ratingLabel = '';
        if (ratingNum != null && !isNaN(ratingNum)) {
            ratingLabel = ratingNum.toFixed(1);
            if (poi.userRatingCount) {
                ratingLabel += ' (' + this.formatCount(poi.userRatingCount) + ')';
            }
        }
        var ctx = {
            id: poi.id, title: poi.title || poi.name || '',
            initial: (poi.title || poi.name || '·').charAt(0).toUpperCase(),
            initial_lc: (poi.title || poi.name || '·').charAt(0).toLowerCase(),
            stickerColor: color,
            categoryColor: cat.color, categoryLabel: cat.label,
            firstTag: firstTag,
            vibeTags: tags.map(function (t) { return { value: t }; }),
            image: poi.image || '',
            description: poi.description || '',
            blurb: poi.blurb || poi.description || '',
            hours: poi.hours || '',
            dist: poi.dist || '',
            rating: ratingNum != null ? ratingNum.toFixed(1) : '',
            ratingLabel: ratingLabel,
            googleMapsUri: poi.googleMapsUri || '',
            open: poi.open ? 'true' : 'false',
            openLabel: poi.open ? 'Open now' : 'Closed',
            button: { url: (poi.button && poi.button.url) || '', text: (poi.button && poi.button.text) || 'Directions' }
        };
        // Counter + prev/next names (rendered through extra string interpolation)
        var counter = (n > 1 ? (idx + 1) + ' / ' + n + ' · ' + cat.label : '');
        // Attach as fields the template can render.
        ctx.counter = counter;
        ctx.prevName = prev ? (prev.title || prev.name || '') : '';
        ctx.nextName = next ? (next.title || next.name || '') : '';
        // Loading-placeholder flag: true while a Places request for this POI
        // is in-flight (or pending). The template renders a small "Looking
        // up live details…" status row + a placeholder rating chip while
        // this is on. Re-render on enrichment resolve clears it.
        ctx.enrichLoading = this.enrichmentPendingFor(poi);
        // Personal note (own or carried by a friend's shared pick). The
        // template renders a clickable accent-coloured preview above the
        // actions when this is set; tap opens the full notes editor.
        ctx.noteText = this.getNote(poi.id) || poi.note || '';
        ctx.hasNote = !!ctx.noteText;
        // Stash for navigation.
        this.sheet.siblings = siblings;
        this.sheet.idx = idx;
        return ctx;
    };

    /**
     * The POIs swipe-left / swipe-right cycles through. Depends on the sheet
     * context (set by openSheet):
     *   - 'saved'  - the readSavedList() snapshot (kept tab swipe)
     *   - 'shared' - the active shared wishlist in sessionStorage
     *   - 'list'   - the currently filtered + sorted list view
     *   - 'map'    - POIs sharing the same category/type (default)
     * Synthesizes minimal POI objects from snapshots when the live POI is
     * gone (deleted from WP DB), so swipe still works on stale references.
     */
    SkinController.prototype.siblingsFor = function (poi) {
        var ctx = (this.sheet && this.sheet.context) || 'map';
        var self = this;
        var live = this.cfg.pois || [];
        var liveById = {};
        for (var i = 0; i < live.length; i++) liveById[ String(live[i].id) ] = live[i];
        function fromEntry(entry) {
            // Prefer the live POI (full description, fresh fields) when it
            // still exists; otherwise rebuild a minimal POI from the saved
            // snapshot so the sheet renders.
            if (entry && liveById[ String(entry.id) ]) return liveById[ String(entry.id) ];
            if (!entry) return null;
            return {
                id: entry.id, title: entry.title, name: entry.title,
                cat: entry.cat, type: entry.type,
                lat: entry.lat, lon: entry.lon,
                image: entry.image, imageThumb: entry.imageThumb,
                rating: entry.rating, tag: entry.tag,
                blurb: entry.blurb, description: entry.description,
                button: entry.button || { url: '', text: '' },
                __synthetic: true
            };
        }
        if (ctx === 'saved') {
            return self.readSavedList().map(fromEntry).filter(Boolean);
        }
        if (ctx === 'shared') {
            return (self.readSharedPicks() || []).map(fromEntry).filter(Boolean);
        }
        if (ctx === 'list') {
            return self.sortPois( self.visiblePois() );
        }
        // 'map' / default: same-category siblings.
        return live.filter(function (p) { return (p.cat || p.type) === (poi.cat || poi.type); });
    };

    SkinController.prototype.navigateSheet = function (dir) {
        var sib = this.sheet.siblings || [];
        if (sib.length < 2) return;
        var idx = (this.sheet.idx + dir + sib.length) % sib.length;
        recordSwipeUsed();
        this.sheet.poi = sib[idx];
        this.renderSheet();
        // Treat swipe as an "in-view" event for the new POI: trigger Places
        // enrichment if we haven't already resolved this id this session.
        // The cache short-circuits a real network call when we have one.
        this.requestEnrichment(this.sheet.poi);
    };

    /**
     * Three coexisting gestures on the sheet:
     *   - Horizontal swipe (anywhere) -> prev/next POI in category
     *   - Vertical drag DOWN from the top zone (grabber + nav row) -> close
     *   - Native scroll (vertical, below the top zone) -> scrolls sheet content
     * The mode is decided on the first move past an 8px threshold and stays
     * locked for the rest of that gesture. Buttons / links cancel the
     * gesture entirely so taps still fire.
     */
    SkinController.prototype.bindSheetSwipe = function (inner) {
        var self = this;
        var startX = 0, startY = 0, dx = 0, dy = 0, mode = null, active = false, topZone = false;
        var TOP_ZONE_PX = 90;
        var HORIZ_THRESHOLD = 70;
        var VERT_CLOSE_THRESHOLD = 110;

        function onDown(e) {
            if (e.target.closest('button, a, input, select, textarea')) return;
            startX = e.clientX; startY = e.clientY;
            dx = 0; dy = 0;
            mode = null;
            active = true;
            // "Top zone" = first ~90px of the sheet (grabber + nav row).
            // Only vertical drags STARTING here close the sheet; below this,
            // vertical scroll is native (the content area can scroll).
            var rect = inner.getBoundingClientRect();
            topZone = (e.clientY - rect.top) < TOP_ZONE_PX;
            inner.classList.remove('is-hint');
            // Remove any in-progress snap-back transition so the drag tracks
            // the finger instantly.
            inner.style.transition = 'none';
            try { inner.setPointerCapture(e.pointerId); } catch (er) {}
        }
        function onMove(e) {
            if (!active) return;
            dx = e.clientX - startX;
            dy = e.clientY - startY;
            if (mode === null) {
                if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
                if (Math.abs(dx) > Math.abs(dy)) mode = 'h';
                else if (dy > 0 && topZone) mode = 'v';
                else mode = 'native';
            }
            if (mode === 'h') {
                if (e.cancelable) e.preventDefault();
                inner.style.transform = 'translateX(' + dx + 'px)';
                inner.style.opacity = Math.max(0.4, 1 - Math.abs(dx) / 320);
            } else if (mode === 'v') {
                if (e.cancelable) e.preventDefault();
                var down = Math.max(0, dy);
                inner.style.transform = 'translateY(' + down + 'px)';
                inner.style.opacity = Math.max(0.5, 1 - down / 600);
            }
            // 'native' -> let the browser scroll the inner naturally.
        }
        function onUp(e) {
            if (!active) return;
            active = false;
            try { inner.releasePointerCapture(e.pointerId); } catch (er) {}
            // Restore the sheet's standard return transition.
            inner.style.transition = '';
            if (mode === 'h') {
                inner.style.transform = '';
                inner.style.opacity = '';
                if (dx < -HORIZ_THRESHOLD) self.navigateSheet(+1);
                else if (dx > HORIZ_THRESHOLD) self.navigateSheet(-1);
            } else if (mode === 'v') {
                if (dy > VERT_CLOSE_THRESHOLD) {
                    // Past the threshold: animate the rest of the way down
                    // and close. Use closeSheet's own slide-out for the
                    // backdrop fade.
                    self.closeSheet();
                    // closeSheet animates the whole .in-sheet wrapper; clear
                    // the local inline transform so the wrapper's transition
                    // is what the user sees.
                    inner.style.transform = '';
                    inner.style.opacity = '';
                } else {
                    inner.style.transform = '';
                    inner.style.opacity = '';
                }
            }
            mode = null;
        }
        inner.addEventListener('pointerdown', onDown);
        inner.addEventListener('pointermove', onMove);
        inner.addEventListener('pointerup', onUp);
        inner.addEventListener('pointercancel', onUp);
    };

    SkinController.prototype.maybePlayHint = function () {
        var self = this;
        var sib = this.sheet.siblings || [];
        if (this.sheet.hintShown || sib.length < 2 || !shouldShowHint()) return;
        this.sheet.hintShown = true;
        recordHintShown();
        var inner = this.target.querySelector('[data-innsight-sheet-inner]');
        if (!inner) return;
        clearTimeout(this.sheet.hintTimer);
        this.sheet.hintTimer = setTimeout(function () {
            inner.classList.add('is-hint');
            setTimeout(function () { inner.classList.remove('is-hint'); }, 2200);
        }, 380);
    };

    SkinController.prototype.bindSheetBackdrop = function () {
        var self = this;
        var bd = this.target.querySelector('[data-innsight-sheet-backdrop]');
        if (bd) bd.addEventListener('click', function () { self.closeSheet(); });
    };

    /* ── List view rendering ─────────────────────────────────────────────── */
    SkinController.prototype.renderList = function () {
        var host = this.target.querySelector('[data-innsight-list]');
        if (!host) return;
        var pois = this.sortPois( this.visiblePois() );
        var template = this.state.partials.listRow || this.state.partials.listItem || '';
        if (!template) return;
        if (pois.length === 0) {
            host.innerHTML = '<div class="in-list__empty">Nothing matches that yet.</div>';
            return;
        }
        var palette = (this.cfg.branding && this.cfg.branding.stickerColors) ||
            ['#FFB85C', '#FF6B3D', '#C9F73F', '#6BB7FF', '#FF4D8F', '#B07AFF', '#FFD93D', '#5EE2A8'];
        var self = this;
        var html = pois.map(function (p, i) {
            var hash = 0, id = String(p.id || p.title || '');
            for (var k = 0; k < id.length; k++) hash = (hash + id.charCodeAt(k)) % 1e9;
            var color = palette[hash % palette.length];
            var cat = self.catById[p.cat] || self.catById[p.type] || { label: '', color: '#0F0F0F' };
            // Prefer the smaller image variant for the 56px sticker. Falls
            // back to the full-size `image` if the plugin didn't ship one.
            var thumb = p.imageThumb || p.image || '';
            // Note text: prefer the recipient's own saved note, fall back
            // to a friend's-shared note carried on the synthesized POI
            // (poi.note exists only when this row came from a preview-
            // shared pick).
            var note = self.getNote(p.id) || p.note || '';
            var ctx = {
                id: p.id,
                title: p.title || p.name || '',
                initial: (p.title || p.name || '·').charAt(0).toUpperCase(),
                stickerColor: color,
                categoryColor: cat.color,
                categoryLabel: cat.label,
                tag: p.tag || '',
                dist: p.dist || '',
                distFromHostel: self.formatDist(p),
                image: p.image || '',
                imageThumb: thumb,
                rating: p.rating != null ? Number(p.rating).toFixed(1) : '',
                open: p.open ? 'true' : 'false',
                openShort: p.open ? 'Open' : 'Closed',
                note: note,
                __index: i
            };
            return Innsight._template.render(template, ctx);
        }).join('');
        host.innerHTML = html;
        // Wire row clicks.
        var rows = host.querySelectorAll('[data-innsight-list-row]');
        for (var i = 0; i < rows.length; i++) {
            (function (row) {
                row.addEventListener('click', function () {
                    var id = row.getAttribute('data-poi-id');
                    var poi = (self.cfg.pois || []).find(function (p) { return String(p.id) === id; });
                    if (poi) self.openSheet(poi, { context: 'list' });
                });
            })(rows[i]);
        }
    };

    /* ── Empty states (Saved / You) ──────────────────────────────────────── */
    SkinController.prototype.renderEmpty = function (title, body) {
        var host = this.target.querySelector('[data-innsight-empty]');
        if (!host) return;
        var template = this.state.partials.emptyState || '';
        if (!template) {
            host.innerHTML = '<h2>' + title + '</h2><p>' + body + '</p>';
            return;
        }
        host.innerHTML = Innsight._template.render(template, {
            title: title, body: body,
            initial: title.charAt(0).toLowerCase()
        });
    };

    /* ── Saved tab rendering ─────────────────────────────────────────────── */

    /**
     * Render the Saved tab. Uses the same list-row template as the List
     * tab so the visual stays consistent. Falls back to an inline empty
     * state when nothing is saved (no need for a separate template).
     * Also updates the count + meta line in the head.
     */
    SkinController.prototype.renderSaved = function () {
        var host        = this.target.querySelector('[data-innsight-saved]');
        var countEl     = this.target.querySelector('[data-innsight-saved-count]');
        var metaEl      = this.target.querySelector('[data-innsight-saved-meta]');
        if (!host) return;

        var savedList = this.readSavedList();
        if (countEl) countEl.textContent = savedList.length;
        if (metaEl)  metaEl.textContent  = savedList.length + (savedList.length === 1 ? ' spot' : ' spots') + ' · expires after 30 days';

        // Reveal the Share button only when there's something to share AND
        // the share feature isn't disabled in plugin settings.
        var shareBtn = this.target.querySelector('[data-innsight-share-open]');
        if (shareBtn) {
            var shareEnabled = this.shareConfig().enabled;
            shareBtn.hidden = !shareEnabled || savedList.length === 0;
        }

        if (savedList.length === 0) {
            host.innerHTML = '<div class="in-empty" style="padding:60px 12px 0;text-align:center">'
                + '<div class="in-empty__tile" aria-hidden="true">s</div>'
                + '<h2 class="in-empty__title">Nothing saved yet</h2>'
                + '<p class="in-empty__body">Tap <strong>Save</strong> on any spot to keep it here for 30 days.</p>'
                + '</div>';
            return;
        }

        var template = this.state.partials.listRow || this.state.partials.listItem || '';
        if (!template) return;
        var palette = (this.cfg.branding && this.cfg.branding.stickerColors) ||
            ['#FFB85C', '#FF6B3D', '#C9F73F', '#6BB7FF', '#FF4D8F', '#B07AFF', '#FFD93D', '#5EE2A8'];
        var self = this;

        var html = savedList.map(function (entry, i) {
            var hash = 0, id = String(entry.id || entry.title || '');
            for (var k = 0; k < id.length; k++) hash = (hash + id.charCodeAt(k)) % 1e9;
            var color = palette[hash % palette.length];
            var cat = self.catById[entry.cat] || self.catById[entry.type] || { label: '', color: '#0F0F0F' };
            // Surface the original POI (if still in the live data) just for
            // distance computation; otherwise we have the saved lat/lon.
            var distFromHostel = '';
            if (self.hostelRef) {
                var poiForDist = { lat: entry.lat, lon: entry.lon, pinned: false };
                self.computeDistanceFor(poiForDist);
                distFromHostel = self.formatDist(poiForDist);
            }
            return Innsight._template.render(template, {
                id:             entry.id,
                title:          entry.title || '',
                initial:        (entry.title || '·').charAt(0).toUpperCase(),
                stickerColor:   color,
                categoryColor:  cat.color,
                categoryLabel:  cat.label,
                tag:            entry.tag || '',
                dist:           '',
                distFromHostel: distFromHostel,
                image:          entry.image || '',
                imageThumb:     entry.imageThumb || entry.image || '',
                rating:         entry.rating != null ? Number(entry.rating).toFixed(1) : '',
                open:           '',
                openShort:      '',
                note:           self.getNote(entry.id) || '',
                __index:        i
            });
        }).join('');
        host.innerHTML = html;

        // Wire taps -> open the sheet. Look up the live POI by id; if it
        // no longer exists, synthesize a minimal POI from the saved entry
        // so the sheet still opens. Also inject an X "remove" affordance
        // per row so the user can unsave directly without opening the
        // sheet first.
        var rows = host.querySelectorAll('[data-innsight-list-row]');
        for (var i = 0; i < rows.length; i++) {
            (function (row) {
                // Append X. Use a <span role="button"> not <button> to
                // avoid invalid nested-button HTML (the row itself is a
                // <button>). stopPropagation prevents the row click from
                // also firing.
                var x = document.createElement('span');
                x.className = 'in-row__remove';
                x.setAttribute('role', 'button');
                x.setAttribute('tabindex', '-1');
                x.setAttribute('aria-label', 'Remove from saved');
                x.textContent = '×';
                var unsave = function (e) {
                    e.stopPropagation();
                    e.preventDefault();
                    var id = row.getAttribute('data-poi-id');
                    var live = (self.cfg.pois || []).find(function (p) { return String(p.id) === id; });
                    var entry = self.readSaved()[id];
                    var poi = live || (entry ? {
                        id: entry.id, title: entry.title,
                        cat: entry.cat, type: entry.type,
                        lat: entry.lat, lon: entry.lon
                    } : { id: id });
                    // toggleSavedPoi will detect the entry is currently
                    // saved (it is) and remove it. Our re-render call
                    // inside toggleSavedPoi (route==='save' branch) then
                    // refreshes the list without this row.
                    self.toggleSavedPoi(poi);
                };
                x.addEventListener('click', unsave);
                row.appendChild(x);

                row.addEventListener('click', function (e) {
                    // Skip if the click was actually on the X (already
                    // handled, but extra safety).
                    if (e.target === x || x.contains(e.target)) return;
                    var id = row.getAttribute('data-poi-id');
                    var live = (self.cfg.pois || []).find(function (p) { return String(p.id) === id; });
                    // context:'saved' makes prev/next swipe cycle through the
                    // user's saved list rather than category siblings.
                    if (live) { self.openSheet(live, { context: 'saved' }); return; }
                    // Synthesize from saved snapshot.
                    var entry = self.readSaved()[id];
                    if (!entry) return;
                    self.openSheet({
                        id: entry.id, title: entry.title, name: entry.title,
                        cat: entry.cat, type: entry.type,
                        lat: entry.lat, lon: entry.lon,
                        image: entry.image, imageThumb: entry.imageThumb,
                        rating: entry.rating,
                        tag: entry.tag,
                        blurb: entry.blurb, description: entry.description,
                        button: entry.button || { url: '', text: '' },
                        open: true,
                        __synthetic: true
                    }, { context: 'saved' });
                });
            })(rows[i]);
        }
    };

    /* ── Personal notes (pull-up panel inside the sheet) ─────────────────── */

    var NOTES_KEY = 'innsight.notes';

    SkinController.prototype.readNotes = function () {
        try { return JSON.parse(root.localStorage.getItem(NOTES_KEY) || '{}') || {}; }
        catch (e) { return {}; }
    };

    SkinController.prototype.writeNotes = function (obj) {
        try { root.localStorage.setItem(NOTES_KEY, JSON.stringify(obj || {})); } catch (e) {}
    };

    SkinController.prototype.getNote = function (poiId) {
        if (poiId == null) return '';
        var n = this.readNotes()[ String(poiId) ];
        return n && typeof n.text === 'string' ? n.text : '';
    };

    /**
     * Save a note for `poiId`. Empty string deletes the entry to keep
     * storage compact. Persistence is fire-and-forget; we surface a tiny
     * "Saved" status indicator so the user is never anxious about whether
     * their typing was kept.
     */
    SkinController.prototype.saveNote = function (poiId, text, statusEl) {
        if (poiId == null) return;
        var notes = this.readNotes();
        var clean = String(text == null ? '' : text);
        if (clean === '') {
            delete notes[ String(poiId) ];
        } else {
            notes[ String(poiId) ] = { text: clean, updatedAt: Date.now() };
        }
        this.writeNotes(notes);
        if (statusEl) {
            statusEl.textContent = 'Saved';
            statusEl.classList.add('is-saved');
            clearTimeout(this._noteStatusTimer);
            var self = this;
            this._noteStatusTimer = setTimeout(function () {
                if (statusEl && self.sheet.poi) {
                    statusEl.textContent = 'Auto-saving as you type';
                    statusEl.classList.remove('is-saved');
                }
            }, 1400);
        }
    };

    /**
     * Lazy singleton notes panel. The panel is NOT created until the user
     * actually taps the peek strip - this guarantees it can't accidentally
     * appear from a CSS race or a stale class. Once created, it's parked
     * under `.in-app` (positioned ancestor with `overflow: hidden`) and
     * reused for every POI. The textarea content + the active poi id swap
     * per open. Done button always closes because it's bound to the
     * singleton at creation time.
     */
    SkinController.prototype.ensureNotesPanel = function () {
        if (this._notesPanel && this._notesPanel.parentNode === this.app) {
            return this._notesPanel;
        }
        // Wipe ANY stale instance from prior buggy versions (could be
        // hanging off this.target or even document.body in pre-0.5.3).
        var stale = (this.target && this.target.querySelectorAll('[data-innsight-notes-panel]')) || [];
        for (var s = 0; s < stale.length; s++) {
            if (stale[s].parentNode) stale[s].parentNode.removeChild(stale[s]);
        }
        var panel = document.createElement('div');
        panel.className = 'in-notes';
        panel.setAttribute('data-innsight-notes-panel', '');
        // Belt-and-braces: hidden by HTML attr AND by CSS. The class
        // toggle below adds `is-notes-open` on .in-app to reveal it.
        panel.hidden = true;
        panel.innerHTML =
            '<div class="in-notes__inner">' +
                '<div class="in-notes__head" data-innsight-notes-head>' +
                    '<span class="in-notes__grabber" aria-hidden="true"></span>' +
                    '<div class="in-notes__title">Personal note</div>' +
                    '<button type="button" class="in-notes__close" data-innsight-notes-close aria-label="Close note">Done</button>' +
                '</div>' +
                '<div class="in-notes__sub" data-innsight-notes-status>Auto-saving as you type</div>' +
                '<textarea class="in-notes__area" data-innsight-notes-area ' +
                    'placeholder="Anything you want to remember about this place — vibes, prices, what to order, who you went with…" ' +
                    'rows="6" autocomplete="off" autocorrect="on" spellcheck="true"></textarea>' +
            '</div>';
        // CRITICAL: append to .in-app, NOT .innsight-map-target. .in-app
        // has position: relative + overflow: hidden so the panel's
        // absolute positioning stays inside the shortcode footprint.
        this.app.appendChild(panel);
        this._notesPanel = panel;

        var self = this;
        var closeBtn = panel.querySelector('[data-innsight-notes-close]');
        var head     = panel.querySelector('[data-innsight-notes-head]');
        var area     = panel.querySelector('[data-innsight-notes-area]');
        var status   = panel.querySelector('[data-innsight-notes-status]');

        var saveTimer = null;
        area.addEventListener('input', function () {
            if (status) { status.classList.remove('is-saved'); status.textContent = 'Saving…'; }
            clearTimeout(saveTimer);
            saveTimer = setTimeout(function () {
                self.saveNote(self._notesActiveId, area.value, status);
                if (self._notesActivePeekLabel) {
                    self._notesActivePeekLabel.textContent = area.value ? 'Your note' : 'Add a personal note';
                }
            }, 350);
        });
        area.addEventListener('blur', function () {
            clearTimeout(saveTimer);
            self.saveNote(self._notesActiveId, area.value, status);
        });

        // Done. Multiple events because some Safari versions occasionally
        // swallow click events that follow keyboard dismissal - pointerup
        // catches that case.
        function doClose(e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            self.closeNotesPanel();
        }
        closeBtn.addEventListener('click', doClose);
        closeBtn.addEventListener('pointerup', doClose);

        var dragStartY = 0, dragging = false;
        head.addEventListener('pointerdown', function (e) {
            // Don't hijack a tap on Done as a drag start.
            if (e.target === closeBtn || closeBtn.contains(e.target)) return;
            dragStartY = e.clientY; dragging = true;
        });
        head.addEventListener('pointermove', function (e) {
            if (!dragging) return;
            if (e.clientY - dragStartY > 80) { dragging = false; self.closeNotesPanel(); }
        });
        head.addEventListener('pointerup', function () { dragging = false; });
        head.addEventListener('pointercancel', function () { dragging = false; });

        if (root.visualViewport) {
            var resize = function () {
                if (!self.app.classList.contains('is-notes-open')) return;
                panel.style.setProperty('--in-notes-h', root.visualViewport.height + 'px');
            };
            root.visualViewport.addEventListener('resize', resize);
            root.visualViewport.addEventListener('scroll', resize);
        }

        return panel;
    };

    SkinController.prototype.openNotesPanel = function () {
        if (!this.sheet.poi) return;
        var panel = this.ensureNotesPanel();
        var poiId = String(this.sheet.poi.id);
        var area  = panel.querySelector('[data-innsight-notes-area]');
        if (area) area.value = this.getNote(poiId);
        this._notesActiveId = poiId;
        // Track the peek's label so input updates "Add a personal note" ↔ "Your note".
        var inner = this.target.querySelector('[data-innsight-sheet-inner]');
        this._notesActivePeekLabel = inner ? inner.querySelector('.in-sheet__notes-peek-label') : null;

        panel.hidden = false;
        // Force a reflow so the browser registers the hidden→visible
        // transition before we add the open class. Without it the slide-
        // up animation can skip on the first open per session.
        // eslint-disable-next-line no-unused-expressions
        panel.offsetHeight;
        this.app.classList.add('is-notes-open');
        var self = this;
        setTimeout(function () { try { area && area.focus(); } catch (e) {} }, 320);
    };

    SkinController.prototype.closeNotesPanel = function () {
        this.app.classList.remove('is-notes-open');
        var panel = this._notesPanel;
        if (!panel) return;
        var area = panel.querySelector('[data-innsight-notes-area]');
        if (area) { try { area.blur(); } catch (e) {} }
        // Hide AFTER the slide-down animation finishes so the panel
        // visibly transitions instead of vanishing instantly.
        setTimeout(function () {
            if (!panel.parentNode) return;
            // Only hide if we're still in closed state (user might have
            // re-opened during the transition).
            if (!panel.parentNode.parentNode) return;
            // Walk up: panel.parentNode = .in-app
            if (!panel.parentNode.classList.contains('is-notes-open')) {
                panel.hidden = true;
            }
        }, 340);
    };

    /**
     * Per-sheet binding: bind the freshly-rendered peek strip's tap +
     * drag-up gestures. Does NOT create the panel - that's lazy on first
     * actual interaction so a sheet open never accidentally renders the
     * notes panel.
     */
    SkinController.prototype.bindNotesPanel = function (inner) {
        var self = this;
        var peek = inner.querySelector('[data-innsight-notes-peek]');
        if (!peek || !this.sheet.poi) return;

        peek.onclick = function () { self.openNotesPanel(); };
        peek.onkeydown = function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); self.openNotesPanel(); }
        };
        // Drag UP threshold raised to 100px so a sheet body scroll that
        // happens to start over the peek doesn't accidentally open notes.
        var pStartY = 0, pDragging = false;
        peek.onpointerdown = function (e) { pStartY = e.clientY; pDragging = true; };
        peek.onpointermove = function (e) {
            if (!pDragging) return;
            if (e.clientY - pStartY < -100) { pDragging = false; self.openNotesPanel(); }
        };
        peek.onpointerup = peek.onpointercancel = function () { pDragging = false; };
    };

    /* ── Share wishlist (Saved tab) ──────────────────────────────────────── */

    var SHARE_PARAM = 'innsight_share';
    var SHARED_PICKS_KEY = 'innsight.sharedPicks';   // sessionStorage

    /**
     * Encode the user's saved POIs into a URL-safe base64 token.
     *
     * Token shape is deliberately MINIMAL because messengers truncate
     * long URLs (WhatsApp loses the click region somewhere around the
     * 2-3kB mark). We only carry what the recipient can't reconstruct:
     *   [id, title, cat, lat, lon, note]
     * Image URL, thumbnail, rating, tag, button - all looked up from the
     * recipient's live POI data when the id matches. If the recipient is
     * on a different site / the POI no longer exists, the synthesized
     * fallback uses the title + lat/lon + note we did encode.
     *
     * Format is array-of-arrays (not array-of-objects) to skip the key
     * names entirely - saves ~30 bytes per POI.
     */
    SkinController.prototype.encodeSharedPicks = function (savedList) {
        if (!savedList || !savedList.length) return '';
        var self = this;
        var slim = savedList.map(function (e) {
            return [
                e.id,
                e.title || '',
                e.cat || e.type || '',
                Number(e.lat),
                Number(e.lon),
                self.getNote(e.id) || ''
            ];
        });
        try {
            var json = JSON.stringify(slim);
            var b64 = root.btoa(unescape(encodeURIComponent(json)));
            return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        } catch (e) { return ''; }
    };

    SkinController.prototype.decodeSharedPicks = function (token) {
        if (!token) return null;
        try {
            var b64 = String(token).replace(/-/g, '+').replace(/_/g, '/');
            while (b64.length % 4) b64 += '=';
            var json = decodeURIComponent(escape(root.atob(b64)));
            var slim = JSON.parse(json);
            if (!Array.isArray(slim)) return null;
            return slim.map(function (e) {
                // Tuple form: [id, title, cat, lat, lon, note]. Defensive
                // against partial/old-format tokens that came through as
                // {i,t,c,...} objects from pre-0.5.9 sharers.
                if (Array.isArray(e)) {
                    return {
                        id:    e[0],
                        title: e[1] || '',
                        cat:   e[2] || '',
                        type:  e[2] || '',
                        lat:   Number(e[3]),
                        lon:   Number(e[4]),
                        note:  e[5] || '',
                        image: '', imageThumb: '',
                        rating: null, tag: '',
                        button: { url: '', text: '' }
                    };
                }
                return {
                    id: e.i, title: e.t, cat: e.c, type: e.ty,
                    lat: e.la, lon: e.lo,
                    image: e.im || '', imageThumb: e.th || '',
                    rating: e.ra != null ? e.ra : null, tag: e.tg || '',
                    button: e.bu ? { url: e.bu.u || '', text: e.bu.x || '' } : { url: '', text: '' },
                    note: e.nt || ''
                };
            });
        } catch (e) { return null; }
    };

    SkinController.prototype.buildShareUrl = function () {
        var saved = this.readSavedList();
        var token = this.encodeSharedPicks(saved);
        if (!token) return '';
        var base = root.location.origin + root.location.pathname + root.location.search;
        // Use a hash fragment with `/` as the separator (NOT `?param=value`).
        // WhatsApp / iMessage / SMS auto-link detectors truncate URLs at
        // `=` and several other URL-special characters, so the recipient
        // would see the link end at `…/?innsight_share` with the token
        // chopped off. A hash fragment + slash separator survives every
        // messenger we've tested: nothing after `#` is "special" to URL
        // detection, and `/` reads as part of the path.
        return base + '#' + SHARE_PARAM + '/' + token;
    };

    SkinController.prototype.shareConfig = function () {
        var s = (this.cfg.ui && this.cfg.ui.share) || {};
        return {
            enabled:        s.enabled !== false,
            inviteMessage:  s.inviteMessage  || "Hey! I'm sharing my travel wishlist with you 👇",
            popupTitle:     (s.popup && s.popup.title)        || 'A friend is sharing travel tips',
            popupBody:      (s.popup && s.popup.body)         || "They've curated a wishlist just for you. Want a peek?",
            previewLabel:   (s.popup && s.popup.previewLabel) || 'Just preview',
            saveAllLabel:   (s.popup && s.popup.saveAllLabel) || 'Save them all',
            chipLabel:      s.chipLabel      || "Friend's picks"
        };
    };

    SkinController.prototype.bindShareControls = function () {
        var self = this;
        var openBtn  = this.target.querySelector('[data-innsight-share-open]');
        var menu     = this.target.querySelector('[data-innsight-share-menu]');
        var backdrop = this.target.querySelector('[data-innsight-share-backdrop]');
        var closeBtn = this.target.querySelector('[data-innsight-share-close]');
        var nativeBtn = this.target.querySelector('[data-innsight-share-channel="native"]');

        // Show the native-share tile only when supported.
        if (nativeBtn && root.navigator && root.navigator.share) nativeBtn.hidden = false;
        // Hide the share button entirely if disabled in settings.
        if (!this.shareConfig().enabled && openBtn) {
            openBtn.style.display = 'none';
        }

        function openMenu() {
            var saved = self.readSavedList();
            if (!saved.length) {
                self.showToast('Save a few spots first');
                return;
            }
            var countEl = self.target.querySelector('[data-innsight-share-count]');
            if (countEl) countEl.textContent = saved.length + ' ' + (saved.length === 1 ? 'spot' : 'spots') + ' to share';
            self.app.classList.add('is-share-open');
            if (menu) menu.setAttribute('aria-hidden', 'false');
            if (backdrop) backdrop.setAttribute('aria-hidden', 'false');
        }
        function closeMenu() {
            self.app.classList.remove('is-share-open');
            if (menu) menu.setAttribute('aria-hidden', 'true');
            if (backdrop) backdrop.setAttribute('aria-hidden', 'true');
        }

        if (openBtn) openBtn.addEventListener('click', openMenu);
        if (closeBtn) closeBtn.addEventListener('click', closeMenu);
        if (backdrop) backdrop.addEventListener('click', closeMenu);

        // Channel buttons.
        var tiles = this.target.querySelectorAll('[data-innsight-share-channel]');
        for (var i = 0; i < tiles.length; i++) {
            (function (tile) {
                tile.addEventListener('click', function () {
                    var channel = tile.getAttribute('data-innsight-share-channel');
                    self.dispatchShare(channel);
                    closeMenu();
                });
            })(tiles[i]);
        }
    };

    /**
     * Dispatch a share through the chosen channel. Native uses the Web
     * Share API (fall back to copy on any failure); WhatsApp / Email /
     * Facebook open vendor URLs in a new tab; copy writes the link to the
     * clipboard with a toast confirmation.
     *
     * For WhatsApp specifically: WhatsApp Desktop wraps long URLs onto
     * multiple visual lines and only the first line stays clickable, so
     * we warn the user when the URL exceeds a safe length and route them
     * toward Copy Link / Email instead.
     */
    SkinController.prototype.dispatchShare = function (channel) {
        var url = this.buildShareUrl();
        if (!url) { this.showToast('Save a few spots first'); return; }
        var msg = this.shareConfig().inviteMessage;
        var combined = msg + '\n\n' + url;
        var self = this;

        // WhatsApp's clickable-URL line-wrap limit is roughly 2 KB on
        // desktop; mobile fares better. Beyond that the recipient sees a
        // truncated link with the token chopped off mid-base64.
        if (channel === 'whatsapp' && url.length > 1800) {
            this.showToast('Wishlist too long for WhatsApp - use Copy link or Email');
            return;
        }

        switch (channel) {
            case 'native':
                if (root.navigator && root.navigator.share) {
                    root.navigator.share({ title: 'My wishlist', text: msg, url: url }).catch(function () {});
                } else {
                    self.copyToClipboard(url);
                }
                break;
            case 'whatsapp':
                root.open('https://wa.me/?text=' + encodeURIComponent(combined), '_blank', 'noopener');
                break;
            case 'facebook':
                root.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url) + '&quote=' + encodeURIComponent(msg), '_blank', 'noopener');
                break;
            case 'email':
                root.location.href = 'mailto:?subject=' + encodeURIComponent('My travel wishlist') + '&body=' + encodeURIComponent(combined);
                break;
            case 'copy':
                self.copyToClipboard(url);
                break;
        }
    };

    SkinController.prototype.copyToClipboard = function (text) {
        var self = this;
        if (root.navigator && root.navigator.clipboard && root.navigator.clipboard.writeText) {
            root.navigator.clipboard.writeText(text).then(function () { self.showToast('Link copied'); }, function () { self.fallbackCopy(text); });
        } else {
            self.fallbackCopy(text);
        }
    };
    SkinController.prototype.fallbackCopy = function (text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); this.showToast('Link copied'); } catch (e) {}
        document.body.removeChild(ta);
    };

    /* ── Receive popup (recipient's flow) ────────────────────────────────── */

    SkinController.prototype.bindReceiveControls = function () {
        var self = this;
        var receive = this.target.querySelector('[data-innsight-receive]');
        if (!receive) return;
        var actions = receive.querySelectorAll('[data-innsight-receive-action]');
        for (var i = 0; i < actions.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var action = btn.getAttribute('data-innsight-receive-action');
                    self.handleReceiveChoice(action);
                });
            })(actions[i]);
        }
    };

    SkinController.prototype.consumeShareUrl = function () {
        try {
            var hash = root.location.hash || '';
            var qs   = root.location.search || '';
            var token = '';
            // Preferred: hash fragment with `/` separator (WhatsApp-safe).
            //   #innsight_share/<base64url>
            var hashMatch = hash.match(new RegExp('[#&]' + SHARE_PARAM + '/([^&#]+)'));
            if (hashMatch) {
                token = hashMatch[1];
            } else {
                // Legacy: query parameter (still respected so old links work).
                //   ?innsight_share=<base64url>
                var qsMatch = qs.match(new RegExp('[?&]' + SHARE_PARAM + '=([^&]+)'));
                if (qsMatch) token = qsMatch[1];
            }
            if (!token) return;
            var picks = this.decodeSharedPicks(decodeURIComponent(token));
            if (!picks || !picks.length) return;
            // Stash for the session so the dancing chip survives navigation
            // between tabs (but not a full new tab open).
            try { root.sessionStorage.setItem(SHARED_PICKS_KEY, JSON.stringify(picks)); } catch (e) {}
            this.showReceivePopup(picks);
            // Clean both URL forms so a manual reload doesn't re-pop the
            // modal. history.replaceState gives a tidy URL without a
            // network round-trip.
            if (root.history && root.history.replaceState) {
                var cleanQs = qs.replace(new RegExp('([?&])' + SHARE_PARAM + '=[^&]+&?'), '$1').replace(/[?&]$/, '');
                var cleanHash = hash.replace(new RegExp('[#&]' + SHARE_PARAM + '/[^&]+'), '');
                if (cleanHash === '#') cleanHash = '';
                root.history.replaceState({}, '', root.location.pathname + cleanQs + cleanHash);
            }
        } catch (e) {}
    };

    SkinController.prototype.readSharedPicks = function () {
        try {
            var raw = root.sessionStorage.getItem(SHARED_PICKS_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    };

    SkinController.prototype.showReceivePopup = function (picks) {
        var receive = this.target.querySelector('[data-innsight-receive]');
        if (!receive) return;
        var cfg = this.shareConfig();
        var titleEl = receive.querySelector('[data-innsight-receive-title]');
        var bodyEl  = receive.querySelector('[data-innsight-receive-body]');
        var countEl = receive.querySelector('[data-innsight-receive-count]');
        var prevBtn = receive.querySelector('[data-innsight-receive-action="preview"]');
        var saveBtn = receive.querySelector('[data-innsight-receive-action="save"]');
        if (titleEl) titleEl.textContent = cfg.popupTitle;
        if (bodyEl)  bodyEl.textContent  = cfg.popupBody;
        if (countEl) countEl.textContent = picks.length;
        if (prevBtn) prevBtn.textContent = cfg.previewLabel;
        if (saveBtn) saveBtn.textContent = cfg.saveAllLabel;
        receive.setAttribute('aria-hidden', 'false');
        this.app.classList.add('is-receive-open');
    };

    SkinController.prototype.hideReceivePopup = function () {
        var receive = this.target.querySelector('[data-innsight-receive]');
        if (receive) receive.setAttribute('aria-hidden', 'true');
        this.app.classList.remove('is-receive-open');
    };

    SkinController.prototype.handleReceiveChoice = function (action) {
        var picks = this.readSharedPicks() || [];
        if (action === 'save') {
            // Save them all into the user's localStorage. We piggy-back on
            // toggleSavedPoi by synthesizing a POI-shaped object per pick.
            // We deliberately DON'T overwrite an existing user note - their
            // own text wins over the friend's; only fill in if absent.
            var saved = this.readSaved();
            var notes = this.readNotes();
            var added = 0;
            picks.forEach(function (p) {
                if (!saved[ String(p.id) ]) {
                    added++;
                    saved[ String(p.id) ] = {
                        id: p.id, title: p.title, cat: p.cat, type: p.type,
                        lat: p.lat, lon: p.lon,
                        image: p.image, imageThumb: p.imageThumb,
                        rating: p.rating, tag: p.tag,
                        blurb: '', description: '',
                        button: p.button || { url: '', text: '' },
                        savedAt: Date.now()
                    };
                }
                if (p.note && !notes[ String(p.id) ]) {
                    notes[ String(p.id) ] = { text: String(p.note), updatedAt: Date.now() };
                }
            });
            this.writeSaved(saved);
            this.writeNotes(notes);
            this.showToast(added + ' spot' + (added === 1 ? '' : 's') + ' saved');
            this.hideReceivePopup();
            this.setRoute('save');
        } else {
            // Preview path: keep the picks in sessionStorage, show the
            // dancing chip in the filter bar, drop into the map view.
            this.hideReceivePopup();
            this.activateSharedPicksChip();
            this.setRoute('map');
        }
    };

    /* ── Dancing 'Friend's picks' chip ───────────────────────────────────── */

    SkinController.prototype.restoreSharedPicksChip = function () {
        if (this.readSharedPicks()) this.activateSharedPicksChip();
    };

    SkinController.prototype.activateSharedPicksChip = function () {
        var picks = this.readSharedPicks();
        if (!picks || !picks.length) return;
        var label = this.shareConfig().chipLabel + ' (' + picks.length + ')';
        var hosts = [
            this.target.querySelector('[data-innsight-chips]'),
            this.target.querySelector('[data-innsight-list-chips]')
        ].filter(Boolean);
        var self = this;
        hosts.forEach(function (host) {
            // Remove an existing chip first so we don't stack duplicates on
            // re-activation (e.g. after tab navigation).
            var existing = host.querySelector('.in-chip--shared');
            if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
            var btn = document.createElement('button');
            btn.className = 'in-chip in-chip--shared in-chip--dance';
            btn.type = 'button';
            btn.setAttribute('role', 'tab');
            btn.setAttribute('data-cat', '__shared');
            btn.setAttribute('aria-pressed', self.cat === '__shared' ? 'true' : 'false');
            btn.innerHTML = '<span class="in-chip__heart" aria-hidden="true">♥</span>' + self.escape(label) + '<span class="in-chip__close" aria-label="Dismiss" data-shared-dismiss>×</span>';
            btn.addEventListener('click', function (e) {
                if (e.target && e.target.getAttribute('data-shared-dismiss') !== null) {
                    self.dismissSharedPicks();
                    return;
                }
                self.setCategoryShared();
            });
            // Put it first so it's hard to miss.
            host.insertBefore(btn, host.firstChild);
        });
    };

    SkinController.prototype.dismissSharedPicks = function () {
        try { root.sessionStorage.removeItem(SHARED_PICKS_KEY); } catch (e) {}
        var chips = this.target.querySelectorAll('.in-chip--shared');
        for (var i = 0; i < chips.length; i++) {
            if (chips[i].parentNode) chips[i].parentNode.removeChild(chips[i]);
        }
        if (this.cat === '__shared') this.setCategory('all');
    };

    SkinController.prototype.setCategoryShared = function () {
        this.cat = '__shared';
        var chips = this.target.querySelectorAll('[data-cat]');
        for (var i = 0; i < chips.length; i++) {
            chips[i].setAttribute('aria-pressed', chips[i].getAttribute('data-cat') === '__shared' ? 'true' : 'false');
        }
        // Drop into List view so the user actually sees the curated picks
        // (the dancing chip + a List filter is the user-friendly outcome).
        if (this.route !== 'list') this.setRoute('list');
        else this.renderList();
        this.updateCounts();
    };

    /**
     * Compute __distMeters for a POI not in the original cfg.pois array
     * (e.g. a saved entry we're rendering). Same Haversine as
     * annotateDistances but for one POI in-place.
     */
    SkinController.prototype.computeDistanceFor = function (poi) {
        var ref = this.hostelRef;
        if (!ref || !isFinite(poi.lat) || !isFinite(poi.lon)) return;
        var R = 6371000;
        var toRad = function (d) { return d * Math.PI / 180; };
        var refLat = toRad(ref.lat), refLon = toRad(ref.lon);
        var lat = toRad(+poi.lat), lon = toRad(+poi.lon);
        var dLat = lat - refLat, dLon = lon - refLon;
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(refLat) * Math.cos(lat) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        poi.__distMeters = R * c;
    };
})(typeof window !== 'undefined' ? window : this);
