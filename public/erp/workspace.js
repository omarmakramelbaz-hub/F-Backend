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
  const defaults = {deputy_manager:['branches.manage','inventory.manage','employees.manage','payroll.manage','orders.view','audit.view'],branch_manager:['inventory.manage','employees.manage','orders.view','audit.view'],inventory_manager:['inventory.manage','audit.view'],hr_manager:['employees.manage','payroll.manage','audit.view']};
  const form = picker.closest('form');
  const apply = reset => {
    form.querySelectorAll('input[name="permissions[]"]').forEach(box => {
      if (reset) box.checked = (defaults[picker.value] || []).includes(box.value);
      box.disabled = picker.value === 'branch_manager' && ['branches.manage','payroll.manage'].includes(box.value);
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
