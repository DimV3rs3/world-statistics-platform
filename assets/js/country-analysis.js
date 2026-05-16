/**
 * Country page analytics — regression, clustering, classification (native API).
 */
(function ($) {
	'use strict';

	var chartSeq = 0;

	function esc(s) {
		return String(s || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function setStatus(text) {
		$('#wsp-country-analysis-status').text(text || '');
	}

	function renderChart($container, chartCfg) {
		if (!chartCfg || typeof window.WSPChart === 'undefined') {
			return;
		}
		chartSeq += 1;
		var id = 'wsp-ca-chart-' + chartSeq;
		var h = chartCfg.height || 260;
		var html = '<div class="wsp-ca-chart wsp-chart-wrap">';
		if (chartCfg.title) {
			html += '<h4 class="wsp-chart-title">' + esc(chartCfg.title) + '</h4>';
		}
		html += '<div class="wsp-chart-canvas-wrap" style="position:relative;height:' + h + 'px;">';
		html += '<canvas id="' + id + '"></canvas></div></div>';
		$container.append(html);

		var cfg = {
			type: chartCfg.type || 'line',
			labels: chartCfg.labels || [],
			datasets: chartCfg.datasets || [],
			xLabel: chartCfg.x_label || chartCfg.xLabel || '',
			yLabel: chartCfg.y_label || chartCfg.yLabel || '',
			legend: chartCfg.legend !== false
		};

		function tryRender(attempt) {
			var canvas = document.getElementById(id);
			if (!canvas || typeof Chart === 'undefined' || !window.WSPChart) {
				if ((attempt || 0) < 40) {
					setTimeout(function () { tryRender((attempt || 0) + 1); }, 80);
				}
				return;
			}
			requestAnimationFrame(function () {
				requestAnimationFrame(function () {
					window.WSPChart.render(id, cfg);
				});
			});
		}
		tryRender(0);
	}

	function renderCharts($block, charts) {
		if (!charts || !charts.length) {
			return;
		}
		charts.forEach(function (c) {
			renderChart($block, c);
		});
	}

	function blockShell(title, desc) {
		var html = '<article class="wsp-ca-block">';
		html += '<h4 class="wsp-ca-block__title">' + esc(title) + '</h4>';
		if (desc) {
			html += '<p class="wsp-ca-block__desc">' + esc(desc) + '</p>';
		}
		return $(html + '</article>');
	}

	function renderRegression($root, data) {
		var $b = blockShell(data.title, data.description);
		if (!data.ok) {
			$b.append('<p class="wsp-ca-notice">' + esc(data.message) + '</p>');
			$root.append($b);
			return;
		}
		var i18n = (window.wspCountryAnalysis && window.wspCountryAnalysis.i18n) || {};
		var st = data.stats || {};
		var html = '<div class="wsp-ca-stats">';
		html += '<span><strong>' + esc(i18n.r2 || 'R²') + ':</strong> ' + esc(st.r2) + '</span>';
		html += '<span><strong>' + esc(i18n.trend || 'Тренд') + ':</strong> ' + esc(st.direction) + '</span>';
		if (st.forecast) {
			html += '<span><strong>' + esc(i18n.forecast || 'Прогноз') + ':</strong> '
				+ esc(st.forecast.value) + ' (' + esc(st.forecast.year) + ' ' + esc(i18n.year || 'г.') + ')</span>';
		}
		html += '</div>';
		$b.append(html);
		$root.append($b);
		renderChart($b, data.chart);
	}

	function renderClustering($root, data) {
		var $b = blockShell(data.title, data.description);
		if (!data.ok) {
			$b.append('<p class="wsp-ca-notice">' + esc(data.message) + '</p>');
			$root.append($b);
			return;
		}
		var i18n = (window.wspCountryAnalysis && window.wspCountryAnalysis.i18n) || {};
		var $ul = $('<ul class="wsp-ca-groups"></ul>');
		(data.groups || []).forEach(function (group, idx) {
			if (!group || !group.length) {
				return;
			}
			$ul.append('<li><strong>' + esc((i18n.cluster || 'Кластер') + ' ' + (idx + 1)) + ':</strong> '
				+ esc(group.join(', ')) + '</li>');
		});
		$b.append($ul);
		$root.append($b);
		renderCharts($b, data.charts);
	}

	function renderClassification($root, data) {
		var $b = blockShell(data.title, data.description);
		if (!data.ok) {
			$b.append('<p class="wsp-ca-notice">' + esc(data.message) + '</p>');
			$root.append($b);
			return;
		}
		var rows = data.timeline || [];
		if (rows.length) {
			var tbl = '<table class="wsp-ca-timeline"><thead><tr><th>'
				+ esc((window.wspCountryAnalysis && window.wspCountryAnalysis.i18n && window.wspCountryAnalysis.i18n.years) || 'Год')
				+ '</th><th>' + esc('Период') + '</th></tr></thead><tbody>';
			rows.forEach(function (r) {
				tbl += '<tr><td>' + esc(r.year) + '</td><td>' + esc(r.period) + '</td></tr>';
			});
			tbl += '</tbody></table>';
			$b.append(tbl);
		}
		$root.append($b);
		renderCharts($b, data.charts);
	}

	function renderResults(payload) {
		var $root = $('#wsp-country-analysis-results');
		$root.empty();
		if (!payload || !payload.ok) {
			$root.html('<p class="wsp-ca-notice">' + esc(payload && payload.error ? payload.error : 'Ошибка') + '</p>');
			return;
		}
		renderRegression($root, payload.regression || {});
		renderClustering($root, payload.clustering || {});
		renderClassification($root, payload.classification || {});
	}

	function runAnalysis() {
		var cfg = window.wspCountryAnalysis;
		if (!cfg || !cfg.ajaxUrl) {
			return;
		}
		var $btn = $('#wsp-country-analysis-run');
		$btn.prop('disabled', true);
		setStatus(cfg.i18n && cfg.i18n.running ? cfg.i18n.running : '…');

		$.post(cfg.ajaxUrl, {
			action: 'worldstat_run_country_analysis',
			nonce: cfg.nonce,
			post_id: cfg.postId,
			metric_id: $('#wsp-country-analysis-metric').val() || '',
			k: parseInt($('#wsp-country-analysis-k').val(), 10) || 3
		})
			.done(function (res) {
				if (res && res.success && res.data) {
					renderResults(res.data);
					setStatus(cfg.i18n && cfg.i18n.done ? cfg.i18n.done : '');
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Error';
					$('#wsp-country-analysis-results').html('<p class="wsp-ca-notice">' + esc(msg) + '</p>');
					setStatus(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : '');
				}
			})
			.fail(function (xhr) {
				var msg = 'Request failed';
				try {
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					}
				} catch (e) { /* ignore */ }
				$('#wsp-country-analysis-results').html('<p class="wsp-ca-notice">' + esc(msg) + '</p>');
				setStatus(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : '');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	}

	$(document).ready(function () {
		if (!$('#wsp-country-analytics').length) {
			return;
		}
		$('#wsp-country-analysis-run').on('click', runAnalysis);
		if (window.wspCountryAnalysis && window.wspCountryAnalysis.autoRun) {
			runAnalysis();
		}
	});
}(jQuery));
