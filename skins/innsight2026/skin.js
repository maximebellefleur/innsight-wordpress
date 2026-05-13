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
        this.fullscreen = false;
        this.sheet = { poi: null, hintShown: false, drag: 0, swiping: false, startX: 0, startY: 0, hintTimer: null };
        this.app = this.target.querySelector('.in-app');
    }

    SkinController.prototype.boot = function () {
        this.applyBranding();
        this.renderChips();
        this.bindChrome();
        this.bindMapControls();
        this.bindTabs();
        this.bindSheetBackdrop();
        this.bindEngineEvents();
        this.updateCounts();
        // Sync route class on first render so CSS scope variants apply.
        this.setRoute('map');
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
        // Map sometimes needs a resize after the chrome collapses.
        var p = this.state.provider;
        if (p && p.invalidateSize) setTimeout(function () { p.invalidateSize(); }, 240);
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
        if (route === 'save') this.renderEmpty('Saved', "Stickers you've kept will live here.");
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
        this.maybePlayHint();
    };
    SkinController.prototype.closeSheet = function () {
        this.app.classList.remove('is-sheet-open');
        // Defer poi clearing so the slide-out animation has data to show.
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
        // Save button (placeholder - pure UI in v0.1; emit event for hosts).
        var saveBtn = inner.querySelector('[data-innsight-sheet-save]');
        if (saveBtn) saveBtn.addEventListener('click', function () {
            self.state.events.emit('sheet:save', poi);
        });
        // Bind swipe.
        this.bindSheetSwipe(inner);
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
            rating: poi.rating != null ? Number(poi.rating).toFixed(1) : '',
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

    SkinController.prototype.bindSheetSwipe = function (inner) {
        var self = this;
        var startX = 0, startY = 0, dx = 0, swiping = false;
        function onDown(e) {
            if (e.target.closest('button, a')) return;
            startX = e.clientX; startY = e.clientY; dx = 0; swiping = true;
            inner.classList.remove('is-hint');
        }
        function onMove(e) {
            if (!swiping) return;
            var dy = e.clientY - startY;
            dx = e.clientX - startX;
            if (Math.abs(dy) > Math.abs(dx) + 8) { swiping = false; inner.style.transform = ''; return; }
            inner.style.transform = 'translateX(' + dx + 'px)';
            inner.style.opacity = Math.max(0.4, 1 - Math.abs(dx) / 320);
        }
        function onUp() {
            if (!swiping) return;
            swiping = false;
            var threshold = 70;
            inner.style.transform = '';
            inner.style.opacity = '';
            if (dx < -threshold) self.navigateSheet(+1);
            else if (dx > threshold) self.navigateSheet(-1);
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
        var pois = this.visiblePois();
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
            var ctx = {
                id: p.id,
                title: p.title || p.name || '',
                initial: (p.title || p.name || '·').charAt(0).toUpperCase(),
                stickerColor: color,
                categoryColor: cat.color,
                categoryLabel: cat.label,
                tag: p.tag || '',
                dist: p.dist || '',
                image: p.image || '',
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
})(typeof window !== 'undefined' ? window : this);
