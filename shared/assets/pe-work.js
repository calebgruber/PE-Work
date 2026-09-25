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
    const autosaveStatus = editor.querySelector('[data-revision-autosave-status]');
    const rentTotal = document.querySelector('[data-revision-rent-total]');
    const spareTotal = document.querySelector('[data-revision-spare-total]');
    const overallTotal = document.querySelector('[data-revision-overall-total]');
    const submitButtons = Array.prototype.slice.call(editor.querySelectorAll('[data-revision-submit]'));
    const items = Array.prototype.slice.call(editor.querySelectorAll('[data-revision-item]'));
    let validationTimer = null;
    let latestValidationRun = 0;
    let validationAbortController = null;
    let autosaveTimer = null;
    let latestAutosaveRun = 0;
    let latestRevisionVersion = 0;
    let latestPersistedRevisionVersion = 0;
    let hasPendingAutosave = false;
    let hasDirtyRevisionChanges = false;
    let pendingAutosaveRun = 0;
    let autosaveInFlight = false;
    let autosaveQueued = false;
    let manualSaveInFlight = false;
    let allowNativeSubmit = false;

    function buildRevisionPayload() {
      const payload = {};
      editor.querySelectorAll('input[name^="items["], select[name^="items["], textarea[name^="items["]').forEach(function (input) {
        const match = input.name.match(/^items\[(\d+)\]\[([^\]]+)\]$/);
        if (!match) return;
        const itemId = match[1];
        const field = match[2];
        if (!payload[itemId]) payload[itemId] = {};
        payload[itemId][field] = input.value;
      });
      return payload;
    }

    function buildRevisionFormData(actionName) {
      const formData = new FormData();
      const csrf = editor.querySelector('input[name="csrf_token"]');
      const revisionId = editor.querySelector('input[name="revision_id"]');
      if (csrf) formData.set('csrf_token', csrf.value);
      if (revisionId) formData.set('revision_id', revisionId.value);
      formData.set('action', actionName);
      formData.set('revision_payload', JSON.stringify(buildRevisionPayload()));
      return formData;
    }

    function renderAutosaveStatus(message, state) {
      if (!autosaveStatus) return;
      autosaveStatus.textContent = message;
      autosaveStatus.setAttribute('data-state', state || 'idle');
    }

    function renderTotals(totals) {
      if (!totals) return;
      if (rentTotal) rentTotal.textContent = String(totals.rent_total || 0);
      if (spareTotal) spareTotal.textContent = String(totals.spare_total || 0);
      if (overallTotal) overallTotal.textContent = String(totals.overall_total || 0);
    }

    function renderWarnings(warnings) {
      if (!warningsWrap || !warningsList) return;

      warningsList.innerHTML = '';
      warningsWrap.classList.toggle('hidden', warnings.length === 0);
      warnings.forEach(function (warning) {
        const item = document.createElement('div');
        item.className = 'revision-alert revision-alert-' + warning.type;
        item.textContent = warning.message;
        warningsList.appendChild(item);
      });
    }

    function scheduleValidation() {
      if (typeof window.fetch !== 'function') return;
      if (validationTimer) {
        window.clearTimeout(validationTimer);
      }
      validationTimer = window.setTimeout(runValidation, 120);
    }

    function runValidation(callback) {
      if (typeof window.fetch !== 'function') {
        if (callback) callback([]);
        return;
      }
      if (validationAbortController) {
        validationAbortController.abort();
      }
      validationAbortController = new AbortController();
      const abortController = validationAbortController;
      const formData = buildRevisionFormData('validate_revision');
      const validationRun = ++latestValidationRun;

      fetch(editor.getAttribute('action') || window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData,
        signal: abortController.signal
      })
        .then(function (response) {
          const contentType = (response.headers.get('content-type') || '').toLowerCase();
          const responseBody = contentType.indexOf('application/json') !== -1
            ? response.json().catch(function () { return null; })
            : response.text().catch(function () { return ''; });

          return responseBody.then(function (payload) {
            if (!response.ok) {
              const message = Array.isArray(payload?.warnings) && payload.warnings[0]?.message
                ? payload.warnings[0].message
                : (typeof payload === 'string' && payload.trim() !== '' ? payload.trim() : 'Unable to validate this revision right now. Please try again.');
              throw new Error(message);
            }
            return payload;
          });
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
        .catch(function (error) {
          if (abortController.signal.aborted) {
            return;
          }
          if (validationRun !== latestValidationRun) {
            return;
          }
          renderWarnings([{
            type: 'rule',
            message: error?.message || 'Unable to validate this revision right now. Please try again.'
          }]);
        });
    }

    function runAutosave() {
      if (typeof window.fetch !== 'function') return;
      if (autosaveInFlight) {
        autosaveQueued = true;
        return;
      }
      const formData = buildRevisionFormData('autosave_revision');
      const autosaveRun = ++latestAutosaveRun;
      const revisionVersion = latestRevisionVersion;
      autosaveInFlight = true;
      autosaveQueued = false;
      hasPendingAutosave = true;
      pendingAutosaveRun = autosaveRun;
      renderAutosaveStatus('Saving changes…', 'saving');

      fetch(editor.getAttribute('action') || window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      })
        .then(function (response) {
          const contentType = (response.headers.get('content-type') || '').toLowerCase();
          const responseBody = contentType.indexOf('application/json') !== -1
            ? response.json().catch(function () { return null; })
            : response.text().catch(function () { return ''; });

          return responseBody.then(function (payload) {
            if (!response.ok) {
              const message = Array.isArray(payload?.warnings) && payload.warnings[0]?.message
                ? payload.warnings[0].message
                : (typeof payload === 'string' && payload.trim() !== '' ? payload.trim() : 'Unable to autosave this revision right now.');
              throw new Error(message);
            }
            return payload;
          });
        })
        .then(function (payload) {
          if (revisionVersion > latestPersistedRevisionVersion) {
            latestPersistedRevisionVersion = revisionVersion;
          }
          if (autosaveRun !== latestAutosaveRun || revisionVersion !== latestRevisionVersion || autosaveQueued) {
            return;
          }
          if (pendingAutosaveRun === autosaveRun) {
            hasPendingAutosave = false;
            pendingAutosaveRun = 0;
          }
          hasDirtyRevisionChanges = latestPersistedRevisionVersion < latestRevisionVersion;
          renderWarnings(Array.isArray(payload?.warnings) ? payload.warnings : []);
          renderTotals(payload?.totals || null);
          renderAutosaveStatus('All changes saved.', 'saved');
        })
        .catch(function (error) {
          if (autosaveRun !== latestAutosaveRun) {
            return;
          }
          if (revisionVersion !== latestRevisionVersion || autosaveQueued) {
            return;
          }
          if (pendingAutosaveRun === autosaveRun) {
            hasPendingAutosave = false;
            pendingAutosaveRun = 0;
          }
          hasDirtyRevisionChanges = latestPersistedRevisionVersion < latestRevisionVersion;
          renderAutosaveStatus(error?.message || 'Autosave failed. Use Save Changes.', 'error');
        })
        .finally(function () {
          autosaveInFlight = false;
          if (revisionVersion !== latestRevisionVersion || autosaveQueued) {
            autosaveQueued = false;
            window.setTimeout(runAutosave, 0);
          }
        });
    }

    function scheduleAutosave() {
      latestRevisionVersion += 1;
      hasPendingAutosave = true;
      hasDirtyRevisionChanges = true;
      autosaveQueued = autosaveInFlight;
      renderAutosaveStatus('Unsaved changes…', 'saving');
      if (autosaveTimer) {
        window.clearTimeout(autosaveTimer);
      }
      autosaveTimer = window.setTimeout(runAutosave, 450);
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
      item.querySelectorAll('input[name^="items["], select[name^="items["], textarea[name^="items["]').forEach(function (input) {
        input.addEventListener('change', function () {
          if (!input.name || input.name.indexOf('items[') !== 0) return;
          if (input.matches('[data-action-select],[data-rent-input],[data-spare-input]')) {
            updateRowState(item);
          }
          scheduleValidation();
          scheduleAutosave();
        });
        input.addEventListener('input', function () {
          if (!input.name || input.name.indexOf('items[') !== 0) return;
          if (input.matches('[data-action-select],[data-rent-input],[data-spare-input]')) {
            updateRowState(item);
          }
          scheduleValidation();
          scheduleAutosave();
        });
      });
      updateRowState(item);
    });

    if (search) {
      search.addEventListener('input', applySearch);
    }

    if (typeof window.fetch !== 'function') {
      applySearch();
      renderAutosaveStatus('Autosave requires a newer browser. Standard saves still work.', 'idle');
      return;
    }

    editor.addEventListener('submit', function (event) {
      const submitter = event.submitter || null;
      if (allowNativeSubmit) {
        allowNativeSubmit = false;
        return;
      }
      if (autosaveTimer) {
        window.clearTimeout(autosaveTimer);
        autosaveTimer = null;
      }
      event.preventDefault();
      manualSaveInFlight = true;
      renderAutosaveStatus('Saving changes…', 'saving');
      fetch(editor.getAttribute('action') || window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: buildRevisionFormData('save_revision')
      })
        .then(function (response) {
          const contentType = (response.headers.get('content-type') || '').toLowerCase();
          const responseBody = contentType.indexOf('application/json') !== -1
            ? response.json().catch(function () { return null; })
            : response.text().catch(function () { return ''; });

          return responseBody.then(function (payload) {
            if (!response.ok) {
              const message = Array.isArray(payload?.warnings) && payload.warnings[0]?.message
                ? payload.warnings[0].message
                : (typeof payload === 'string' && payload.trim() !== '' ? payload.trim() : 'Unable to save this revision right now.');
              throw new Error(message);
            }
            return payload;
          });
        })
        .then(function (payload) {
          manualSaveInFlight = false;
          latestPersistedRevisionVersion = latestRevisionVersion;
          hasPendingAutosave = false;
          hasDirtyRevisionChanges = false;
          pendingAutosaveRun = 0;
          renderWarnings(Array.isArray(payload?.warnings) ? payload.warnings : []);
          renderTotals(payload?.totals || null);
          renderAutosaveStatus(payload?.message || 'All changes saved.', 'saved');
          if (Array.isArray(payload?.warnings) && payload.warnings.length > 0 && warningsWrap) {
            const bounds = warningsWrap.getBoundingClientRect();
            if (bounds.top < 0 || bounds.bottom > window.innerHeight) {
              warningsWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          }
        })
        .catch(function (error) {
          manualSaveInFlight = false;
          if (error instanceof TypeError) {
            allowNativeSubmit = true;
            if (typeof editor.requestSubmit === 'function') {
              editor.requestSubmit(submitter || undefined);
            } else {
              editor.submit();
            }
            return;
          }
          renderAutosaveStatus(error?.message || 'Save failed. Try again.', 'error');
        });
    });

    window.addEventListener('beforeunload', function (event) {
      if (manualSaveInFlight || (!hasPendingAutosave && !hasDirtyRevisionChanges)) return;
      event.preventDefault();
      event.returnValue = '';
    });

    applySearch();
    renderAutosaveStatus('Autosave ready.', 'idle');
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
        if (panel) {
          panel.focus();
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
        const active = document.activeElement;

        if (!active || active === modal || !modal.contains(active)) {
          event.preventDefault();
          (event.shiftKey ? last : first).focus();
          return;
        }

        if (event.shiftKey && active === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && active === last) {
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
      function trackSubmitter(event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
        lastSubmitter = button;
      }
      function confirmAction(event) {
        const submitter = event.submitter || lastSubmitter || (document.activeElement === button ? button : null);
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
        button.addEventListener('click', trackSubmitter);
        button.addEventListener('keydown', trackSubmitter);
        button.addEventListener('keyup', trackSubmitter);
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
      function trackSubmitter(event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
        lastSubmitter = button;
      }
      function confirmAction(event) {
        const submitter = event.submitter || lastSubmitter || (document.activeElement === button ? button : null);
        if (submitter !== button) return;
        if (!window.confirm(button.getAttribute('data-confirm-message') || 'Are you sure?')) {
          event.preventDefault();
          event.stopPropagation();
        }
      }

      if (form) {
        button.addEventListener('click', trackSubmitter);
        button.addEventListener('keydown', trackSubmitter);
        button.addEventListener('keyup', trackSubmitter);
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
