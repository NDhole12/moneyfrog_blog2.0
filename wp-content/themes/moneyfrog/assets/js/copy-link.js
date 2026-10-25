/* Copy-link control. Without JS (or without the clipboard API) it stays a plain link to the post. */
(function () {
  'use strict';
  var link = document.querySelector('[data-copy-link]');
  if (!link || !navigator.clipboard || !navigator.clipboard.writeText) { return; }
  var status = link.parentNode.querySelector('.share__status');
  var timer = null;

  function announce(message, ok) {
    if (status) { status.textContent = message; }
    link.classList.toggle('is-copied', ok);
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      if (status) { status.textContent = ''; }
      link.classList.remove('is-copied');
    }, 2500);
  }

  link.setAttribute('role', 'button');
  link.addEventListener('click', function (event) {
    event.preventDefault();
    navigator.clipboard.writeText(link.href).then(
      function () { announce('Copied', true); },
      function () { announce('Copy failed', false); }
    );
  });
  link.addEventListener('keydown', function (event) {
    if (event.key === ' ') { event.preventDefault(); link.click(); }
  });
}());
