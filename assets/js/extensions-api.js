/**
 * World Statistics Platform — JS API for extensions.
 *
 * Provides an event system and data-fetch utilities extensions can use.
 */
(function($) {
    'use strict';

    var WSPExtensions = window.WSPExtensions = {

        /**
         * Fetch data from the platform REST API.
         */
        getData: function(extId, countryCode, metric) {
            return $.ajax({
                url: worldstatPlatform.restUrl + 'data/' + extId + '/' + countryCode + '/' + metric,
                headers: { 'X-WP-Nonce': worldstatPlatform.nonce },
                dataType: 'json'
            });
        },

        /**
         * Fetch full country data.
         */
        getCountry: function(countryCode) {
            return $.ajax({
                url: worldstatPlatform.restUrl + 'countries/' + countryCode,
                headers: { 'X-WP-Nonce': worldstatPlatform.nonce },
                dataType: 'json'
            });
        },

        /**
         * Fetch list of all countries.
         */
        getCountries: function(params) {
            return $.ajax({
                url: worldstatPlatform.restUrl + 'countries',
                data: params || {},
                headers: { 'X-WP-Nonce': worldstatPlatform.nonce },
                dataType: 'json'
            });
        },

        /**
         * Compare countries.
         */
        compare: function(countries, metrics) {
            return $.ajax({
                url: worldstatPlatform.restUrl + 'compare',
                data: {
                    countries: countries.join(','),
                    metrics: metrics.join(',')
                },
                headers: { 'X-WP-Nonce': worldstatPlatform.nonce },
                dataType: 'json'
            });
        },

        /**
         * Get available metrics.
         */
        getMetrics: function() {
            return $.ajax({
                url: worldstatPlatform.restUrl + 'metrics',
                headers: { 'X-WP-Nonce': worldstatPlatform.nonce },
                dataType: 'json'
            });
        },

        /**
         * Get active extensions list.
         */
        getExtensions: function() {
            return $.ajax({
                url: worldstatPlatform.restUrl + 'extensions',
                headers: { 'X-WP-Nonce': worldstatPlatform.nonce },
                dataType: 'json'
            });
        },

        /* ─── Event System ──────────────────────────────── */

        _listeners: {},

        /**
         * Subscribe to a platform event.
         */
        on: function(event, callback) {
            if (!this._listeners[event]) this._listeners[event] = [];
            this._listeners[event].push(callback);
        },

        /**
         * Emit a platform event.
         */
        emit: function(event, data) {
            var listeners = this._listeners[event] || [];
            for (var i = 0; i < listeners.length; i++) {
                listeners[i](data);
            }
            // Also trigger on document for jQuery listeners
            $(document).trigger('wsp:' + event, [data]);
        },

        /**
         * Format a number with locale-aware separators.
         */
        formatNumber: function(num, decimals) {
            if (typeof num !== 'number') num = parseFloat(num) || 0;
            return num.toLocaleString('ru-RU', {
                minimumFractionDigits: decimals || 0,
                maximumFractionDigits: decimals || 0
            });
        }
    };

})(jQuery);
