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
    const closeButton = modal.querySelector('[data-close-modal]');
    let lastTrigger = null;

    function focusableElements() {
      return modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    }

    function closeModal() {
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      if (lastTrigger) lastTrigger.focus();
    }

    document.querySelectorAll('[data-note-trigger]').forEach(function (button) {
      button.addEventListener('click', function () {
        lastTrigger = button;
        if (title) title.textContent = button.getAttribute('data-note-title') || 'Item Note';
        if (body) body.textContent = button.getAttribute('data-note-body') || '';
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        if (closeButton) closeButton.focus();
      });
    });

    modal.querySelectorAll('[data-close-modal]').forEach(function (button) {
      button.addEventListener('click', function () {
        closeModal();
      });
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (modal.classList.contains('hidden')) return;

      if (event.key === 'Escape') {
        closeModal();
        return;
      }

      if (event.key === 'Tab') {
        const focusables = Array.prototype.slice.call(focusableElements());
        if (!focusables.length) return;

        const first = focusables[0];
        const last = focusables[focusables.length - 1];

        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
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
