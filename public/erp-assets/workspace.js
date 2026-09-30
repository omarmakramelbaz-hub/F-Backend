'use strict';
document.addEventListener('submit', function (event) {
  const form = event.target;
  if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
  if (!form.checkValidity()) return;
  const button = event.submitter;
  if (button && form.method.toLowerCase() === 'post') { button.dataset.originalText = button.textContent; button.disabled = true; button.textContent = 'جاري الحفظ…'; }
});
window.addEventListener('pageshow', () => document.querySelectorAll('[data-original-text]').forEach(button => {
  button.disabled = false; button.textContent = button.dataset.originalText; delete button.dataset.originalText;
}));
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
document.querySelectorAll('[data-role-picker]').forEach(picker => {
  const form = picker.closest('form');
  const apply = reset => {
    form.querySelectorAll('input[name="permissions[]"]').forEach(box => {
      if (reset) box.checked = JSON.parse(picker.selectedOptions[0].dataset.permissions || '[]').includes(box.value);
      box.disabled = picker.value === 'branch_manager' && ['branches.manage','payroll.manage','purchasing.manage','finance.manage'].includes(box.value);
      if (box.disabled) box.checked = false;
    });
    const branch = form.querySelector('[name="branch_id"]');
    branch.required = picker.value === 'branch_manager';
    branch.disabled = !branch.required;
  };
  picker.addEventListener('change', () => apply(true));
  apply(false);
});
document.querySelectorAll('form[data-stock-form]').forEach(form => {
  const picker = form.querySelector('[name="type"]');
  const apply = () => {
    const visibility = {destination_id: picker.value === 'transfer', expected_quantity: picker.value === 'count', unit_cost: ['opening','receipt','count'].includes(picker.value)};
    Object.entries(visibility).forEach(([name, visible]) => {
      const input = form.querySelector(`[name="${name}"]`);
      input.closest('.field').hidden = !visible;
      input.disabled = !visible;
      input.required = visible && (name !== 'unit_cost' || picker.value !== 'count');
    });
  };
  picker.addEventListener('change', apply); apply();
});

document.querySelectorAll('[data-line-editor]').forEach(editor => {
  const preview = () => {
    const output = editor.querySelector('[data-line-total]');
    if (!output) return;
    const scaled = (value, places) => {
      if (!new RegExp('^\\d+(?:\\.\\d{1,' + places + '})?$').test(value)) return null;
      const parts = value.split('.');
      return BigInt(parts[0]) * (10n ** BigInt(places)) + BigInt((parts[1] || '').padEnd(places, '0'));
    };
    let total = 0n;
    for (const row of editor.querySelectorAll('[data-line-rows] [data-line-row]')) {
      const quantity = scaled(row.querySelector('[name$="[quantity]"]').value, 3);
      const cost = scaled(row.querySelector('[name$="[unit_cost]"]').value, 2);
      if (quantity === null || cost === null) { output.textContent = 'أكمل الكميات والتكلفة'; return; }
      total += (quantity * cost + 500n) / 1000n;
    }
    output.textContent = `${total / 100n}.${String(total % 100n).padStart(2, '0')} ج.م`;
  };
  editor.addEventListener('input', preview);
  editor.addEventListener('click', event => {
    const rows = editor.querySelector('[data-line-rows]');
    if (event.target.closest('[data-add-line]')) {
      if (rows.children.length >= 30) return;
      const index = Number(editor.dataset.nextIndex);
      rows.insertAdjacentHTML('beforeend', editor.querySelector('template').innerHTML.replaceAll('__INDEX__', String(index)));
      editor.dataset.nextIndex = String(index + 1);
    }
    if (event.target.closest('[data-remove-line]') && rows.children.length > 1) {
      event.target.closest('[data-line-row]').remove();
    }
    preview();
  });
  preview();
});
document.querySelectorAll('[data-cash-form]').forEach(form => {
  const picker = form.querySelector('[name="type"]');
  const apply = () => {
    const fields = {supplier_id: 'supplier_payment', payroll_id: 'payroll_payment', branch_id: 'expense'};
    Object.entries(fields).forEach(([name, type]) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (!input) return;
      input.disabled = picker.value !== type;
      input.closest('.field').hidden = input.disabled;
      input.required = !input.disabled && name !== 'branch_id';
    });
  };
  picker.addEventListener('change', apply); apply();
});

document.querySelectorAll('[data-supplier-picker]').forEach(picker => picker.addEventListener('change', () => {
  const supplier = JSON.parse(picker.selectedOptions[0].dataset.supplier || '{}');
  const form = picker.closest('form');
  ['name','phone','address','active'].forEach(field => {
    form.querySelector(`[name="${field}"]`).value = supplier[field] ?? (field === 'active' ? '1' : '');
  });
}));
