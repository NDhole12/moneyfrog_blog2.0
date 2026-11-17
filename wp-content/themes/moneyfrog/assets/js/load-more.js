/* Home "Load More Articles": appends the next page of the latest list in place.
   Without JavaScript (or on any failure) the button is a plain link to /page/N/. */
(function () {
  'use strict';
  var btn = document.querySelector('[data-load-more]');
  var grid = document.querySelector('#latest .card-grid');
  if (!btn || !grid || !window.fetch || !window.DOMParser) { return; }

  var base = btn.getAttribute('data-base');
  var total = parseInt(btn.getAttribute('data-total'), 10);
  var next = parseInt(btn.getAttribute('data-next'), 10);
  var label = btn.textContent;
  if (!base || !(total > 1) || !(next > 1)) { return; }

  btn.addEventListener('click', function (event) {
    event.preventDefault();
    if (btn.getAttribute('aria-busy') === 'true') { return; }
    var url = base + next + '/';
    btn.setAttribute('aria-busy', 'true');
    btn.textContent = 'Loading…';

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var cards = doc.querySelectorAll('#latest .card-grid > .card');
        if (!cards.length) { throw new Error('no cards'); }
        var first = null;
        Array.prototype.forEach.call(cards, function (card) {
          var node = document.importNode(card, true);
          grid.appendChild(node);
          first = first || node;
        });
        next += 1;
        if (next > total) {
          btn.parentNode.removeChild(btn);
        } else {
          btn.setAttribute('href', base + next + '/#latest');
          btn.textContent = label;
          btn.removeAttribute('aria-busy');
        }
        var link = first && first.querySelector('.card__link');
        if (link) { link.focus({ preventScroll: true }); }
      })
      .catch(function () {
        window.location.href = url + '#latest';
      });
  });
})();
