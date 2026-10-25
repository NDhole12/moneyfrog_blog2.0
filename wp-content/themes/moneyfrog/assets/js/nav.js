/* Header dropdowns and mobile drawer. No dependencies. */
(function () {
  'use strict';

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

  /* ---- Dropdowns: real buttons with aria-expanded ---- */
  var dropdowns = Array.prototype.slice.call(document.querySelectorAll('[data-dropdown]'));

  function setDropdown(item, open) {
    item.classList.toggle('is-open', open);
    var btn = item.querySelector('button');
    if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }

  function closeDropdowns(except) {
    dropdowns.forEach(function (item) { if (item !== except) { setDropdown(item, false); } });
  }

  dropdowns.forEach(function (item) {
    var btn = item.querySelector('button');
    if (!btn) { return; }
    btn.addEventListener('click', function (e) {
      /* A mouse click on a menu already opened by hover keeps it open; Enter/Space toggle. */
      var open = e.detail > 0 ? true : !item.classList.contains('is-open');
      closeDropdowns(item);
      setDropdown(item, open);
    });
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-dropdown]')) { closeDropdowns(null); }
  });

  /* Tabbing out of an open menu closes it. */
  dropdowns.forEach(function (item) {
    item.addEventListener('focusout', function (e) {
      if (!e.relatedTarget || !item.contains(e.relatedTarget)) { setDropdown(item, false); }
    });
    /* Hover reveals the menu through the same state, so aria-expanded never disagrees. */
    item.addEventListener('pointerenter', function (e) {
      if (e.pointerType === 'mouse') { closeDropdowns(item); setDropdown(item, true); }
    });
    item.addEventListener('pointerleave', function (e) {
      if (e.pointerType === 'mouse' && !item.contains(document.activeElement)) { setDropdown(item, false); }
    });
  });

  /* ---- Drawer with focus trap ---- */
  var drawer = document.getElementById('drawer');
  var opener = document.querySelector('[data-drawer-open]');
  if (!drawer || !opener) { return; }
  var panel = drawer.querySelector('.drawer__panel');

  function focusables() {
    return Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE));
  }

  function setDrawer(open, restoreFocus) {
    drawer.classList.toggle('is-open', open);
    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    opener.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('is-locked', open);
    if (open) {
      var items = focusables();
      if (items.length) { items[0].focus(); }
    } else if (restoreFocus) {
      opener.focus();
    }
  }

  opener.addEventListener('click', function () { setDrawer(true, true); });
  Array.prototype.forEach.call(drawer.querySelectorAll('[data-drawer-close]'), function (el) {
    el.addEventListener('click', function () { setDrawer(false, true); });
  });
  /* Following a link inside the drawer closes it without stealing focus from the navigation. */
  Array.prototype.forEach.call(panel.querySelectorAll('a[href]'), function (a) {
    a.addEventListener('click', function () { setDrawer(false, false); });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (drawer.classList.contains('is-open')) { setDrawer(false, true); return; }
      var openItem = dropdowns.filter(function (i) { return i.classList.contains('is-open'); })[0];
      if (openItem) {
        closeDropdowns(null);
        var b = openItem.querySelector('button');
        if (b) { b.focus(); }
      }
      return;
    }
    if (e.key !== 'Tab' || !drawer.classList.contains('is-open')) { return; }
    var items = focusables();
    if (!items.length) { return; }
    var first = items[0];
    var last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!panel.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  });

  /* Growing past the mobile breakpoint with the drawer open would strand the focus trap. */
  if (window.matchMedia) {
    window.matchMedia('(min-width: 1025px)').addEventListener('change', function (m) {
      if (m.matches && drawer.classList.contains('is-open')) { setDrawer(false, false); }
    });
  }

  /* ---- Forms that apply on change (category sort); a <noscript> button covers no-JS ---- */
  Array.prototype.forEach.call(document.querySelectorAll('form[data-autosubmit]'), function (form) {
    form.addEventListener('change', function () { form.submit(); });
  });
})();
