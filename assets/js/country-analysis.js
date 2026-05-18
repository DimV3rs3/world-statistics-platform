/**
 * Country page analytics — regression, clustering, classification.
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

	function filterClusterPicks() {
		var cat = $('#wsp-country-analysis-cluster-cat').val() || 'all';
		$('#wsp-cluster-metric-picks .wsp-ca-metric-pick').each(function () {
			var $el = $(this);
			var show = cat === 'all' || $el.data('category') === cat;
			$el.toggle(show);
		});
	}

	function setVisibleClusterChecks(checked) {
		var cat = $('#wsp-country-analysis-cluster-cat').val() || 'all';
		$('#wsp-cluster-metric-picks .wsp-ca-metric-pick').each(function () {
			var $el = $(this);
			if (!$el.is(':visible')) {
				return;
			}
			if (cat === 'all' || $el.data('category') === cat) {
				$el.find('input[type="checkbox"]').prop('checked', !!checked);
			}
		});
	}

	function collectClusterMetricIds() {
		var ids = [];
		$('#wsp-cluster-metric-picks input[type="checkbox"]:checked').each(function () {
			var v = $(this).val();
			if (v) {
				ids.push(v);
			}
		});
		return ids;
	}

	function renderChart($container, chartCfg) {
		if (!chartCfg) {
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
	}

	function renderClustering($root, data) {
		var $b = blockShell(data.title, data.description);
		if (!data.ok) {
			$b.append('<p class="wsp-ca-notice">' + esc(data.message) + '</p>');
			$root.append($b);
			return;
		}
		var $ul = $('<ul class="wsp-ca-groups"></ul>');
		(data.groups || []).forEach(function (group, idx) {
			if (!group || !group.length) {
				return;
			}
			$ul.append('<li><strong>' + esc(i18n('cluster', 'Кластер') + ' ' + (idx + 1)) + ':</strong> '
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
				+ esc(i18n('years', 'Год')) + '</th><th>'
				+ esc(i18n('value', 'Значение')) + '</th><th>'
				+ esc(i18n('period', 'Уровень')) + '</th></tr></thead><tbody>';
			rows.forEach(function (r) {
				tbl += '<tr><td>' + esc(r.year) + '</td><td>' + esc(r.value) + '</td><td>' + esc(r.period) + '</td></tr>';
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

		var metricId = $('#wsp-country-analysis-metric').val() || '';
		var clusterIds = collectClusterMetricIds();

		if (!metricId && !clusterIds.length) {
			setStatus(i18n('pickEither', 'Выберите показатель и/или отметьте показатели для кластеризации.'));
			$('#wsp-country-analysis-results').html(
				'<p class="wsp-ca-notice">' + esc(i18n('pickEither', 'Выберите показатель и/или отметьте показатели.')) + '</p>'
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
			metric_id: metricId,
			k_cluster: parseInt($('#wsp-country-analysis-k-cluster').val(), 10) || 3,
			k_classify: parseInt($('#wsp-country-analysis-k-classify').val(), 10) || 3,
			cluster_category: $('#wsp-country-analysis-cluster-cat').val() || 'all',
			cluster_metrics: clusterIds
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

		filterClusterPicks();

		$('#wsp-country-analysis-cluster-cat').on('change', filterClusterPicks);
		$('#wsp-cluster-select-visible').on('click', function () {
			setVisibleClusterChecks(true);
		});
		$('#wsp-cluster-clear-visible').on('click', function () {
			setVisibleClusterChecks(false);
		});

		$('#wsp-country-analysis-run').on('click', runAnalysis);
	});
}(jQuery));
