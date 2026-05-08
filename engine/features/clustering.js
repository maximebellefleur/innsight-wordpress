/* Innsight - cluster icon HTML factory. The skin owns the visual classes (.clusterui, .md-{type}). */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    function buildIconCreator(type, clusterTemplate) {
        return function (cluster) {
            var count = cluster.getChildCount();
            var html;
            if (clusterTemplate) {
                html = Innsight._template.render(clusterTemplate, { count: count, type: type });
            } else {
                html = '<div class="number">' + count + '</div>';
            }
            return L.divIcon({
                html: html,
                className: 'clusterui md-' + type,
                iconSize: L.point(32, 32)
            });
        };
    }

    Innsight._clustering = { buildIconCreator: buildIconCreator };
})(typeof window !== 'undefined' ? window : this);
