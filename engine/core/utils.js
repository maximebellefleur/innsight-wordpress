/* Innsight - shared utilities. Attaches to window.Innsight._utils. */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function parseLatLng(value) {
        if (Array.isArray(value) && value.length >= 2) {
            return [Number(value[0]), Number(value[1])];
        }
        if (typeof value === 'string' && value.indexOf(',') !== -1) {
            var parts = value.split(',').map(function (n) { return Number(n.trim()); });
            return [parts[0], parts[1]];
        }
        if (value && typeof value === 'object' && 'lat' in value && ('lon' in value || 'lng' in value)) {
            return [Number(value.lat), Number(value.lon != null ? value.lon : value.lng)];
        }
        return null;
    }

    function debounce(fn, wait) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    function isMobile() {
        return /Mobi|Android|iPhone|iPad|iPod/i.test(root.navigator && root.navigator.userAgent || '');
    }

    function fillTemplateString(tmpl, data) {
        if (!tmpl) return '';
        return String(tmpl).replace(/\{\{\s*([\w.]+)\s*\}\}/g, function (m, key) {
            var v = key.split('.').reduce(function (o, k) {
                return (o && o[k] != null) ? o[k] : null;
            }, data);
            return v == null ? '' : escapeHtml(v);
        });
    }

    Innsight._utils = {
        escapeHtml: escapeHtml,
        parseLatLng: parseLatLng,
        debounce: debounce,
        isMobile: isMobile,
        fillTemplateString: fillTemplateString
    };
})(typeof window !== 'undefined' ? window : this);
