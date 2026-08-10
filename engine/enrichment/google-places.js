/* Innsight - Google Places enrichment client (server-backed).
 *
 * The heavy lifting (calling Google's Places API, caching, refreshing
 * stale rows) happens server-side in the WordPress plugin's Places
 * service. This file is now a thin REST client that:
 *   - Asks /wp-json/innsight/v1/places?poi_id=... for the current data.
 *   - Passes it back to the skin unchanged when fresh.
 *   - When the server responds with `refreshing:true`, keeps a short
 *     memory of the current fetchedAt, polls once more after 5s, and
 *     resolves with the newer payload plus an `_updated:true` marker
 *     so the skin can toast "Info updated".
 *
 * Public shape (unchanged so the skin doesn't care where data comes from):
 *   { placeId, rating, userRatingCount, openNow, todaysHours,
 *     weekdayHours, googleMapsUri, reviewsUri, directionsUri,
 *     websiteUri, phone, photoUrl, reviews, _updated? }
 * Returns null when disabled / not found / on network error.
 */
(function (root) {
    'use strict';

    var Innsight = root.Innsight = root.Innsight || {};

    /**
     * Request enrichment for one POI. Uses fetch (with a modest
     * timeout so a slow Google reply doesn't hang the sheet forever).
     * The skin passes the poi through so we can hand the server the
     * lat/lon/title it needs to search Places when no placeId exists.
     */
    function fetchOnce(poi, endpoint) {
        var url = endpoint
            + (endpoint.indexOf('?') === -1 ? '?' : '&')
            + 'poi_id=' + encodeURIComponent(String(poi.id || ''))
            + '&title=' + encodeURIComponent(String(poi.title || poi.name || ''))
            + '&lat=' + encodeURIComponent(String(poi.lat || 0))
            + '&lon=' + encodeURIComponent(String(poi.lon || 0))
            + (poi.googlePlaceId ? '&place_id=' + encodeURIComponent(poi.googlePlaceId) : '');

        return root.fetch(url, { method: 'GET', credentials: 'omit' })
            .then(function (r) { return r.ok ? r.json() : { data: null }; })
            .catch(function () { return { data: null }; });
    }

    /**
     * Public API. Resolves with the shape the skin templates expect
     * (or null when nothing is available). Handles the stale-while-
     * revalidate flow: if the first response is `refreshing:true`
     * we poll once more after a short delay and, if the second
     * response is genuinely newer, resolve with `_updated:true` so
     * the skin can flash an "Info updated" toast.
     */
    function enrichPoi(poi, config) {
        if (!poi || !poi.id) return Promise.resolve(null);
        var endpoint = config && config.ui && config.ui.placesUrl;
        if (!endpoint) return Promise.resolve(null);

        return fetchOnce(poi, endpoint).then(function (initial) {
            if (initial && initial.data && !initial.refreshing) {
                return initial.data;
            }
            // No data yet, or stale-and-refreshing. Poll after 5s to
            // pick up the freshly-cached row.
            var initialFetched = initial && initial.fetchedAt ? initial.fetchedAt : '';
            return new Promise(function (resolve) {
                setTimeout(function () {
                    fetchOnce(poi, endpoint).then(function (second) {
                        if (!second || !second.data) {
                            resolve(initial && initial.data ? initial.data : null);
                            return;
                        }
                        // Second poll landed with data. If fetchedAt
                        // has advanced OR the first pass returned no
                        // data at all, mark this as an update so the
                        // skin can show a toast.
                        var updated = (!initialFetched && second.data)
                            || (second.fetchedAt && second.fetchedAt !== initialFetched);
                        if (updated) second.data._updated = true;
                        resolve(second.data);
                    });
                }, 5000);
            });
        });
    }

    Innsight._enrichment = { enrichPoi: enrichPoi };
})(typeof window !== 'undefined' ? window : this);
