/* =========================================================
   Boot sequence and global page behaviour
   ========================================================= */
(function (window, document) {
  'use strict';

  var SI = window.SI;

  document.addEventListener('DOMContentLoaded', function () {
    /* The listener is registered before boot() because boot() can dispatch
       si:ready synchronously when there is no splash, and a listener added
       afterwards would never hear it. */
    window.addEventListener('si:ready', function () {
      scheduleSignalDrops();
      scrambleHero();
    });
    boot();
    SI.$$('.reveal').forEach(function (node) {
      node.style.setProperty('--d', (node.getAttribute('data-delay') || 0) + 'ms');
    });
    SI.bindGlitch();
    SI.observeReveal();
    wireAnchors();
  });

  /* ---------------------------------------------------------
     Boot splash
     --------------------------------------------------------- */
  /* The splash was on screen for a fixed ~1.2s of setTimeout steps regardless
     of whether the page was ready. It now waits on the real signal, the window
     load event, and never outlasts a 1.6s ceiling. A visitor on a warm cache
     previously sat through the whole thing watching a fake progress bar. */
  function boot() {
    var overlay = SI.$('#boot');
    if (!overlay) {
      /* No splash on this page. Fire the ready signal straight away so the
         effects below still start. */
      window.dispatchEvent(new CustomEvent('si:ready'));
      return;
    }
    var bar = overlay.querySelector('.boot__bar i');
    var text = SI.$('#bootText');
    var progress = 0;
    var done = false;
    var timers = [];

    var step = function () {
      progress = Math.min(92, progress + 12 + Math.random() * 16);
      if (bar) bar.style.width = progress + '%';
      if (text) {
        text.textContent = progress < 45
          ? 'INITIALISING STITCHLINE'
          : progress < 85 ? 'LINKING UPLINK' : 'READY';
      }
    };

    var finish = function () {
      if (done) return;
      done = true;
      timers.forEach(window.clearTimeout);
      if (bar) bar.style.width = '100%';
      overlay.classList.add('is-done');
      window.setTimeout(function () {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        /* The cursor and every animation below were ticking behind an opaque
           overlay for the whole splash. Start them once it is gone. */
        document.dispatchEvent(new CustomEvent('si:ready'));
      }, 600);
      window.setTimeout(function () { SI.signalDrop(); }, 240);
    };

    var tick = window.setInterval(step, 90);
    timers.push(tick);
    /* Ceiling: if load never fires (a stalled asset), still get to the page. */
    timers.push(window.setTimeout(finish, 1600));
    if (document.readyState === 'complete') timers.push(window.setTimeout(finish, 120));
    else window.addEventListener('load', function () { timers.push(window.setTimeout(finish, 80)); });
  }

  /* ---------------------------------------------------------
     Hero title scramble
     --------------------------------------------------------- */
  function scrambleHero() {
    var lines = SI.$$('.hero__line');
    if (!lines.length) return;
    lines.forEach(function (line, i) {
      var finalText = line.getAttribute('data-glitch') || line.textContent.trim();
      line.textContent = '';
      window.setTimeout(function () {
        SI.scramble(line, finalText, { from: 14, settle: 34 });
        if (i === lines.length - 1) {
          window.setTimeout(function () { SI.glitch(line); }, 900);
        }
      }, SI.reduced ? 0 : 220 + i * 220);
    });
  }

  /* ---------------------------------------------------------
     In-page anchors
     --------------------------------------------------------- */
  function wireAnchors() {
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[href^="#"]');
      if (!link) return;
      var id = link.getAttribute('href');
      if (!id || id === '#') return;
      var target = document.querySelector(id);
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: SI.reduced ? 'auto' : 'smooth', block: 'start' });
    });
  }

  /* ---------------------------------------------------------
     Occasional signal drop for atmosphere
     --------------------------------------------------------- */
  function scheduleSignalDrops() {
    if (SI.reduced) return;
    var loop = function () {
      window.setTimeout(function () {
        SI.signalDrop();
        loop();
      }, 9000 + Math.random() * 14000);
    };
    loop();
  }
})(window, document);
