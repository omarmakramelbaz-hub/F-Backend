/*
 * The quantity of an item already in an invoice (cashier, dine-in and delivery pages): tapping the item's quantity
 * opens a large pad (digits, decimal point, ¼ ½ ¾ kg, backspace, clear) whose value goes into the line's own quantity box
 * with the box's change event, so each page applies it exactly as when typed there (its checks, its pricing, its saving).
 * Typed quantities also accept the ways people write them: ".5", "0,5", "٫٥", "۰٫۵".
 */
(function () {
    'use strict';
    if (window.GoPosLineQuantity) return;

    var LINE = '[data-pos-line-quantity],[data-dining-line-quantity],[data-phone-line-quantity]';
    // The next item's quantity on the delivery page: a typed box too.
    var TYPED = LINE + ',[data-phone-quantity]';
    var ar = function () { return (document.documentElement.lang || 'ar').indexOf('en') !== 0; };
    var words = function (arabic, english) { return ar() ? arabic : english; };

    /** A typed quantity in the form the pages read: Western digits, a dot, a leading zero. Quantities only, never money. */
    function normalize(value) {
        var text = String(value === undefined || value === null ? '' : value).trim()
            .replace(/[٠-٩]/g, function (digit) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)); })
            .replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
            .replace(/[٫,،]/g, '.').replace(/\s+/g, '');
        if (/^\.[0-9]/.test(text)) text = '0' + text;
        if (/^[0-9]+\.$/.test(text)) text = text.slice(0, -1);
        return text;
    }

    function typed(event) {
        var input = event.target;
        if (!input || !input.matches || !input.matches(TYPED)) return;
        var value = normalize(input.value);
        if (value !== input.value) input.value = value;
    }
    // Before the page's own listeners (they listen on the page, these on the document while the event comes down).
    document.addEventListener('change', typed, true);
    document.addEventListener('keydown', function (event) { if (event.key === 'Enter') typed(event); }, true);

    var pad = null;

    function style() {
        if (document.getElementById('go-pos-qty-style')) return;
        var css = document.createElement('style');
        css.id = 'go-pos-qty-style';
        css.textContent = '.go-qty-backdrop{position:fixed;inset:0;z-index:100000;background:rgba(9,19,32,.55);display:grid;place-items:center;padding:16px}'
            + '.go-qty-pad{width:min(360px,100%);background:#fff;color:#1f2937;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.35);padding:16px;font-family:inherit;direction:rtl}'
            + '.go-qty-pad[dir=ltr]{direction:ltr}.go-qty-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}'
            + '.go-qty-head strong{font-size:16px;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            + '.go-qty-display{display:flex;align-items:baseline;justify-content:center;gap:8px;border:2px solid #fd7201;border-radius:12px;padding:10px;margin-bottom:10px;font-size:30px;font-weight:700}'
            + '.go-qty-display small{font-size:14px;font-weight:400;color:#6b7280}'
            + '.go-qty-quick,.go-qty-keys,.go-qty-actions{display:grid;gap:8px;margin-bottom:8px}.go-qty-quick{grid-template-columns:repeat(3,1fr)}'
            + '.go-qty-keys{grid-template-columns:repeat(3,1fr);direction:ltr}.go-qty-actions{grid-template-columns:1fr 2fr;margin:0}'
            + '.go-qty-pad button{min-height:52px;border-radius:10px;border:1px solid #d1d5db;background:#f9fafb;color:#111827;font:inherit;font-size:20px;font-weight:700;cursor:pointer}'
            + '.go-qty-pad button:active{transform:scale(.97)}.go-qty-quick button{background:#fff7ed;border-color:#fdba74;color:#9a3412;font-size:16px}'
            + '.go-qty-pad button.go-qty-confirm{background:#fd7201;border-color:#fd7201;color:#fff}.go-qty-pad button.go-qty-close{min-height:36px;width:36px;font-size:18px}'
            + '.go-qty-pad button[disabled]{opacity:.35;cursor:not-allowed}';
        document.head.appendChild(css);
    }

    function close() {
        if (!pad) return;
        var current = pad;
        pad = null;
        current.backdrop.remove();
        document.removeEventListener('keydown', current.keys, true);
    }

    function unitOf(input) {
        var cell = input.closest('td') || input.parentElement;
        var unit = cell && cell.querySelector('.tp-line-unit,.dp-line-unit,.ph-line-unit,small');
        return unit ? unit.textContent.trim() : '';
    }

    function open(input) {
        if (!input || input.disabled || input.readOnly) return;
        close();
        style();
        var unit = unitOf(input);
        // A piece is counted whole (the page refuses a fraction of one too).
        var whole = input.inputMode === 'numeric' || /قطعة|piece/i.test(unit);
        var value = normalize(input.value) || '0', fresh = true;
        var backdrop = document.createElement('div');
        backdrop.className = 'go-qty-backdrop';
        var box = document.createElement('div');
        box.className = 'go-qty-pad';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        if (!ar()) box.setAttribute('dir', 'ltr');
        var head = document.createElement('div');
        head.className = 'go-qty-head';
        var title = document.createElement('strong');
        // The box's own name already says it: «الكمية / الوزن» and the item.
        title.textContent = input.getAttribute('aria-label') || words('تعديل الكمية', 'Change quantity');
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'go-qty-close';
        cancel.textContent = '×';
        cancel.setAttribute('aria-label', words('إلغاء', 'Cancel'));
        head.append(title, cancel);
        var display = document.createElement('div');
        display.className = 'go-qty-display';
        display.setAttribute('role', 'status');
        var shown = document.createElement('bdi');
        var unitText = document.createElement('small');
        unitText.textContent = unit;
        display.append(shown, unitText);
        box.append(head, display);
        var render = function () { shown.textContent = value; };
        var set = function (next) { value = next; fresh = false; render(); };
        var press = function (key) {
            if (key === 'clear') { value = '0'; fresh = true; return render(); }
            if (key === 'back') { set(value.length > 1 ? value.slice(0, -1) : '0'); if (value === '0') fresh = true; return; }
            if (key === '.') {
                if (whole) return;
                if (fresh) value = '0';
                if (value.indexOf('.') < 0) set(value + '.');
                return;
            }
            if (fresh || value === '0') return set(key);
            var decimals = value.indexOf('.') < 0 ? 0 : value.split('.')[1].length;
            if (value.length < 9 && decimals < 3) set(value + key);
        };
        if (!whole) {
            var quick = document.createElement('div');
            quick.className = 'go-qty-quick';
            [['0.25', words('¼ كيلو', '¼ kg')], ['0.5', words('½ كيلو', '½ kg')], ['0.75', words('¾ كيلو', '¾ kg')]].forEach(function (pair) {
                var button = document.createElement('button');
                button.type = 'button';
                button.dataset.goQtyQuick = pair[0];
                button.textContent = pair[1];
                button.addEventListener('click', function () { value = pair[0]; fresh = true; render(); });
                quick.appendChild(button);
            });
            box.appendChild(quick);
        }
        var keys = document.createElement('div');
        keys.className = 'go-qty-keys';
        ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', 'back'].forEach(function (key) {
            var button = document.createElement('button');
            button.type = 'button';
            button.dataset.goQtyKey = key;
            button.textContent = key === 'back' ? '⌫' : key;
            if (key === 'back') button.setAttribute('aria-label', words('مسح رقم', 'Backspace'));
            if (key === '.' && whole) button.disabled = true;
            button.addEventListener('click', function () { press(key); });
            keys.appendChild(button);
        });
        box.appendChild(keys);
        var actions = document.createElement('div');
        actions.className = 'go-qty-actions';
        var clear = document.createElement('button');
        clear.type = 'button';
        clear.textContent = words('مسح', 'Clear');
        clear.addEventListener('click', function () { press('clear'); });
        var confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'go-qty-confirm';
        confirm.dataset.goQtyConfirm = '1';
        confirm.textContent = words('تأكيد الكمية', 'Confirm quantity');
        actions.append(clear, confirm);
        box.appendChild(actions);
        backdrop.appendChild(box);
        var apply = function () {
            close();
            if (!input.isConnected || input.disabled) return;
            input.value = normalize(value);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        };
        confirm.addEventListener('click', apply);
        cancel.addEventListener('click', close);
        // A new touch or click outside the pad closes it (the end of the touch that opened it lands here too).
        backdrop.addEventListener('pointerdown', function (event) { if (event.target === backdrop) close(); });
        var keyboard = function (event) {
            if (!pad) return;
            var key = normalize(event.key), handled = true;
            // The page behind the pad never sees these keys (its own shortcuts, e.g. Enter, stay untouched).
            if (event.key === 'Escape') close();
            else if (event.key === 'Enter') apply();
            else if (event.key === 'Backspace') press('back');
            else if (event.key === 'Delete') press('clear');
            else if (/^[0-9]$/.test(key) || key === '.') press(key);
            else handled = false;
            if (handled) { event.preventDefault(); event.stopPropagation(); }
        };
        document.addEventListener('keydown', keyboard, true);
        pad = { backdrop: backdrop, keys: keyboard, input: input };
        render();
        document.body.appendChild(backdrop);
        confirm.focus();
    }

    // A touch on the quantity opens the pad instead of the screen keyboard; a click (mouse) opens it too, and typing in
    // the box still works.
    document.addEventListener('pointerdown', function (event) {
        var input = event.target && event.target.closest && event.target.closest(LINE);
        if (!input || input.disabled || event.pointerType === 'mouse') return;
        event.preventDefault();
        open(input);
    }, true);
    document.addEventListener('click', function (event) {
        var input = event.target && event.target.closest && event.target.closest(LINE);
        if (!input || input.disabled || pad) return;
        open(input);
    });

    window.GoPosLineQuantity = { normalize: normalize, open: open, close: close };
}());
