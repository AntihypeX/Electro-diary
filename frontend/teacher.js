'use strict';

const csrf = document.querySelector('meta[name="csrf-token"]').content;
const dialog = document.querySelector('#teacher-editor');
const form = document.querySelector('#teacher-editor-form');
const matrix = document.querySelector('#teacher-matrix');
const confirmation = document.querySelector('#teacher-confirm');
const fields = document.querySelector('#teacher-fields');
const lessonFields = document.querySelector('#teacher-lesson-fields');
const gradeFields = document.querySelector('#teacher-grade-fields');
const gradeRows = document.querySelector('#teacher-grade-rows');
const gradeTemplate = document.querySelector('#teacher-grade-template');
const addRowButton = document.querySelector('#teacher-add-row');
const saveButton = document.querySelector('#teacher-save');
const errorBox = document.querySelector('#teacher-editor-error');
const notice = document.querySelector('#teacher-notice');
const filters = document.querySelector('.teacher-filters');
let editor;
let busy = false;

if (filters) {
  filters.querySelector('button[type="submit"]').hidden = true;
  filters.addEventListener('change', (event) => {
    if (['year', 'group'].includes(event.target.name)) {
      filters.elements.student.value = '0';
    }
    filters.requestSubmit();
  });
}

if (matrix && Number.isFinite(history.state?.teacherMatrixScroll)) {
  matrix.scrollLeft = history.state.teacherMatrixScroll;
  history.replaceState({ ...history.state, teacherMatrixScroll: null }, '');
}

function refreshGradeOptions() {
  const choices = Array.from(gradeRows.querySelectorAll('select'));
  const absent = choices.some((select) => select.value === 'н');
  const multipleGrades = choices.length > 1 || editor.expectedCount > (editor.action === 'grade_edit' ? 1 : 0);

  for (const [index, select] of choices.entries()) {
    select.previousElementSibling.textContent = choices.length > 1 ? `Оценка ${index + 1}` : 'Отметка';
    select.querySelector('option[value="н"]').disabled = multipleGrades;
  }

  addRowButton.disabled = absent || choices.length >= 10;
}

function addGradeRow(value = '') {
  const row = gradeTemplate.content.firstElementChild.cloneNode(true);
  const select = row.querySelector('select');
  const remove = row.querySelector('button');
  remove.hidden = !gradeRows.children.length;
  select.value = value === 'Н' ? 'н' : value;
  select.addEventListener('change', refreshGradeOptions);
  remove.addEventListener('click', () => {
    row.remove();
    refreshGradeOptions();
    addRowButton.focus();
  });
  gradeRows.append(row);
  refreshGradeOptions();
  return select;
}

function showFields(moveFocus = true) {
  const lesson = editor.action === 'lesson';
  const adding = editor.action === 'grade_add';
  confirmation.hidden = true;
  fields.hidden = false;
  lessonFields.hidden = !lesson;
  gradeFields.hidden = lesson;
  saveButton.hidden = false;

  let firstField;
  if (lesson) {
    form.elements.topic.value = editor.topicId;
    form.elements.type.value = editor.previousType;
    syncTopicType();
    firstField = form.elements.topic;
  } else {
    addRowButton.hidden = !adding;
    firstField = addGradeRow(adding ? '' : editor.previous);
  }
  if (moveFocus) firstField.focus();
}

