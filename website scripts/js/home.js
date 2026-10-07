/* =========================================================
   Home page renderer — pulls every editable value from the API
   ========================================================= */
(function (window, document) {
  'use strict';

  var SI = window.SI;

  var QUOTES = [
    'What you can do is change your future from your past',
    "When you fail, failure shouldn't define you it should build you",
    "We build what's right for players"
  ];

  var READ_KEY = 'si.announcements.read';
  /* sessionStorage, not localStorage: a "visit" is one browser session, so a
     returning visitor still counts while a refresh does not inflate the total. */
  var VISIT_KEY = 'si.visit.counted';

  document.addEventListener('DOMContentLoaded', function () {
    start();
  });

  /* One fetch, and one apply.

     The failure handler does not re-run the whole page. It used to call
     applySite(null), which is safe-looking and was not: a throw anywhere inside
     applySite lands in this catch, so the error handler ran a second render with
     no data and overwrote every section the first one had already filled in. A
     single bad line in one renderer therefore blanked the whole home page, and
     the visible symptom - a "pending" that no stored value could clear - pointed
     at the data rather than at the code. The first renderer to throw a bare
     `esc` cost an afternoon to find because the throw was swallowed here and
     reported nowhere.

     So: renderers are called independently, one failure cannot take the others
     with it, and whatever goes wrong is written to the console. */
  function start() {
    renderQuotes();
    SI.api.get('/api/site').then(function (data) {
      applySite(data);
    }).catch(function (err) {
      console.warn('The site content could not be loaded.', err);
    });
  }

  /* ---------------------------------------------------------
     Hero
     --------------------------------------------------------- */
  function renderQuotes() {
    var wrap = SI.$('#quotes');
    var dots = SI.$('#quoteDots');
    if (!wrap || !dots) return;

    var blocks = SI.$$('.quote', wrap);
    if (!blocks.length) return;

    blocks.forEach(function (q, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-label', 'Quote ' + (i + 1));
      if (i === 0) b.className = 'is-on';
      b.addEventListener('click', function () { show(i); });
      dots.appendChild(b);
    });

    var buttons = SI.$$('button', dots);
    var current = 0;
    var timer = null;

    function show(index) {
      blocks.forEach(function (b, i) {
        b.classList.toggle('is-active', i === index);
        b.classList.toggle('is-out', i !== index);
      });
      buttons.forEach(function (b, i) { b.classList.toggle('is-on', i === index); });
      current = index;
    }

    function play() {
      window.clearInterval(timer);
      if (SI.reduced) return;
      timer = window.setInterval(function () { show((current + 1) % blocks.length); }, 5200);
    }
    play();
    dots.addEventListener('mouseenter', function () { window.clearInterval(timer); });
    dots.addEventListener('mouseleave', play);
  }

  /* ---------------------------------------------------------
     Socials
     --------------------------------------------------------- */
  /* The header row is identical on every page, so SI.renderSocials in fx.js
     owns it. This wrapper is kept only so the call site reads the same as the
     other page scripts. */
  function renderSocials(socials) {
    SI.renderSocials(socials);
  }

  /* ---------------------------------------------------------
     Counters
     --------------------------------------------------------- */
  var counted = false;

  function renderCounters(data) {
    var stats = (data && data.stats) || {};
    var community = (data && data.community) || {};

    var gamesEl = SI.$('[data-counter="games"]');
    var teamEl = SI.$('[data-counter="team"]');
    var commEl = SI.$('[data-counter="community"]');
    var gamesNote = SI.$('[data-counter-note="games"]');

    if (gamesEl) gamesEl.setAttribute('data-target', String(stats.games || 0));
    if (teamEl) teamEl.setAttribute('data-target', String(stats.team_members || 0));
    if (commEl) commEl.setAttribute('data-target', String(community.total || 0));

    if (gamesNote) {
      var wip = stats.games_in_production || 0;
      gamesNote.textContent = stats.games
        ? (wip === stats.games ? 'all in development' : wip + ' in production')
        : 'none in production yet';
    }

    var commNote = SI.$('[data-counter-note="community"]');
    if (commNote) {
      if (!community.total) {
        commNote.textContent = community.source === 'config'
          ? 'add a bot token to link live'
          : 'across our servers';
      } else {
        commNote.textContent = SI.formatNumber(community.online || 0) + ' online now';
      }
    }

    if (counted) return;
    var box = SI.$('#counters');
    if (!box) return;
    if (!('IntersectionObserver' in window)) { fireCounters(); return; }
    var io = new IntersectionObserver(function (entries) {
      if (entries.some(function (e) { return e.isIntersecting; })) {
        fireCounters();
        io.disconnect();
      }
    }, { threshold: 0.3 });
    io.observe(box);
  }

  function fireCounters() {
    counted = true;
    SI.$$('[data-counter]').forEach(function (node) {
      var target = parseInt(node.getAttribute('data-target') || '0', 10);
      SI.countUp(node, target, 1500);
    });
  }

  /* ---------------------------------------------------------
     Announcements — one dot per announcement, 14 day lifetime
     --------------------------------------------------------- */
  function renderAnnouncements(data) {
    var dotsWrap = SI.$('#announceDots');
    var panel = SI.$('#announcePanel');
    if (!dotsWrap || !panel) return;

    var items = (data && data.announcements) || [];

    if (!items.length) {
      panel.innerHTML =
        '<div class="announce__empty">' +
          '<b>No active announcements</b>' +
          '<span>Studio updates appear here the moment they are posted, and each one retires ' +
            'automatically after ' + ((data && data.announcement_ttl_days) || 14) + ' days.</span>' +
        '</div>';
      return;
    }

    var read = SI.store.get(READ_KEY, {}) || {};

    function paint(index) {
      var item = items[index];
      var age = daysSince(item.created_at);
      var expiry = (data && data.announcement_ttl_days) || 14;

      panel.innerHTML =
        '<div class="announce__head">' +
          '<h3 class="announce__title">' + SI.esc(item.title || 'Update') + '</h3>' +
          '<span class="tag tag--alt">' + SI.esc(item.tag || 'Update') + '</span>' +
          '<div class="announce__nav">' +
            '<button type="button" data-ann="prev" aria-label="Previous">&#8592;</button>' +
            '<button type="button" data-ann="next" aria-label="Next">&#8594;</button>' +
          '</div>' +
        '</div>' +
        '<p class="announce__body">' + SI.esc(item.body || '') + '</p>' +
        '<p class="announce__meta">Posted ' + age + ' &middot; expires after ' + expiry + ' days</p>';

      SI.$$('[data-ann]', panel).forEach(function (b) {
        b.addEventListener('click', function () {
          var step = b.getAttribute('data-ann') === 'next' ? 1 : -1;
          var next = (index + step + items.length) % items.length;
          select(next);
        });
      });

      SI.$$('.announce__dot', dotsWrap).forEach(function (dot, i) {
        dot.classList.toggle('is-active', i === index);
      });
    }

    function select(index) {
      var item = items[index];
      if (!item) return;
      paint(index);
      if (item.id) { read[item.id] = Date.now(); SI.store.set(READ_KEY, read); }
      refreshDots();
    }

    function refreshDots() {
      SI.$$('.announce__dot', dotsWrap).forEach(function (dot, i) {
        var item = items[i];
        dot.classList.toggle('is-unread', !(item.id && read[item.id]));
        dot.setAttribute('aria-label', 'Announcement ' + (i + 1) + ': ' + (item.title || 'Update'));
      });
    }

    items.forEach(function (item, i) {
      var dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'announce__dot';
      dot.setAttribute('role', 'tab');
      dot.addEventListener('click', function () { select(i); });
      dotsWrap.appendChild(dot);
    });

    refreshDots();
    paint(0);
  }

  function daysSince(stamp) {
    if (!stamp) return 'recently';
    var then = Date.parse(stamp);
    if (isNaN(then)) return 'recently';
    var days = Math.max(0, Math.floor((Date.now() - then) / 86400000));
    if (days === 0) return 'today';
    if (days === 1) return 'yesterday';
    return days + ' days ago';
  }

  /* ---------------------------------------------------------
     Featured game
     --------------------------------------------------------- */
  var starGlyph = '★';

  function renderFeatured(data) {
    var f = (data && data.featured) || {};
    var stats = f.stats || {};

    setText('#featureStatus', f.status || 'In Production');
    setText('#featureEta', f.release_estimate || 'TBA');
    setText('#featureDesc', f.description || '');
    setAttr('#featurePlay', 'href', f.url || '#');
    setAttr('#featureDiscord', 'href', f.discord || (data && data.socials && data.socials.discord) || '#');
    if (f.thumb) setAttr('#featureThumb', 'src', f.thumb);

    var nameNode = SI.$('.feature__name');
    if (nameNode && f.title) {
      nameNode.textContent = f.title;
      nameNode.setAttribute('data-glitch', f.title);
    }

    var box = SI.$('#featureStats');
    if (box) {
      // Rating, player count and visit count are all statistics about the
      // *game*, so all three follow the same rule: the panel-editable number
      // wins, the bot can publish a live figure used only when the stored value
      // is blank, and with neither it says "pending" rather than showing a zero
      // that reads as a real measurement.
      //
      // And all three are built the same way - label, value, note - because the
      // old version had the rating's "pending" at display size and the two
      // numbers' at caption size, in different places in two different boxes.
      // Nothing was actually wrong with either; it just looked like two ideas
      // that had been dropped in rather than one idea done three times.
      var live = data && data.stats;
      var active = featuredValue(stats.active_players, live && live.featured_active_players);
      var visits = featuredValue(stats.total_visits, live && live.featured_total_visits);
      var rating = featuredScore(stats.rating);
      var count = featuredValue(stats.rating_count, live && live.featured_rating_count);
      box.innerHTML =
        stat('Rating',
          rating === null
            ? pending()
            : stars(rating) +
              '<span class="fstat__score">' + rating.toFixed(1) + '</span>',
          rating === null || count === null
            ? (rating === null ? '' : note('ratings not counted'))
            : note(SI.formatNumber(count) + (Math.round(count) === 1 ? ' rating' : ' ratings'))) +
        stat('Current Active Players',
          active === null ? pending() : SI.formatNumber(Number(active) || 0)) +
        stat('Total Active Visits',
          visits === null ? pending() : SI.formatNumber(Number(visits) || 0));
    }
  }

  /* One of the three cells, with a third line whether or not there is anything
     to put in it, so the row's three boxes keep the same internal rhythm and
     the numbers sit on the same line. */
  function stat(label, value, sub) {
    return '<dl class="fstat"><dt>' + SI.esc(label) + '</dt>' +
      '<dd>' + (value || '<span class="fstat__none">&mdash;</span>') + '</dd>' +
      '<dd class="fstat__note">' + (sub || '') + '</dd></dl>';
  }

  /* The one "no measurement yet" treatment, used by all three. */
  function pending() {
    return '<span class="fstat__pending">pending</span>';
  }

  function note(text) {
    return text ? '<span class="fstat__sub">' + SI.esc(text) + '</span>' : '';
  }

  /* A score out of five, or null when there is not one. Clamped rather than
     rejected: a stored 47 is a typo, and a page that draws 47 stars is worse
     than one that draws five. */
  function featuredScore(value) {
    if (value === null || value === undefined || value === '') return null;
    var score = Number(value);
    if (!isFinite(score) || score <= 0) return null;
    return Math.min(5, score);
  }

  /* Five stars, filled proportionally to the score.

     The fill is a clipped overlay rather than a count of full and half stars,
     because a rating is continuous - 4.6 is four and three fifths, and rounding
     it to four and a half says something untrue. A store that showed a 4.6 as
     four stars is understating it; one that showed five would be inventing.

     The row is one image to a screen reader, which is why it carries the score
     in its label: five asterisks read aloud are noise. */
  function stars(score) {
    var percent = Math.max(0, Math.min(100, (score / 5) * 100));
    var rounded = Math.round(score * 10) / 10;
    return '<span class="stars" role="img" aria-label="' + SI.esc(rounded.toFixed(1)) +
      ' out of 5">' +
      '<span class="stars__row" aria-hidden="true">' +
      '<span class="stars__empty">' + starGlyph.repeat(5) + '</span>' +
      '<span class="stars__fill" style="width:' + percent.toFixed(2) + '%">' +
      starGlyph.repeat(5) + '</span></span></span>';
  }

  /* The stored panel value, or a live bot figure when the stored one is blank.
     Returns null when there is neither, which is the case that must read as
     "pending" rather than as a number. */
  function featuredValue(stored, live) {
    if (stored !== null && stored !== undefined && stored !== '') return Number(stored) || 0;
    if (live !== null && live !== undefined && live !== '') return Number(live) || 0;
    return null;
  }

  /* ---------------------------------------------------------
     Values / Players / Goals
     --------------------------------------------------------- */
  function renderCards(targetSel, items, mod) {
    var wrap = SI.$(targetSel);
    if (!wrap) return;
    wrap.innerHTML = '';
    (items || []).forEach(function (item, i) {
      var card = document.createElement('article');
      card.className = 'card reveal' + (mod ? ' card--alt' : '');
      card.setAttribute('data-delay', String(i * 80));
      card.innerHTML =
        '<span class="card__n">' + pad(i + 1) + '</span>' +
        '<h3 class="card__title">' + SI.esc(item.title || '') + '</h3>' +
        '<p class="card__text">' + SI.esc(item.text || item.description || '') + '</p>';
      wrap.appendChild(card);
    });
    SI.observeReveal(wrap);
  }

  /* ---------------------------------------------------------
     Team preview
     --------------------------------------------------------- */
  /* ---------------------------------------------------------
     Founders of the studio
     ---------------------------------------------------------
     Written about in prose rather than derived from a job title, so they live in
     their own list: the founders write who they are and why, and the roster on
     /team says who holds which role. Editing one does not quietly rewrite the
     other, and a founder without a written background is a gap in the front page
     rather than a card with an empty paragraph. */
  function renderFounders(data) {
    var wrap = SI.$('#foundersRow');
    if (!wrap) return;
    var team = (data && data.team) || {};
    var founders = team.founders || [];
    if (!founders.length) {
      wrap.innerHTML = '';
      wrap.hidden = true;
      return;
    }
    wrap.hidden = false;
    wrap.innerHTML = founders.map(function (person, i) {
      var discordId = String(person.discord_id || '').trim();
      var discordProfile = /^[0-9]{17,20}$/.test(discordId)
        ? '<p class="muted small mono">Discord ID: <a href="https://discord.com/users/' +
          discordId + '" target="_blank" rel="noopener noreferrer">' + SI.esc(discordId) + '</a></p>'
        : '';
      return '<article class="founder reveal" data-delay="' + (i * 90) + '">' +
        '<div class="founder__face">' + SI.memberFace(person) + '</div>' +
        '<div class="founder__body">' +
          '<p class="founder__role">' + SI.esc(person.title || 'Founder') + '</p>' +
          '<h3 class="founder__name">' + SI.esc(person.name) + '</h3>' +
          discordProfile +
          '<p class="founder__blurb">' + SI.esc(person.blurb || '') + '</p>' +
        '</div></article>';
    }).join('');
    SI.observeReveal(wrap);
  }

  /* The rest of the studio, as a compact grid. The founders are already given
     their own cards above, so the tiers they head are left out rather than
     repeated here in a smaller form. */
  function renderTeam(data) {
    var wrap = SI.$('#teamPreview');
    if (!wrap) return;
    var team = (data && data.team) || {};
    var founderNames = {};
    (team.founders || []).forEach(function (person) {
      if (person && person.name) founderNames[String(person.name)] = true;
    });
    var groups = (team.groups || []).map(function (group) {
      return Object.assign({}, group, {
        members: (group.members || []).filter(function (member) {
          return !founderNames[String(member.name || '')];
        })
      });
    }).filter(function (group) {
      // A tier whose only members are the founders adds nothing below them.
      return group.key !== 'Leadership';
    });
    wrap.innerHTML = '';

    groups.forEach(function (group, gi) {
      var members = group.members || [];
      var label = group.label || group.key;
      var box = document.createElement('article');
      box.className = 'member reveal';
      box.setAttribute('data-delay', String(gi * 90));

      var visual;
      if (members.length === 1) {
        visual =
          '<div class="member__pfp">' + SI.memberFace(members[0]) + '</div>' +
          '<p class="member__rank">' + SI.esc(members[0].title || label) + '</p>' +
          '<h3 class="member__name">' + SI.esc(members[0].name) + '</h3>';
      } else if (members.length > 1) {
        visual =
          '<div class="faces">' + members.slice(0, 4).map(function (m) {
            return '<span>' + SI.memberFace(m) + '</span>';
          }).join('') + '</div>' +
          '<p class="member__rank">' + members.length + ' in ' + SI.esc(label) + '</p>';
      } else {
        visual =
          '<div class="faces"><span class="faces--open" aria-hidden="true">+</span></div>' +
          '<p class="member__rank">' + SI.esc(label) + '</p>' +
          (group.roles && group.roles.length
            ? '<h3 class="member__name">' + group.roles.length + ' roles</h3>'
            : '<h3 class="member__name">Open</h3>');
      }

      box.innerHTML = visual +
        (members.length === 1 ? '' : '<p class="member__blurb">' + SI.esc(group.blurb || '') + '</p>');
      wrap.appendChild(box);
    });

    if (!groups.length) {
      wrap.innerHTML = SI.empty('Nobody to show yet',
        'The founders are above. Everyone else is added from the panel under ' +
        'Leadership > Staff Configuration.');
    }
    SI.observeReveal(wrap);
  }

  /* ---------------------------------------------------------
     Visit counter, for the website
     --------------------------------------------------------- */
  function countVisit() {
    // localStorage would count one visit for the lifetime of the browser, so a
    // returning visitor would never register again. sessionStorage gives one
    // count per session, which is the right unit for "how many times people
    // came here".
    try {
      if (window.sessionStorage.getItem(VISIT_KEY)) return;
      window.sessionStorage.setItem(VISIT_KEY, '1');
    } catch (e) {
      return; /* private mode: skip rather than count on every load */
    }
    /* Nothing is written to the page. This used to overwrite the featured
       game's "Total Active Visits" with this site's own page views, so the
       studio's traffic was displayed as a statistic about the game. The count
       is still real and is reported in the panel as site visits. */
    SI.api.post('/api/visits').catch(function () { /* non-critical */ });
  }

  /* ---------------------------------------------------------
     Apply everything
     --------------------------------------------------------- */
  function applySite(data) {
    if (data && data.studio) {
      var name = data.studio.name || 'Stitchline Interactive';
      document.title = name;
      if (data.studio.tagline) {
        var tag = SI.$('.hero__tagline');
        if (tag) tag.textContent = data.studio.tagline;
        var meta = document.querySelector('meta[name="description"]');
        if (meta) meta.setAttribute('content', data.studio.tagline);
      }
      if (data.studio.favicon) setAttr('link[rel="icon"]', 'href', data.studio.favicon);
      if (data.studio.background) {
        var bg = SI.$('.hero__logo');
        if (bg) bg.src = data.studio.background;
      }
      if (data.studio.cursor) {
        var cube = SI.$('#cursor img');
        if (cube) cube.src = data.studio.cursor;
      }
    }

    renderSocials(data && data.socials);
    renderCounters(data);
    renderAnnouncements(data);
    each([
      ['founders', function () { renderFounders(data); }],
      ['featured game', function () { renderFeatured(data); }],
      ['values', function () { renderCards('#valuesRow', data && data.values, false); }],
      ['players', function () { renderCards('#playersRow', data && data.players, true); }],
      ['goals', function () { renderCards('#goalsRow', data && data.goals, false); }],
      ['team', function () { renderTeam(data); }]
    ]);

    if (data) countVisit();
    window.dispatchEvent(new CustomEvent('si:site', { detail: data }));
  }

  /* Run each renderer on its own, and report the ones that fail.

     applySite draws nine unrelated things into nine unrelated places. Called as
     one straight line of function calls, the first throw skipped every section
     after it and was then swallowed by the fetch's catch handler, so the page
     came up half drawn with nothing in the console. One section failing is now
     one section failing, and it says which.
  */
  function each(renderers) {
    renderers.forEach(function (entry) {
      try {
        entry[1]();
      } catch (err) {
        console.warn('Could not render the ' + entry[0] + ' section.', err);
      }
    });
  }

  /* ---------------------------------------------------------
     Helpers
     --------------------------------------------------------- */
  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function setText(sel, text) {
    var node = SI.$(sel);
    if (node) node.textContent = text;
  }

  function setAttr(sel, attr, value) {
    var node = SI.$(sel);
    if (node) node.setAttribute(attr, value);
  }
})(window, document);
