/* Innsight - per-instance state registry. */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var instances = {};
    var nextId = 1;

    function create(initial) {
        var id = 'innsight-' + (nextId++);
        var state = Object.assign({
            id: id,
            target: null,
            config: null,
            normalized: null,
            provider: null,
            skin: null,
            partials: null,
            markers: [],
            paths: [],
            groupTypes: {},
            allBounds: null,
            soloMode: false,
            ready: false,
            destroyed: false
        }, initial || {});
        instances[id] = state;
        return state;
    }

    function get(id) { return instances[id]; }

    function remove(id) {
        delete instances[id];
    }

    function all() {
        return Object.keys(instances).map(function (k) { return instances[k]; });
    }

    Innsight._state = { create: create, get: get, remove: remove, all: all };
})(typeof window !== 'undefined' ? window : this);
