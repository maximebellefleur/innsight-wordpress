/* Innsight - popup HTML builder. Renders the skin's popup.html template per POI.
 * Falls back to a minimal default if the skin didn't supply one.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var DEFAULT_TEMPLATE = '<div class="popupContent"><div class="title_holder"><h3>{{title}}</h3>' +
        '{{#if button.url}}<a href="{{button.url}}" class="more" target="_blank">{{button.text}}</a>{{/if}}</div>' +
        '{{#if image}}<div class="img_holder"><img src="{{image}}" alt="{{title}}" /></div>{{/if}}' +
        '<p>{{{description}}}</p>' +
        '{{#if actionLinks}}<div class="external_action"><ul>{{#each actionLinks}}' +
        '<li class="{{key}}"><a href="{{url}}" target="_blank">{{label}}</a></li>' +
        '{{/each}}</ul></div>{{/if}}</div>';

    function buildPopupContext(poi, config) {
        var actionLinks = [];
        var defs = config.actionLinks || {};
        var isMobile = Innsight._utils.isMobile();
        Object.keys(defs).forEach(function (key) {
            var def = defs[key];
            if (!def || !def.urlTemplate) return;
            if (def.mobileOnly && !isMobile) return;
            var url = def.urlTemplate
                .replace(/\{\{\s*lat\s*\}\}/g, encodeURIComponent(poi.lat))
                .replace(/\{\{\s*lon\s*\}\}/g, encodeURIComponent(poi.lon))
                .replace(/\{\{\s*title\s*\}\}/g, encodeURIComponent(poi.title));
            actionLinks.push({ key: key, label: def.label || key, url: url });
        });
        return Object.assign({}, poi, { actionLinks: actionLinks });
    }

    function render(poi, config, template) {
        var ctx = buildPopupContext(poi, config);
        var tpl = template || DEFAULT_TEMPLATE;
        return Innsight._template.render(tpl, ctx);
    }

    Innsight._popup = { render: render, buildPopupContext: buildPopupContext, DEFAULT_TEMPLATE: DEFAULT_TEMPLATE };
})(typeof window !== 'undefined' ? window : this);
