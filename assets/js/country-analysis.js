/**
 * Country page analytics — regression chart (ergonomics classification is static above).
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

	function i18n(key, fallback) {
		var cfg = window.wspCountryAnalysis;
		return (cfg && cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : fallback;
	}

	function parseAjaxJson(xhr) {
		if (xhr && xhr.responseJSON) {
			return xhr.responseJSON;
		}
		var raw = (xhr && xhr.responseText) ? xhr.responseText : '';
		raw = raw.replace(/^\uFEFF/, '');
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	function ajaxErrorMessage(xhr, fallback) {
		var res = parseAjaxJson(xhr);
		if (res && res.data && res.data.message) {
			return res.data.message;
		}
		return fallback || i18n('networkError', 'Ошибка сети. Попробуйте снова.');
	}

	function setStatus(text) {
		$('#wsp-country-analysis-status').text(text || '');
	}

	function renderChart($container, chartCfg) {
		if (!chartCfg) {
			return;
		}
		chartSeq += 1;
		var id = 'wsp-ca-chart-' + chartSeq;
		var h = chartCfg.height || 260;
		var html = '<div class="wsp-ca-chart">';
		if (chartCfg.title) {
			html += '<h4 class="wsp-chart-title">' + esc(chartCfg.title) + '</h4>';
		}
		if (chartCfg.subtitle) {
			html += '<p class="wsp-chart-subtitle wsp-muted">' + esc(chartCfg.subtitle) + '</p>';
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

	function blockShell(title, desc) {
		var html = '<article class="wsp-ca-block">';
		html += '<h4 class="wsp-ca-block__title">' + esc(title) + '</h4>';
		if (desc) {
			html += '<p class="wsp-ca-block__desc">' + esc(desc) + '</p>';
		}
		return $(html + '</article>');
	}

	function renderAnalysis($container, analysis) {
		if (!analysis || (!analysis.summary && !(analysis.insights && analysis.insights.length))) {
			return;
		}
		var title = i18n('analysisTitle', 'Аналитический вывод по показателю');
		var html = '<article class="wsergo-compare-analysis wsergo-compare-analysis--overview">';
		html += '<h4 class="wsergo-compare-analysis__title">' + esc(title) + '</h4>';
		if (analysis.summary) {
			html += '<p class="wsergo-compare-analysis__summary">' + esc(analysis.summary) + '</p>';
		}
		if (analysis.highlights && analysis.highlights.length) {
			html += '<div class="wsergo-compare-analysis__highlights">';
			analysis.highlights.forEach(function (h) {
				html += '<div class="wsergo-compare-analysis__highlight">';
				html += '<span class="wsergo-compare-analysis__hl-label">' + esc(h.label) + '</span>';
				html += '<strong>' + esc(h.value) + '</strong></div>';
			});
			html += '</div>';
		}
		if (analysis.insights && analysis.insights.length) {
			html += '<ul class="wsergo-compare-analysis__insights">';
			analysis.insights.forEach(function (line) {
				html += '<li>' + esc(line) + '</li>';
			});
			html += '</ul>';
		}
		html += '</article>';
		$container.append(html);
	}

	function renderRegression($root, data, analysis) {
		var $b = blockShell(data.title, data.description);
		if (!data.ok) {
			$b.append('<p class="wsp-ca-notice">' + esc(data.message) + '</p>');
			$root.append($b);
			return;
		}
		var st = data.stats || {};
		var html = '<div class="wsp-ca-stats">';
		html += '<span><strong>' + esc(i18n('r2', 'R²')) + ':</strong> ' + esc(st.r2) + '</span>';
		html += '<span><strong>' + esc(i18n('trend', 'Тренд')) + ':</strong> ' + esc(st.direction) + '</span>';
		if (st.forecast) {
			html += '<span><strong>' + esc(i18n('forecast', 'Прогноз')) + ':</strong> '
				+ esc(st.forecast.value) + ' (' + esc(st.forecast.year) + ' ' + esc(i18n('year', 'г.') ) + ')</span>';
		}
		html += '</div>';
		$b.append(html);
		$root.append($b);
		renderChart($b, data.chart);
		renderAnalysis($b, analysis || {});
		$root.find('.wsergo-tier-badge:empty').remove();
	}

	function renderResults(payload) {
		var $root = $('#wsp-country-analysis-results');
		$root.empty();
		if (!payload || !payload.ok) {
			$root.html('<p class="wsp-ca-notice">' + esc(payload && payload.error ? payload.error : 'Ошибка') + '</p>');
			return;
		}
		renderRegression($root, payload.regression || {}, payload.analysis || {});
	}

	function runAnalysis() {
		var cfg = window.wspCountryAnalysis;
		if (!cfg || !cfg.ajaxUrl) {
			return;
		}

		var metricId = $('#wsp-country-analysis-metric').val() || '';
		if (!metricId) {
			setStatus(i18n('pickMetric', 'Выберите показатель.'));
			$('#wsp-country-analysis-results').html(
				'<p class="wsp-ca-notice">' + esc(i18n('pickMetric', 'Выберите показатель.')) + '</p>'
			);
			return;
		}

		var $btn = $('#wsp-country-analysis-run');
		$btn.prop('disabled', true);
		setStatus(i18n('running', 'Расчёт…'));

		$.post(cfg.ajaxUrl, {
			action: 'worldstat_run_country_analysis',
			nonce: cfg.nonce,
			post_id: cfg.postId,
			metric_id: metricId
		})
			.done(function (res) {
				if (res && res.success && res.data) {
					renderResults(res.data);
					setStatus(i18n('done', 'Готово'));
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Error';
					$('#wsp-country-analysis-results').html('<p class="wsp-ca-notice">' + esc(msg) + '</p>');
					setStatus(i18n('error', 'Ошибка'));
				}
			})
			.fail(function (xhr) {
				var msg = ajaxErrorMessage(xhr, i18n('networkError', 'Ошибка сети. Попробуйте снова.'));
				$('#wsp-country-analysis-results').html('<p class="wsp-ca-notice">' + esc(msg) + '</p>');
				setStatus(i18n('error', 'Ошибка'));
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
	});
}(jQuery));
