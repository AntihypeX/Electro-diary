'use strict';

const filters = document.querySelector('.sec-filters');
filters?.addEventListener('change', (event) => {
  if (event.target.name === 'campus') filters.elements.group.value = '';
  if (['campus', 'group'].includes(event.target.name)) filters.elements.student.value = '';
  filters.requestSubmit();
});

const campus = document.querySelector('#sec-campus');
const group = document.querySelector('#sec-group');
function updateGroups(reset = false) {
  if (!campus || !group) return;
  for (const option of group.options) {
    if (!option.value) continue;
    option.hidden = option.disabled = option.dataset.campus !== campus.value;
  }
  if (reset || group.selectedOptions[0]?.disabled) group.value = '';
}
campus?.addEventListener('change', () => updateGroups(true));
updateGroups();

for (const button of document.querySelectorAll('.sec-add')) {
  button.addEventListener('click', () => {
    const section = button.dataset.section;
    const container = document.querySelector(`.sec-collection[data-section="${section}"]`);
    const row = document.querySelector(`#sec-template-${section}`).content.firstElementChild.cloneNode(true);
    const index = Array.from(container.querySelectorAll('input[type="hidden"]'), input => {
      const match = input.name.match(/\[(\d+)\]/);
      return match ? Number(match[1]) : -1;
    }).reduce((max, n) => Math.max(max, n), -1) + 1;
    for (const field of row.querySelectorAll('[name]')) field.name = field.name.replace('NEW_ROW', String(index));
    container.append(row);
    row.querySelector('input:not([type="hidden"])')?.focus();
  });
}
document.addEventListener('click', (event) => {
  const remove = event.target.closest('.sec-remove');
  if (remove && confirm('Убрать эту запись из карточки? Изменение применится после сохранения.')) remove.closest('.sec-repeat-row').remove();
});

const expel = document.querySelector('#sec-expel');
if (expel?.dataset.reopen === '1') expel.showModal();
document.querySelector('#sec-expel-open')?.addEventListener('click', () => expel.showModal());
expel?.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => expel.close()));

for (const form of document.querySelectorAll('.sec-card-form, .sec-dialog form')) {
  // Разворачиваем раздел с ошибкой, чтобы браузер мог сфокусировать обязательное поле.
  form.addEventListener('invalid', (event) => {
    const details = event.target.closest('details');
    if (details) details.open = true;
  }, true);
  form.addEventListener('submit', () => {
    for (const button of form.querySelectorAll('[type="submit"]')) button.disabled = true;
  });
}
window.addEventListener('pageshow', () => {
  for (const button of document.querySelectorAll('[type="submit"]:disabled')) button.disabled = false;
});
