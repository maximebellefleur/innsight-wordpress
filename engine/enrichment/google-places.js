/* Innsight - on-demand Google Places enrichment.
 *
 * The user opens a POI's bottom sheet -> the skin calls
 * `instance.enrichPoi(poi)` -> we look up the Place ID (search by text +
 * location bias when the POI doesn't already carry one), fetch the Place
 * Details, cache the response in localStorage, and resolve with the merged
 * data.
 *
 * Cost / privacy tradeoffs are explicit:
 *   - Only POIs the user actually opens get queried (no bulk init enrich).
 *   - localStorage cache TTL defaults to 30 days; place facts move slowly.
 *   - A negative result is cached too (shorter TTL) so we don't re-pay the
 *     search cost for POIs Google can't match.
 *   - The API key is sent from the browser; restrict it to your HTTP
 *     referrer in the Google Cloud Console before enabling enrichment.
 *
 * Returns a single shape regardless of source:
 *   { placeId, rating, userRatingCount, openNow, todaysHours,
 *     weekdayHours[7], googleMapsUri, websiteUri, phone, photoUrl,
 *     reviews[<=5] }
 * Returns null when disabled / not found / on error.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};
    var CACHE_PREFIX = 'innsight:gp:';
    var NEG_TTL_HOURS = 24 * 7;   // a week is enough for a re-try
    var POS_TTL_HOURS = 24 * 30;  // a month for found places

    function cacheKey(poi) {
        if (poi.googlePlaceId) return CACHE_PREFIX + poi.googlePlaceId;
        return CACHE_PREFIX + 'id:' + String(poi.id || (poi.lat + ',' + poi.lon));
    }

    function readCache(key) {
        try {
            var raw = root.localStorage && root.localStorage.getItem(key);
            if (!raw) return null;
            var entry = JSON.parse(raw);
            if (!entry || !entry.savedAt) return null;
            var ttlMs = (entry.notFound ? NEG_TTL_HOURS : POS_TTL_HOURS) * 3600 * 1000;
            if (Date.now() - entry.savedAt > ttlMs) return null;
            return entry;
        } catch (e) { return null; }
    }

    function writeCache(key, payload) {
        try {
            root.localStorage && root.localStorage.setItem(key, JSON.stringify({
                savedAt: Date.now(),
                notFound: !!payload.notFound,
                data: payload.data || null
            }));
        } catch (e) {}
    }

    /* Resolve Place ID from a free-text query, biased by the POI's lat/lon. */
    function searchPlaceId(poi, apiKey) {
        var query = (poi.title || poi.name || '') + (poi.cat || poi.type ? ' ' + (poi.cat || poi.type) : '');
        var bias = {
            circle: {
                center: { latitude: Number(poi.lat), longitude: Number(poi.lon) },
                radius: 500   // metres - tight enough to avoid wrong-city collisions
            }
        };
        return fetch('https://places.googleapis.com/v1/places:searchText', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Goog-Api-Key': apiKey,
                'X-Goog-FieldMask': 'places.id,places.displayName,places.location'
            },
            body: JSON.stringify({ textQuery: query, locationBias: bias, maxResultCount: 1 })
        }).then(function (r) {
            if (!r.ok) throw new Error('places:searchText ' + r.status);
            return r.json();
        }).then(function (res) {
            if (res.places && res.places.length) return res.places[0].id;
            return null;
        });
    }

    /* Fetch Place Details for an id. Fields = display + ops + 3 reviews. */
    function fetchDetails(placeId, apiKey) {
        var url = 'https://places.googleapis.com/v1/places/' + encodeURIComponent(placeId);
        var fieldMask = [
            'id', 'displayName',
            'rating', 'userRatingCount',
            'currentOpeningHours', 'regularOpeningHours',
            'photos',
            'googleMapsUri', 'websiteUri',
            'nationalPhoneNumber',
            'reviews'
        ].join(',');
        return fetch(url, {
            method: 'GET',
            headers: { 'X-Goog-Api-Key': apiKey, 'X-Goog-FieldMask': fieldMask }
        }).then(function (r) {
            if (!r.ok) throw new Error('places.details ' + r.status);
            return r.json();
        });
    }

    /* Reshape the raw Places response into the flat shape skin templates
     * expect. Stable keys, missing values become null/empty. */
    function shape(details, apiKey) {
        if (!details) return null;
        var open = details.currentOpeningHours || details.regularOpeningHours || null;
        var weekday = open && open.weekdayDescriptions ? open.weekdayDescriptions : [];
        // weekdayDescriptions are Sunday-first in some Places responses,
        // Monday-first in others depending on locale. We just expose all 7.
        var d = new Date();
        var todayIdx = d.getDay(); // 0 = Sun
        var todays = weekday[todayIdx] ? weekday[todayIdx].split(': ').slice(1).join(': ') : '';
        var photoUrl = '';
        if (details.photos && details.photos.length && apiKey) {
            // The /v1/{name}/media endpoint returns a redirect to the actual
            // image bytes. Including the key works because the key is
            // referrer-restricted (admin's responsibility).
            photoUrl = 'https://places.googleapis.com/v1/' + details.photos[0].name + '/media?maxHeightPx=720&key=' + encodeURIComponent(apiKey);
        }
        return {
            placeId:         details.id || '',
            rating:          details.rating != null ? Number(details.rating) : null,
            userRatingCount: details.userRatingCount != null ? Number(details.userRatingCount) : null,
            openNow:         open ? !!open.openNow : null,
            todaysHours:     todays || '',
            weekdayHours:    weekday,
            googleMapsUri:   details.googleMapsUri || '',
            websiteUri:      details.websiteUri || '',
            phone:           details.nationalPhoneNumber || '',
            photoUrl:        photoUrl,
            reviews:         Array.isArray(details.reviews) ? details.reviews.slice(0, 3).map(function (r) {
                return {
                    author: (r.authorAttribution && r.authorAttribution.displayName) || '',
                    rating: r.rating || null,
                    text:   (r.text && r.text.text) || '',
                    when:   r.relativePublishTimeDescription || ''
                };
            }) : []
        };
    }

    /**
     * Public: enrich a single POI. Resolves with the shape() result or null.
     * Honours config.enrichment.google.apiKey + cacheTtlHours from the v1
     * JSON. Safe to call repeatedly for the same POI - the cache short-
     * circuits.
     */
    function enrichPoi(poi, config) {
        var conf = config && config.enrichment && config.enrichment.google;
        if (!conf || !conf.apiKey || !poi) return Promise.resolve(null);
        var key = cacheKey(poi);
        var cached = readCache(key);
        if (cached) {
            return Promise.resolve(cached.notFound ? null : cached.data);
        }

        var placeIdPromise = poi.googlePlaceId
            ? Promise.resolve(poi.googlePlaceId)
            : searchPlaceId(poi, conf.apiKey);

        return placeIdPromise.then(function (placeId) {
            if (!placeId) {
                writeCache(key, { notFound: true });
                return null;
            }
            return fetchDetails(placeId, conf.apiKey).then(function (details) {
                var data = shape(details, conf.apiKey);
                writeCache(key, { data: data });
                return data;
            });
        }).catch(function (err) {
            if (root.console) root.console.warn('[innsight] places enrichment for ' + (poi.id || '?') + ':', err && err.message);
            // Negative-cache transient failures briefly so we don't re-pay
            // for a flaky network on every sheet open.
            writeCache(key, { notFound: true });
            return null;
        });
    }

    Innsight._enrichment = { enrichPoi: enrichPoi };
})(typeof window !== 'undefined' ? window : this);
