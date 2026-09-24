/* global app.js – shared across all CG Internal pages */

(function () {
  'use strict';

  /* ── Theme toggle ─────────────────────────────── */
  const THEME_KEY = 'cg-theme';

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    const icon = document.getElementById('theme-icon');
    if (icon) icon.textContent = theme === 'dark' ? 'light_mode' : 'dark_mode';
    const toggle = document.getElementById('theme-toggle');
    if (toggle) toggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
  }

  function initTheme() {
    const saved = localStorage.getItem(THEME_KEY) ||
      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyTheme(saved);
  }

  function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
  }

  /* ── Dismissible alerts ───────────────────────── */
  function initAlerts() {
    document.querySelectorAll('.alert-close').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const alert = btn.closest('.alert');
        if (alert) {
          alert.style.opacity = '0';
          alert.style.transform = 'translateY(-4px)';
          alert.style.transition = 'opacity 200ms, transform 200ms';
          setTimeout(function () { alert.remove(); }, 200);
        }
      });
    });
  }

  /* ── Auto-dismiss flash messages ─────────────── */
  function initFlash() {
    const flash = document.querySelectorAll('.alert[data-auto-dismiss]');
    flash.forEach(function (el) {
      const delay = parseInt(el.getAttribute('data-auto-dismiss'), 10) || 4000;
      setTimeout(function () {
        el.style.opacity = '0';
        el.style.transition = 'opacity 400ms';
        setTimeout(function () { el.remove(); }, 400);
      }, delay);
    });
  }

  /* ── Confirm dangerous actions ────────────────── */
  function initConfirm() {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (el.closest('form')) return;
        const msg = el.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) e.preventDefault();
      });
    });
  }

  /* ── Password strength indicator ─────────────── */
  function initPasswordStrength() {
    const pw = document.getElementById('password');
    const bar = document.getElementById('pw-strength-bar');
    if (!pw || !bar) return;

    pw.addEventListener('input', function () {
      const val = pw.value;
      let score = 0;
      if (val.length >= 8)  score++;
      if (/[A-Z]/.test(val)) score++;
      if (/[0-9]/.test(val)) score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;

      const pct = (score / 4) * 100;
      const colors = ['#ef4444', '#f59e0b', '#3b82f6', '#10b981'];
      bar.querySelector('.progress-fill').style.width = pct + '%';
      bar.querySelector('.progress-fill').style.background = colors[score - 1] || '#e2e8f0';
    });
  }

  /* ── Color picker preview ─────────────────────── */
  function initColorPreview() {
    document.querySelectorAll('input[type="color"][data-preview]').forEach(function (inp) {
      const target = document.getElementById(inp.getAttribute('data-preview'));
      if (!target) return;
      inp.addEventListener('input', function () {
        target.style.background = inp.value;
      });
    });
  }

  /* ── Dynamic form rows (add / remove) ────────── */
  function initDynamicRows() {
    document.querySelectorAll('[data-add-row]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const container = document.getElementById(btn.getAttribute('data-add-row'));
        if (!container) return;
        const template  = container.querySelector('[data-row-template]');
        if (!template) return;
        const clone = template.cloneNode(true);
        clone.removeAttribute('data-row-template');
        clone.querySelectorAll('input, select, textarea').forEach(function (el) {
          el.value = '';
        });
        container.appendChild(clone);
      });
    });

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-remove-row]')) {
        var row = e.target.closest('[data-remove-row]').closest('[data-row]');
        if (row) row.remove();
      }
    });
  }

  /* ── Page loader ──────────────────────────────── */
  function initPageLoader() {
    var loader = document.getElementById('page-loader');
    if (!loader) return;

    function startLoader() {
      loader.classList.remove('pg-done');
      loader.style.opacity  = '1';
      loader.style.animation = 'none';
      void loader.offsetWidth; // force reflow to restart animation
      loader.style.animation = '';
    }

    // Intercept same-origin link clicks
    document.addEventListener('click', function (e) {
      var link = e.target.closest('a[href]');
      if (!link) return;
      if (typeof e.button === 'number' && e.button !== 0) return;
      var href = link.getAttribute('href') || '';
      var target = (link.getAttribute('target') || '').toLowerCase();
      if ((target && target !== '_self') || link.hasAttribute('download') || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey ||
          href.charAt(0) === '#' || /^(javascript|data|vbscript|mailto|tel):/i.test(href) || href === '') return;
      var targetUrl;
      try {
        targetUrl = new URL(href, window.location.href);
      } catch (err) {
        return;
      }
      if (!/^https?:$/i.test(targetUrl.protocol) || targetUrl.origin !== window.location.origin) return;
      startLoader();
    });

    // Intercept form submits
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!form.matches('[data-start-loader]')) return;
      if (form.matches('[data-revision-editor], [data-no-loader]')) return;
      startLoader();
    });
  }

  /* ── Bootstrap on DOMContentLoaded ───────────── */
  document.addEventListener('DOMContentLoaded', function () {
    /* Complete the page loader */
    var loader = document.getElementById('page-loader');
    if (loader) loader.classList.add('pg-done');

    initTheme();
    initAlerts();
    initFlash();
    initConfirm();
    initPasswordStrength();
    initColorPreview();
    initDynamicRows();
    initPageLoader();

    /* Theme toggle button */
    const themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) themeBtn.addEventListener('click', toggleTheme);
  });

})();
