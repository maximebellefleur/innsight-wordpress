/* Innsight - client-side KML generation. Ports yuna-innsight.php generate_kml() to JS so the
 * demo and any non-WordPress consumer can produce a downloadable KML without a backend.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var escape = Innsight._utils.escapeHtml;

    function buildKml(state) {
        var pois = state.normalized.pois || [];
        var paths = state.normalized.paths || [];
        var lines = [];
        lines.push('<?xml version="1.0" encoding="UTF-8"?>');
        lines.push('<kml xmlns="http://www.opengis.net/kml/2.2">');
        lines.push('<Document>');
        lines.push('<name>' + escape(state.normalized.skin && state.normalized.skin.name || 'innsight') + '</name>');

        pois.forEach(function (p) {
            lines.push('<Placemark>');
            lines.push('<name>' + escape(p.title) + '</name>');
            if (p.description) lines.push('<description><![CDATA[' + p.description + ']]></description>');
            lines.push('<Point><coordinates>' + p.lon + ',' + p.lat + ',0</coordinates></Point>');
            lines.push('</Placemark>');
        });

        paths.forEach(function (path) {
            lines.push('<Placemark>');
            lines.push('<name>' + escape(path.title) + '</name>');
            lines.push('<Style><LineStyle><color>ff' + (path.color || '#3d3c3c').replace('#', '').match(/.{2}/g).reverse().join('') + '</color><width>3</width></LineStyle></Style>');
            lines.push('<LineString><coordinates>');
            path.coordinates.forEach(function (c) { lines.push(c[1] + ',' + c[0] + ',0'); });
            lines.push('</coordinates></LineString>');
            lines.push('</Placemark>');
        });

        lines.push('</Document>');
        lines.push('</kml>');
        return lines.join('\n');
    }

    function download(state, filename) {
        var kml = buildKml(state);
        var blob = new Blob([kml], { type: 'application/vnd.google-earth.kml+xml' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename || 'map.kml';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        return kml;
    }

    Innsight._kmlExport = { buildKml: buildKml, download: download };
})(typeof window !== 'undefined' ? window : this);
