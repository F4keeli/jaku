/* =========================================================
   Header behaviour, mobile nav, modals, back-to-top
   ========================================================= */
(function (window, document) {
  'use strict';

  var SI = window.SI;

  var TERMS = [
    ['1. Acceptance of Terms', 'By accessing this website, its Discord server, or any Stitchline Interactive service, you agree to these Terms. If you do not agree, you must stop using the services.'],
    ['2. Studio Rules', [
      'Harassment, slurs, threats, sexual content involving minors, and any form of targeted bullying are grounds for immediate removal with no appeal.',
      'Impersonation of studio staff, developers, or moderation is prohibited in every Stitchline space, voice channel, and game.',
      'Nuisance behaviour — mass pings, spam, advertising, raids, or exploiting bugs for personal gain — is not tolerated.',
      'Cheating, unfair automation, and exploiting a released build will result in a permanent ban from all studio properties.'
    ]],
    ['3. Fair and Balanced Play', 'Every competitive game we ship is designed to reward skill. Any player found to be cheating, abusing an exploit, or using third-party modification software will be banned permanently, and the studio reserves the right to revoke in-game progression, purchases, and roles obtained through that account. Bans are reviewed on a case-by-case basis and can be appealed once in writing.'],
    ['4. Purchases, Roles, and Refunds', [
      'All purchases are final once delivered. Digital currency (StitchBlocks), roles, and supporter items are non-refundable except where required by law.',
      'A buyer is fully liable for the Discord account ID supplied at checkout. Entering the wrong ID, or the ID of another person, voids the order and is not eligible for a refund or transfer.',
      'Role purchases are non-transferable and may be revoked if a supporter is found to be in breach of these Terms or the Code of Conduct.'
    ]],
    ['5. Content Ownership', 'All game titles, logos, art, audio, and written material on this site belong to Stitchline Interactive. You may share and stream our games freely with visible credit, but you may not re-upload, resell, or rebrand our assets.'],
    ['6. Liability', 'Stitchline Interactive provides games and services "as is". We are not responsible for third-party platforms, network conditions outside our control, or losses caused by account compromise. Roblox, Discord, YouTube, TikTok, and X each govern their own services under their own terms.'],
    ['7. Changes', 'These Terms may be updated as the studio grows. The date below reflects the current revision, and continued use of the services constitutes acceptance of the updated version.']
  ];

  var PRIVACY = [
    ['1. What We Collect', [
      'Account identifiers and security details: your Discord user ID and username, plus the email address and mobile number you verify for account access and two-factor login.',
      'Usage data: which pages you visit and the number of visits we record for our own statistics.',
      'Discord counts: the public member totals of the servers we are connected to, used only to display the community counter.'
    ]],
    ['2. What We Never Do', 'We do not sell, rent, or trade your data, and we do not run third-party advertising or tracking scripts. Account data is sent only to providers needed for the feature: Discord for OAuth, Roblox for account linking, and Stripe for checkout and saved-payment management.'],
    ['3. Payment Data', 'Stripe processes payments and stores any saved payment methods. Card numbers are never sent to or stored on our servers. We retain order records, the Discord ID, and Stripe customer ID needed to link your orders and open Stripe Billing Portal.'],
    ['4. Legal Disclosure', 'Your data is shared with a third party in exactly one circumstance: a valid, binding legal request from Discord, YouTube, TikTok, or X (X Corp.), or from a law-enforcement or court authority. We will only respond to a request that is properly scoped to the specific account in question, and we will notify you unless prohibited from doing so.'],
    ['5. Retention and Deletion', 'Application and contact submissions are kept only as long as needed to handle the enquiry, then removed. Purchase records are retained for accounting obligations. You may request deletion of your contact or application data at any time by emailing the address on the Contact page.'],
    ['6. Children', 'Our services are not directed at children under 13, and we knowingly do not collect their personal information.']
  ];

  function legalHTML(blocks) {
    return blocks.map(function (block) {
      var head = '<h4>' + block[0] + '</h4>';
      var body = Array.isArray(block[1])
        ? '<ul>' + block[1].map(function (item) { return '<li>' + item + '</li>'; }).join('') + '</ul>'
        : '<p>' + block[1] + '</p>';
      return head + body;
    }).join('') +
      '<p class="modal__updated">Last revised 2026 &middot; Stitchline Interactive</p>';
  }

  SI.legal = { terms: TERMS, privacy: PRIVACY, legalHTML: legalHTML };

  document.addEventListener('DOMContentLoaded', function () {
    var header = SI.$('#header');
    var rail = SI.$('#headerRail');
    var burger = SI.$('#burger');
    var nav = SI.$('#nav');
    var toTop = SI.$('#toTop');

    var accountCsrf = '';
    var accountControl = nav && nav.querySelector('[data-account-control]');
    if (nav && !accountControl) {
      accountControl = nav.querySelector('a[href="/account.html"]');
      if (!accountControl) {
        accountControl = document.createElement('a');
        nav.appendChild(accountControl);
      }
      accountControl.setAttribute('data-account-control', '');
    }

    function renderAccountControl(state) {
      if (!nav || !accountControl) return;
      accountCsrf = state.csrf || '';
      if (state.authenticated && state.discord) {
        var menu = document.createElement('div');
        menu.className = 'account-menu';
        menu.setAttribute('data-account-control', '');
        var username = String(state.discord.username || 'Discord account');
        var id = String(state.discord.id || '');
        var avatar = String(state.discord.avatar_url || '');
        menu.innerHTML =
          '<button class="nav__link account-verify-option account-menu__trigger' +
            (document.body.getAttribute('data-page') === 'account' ? ' is-active' : '') +
            '" type="button" data-account-toggle aria-haspopup="menu" aria-expanded="false">' +
            '<img class="account-menu__avatar" src="' + SI.esc(avatar) + '" alt="">' +
            '<span class="account-menu__username">' + SI.esc(username) + '</span>' +
            '<span class="account-menu__chevron" aria-hidden="true">&#9662;</span></button>' +
          '<div class="account-menu__panel" role="menu" hidden>' +
            '<div class="account-menu__identity"><img src="' + SI.esc(avatar) + '" alt="">' +
              '<span><b>' + SI.esc(username) + '</b><small class="mono">' + SI.esc(id) + '</small></span></div>' +
            '<a role="menuitem" href="/account.html">Dashboard</a>' +
            '<a role="menuitem" href="/account.html#paymentHeading">Saved payment methods</a>' +
            (state.staff ? '<a role="menuitem" href="/staff.html">Staff login</a>' : '') +
            '<button role="menuitem" type="button" data-account-logout>Sign out</button>' +
          '</div>';
        accountControl.replaceWith(menu);
        accountControl = menu;
        return;
      }

      var link = document.createElement('a');
      link.className = 'nav__link account-verify-option' +
        (document.body.getAttribute('data-page') === 'account' ? ' is-active' : '');
      link.setAttribute('data-account-control', '');
      link.textContent = 'Verify';
      var staffReturn = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('staff') === '1';
      link.href = state.configured
        ? '/api/account/connect' + (staffReturn ? '?staff=1' : '')
        : '/account.html';
      accountControl.replaceWith(link);
      accountControl = link;
    }

    function refreshAccountControl() {
      if (!nav || !accountControl) return;
      if (document.body.getAttribute('data-static') === '1') {
        renderAccountControl({ authenticated: false, configured: false });
        return;
      }
      fetch('/api/account/state', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) {
          if (!response.ok) throw new Error('Account service unavailable.');
          return response.json();
        })
        .then(renderAccountControl)
        .catch(function () { renderAccountControl({ authenticated: false, configured: false }); });
    }
    window.refreshStitchlineAccountMenu = refreshAccountControl;
    window.addEventListener('si:account-updated', refreshAccountControl);
    refreshAccountControl();

    document.addEventListener('click', function (event) {
      var toggle = event.target.closest('[data-account-toggle]');
      if (toggle) {
        var panel = toggle.parentNode.querySelector('.account-menu__panel');
        var open = panel.hidden;
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        return;
      }
      document.querySelectorAll('.account-menu__panel:not([hidden])').forEach(function (panel) {
        if (!panel.parentNode.contains(event.target)) {
          panel.hidden = true;
          panel.parentNode.querySelector('[data-account-toggle]').setAttribute('aria-expanded', 'false');
        }
      });
      if (event.target.closest('[data-account-logout]')) {
        fetch('/api/account/logout', {
          method: 'POST', credentials: 'same-origin',
          headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':accountCsrf},
          body: '{}'
        }).then(function (response) {
          if (!response.ok) throw new Error('Sign out failed.');
          window.dispatchEvent(new Event('si:account-updated'));
        }).catch(function () {});
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      document.querySelectorAll('.account-menu__panel:not([hidden])').forEach(function (panel) {
        panel.hidden = true;
        var toggle = panel.parentNode.querySelector('[data-account-toggle]');
        if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
      });
    });

    /* ---- sticky header + scroll progress + parallax ---- */
    /* Cached once. Querying these inside onScroll meant a DOM lookup per
       scroll frame, and reading scrollHeight per frame forced a layout flush
       every frame, which is the classic scroll-jank pattern. Both are now read
       on demand and only re-measured when the viewport actually changes. */
    var hero = SI.$('.hero');
    var heroLogo = SI.$('.hero__logo');
    var scrollMax = 0;

    function measure() {
      scrollMax = document.documentElement.scrollHeight - window.innerHeight;
    }
    measure();
    window.addEventListener('resize', measure, { passive: true });
    window.addEventListener('load', measure);

    function onScroll() {
      var y = window.scrollY || document.documentElement.scrollTop;
      if (header) header.classList.toggle('is-stuck', y > 24);
      if (toTop) toTop.classList.toggle('is-on', y > 600);
      if (rail) {
        rail.style.width = (scrollMax > 0 ? Math.min(100, (y / scrollMax) * 100) : 0) + '%';
      }
      if (hero && heroLogo && !SI.reduced && y < window.innerHeight * 1.2) {
        heroLogo.style.setProperty('--parallax', (y * 0.16).toFixed(1) + 'px');
      }
    }
    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () { onScroll(); ticking = false; });
    }, { passive: true });
    onScroll();

    if (toTop) {
      toTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: SI.reduced ? 'auto' : 'smooth' });
      });
    }

    /* ---- mobile nav ---- */
    if (burger && nav) {
      burger.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        burger.setAttribute('aria-expanded', String(open));
        burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        document.body.classList.toggle('is-locked', open);
      });
      nav.addEventListener('click', function (event) {
        if (event.target.closest('.nav__link') && !event.target.closest('[data-account-toggle]')) {
          nav.classList.remove('is-open');
          burger.setAttribute('aria-expanded', 'false');
          document.body.classList.remove('is-locked');
        }
      });
    }

    /* ---- modals ---- */
    var modal = SI.$('#modal');
    var modalTitle = SI.$('#modalTitle');
    var modalBody = SI.$('#modalBody');
    var lastFocus = null;

    function openModal(title, html) {
      if (!modal) return;
      lastFocus = document.activeElement;
      modalTitle.textContent = title;
      modalBody.innerHTML = html;
      modal.hidden = false;
      document.body.classList.add('is-locked');
      var close = modal.querySelector('.modal__close');
      if (close) close.focus();
    }

    function closeModal() {
      if (!modal || modal.hidden) return;
      modal.hidden = true;
      document.body.classList.remove('is-locked');
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-modal]');
      if (trigger) {
        var key = trigger.getAttribute('data-modal');
        if (key === 'terms') openModal('Studio Terms', legalHTML(TERMS));
        if (key === 'privacy') openModal('Privacy Policy', legalHTML(PRIVACY));
        return;
      }
      if (event.target.closest('[data-close]')) closeModal();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeModal();
        if (burger && nav && nav.classList.contains('is-open')) burger.click();
      }
    });

    /* ---- socials are rendered by home.js from the content store ---- */
  });
})(window, document);
