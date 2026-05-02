/**
 * World Statistics — общие скрипты аналитики
 */
(function() {
    'use strict';

    /* ── Экспорт CSV (общий) ────────────────────────── */
    window.wspExportCSV = function(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var csv = [];
        table.querySelectorAll('tr').forEach(function(row) {
            var cols = [];
            row.querySelectorAll('th, td').forEach(function(cell) {
                cols.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
            });
            csv.push(cols.join(','));
        });
        var blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename || 'export.csv';
        link.click();
    };

    /* ── Метрики: Выбрать все ───────────────────────── */
    document.querySelectorAll('.wsp-check-all-group').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var g = this.dataset.group;
            var c = this.checked;
            document.querySelectorAll('.wsp-metric-checkbox[data-group="' + g + '"]').forEach(function(m) {
                m.checked = c;
                m.closest('.wsp-metric-item').classList.toggle('is-checked', c);
            });
        });
    });

    /* ── Метрики: чекбокс → подсветка ───────────────── */
    document.querySelectorAll('.wsp-metric-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.closest('.wsp-metric-item').classList.toggle('is-checked', this.checked);
            var g = this.dataset.group;
            var all = document.querySelectorAll('.wsp-metric-checkbox[data-group="' + g + '"]');
            var allChecked = Array.from(all).every(function(c) { return c.checked; });
            var checkAll = document.querySelector('.wsp-check-all-group[data-group="' + g + '"]');
            if (checkAll) checkAll.checked = allChecked;
        });
    });

})();

/* РЕЙТИНГИ — аккордеон  */
window.wspToggleDropdown = function() {
    var dropdown = document.getElementById('wsp-metric-dropdown');
    var arrow = document.getElementById('wsp-dropdown-arrow');
    var regionDropdown = document.getElementById('wsp-region-dropdown');
    var regionArrow = document.getElementById('wsp-region-arrow');
    
    if (regionDropdown) {
        regionDropdown.style.display = 'none';
        if (regionArrow) regionArrow.classList.remove('rotated');
    }
    
    var isOpen = dropdown && dropdown.style.display !== 'none';
    if (isOpen) {
        dropdown.style.display = 'none';
        if (arrow) arrow.classList.remove('rotated');
    } else if (dropdown) {
        dropdown.style.display = 'block';
        if (arrow) arrow.classList.add('rotated');
    }
};

window.wspToggleGroup = function(header) {
    var body = header.nextElementSibling;
    var isOpen = header.classList.contains('open');
    
    document.querySelectorAll('#wsp-metric-dropdown .wsp-dropdown__group-header').forEach(function(h) {
        h.classList.remove('open');
        if (h.nextElementSibling) h.nextElementSibling.style.display = 'none';
    });
    
    if (!isOpen) {
        header.classList.add('open');
        if (body) body.style.display = 'block';
    }
};

window.wspSelectMetric = function(option) {
    var value = option.getAttribute('data-value');
    var label = option.getAttribute('data-label');
    var unit = option.getAttribute('data-unit') || '';
    
    var input = document.getElementById('wsp-metric-input');
    var labelEl = document.getElementById('wsp-metric-selected-label');
    var unitEl = document.getElementById('wsp-metric-selected-unit');
    
    if (input) input.value = value;
    if (labelEl) labelEl.textContent = label;
    if (unitEl) unitEl.textContent = unit;
    
    document.querySelectorAll('#wsp-metric-dropdown .wsp-dropdown__option').forEach(function(o) {
        o.classList.remove('selected');
        var check = o.querySelector('.wsp-metric-check');
        if (check) check.remove();
    });
    option.classList.add('selected');
    var check = document.createElement('span');
    check.className = 'wsp-metric-check';
    check.textContent = '✓';
    option.appendChild(check);
    
    var dropdown = document.getElementById('wsp-metric-dropdown');
    var arrow = document.getElementById('wsp-dropdown-arrow');
    if (dropdown) dropdown.style.display = 'none';
    if (arrow) arrow.classList.remove('rotated');
    
    var form = document.getElementById('wsp-rankings-form');
    if (form) form.submit();
};

