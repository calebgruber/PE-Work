(function () {
  'use strict';

  const revisionRowCache = new WeakMap();

  function actionClass(action) {
    return {
      add: 'action-row-add',
      return: 'action-row-return',
      exchange: 'action-row-exchange',
      note: 'action-row-note'
    }[action] || '';
  }

  function setAccordionState(button, panel, isOpen) {
    if (!button || !panel) return;
    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.classList.toggle('hidden', !isOpen);
  }

  function rowControls(row) {
    if (!revisionRowCache.has(row)) {
      revisionRowCache.set(row, {
        select: row.querySelector('[data-action-select]'),
        rent: row.querySelector('[data-rent-input]'),
        spares: row.querySelector('[data-spare-input]'),
        total: row.querySelector('[data-total-output]'),
        stockWarning: row.querySelector('[data-stock-warning]')
      });
    }
    return revisionRowCache.get(row);
  }

  function updateRowState(row) {
    const controls = rowControls(row);
    const select = controls.select;
    const rent = controls.rent;
    const spares = controls.spares;
    const total = controls.total;
    if (!select || !rent || !spares || !total) return;

    row.classList.remove('action-row-add', 'action-row-return', 'action-row-exchange', 'action-row-note');
    const className = actionClass(select.value);
    if (className) row.classList.add(className);

    const totalValue = (parseInt(rent.value || '0', 10) || 0) + (parseInt(spares.value || '0', 10) || 0);
    total.textContent = String(totalValue);

    const stockWarning = controls.stockWarning;
    const shopQuantity = parseInt(row.getAttribute('data-shop-quantity') || '0', 10) || 0;
    if (stockWarning) {
      const overStock = totalValue > shopQuantity;
      stockWarning.classList.toggle('hidden', !overStock);
      row.classList.toggle('has-stock-warning', overStock);
      if (overStock) {
        stockWarning.textContent = 'This line currently exceeds shop stock.';
      }
    }
  }

  function initRevisionEditor() {
    const editor = document.querySelector('[data-revision-editor]');
    if (!editor) return;

    const search = editor.querySelector('[data-revision-search]');
    const warningsWrap = editor.querySelector('[data-revision-warnings-wrap]');
    const warningsList = editor.querySelector('[data-revision-warnings]');
    const submitButtons = Array.prototype.slice.call(editor.querySelectorAll('[data-revision-submit]'));
    const items = Array.prototype.slice.call(editor.querySelectorAll('[data-revision-item]'));
    let allowValidatedSubmit = false;
    let validationTimer = null;
    let latestValidationRun = 0;
    let validationAbortController = null;

    function renderWarnings(warnings) {
      if (!warningsWrap || !warningsList) return;

      warningsList.innerHTML = '';
      warningsWrap.classList.toggle('hidden', warnings.length === 0);
      submitButtons.forEach(function (button) {
        button.disabled = warnings.length > 0;
      });
      warnings.forEach(function (warning) {
        const item = document.createElement('div');
        item.className = 'revision-alert revision-alert-' + warning.type;
        item.textContent = warning.message;
        warningsList.appendChild(item);
      });
    }

    function scheduleValidation() {
      if (validationTimer) {
        window.clearTimeout(validationTimer);
      }
      validationTimer = window.setTimeout(runValidation, 120);
    }

    function runValidation(callback) {
      if (validationAbortController) {
        validationAbortController.abort();
      }
      validationAbortController = new AbortController();
      const abortController = validationAbortController;
      const formData = new FormData(editor);
      formData.set('action', 'validate_revision');
      const validationRun = ++latestValidationRun;

      fetch(editor.getAttribute('action') || window.location.href, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData,
        signal: abortController.signal
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Validation failed');
          }
          return response.json();
        })
        .then(function (payload) {
          if (validationRun !== latestValidationRun) {
            return;
          }
          const warnings = Array.isArray(payload?.warnings) ? payload.warnings : [];
          renderWarnings(warnings);
          if (callback) {
            callback(warnings);
          }
        })
        .catch(function () {
          if (abortController.signal.aborted) {
            return;
          }
          if (validationRun !== latestValidationRun) {
            return;
          }
          renderWarnings([{
            type: 'rule',
            message: 'Unable to validate this revision right now. Please try again.'
          }]);
        });
    }

    function applySearch() {
      const term = (search?.value || '').trim().toLowerCase();

      editor.querySelectorAll('[data-revision-category]').forEach(function (category) {
        const categoryName = category.getAttribute('data-category-name') || '';
        const trigger = category.querySelector('[data-accordion-trigger]');
        const panel = category.querySelector('[data-accordion-panel]');
        let visibleCount = 0;

        category.querySelectorAll('[data-revision-item]').forEach(function (item) {
          const haystack = item.getAttribute('data-item-name') || '';
          const match = !term || haystack.indexOf(term) !== -1 || categoryName.indexOf(term) !== -1;
          item.classList.toggle('hidden', !match);
          if (match) visibleCount += 1;
        });

        category.classList.toggle('hidden', visibleCount === 0);
        if (trigger && panel) {
          if (visibleCount === 0) {
            setAccordionState(trigger, panel, false);
            return;
          }
          setAccordionState(trigger, panel, term ? visibleCount > 0 : false);
        }
      });
    }

    items.forEach(function (item) {
      item.querySelectorAll('[data-action-select],[data-rent-input],[data-spare-input]').forEach(function (input) {
        input.addEventListener('change', function () {
          updateRowState(item);
          scheduleValidation();
        });
        input.addEventListener('input', function () {
          updateRowState(item);
          scheduleValidation();
        });
      });
      updateRowState(item);
    });

    if (search) {
      search.addEventListener('input', applySearch);
    }

    editor.addEventListener('submit', function (event) {
      if (allowValidatedSubmit) {
        allowValidatedSubmit = false;
        return;
      }
      event.preventDefault();
      runValidation(function (warnings) {
        if (warnings.length > 0) {
          if (warningsWrap) {
            warningsWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
          return;
        }
        allowValidatedSubmit = true;
        if (event.submitter && typeof editor.requestSubmit === 'function') {
          editor.requestSubmit(event.submitter);
          return;
        }
        editor.submit();
      });
    });

    applySearch();
    runValidation();
  }

  function initNoteModal() {
    const modal = document.getElementById('note-modal');
    if (!modal) return;

    const body = document.getElementById('note-modal-body');
    const title = document.getElementById('note-modal-title');
    const closeButton = modal.querySelector('[data-close-modal]');
    const panel = modal.querySelector('.modal-panel');
    let lastTrigger = null;

    function focusableElements() {
      return modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    }

    function closeModal() {
      modal.classList.add('hidden');
      modal.setAttribute('aria-hidden', 'true');
      if (lastTrigger) {
        lastTrigger.setAttribute('aria-expanded', 'false');
        lastTrigger.focus();
      }
    }

    document.querySelectorAll('[data-note-trigger]').forEach(function (button) {
      button.setAttribute('aria-haspopup', 'dialog');
      button.setAttribute('aria-expanded', 'false');
      button.addEventListener('click', function () {
        lastTrigger = button;
        if (title) title.textContent = button.getAttribute('data-note-title') || 'Item Note';
        if (body) body.textContent = button.getAttribute('data-note-body') || '';
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        button.setAttribute('aria-expanded', 'true');
        const focusables = focusableElements();
        if (focusables.length) {
          focusables[0].focus();
        } else {
          modal.focus();
        }
      });
    });

    modal.querySelectorAll('[data-close-modal]').forEach(function (button) {
      button.addEventListener('click', function () {
        closeModal();
      });
    });

    modal.addEventListener('click', function (event) {
      if (panel && !panel.contains(event.target)) {
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

  function initAccordion() {
    document.querySelectorAll('[data-accordion-trigger]').forEach(function (button) {
      button.addEventListener('click', function () {
        const panel = button.parentElement?.querySelector('[data-accordion-panel]');
        if (!panel) return;

        const isOpen = button.getAttribute('aria-expanded') === 'true';
        setAccordionState(button, panel, !isOpen);
      });
    });
  }

  function initInventoryFilters() {
    const search = document.querySelector('[data-inventory-search]');
    const filter = document.querySelector('[data-category-filter]');
    if (!search && !filter) return;

    const categories = Array.prototype.slice.call(document.querySelectorAll('[data-inventory-category]'));

    function applyFilters() {
      const searchTerm = (search?.value || '').trim().toLowerCase();
      const categoryFilter = filter?.value || '';

      categories.forEach(function (category) {
        const categoryId = category.getAttribute('data-category-id') || '';
        const categoryName = category.getAttribute('data-category-name') || '';
        const categoryMatches = !categoryFilter || categoryId === categoryFilter;
        const trigger = category.querySelector('[data-accordion-trigger]');
        const panel = category.querySelector('[data-accordion-panel]');
        let visibleItems = 0;

        category.querySelectorAll('[data-inventory-item]').forEach(function (item) {
          const haystack = item.getAttribute('data-item-name') || '';
          const match = (!searchTerm && categoryMatches) || (categoryMatches && (haystack.indexOf(searchTerm) !== -1 || categoryName.indexOf(searchTerm) !== -1));
          item.classList.toggle('hidden', !match);
          if (match) visibleItems += 1;
        });

        category.classList.toggle('hidden', visibleItems === 0 || !categoryMatches);
        if (trigger && panel) {
          if (visibleItems === 0 || !categoryMatches) {
            setAccordionState(trigger, panel, false);
          } else if (searchTerm || categoryFilter) {
            setAccordionState(trigger, panel, true);
          }
        }
      });
    }

    if (search) {
      search.addEventListener('input', applyFilters);
    }
    if (filter) {
      filter.addEventListener('change', applyFilters);
    }
    applyFilters();
  }

  function initConfirmCodes() {
    document.querySelectorAll('[data-confirm-code]').forEach(function (button) {
      const form = button.closest('form');
      let lastSubmitter = null;
      function confirmAction(event) {
        const submitter = event.submitter || lastSubmitter;
        if (submitter !== button) return;
        const expected = button.getAttribute('data-confirm-code') || '';
        const message = button.getAttribute('data-confirm') || ('Type ' + expected + ' to continue.');
        const entered = window.prompt(message, '');
        if (entered !== expected) {
          event.preventDefault();
          event.stopPropagation();
        }
      }

      if (form) {
        button.addEventListener('click', function () {
          lastSubmitter = button;
        });
        form.addEventListener('submit', confirmAction);
      } else {
        button.addEventListener('click', confirmAction);
      }
    });
  }

  function initSimpleConfirms() {
    document.querySelectorAll('[data-confirm-message]').forEach(function (button) {
      const form = button.closest('form');
      let lastSubmitter = null;
      function confirmAction(event) {
        const submitter = event.submitter || lastSubmitter;
        if (submitter !== button) return;
        if (!window.confirm(button.getAttribute('data-confirm-message') || 'Are you sure?')) {
          event.preventDefault();
          event.stopPropagation();
        }
      }

      if (form) {
        button.addEventListener('click', function () {
          lastSubmitter = button;
        });
        form.addEventListener('submit', confirmAction);
      } else {
        button.addEventListener('click', confirmAction);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initRevisionEditor();
    initNoteModal();
    initPrintActions();
    initAccordion();
    initInventoryFilters();
    initConfirmCodes();
    initSimpleConfirms();
  });
})();
