/* Innsight - tiny pub/sub used internally and exposed on every instance. */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function Emitter() {
        this._handlers = {};
    }

    Emitter.prototype.on = function (event, fn) {
        if (!event || typeof fn !== 'function') return this;
        (this._handlers[event] = this._handlers[event] || []).push(fn);
        return this;
    };

    Emitter.prototype.off = function (event, fn) {
        if (!this._handlers[event]) return this;
        if (!fn) { delete this._handlers[event]; return this; }
        this._handlers[event] = this._handlers[event].filter(function (h) { return h !== fn; });
        return this;
    };

    Emitter.prototype.emit = function (event, payload) {
        var list = this._handlers[event];
        if (!list || !list.length) return this;
        for (var i = 0; i < list.length; i++) {
            try { list[i](payload); } catch (e) {
                if (root.console && root.console.error) root.console.error('[innsight] handler for ' + event + ':', e);
            }
        }
        return this;
    };

    Emitter.prototype.removeAll = function () {
        this._handlers = {};
        return this;
    };

    Innsight._Emitter = Emitter;
})(typeof window !== 'undefined' ? window : this);
