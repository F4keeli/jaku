/* =========================================================
   Custom cursor — a slowly rotating Stitchline cube
   ========================================================= */
(function (window) {
  'use strict';

  var SI = window.SI;
  if (!SI || !SI.fine) return;

  document.addEventListener('DOMContentLoaded', function () {
    var cursor = SI.$('#cursor');
    if (!cursor) return;

    document.body.classList.add('has-cursor');

    var targetX = window.innerWidth / 2;
    var targetY = window.innerHeight / 2;
    var cubeX = targetX;
    var cubeY = targetY;

    var HOT = 'a, button, input, textarea, select, [role="button"], .card, .member, .quote, .announce__dot';

    document.addEventListener('mousemove', function (event) {
      targetX = event.clientX;
      targetY = event.clientY;
      if (!cursor.classList.contains('is-live')) {
        cursor.classList.add('is-live');
        cubeX = targetX;
        cubeY = targetY;
        /* Paint the first position immediately. tick() parks itself when the
           cube has caught up, and on this first move it is already there, so
           without this the cube would fade in pinned to the top-left corner. */
        cursor.style.transform = 'translate3d(' + cubeX + 'px,' + cubeY + 'px,0)';
      }
      wake();
    }, { passive: true });

    document.addEventListener('mousedown', function () {
      cursor.classList.add('is-down');
    }, { passive: true });

    document.addEventListener('mouseup', function () {
      cursor.classList.remove('is-down');
    }, { passive: true });

    document.addEventListener('mouseover', function (event) {
      var hot = event.target instanceof Element && event.target.closest(HOT);
      cursor.classList.toggle('is-hot', !!hot);
    }, { passive: true });

    document.addEventListener('mouseleave', function () {
      cursor.classList.remove('is-live', 'is-hot', 'is-down');
    });

    document.addEventListener('mouseenter', function () {
      cursor.classList.add('is-live');
      wake();
    });

    if (SI.reduced) return;

    /* Seed the position at start-up. The element is position:fixed at 0,0 and
       the first frame of a live cursor is written by the mousemove handler, so
       without this the cube is briefly drawn in the corner if the pointer is
       already inside the window when the page loads. */
    cursor.style.transform = 'translate3d(' + cubeX + 'px,' + cubeY + 'px,0)';

    /* The rAF loop below used to write three custom properties and a transform
       on every frame, unconditionally, forever. Two things made that expensive:
       the drop-shadow radius is derived from --lag, so changing it every frame
       re-rasterised the cube 60 times a second, and the loop kept running even
       when the pointer had not moved. Now the loop parks itself as soon as the
       cube reaches the pointer, and each property is only written when its
       quantised value actually changes. */
    var raf = 0;
    var lastX = null;
    var lastY = null;
    var lastTiltX = null;
    var lastTiltY = null;
    var lastLag = null;

    function tick() {
      cubeX += (targetX - cubeX) * 0.19;
      cubeY += (targetY - cubeY) * 0.19;

      var dx = targetX - cubeX;
      var dy = targetY - cubeY;
      var dist = Math.hypot(dx, dy);

      /* Park the loop once the cube has essentially caught up. Any further
         movement schedules another frame, so this costs nothing when idle. */
      if (dist < 0.15) {
        raf = 0;
        return;
      }

      var qx = Math.round(cubeX * 2) / 2;
      var qy = Math.round(cubeY * 2) / 2;
      if (qx !== lastX || qy !== lastY) {
        lastX = qx;
        lastY = qy;
        cursor.style.transform = 'translate3d(' + qx + 'px,' + qy + 'px,0)';
      }

      var tiltX = Math.round(Math.max(-16, Math.min(16, dy * 0.28)) * 2) / 2;
      var tiltY = Math.round(Math.max(-16, Math.min(16, -dx * 0.28)) * 2) / 2;
      if (tiltX !== lastTiltX) {
        lastTiltX = tiltX;
        cursor.style.setProperty('--tiltX', tiltX + 'deg');
      }
      if (tiltY !== lastTiltY) {
        lastTiltY = tiltY;
        cursor.style.setProperty('--tiltY', tiltY + 'deg');
      }

      /* Half-pixel steps: the glow still tracks the lag, but the drop-shadow
         filter is recalculated a fraction as often. */
      var lag = Math.round((dist / 40) * 4) / 4;
      if (lag !== lastLag) {
        lastLag = lag;
        cursor.style.setProperty('--lag', String(lag));
      }

      raf = window.requestAnimationFrame(tick);
    }

    function wake() {
      if (!raf) raf = window.requestAnimationFrame(tick);
    }
  });
})(window);
