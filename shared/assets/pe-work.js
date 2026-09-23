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

  function setAccordionState(button, panel, isOpen) {
    if (!button || !panel) return;
    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    panel.classList.toggle('hidden', !isOpen);
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

    const stockWarning = row.querySelector('[data-stock-warning]');
    const shopQuantity = parseInt(row.getAttribute('data-shop-quantity') || '0', 10) || 0;
    if (stockWarning) {
      const overStock = totalValue > shopQuantity;
      stockWarning.classList.toggle('hidden', !overStock);
      if (overStock) {
        stockWarning.textContent = 'This line currently exceeds shop stock.';
      }
    }
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

  function initRevisionEditor() {
    const editor = document.querySelector('[data-revision-editor]');
    if (!editor) return;

    const search = editor.querySelector('[data-revision-search]');
    const warningsWrap = editor.querySelector('[data-revision-warnings-wrap]');
    const warningsList = editor.querySelector('[data-revision-warnings]');
    const items = Array.prototype.slice.call(editor.querySelectorAll('[data-revision-item]'));
    const rulesNode = document.getElementById('revision-rules-data');
    let rules = [];

    if (rulesNode) {
      try {
        rules = JSON.parse(rulesNode.textContent || '[]');
      } catch (error) {
        rules = [];
      }
    }

    function itemRent(item) {
      const input = item.querySelector('[data-rent-input]');
      return parseInt(input?.value || '0', 10) || 0;
    }

    function itemTotal(item) {
      const rent = item.querySelector('[data-rent-input]');
      const spares = item.querySelector('[data-spare-input]');
      return (parseInt(rent?.value || '0', 10) || 0) + (parseInt(spares?.value || '0', 10) || 0);
    }

    function renderWarnings() {
      if (!warningsWrap || !warningsList) return;

      const warnings = [];
      const rentByItem = {};

      items.forEach(function (item) {
        updateRowState(item);
        const itemId = parseInt(item.getAttribute('data-item-id') || '0', 10) || 0;
        const label = item.getAttribute('data-item-label') || 'Item';
        const total = itemTotal(item);
        const stock = parseInt(item.getAttribute('data-shop-quantity') || '0', 10) || 0;

        rentByItem[itemId] = itemRent(item);
        if (total > stock) {
          warnings.push(label + ' exceeds shop stock (' + total + ' requested, ' + stock + ' available).');
        }
      });

      rules.forEach(function (rule) {
        const triggerQty = parseInt(rule.trigger_quantity || 0, 10) || 0;
        const requiredQty = parseInt(rule.required_quantity || 0, 10) || 0;
        const triggerCurrent = rentByItem[parseInt(rule.trigger_item_id || 0, 10) || 0] || 0;
        const requiredCurrent = rentByItem[parseInt(rule.required_item_id || 0, 10) || 0] || 0;

        if (triggerQty <= 0 || requiredQty <= 0 || triggerCurrent < triggerQty) {
          return;
        }

        const recommended = Math.ceil(triggerCurrent / triggerQty) * requiredQty;
        if (requiredCurrent < recommended) {
          let warning = 'Rule warning: ' + rule.trigger_item_name + ' may need ' + rule.required_item_name + ' (' + recommended + ' suggested, ' + requiredCurrent + ' currently on the order).';
          if (rule.note) {
            warning += ' ' + rule.note;
          }
          warnings.push(warning);
        }
      });

      warningsList.innerHTML = '';
      warningsWrap.classList.toggle('hidden', warnings.length === 0);
      warnings.forEach(function (warning) {
        const item = document.createElement('div');
        item.className = 'revision-alert';
        item.textContent = warning;
        warningsList.appendChild(item);
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
        if (term && trigger && panel) {
          setAccordionState(trigger, panel, visibleCount > 0);
        }
      });
    }

    items.forEach(function (item) {
      item.querySelectorAll('[data-action-select],[data-rent-input],[data-spare-input]').forEach(function (input) {
        input.addEventListener('change', renderWarnings);
        input.addEventListener('input', renderWarnings);
      });
      updateRowState(item);
    });

    if (search) {
      search.addEventListener('input', applySearch);
    }

    applySearch();
    renderWarnings();
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
        if (trigger && panel && (searchTerm || categoryFilter)) {
          setAccordionState(trigger, panel, visibleItems > 0 && categoryMatches);
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
      function confirmAction(event) {
        const expected = button.getAttribute('data-confirm-code') || '';
        const message = button.getAttribute('data-confirm') || ('Type ' + expected + ' to continue.');
        const entered = window.prompt(message, '');
        if (entered !== expected) {
          event.preventDefault();
          event.stopPropagation();
        }
      }

      if (form) {
        form.addEventListener('submit', confirmAction);
      } else {
        button.addEventListener('click', confirmAction);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initRevisionRows();
    initRevisionEditor();
    initNoteModal();
    initPrintActions();
    initAccordion();
    initInventoryFilters();
    initConfirmCodes();
  });
})();
