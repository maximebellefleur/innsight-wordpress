/* Innsight - Mustache-lite templating.
 * Supports: {{var}} (escaped), {{{rawHtml}}} (raw), {{#if x}}..{{/if}}, {{#unless x}}..{{/unless}},
 * {{#if x}}..{{else}}..{{/if}}, {{#each list}}..{{/each}}.
 * No nesting of {{#each}} inside {{#each}} of the same key, no helpers - keep it tiny.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var escapeHtml = Innsight._utils.escapeHtml;

    function lookup(ctx, key) {
        if (!ctx || key == null) return undefined;
        if (key === '.' || key === 'this') return ctx;
        var parts = String(key).split('.');
        var v = ctx;
        for (var i = 0; i < parts.length; i++) {
            if (v == null) return undefined;
            v = v[parts[i]];
        }
        return v;
    }

    function isTruthy(v) {
        if (Array.isArray(v)) return v.length > 0;
        return !!v;
    }

    function renderEach(body, list, parent) {
        var out = '';
        for (var i = 0; i < list.length; i++) {
            var item = list[i];
            var ctx;
            if (item != null && typeof item === 'object') {
                ctx = {};
                for (var k in item) if (Object.prototype.hasOwnProperty.call(item, k)) ctx[k] = item[k];
                ctx.__index = i;
                ctx.__parent = parent;
            } else {
                ctx = { value: item, __index: i, __parent: parent };
            }
            out += render(body, ctx);
        }
        return out;
    }

    function findMatchingClose(src, openTag, closeTag, startAt) {
        var depth = 1;
        var pos = startAt;
        var openRe = new RegExp('\\{\\{\\s*' + openTag + '\\s+[^}]*?\\}\\}', 'g');
        var closeRe = new RegExp('\\{\\{\\s*' + closeTag + '\\s*\\}\\}', 'g');
        while (depth > 0 && pos < src.length) {
            openRe.lastIndex = pos;
            closeRe.lastIndex = pos;
            var openMatch = openRe.exec(src);
            var closeMatch = closeRe.exec(src);
            if (!closeMatch) return -1;
            if (openMatch && openMatch.index < closeMatch.index) {
                depth++;
                pos = openMatch.index + openMatch[0].length;
            } else {
                depth--;
                if (depth === 0) return closeMatch.index;
                pos = closeMatch.index + closeMatch[0].length;
            }
        }
        return -1;
    }

    /**
     * Depth-aware {{else}} splitter. The simple regex-first-match version
     * broke when a block contained a NESTED {{#if}}{{else}}{{/if}} but had
     * no else of its own - the nested {{else}} was picked up as the outer
     * split point and the ifBranch got truncated, leaving a broken template
     * that emitted raw {{#if key}} text on the page.
     *
     * Returns [ifBranch, elseBranch]. When the body has no matching else at
     * depth 0, elseBranch is ''.
     */
    function splitOnElse(body) {
        var openRe  = /\{\{\s*(#if|#unless|#each)\s+[\w.]+\s*\}\}/;
        var closeRe = /\{\{\s*(\/if|\/unless|\/each)\s*\}\}/;
        var elseRe  = /\{\{\s*else\s*\}\}/;
        var depth = 0;
        var i = 0;
        while (i < body.length) {
            var openIdx = body.indexOf('{{', i);
            if (openIdx === -1) break;
            var rest = body.slice(openIdx);
            var m;
            if ((m = rest.match(openRe)) && m.index === 0)  { depth++;  i = openIdx + m[0].length; continue; }
            if ((m = rest.match(closeRe)) && m.index === 0) { depth--;  i = openIdx + m[0].length; continue; }
            if ((m = rest.match(elseRe)) && m.index === 0) {
                if (depth === 0) return [body.slice(0, openIdx), body.slice(openIdx + m[0].length)];
                i = openIdx + m[0].length;
                continue;
            }
            i = openIdx + 2; // regular {{var}} - skip past the braces
        }
        return [body, ''];
    }

    function render(template, data) {
        if (template == null) return '';
        var src = String(template);
        var out = '';
        var i = 0;
        var ctx = data || {};

        while (i < src.length) {
            var openIdx = src.indexOf('{{', i);
            if (openIdx === -1) { out += src.slice(i); break; }
            out += src.slice(i, openIdx);

            // Block tags: {{#if x}}, {{#unless x}}, {{#each x}}
            var blockMatch = src.slice(openIdx).match(/^\{\{\s*(#if|#unless|#each)\s+([\w.]+)\s*\}\}/);
            if (blockMatch) {
                var tag = blockMatch[1];
                var key = blockMatch[2];
                var bodyStart = openIdx + blockMatch[0].length;
                var closeTag = tag === '#if' ? '/if' : (tag === '#unless' ? '/unless' : '/each');
                var closeIdx = findMatchingClose(src, tag, closeTag, bodyStart);
                if (closeIdx === -1) { out += src.slice(openIdx); break; }
                var body = src.slice(bodyStart, closeIdx);
                var afterBlock = src.indexOf('}}', closeIdx) + 2;
                var value = lookup(ctx, key);

                if (tag === '#each') {
                    var list = Array.isArray(value) ? value : [];
                    out += renderEach(body, list, ctx);
                } else {
                    var truthy = isTruthy(value);
                    if (tag === '#unless') truthy = !truthy;
                    // Depth-aware split so a nested {{else}} inside a
                    // child block doesn't get mistaken for this block's
                    // else - the classic cause of "raw {{#if key}} text
                    // shows on the page" bugs.
                    var parts = splitOnElse(body);
                    out += render(truthy ? parts[0] : parts[1], ctx);
                }
                i = afterBlock;
                continue;
            }

            // Triple-stache (raw HTML)
            var rawMatch = src.slice(openIdx).match(/^\{\{\{\s*([\w.]+)\s*\}\}\}/);
            if (rawMatch) {
                var rv = lookup(ctx, rawMatch[1]);
                out += rv == null ? '' : String(rv);
                i = openIdx + rawMatch[0].length;
                continue;
            }

            // Variable (escaped)
            var varMatch = src.slice(openIdx).match(/^\{\{\s*([\w.]+)\s*\}\}/);
            if (varMatch) {
                var ev = lookup(ctx, varMatch[1]);
                out += ev == null ? '' : escapeHtml(ev);
                i = openIdx + varMatch[0].length;
                continue;
            }

            // Unknown — emit literal '{{' and continue.
            out += '{{';
            i = openIdx + 2;
        }
        return out;
    }

    Innsight._template = { render: render };
})(typeof window !== 'undefined' ? window : this);
