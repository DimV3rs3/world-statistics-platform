/**
 * World Statistics Platform — core frontend JS.
 *
 * Tab switching, table search/sort, compare form, common utilities.
 */
(function($) {
    'use strict';

    var WSP = window.WSP = window.WSP || {};

    /* ═══════════════════════════════════════════════════════
       TABS
    ═══════════════════════════════════════════════════════ */
    WSP.Tabs = {
        init: function() {
            var self = this;
            $(document).on('click', '.wsp-tab-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var tabId = $btn.data('tab');
                var $container = $btn.closest('.wsp-tabs');

                // Update buttons
                $container.find('.wsp-tab-btn').removeClass('wsp-tab-active').attr('aria-selected', 'false');
                $btn.addClass('wsp-tab-active').attr('aria-selected', 'true');

                // Update panels
                $container.find('.wsp-tab-panel').removeClass('wsp-tab-panel-active');
                var $panel = $container.find('.wsp-tab-panel[data-tab="' + tabId + '"]');
                $panel.addClass('wsp-tab-panel-active');

                // Load via AJAX if panel has loading indicator
                if ($panel.find('.wsp-tab-loading').length) {
                    self.loadTab(tabId, $container.data('iso2'), $panel);
                }
            });
        },

        loadTab: function(tabId, iso2, $panel) {
            $.ajax({
                url: worldstatPlatform.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'worldstat_load_tab',
                    tab: tabId,
                    iso2: iso2
                },
                success: function(response) {
                    if (response.success) {
                        $panel.html(response.data.html);
                        // Re-init components in new content
                        WSP.Tables.init($panel);
                        $(document).trigger('wsp:tab:loaded', [tabId, iso2, $panel]);
                    } else {
                        $panel.html('<p class="wsp-error">Ошибка загрузки вкладки.</p>');
                    }
                },
                error: function() {
                    $panel.html('<p class="wsp-error">Ошибка сети. Попробуйте снова.</p>');
                }
            });
        }
    };

    /* ═══════════════════════════════════════════════════════
       TABLE SORT + SEARCH
    ═══════════════════════════════════════════════════════ */
    WSP.Tables = {
        init: function($scope) {
            var $ctx = $scope || $(document);

            // Sortable headers
            $ctx.find('.wsp-sortable th').off('click').on('click', function() {
                var $th = $(this);
                var idx = $th.index();
                var $table = $th.closest('table');
                var $tbody = $table.find('tbody');
                var rows = $tbody.find('tr').get();
                var asc = !$th.hasClass('wsp-sort-asc');

                $table.find('th').removeClass('wsp-sort-asc wsp-sort-desc');
                $th.addClass(asc ? 'wsp-sort-asc' : 'wsp-sort-desc');

                rows.sort(function(a, b) {
                    var va = $(a).children('td').eq(idx).text().trim();
                    var vb = $(b).children('td').eq(idx).text().trim();
                    var na = parseFloat(va.replace(/[^\d.-]/g, ''));
                    var nb = parseFloat(vb.replace(/[^\d.-]/g, ''));
                    if (!isNaN(na) && !isNaN(nb)) return asc ? na - nb : nb - na;
                    return asc ? va.localeCompare(vb) : vb.localeCompare(va);
                });

                $.each(rows, function(_, row) { $tbody.append(row); });
            });

            // Search
            $ctx.find('.wsp-table-search').off('input').on('input', function() {
                var query = $(this).val().toLowerCase();
                var tableId = $(this).data('target');
                $('#' + tableId + ' tbody tr').each(function() {
                    var text = $(this).text().toLowerCase();
                    $(this).toggle(text.indexOf(query) !== -1);
                });
            });

            // CSV Export
            $ctx.find('.wsp-table-export').off('click').on('click', function() {
                var tableId = $(this).data('target');
                var $table = $('#' + tableId + ' table');
                var csv = [];

                $table.find('tr').each(function() {
                    var row = [];
                    $(this).find('th, td').each(function() {
                        row.push('"' + $(this).text().replace(/"/g, '""') + '"');
                    });
                    csv.push(row.join(','));
                });

                var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'worldstat-export.csv';
                link.click();
            });
        }
    };

    /* ═══════════════════════════════════════════════════════
       COMPARE PAGE
    ═══════════════════════════════════════════════════════ */
    WSP.Compare = {
        init: function() {
            $('#wsp-compare-add').on('click', function() {
                var $inputs = $('#wsp-compare-inputs');
                if ($inputs.find('select').length >= 5) return;
                var $clone = $inputs.find('select:first').clone();
                $clone.val('');
                $inputs.append($clone);
            });

            $('.wsp-compare-form').on('submit', function(e) {
                e.preventDefault();
                var codes = [];
                $(this).find('select[name="country[]"]').each(function() {
                    var v = $(this).val();
                    if (v) codes.push(v);
                });
                if (codes.length < 2) {
                    alert('Выберите минимум 2 страны для сравнения.');
                    return;
                }
                window.location.href = window.location.pathname + '?c=' + codes.join(',');
            });
        }
    };

    /* ═══════════════════════════════════════════════════════
       COUNTRIES SEARCH (archive page)
    ═══════════════════════════════════════════════════════ */
    WSP.CountriesSearch = {
        init: function() {
            var $grid = $('.wsp-countries-grid');
            if (!$grid.length) return;

            // Real-time filter for the grid
            $('.wsp-search-input').on('input', function() {
                var q = $(this).val().toLowerCase();
                $grid.find('.wsp-country-card').each(function() {
                    var name = $(this).find('.wsp-country-card-name').text().toLowerCase();
                    $(this).toggle(name.indexOf(q) !== -1);
                });
            });
        }
    };

    /* ═══════════════════════════════════════════════════════
       INIT
    ═══════════════════════════════════════════════════════ */
    $(document).ready(function() {
        WSP.Tabs.init();
        WSP.Tables.init();
        WSP.Compare.init();
        WSP.CountriesSearch.init();
    });

})(jQuery);
