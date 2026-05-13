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
        this.sheet = { poi: null, hintShown: false, drag: 0, swiping: false, startX: 0, startY: 0, hintTimer: null };
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
        this.applyBranding();
        this.renderChips();
        this.bindChrome();
        this.bindMapControls();
        this.bindTabs();
        this.bindSheetBackdrop();
        this.bindSortMenu();
        this.bindFullscreenSync();
        this.bindEngineEvents();
        this.startLiveLocation();
        this.updateCounts();
        // Sync route class on first render so CSS scope variants apply.
        this.setRoute('map');
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
        // for cat we filter the visible markers' types directly.
        if (this.state.instance && this.state.instance.filter) {
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
    SkinController.prototype.openSheet = function (poi) {
        this.sheet.poi = poi;
        this.sheet.drag = 0;
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
     */
    SkinController.prototype.requestEnrichment = function (poi) {
        if (!this.state.instance || !this.state.instance.enrichPoi) return;
        var self = this;
        this.state.instance.enrichPoi(poi).then(function (data) {
            if (!data) return;
            // Map Places fields onto the POI shape the template renders.
            if (data.rating != null)          poi.rating = data.rating;
            if (data.userRatingCount != null) poi.userRatingCount = data.userRatingCount;
            if (data.openNow != null)         poi.open = data.openNow;
            if (data.todaysHours)             poi.hours = data.todaysHours;
            if (data.googleMapsUri)           poi.googleMapsUri = data.googleMapsUri;
            if (data.websiteUri && !poi.button.url) {
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
            if (self.sheet.poi && String(self.sheet.poi.id) === String(poi.id)) {
                self.renderSheet();
            }
        });
    };
    SkinController.prototype.closeSheet = function () {
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
        // Bind swipe.
        this.bindSheetSwipe(inner);
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
        // Stash for navigation.
        this.sheet.siblings = siblings;
        this.sheet.idx = idx;
        return ctx;
    };

    SkinController.prototype.siblingsFor = function (poi) {
        var pois = this.cfg.pois || [];
        return pois.filter(function (p) { return (p.cat || p.type) === (poi.cat || poi.type); });
    };

    SkinController.prototype.navigateSheet = function (dir) {
        var sib = this.sheet.siblings || [];
        if (sib.length < 2) return;
        var idx = (this.sheet.idx + dir + sib.length) % sib.length;
        recordSwipeUsed();
        this.sheet.poi = sib[idx];
        this.renderSheet();
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
                    if (poi) self.openSheet(poi);
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
                    if (live) { self.openSheet(live); return; }
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
                    });
                });
            })(rows[i]);
        }
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
