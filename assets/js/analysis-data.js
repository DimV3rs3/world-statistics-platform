/**
 * Analysis page — CSV input + AJAX run.
 */
(function($) {
    'use strict';

    function csvLineToFields(line, delimiter) {
        // Small CSV tokenizer for header line only (UI needs column names).
        // Supports quoted fields like: "a,b", "c".
        var res = [];
        var cur = '';
        var inQuotes = false;
        for (var i = 0; i < line.length; i++) {
            var ch = line[i];
            if (ch === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    // Escaped quote
                    cur += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
            } else if (!inQuotes && ch === delimiter) {
                res.push(cur);
                cur = '';
            } else {
                cur += ch;
            }
        }
        res.push(cur);
        return res.map(function(s) { return String(s).trim(); });
    }

    function getHeaderColumns($csv, delimiter, hasHeader) {
        var txt = String($csv.val() || '');
        var lines = txt.split(/\r\n|\n|\r/).map(function(l) { return l.trim(); }).filter(Boolean);
        if (!lines.length) return [];
        var headerLine = lines[0];
        if (!hasHeader) {
            var fields = csvLineToFields(headerLine, delimiter);
            return fields.map(function(_, idx) { return 'col_' + (idx + 1); });
        }
        return csvLineToFields(headerLine, delimiter);
    }

    function setStatus($el, text) {
        $el.text(text);
    }

    $(document).ready(function() {
        var $source = $('#wsp-analysis-source');
        var $csv = $('#wsp-analysis-csv');
        var $delim = $('#wsp-analysis-delim');
        var $hasHeader = $('#wsp-analysis-has-header');
        var $target = $('#wsp-analysis-target');
        var $k = $('#wsp-analysis-k');
        var $eps = $('#wsp-analysis-eps');
        var $minpts = $('#wsp-analysis-minpts');
        var $runBtn = $('#wsp-analysis-run');
        var $status = $('#wsp-analysis-status');
        var $out = $('#wsp-analysis-output');

        // Fill target select for the current CSV.
        function refreshTargetSelect() {
            var delimiter = String($delim.val() || ',');
            if (!delimiter || delimiter.length < 1) delimiter = ',';
            var cols = getHeaderColumns($csv, delimiter[0], $hasHeader.is(':checked'));

            $target.empty();
            $target.append('<option value="">(не выбрано)</option>');
            cols.forEach(function(c) {
                if (!c) return;
                $target.append('<option value="' + c.replace(/"/g, '&quot;') + '">' + c + '</option>');
            });
        }

        // Source selector swaps textarea sample.
        $source.on('change', function() {
            var key = String($(this).val() || '');
            if (key && key !== 'custom' && window.worldstatAnalysisSamples && window.worldstatAnalysisSamples[key]) {
                $csv.val(window.worldstatAnalysisSamples[key]);
                refreshTargetSelect();
            }
        });

        // Refresh on key input changes.
        $delim.on('input', refreshTargetSelect);
        $hasHeader.on('change', refreshTargetSelect);
        $csv.on('blur', refreshTargetSelect);

        // Initial fill.
        refreshTargetSelect();

        $runBtn.on('click', function() {
            var delimiter = String($delim.val() || ',');
            delimiter = delimiter.length ? delimiter[0] : ',';

            var payload = {
                action: 'worldstat_run_data_analysis',
                nonce: window.worldstatPlatform ? window.worldstatPlatform.nonce : '',
                csv: String($csv.val() || ''),
                delimiter: delimiter,
                has_header: $hasHeader.is(':checked') ? 1 : 0,
                target_column: String($target.val() || ''),
                k: parseInt($k.val(), 10) || 3,
                eps: parseFloat($eps.val()) || 0.5,
                minpts: parseInt($minpts.val(), 10) || 3
            };

            if (!payload.csv || payload.csv.length < 30) {
                setStatus($status, 'Введите CSV (минимум несколько строк).');
                return;
            }

            $runBtn.prop('disabled', true);
            setStatus($status, 'Выполняется анализ...');

            $.ajax({
                url: window.worldstatPlatform.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(resp) {
                    if (resp && resp.success && resp.data && resp.data.html) {
                        $out.html(resp.data.html);
                        setStatus($status, 'Готово.');
                    } else {
                        $out.html('');
                        setStatus($status, (resp && resp.data && resp.data.message) ? resp.data.message : 'Ошибка анализа.');
                    }
                },
                error: function() {
                    $out.html('');
                    setStatus($status, 'Ошибка сети. Попробуйте ещё раз.');
                },
                complete: function() {
                    $runBtn.prop('disabled', false);
                }
            });
        });
    });
})(jQuery);