window.wspToggleRegionDropdown = function() {
    var dropdown = document.getElementById('wsp-region-dropdown');
    var arrow = document.getElementById('wsp-region-arrow');
    var metricDropdown = document.getElementById('wsp-metric-dropdown');
    var metricArrow = document.getElementById('wsp-dropdown-arrow');
    
    if (metricDropdown) {
        metricDropdown.style.display = 'none';
        if (metricArrow) metricArrow.classList.remove('rotated');
    }
    
    var isOpen = dropdown && dropdown.style.display !== 'none';
    if (isOpen) {
        dropdown.style.display = 'none';
        if (arrow) arrow.classList.remove('rotated');
    } else if (dropdown) {
        dropdown.style.display = 'block';
        if (arrow) arrow.classList.add('rotated');
    }
};

window.wspToggleRegionGroup = function(header, event) {
    event.stopPropagation();
    var body = header.nextElementSibling;
    var isOpen = header.classList.contains('open');
    
    document.querySelectorAll('#wsp-region-dropdown .wsp-dropdown__group-header').forEach(function(h) {
        h.classList.remove('open');
        if (h.nextElementSibling) h.nextElementSibling.style.display = 'none';
    });
    
    if (!isOpen) {
        header.classList.add('open');
        if (body) body.style.display = 'block';
    }
};

window.wspSelectRegionGroup = function(slug, label, event) {
    event.stopPropagation();
    
    var input = document.getElementById('wsp-region-input');
    var labelEl = document.getElementById('wsp-region-selected-label');
    var dropdown = document.getElementById('wsp-region-dropdown');
    var arrow = document.getElementById('wsp-region-arrow');
    
    if (input) input.value = slug;
    if (labelEl) labelEl.textContent = label;
    if (dropdown) dropdown.style.display = 'none';
    if (arrow) arrow.classList.remove('rotated');
    
    var form = document.getElementById('wsp-rankings-form');
    if (form) form.submit();
};

window.wspSelectRegion = function(option, event) {
    if (event) event.stopPropagation();
    
    var value = option.getAttribute('data-value');
    var label = option.getAttribute('data-label');
    
    var input = document.getElementById('wsp-region-input');
    var labelEl = document.getElementById('wsp-region-selected-label');
    
    if (input) input.value = value;
    if (labelEl) labelEl.textContent = label;
    
    document.querySelectorAll('#wsp-region-dropdown .wsp-dropdown__option').forEach(function(o) {
        o.classList.remove('selected');
        var c = o.querySelector('.wsp-metric-check');
        if (c) c.remove();
    });
    option.classList.add('selected');
    var check = document.createElement('span');
    check.className = 'wsp-metric-check';
    check.textContent = '✓';
    option.appendChild(check);
    
    var dropdown = document.getElementById('wsp-region-dropdown');
    var arrow = document.getElementById('wsp-region-arrow');
    if (dropdown) dropdown.style.display = 'none';
    if (arrow) arrow.classList.remove('rotated');
    
    var form = document.getElementById('wsp-rankings-form');
    if (form) form.submit();
};

document.addEventListener('click', function(e) {
    var metricTrigger = document.getElementById('wsp-metric-dropdown-trigger');
    var metricDropdown = document.getElementById('wsp-metric-dropdown');
    if (metricTrigger && metricDropdown && 
        !metricTrigger.contains(e.target) && !metricDropdown.contains(e.target)) {
        metricDropdown.style.display = 'none';
        var arrow = document.getElementById('wsp-dropdown-arrow');
        if (arrow) arrow.classList.remove('rotated');
    }
    
    var regionTrigger = document.getElementById('wsp-region-dropdown-trigger');
    var regionDropdown = document.getElementById('wsp-region-dropdown');
    if (regionTrigger && regionDropdown && 
        !regionTrigger.contains(e.target) && !regionDropdown.contains(e.target)) {
        regionDropdown.style.display = 'none';
        var arrow = document.getElementById('wsp-region-arrow');
        if (arrow) arrow.classList.remove('rotated');
    }
});

