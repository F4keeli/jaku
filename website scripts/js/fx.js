/* =========================================================
   Stitchline Interactive — shared client utilities
   Exposes a single global namespace: window.SI
   ========================================================= */
(function (window) {
  'use strict';

  var SI = window.SI || {};
  window.SI = SI;

  SI.reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  SI.fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  SI.$ = function (sel, root) { return (root || document).querySelector(sel); };
  SI.$$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  SI.el = function (tag, className, html) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined && html !== null) node.innerHTML = html;
    return node;
  };

  /* Escape a value for use in innerHTML. Every renderer builds markup as
     strings, so this is the boundary that keeps panel-editable text (titles,
     names, blurbs, discount notes) from becoming markup. It used to be
     copy-pasted into home.js, site.js and panel.js, which meant three places to
     forget a character; fx.js loads first on every page, so this is the one
     copy. */
  SI.esc = function (value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  /* The inside of a staff member's avatar circle: their picture, or their
     initial. One implementation because the empty case is the dangerous one -
     an <img> with an empty src is a broken image, and the browser draws its alt
     text in the box, so a member with no picture had their name rendered
     *inside* their own avatar on top of the name printed underneath it. */
  /* The one empty state, for every list on the site and the panel.

     It was hand-written at each of nine call sites in site.js and a few in
     panel.js, and they had drifted: some centred their text, some left-aligned
     it, some had a glyph and some did not, and inside a card grid the note was
     itself a grid item and so took one card's width - which is what made the
     store's "no one-off items" read as a rendering fault rather than as a
     deliberate note.

     The glyph is decorative and carries no meaning, so it is hidden from
     assistive technology; the title is the thing being announced.
  */
  SI.empty = function (title, body) {
    return '<div class="empty-note">' +
      '<b>' + SI.esc(title) + '</b>' +
      (body ? '<span>' + SI.esc(body) + '</span>' : '') +
      '</div>';
  };

  SI.memberFace = function (member) {
    var m = member || {};
    var avatar = String(m.avatar || '').trim();
    if (avatar) {
      return '<img src="' + SI.esc(avatar) + '" alt="" loading="lazy">';
    }
    var name = String(m.name || '').trim();
    var initial = name ? name.charAt(0).toUpperCase() : '?';
    return '<span class="face-initial" aria-hidden="true">' + SI.esc(initial) + '</span>';
  };

  SI.store = {
    get: function (key, fallback) {
      try {
        var raw = window.localStorage.getItem(key);
        return raw === null ? fallback : JSON.parse(raw);
      } catch (err) {
        return fallback;
      }
    },
    set: function (key, value) {
      try {
        window.localStorage.setItem(key, JSON.stringify(value));
      } catch (err) { /* private mode */ }
    }
  };

  /* The server always answers with JSON, including for errors: it sends
     {"error": "<code>", "message": "<safe to show a visitor>"}. Surfacing both
     on the thrown Error means a form can show the real reason ("that is not the
     right answer") instead of "HTTP 422", and can react to a specific code
     (a spent human check reloads itself).

     The fallback for a body that is not JSON used to be "The server sent
     something unreadable", which is honest and useless: it does not say which
     request, which status, or what answered instead. That vagueness cost an
     afternoon once - the page had been open against an editor's file server on
     port 5500, so every API call 404'd with HTML and the site looked broken for
     reasons that had nothing to do with the site. So the fallback names the
     request, the status, and the shape of what came back, and says the most
     likely cause when the answer is HTML.
  */
  function request(path, options) {
    return window.fetch(path, options).then(function (res) {
      return res.text().then(function (body) {
        var data = null;
        var parsed = true;
        if (body) {
          try { data = JSON.parse(body); } catch (err) { parsed = false; }
        }
        if (!parsed) {
          var kind = /^\s*</.test(body) ? 'HTML' : 'plain text';
          var err = new Error(
            'The server sent ' + kind + ' instead of data for ' + path +
            ' (HTTP ' + res.status + ').' +
            (/^\s*</.test(body)
              ? ' That usually means something else is answering on this port - an ' +
                'editor\'s file server, for instance - rather than the site.'
              : ''));
          err.code = 'not_json';
          err.status = res.status;
          throw err;
        }
        data = data || {};
        if (!res.ok) {
          var bad = new Error(data && data.message ? data.message : 'HTTP ' + res.status);
          bad.code = (data && data.error) || 'http_' + res.status;
          bad.status = res.status;
          if (data && data.field) bad.field = data.field;
          throw bad;
        }
        return data;
      });
    }, function () {
      var err = new Error('Could not reach the server. Check your connection and try again.');
      err.code = 'network';
      throw err;
    });
  }

  /* A static build marks <body data-static="1">. On such a page there is no
     API behind anything: /api/site 404s, and every POST would too. The content
     is already inlined in the page, so reads are covered. Writes are not, and
     they are refused here with a sentence that explains why, rather than
     letting a visitor fill in a contact form and be told the server is
     unreachable - which is what a fetch failure says, and which would be a lie
     about a site that is working exactly as built. */
  var STATIC = document.body && document.body.getAttribute('data-static') === '1';
  var NO_BACKEND =
    'This form is not connected on this build of the site. Please reach us on Discord.';

  /* A static build inlines every read the pages make, keyed by endpoint, into
     <script id="site-data" type="application/json">:
       {"/api/site": {...}, "/api/store": {...}, "/api/applications": {...}}
     Read once, and only on a build that marked itself static. With the app
     serving, nothing here runs - which is deliberate, because a snapshot taken
     at build time must never be able to shadow live content the studio has since
     edited in the panel. */
  var staticPayloads = null;
  function readStaticPayload(path) {
    if (staticPayloads === null) {
      staticPayloads = {};
      try {
        var node = document.getElementById('site-data');
        if (node && node.textContent) {
          var parsed = JSON.parse(node.textContent);
          // Tolerate a plain object, so a payload inlined as just the site
          // content still answers /api/site rather than being misread as a map.
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            staticPayloads = parsed['/api/site'] !== undefined ? parsed : { '/api/site': parsed };
          }
        }
      } catch (err) {
        staticPayloads = {};
      }
    }
    var value = staticPayloads[path];
    return value === undefined ? null : value;
  }

  function noBackend() {
    var err = new Error(NO_BACKEND);
    err.code = 'no_backend';
    return Promise.reject(err);
  }

  SI.api = {
    static: STATIC,
    get: function (path) {
      /* Short-circuited outright on a static build: there is no API to ask, and
         a request certain to 404 is a request not worth making. */
      if (STATIC) {
        var inlined = readStaticPayload(path);
        if (inlined !== null) return Promise.resolve(inlined);
        return noBackend();
      }
      return request(path, { headers: { Accept: 'application/json' } })
        .catch(function (err) {
          /* 404 is the case worth catching: a static host answers the page and
             then 404s every API route, so the page would otherwise render
             empty. A network failure or a 500 is left alone - retrying those
             against a stale snapshot would hide a real outage. */
          if (err.status === 404 || err.status >= 500 || err.code === 'network') {
            var fallback = readStaticPayload(path);
            if (fallback !== null) return fallback;
          }
          throw err;
        });
    },
    post: function (path, body) {
      /* Never falls back. A POST is a submission, and quietly answering it from
         a static snapshot would tell a buyer their order was placed when
         nothing was ever sent. On a static build it is refused outright. */
      if (STATIC) return noBackend();
      return request(path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body || {})
      });
    }
  };

  SI.icons = {
    discord: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.32 4.57A19.79 19.79 0 0 0 15.43 3c-.24.42-.5.99-.69 1.44a18.3 18.3 0 0 0-5.48 0C9.07 3.99 8.8 3.42 8.56 3a19.74 19.74 0 0 0-4.89 1.57C.55 9.1-.28 13.54.12 17.93a19.9 19.9 0 0 0 6.03 3.06c.49-.66.92-1.37 1.29-2.11-.71-.27-1.39-.6-2.03-.98.17-.13.34-.26.5-.4a14.2 14.2 0 0 0 12.18 0c.16.14.33.27.5.4-.64.38-1.32.71-2.03.98.37.74.8 1.45 1.29 2.11a19.86 19.86 0 0 0 6.03-3.06c.47-5.09-.84-9.52-3.56-13.36ZM8.02 15.44c-1.18 0-2.15-1.08-2.15-2.4 0-1.32.95-2.4 2.15-2.4 1.2 0 2.17 1.08 2.15 2.4Zm7.96 0c-1.18 0-2.15-1.08-2.15-2.4 0-1.32.95-2.4 2.15-2.4 1.2 0 2.17 1.08 2.15 2.4Z"/></svg>',
    twitter: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.9 2H22l-6.8 7.77L23.2 22h-6.26l-4.9-6.42L6.5 22H3.34l7.27-8.3L2.4 2h6.42l4.44 5.87L18.9 2Zm-1.1 18.1h1.73L7.86 3.8H6.01L17.8 20.1Z"/></svg>',
    tiktok: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.6 5.82A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 1 1-1.79-2.46V9.74a5.72 5.72 0 1 0 4.88 5.66V9.01a7.34 7.34 0 0 0 4.3 1.38V7.3a4.28 4.28 0 0 1-3.24-1.48Z"/></svg>',
    youtube: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23.5 6.9a3.02 3.02 0 0 0-2.12-2.14C19.5 4.25 12 4.25 12 4.25s-7.5 0-9.38.51A3.02 3.02 0 0 0 .5 6.9C0 8.78 0 12.36 0 12.36s0 3.58.5 5.46a3.02 3.02 0 0 0 2.12 2.14c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3.02 3.02 0 0 0 2.12-2.14c.5-1.88.5-5.46.5-5.46s0-3.58-.5-5.46ZM9.55 15.93V8.79l6.27 3.57-6.27 3.57Z"/></svg>'
  };

  /* Every page shows the same social row in the header, so it is built once
     here instead of being copied into each page's script. A social with no URL
     is skipped rather than rendered as a dead link. */
  SI.renderSocials = function (socials) {
    var wrap = SI.$('#socials');
    if (!wrap) return;
    wrap.textContent = '';
    ['discord', 'twitter', 'tiktok', 'youtube'].forEach(function (name) {
      var href = socials && socials[name];
      if (!href) return;
      var link = document.createElement('a');
      link.className = 'social';
      link.href = href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.setAttribute('aria-label', name);
      link.title = name.charAt(0).toUpperCase() + name.slice(1);
      link.innerHTML = SI.icons[name] || '';
      wrap.appendChild(link);
    });
  };

  /* ---------------------------------------------------------
     Glitch bursts
     --------------------------------------------------------- */
  var glitchBusy = new WeakSet();

  SI.glitch = function (node) {
    if (!node || SI.reduced || glitchBusy.has(node)) return;
    glitchBusy.add(node);
    node.classList.remove('is-glitching');
    void node.offsetWidth;
    node.classList.add('is-glitching');
    window.setTimeout(function () {
      node.classList.remove('is-glitching');
      glitchBusy.delete(node);
    }, 460);
  };

  SI.bindGlitch = function (root) {
    SI.$$('[data-glitch]', root || document).forEach(function (node) {
      node.addEventListener('mouseenter', function () { SI.glitch(node); });
    });
  };

  /* rare full-page signal drop */
  SI.signalDrop = function () {
    var flash = SI.$('#fxFlash');
    if (!flash || SI.reduced) return;
    flash.classList.remove('is-firing');
    void flash.offsetWidth;
    flash.classList.add('is-firing');
    window.setTimeout(function () { flash.classList.remove('is-firing'); }, 500);
  };

  /* ---------------------------------------------------------
     Reveal on scroll
     --------------------------------------------------------- */
  SI.observeReveal = function (root) {
    var items = SI.$$('.reveal:not(.is-in)', root || document);
    if (!('IntersectionObserver' in window)) {
      items.forEach(function (n) { n.classList.add('is-in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        io.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    items.forEach(function (n) { io.observe(n); });
  };

  /* ---------------------------------------------------------
     Count-up animation
     --------------------------------------------------------- */
  SI.countUp = function (node, target, duration) {
    if (!node) return;
    if (SI.reduced || target === null || target === undefined) {
      node.textContent = target === null || target === undefined ? '—' : format(target);
      return;
    }
    var start = performance.now();
    var span = duration || 1400;
    function frame(now) {
      var p = Math.min(1, (now - start) / span);
      var eased = 1 - Math.pow(1 - p, 3);
      node.textContent = format(Math.round(target * eased));
      if (p < 1) window.requestAnimationFrame(frame);
    }
    window.requestAnimationFrame(frame);
  };

  function format(value) {
    return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  SI.formatNumber = format;

  /* ---------------------------------------------------------
     Typed line scrambler (used for headers)
     --------------------------------------------------------- */
  SI.scramble = function (node, finalText, opts) {
    if (!node) return;
    var settings = opts || {};
    var glyphs = settings.glyphs || 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789#%&@$*<>/\\[]';
    if (SI.reduced) { node.textContent = finalText; return; }
    var queue = finalText.split('').map(function (ch) {
      return { ch: ch, to: '', start: 0 };
    });
    var len = queue.length;
    var counterOut = 0;
    var scrambleFrom = settings.from || 18;
    var settle = settings.settle || 30;
    var out = [];

    function update() {
      for (var i = 0; i < len; i++) {
        var q = queue[i];
        if (!q.start) { q.start = i * settle; }
        var x = counterOut - q.start;
        if (x < 0) { out[i] = ''; continue; }
        if (x > scrambleFrom) { q.to = q.ch; }
        else if (x > scrambleFrom * 0.66) { q.to = ''; }
        else { q.to = glyphs[Math.floor(Math.random() * glyphs.length)]; }
        out[i] = q.to;
      }
      /* textContent, not innerHTML. The glyph pool contains < > &, and writing
         them through the HTML parser on every tick both re-parses markup and
         risks mangling the output. */
      node.textContent = out.join('');
    }

    var interval = window.setInterval(function () {
      counterOut++;
      update();
      if (counterOut > len + scrambleFrom) {
        window.clearInterval(interval);
        node.textContent = finalText;
      }
    }, 30);
    return interval;
  };
})(window);
