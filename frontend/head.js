'use strict';

const filters = document.querySelector('.head-filters');
filters?.addEventListener('change', (event) => {
  if (event.target.name === 'campus') filters.elements.group.value = '';
  if (['campus', 'group'].includes(event.target.name) && filters.elements.student) {
    filters.elements.student.value = '0';
  }
  filters.requestSubmit();
});
