/* Innsight - opt-in Google Places enrichment.
 *
 * Activates when:
 *   - config.enrichment.google.apiKey is set
 *   - the POI has a googlePlaceId (or matches by name+lat/lon at runtime)
 *
 * For each POI missing one or more configured `fields`, calls the Place Details endpoint
 * via Google's recommended "Places API (New)" v1 fetch shape, fills the gaps in-memory,
 * and re-renders the popup HTML on next open. Caches responses in localStorage.
 *
 * Falls back silently on failure - JSON-supplied fields are always the source of truth.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var CACHE_PREFIX = 'innsight:gp:';

    function readCache(placeId, ttlHours) {
        try {
            var raw = root.localStorage && root.localStorage.getItem(CACHE_PREFIX + placeId);
            if (!raw) return null;
            var entry = JSON.parse(raw);
            if (!entry || !entry.savedAt) return null;
            var ageMs = Date.now() - entry.savedAt;
            if (ageMs > (ttlHours || 24) * 3600 * 1000) return null;
            return entry.data;
        } catch (e) { return null; }
    }

    function writeCache(placeId, data) {
        try {
            root.localStorage && root.localStorage.setItem(CACHE_PREFIX + placeId, JSON.stringify({ savedAt: Date.now(), data: data }));
        } catch (e) {}
    }

    function fetchDetails(placeId, apiKey, fields) {
        var url = 'https://places.googleapis.com/v1/places/' + encodeURIComponent(placeId);
        var fieldMask = (fields && fields.length ? fields : ['photos', 'opening_hours', 'rating'])
            .map(function (f) {
                if (f === 'photos') return 'photos';
                if (f === 'opening_hours') return 'currentOpeningHours,regularOpeningHours';
                if (f === 'rating') return 'rating,userRatingCount';
                return f;
            }).join(',');
        return fetch(url, {
            method: 'GET',
            headers: {
                'X-Goog-Api-Key': apiKey,
                'X-Goog-FieldMask': fieldMask
            }
        }).then(function (r) {
            if (!r.ok) throw new Error('places API ' + r.status);
            return r.json();
        });
    }

    function poiMissingFields(poi, fields) {
        var missing = [];
        if (!fields || !fields.length) return missing;
        fields.forEach(function (f) {
            if (f === 'photos' && !poi.image) missing.push('photos');
            if (f === 'opening_hours' && !poi.openingHours) missing.push('opening_hours');
            if (f === 'rating' && poi.rating == null) missing.push('rating');
        });
        return missing;
    }

    function applyDetails(poi, details) {
        if (!details) return false;
        var changed = false;
        if (!poi.image && details.photos && details.photos.length) {
            // Construct a photo URL using the photo resource name.
            poi.image = 'https://places.googleapis.com/v1/' + details.photos[0].name + '/media?maxHeightPx=400';
            changed = true;
        }
        if (!poi.openingHours && (details.regularOpeningHours || details.currentOpeningHours)) {
            poi.openingHours = (details.currentOpeningHours || details.regularOpeningHours).weekdayDescriptions || [];
            changed = true;
        }
        if (poi.rating == null && details.rating != null) {
            poi.rating = details.rating;
            poi.userRatingCount = details.userRatingCount;
            changed = true;
        }
        return changed;
    }

    function enrich(state) {
        var conf = state.normalized.enrichment && state.normalized.enrichment.google;
        if (!conf || !conf.apiKey) return Promise.resolve();
        var fields = conf.fields || [];
        var ttl = conf.cacheTtlHours || 24;
        var jobs = [];

        state.normalized.pois.forEach(function (poi) {
            if (!poi.googlePlaceId) return;
            var missing = poiMissingFields(poi, fields);
            if (!missing.length) return;
            var cached = readCache(poi.googlePlaceId, ttl);
            if (cached) {
                if (applyDetails(poi, cached)) {
                    Innsight._markers.rebuildPopup(state, poi);
                    state.events.emit('enrichment:applied', { poi: poi, source: 'cache' });
                }
                return;
            }
            jobs.push(fetchDetails(poi.googlePlaceId, conf.apiKey, missing).then(function (details) {
                writeCache(poi.googlePlaceId, details);
                if (applyDetails(poi, details)) {
                    Innsight._markers.rebuildPopup(state, poi);
                    state.events.emit('enrichment:applied', { poi: poi, source: 'remote' });
                }
            }).catch(function (err) {
                if (root.console) root.console.warn('[innsight] places enrichment failed for ' + poi.id + ':', err.message);
            }));
        });

        return Promise.all(jobs);
    }

    Innsight._enrichment = { enrich: enrich };
})(typeof window !== 'undefined' ? window : this);
