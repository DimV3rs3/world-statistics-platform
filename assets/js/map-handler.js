/**
 * World Statistics Platform — SVG Map handler.
 *
 * Works with the ergonosphera theme map OR renders its own if needed.
 * Supports layer switching for extension choropleth / heatmap layers.
 */
(function($) {
    'use strict';

    var WSPMap = window.WSPMap = {

        countryUrls: {},
        layers: [],
        activeLayer: null,

        init: function() {
            if (typeof worldstatMap === 'undefined') return;

            this.countryUrls = worldstatMap.countryUrls || {};
            this.layers      = worldstatMap.layers || [];

            this.bindMapClicks();
            this.bindLayerSwitcher();
        },

        /**
         * Bind click events on SVG map paths to navigate to country pages.
         * Works with both the ergonosphera theme map and our own map.
         */
        bindMapClicks: function() {
            var self = this;

            // Theme map integration: look for paths with data-id or data-a2 attributes
            $(document).on('click', '.svg-map-container path[data-a2], #wsp-svg-map path[data-a2]', function(e) {
                var iso2 = $(this).attr('data-a2') || $(this).data('a2');
                if (iso2 && self.countryUrls[iso2]) {
                    e.preventDefault();
                    window.location.href = self.countryUrls[iso2];
                }
            });

            // Also support the theme's click handler format
            $(document).on('worldstat:map:country_click', function(e, iso2) {
                if (iso2 && self.countryUrls[iso2]) {
                    window.location.href = self.countryUrls[iso2];
                }
            });
        },

        /**
         * Layer switcher dropdown.
         */
        bindLayerSwitcher: function() {
            var self = this;

            $('#wsp-layer-select').on('change', function() {
                var layerId = $(this).val();
                if (!layerId) {
                    self.resetMapColors();
                    self.activeLayer = null;
                    return;
                }
                self.loadLayer(layerId);
            });
        },

        /**
         * Load layer data from REST API and apply choropleth.
         */
        loadLayer: function(layerId) {
            var self = this;
            var layerConfig = null;

            for (var i = 0; i < this.layers.length; i++) {
                if (this.layers[i].id === layerId) {
                    layerConfig = this.layers[i];
                    break;
                }
            }

            if (!layerConfig) return;

            $.ajax({
                url: worldstatMap.restUrl + 'map-layers/' + layerId + '/data',
                headers: { 'X-WP-Nonce': worldstatMap.nonce },
                success: function(data) {
                    self.activeLayer = layerConfig;
                    self.applyChoropleth(data, layerConfig.colorScale || ['#f0f0f0', '#003d99']);
                }
            });
        },

        /**
         * Apply choropleth coloring to map paths.
         */
        applyChoropleth: function(data, colorScale) {
            if (!data || typeof data !== 'object') return;

            var values = Object.values(data).filter(function(v) { return !isNaN(v); });
            if (values.length === 0) return;

            var min = Math.min.apply(null, values);
            var max = Math.max.apply(null, values);
            var range = max - min || 1;

            var startColor = this.hexToRgb(colorScale[0]);
            var endColor   = this.hexToRgb(colorScale[1] || colorScale[0]);

            var $paths = $('path[data-a2]');
            $paths.each(function() {
                var iso2 = $(this).attr('data-a2');
                var val  = data[iso2];

                if (val !== undefined && !isNaN(val)) {
                    var t = (val - min) / range;
                    var r = Math.round(startColor.r + (endColor.r - startColor.r) * t);
                    var g = Math.round(startColor.g + (endColor.g - startColor.g) * t);
                    var b = Math.round(startColor.b + (endColor.b - startColor.b) * t);
                    $(this).css('fill', 'rgb(' + r + ',' + g + ',' + b + ')');
                } else {
                    $(this).css('fill', '#e0e0e0');
                }
            });
        },

        /**
         * Reset map colors to default.
         */
        resetMapColors: function() {
            $('path[data-a2]').css('fill', '');
        },

        hexToRgb: function(hex) {
            hex = hex.replace('#', '');
            if (hex.length === 3) {
                hex = hex[0]+hex[0] + hex[1]+hex[1] + hex[2]+hex[2];
            }
            return {
                r: parseInt(hex.substr(0, 2), 16),
                g: parseInt(hex.substr(2, 2), 16),
                b: parseInt(hex.substr(4, 2), 16)
            };
        },

        /**
         * Show tooltip at mouse position.
         */
        showTooltip: function(text, event) {
            var $tip = $('#wsp-map-tooltip');
            $tip.text(text).css({
                display: 'block',
                left: event.pageX + 10,
                top: event.pageY - 30
            });
        },

        hideTooltip: function() {
            $('#wsp-map-tooltip').hide();
        }
    };

    $(document).ready(function() {
        WSPMap.init();
    });

})(jQuery);
