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

                if (config.type === 'area') {
                    dataset.fill = true;
                }

                return dataset;
            });

            var options = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: datasets.length > 1 || type === 'pie' || type === 'doughnut',
                        position: 'top',
                    },
                    title: {
                        display: false, // title rendered in PHP
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
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
                    beginAtZero: true
                };
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