/* СРАВНЕНИЕ СТРАН */
(function() {
    var wrap = document.getElementById('wsp-compare-countries-wrap');
    if (!wrap) return;
    var max = 5;

    function update() {
        var rows = wrap.querySelectorAll('.wsp-compare-row');
        
        // Показываем/скрываем кнопки удаления
        wrap.querySelectorAll('.wsp-btn--danger').forEach(function(b) {
            b.style.display = rows.length > 2 ? 'flex' : 'none';
        });
        
        // Блокируем кнопку добавления
        var add = document.getElementById('wsp-compare-add-btn');
        if (add) {
            add.disabled = rows.length >= max;
            add.style.opacity = rows.length >= max ? '0.4' : '';
        }
        
        // Фильтруем опции — убираем уже выбранные страны из других селектов
        var selectedValues = [];
        wrap.querySelectorAll('select').forEach(function(s) {
            if (s.value) selectedValues.push(s.value);
        });
        
        wrap.querySelectorAll('select').forEach(function(s) {
            var currentVal = s.value;
            s.querySelectorAll('option').forEach(function(opt) {
                if (opt.value && opt.value !== currentVal && selectedValues.indexOf(opt.value) !== -1) {
                    opt.disabled = true;
                    opt.style.color = '#94a3b8';
                } else {
                    opt.disabled = false;
                    opt.style.color = '';
                }
            });
        });
    }

    wrap.addEventListener('change', function(e) {
        if (e.target.tagName === 'SELECT') {
            e.target.classList.toggle('has-value', !!e.target.value);
            update();
        }
    });

    wrap.addEventListener('click', function(e) {
        if (!e.target.classList.contains('wsp-btn--danger')) return;
        if (wrap.querySelectorAll('.wsp-compare-row').length <= 2) return;
        e.target.closest('.wsp-compare-row').remove();
        update();
    });

    var addBtn = document.getElementById('wsp-compare-add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            if (wrap.querySelectorAll('.wsp-compare-row').length >= max) return;
            var tpl = wrap.querySelector('.wsp-compare-row').cloneNode(true);
            tpl.querySelector('select').value = '';
            tpl.querySelector('select').classList.remove('has-value');
            wrap.appendChild(tpl);
            update();
        });
    }
    update();
})();

/* КАТАЛОГ МЕТРИК */
(function() {
    var table = document.getElementById('wsp-metrics-table');
    if (!table) return;

    var filter = 'all';
    var search = '';

    function apply() {
        var n = 0;
        table.querySelectorAll('tbody tr').forEach(function(r) {
            var ok = (filter === 'all' || r.dataset.source === filter) &&
                     (search === '' || (r.dataset.search || '').indexOf(search) !== -1);
            r.style.display = ok ? '' : 'none';
            if (ok) n++;
        });
        var nr = document.getElementById('wsp-catalog-no-results');
        if (nr) nr.style.display = n ? 'none' : 'block';
    }

    document.querySelectorAll('#wsp-catalog-filters .wsp-tag').forEach(function(t) {
        t.addEventListener('click', function() {
            document.querySelectorAll('#wsp-catalog-filters .wsp-tag').forEach(function(x) { x.classList.remove('active'); });
            this.classList.add('active');
            filter = this.dataset.filter;
            apply();
        });
    });

    var si = document.getElementById('wsp-catalog-search-input');
    if (si) si.addEventListener('input', function() { search = this.value.toLowerCase().trim(); apply(); });

    table.querySelectorAll('th[data-sort]').forEach(function(th) {
        th.addEventListener('click', function() {
            var ci = Array.from(this.parentElement.children).indexOf(this);
            var asc = this.classList.contains('sorted-asc');
            table.querySelectorAll('th').forEach(function(h) { h.classList.remove('sorted-asc', 'sorted-desc'); });
            var rows = Array.from(table.querySelectorAll('tbody tr'));
            rows.sort(function(a, b) {
                var av = (a.cells[ci]?.textContent || '').trim().toLowerCase();
                var bv = (b.cells[ci]?.textContent || '').trim().toLowerCase();
                return av < bv ? (asc ? 1 : -1) : av > bv ? (asc ? -1 : 1) : 0;
            });
            this.classList.add(asc ? 'sorted-desc' : 'sorted-asc');
            rows.forEach(function(r) { table.querySelector('tbody').appendChild(r); });
        });
    });
})();