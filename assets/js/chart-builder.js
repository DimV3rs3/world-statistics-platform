/**
 * World Statistics Platform — Chart.js wrapper.
 *
 * Used by WorldStat_UI::chart() component.
 */
(function() {
    'use strict';

    var defaultColors = [
        '#3366cc', '#dc3912', '#ff9900', '#109618', '#990099',
        '#0099c6', '#dd4477', '#66aa00', '#b82e2e', '#316395'
    ];

    var WSPChart = window.WSPChart = {

        instances: {},

        render: function(canvasId, config) {
            var canvas = document.getElementById(canvasId);
            if (!canvas || typeof Chart === 'undefined') return;

            var ctx = canvas.getContext('2d');
            var type = config.type || 'line';
            if (type === 'area') type = 'line';

            var datasets = (config.datasets || []).map(function(ds, i) {
                var color = ds.color || defaultColors[i % defaultColors.length];
                var dataset = {
                    label: ds.label || '',
                    data: ds.data || [],
                    borderColor: color,
                    backgroundColor: (type === 'pie' || type === 'doughnut')
                        ? defaultColors.slice(0, (ds.data || []).length)
                        : color + '33',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                };

                if (type === 'scatter') {
                    dataset.showLine = false;
                    dataset.pointRadius = 6;
                    dataset.pointHoverRadius = 9;
                    dataset.pointBorderWidth = 1.5;
                    dataset.pointBorderColor = color;
                    dataset.backgroundColor = color + '99';
                }

                if (config.type === 'area') {
                    dataset.fill = true;
                }

                if (ds.borderDash && ds.borderDash.length) {
                    dataset.borderDash = ds.borderDash;
                }
                if (ds.pointRadius !== undefined && ds.pointRadius !== null) {
                    dataset.pointRadius = ds.pointRadius;
                }
                if (ds.pointHoverRadius !== undefined && ds.pointHoverRadius !== null) {
                    dataset.pointHoverRadius = ds.pointHoverRadius;
                }
                if (ds.fill === false) {
                    dataset.fill = false;
                }

                return dataset;
            });

            var options = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: (config.legend !== undefined && config.legend !== null)
                            ? !!config.legend
                            : (datasets.length > 1 || type === 'pie' || type === 'doughnut'),
                        position: 'top',
                    },
                    title: {
                        display: false, // title rendered in PHP
                    },
                    tooltip: {
                        mode: type === 'scatter' ? 'nearest' : 'index',
                        intersect: type === 'scatter',
                        callbacks: type === 'scatter' ? {
                            label: function(ctx) {
                                var raw = ctx.raw || {};
                                var name = raw.label ? (raw.label + ': ') : '';
                                var x = typeof ctx.parsed.x === 'number' ? ctx.parsed.x.toFixed(2) : ctx.parsed.x;
                                var y = typeof ctx.parsed.y === 'number' ? ctx.parsed.y.toFixed(2) : ctx.parsed.y;
                                return name + '(' + x + ', ' + y + ')';
                            }
                        } : undefined
                    }
                },
                scales: {}
            };

            if (type !== 'pie' && type !== 'doughnut') {
                options.scales.x = {
                    title: {
                        display: !!config.xLabel,
                        text: config.xLabel || ''
                    }
                };
                options.scales.y = {
                    title: {
                        display: !!config.yLabel,
                        text: config.yLabel || ''
                    },
                    beginAtZero: type !== 'scatter'
                };
                if (type === 'scatter') {
                    options.scales.x.type = 'linear';
                    options.scales.y.type = 'linear';
                    var tickFmt = function(v) {
                        var n = Number(v);
                        if (!isFinite(n)) return v;
                        if (Math.abs(n) >= 100 || (Math.abs(n) > 0 && Math.abs(n) < 0.05)) {
                            return n.toFixed(2);
                        }
                        return n.toFixed(2);
                    };
                    options.scales.x.ticks = { callback: tickFmt };
                    options.scales.y.ticks = { callback: tickFmt };
                }
            }

            // Destroy existing chart if re-rendering
            if (this.instances[canvasId]) {
                this.instances[canvasId].destroy();
            }

            this.instances[canvasId] = new Chart(ctx, {
                type: type,
                data: {
                    labels: config.labels || [],
                    datasets: datasets
                },
                options: options
            });
        }
    };

})();