matrix?.addEventListener('click', (event) => {
  const cell = event.target.closest('.teacher-cell');
  const button = event.target.closest('button[data-action]')
    || cell?.querySelector('[data-action="grade_add"], [data-action="attestation"], [data-action="grade_edit"]');
  if (!button) return;

  const { action, value, caption } = button.dataset;
  const row = button.closest('[data-student]');
  const selectedCell = button.closest('.teacher-cell');
  editor = { action };
  let context = caption || '';
  let title = 'Тема занятия';
  let mustConfirm = false;

  if (action === 'lesson') {
    Object.assign(editor, {
      lesson: button.dataset.lesson,
      previousTopic: button.dataset.topic,
      topicId: button.dataset.topicId,
      previousType: button.dataset.type,
    });
  } else if (action === 'attestation') {
    const { kind, period, group, subject } = button.dataset;
    Object.assign(editor, { student: row.dataset.student, kind, period, group, subject, previous: value || null });
    title = 'Результат аттестации';
    context = `${row.dataset.name} · ${context}`;
    mustConfirm = Boolean(value);
  } else {
    const dateButton = matrix.querySelector(`[data-action="lesson"][data-lesson="${selectedCell.dataset.lesson}"]`);
    Object.assign(editor, {
      lesson: selectedCell.dataset.lesson,
      student: row.dataset.student,
      expectedCount: Number(selectedCell.dataset.count),
    });
    context = `${row.dataset.name} · ${dateButton.dataset.caption}`;
    title = action === 'grade_add' ? 'Добавить оценку' : 'Изменить оценку';
    if (action === 'grade_edit') {
      Object.assign(editor, { entry: button.dataset.entry, previous: value });
      mustConfirm = true;
    }
  }

  form.reset();
  gradeRows.replaceChildren();
  lessonFields.querySelectorAll('input, select').forEach((field) => {
    field.disabled = action !== 'lesson';
    field.required = action === 'lesson';
  });
  errorBox.hidden = true;
  fields.hidden = true;
  saveButton.hidden = true;
  confirmation.hidden = !mustConfirm;
  document.querySelector('#teacher-editor-title').textContent = title;
  document.querySelector('#teacher-editor-context').textContent = context;

  if (mustConfirm) {
    document.querySelector('#teacher-previous-grade').textContent = value;
    dialog.showModal();
    document.querySelector('#teacher-confirm-edit').focus();
  } else {
    showFields(false);
    dialog.showModal();
    fields.querySelector(action === 'lesson' ? '[name="topic"]' : '[name="grades"]').focus();
  }
});

document.querySelector('#teacher-confirm-edit').addEventListener('click', () => showFields());
addRowButton.addEventListener('click', () => addGradeRow().focus());
dialog.querySelectorAll('[data-close]').forEach((button) => {
  button.addEventListener('click', () => {
    if (!busy) dialog.close();
  });
});
dialog.addEventListener('cancel', (event) => {
  if (busy) event.preventDefault();
});

async function send(url, payload) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: JSON.stringify(payload),
  });
  const result = await response.json().catch(() => ({}));
  if (!response.ok || !result.ok) {
    throw new Error(result.error || 'Не удалось выполнить действие. Попробуйте ещё раз.');
  }
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (busy || saveButton.hidden) return;

  const payload = { ...editor };
  if (editor.action === 'lesson') {
    payload.topic = form.elements.topic.value;
    payload.newTopic = form.elements.newTopic.value.trim();
    payload.type = form.elements.type.value;
  } else {
    const grades = Array.from(gradeRows.querySelectorAll('select'), (select) => select.value);
    if (editor.action === 'grade_add') payload.grades = grades;
    else payload.grade = grades[0];
  }

  errorBox.hidden = true;
  saveButton.disabled = busy = true;
  try {
    await send(location.href, payload);
    if (matrix) history.replaceState({ ...history.state, teacherMatrixScroll: matrix.scrollLeft }, '');
    location.reload();
  } catch (error) {
    errorBox.textContent = error instanceof TypeError ? 'Не удалось связаться с сервером. Попробуйте ещё раз.' : error.message;
    errorBox.hidden = false;
    saveButton.disabled = busy = false;
  }
});

function syncTopicType() {
  const addingTopic = form.elements.newTopic.value.trim() !== '';
  const selected = form.elements.topic.selectedOptions[0];
  const type = addingTopic ? '' : selected?.dataset.type;
  form.elements.topic.required = !addingTopic;
  form.elements.topic.disabled = addingTopic;
  form.elements.newTopic.required = false;
  if (type) form.elements.type.value = type;
  form.elements.type.disabled = Boolean(type);
}
form.elements.topic.addEventListener('change', syncTopicType);
form.elements.newTopic.addEventListener('input', syncTopicType);

const autoAttestation = document.querySelector('[data-auto-attestation]');
autoAttestation?.addEventListener('click', async () => {
  if (!window.confirm('Заполнить пустые ячейки аттестации по среднему баллу?')) return;
  autoAttestation.disabled = true;
  try {
    const response = await fetch(location.href, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
      },
      body: autoAttestation.dataset.autoAttestation,
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось заполнить аттестацию.');
    location.reload();
  } catch (error) {
    alert(error.message);
    autoAttestation.disabled = false;
  }
});
