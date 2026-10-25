/* Reading progress bar for single posts. Updates aria-valuenow; no smooth animation under prefers-reduced-motion. */
(function () {
  'use strict';
  var bar = document.querySelector('.read-progress');
  var article = document.querySelector('article.post .entry-content');
  if (!bar || !article) { return; }
  var fill = bar.querySelector('.read-progress__bar');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) { bar.classList.add('is-static'); }
  var ticking = false;

  function percent() {
    var rect = article.getBoundingClientRect();
    var total = rect.height - window.innerHeight * 0.6;
    if (total <= 0) { return rect.bottom <= window.innerHeight ? 100 : 0; }
    var done = (window.innerHeight * 0.1 - rect.top) / total;
    return Math.max(0, Math.min(100, Math.round(done * 100)));
  }

  function update() {
    ticking = false;
    var p = percent();
    fill.style.transform = 'scaleX(' + (p / 100) + ')';
    bar.setAttribute('aria-valuenow', String(p));
  }

  function request() {
    if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
  }

  bar.hidden = false;
  window.addEventListener('scroll', request, { passive: true });
  window.addEventListener('resize', request, { passive: true });
  update();
}());
