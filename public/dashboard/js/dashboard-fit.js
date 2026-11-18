(function () {
    'use strict';
    // The shell persists across SPA navigation; repeated script tags reuse it.
    if (window.DashboardFit) { window.DashboardFit.refresh(); return; }
    // Makes the dashboard's lists fit any screen by themselves:
    // every data table learns its column names (so a phone can show each row as a card),
    // and a table that is not already inside a scrolling box gets one (so a laptop scrolls the table, never the page).
    // Full-screen work pages (cashier, dining, phone orders, the order board, inboxes, the live home) keep their own layouts.
    var SKIP = '.takeaway-wrapper,.dining-wrapper,.phone-orders-wrapper,.order-board-wrapper,.ho-wrapper,#go-hud,.wa-inbox-wrapper,.meta-comments-wrapper,.meta-connection-wrapper,[data-fit="off"]';
    var PHONE = 768, CARDS_UP_TO = 1200, pending = 0, observer = null, resizeTimer = 0;

    function text(cell) {
        var clone = cell.cloneNode(true);
        clone.querySelectorAll('input,select,button,script,style,.sr-only').forEach(function (n) { n.remove(); });
        return clone.textContent.replace(/\s+/g, ' ').trim();
    }
    function headers(table) {
        var row = table.tHead && table.tHead.rows.length ? table.tHead.rows[table.tHead.rows.length - 1] : null;
        if (!row) {
            // Some lists put their <th> cells straight in the table, without <thead>.
            var first = table.querySelector('tr'); if (first && first.querySelector('th') && !first.querySelector('td')) row = first;
        }
        if (!row) return null;
        var lines = table.tHead && table.tHead.rows.length ? Array.prototype.slice.call(table.tHead.rows) : [row], grid = [];
        lines.forEach(function (line, index) {
            if (!grid[index]) grid[index] = [];
            var column = 0;
            Array.prototype.forEach.call(line.cells, function (cell) {
                while (grid[index][column]) column++;
                var span = Math.max(1, cell.colSpan || 1), depth = Math.max(1, cell.rowSpan || 1);
                var info = {label: text(cell), check: !!cell.querySelector('input[type="checkbox"]')};
                for (var down = 0; down < depth && index + down < lines.length; down++) {
                    if (!grid[index + down]) grid[index + down] = [];
                    for (var across = 0; across < span; across++) grid[index + down][column + across] = info;
                }
                column += span;
            });
        });
        return {row: row, rows: lines, cells: grid[grid.length - 1] || []};
    }
    function isActions(cell) {
        // A cell of buttons: once its buttons and links are set aside, little or no text is left.
        if (!cell.querySelector('a.btn,button,.btn,form,.dropdown')) return false;
        var clone = cell.cloneNode(true);
        clone.querySelectorAll('a,button,.btn,form,.dropdown,input,select,.badge,i,svg').forEach(function (n) { n.remove(); });
        return clone.textContent.replace(/\s+/g, ' ').trim().length <= 24;
    }
    function fit(table) {
        if (table.closest(SKIP) || table.parentElement.closest('table')) return;
        var head = headers(table); if (!head || head.cells.length < 3) return;
        // Decided before the table is marked, while its box still shows its own scroll setting.
        var parent = table.parentElement, needsBox = !table.classList.contains('fit-table') && !parent.classList.contains('table-responsive') && !parent.classList.contains('fit-scroll') && getComputedStyle(parent).overflowX === 'visible';
        table.classList.add('fit-table'); head.row.classList.add('fit-head');
        var selection = false;
        head.rows.forEach(function (line) {
            var selected = false;
            Array.prototype.forEach.call(line.cells, function (cell) {
                var input = cell.querySelector('input[type="checkbox"]');
                cell.classList.toggle('fit-master', !!input);
                if (input) {
                    selected = selection = true;
                    if (!input.getAttribute('aria-label')) input.setAttribute('aria-label', document.documentElement.dir === 'rtl' ? 'تحديد الكل' : 'Select all');
                }
            });
            line.classList.toggle('fit-select-head', selected);
        });
        table.classList.toggle('fit-has-selection', selection);
        var rows = table.tBodies.length ? Array.prototype.slice.call(table.tBodies).reduce(function (all, body) { return all.concat(Array.prototype.slice.call(body.rows)); }, []) : Array.prototype.slice.call(table.rows);
        rows.forEach(function (tr) {
            if (tr === head.row || tr.parentElement === table.tHead) return;
            var col = 0;
            Array.prototype.forEach.call(tr.cells, function (cell) {
                var info = head.cells[col] || {label: '', check: false}, span = Math.max(1, cell.colSpan || 1);
                cell.classList.remove('fit-full', 'fit-check', 'fit-index', 'fit-actions');
                if (span >= head.cells.length - 1) cell.classList.add('fit-full');
                else if (info.check || (col === 0 && cell.querySelector('input[type="checkbox"]') && text(cell) === '')) cell.classList.add('fit-check');
                else if (info.label === '#' || /^(م|#|No\.?)$/i.test(info.label)) cell.classList.add('fit-index');
                else if (isActions(cell) && (col + span >= head.cells.length || /إجراء|الإجراءات|عمليات|actions|التحكم|خيارات/i.test(info.label))) cell.classList.add('fit-actions');
                if (cell.getAttribute('data-label') !== info.label) {
                    // Long column names take two or three lines on a phone; the row grows to fit them.
                    cell.setAttribute('data-label', info.label);
                    cell.style.setProperty('--fit-lines', String(Math.min(3, Math.max(1, Math.ceil(info.label.length / 13)))));
                }
                col += span;
            });
        });
        // Laptops: a wide table scrolls inside its own box.
        if (needsBox) {
            var box = document.createElement('div'); box.className = 'fit-scroll';
            parent.insertBefore(box, table); box.appendChild(table);
        }
        decide(table);
    }
    // Cards or table: always cards on a phone; on a tablet or small laptop only when the table is wider than its box;
    // a table on a wider screen. Shift closing keeps its table on tablets (its page is sized to the screen).
    function decide(table) {
        var width = window.innerWidth, stack;
        if (width < PHONE) stack = true;
        else if (width >= CARDS_UP_TO || table.closest('.branch-shifts-wrapper')) stack = false;
        else {
            table.classList.remove('fit-stack');
            var box = table.parentElement;
            while (box && box !== document.body && getComputedStyle(box).overflowX === 'visible' && box.parentElement) box = box.parentElement;
            stack = Math.max(table.scrollWidth, table.offsetWidth) > (box || table.parentElement).clientWidth + 24;
        }
        table.classList.toggle('fit-stack', stack);
    }
    function run() {
        pending = 0;
        var page = document.getElementById('dashboard-page') || document.body;
        page.querySelectorAll('table').forEach(fit);
    }
    function later() { if (!pending) pending = window.requestAnimationFrame ? requestAnimationFrame(run) : setTimeout(run, 16); }
    function watch() {
        if (observer || !window.MutationObserver) return;
        // Lists drawn or refreshed by the page's own script are fitted as they appear.
        observer = new MutationObserver(function (changes) {
            for (var i = 0; i < changes.length; i++) {
                var c = changes[i];
                for (var j = 0; j < c.addedNodes.length; j++) {
                    var n = c.addedNodes[j];
                    if (n.nodeType === 1 && (n.tagName === 'TABLE' || n.tagName === 'TR' || n.tagName === 'TBODY' || n.tagName === 'TD' || n.querySelector && n.querySelector('table'))) { later(); return; }
                }
            }
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }
    // Big screens scale the page up; select2 places its list from the box's unscaled size, so its list is
    // put back exactly under (or above) its box, at the box's real width, whenever select2 places it.
    function placeList(box, wrapper, list) {
        var r = box.getBoundingClientRect(); if (!wrapper.isConnected || Math.abs(r.width - box.offsetWidth) < 1) return;
        var above = list.classList.contains('select2-dropdown--above'), top = (above ? r.top - wrapper.offsetHeight : r.bottom) + window.scrollY, left = r.left + window.scrollX;
        if (Math.abs(parseFloat(wrapper.style.top) - top) > .5) wrapper.style.top = top + 'px';
        if (Math.abs(parseFloat(wrapper.style.left) - left) > .5) wrapper.style.left = left + 'px';
        if (Math.abs(parseFloat(list.style.width) - r.width) > .5) list.style.width = r.width + 'px';
    }
    function fixLists() {
        if (!window.jQuery) return;
        window.jQuery(document).on('select2:open', function (event) {
            var widget = window.jQuery(event.target).data('select2'); if (!widget || !widget.$container || !widget.$dropdown) return;
            var box = widget.$container[0], wrapper = widget.$dropdown[0], list = wrapper.querySelector('.select2-dropdown'); if (!list) return;
            placeList(box, wrapper, list);
            if (!wrapper.fitWatch && window.MutationObserver) {
                wrapper.fitWatch = new MutationObserver(function () { placeList(box, wrapper, list); });
                wrapper.fitWatch.observe(wrapper, {attributes: true, attributeFilter: ['style', 'class']});
                wrapper.fitWatch.observe(list, {attributes: true, attributeFilter: ['style', 'class']});
            }
        });
    }
    function start() {
        run(); watch(); fixLists();
        window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(run, 150); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    document.addEventListener('dashboard:page-mounted', later);
    window.DashboardFit = {refresh: later};
})();

