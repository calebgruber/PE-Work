(function () {
  'use strict';

  function actionClass(action) {
    return {
      add: 'action-row-add',
      return: 'action-row-return',
      exchange: 'action-row-exchange',
      note: 'action-row-note'
    }[action] || '';
  }

  function updateRowState(row) {
    const select = row.querySelector('[data-action-select]');
    const rent = row.querySelector('[data-rent-input]');
    const spares = row.querySelector('[data-spare-input]');
    const total = row.querySelector('[data-total-output]');
    if (!select || !rent || !spares || !total) return;

    row.classList.remove('action-row-add', 'action-row-return', 'action-row-exchange', 'action-row-note');
    const className = actionClass(select.value);
    if (className) row.classList.add(className);

    const totalValue = (parseInt(rent.value || '0', 10) || 0) + (parseInt(spares.value || '0', 10) || 0);
    total.textContent = String(totalValue);
  }

  function initRevisionRows() {
    document.querySelectorAll('[data-action-row]').forEach(function (row) {
      row.querySelectorAll('[data-action-select],[data-rent-input],[data-spare-input]').forEach(function (input) {
        input.addEventListener('change', function () { updateRowState(row); });
        input.addEventListener('input', function () { updateRowState(row); });
      });
      updateRowState(row);
    });
  }

  function initNoteModal() {
    const modal = document.getElementById('note-modal');
    if (!modal) return;

    const body = document.getElementById('note-modal-body');
    const title = document.getElementById('note-modal-title');

    document.querySelectorAll('[data-note-trigger]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (title) title.textContent = button.getAttribute('data-note-title') || 'Item Note';
        if (body) body.textContent = button.getAttribute('data-note-body') || '';
        modal.classList.remove('hidden');
      });
    });

    modal.querySelectorAll('[data-close-modal]').forEach(function (button) {
      button.addEventListener('click', function () {
        modal.classList.add('hidden');
      });
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        modal.classList.add('hidden');
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        modal.classList.add('hidden');
      }
    });
  }

  function initPrintActions() {
    document.querySelectorAll('[data-print-page]').forEach(function (button) {
      button.addEventListener('click', function () {
        window.print();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initRevisionRows();
    initNoteModal();
    initPrintActions();
  });
})();
