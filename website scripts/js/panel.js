/* =========================================================
   Staff panel: four-step sign-in + the authenticated shell
   ========================================================= */
(function (window, document) {
  'use strict';

  var SI = window.SI;
  var API = '/staff/api';
  var PREVIEW = false;
  var PREVIEW_SITE = {};
  var PREVIEW_APPLICATIONS = [];
  var PREVIEW_STORE = {};

  var state = {
    csrf: '',
    staff: null,
    registry: null,
    pending: '',
    step: 1,
    route: 'dashboard',
    mail: null,
    discounts: [],
    /* The descriptor of whatever section is on screen. The generic record views
       build their tables and their row-edit forms from it, and the delegated
       click handlers read it to know what a delete refers to. */
    view: null,
    /* Cached from whichever view last rendered them, so the editors in
       Web Configuration and Store Management open against the same data that
       is on screen instead of re-fetching it. */
    featured: {},
    gameStatuses: [],
    webConfigGames: []
  };

  var el = {};
  var codeTimer = null;
  var resendTimer = null;
  var passkeyFallback = null;

  /* ---------------------------------------------------------------- boot */

  document.addEventListener('DOMContentLoaded', function () {
    cache();
    wire();
    boot();
  });

  function cache() {
    ['wizard', 'loginView', 'appView', 'steps', 'side', 'canvas', 'modal', 'modalTitle',
     'modalBody', 'whoName', 'whoRank', 'whoPfp', 'botPill', 'codeTimer', 'resendNote'
    ].forEach(function (id) { el[id] = document.getElementById(id); });
  }

  function boot() {
    /* Two ways to arrive here without a panel behind you.

       The first is the static build, which says so in the markup with
       data-static. The second is subtler and cost the studio several sessions:
       the *live* staff.html opened through an editor's tunnel or file server,
       which serves the page perfectly and 404s every API call. Nothing in the
       markup says static, so the guard above did not fire, the sign-in form
       rendered, and it failed on its first call with a message about HTML.

       So this probes rather than assumes. If the panel's own state call does not
       come back as JSON, the page is not being served by the site and it says
       so in one line, with the address the panel is actually on. A form that
       cannot work should not be offered. */
    var resetToken = new URLSearchParams(window.location.search).get('reset') || '';
    if (resetToken) showReset(resetToken);
    if (resetToken || (SI.api && SI.api.static)) {
      if (resetToken) return;
      startPreview();
      return;
    }
    api('GET', 'auth/state').then(function (data) {
      state.mail = data.mail;
      if (data.passkeys_supported === false) {
        var passkeyButton = document.getElementById('btnPasskeySignin');
        if (passkeyButton) passkeyButton.hidden = true;
      }
      if (data.authenticated) {
        state.csrf = data.csrf;
        state.staff = data.staff;
        return openPanel();
      }
      if (data.account_required) {
        showError('errLogin', 'Verify your linked Discord and email at /account.html before using staff tools.');
      }
      return null;
    }).catch(function (err) {
      if (err && err.code === 'not_json') {
        if (/^(localhost|127\.0\.0\.1|\[::1\])$/i.test(window.location.hostname)) {
          startPreview();
        } else {
          noBackend(true);
        }
        return;
      }
      showError('errLogin', err);
    });
  }

  function startPreview() {
    PREVIEW = true;
    Promise.all([
      SI.api.get('/api/site').catch(function () { return {}; }),
      SI.api.get('/api/applications').catch(function () { return { roles: [] }; }),
      SI.api.get('/api/store').catch(function () { return {}; })
    ]).then(function (items) {
      PREVIEW_SITE = items[0] || {};
      PREVIEW_APPLICATIONS = items[1] || { roles: [] };
      PREVIEW_STORE = items[2] || {};
      state.staff = {
        username: 'Preview',
        title: 'Read-only',
        tier: 'Leadership',
        rank_label: 'Read-only preview',
        pfp: ''
      };
      var logout = document.getElementById('btnLogout');
      if (logout) logout.hidden = true;
      openPanel();
    });
  }

  function previewRegistry() {
    var sections = [
      { id: 'dashboard', label: 'Dashboard', subs: [] },
      { id: 'tickets', label: 'Tickets', subs: [{ id: 'ticket-config', label: 'Ticket Configuration' }] },
      { id: 'community', label: 'Community', subs: [{ id: 'giveaways', label: 'Giveaway Hosting' }, { id: 'events', label: 'Event Hosting' }] },
      { id: 'logs', label: 'Logs', subs: [{ id: 'case-logs', label: 'Case Logs' }, { id: 'ticket-logs', label: 'Ticket Logs' }, { id: 'message-logs', label: 'Message Logs' }, { id: 'server-logs', label: 'Server Logs' }] },
      { id: 'staff', label: 'Staff', subs: [{ id: 'logins-management', label: 'Logins Management' }, { id: 'staff-announcements', label: 'Staff Announcements' }, { id: 'moderation-actions', label: 'Moderation Actions' }, { id: 'applications', label: 'Applications' }, { id: 'contact', label: 'Suggestion Box' }] },
      { id: 'ai-chat', label: 'AI Chat', subs: [] },
      { id: 'development', label: 'Development', subs: [{ id: 'dev-announcements', label: 'Development Announcements' }, { id: 'dev-tasks', label: 'Development Tasks' }, { id: 'work-submit', label: 'Work Submit' }, { id: 'testers-posting', label: 'Testers Posting Area' }] },
      { id: 'leadership', label: 'Leadership', subs: [{ id: 'web-config', label: 'Web Configuration' }, { id: 'web-announcements', label: 'Web Announcements' }, { id: 'staff-config', label: 'Staff Configuration' }, { id: 'payments', label: 'Payments' }, { id: 'store-management', label: 'Store Management' }] }
    ];
    var routes = {};
    sections.forEach(function (section) {
      routes[section.id] = { id: section.id, label: section.label, parent: null };
      section.subs.forEach(function (sub) {
        routes[sub.id] = { id: sub.id, label: sub.label, parent: section.id };
      });
    });
    return { sections: sections, routes: routes, tiers: ['Leadership', 'Management', 'Staff', 'Development', 'Testing'],
      bot: { bots: [], online: 0, total: 2, all_online: false, reason: 'Read-only preview' } };
  }

  function previewRoute(route) {
    var site = PREVIEW_SITE || {};
    if (route === 'dashboard') return {
      route: route, status: 'ok', staff: 0, active_sessions: 0, games: (site.games || []).length,
      site_visits: 0, announcements: (site.announcements || []).length,
      mail: { configured: false }, ai: { configured: false }, chat: { threads: 0 },
      bot: { bots: [], online: 0, total: 2, all_online: false }
    };
    if (route === 'community') return { route: route, status: 'ok', view: 'overview', cards: [
      { label: 'Community members', value: (site.community || {}).total || 0 },
      { label: 'Open giveaways', value: 0 }, { label: 'Events', value: 0 }
    ], links: [{id:'giveaways',label:'Giveaways'},{id:'events',label:'Events'}] };
    if (route === 'logs') return { route: route, status: 'ok', view: 'overview', cards: [
      { label: 'Cases', value: 0 }, { label: 'Messages logged', value: 0 }, { label: 'Server events', value: 0 }
    ], links: [{id:'case-logs',label:'Cases'},{id:'message-logs',label:'Messages'},{id:'server-logs',label:'Server events'}] };
    if (route === 'staff') return { route: route, status: 'ok', view: 'overview', cards: [
      { label: 'Staff logins', value: 0 }, { label: 'Suggestions', value: 0 }, { label: 'Applications', value: 0 }
    ], links: [{id:'logins-management',label:'Logins'},{id:'contact',label:'Suggestions'},{id:'applications',label:'Applications'}] };
    if (route === 'development') return { route: route, status: 'ok', view: 'overview', cards: [
      { label: 'Tasks', value: 0 }, { label: 'Submissions', value: 0 }
    ], links: [{id:'dev-tasks',label:'Tasks'},{id:'work-submit',label:'Work submissions'},{id:'testers-posting',label:'Tester posts'}] };
    if (route === 'leadership') return { route: route, status: 'ok', view: 'overview', cards: [
      { label: 'Games', value: (site.games || []).length }, { label: 'Site visits', value: 0 }, { label: 'Announcements', value: (site.announcements || []).length }
    ], links: [{id:'web-config',label:'Web configuration'},{id:'web-announcements',label:'Announcements'},{id:'staff-config',label:'Roster'}] };
    if (route === 'web-config') return Object.assign({
      route: route, status: 'ok', statuses: ['Concept','In Production','Playtesting','Released','Paused'], site_visits: 0
    }, site);
    if (route === 'web-announcements') return {
      route: route, status: 'ok', announcements: (site.announcements || []).filter(function (a) { return !a.audience || a.audience === 'public'; }),
      kind: 'public', label: 'Web', ttl_days: 14, audiences: [{id:'public',label:'Web'},{id:'staff',label:'Staff'},{id:'dev',label:'Development'}]
    };
    if (route === 'staff-config') return { route: route, status: 'ok', team: site.team || {}, tiers: ['Leadership','Management','Staff','Development','Testing'], hint: 'Read-only preview.' };
    if (route === 'applications') return { route: route, status: 'ok', roles: PREVIEW_APPLICATIONS.roles || [], applications: [] };
    if (route === 'contact') return { route: route, status: 'ok', messages: [], address: (site.contact || {}).inbox || '', pending: 0, approved: 0, denied: 0, can_decide: false };
    if (route === 'logins-management') return { route: route, status: 'ok', staff: [], can_manage: false, bot: { online: false } };
    if (route === 'store-management') return { route: route, status: 'ok', currency: site.store || {}, discounts: [], games: site.games || [], economy: {} };
    if (route === 'payments') return { route: route, status: 'ok', orders: [], gross: 0, providers: { stripe: false } };
    if (route === 'ai-chat') return { route: route, status: 'ok', provider: 'Thunderbolt', embedded: true, provider_status: { configured: true }, messages: [] };
    if (['web-announcements','staff-announcements','dev-announcements'].indexOf(route) >= 0) return { route: route, status: 'ok', view: 'announcements', announcements: [], kind: 'public', label: 'Web', ttl_days: 14, audiences: [] };
    if (['bot-config','ticket-config','dev-config'].indexOf(route) >= 0) return { route: route, status: 'ok', view: 'config', label: 'Configuration', fields: [], settings: {}, hint: 'Read-only preview.' };
    return { route: route, status: 'ok', view: 'collection', kind: route, label: route.replace(/-/g, ' '), writable: false, records: [], columns: [], hint: 'Read-only preview. Live records appear after the bot service is connected.' };
  }

  function previewApi(method, path) {
    if (method !== 'GET') {
      var readOnly = new Error('Read-only preview: changes are not saved. Use the configured Spaceship site for live edits.');
      readOnly.code = 'preview_read_only';
      return Promise.reject(readOnly);
    }
    if (path === 'panel/registry') return Promise.resolve(previewRegistry());
    var route = path.match(/^panel\/route\/([^?]+)/);
    if (route) return Promise.resolve(previewRoute(decodeURIComponent(route[1])));
    var empty = new Error('This preview route is not available.');
    empty.code = 'preview_route';
    return Promise.reject(empty);
  }

  /* ---------------------------------------------------------------- api */

  /* The server always answers with JSON, including for errors.

     The non-JSON case used to be swallowed into an empty object, which turned
     every failure into "Request failed (404)" - a message that cannot be acted
     on, and that hid the real situation for as long as it took to notice that
     the browser had been pointed at an editor's file server instead of the app.
     It now says which call, which status, and what came back instead, exactly
     as fx.js does on the public side. */
  function api(method, path, body) {
    if (PREVIEW) return previewApi(method, path, body);
    var opts = {
      method: method,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    if (state.csrf && method !== 'GET') opts.headers['X-CSRF-Token'] = state.csrf;

    return fetch(API + '/' + path, opts).then(function (res) {
      return res.text().then(function (body) {
        var data = null;
        var parsed = true;
        if (body) {
          try { data = JSON.parse(body); } catch (err) { parsed = false; }
        }
        if (!parsed) {
          var html = /^\s*</.test(body);
          var err = new Error(
            'The server sent ' + (html ? 'HTML' : 'plain text') + ' instead of data for ' +
            API + '/' + path + ' (HTTP ' + res.status + ').' +
            (html
              ? ' That usually means something else is answering on this port - an ' +
                'editor\'s file server, for instance - rather than the panel.'
              : ''));
          err.code = 'not_json';
          err.status = res.status;
          throw err;
        }
        data = data || {};
        if (!res.ok) {
          var bad = new Error(data.message || ('Request failed (' + res.status + ')'));
          bad.code = data.error;
          bad.status = res.status;
          throw bad;
        }
        return data;
      });
    }, function () {
      var offline = new Error(
        'Could not reach ' + API + '. If you opened the panel by hand rather than ' +
        'through the site, the address has to include /staff.');
      offline.code = 'network';
      throw offline;
    });
  }

  function showError(id, message) {
    var node = document.getElementById(id);
    if (!node) { if (message) console.warn(message); return; }
    node.textContent = message || '';
    node.hidden = !message;
  }

  function clearErrors() {
    ['errLogin', 'errEmail', 'errCode', 'errPasskey'].forEach(function (id) { showError(id, ''); });
  }

  /* ---------------------------------------------------------------- steps */

  function goto(step) {
    state.step = step;
    SI.$$('[data-step-body]').forEach(function (node) {
      node.classList.toggle('is-on', Number(node.getAttribute('data-step-body')) === step);
    });
    SI.$$('.steps__item').forEach(function (node) {
      var n = Number(node.getAttribute('data-step'));
      node.classList.toggle('is-active', n === step);
      node.classList.toggle('is-done', n < step);
    });
    if (step === 2) {
      var input = document.getElementById('emailInput');
      if (input) input.focus();
    }
    if (step === 3) {
      var code = document.getElementById('codeInput');
      if (code) code.focus();
    }
  }

  /* ---------------------------------------------------------------- login */

  function wire() {
    document.getElementById('formLogin').addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors();
      var form = event.currentTarget;
      var username = form.username.value.trim();
      var password = form.password.value;
      if (!username || !password) {
        showError('errLogin', 'Enter your username and password.');
        return;
      }
      api('POST', 'auth/login', { username: username, password: password }).then(function (data) {
        state.pending = data.pending;
        document.getElementById('whoSignedIn').textContent = data.hint || username;
        form.password.value = '';
        goto(2);
      }).catch(function (err) {
        showError('errLogin', err.message);
        if (err.code === 'locked') showLock(err.message);
      });
    });

    document.getElementById('formEmail').addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors();
      var email = document.getElementById('emailInput').value.trim();
      if (!email) { showError('errEmail', 'Enter the full email address on your account.'); return; }
      api('POST', 'auth/confirm-email', { pending: state.pending, email: email })
        .then(function () { return api('POST', 'auth/request-code', { pending: state.pending }); })
        .then(function (data) {
          document.getElementById('codeHint').textContent =
            'We sent a six-digit code to ' + email + '. It expires in 10 minutes.';
          beginCountdown(data.expires_in || 600);
          beginResend(data.resend_in || 45);
          goto(3);
        })
        .catch(function (err) { showError('errEmail', err.message); });
    });

    document.getElementById('formCode').addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors();
      var code = document.getElementById('codeInput').value.replace(/\D/g, '');
      if (code.length !== 6) { showError('errCode', 'Enter all six digits.'); return; }
      api('POST', 'auth/verify-code', { pending: state.pending, code: code, remember: false })
        .then(function (data) {
          state.csrf = data.csrf;
          state.staff = data.staff;
          if (data.account_required) {
            window.location.href = '/account.html#staff=1';
            return;
          }
          if (data.passkey_prompt) { goto(4); } else { openPanel(); }
        })
        .catch(function (err) { showError('errCode', err.message); });
    });

    /* Stepping back leaves the code step's countdown and resend timer running
       against nodes that are no longer visible, and the resend button stays
       disabled behind a hidden step. Both are stopped on the way out. */
    function leaveCodeStep(step) {
      clearErrors();
      stopStepTimers();
      goto(step);
    }

    document.getElementById('btnBack1').addEventListener('click', function () { leaveCodeStep(1); });
    document.getElementById('btnBack2').addEventListener('click', function () { leaveCodeStep(2); });
    document.getElementById('btnResend').addEventListener('click', resend);
    document.getElementById('btnSkip4').addEventListener('click', openPanel);
    document.getElementById('btnFinish4').addEventListener('click', finishStep4);
    document.getElementById('btnPasskeySignin').addEventListener('click', passkeySignin);
    document.getElementById('btnResetStart').addEventListener('click', function () { showReset(''); });
    document.getElementById('btnResetCancel').addEventListener('click', hideReset);
    document.getElementById('formReset').addEventListener('submit', submitReset);
    document.getElementById('btnLogout').addEventListener('click', signOut);
    document.getElementById('btnMenu').addEventListener('click', function () {
      el.side.classList.toggle('is-open');
    });

    var search = document.getElementById('panelSearch');
    if (search) {
      search.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        var needle = search.value.trim().toLowerCase();
        if (!needle) return;
        var match = null;
        SI.$$('.side__sub', el.side).some(function (node) {
          if (node.textContent.toLowerCase().indexOf(needle) === -1) return false;
          match = node.getAttribute('data-route');
          return true;
        });
        if (match) { navigate(match); search.value = ''; }
      });
    }

    document.addEventListener('click', function (event) {
      if (event.target.closest('[data-close]')) closeModal();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeModal();
    });
  }

  function showReset(token) {
    var form = document.getElementById('formLogin');
    var passkey = document.getElementById('btnPasskeySignin');
    var reset = document.getElementById('resetBox');
    if (form) form.hidden = true;
    if (passkey) passkey.hidden = true;
    if (reset) reset.hidden = false;
    if (token) {
      reset.setAttribute('data-token', token);
      document.getElementById('resetEmail').hidden = true;
      document.getElementById('resetEmail').value = 'Reset link verified';
      document.querySelector('#formReset .wizard__actions button[type="submit"]').textContent = 'Change password';
    }
  }

  function hideReset() {
    var form = document.getElementById('formLogin');
    var passkey = document.getElementById('btnPasskeySignin');
    var reset = document.getElementById('resetBox');
    if (form) form.hidden = false;
    if (passkey) passkey.hidden = false;
    if (reset) reset.hidden = true;
  }

  function submitReset(event) {
    event.preventDefault();
    showError('errReset', '');
    var box = document.getElementById('resetBox');
    var token = box.getAttribute('data-token') || '';
    var first = document.getElementById('resetPassword').value;
    var again = document.getElementById('resetPasswordAgain').value;
    if (token && first !== again) { showError('errReset', 'The passwords do not match.'); return; }
    if (token && first.length < 12) { showError('errReset', 'Use a password of at least 12 characters.'); return; }
    var request = token
      ? api('POST', 'auth/password-reset/complete', { token: token, password: first })
      : api('POST', 'auth/password-reset/request', { email: document.getElementById('resetEmail').value.trim() });
    request.then(function (data) {
      showError('errReset', data.message || 'Check your email.');
      if (token) window.setTimeout(function () { window.location.href = '/staff'; }, 1800);
    }).catch(function (err) { showError('errReset', err.message); });
  }

  function showLock(message) {
    var node = document.getElementById('lockLogin');
    if (!node) return;
    node.textContent = message;
    node.hidden = false;
  }

  /* ---------------------------------------------------------------- timers */

  function stopStepTimers() {
    window.clearInterval(codeTimer);
    window.clearInterval(resendTimer);
    codeTimer = resendTimer = null;
    /* The resend button's disabled state is owned by the resend timer, so
       leaving the step has to hand it back or it comes back disabled. */
    var button = document.getElementById('btnResend');
    if (button) button.disabled = false;
  }

  function beginCountdown(seconds) {
    var end = Date.now() + seconds * 1000;
    var meter = document.getElementById('codeMeter');
    window.clearInterval(codeTimer);
    codeTimer = window.setInterval(function () {
      var left = Math.max(0, Math.round((end - Date.now()) / 1000));
      var mins = Math.floor(left / 60);
      var secs = left % 60;
      /* Checked before the write, not after: a throw here happens first and
         would leave the interval re-raising every second for the life of the
         page, because the clear at the bottom would never be reached. */
      if (el.codeTimer) el.codeTimer.textContent = 'Expires in ' + mins + ':' + (secs < 10 ? '0' + secs : secs);
      if (meter) meter.style.width = (left * 100 / seconds).toFixed(1) + '%';
      if (left <= 0) {
        window.clearInterval(codeTimer);
        codeTimer = null;
        if (el.codeTimer) el.codeTimer.textContent = 'Code expired — request a new one.';
      }
    }, 1000);
  }

  function beginResend(seconds) {
    var end = Date.now() + seconds * 1000;
    var button = document.getElementById('btnResend');
    window.clearInterval(resendTimer);
    resendTimer = window.setInterval(function () {
      var left = Math.max(0, Math.round((end - Date.now()) / 1000));
      if (button) button.disabled = left > 0;
      if (el.resendNote) el.resendNote.textContent = left > 0 ? ' (' + left + 's)' : '';
      if (left <= 0) {
        window.clearInterval(resendTimer);
        resendTimer = null;
      }
    }, 500);
  }

  function resend() {
    showError('errCode', '');
    api('POST', 'auth/request-code', { pending: state.pending })
      .then(function (data) {
        beginCountdown(data.expires_in || 600);
        beginResend(data.resend_in || 45);
        document.getElementById('codeInput').value = '';
        document.getElementById('codeInput').focus();
      })
      .catch(function (err) { showError('errCode', err.message); });
  }

  /* ---------------------------------------------------------------- passkey */

  function b64uToBuf(value) {
    var padded = value.replace(/-/g, '+').replace(/_/g, '/');
    while (padded.length % 4) padded += '=';
    var raw = window.atob(padded);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out.buffer;
  }

  function bufToB64u(buffer) {
    var bytes = new Uint8Array(buffer);
    var raw = '';
    for (var i = 0; i < bytes.length; i++) raw += String.fromCharCode(bytes[i]);
    return window.btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function passkeySupported() {
    return window.PublicKeyCredential && window.navigator.credentials &&
      window.isSecureContext !== false;
  }

  function passkeySignin() {
    showError('errLogin', '');
    var username = document.getElementById('formLogin').username.value.trim();
    if (!username) {
      showError('errLogin', 'Type your username first, then choose "Use a passkey instead".');
      return;
    }
    if (!passkeySupported()) {
      showError('errLogin', 'This browser cannot create passkeys here. Use https, or localhost.');
      return;
    }
    var options = null;
    api('POST', 'auth/passkey/options', { username: username })
      .then(function (opts) {
        options = opts;
        return navigator.credentials.get({
          publicKey: {
            challenge: b64uToBuf(opts.challenge),
            rpId: opts.rp.id,
            userVerification: 'preferred',
            timeout: opts.timeout
          }
        });
      })
      .then(function (assertion) {
        if (!assertion) throw new Error('Passkey prompt was cancelled.');
        var response = assertion.response;
        return api('POST', 'auth/passkey/verify', {
          username: username,
          remember: document.getElementById('chkRemember').checked,
          response: {
            challenge: options.challenge,
            credentialId: bufToB64u(assertion.rawId),
            clientDataJSON: bufToB64u(response.clientDataJSON),
            authenticatorData: bufToB64u(response.authenticatorData),
            signature: bufToB64u(response.signature),
            userHandle: response.userHandle ? bufToB64u(response.userHandle) : ''
          }
        });
      })
      .then(function (data) {
        state.csrf = data.csrf;
        state.staff = data.staff;
        if (data.account_required) {
          window.location.href = '/account.html#staff=1';
          return;
        }
        openPanel();
      })
      .catch(function (err) { showError('errLogin', err.message); });
  }

  function finishStep4() {
    var remember = document.getElementById('chkRemember').checked;
    var wantPasskey = document.getElementById('chkPasskey').checked;

    // applyRemember() revokes the session it was called with and issues a new
    // cookie. It must therefore settle before any panel request is made, or
    // those requests go out with the revoked token and come back 401.
    if (!wantPasskey) {
      applyRemember(remember).then(openPanel);
      return;
    }
    if (!passkeySupported()) {
      showError('errPasskey', 'This browser cannot create passkeys here. Continuing without one.');
      applyRemember(remember).then(openPanel);
      return;
    }

    showError('errPasskey', '');
    var options = null;
    api('POST', 'auth/passkey/register/options', {}).then(function (opts) {
      options = opts;
      return navigator.credentials.create({
        publicKey: {
          challenge: b64uToBuf(opts.challenge),
          rp: { id: opts.rp.id, name: opts.rp.name },
          user: {
            id: b64uToBuf(opts.user.id),
            name: opts.user.name,
            displayName: opts.user.displayName
          },
          pubKeyCredParams: opts.pubKeyCredParams,
          timeout: opts.timeout,
          attestation: opts.attestation,
          authenticatorSelection: opts.authenticatorSelection
        }
      });
    }).then(function (credential) {
      if (!credential) throw new Error('Passkey creation was cancelled.');
      var response = credential.response;
      return api('POST', 'auth/passkey/register/verify', {
        response: {
          challenge: options.challenge,
          credentialId: bufToB64u(credential.rawId),
          clientDataJSON: bufToB64u(response.clientDataJSON),
          attestationObject: bufToB64u(response.attestationObject)
        }
      });
    }).then(function () {
      applyRemember(remember).then(openPanel);
    }).catch(function (err) {
      showError('errPasskey', 'Passkey not created: ' + err.message);
      /* A failed passkey is not a failed sign-in: the code was already accepted,
         so the operator is shown the panel after reading the message. Tracked so
         a second attempt inside that window cannot run openPanel() twice, which
         fetched the registry and rendered the section twice over. */
      window.clearTimeout(passkeyFallback);
      passkeyFallback = window.setTimeout(function () { applyRemember(remember).then(openPanel); }, 2200);
    });
  }

  function applyRemember(remember) {
    // The verification code is single-use, so the session lifetime is changed
    // through its own endpoint rather than by replaying step 3.
    return api('POST', 'auth/session/refresh', { remember: remember })
      .then(function (data) { state.csrf = data.csrf; })
      .catch(function () { /* the 8h session already issued stays valid */ });
  }

  function signOut() {
    api('POST', 'auth/logout', {}).catch(function () { return null; }).then(function () {
      window.location.reload();
    });
  }

  /* ---------------------------------------------------------------- panel */

  function openPanel() {
    stopStepTimers();
    window.clearTimeout(passkeyFallback);
    el.loginView.style.display = 'none';
    // #main keeps .auth for the login screen, so drop that page-level padding
    // and centring before the app grid takes over.
    var main = document.getElementById('main');
    if (main) main.classList.add('is-app');
    el.appView.classList.add('is-on');
    paintIdentity();
    return api('GET', 'panel/registry').then(function (data) {
      state.registry = data;
      paintBot(data.bot);
      buildSidebar(data);
      navigate(state.route);
      return null;
    }).catch(function (err) {
      if (err.status === 401) { window.location.reload(); return null; }
      el.canvas.innerHTML = '<div class="needs-bot"><b>Panel unavailable</b><span>' +
        esc(err.message) + '</span></div>';
      return null;
    });
  }

  function paintIdentity() {
    if (!state.staff) return;
    el.whoName.textContent = state.staff.username;
    el.whoRank.textContent = state.staff.rank_label || state.staff.tier;
    el.whoPfp.innerHTML = state.staff.pfp
      ? '<img src="' + esc(state.staff.pfp) + '" alt="">'
      : (state.staff.username || '?').slice(0, 2).toUpperCase();
  }

  function paintBot(bot) {
    if (PREVIEW) {
      el.botPill.textContent = 'PREVIEW · READ ONLY';
      el.botPill.className = 'pill pill--warn';
      el.botPill.title = 'Layout preview only. No bot or backend is connected.';
      return;
    }
    if (!bot) return;
    var online = bot.online || 0;
    var total = bot.total || (bot.bots ? bot.bots.length : 0);
    el.botPill.textContent = online + '/' + total + ' bots';
    el.botPill.className = 'pill ' + (bot.all_online ? 'pill--live' : 'pill--off');
    el.botPill.title = (bot.bots || []).map(function (b) {
      return b.label + ': ' + (b.online ? 'online' : 'offline');
    }).join('\n') || (bot.reason || '');
  }

  function buildSidebar(data) {
    el.side.innerHTML = '';
    data.sections.forEach(function (section) {
      var group = document.createElement('div');
      group.className = 'side__group';

      var top = document.createElement('button');
      top.className = 'side__top';
      top.type = 'button';
      top.setAttribute('data-route', section.id);
      top.innerHTML =
        '<span class="side__dot' + (section.bot ? '' : ' side__dot--off') + '"></span>' +
        esc(section.label);
      top.addEventListener('click', function () { navigate(section.id); });
      group.appendChild(top);

      // Subsections are always listed. They are navigation, not disclosure
      // widgets, so nothing is hidden behind a caret.
      if (section.subs.length) {
        var subs = document.createElement('div');
        subs.className = 'side__subs';
        section.subs.forEach(function (sub) {
          var link = document.createElement('button');
          link.className = 'side__sub';
          link.type = 'button';
          link.setAttribute('data-route', sub.id);
          var unread = sub.unread || 0;
          link.innerHTML = esc(sub.label) +
            (sub.bot ? '<span class="side__tag">bot</span>' : '') +
            (unread ? '<span class="side__tag side__tag--new">' + unread + '</span>' : '');
          link.addEventListener('click', function () { navigate(sub.id); });
          subs.appendChild(link);
        });
        group.appendChild(subs);
      }
      el.side.appendChild(group);
    });
  }

  /* Monotonic counter for navigate(), so a slow response from an earlier
     navigation cannot repaint over a newer one. */
  var navSeq = 0;

  function navigate(routeId) {
    if (state.route === 'ai-chat' && routeId !== 'ai-chat' && window.Thunderbolt && window.Thunderbolt.close) {
      window.Thunderbolt.close();
    }
    state.route = routeId;
    SI.$$('.side__sub', el.side).forEach(function (node) {
      node.classList.toggle('is-on', node.getAttribute('data-route') === routeId);
    });
    SI.$$('.side__top', el.side).forEach(function (node) {
      node.classList.remove('is-on');
    });
    el.side.classList.remove('is-open');

    /* The sidebar and the content are two separate scrollers, which brings back
       a problem a single scrolling page did not have: a short section opened
       after a long one kept the old scroll position and appeared to start
       halfway down, and in a sidebar of thirty-six destinations the section you
       just opened is often somewhere off-screen. Both are put back in view. */
    if (el.canvas) el.canvas.scrollTop = 0;
    var active = el.side.querySelector('.side__sub.is-on') || el.side.querySelector('.side__top');
    if (active && active.scrollIntoView) {
      // "nearest", not "center": the sidebar should not lurch when the item is
      // already on screen, and nothing else scrolls, so this cannot drag the
      // document with it.
      active.scrollIntoView({ block: 'nearest' });
    }

    el.canvas.innerHTML = '<p class="crumbs">Loading&hellip;</p>';
    /* Navigation is not strictly ordered: two rapid clicks can land their
       responses out of sequence, and the slower one would otherwise repaint
       the canvas with the section the operator already left. The sequence
       number is checked on arrival so only the newest navigation paints. */
    var navigation = ++navSeq;
    api('GET', 'panel/route/' + encodeURIComponent(routeId))
      .then(function (data) {
        if (navigation !== navSeq) return;
        render(routeId, data);
      })
      .catch(function (err) {
        if (navigation !== navSeq) return;
        /* The poll lives on the chat timer, not on this render, so a failed
           navigation has to stop it explicitly. Without this the panel kept
           polling a thread that is no longer on screen, once a second, for the
           rest of the shift. */
        if (routeId !== 'chat') stopChatPoll();
        el.canvas.innerHTML = '<p class="crumbs">Error</p><h2 class="page__title">Could not load</h2>' +
          '<p class="page__desc">' + esc(err.message) + '</p>';
      });
  }

  /* ---------------------------------------------------------------- render */

  function render(routeId, data) {
    var meta = (state.registry && state.registry.routes[routeId]) || {};
    var parent = meta.parent
      ? (state.registry.routes[meta.parent] || {}).label
      : null;
    var head =
      '<p class="crumbs">' + (parent ? esc(parent) + ' <span>/</span> ' : '') +
      esc(meta.label || routeId) + '</p>' +
      '<h2 class="page__title">' + esc(meta.label || routeId) + '</h2>';

    /* Backstops. Every route in the sidebar answers with data, so these two are
       no longer reachable from the navigation; they are kept so that a route
       added to the sidebar without a view fails as a visible, explicable
       failure instead of a blank page. */
    if (data.status === 'needs_bot') {
      el.canvas.innerHTML = head + needsBot(data);
      var reload = document.getElementById('btnReloadRoute');
      if (reload) reload.addEventListener('click', function () { navigate(routeId); });
      return;
    }
    if (data.status === 'not_implemented') {
      el.canvas.innerHTML = head +
        '<div class="needs-bot"><span class="needs-bot__code">NO VIEW</span>' +
        '<b>This section has no view wired to it</b><span>' + esc(data.hint || '') + '</span></div>';
      return;
    }

    /* The view is chosen by what the server says the section is, not by its id.
       One descriptor, one renderer: the twenty-four Discord-backed sections
       differ only in the columns and fields they declare. */
    var bespoke = {
      dashboard: viewDashboard,
      chat: viewChat,
      'ai-chat': viewAiChat,
      'logins-management': viewStaff,
      'staff-requests': viewStaff,
      'web-config': viewWebConfig,
      'store-management': viewStore,
      applications: viewApplications,
      contact: viewContactInbox,
      'staff-config': viewStaffConfig,
      payments: viewPayments
    };
    var generic = {
      collection: viewCollection,
      overview: viewOverview,
      config: viewConfig,
      announcements: viewAnnouncements
    };
    /* The generic record sections need the descriptor to build a row's edit
       form, and the click handlers below need it to know what to delete, so it
       is held for the current route rather than threaded through. */
    state.view = data;
    var previewNotice = PREVIEW
      ? '<div class="notice" role="status">Read-only preview. Changes are not saved.</div>'
      : '';
    el.canvas.innerHTML = head + previewNotice + (
      (data.view && generic[data.view])
        ? generic[data.view](data)
        : (bespoke[routeId] || (routeId === 'web-announcements' ? viewAnnouncements : viewGeneric))(data)
    );

    if (routeId === 'chat') {
      wireChat(data);
    } else {
      chat.thread = null;
      if (chat.poll) { window.clearInterval(chat.poll); chat.poll = null; }
    }
    if (routeId === 'ai-chat') wireAiChat(data);
  }

  function needsBot(data) {
    var bot = data.bot || {};
    return '<div class="needs-bot">' +
      '<span class="needs-bot__code">NEEDS BOT</span>' +
      '<b>The combined bot is not connected</b>' +
      '<span>' + esc(bot.reason || 'This section needs a live bot connection.') + '</span>' +
      (bot.last_seen ? '<span>Last seen ' + esc(new Date(bot.last_seen * 1000).toLocaleString()) + '</span>' : '') +
      '<div class="table__actions" style="justify-content:center;margin-top:.4rem">' +
        '<button class="mini" id="btnReloadRoute">Check again</button></div>' +
      '</div>';
  }

  function viewGeneric(data) {
    return '<div class="empty-note">' +
      '<b>' + esc(data.label || 'Section') + '</b>' +
      '<span>No data source is connected to this section yet.</span></div>';
  }

  /* ------------------------------------------------ generic record sections */

  /* The twenty-four Discord-backed sections are all the same screen: a table
     over one collection, described by the server. Everything below is driven by
     that description, so a new section is a new entry in the server's registry
     and it arrives with a working table, form and row actions - which is what
     stops the panel from being half built. */

  function cell(col, row) {
    var raw = row[col.key];
    if (col.kind === 'number') {
      return raw == null || raw === '' ? '<span class="muted">—</span>'
        : esc(SI.formatNumber(raw));
    }
    if (col.kind === 'money') {
      return raw == null || raw === '' ? '<span class="muted">—</span>'
        : esc('$' + Number(raw).toFixed(2));
    }
    if (col.kind === 'time') {
      return raw ? '<span class="mono">' + esc(formatWhen(raw)) + '</span>'
        : '<span class="muted">—</span>';
    }
    if (col.kind === 'status') {
      var value = String(raw == null ? '' : raw);
      if (!value) return '<span class="muted">—</span>';
      var tone = (col.options || []).indexOf(value) >= 0 ? value : '';
      return '<span class="pill pill--' + esc(tone || 'off') + '">' + esc(value) + '</span>';
    }
    if (col.kind === 'user') {
      return raw ? esc(raw) + (row[col.key + '_id']
        ? ' <small class="muted mono">' + esc(row[col.key + '_id']) + '</small>' : '')
        : '<span class="muted">—</span>';
    }
    if (raw == null || raw === '') return '<span class="muted">—</span>';
    var text = String(raw);
    if (text.length > 140) {
      return esc(text.slice(0, 140)) + '…';
    }
    return esc(text);
  }

  function formatWhen(value) {
    var stamp = typeof value === 'number' ? value * 1000 : Date.parse(value);
    if (!stamp || isNaN(stamp)) return String(value);
    var date = new Date(stamp);
    return date.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
  }

  function viewCollection(data) {
    var rows = data.records || [];
    var columns = data.columns || [];
    var writable = data.writable !== false;
    var open = data.open || {};

    var head = '';
    if (data.hint) {
      head = '<p class="page__desc">' + esc(data.hint) + '</p>';
    }
    var counts = Object.keys(open).map(function (key) {
      return open[key] ? '<span class="pill pill--warn">' + esc(key) + ': ' + open[key] + '</span>' : '';
    }).join(' ');

    var body = rows.length
      ? '<div class="table-scroll"><table class="table"><thead><tr>' +
        columns.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') +
        (writable ? '<th></th>' : '') + '</tr></thead><tbody>' +
        rows.map(function (row) {
          return '<tr>' + columns.map(function (c) {
            return '<td>' + cell(c, row) + '</td>';
          }).join('') +
            (writable
              ? '<td><div class="table__actions">' +
                '<button class="mini" data-rec-edit="' + esc(row.id) + '">Edit</button>' +
                '<button class="mini mini--bad" data-rec-del="' + esc(row.id) + '">Delete</button>' +
                '</div></td>'
              : '') + '</tr>';
        }).join('') + '</tbody></table></div>'
      : '<div class="empty-note"><b>Nothing recorded yet</b>' +
        '<span>' + (writable
          ? 'Add the first one with the New button. Anything the bot records appears here too.'
          : 'The combined bot fills this from Discord. It records nothing until a ' +
            'channel or guild is listed in Bot Configuration.') + '</span></div>';

    return head +
      '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>' +
        esc(data.label || 'Records') + '</h3>' +
        '<span class="pill">' + rows.length + '</span>' + counts +
        (writable
          ? '<button class="btn btn--primary" id="btnNewRecord"><span>New</span></button>'
          : '<span class="pill pill--off">read only</span>') + '</div>' +
      '<div class="box__body box__body--flush">' + body + '</div></div>';
  }

  function fieldInput(field, value) {
    var id = 'rec_' + field.key;
    var label = '<label class="field"><span class="field__label">' + esc(field.label) +
      (field.required ? ' <b style="color:var(--text-dim)">*</b>' : '') + '</span>';
    if (field.type === 'textarea') {
      return label + '<textarea id="' + id + '" maxlength="2000">' + esc(value || '') +
        '</textarea></label>';
    }
    if (field.type === 'select') {
      return label + '<select id="' + id + '">' +
        (field.options || []).map(function (o) {
          return '<option value="' + esc(o) + '"' + (String(value) === o ? ' selected' : '') +
            '>' + esc(o) + '</option>';
        }).join('') + '</select></label>';
    }
    if (field.type === 'number') {
      return label + '<input type="number" step="' + esc(field.step || '1') + '" id="' + id +
        '" value="' + esc(value == null ? '' : value) + '"></label>';
    }
    if (field.type === 'list') {
      var items = Array.isArray(value) ? value.join('\n') : (value || '');
      return label + '<textarea id="' + id + '" placeholder="one Discord id per line">' +
        esc(items) + '</textarea></label>';
    }
    return label + '<input type="text" id="' + id + '" value="' + esc(value == null ? '' : value) +
      '"' + (field.placeholder ? ' placeholder="' + esc(field.placeholder) + '"' : '') +
      ' maxlength="200"></label>';
  }

  function recordDialog(existing) {
    var data = state.view || {};
    var fields = data.form || [];
    var kind = data.kind;
    if (!fields.length) return;
    openModal((existing ? 'Edit ' : 'New ') + (data.label || 'record'),
      fields.map(function (f) {
        return fieldInput(f, existing ? existing[f.key] : f.default);
      }).join('') +
      '<div class="form-error" id="recErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
      '<button class="btn btn--primary" id="recSave"><span>Save</span></button></div>',
      function () {
        document.getElementById('recSave').addEventListener('click', function () {
          /* "section", not "kind": a case and an action both have a field of
             their own called kind, and one key for both meant the section
             selector overwrote it. */
          var payload = { section: kind };
          fields.forEach(function (f) {
            var node = document.getElementById('rec_' + f.key);
            if (node) payload[f.key] = node.value;
          });
          if (existing) payload.id = existing.id;
          api('POST', existing ? 'panel/mod/update' : 'panel/mod', payload)
            .then(function () { closeModal(); navigate(state.route); })
            .catch(function (err) { showError('recErr', err.message); });
        });
      });
  }

  function viewOverview(data) {
    var cards = data.cards || [];
    var links = data.links || [];
    return '<div class="grid grid--4" style="margin-top:1.2rem">' +
      cards.map(function (c) {
        return '<div class="box"><div class="box__body">' +
          '<p class="stat__label">' + esc(c.label) + '</p>' +
          '<p class="stat__value">' + esc(
            typeof c.value === 'number' ? SI.formatNumber(c.value) : c.value) + '</p>' +
          (c.note ? '<p class="muted small">' + esc(c.note) + '</p>' : '') +
          '</div></div>';
      }).join('') + '</div>' +
      '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>Sections</h3></div>' +
      '<div class="box__body"><div class="table__actions">' +
      links.map(function (l) {
        return '<button class="mini" data-goto="' + esc(l.id) + '">' + esc(l.label) + '</button>';
      }).join('') + '</div></div></div>';
  }

  function viewConfig(data) {
    var fields = data.fields || [];
    var settings = data.settings || {};
    var guilds = data.guild_state || [];
    return '<p class="page__desc">' + esc(data.hint || '') + '</p>' +
      '<div class="form-error" id="cfgErr" hidden></div>' +
      '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>' +
        esc(data.label || 'Configuration') + '</h3></div><div class="box__body">' +
      fields.map(function (f) {
        var value = settings[f.key];
        if (f.type === 'list' && Array.isArray(value)) value = value.join('\n');
        return fieldInput(f, value);
      }).join('') +
      '<div class="wizard__actions"><button class="btn btn--primary" id="cfgSave">' +
        '<span>Save</span></button></div>' +
      '</div></div>' +
      (guilds.length
        ? '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Guilds</h3></div>' +
          '<div class="box__body box__body--flush"><table class="table"><thead><tr><th>Id</th>' +
          '<th>Name</th><th>Bot</th></tr></thead><tbody>' +
          guilds.map(function (g) {
            return '<tr><td class="mono">' + esc(g.id) + '</td><td>' +
              esc(g.name || '<i>not visible to the bot</i>') + '</td><td>' +
              '<span class="pill pill--' + (g.online ? 'live' : 'off') + '">' +
              (g.online ? 'connected' : 'not connected') + '</span></td></tr>';
          }).join('') + '</tbody></table></div></div>'
        : '');
  }

  function viewDashboard(data) {
    var bot = data.bot || {};
    var stats = [
      ['Bots Online', (bot.online || 0) + '/' + (bot.total || 0),
        bot.all_online ? 'good' : 'warn', 'community and moderation'],
      ['Bot Tokens', (bot.tokens_valid || 0) + '/' + (bot.total || 0),
        (bot.tokens_valid || 0) === (bot.total || 0) ? 'good' : 'warn',
        'accepted by Discord'],
      ['Staff Logins', data.staff, '', 'accounts with a login'],
      ['Active Sessions', data.active_sessions, '', 'signed in right now'],
      ['Conversations', data.chat && data.chat.threads || 0, '', 'direct message threads'],
      ['Games', data.games, '', 'in the public registry'],
      ['Site Visits', data.site_visits, '', 'sessions on this website'],
      ['Web Announcements', data.announcements, '', 'live on the site'],
      ['Mail Delivery', data.mail && data.mail.configured ? 'SMTP' : 'Console',
        data.mail && data.mail.configured ? 'good' : 'warn',
        data.mail && data.mail.configured ? esc(data.mail.host) : 'codes print to the console'],
      ['AI Chat', data.ai && data.ai.configured ? 'Ready' : 'Not set',
        data.ai && data.ai.configured ? 'good' : 'warn',
        data.ai && data.ai.model ? esc(data.ai.model) : 'add AI_CHAT_URL to .env']
    ];
    var commands = (bot.commands || []).map(function (cmd) {
      var label = typeof cmd === 'string' ? cmd : (cmd.name || '');
      var group = typeof cmd === 'string' ? '' : (cmd.group || '');
      return '<div class="cmd"><b>' + esc(label) + '</b><span>' + esc(group) + '</span></div>';
    }).join('');

    var botCards = (bot.bots || []).map(function (b) {
      var seen = b.last_seen
        ? new Date(b.last_seen * 1000).toLocaleString()
        : 'never reported in';
      /* Token state is reported next to the heartbeat because the two failures
         look identical from the heartbeat alone: a bot that is not running and
         a token Discord rejects both read as "offline", and the fix is
         completely different. The token itself is never sent to the browser. */
      var token;
      if (!b.token_set) {
        token = 'no token in .env (' + esc(b.token_env || 'unset') + ')';
      } else if (b.token_valid) {
        token = 'token accepted' +
          (b.bot_username ? ' as ' + esc(b.bot_username) : '') +
          (b.token_env ? ' via ' + esc(b.token_env) : '');
      } else {
        token = 'token rejected' + (b.token_error ? ': ' + esc(b.token_error) : '');
      }
      var tone = b.token_set && b.token_valid ? 'stat--good' : 'stat--bad';
      return '<div class="stat ' + tone + '">' +
        '<div class="stat__k">' + esc(b.label) + '</div>' +
        '<div class="stat__v">' + (b.online ? 'Online' : 'Offline') + '</div>' +
        '<div class="stat__n">' + esc(b.role) + '</div>' +
        '<div class="stat__n">' + (b.online && b.latency_ms != null
          ? esc(b.latency_ms + 'ms latency') : 'last seen ' + esc(seen)) + '</div>' +
        '<div class="stat__n">' + token + '</div>' +
      '</div>';
    }).join('');

    return '<div class="grid grid--4" style="margin-top:1.2rem">' +
      stats.map(function (s) {
        return '<div class="stat' + (s[2] ? ' stat--' + s[2] : '') + '">' +
          '<div class="stat__k">' + esc(s[0]) + '</div>' +
          '<div class="stat__v">' + esc(String(s[1])) + '</div>' +
          '<div class="stat__n">' + s[3] + '</div></div>';
      }).join('') + '</div>' +
      '<div class="box" style="margin-top:1rem">' +
        '<div class="box__head"><h3>Bots</h3>' +
          (bot.reason ? '<span class="pill pill--warn">' + esc(bot.reason) + '</span>' : '') +
        '</div>' +
        '<div class="box__body"><div class="grid grid--2">' + (botCards ||
          '<div class="empty-note"><b>No bots registered</b></div>') + '</div></div>' +
      '</div>' +
      '<div class="box" style="margin-top:1rem">' +
        '<div class="box__head"><h3>Active commands</h3>' +
          '<span class="pill pill--off">' + (bot.commands || []).length + '</span></div>' +
        '<div class="box__body">' +
          (commands
            ? '<div class="cmd-list">' + commands + '</div>'
            : '<div class="empty-note">' +
              '<b>No commands reported</b><span>Each bot publishes its command list once it ' +
              'connects.</span></div>') +
        '</div></div>';
  }

  function viewStaff(data) {
    var staff = data.staff || [];
    var bot = data.bot || {};
    var canManage = data.can_manage !== false;
    return '<div class="box" style="margin-top:1.2rem">' +
      '<div class="box__head"><h3>Staff logins</h3>' +
        '<span class="pill pill--off">' + staff.length + ' account' + (staff.length === 1 ? '' : 's') + '</span>' +
        (canManage ? '<button class="btn btn--primary" id="btnNewLogin"><span>New login</span></button>' : '') +
        (bot.online ? '' : '<span class="pill pill--warn">discord sync idle</span>') +
      '</div>' +
      '<div class="box__body box__body--flush">' +
        (staff.length ? '<table class="table"><thead><tr>' +
          '<th>Username</th><th>Staff ID</th><th>Rank</th><th>Email</th>' +
          '<th>Verified</th><th>Passkey</th><th>Discord</th><th></th>' +
        '</tr></thead><tbody>' +
        staff.map(function (m) {
          return '<tr data-staff="' + esc(m.id) + '">' +
            '<td><b>' + esc(m.username) + '</b></td>' +
            '<td class="table__id">' + esc(m.id) + '</td>' +
            '<td><span class="pill pill--cyan">' + esc(m.rank_label) + '</span></td>' +
            '<td>' + esc(m.email) + '</td>' +
            '<td>' + (m.email_verified
              ? '<span class="pill pill--live">yes</span>'
              : '<span class="pill pill--warn">pending</span>') + '</td>' +
            '<td>' + (m.passkey
              ? '<span class="pill pill--live">enrolled</span>'
              : '<span class="pill pill--off">none</span>') + '</td>' +
            '<td class="table__id">' + esc(m.discord_id || '—') + '</td>' +
            '<td>' + (canManage
              ? '<div class="table__actions">' +
                '<button class="mini" data-act="rank" data-id="' + esc(m.id) + '">Rank</button>' +
                '<button class="mini mini--bad" data-act="remove" data-id="' + esc(m.id) + '">Remove</button>' +
                '</div>'
              : '<span class="muted small">read only</span>') + '</td></tr>';
        }).join('') + '</tbody></table>'
        : '<div class="empty-note"><b>No staff logins</b><span>Nobody has an account yet.</span></div>') +
      '</div></div>' +
      '<p class="page__desc" style="margin-top:.9rem">You can only act on someone of a ' +
        'strictly lower rank. If someone is ranked in Discord without a website login, the ' +
        'bot strips the rank and DMs whoever applied it.</p>';
  }

  function viewAnnouncements(data) {
    var items = data.announcements || [];
    var audience = data.kind || 'public';
    var label = data.label || 'Web';
    /* One list, three audiences, three sections. Which one this is comes from
       the server, so the same view serves the web feed and the two staff feeds
       and cannot be pointed at the wrong one by a click. */
    return '<p class="page__desc">One announcements list, filed by audience. ' +
      'The <b>' + esc(label) + '</b> feed shows what is addressed to ' +
      esc(label === 'Web' ? 'visitors' : label === 'Staff' ? 'the studio staff' : 'the development team') +
      ' and expires after ' + (data.ttl_days || 14) + ' days.</p>' +
      '<div class="box" style="margin-top:1.2rem">' +
      '<div class="box__head"><h3>' + esc(label) + ' announcements</h3>' +
        '<span class="pill pill--off">expire after ' + (data.ttl_days || 14) + ' days</span>' +
        '<button class="btn btn--primary" id="btnNewAnn"><span>New</span></button></div>' +
      '<div class="box__body box__body--flush">' +
        (items.length ? '<table class="table"><thead><tr><th>Title</th><th>Tag</th>' +
          '<th>Posted</th><th>Expires</th><th></th></tr></thead><tbody>' +
          items.map(function (a) {
            return '<tr><td><b>' + esc(a.title) + '</b><br><small style="color:var(--text-mute)">' +
              esc(a.body) + '</small></td>' +
              '<td><span class="pill pill--cyan">' + esc(a.tag) + '</span></td>' +
              '<td>' + esc((a.created_at || '').slice(0, 10)) + '</td>' +
              '<td>' + esc((a.expires_at || '').slice(0, 10)) + '</td>' +
              '<td><div class="table__actions">' +
              '<button class="mini" data-edit-ann="' + esc(a.id) + '">Edit</button>' +
              '<button class="mini mini--bad" data-del-ann="' +
              esc(a.id) + '">Delete</button></div></td></tr>';
          }).join('') + '</tbody></table>'
        : '<div class="empty-note"><b>Nothing posted</b>' +
          '<span>' + (audience === 'public'
            ? 'Announcements you create here appear on the public site immediately.'
            : 'Nothing has been posted to this audience yet. It stays off the public site.') +
          '</span></div>') +
      '</div></div>';
  }

  function viewWebConfig(data) {
    var socials = data.socials || {};
    var games = data.games || [];
    var featured = data.featured || {};
    var statuses = data.statuses || [];
    /* Cached so the editors open with the same vocabulary and the same values
       the view was just rendered from, instead of re-fetching. */
    state.gameStatuses = statuses;
    state.featured = featured;
    state.webConfigGames = games;

    var gameRows = games.map(function (g) {
      return '<tr><td>' + esc(g.title) + '<br><small style="color:var(--text-mute)">' +
          esc((g.tags || []).join(', ')) + '</small></td>' +
        '<td><span class="pill pill--live">' + esc(g.status) + '</span></td>' +
        '<td>' + (g.featured ? '<span class="pill pill--cyan">Featured</span>' : '<span class="pill pill--off">—</span>') + '</td>' +
        '<td><div class="table__actions">' +
          '<button class="mini" data-edit-game="' + esc(g.id) + '">Edit</button>' +
          (g.featured ? '' : '<button class="mini" data-feature-game="' + esc(g.id) + '">Feature</button>') +
          '<button class="mini mini--bad" data-del-game="' + esc(g.id) + '">Remove</button>' +
        '</div></td></tr>';
    }).join('');

    return '<div class="grid grid--2" style="margin-top:1.2rem">' +
      '<div class="box"><div class="box__head"><h3>Social links</h3></div><div class="box__body">' +
        '<dl class="kv">' + Object.keys(socials).map(function (k) {
          return '<dt>' + esc(k) + '</dt><dd>' + (socials[k] ? esc(socials[k]) : '<i>not set &mdash; the button is hidden</i>') + '</dd>';
        }).join('') + '</dl>' +
        '<p class="muted small" style="margin-top:.7rem">A blank URL hides that button in the ' +
          'header rather than rendering a dead link.</p></div></div>' +
      '<div class="box"><div class="box__head"><h3>Discord</h3></div><div class="box__body">' +
        '<dl class="kv"><dt>Guilds</dt><dd>' +
          esc((data.discord && data.discord.guilds || []).join(', ') || 'none configured') +
        '</dd><dt>Announcement TTL</dt><dd>' + (data.announcement_ttl_days || 14) + ' days</dd>' +
        '<dt>Contact</dt><dd>' + esc((data.contact && data.contact.email) || '—') + '</dd></dl>' +
        '<p class="muted small" style="margin-top:.7rem">Guild IDs are summed for the Community ' +
          'Members counter. They need a bot token in Core/.env to return live numbers.</p>' +
      '</div></div></div>' +

      '<div class="form-error" id="webConfigErr" hidden></div>' +
      '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>Games</h3>' +
        '<span class="pill">' + games.length + '</span>' +
        '<button class="btn btn--primary" id="btnNewGame"><span>Add game</span></button></div>' +
        '<div class="box__body box__body--flush">' +
        (gameRows
          ? '<table class="table"><thead><tr><th>Title</th><th>Status</th><th>Featured</th><th></th></tr></thead><tbody>' +
            gameRows + '</tbody></table>'
          : '<div class="empty-note"><b>No games registered</b><span>The Games page shows whatever is ' +
            'listed here, and the Games counter on the home page counts it.</span></div>') +
      '</div></div>' +

      '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Featured game</h3>' +
        '<span class="pill pill--cyan">' + esc(featured.title || 'not set') + '</span>' +
        '<button class="btn btn--ghost" id="btnEditFeatured"><span>Edit</span></button></div>' +
        '<div class="box__body"><dl class="kv">' +
          '<dt>Status</dt><dd>' + esc(featured.status || '—') + '</dd>' +
          '<dt>Release estimate</dt><dd>' + esc(featured.release_estimate || 'not set') + '</dd>' +
        '<dt>Rating</dt><dd>' + (featured.stats && featured.stats.rating != null
          ? esc(featured.stats.rating) + ' / 5' +
            (featured.stats.rating_count != null
              ? ' <span class="muted">· ' + SI.formatNumber(featured.stats.rating_count) +
                (Math.round(featured.stats.rating_count) === 1 ? ' rating' : ' ratings') +
                '</span>'
              : ' <span class="muted">· not counted</span>')
          : '<i>— pending</i>') + '</dd>' +
          '<dt>Active players</dt><dd>' + (featured.stats && featured.stats.active_players != null
            ? SI.formatNumber(featured.stats.active_players) : '<i>— pending</i>') + '</dd>' +
          '<dt>Total visits</dt><dd>' + (featured.stats && featured.stats.total_visits != null
            ? SI.formatNumber(featured.stats.total_visits) : '<i>— pending</i>') + '</dd>' +
          '<dt>Site visits</dt><dd>' + (data.site_visits != null
            ? SI.formatNumber(data.site_visits) : '0') +
            ' <span class="muted small">(sessions on this website, not the game)</span></dd>' +
        '</dl>' +
        '<p class="muted small" style="margin-top:.7rem">This is the block the home page renders. ' +
          'Use <b>Feature</b> on a game to copy it here, then edit the release estimate and the ' +
          'stats in this panel.</p></div></div>';
  }

  function gameDialog(existing) {
    var g = existing || { title: '', status: 'Concept', tags: [], description: '', thumb: '', url: '', discord: '', release_estimate: '', plays: '' };
    var statuses = state.gameStatuses || ['Concept', 'In Production', 'Playtesting', 'Released', 'Paused'];
    openModal(existing ? 'Edit game' : 'Add game',
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Title</span>' +
          '<input type="text" id="gameTitle" value="' + esc(g.title) + '"></label>' +
        '<label class="field"><span class="field__label">Status</span><select id="gameStatus">' +
          statuses.map(function (s) {
            return '<option value="' + esc(s) + '"' + (g.status === s ? ' selected' : '') + '>' + esc(s) + '</option>';
          }).join('') + '</select></label>' +
      '</div>' +
      '<label class="field"><span class="field__label">Tags (comma separated, up to 8)</span>' +
        '<input type="text" id="gameTags" value="' + esc((g.tags || []).join(', ')) + '"></label>' +
      '<label class="field"><span class="field__label">Description</span>' +
        '<textarea id="gameDesc">' + esc(g.description || '') + '</textarea></label>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Thumbnail path</span>' +
          '<input type="text" id="gameThumb" value="' + esc(g.thumb || '') + '" placeholder="/assets/game-thumb.png"></label>' +
        '<label class="field"><span class="field__label">Release estimate</span>' +
          '<input type="text" id="gameEta" value="' + esc(g.release_estimate || '') + '" placeholder="TBA"></label>' +
      '</div>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Play link</span>' +
          '<input type="text" id="gameUrl" value="' + esc(g.url || '') + '"></label>' +
        '<label class="field"><span class="field__label">Discord link</span>' +
          '<input type="text" id="gameDiscord" value="' + esc(g.discord || '') + '"></label>' +
      '</div>' +
      '<label class="field"><span class="field__label">Plays (leave blank if not published)</span>' +
        '<input type="text" id="gamePlays" value="' + esc(g.plays === null || g.plays === undefined ? '' : g.plays) + '"></label>' +
      '<div class="form-error" id="gameErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
        '<button class="btn btn--primary" id="gameSave"><span>Save</span></button></div>',
      function () {
        document.getElementById('gameSave').addEventListener('click', function () {
          var plays = document.getElementById('gamePlays').value.trim();
          var playsNumber = plays === '' ? null : Number(plays);
          /* JSON.stringify would turn NaN into null on its own, but doing it
             here keeps "not a number" reading as "not published" rather than
             relying on that coincidence. */
          if (playsNumber !== null && !isFinite(playsNumber)) playsNumber = null;
          api(existing ? 'PATCH' : 'POST', existing ? 'panel/games/' + encodeURIComponent(existing.id) : 'panel/games', {
            title: document.getElementById('gameTitle').value,
            status: document.getElementById('gameStatus').value,
            tags: document.getElementById('gameTags').value.split(',')
              .map(function (t) { return t.trim(); }).filter(Boolean),
            description: document.getElementById('gameDesc').value,
            thumb: document.getElementById('gameThumb').value,
            release_estimate: document.getElementById('gameEta').value,
            url: document.getElementById('gameUrl').value,
            discord: document.getElementById('gameDiscord').value,
            plays: playsNumber
          }).then(function () {
            closeModal();
            navigate('web-config');
          }).catch(function (err) { showError('gameErr', err.message); });
        });
      });
  }

  function featuredDialog() {
    var f = state.featured || {};
    var stats = f.stats || {};
    openModal('Featured game',
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Title</span>' +
          '<input type="text" id="fTitle" value="' + esc(f.title || '') + '"></label>' +
        '<label class="field"><span class="field__label">Status</span>' +
          '<input type="text" id="fStatus" value="' + esc(f.status || 'In Production') + '"></label>' +
      '</div>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Release estimate</span>' +
          '<input type="text" id="fEta" value="' + esc(f.release_estimate || '') + '" placeholder="TBA"></label>' +
        '<label class="field"><span class="field__label">Thumbnail path</span>' +
          '<input type="text" id="fThumb" value="' + esc(f.thumb || '') + '"></label>' +
      '</div>' +
      '<label class="field"><span class="field__label">Description</span>' +
        '<textarea id="fDesc">' + esc(f.description || '') + '</textarea></label>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Play link</span>' +
          '<input type="text" id="fUrl" value="' + esc(f.url || '') + '"></label>' +
        '<label class="field"><span class="field__label">Discord link</span>' +
          '<input type="text" id="fDiscord" value="' + esc(f.discord || '') + '"></label>' +
      '</div>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Rating out of 5 (blank = pending)</span>' +
          '<input type="text" id="fRating" inputmode="decimal" placeholder="4.6" value="' +
          esc(stats.rating == null ? '' : stats.rating) + '"></label>' +
        '<label class="field"><span class="field__label">How many ratings</span>' +
          '<input type="text" id="fRatingCount" inputmode="numeric" placeholder="1204" value="' +
          esc(stats.rating_count == null ? '' : stats.rating_count) + '"></label>' +
      '</div>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Active players (blank = pending)</span>' +
          '<input type="text" id="fPlayers" value="' + esc(stats.active_players == null ? '' : stats.active_players) + '"></label>' +
      '</div>' +
      '<div class="form-error" id="fErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
        '<button class="btn btn--primary" id="fSave"><span>Save</span></button></div>',
      function () {
        document.getElementById('fSave').addEventListener('click', function () {
          api('POST', 'panel/featured', {
            title: document.getElementById('fTitle').value,
            status: document.getElementById('fStatus').value,
            release_estimate: document.getElementById('fEta').value,
            thumb: document.getElementById('fThumb').value,
            description: document.getElementById('fDesc').value,
            url: document.getElementById('fUrl').value,
            discord: document.getElementById('fDiscord').value,
            stats: {
              rating: document.getElementById('fRating').value,
              rating_count: document.getElementById('fRatingCount').value,
              active_players: document.getElementById('fPlayers').value,
              total_visits: stats.total_visits
            }
          }).then(function (data) {
            state.featured = data.featured;
            closeModal();
            navigate('web-config');
          }).catch(function (err) { showError('fErr', err.message); });
        });
      });
  }

  function money(value) {
    if (value === null || value === undefined || value === '') return 'custom';
    var n = Number(value);
    if (!isFinite(n)) return 'custom';
    return '$' + n.toFixed(2);
  }

  function viewStore(data) {
    var games = data.games || [];
    var store = data.currency || {};
    var currency = store.currency || {};
    var bundles = currency.bundles || [];
    var roles = store.roles || [];
    var custom = currency.custom || {};
    var rate = store.stitchblocks_per_usd || custom.price_per_unit || 0;
    var pay = store.payments || {};
    /* Kept on state so the discount editor can merge against the live list. */
    state.discounts = data.discounts || [];

    var bundleRows = bundles.map(function (b) {
      return '<tr><td>' + SI.formatNumber(b.amount) + '</td><td>' + money(b.price) + '</td>' +
        '<td>$' + Number(rate).toFixed(4) + '</td></tr>';
    }).join('');

    var customRate = Number(custom.price_per_unit || rate) || 0;
    var customRow = '<tr><td>Custom amount</td><td>' +
      (custom.enabled ? '$' + customRate.toFixed(4) + ' per block' : 'disabled') +
      '</td><td>min ' + SI.formatNumber(custom.min_amount || 1) + '</td></tr>';

    var roleRows = roles.map(function (r) {
      return '<tr><td>' + esc(r.name) + '</td><td>' +
        (r.custom ? '<span class="pill pill--warn">custom amount</span>' : money(r.price)) +
        '</td><td>' + esc(r.blurb || '') + '</td></tr>';
    }).join('');

    var providers = (pay.providers || []).concat(pay.manual_review || []);

    return '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>' +
        esc(currency.name || 'Currency') + '</h3>' +
        '<span class="pill pill--cyan">$' + Number(rate).toFixed(4) + ' per block</span>' +
      '</div><div class="box__body box__body--flush">' +
        (bundles.length || custom.enabled
          ? '<table class="table"><thead><tr><th>Amount</th><th>Price</th><th>Unit rate</th></tr></thead><tbody>' +
            bundleRows + customRow + '</tbody></table>'
          : '<div class="empty-note"><b>No packs configured</b></div>') +
      '</div></div>' +
      '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Roles</h3></div>' +
      '<div class="box__body box__body--flush">' +
        (roleRows
          ? '<table class="table"><thead><tr><th>Role</th><th>Price</th><th>Includes</th></tr></thead><tbody>' +
            roleRows + '</tbody></table>'
          : '<div class="empty-note"><b>No roles configured</b></div>') +
      '</div></div>' +
      '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Payments</h3></div>' +
      '<div class="box__body">' +
        '<dl class="kv"><dt>Accepted</dt><dd>' + esc(providers.join(', ') || 'not set') + '</dd>' +
        '<dt>Discord ID</dt><dd>' + (pay.require_discord_id
          ? 'verified before payment is created' : 'not required') + '</dd>' +
        '<dt>Note</dt><dd>' + esc(pay.note || '') + '</dd>' +
        (pay.liability_note ? '<dt>Liability</dt><dd>' + esc(pay.liability_note) + '</dd>' : '') +
        '</dl></div></div>' +
      '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Games</h3></div>' +
      '<div class="box__body box__body--flush">' +
        (games.length ? '<table class="table"><thead><tr><th>Title</th><th>Status</th>' +
          '<th>Featured</th></tr></thead><tbody>' +
          games.map(function (g) {
            return '<tr><td>' + esc(g.title) + '</td><td><span class="pill pill--live">' +
              esc(g.status) + '</span></td><td>' + (g.featured ? 'yes' : 'no') + '</td></tr>';
          }).join('') + '</tbody></table>'
          : '<div class="empty-note"><b>No games</b></div>') +
      '</div></div>' +
      '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Discount codes</h3>' +
        '<button class="btn btn--primary" id="btnNewDiscount"><span>New code</span></button></div>' +
        '<div class="box__body box__body--flush">' + viewDiscounts(data.discounts) + '</div></div>' +
      '<p class="page__desc" style="margin-top:1rem">Prices are editable in Store Management. ' +
      'Checkout activates when Stripe, Stripe Tax, webhooks, and the public URL are configured.</p>';
  }

  function viewDiscounts(codes) {
    if (!codes || !codes.length) {
      return '<div class="empty-note"><b>No discount codes</b>' +
        '<span>Add one below and shoppers can type it into the cart.</span></div>';
    }
    return '<table class="table"><thead><tr><th>Code</th><th>Discount</th><th>Minimum</th>' +
      '<th>Uses</th><th>Expires</th><th>State</th><th></th></tr></thead><tbody>' +
      codes.map(function (d) {
        var limit = Number(d.max_uses) || 0;
        var used = Number(d.used) || 0;
        return '<tr><td class="mono">' + esc(d.code) + '</td>' +
          '<td>' + (d.kind === 'percent' ? esc(Number(d.value)) + '% off' : '$' + Number(d.value).toFixed(2) + ' off') + '</td>' +
          '<td>' + (Number(d.min_subtotal) > 0 ? '$' + Number(d.min_subtotal).toFixed(2) : 'none') + '</td>' +
          '<td>' + used + (limit ? ' / ' + limit : ' / no limit') + '</td>' +
          '<td>' + esc(d.expires || 'never') + '</td>' +
          '<td><span class="pill ' + (d.active ? 'pill--live' : 'pill--warn') + '">' +
            (d.active ? 'Active' : 'Off') + '</span></td>' +
          '<td><button class="btn btn--ghost" data-edit-discount="' + esc(d.id) + '"><span>Edit</span></button></td></tr>';
      }).join('') + '</tbody></table>';
  }

  function discountDialog(existing) {
    var d = existing || { id: '', code: '', kind: 'percent', value: 10, min_subtotal: 0, max_uses: 0, expires: '', active: true, note: '' };
    openModal(existing ? 'Edit discount code' : 'New discount code',
      '<label class="field"><span class="field__label">Code</span>' +
        '<input type="text" id="dCode" value="' + esc(d.code) + '" placeholder="LAUNCH10" ' +
        'style="text-transform:uppercase"></label>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Kind</span><select id="dKind">' +
          '<option value="percent"' + (d.kind === 'percent' ? ' selected' : '') + '>Percent off</option>' +
          '<option value="fixed"' + (d.kind === 'fixed' ? ' selected' : '') + '>Fixed amount off</option>' +
        '</select></label>' +
        '<label class="field"><span class="field__label">Value</span>' +
          '<input type="number" id="dValue" step="0.01" value="' + esc(d.value) + '"></label>' +
      '</div>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Minimum subtotal (0 = none)</span>' +
          '<input type="number" id="dMin" step="0.01" value="' + esc(d.min_subtotal) + '"></label>' +
        '<label class="field"><span class="field__label">Max uses (0 = no limit)</span>' +
          '<input type="number" id="dMax" min="0" value="' + esc(d.max_uses) + '"></label>' +
      '</div>' +
      '<label class="field"><span class="field__label">Expires (ISO date, blank = never)</span>' +
        '<input type="text" id="dExpires" value="' + esc(d.expires || '') + '" placeholder="2026-12-31T23:59:00+00:00"></label>' +
      '<label class="field"><span class="field__label">Note for shoppers</span>' +
        '<input type="text" id="dNote" value="' + esc(d.note || '') + '"></label>' +
      '<label class="check"><input type="checkbox" id="dActive"' + (d.active ? ' checked' : '') + '>' +
        '<span>Active</span></label>' +
      '<div class="form-error" id="dErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
        '<button class="btn btn--primary" id="dSave"><span>Save code</span></button></div>',
      function () {
        document.getElementById('dSave').addEventListener('click', function () {
          var code = document.getElementById('dCode').value.trim();
          if (!code) { showError('dErr', 'A code needs a name.'); return; }
          /* Replace the entry being edited, or append it, then send the whole
             list. store_settings merges by id, so nothing is lost either way,
             and a code's `used` count is carried over rather than reset. */
          var merged = (state.discounts || []).filter(function (x) { return x.id !== d.id; });
          merged.push({
            id: d.id || code.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
            code: code.toUpperCase(),
            kind: document.getElementById('dKind').value,
            value: Number(document.getElementById('dValue').value) || 0,
            min_subtotal: Number(document.getElementById('dMin').value) || 0,
            max_uses: Number(document.getElementById('dMax').value) || 0,
            used: d.used || 0,
            expires: document.getElementById('dExpires').value.trim(),
            active: document.getElementById('dActive').checked,
            note: document.getElementById('dNote').value.trim()
          });
          api('POST', 'panel/store/settings', { discounts: merged }).then(function () {
            closeModal();
            navigate('store-management');
          }).catch(function (err) { showError('dErr', err.message); });
        });
      });
  }

  function viewApplications(data) {
    var roles = data.roles || [];
    var apps = data.applications || [];
    var counts = {};
    apps.forEach(function (a) { counts[a.role_id] = (counts[a.role_id] || 0) + 1; });

    var roleCards = roles.map(function (role) {
      return '<div class="box" style="margin-top:1rem"><div class="box__head">' +
        '<h3>' + esc(role.role) + '</h3>' +
        (role.open ? '<span class="pill pill--live">Open</span>' : '<span class="pill">Closed</span>') +
        '<span class="pill pill--cyan">' + (counts[role.id] || 0) + ' applicants</span>' +
        '<button class="btn btn--ghost" data-edit-role="' + esc(role.id) + '"><span>Edit</span></button>' +
        '<button class="btn btn--ghost" data-del-role="' + esc(role.id) + '"><span>Delete</span></button>' +
        '</div><div class="box__body">' +
        '<p class="page__desc" style="margin-top:0">' + esc(role.summary) + '</p>' +
        '<dl class="kv"><dt>Questions</dt><dd>' + (role.questions.length || 'none') + '</dd>' +
        '<dt>Closes</dt><dd>' + esc(role.closes_at || 'not set') + '</dd></dl>' +
        '</div></div>';
    }).join('');

    var inbox = apps.map(function (app) {
      return '<tr><td class="mono">' + esc(app.ref) + '</td><td>' + esc(app.role) + '</td>' +
        '<td>' + esc(app.name) + '<br><span class="muted">' + esc(app.email) + '</span></td>' +
        '<td class="muted">' + esc(app.received || '') + '</td>' +
        '<td><span class="pill ' + (app.read ? '' : 'pill--warn') + '">' + esc(app.status || 'new') + '</span></td>' +
        '<td><button class="btn btn--ghost" data-view-app="' + esc(app.ref) + '"><span>Open</span></button></td></tr>';
    }).join('');

    return '<div class="row row--between" style="margin-top:1.2rem">' +
        '<p class="muted small">Roles control what the Application page shows. Everything here ' +
        'is live, so changing a role changes the public page immediately.</p>' +
        '<button class="btn btn--primary" id="btnNewRole"><span>New role</span></button>' +
      '</div>' +
      '<div class="form-error" id="roleErr" hidden></div>' +
      (roleCards || '<div class="empty-note"><b>No roles yet</b></div>') +
      '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>Applications received</h3>' +
        '<span class="pill">' + apps.length + '</span></div><div class="box__body box__body--flush">' +
        (inbox
          ? '<table class="table"><thead><tr><th>Ref</th><th>Role</th><th>Who</th><th>When</th>' +
            '<th>Status</th><th></th></tr></thead><tbody>' + inbox + '</tbody></table>'
          : '<div class="empty-note"><b>Nothing received yet</b><span>Applications land here the moment they are sent.</span></div>') +
      '</div></div>';
  }

  function roleDialog(role) {
    var r = role || { id: '', role: '', summary: '', responsibilities: [], requirements: [], questions: [], open: true, closes_at: '' };
    openModal(role ? 'Edit role' : 'New role',
      '<label class="field"><span class="field__label">Role name</span>' +
        '<input type="text" id="roleName" value="' + esc(r.role) + '"></label>' +
      '<label class="field"><span class="field__label">Summary</span>' +
        '<textarea id="roleSummary">' + esc(r.summary) + '</textarea></label>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Responsibilities (one per line)</span>' +
          '<textarea id="roleResp">' + esc((r.responsibilities || []).join('\n')) + '</textarea></label>' +
        '<label class="field"><span class="field__label">Requirements (one per line)</span>' +
          '<textarea id="roleReq">' + esc((r.requirements || []).join('\n')) + '</textarea></label>' +
      '</div>' +
      '<div class="form__row">' +
        '<label class="field"><span class="field__label">Open for applications</span>' +
          '<select id="roleOpen"><option value="1"' + (r.open ? ' selected' : '') + '>Yes</option>' +
          '<option value="0"' + (r.open ? '' : ' selected') + '>No</option></select></label>' +
        '<label class="field"><span class="field__label">Closes (ISO date, optional)</span>' +
          '<input type="text" id="roleCloses" value="' + esc(r.closes_at || '') + '" placeholder="2026-12-31T23:59:00+00:00"></label>' +
      '</div>' +
      '<label class="field"><span class="field__label">Questions (one per line: "label | type | required")</span>' +
        '<textarea id="roleQuestions">' + esc(questionsToText(r.questions)) + '</textarea>' +
        '<span class="field__hint">Types: text, textarea, email, url, number, select. For a select, ' +
          'add a fourth field with the choices separated by commas: ' +
          'Hours per week | select | required | 5, 10, 20</span></label>' +
      '<div class="form-error" id="roleErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
        '<button class="btn btn--primary" id="roleSave"><span>Save role</span></button></div>',
      function () {
        document.getElementById('roleSave').addEventListener('click', function () {
          var questions = document.getElementById('roleQuestions').value.split('\n')
            .map(function (line) { return line.trim(); })
            .filter(Boolean)
            .map(questionsFromLine);
          api('POST', 'panel/applications', {
            id: r.id,
            role: document.getElementById('roleName').value,
            summary: document.getElementById('roleSummary').value,
            responsibilities: document.getElementById('roleResp').value.split('\n'),
            requirements: document.getElementById('roleReq').value.split('\n'),
            open: document.getElementById('roleOpen').value === '1',
            closes_at: document.getElementById('roleCloses').value,
            questions: questions
          }).then(function () {
            closeModal();
            navigate('applications');
          }).catch(function (err) { showError('roleErr', err.message); });
        });
      });
  }

  /* Questions round-trip through one textarea as
     "label | type | required | option, option". The options field exists
     because a select question with no options renders an empty dropdown and
     can never be answered; without it, editing a role silently destroyed
     every choice list it had. */
  function questionsToText(questions) {
    return (questions || []).map(function (q) {
      return [
        q.label || '',
        q.type || 'text',
        q.required ? 'required' : 'optional',
        (q.options || []).join(', ')
      ].join(' | ');
    }).join('\n');
  }

  function questionsFromLine(line) {
    var parts = line.split('|').map(function (p) { return p.trim(); });
    var question = {
      label: parts[0] || '',
      type: (parts[1] || 'text').toLowerCase(),
      required: (parts[2] || '').toLowerCase() === 'required'
    };
    if (question.type === 'select') {
      question.options = (parts[3] || '').split(',')
        .map(function (o) { return o.trim(); })
        .filter(Boolean);
    }
    return question;
  }

  function viewApplication(ref) {
    api('GET', 'panel/route/applications').then(function (data) {
      var app = null;
      (data.applications || []).forEach(function (a) { if (a.ref === ref) app = a; });
      if (!app) { console.warn('no application with ref', ref); return; }
      openModal('Application ' + app.ref,
        '<p class="muted small">' + esc(app.role) + ' &middot; ' + esc(app.received || '') +
        ' &middot; delivery: ' + esc(app.delivery || 'unknown') + '</p>' +
        '<dl class="kv"><dt>Name</dt><dd>' + esc(app.name) + '</dd>' +
        '<dt>Email</dt><dd>' + esc(app.email) + '</dd></dl>' +
        (app.answers || []).map(function (a) {
          return '<div class="box" style="margin-top:.7rem"><div class="box__body">' +
            '<p class="muted small">' + esc(a.label) + '</p><p>' + esc(a.answer) + '</p>' +
            '</div></div>';
        }).join(''));
    });
  }

  /* The suggestion queue. The three numbers are at the top because the job of
     this screen is to work through what is waiting, not to browse a log. */
  var DECIDE_LABELS = { approved: 'we are doing it', denied: 'we are not doing this one' };

  function viewContactInbox(data) {
    var messages = data.messages || [];
    var canDecide = data.can_decide !== false;
    var pill = function (msg) {
      var status = msg.status || 'pending';
      var tone = status === 'approved' ? 'live' : status === 'denied' ? 'off' : 'warn';
      return '<span class="pill pill--' + tone + '">' + esc(status) + '</span>' +
        (msg.emailed === false && status !== 'pending'
          ? ' <span class="pill pill--warn" title="The decision was recorded but the email did not send">not sent</span>'
          : '');
    };

    var rows = messages.map(function (msg) {
      var body = String(msg.message || '');
      var preview = esc(body.slice(0, 90)) + (body.length > 90 ? '…' : '');
      var decide = (msg.status || 'pending') === 'pending' && canDecide
        ? '<div class="table__actions">' +
          '<button class="mini" data-decide="approved" data-ref="' + esc(msg.ref) + '">Approve</button>' +
          '<button class="mini mini--bad" data-decide="denied" data-ref="' + esc(msg.ref) + '">Deny</button>' +
          '</div>'
        : '<button class="btn btn--ghost" data-view-msg="' + esc(msg.ref) + '"><span>Open</span></button>';
      return '<tr><td class="mono">' + esc(msg.ref) + '</td>' +
        '<td><span class="pill pill--cyan">' + esc(msg.topic_label || msg.topic) + '</span></td>' +
        '<td>' + esc(msg.name) + '<br><span class="muted">' + esc(msg.email) + '</span></td>' +
        '<td class="muted">' + preview + '</td>' +
        '<td>' + pill(msg) + '</td>' +
        '<td>' + decide + '</td></tr>';
    }).join('');

    var counts = '<span class="pill pill--warn">' + (data.pending || 0) + ' waiting</span>' +
      '<span class="pill pill--live">' + (data.approved || 0) + ' approved</span>' +
      '<span class="pill pill--off">' + (data.denied || 0) + ' denied</span>' +
      (data.unreplied ? '<span class="pill pill--warn">' + data.unreplied + ' not emailed</span>' : '');

    return '<p class="page__desc">Suggestions from the site. Approving or denying ' +
        'writes to the person who sent it, in your words - the message is required, ' +
        'because a decision with no explanation is what makes somebody distrust the ' +
        'next one. ' + (canDecide ? '' :
        'Deciding needs the Management rank; you can still read the queue.') + '</p>' +
      '<div class="grid grid--4" style="margin-top:1.2rem">' +
        '<div class="box"><div class="box__body"><p class="stat__k">Waiting</p>' +
          '<p class="stat__v">' + (data.pending || 0) + '</p></div></div>' +
        '<div class="box"><div class="box__body"><p class="stat__k">Approved</p>' +
          '<p class="stat__v">' + (data.approved || 0) + '</p></div></div>' +
        '<div class="box"><div class="box__body"><p class="stat__k">Denied</p>' +
          '<p class="stat__v">' + (data.denied || 0) + '</p></div></div>' +
        '<div class="box"><div class="box__body"><p class="stat__k">Inbox</p>' +
          '<p class="stat__v">' + esc(data.address || 'not set') + '</p></div></div>' +
      '</div>' +
      '<div class="box" style="margin-top:1.2rem"><div class="box__head"><h3>Suggestions</h3>' +
        counts + '</div><div class="box__body box__body--flush">' +
        (rows
          ? '<table class="table"><thead><tr><th>Ref</th><th>Topic</th><th>Who</th><th>Suggestion</th>' +
            '<th>Decision</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
          : SI.empty('The box is empty',
              'Nothing has been sent from the suggestion box yet.')) +
      '</div></div>';
  }

  /* The message staff write back. Not optional, and not a template: the reason
     is the one thing a generated reply cannot do, and it is the whole value of
     saying yes or no rather than leaving somebody wondering. */
  function decideDialog(ref, decision) {
    var approved = decision === 'approved';
    openModal(approved ? 'Approve this suggestion' : 'Decline this suggestion',
      '<p class="muted small">This is emailed to whoever sent it, from the studio ' +
        'address. Write it as if a person will read it, because one will.</p>' +
      '<label class="field"><span class="field__label">Your message</span>' +
      '<textarea id="decideReply" rows="7" placeholder="' +
      (approved ? 'What you are going to do, and what it means for them.'
                : 'Why not, and what would change your mind.') +
      '"></textarea></label>' +
      '<div class="form-error" id="decideErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
      '<button class="btn btn--primary" id="decideSave"><span>' +
      (approved ? 'Approve and send' : 'Decline and send') + '</span></button></div>',
      function () {
        document.getElementById('decideSave').addEventListener('click', function () {
          var reply = document.getElementById('decideReply').value;
          if (reply.trim().length < 10) {
            showError('decideErr', 'Write at least a sentence. Ten characters.');
            return;
          }
          api('POST', 'panel/contact/decide',
              { ref: ref, decision: decision, reply: reply })
            .then(function (result) {
              closeModal();
              navigate('contact');
              if (result && result.emailed === false) {
                window.alert('The decision was saved, but the email did not send: ' +
                  ((result.delivery && result.delivery.error) || 'unknown') +
                  '\n\nSend the reply by hand; the decision is recorded.');
              }
            })
            .catch(function (err) { showError('decideErr', err.message); });
        });
      });
  }

  function viewMessage(ref) {
    api('GET', 'panel/route/contact').then(function (data) {
      var msg = null;
      (data.messages || []).forEach(function (m) { if (m.ref === ref) msg = m; });
      if (!msg) return;
      openModal(msg.topic_label || 'Message',
        '<p class="muted small">' + esc(msg.ref) + ' &middot; ' + esc(msg.received || '') +
        ' &middot; delivery: ' + esc(msg.delivery || 'unknown') + '</p>' +
        '<dl class="kv"><dt>From</dt><dd>' + esc(msg.name) + ' &lt;' + esc(msg.email) + '&gt;</dd></dl>' +
        '<div class="box" style="margin-top:.8rem"><div class="box__body"><p>' +
          esc(msg.message).replace(/\n/g, '<br>') + '</p></div></div>' +
        '<div class="form__row" style="margin-top:1rem">' +
          '<label class="field"><span class="field__label">Status</span>' +
            '<select id="msgStatus">' +
              ['new', 'replied', 'closed'].map(function (s) {
                return '<option value="' + s + '"' + (msg.status === s ? ' selected' : '') + '>' + s + '</option>';
              }).join('') +
            '</select></label>' +
          '<label class="check" style="align-self:end;padding-bottom:.6rem">' +
            '<input type="checkbox" id="msgRead"' + (msg.read ? ' checked' : '') + '><span>Read</span></label>' +
        '</div>' +
        '<div class="form-error" id="msgErr" hidden></div>' +
        '<div class="wizard__actions">' +
          '<button class="btn btn--ghost" id="msgDel"><span>Delete</span></button>' +
          '<button class="btn btn--primary" id="msgSave"><span>Save</span></button></div>',
        function () {
          document.getElementById('msgSave').addEventListener('click', function () {
            api('POST', 'panel/contact', {
              ref: msg.ref,
              status: document.getElementById('msgStatus').value,
              read: document.getElementById('msgRead').checked
            }).then(function () { closeModal(); navigate('contact'); })
              .catch(function (err) { showError('msgErr', err.message); });
          });
          document.getElementById('msgDel').addEventListener('click', function () {
            api('POST', 'panel/contact/delete', { ref: msg.ref }).then(function () {
              closeModal();
              navigate('contact');
            }).catch(function (err) { showError('msgErr', err.message); });
          });
        });
      api('POST', 'panel/contact', { ref: msg.ref, read: true }).catch(function () { });
    });
  }

  function viewStaffConfig(data) {
    var team = data.team || {};
    var groups = team.groups || [];
    var editable = groups.map(function (group, gi) {
      var members = (group.members || []).map(function (member, mi) {
        return '<div class="row" data-person="' + gi + '-' + mi + '" ' +
            'style="gap:.5rem;align-items:center;margin-bottom:.4rem">' +
          '<input type="text" data-field="name" value="' + esc(member.name) + '" placeholder="Name" style="flex:2">' +
          '<input type="text" data-field="title" value="' + esc(member.title || '') + '" placeholder="Title" style="flex:1">' +
          '<input type="text" data-field="avatar" value="' + esc(member.avatar || '') + '" placeholder="/assets/x.png" style="flex:2">' +
          '<input type="text" data-field="discord_id" value="' + esc(member.discord_id || '') + '" placeholder="Discord ID" style="flex:1;min-width:10rem">' +
          '<label class="check" style="white-space:nowrap"><input type="checkbox" data-field="has_login"' +
            (member.has_login ? ' checked' : '') + '><span>Login</span></label>' +
          '<button class="btn btn--ghost" data-rm-person="' + gi + '-' + mi + '"><span>Remove</span></button>' +
        '</div>';
      }).join('');
      /* The blurb is editable here rather than left implicit. team_replace
         rewrites every group it is given, so a form that did not send one
         blanked the tier descriptions the public Team page renders. */
      return '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>' + esc(group.key) + '</h3>' +
        '<span class="pill">' + (group.members || []).length + '</span>' +
        '<button class="btn btn--ghost" data-add-person="' + gi + '"><span>Add person</span></button></div>' +
        '<div class="box__body">' +
          '<label class="field"><span class="field__label">Tier description</span>' +
            '<input type="text" data-blurb value="' + esc(group.blurb || '') + '" maxlength="300"></label>' +
          (members || '<p class="muted small">Nobody in this tier yet.</p>') +
        '</div></div>';
    }).join('');

    return '<p class="page__desc">' + esc(data.hint || '') + '</p>' +
      '<div class="row row--between" style="margin-top:1rem">' +
        '<span class="muted small">Tier order: ' + esc((data.tiers || []).join(' › ')) + '</span>' +
        '<button class="btn btn--primary" id="saveRoster"><span>Save roster</span></button>' +
      '</div>' +
      '<div class="form-error" id="rosterErr" hidden></div>' +
      (editable || '<div class="empty-note"><b>No tiers defined</b></div>');
  }

  function viewPayments(data) {
    var orders = data.orders || [];
    var prov = data.providers || {};
    var rows = orders.map(function (order) {
      var items = (order.lines || []).map(function (l) { return esc(l.label); }).join(', ');
      return '<tr><td class="mono">' + esc(order.ref) + '</td>' +
        '<td>' + esc(items) + '</td>' +
        '<td class="mono">$' + Number(order.total || 0).toFixed(2) + '</td>' +
        '<td>' + esc(order.discord_name || '') + '<br><span class="muted mono">' + esc(order.discord_id || '') + '</span></td>' +
        '<td><span class="pill ' + (order.verification && order.verification.status === 'verified' ? 'pill--live' : 'pill--warn') + '">' +
          esc((order.verification && order.verification.status) || 'unknown') + '</span></td>' +
        '<td><span class="pill">' + esc(order.provider) + '</span><br><span class="muted small">' + esc(order.status) + '</span></td>' +
        '<td><button class="btn btn--ghost" data-order="' + esc(order.ref) + '"><span>Update</span></button></td></tr>';
    }).join('');

    return '<div class="box"><div class="box__head"><h3>Payments</h3>' +
        '<span class="pill pill--live">$' + Number(data.gross || 0).toFixed(2) + ' total</span></div>' +
      '<div class="box__body"><dl class="kv">' +
        '<dt>Stripe Checkout</dt><dd>' + (prov.stripe ? 'configured' : 'not configured') + '</dd>' +
      '</dl>' +
      (prov.stripe
        ? ''
        : '<p class="notice" style="margin-top:.8rem">No payment provider is connected, so orders are ' +
          'recorded and staff deliver by hand. Nothing is charged.</p>') +
      '</div></div>' +
      '<div class="box" style="margin-top:1rem"><div class="box__head"><h3>Orders</h3>' +
        '<span class="pill">' + orders.length + '</span></div><div class="box__body box__body--flush">' +
        (rows
          ? '<table class="table"><thead><tr><th>Ref</th><th>Items</th><th>Total</th><th>Buyer</th>' +
            '<th>ID check</th><th>Method</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
          : '<div class="empty-note"><b>No orders yet</b><span>They appear here the moment one is placed.</span></div>') +
      '</div></div>';
  }

  function orderDialog(ref, statuses) {
    openModal('Order ' + ref,
      '<label class="field"><span class="field__label">Status</span><select id="orderStatus">' +
        statuses.map(function (s) { return '<option value="' + esc(s) + '">' + esc(s) + '</option>'; }).join('') +
      '</select></label>' +
      '<label class="field"><span class="field__label">Note</span><textarea id="orderNote"></textarea></label>' +
      '<div class="form-error" id="orderErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
        '<button class="btn btn--primary" id="orderSave"><span>Save</span></button></div>',
      function () {
        document.getElementById('orderSave').addEventListener('click', function () {
          api('POST', 'panel/orders', {
            ref: ref,
            status: document.getElementById('orderStatus').value,
            note: document.getElementById('orderNote').value
          }).then(function () { closeModal(); navigate('payments'); })
            .catch(function (err) { showError('orderErr', err.message); });
        });
      });
  }

  /* ---------------------------------------------------------------- direct messages */

  /* The poll asks only for messages newer than the last one it rendered, and
     every append is de-duplicated by message id. Before that it refetched the
     whole thread every five seconds and appended all of it, so the box grew a
     full copy of the conversation every tick, a sent message appeared twice
     (once optimistically, once from the poll), and the unread badge re-fired on
     every poll that contained any inbound message at all. */
  var chat = { thread: null, poll: null, lastAt: 0, seen: {} };

  function stopChatPoll() {
    if (chat.poll) window.clearInterval(chat.poll);
    chat.poll = null;
  }

  function openThread(threadId) {
    stopChatPoll();
    chat.thread = threadId;
    chat.lastAt = 0;
    chat.seen = {};
    enableChatInput();
    return api('GET', 'panel/chat/thread/' + encodeURIComponent(threadId))
      .then(function (data) {
        renderThread(data);
        return api('POST', 'panel/chat/thread/' + encodeURIComponent(threadId) + '/read', {});
      })
      .then(function () {
        // Guarded: the operator may have navigated on while this was in flight,
        // and re-arming a poll for a thread that is no longer on screen is what
        // leaves a 5-second timer running over an unrelated page.
        if (chat.thread === threadId) pollThread();
      })
      .catch(function (err) {
        stopChatPoll();
        console.warn(err.message);
      });
  }

  function pollThread() {
    stopChatPoll();
    var threadId = chat.thread;
    if (!threadId) return;
    chat.poll = window.setInterval(function () {
      if (!chat.thread || document.hidden) return;
      api('GET', 'panel/chat/thread/' + encodeURIComponent(threadId) + '?since=' + chat.lastAt)
        .then(function (data) {
          if (chat.thread !== threadId) return;
          var fresh = (data.messages || []).filter(function (m) { return !m.mine; });
          if (!fresh.length) return;
          (data.messages || []).forEach(appendMessage);
          chat.lastAt = data.latest_at || chat.lastAt;
          scrollChat(true);
          bumpUnread(threadLabel(data));
        })
        .catch(function () { /* transient */ });
    }, 5000);
  }

  function threadLabel(data) {
    return (data.with && data.with.username) || 'conversation';
  }

  function renderThread(data) {
    var box = SI.$('#chatBox');
    if (!box) return;
    box.innerHTML = '';
    if (!data.messages.length) {
      box.innerHTML = '<div class="empty-note">' +
        '<b>No messages yet</b><span>Say hello to ' +
        esc(threadLabel(data)) + '.</span></div>';
    }
    data.messages.forEach(appendMessage);
    scrollChat(false);
  }

  function appendMessage(message) {
    var box = SI.$('#chatBox');
    if (!box) return;
    var placeholder = box.querySelector('.empty');
    if (placeholder) box.innerHTML = '';

    /* A message sent locally is rendered as soon as the POST returns, and the
       next poll sends the server's copy of the same row back. Keying on the
       server's own id is what keeps the two from both landing in the box. */
    if (message.id) {
      if (chat.seen[message.id]) return;
      chat.seen[message.id] = true;
    }
    if (message.at) chat.lastAt = Math.max(chat.lastAt, Number(message.at) || 0);

    var node = document.createElement('div');
    node.className = 'msg' + (message.mine ? ' msg--me' : '');
    if (message.id) node.setAttribute('data-msg', message.id);
    node.innerHTML =
      '<div class="msg__who">' +
        (message.pfp ? '<img class="msg__pfp" src="' + esc(message.pfp) + '" alt="">' : '') +
        '<span>' + esc(message.username) + '</span>' +
        '<small>' + esc(message.staff_id) + '</small>' +
      '</div>' +
      '<div class="msg__text">' + esc(message.body) + '</div>' +
      '<div class="msg__time">' + esc(message.rank_label || '') + ' &middot; ' +
        esc(new Date((message.at || 0) * 1000).toLocaleString()) + '</div>';
    box.appendChild(node);
  }

  function scrollChat(stick) {
    var box = SI.$('#chatBox');
    if (!box) return;
    var nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
    if (stick && !nearBottom) return;
    box.scrollTop = box.scrollHeight;
  }

  function bumpUnread(label) {
    var pill = SI.$('#botPill');
    if (pill) pill.title = 'New message from ' + label;
  }

  function sendChat(event) {
    event.preventDefault();
    var input = SI.$('#chatInput');
    if (!input || !chat.thread) return;
    var body = input.value.trim();
    if (!body) return;
    input.value = '';
    api('POST', 'panel/chat/thread/' + encodeURIComponent(chat.thread), { body: body })
      .then(function (message) { appendMessage(message); scrollChat(false); })
      .catch(function (err) { input.value = body; console.warn(err.message); });
  }

  function viewChat(data) {
    var threads = data.threads || [];
    var people = data.people || [];
    return '<div class="chat-layout" style="margin-top:1.2rem">' +
      '<aside class="chat-list">' +
        '<div class="chat-list__head">Conversations</div>' +
        (threads.length ? threads.map(function (t) {
          return '<button class="chat-item" type="button" data-thread="' + esc(t.id) + '">' +
            '<span class="chat-item__who">' + esc(t.with.username) + '</span>' +
            '<span class="chat-item__preview">' + esc(t.last_body || 'No messages yet') + '</span>' +
            (t.unread ? '<span class="side__tag side__tag--new">' + t.unread + '</span>' : '') +
          '</button>';
        }).join('') : '<p class="chat-list__empty">No conversations yet.</p>') +
        (people.length ? '<div class="chat-list__head">Start one</div>' +
          people.map(function (p) {
            return '<button class="chat-item chat-item--new" type="button" data-start="' +
              esc(p.id) + '"><span class="chat-item__who">' + esc(p.username) + '</span>' +
              '<span class="chat-item__preview">' + esc(p.rank_label) + '</span></button>';
          }).join('') : '<p class="chat-list__empty">No other staff have a login yet.</p>') +
      '</aside>' +
      '<section class="chat-main">' +
        '<div class="chatbox" id="chatBox"></div>' +
        '<form class="chat-compose" id="chatForm">' +
          '<input type="text" id="chatInput" placeholder="Select a conversation" disabled>' +
          '<button class="btn btn--primary" type="submit" disabled><span>Send</span></button>' +
        '</form>' +
      '</section>' +
    '</div>';
  }

  function wireChat(data) {
    stopChatPoll();
    chat.thread = null;
    var threads = data.threads || [];
    if (threads.length) { openThread(threads[0].id); }

    SI.$$('[data-thread]').forEach(function (node) {
      node.addEventListener('click', function () { openThread(node.getAttribute('data-thread')); });
    });
    SI.$$('[data-start]').forEach(function (node) {
      node.addEventListener('click', function () {
        /* Navigate rather than open the thread here as well. The thread list is
           ordered newest activity first, so re-rendering the section opens the
           one just created. Opening it here too started two overlapping fetch
           chains, and whichever lost cleared the other's poll and left the box
           showing the wrong conversation. */
        api('POST', 'panel/chat/threads', { staff_id: node.getAttribute('data-start') })
          .then(function () { navigate('chat'); })
          .catch(function (err) { console.warn(err.message); });
      });
    });
    var form = SI.$('#chatForm');
    if (form) form.addEventListener('submit', sendChat);
  }

  function enableChatInput() {
    var input = SI.$('#chatInput');
    var button = SI.$('#chatForm button');
    if (input) input.disabled = false;
    if (button) button.disabled = false;
  }

  /* ---------------------------------------------------------------- AI chat */

  function viewAiChat(data) {
    if (data.embedded) {
      return '<div class="box" style="margin-top:1.2rem"><div class="box__head">' +
        '<h3>AI Chat</h3><span class="pill pill--cyan">Thunderbolt</span></div>' +
        '<div class="box__body"><p class="page__desc" style="margin-top:0">Open the staff AI chat in the Thunderbolt widget.</p>' +
        '<div class="form-error" id="thunderboltError" hidden></div>' +
        '<button class="btn btn--primary" id="btnThunderboltOpen" type="button"><span>Open AI chat</span></button>' +
        '</div></div>';
    }
    var provider = data.provider_status || {};
    var messages = data.messages || [];
    return '<div class="box" style="margin-top:1.2rem">' +
      '<div class="box__head"><h3>AI Chat</h3>' +
        '<span class="pill pill--cyan">' + esc(data.provider) + '</span>' +
        '<span class="pill ' + (provider.configured ? 'pill--live' : 'pill--warn') + '">' +
          (provider.configured ? 'connected' : 'not configured') + '</span>' +
        (provider.model ? '<span class="pill pill--off">' + esc(provider.model) + '</span>' : '') +
      '</div>' +
      '<div class="box__body">' +
        '<p class="page__desc" style="margin-bottom:.9rem">Prompts are sent from this server to ' +
        esc(data.provider) + ' and the reply comes back through it. No third-party code runs in ' +
        'the panel and the provider never sees your session.</p>' +
        (provider.configured ? '' :
          '<div class="hint hint--warn" style="margin-bottom:.9rem"><span class="hint__icon">!</span>' +
          '<span>' + esc(provider.hint || 'The provider is not configured yet.') +
          ' Add <code>AI_CHAT_URL</code>, <code>AI_CHAT_KEY</code> and <code>AI_CHAT_MODEL</code> ' +
          'to <code>Core/.env</code>, then restart.</span></div>') +
        '<div class="chatbox" id="aiBox">' + renderAiHistory(messages) + '</div>' +
        '<form class="chat-compose" id="aiForm">' +
          '<input type="text" id="aiInput" placeholder="Ask the assistant&hellip;"' +
            (provider.configured ? '' : ' disabled') + '>' +
          '<button class="btn btn--primary" type="submit"' +
            (provider.configured ? '' : ' disabled') + '><span>Send</span></button>' +
        '</form>' +
      '</div></div>';
  }

  function renderAiHistory(messages) {
    if (!messages || !messages.length) {
      return '<div class="empty-note"><b>No questions yet</b>' +
        '<span>Ask something about the studio, a game, or a ticket.</span></div>';
    }
    return messages.map(function (m) {
      var mine = m.role === 'user';
      return '<div class="msg' + (mine ? ' msg--me' : '') + '">' +
        '<div class="msg__who"><span>' + (mine ? 'You' : 'Assistant') + '</span></div>' +
        '<div class="msg__text">' + esc(m.content || '') + '</div></div>';
    }).join('');
  }

  function wireAiChat(data) {
    if (data.embedded) {
      var button = document.getElementById('btnThunderboltOpen');
      var error = document.getElementById('thunderboltError');
      if (!button) return;
      function openWidget() {
        if (window.Thunderbolt && typeof window.Thunderbolt.open === 'function') {
          window.Thunderbolt.open();
          return;
        }
        var script = document.querySelector('script[data-si-thunderbolt]');
        if (script) {
          if (script.dataset.loaded === '1' && window.Thunderbolt) {
            window.Thunderbolt.open();
          } else if (!script.dataset.loading) {
            script.dataset.loading = '1';
            script.addEventListener('load', function () {
              script.dataset.loaded = '1';
              if (window.Thunderbolt && window.Thunderbolt.open) window.Thunderbolt.open();
              else if (error) { error.textContent = 'Thunderbolt did not initialize.'; error.hidden = false; }
            }, { once: true });
          }
          return;
        }
        script = document.createElement('script');
        script.src = 'https://www.thunderbolt.com/gateway/api/v1/thunderbolt-ui/embed.js';
        script.async = true;
        script.dataset.hostLauncher = 'true';
        script.dataset.siThunderbolt = '1';
        script.addEventListener('load', function () {
          script.dataset.loaded = '1';
          if (window.Thunderbolt && window.Thunderbolt.open) window.Thunderbolt.open();
          else if (error) { error.textContent = 'Thunderbolt did not initialize.'; error.hidden = false; }
        }, { once: true });
        script.addEventListener('error', function () {
          if (error) { error.textContent = 'Could not load Thunderbolt. Check the connection and try again.'; error.hidden = false; }
        }, { once: true });
        document.head.appendChild(script);
      }
      button.addEventListener('click', openWidget);
      return;
    }
    var box = SI.$('#aiBox');
    if (box) box.scrollTop = box.scrollHeight;
    var form = SI.$('#aiForm');
    if (!form) return;
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var input = SI.$('#aiInput');
      var text = input.value.trim();
      if (!text) return;
      input.value = '';
      var placeholder = box.querySelector('.empty');
      if (placeholder) box.innerHTML = '';
      box.insertAdjacentHTML('beforeend',
        '<div class="msg msg--me"><div class="msg__who"><span>You</span></div>' +
        '<div class="msg__text">' + esc(text) + '</div></div>');
      box.insertAdjacentHTML('beforeend',
        '<div class="msg msg--ai" id="aiPending"><div class="msg__who"><span>Assistant</span></div>' +
        '<div class="msg__text">Thinking&hellip;</div></div>');
      box.scrollTop = box.scrollHeight;

      api('POST', 'panel/ai/chat', { message: text })
        .then(function (result) {
          var pending = SI.$('#aiPending');
          if (pending) {
            pending.querySelector('.msg__text').textContent = result.reply;
            box.scrollTop = box.scrollHeight;
          }
        })
        .catch(function (err) {
          var pending = SI.$('#aiPending');
          if (pending) {
            pending.classList.add('msg--bad');
            pending.querySelector('.msg__text').textContent = err.message;
          }
        });
    });
  }

  /* ---------------------------------------------------------------- modal */

  function openModal(title, html, onMount) {
    el.modalTitle.textContent = title;
    el.modalBody.innerHTML = html;
    el.modal.hidden = false;
    if (onMount) onMount(el.modalBody);
  }

  function closeModal() {
    if (el.modal) el.modal.hidden = true;
  }

  document.addEventListener('click', function (event) {
    if (event.target.closest('#btnNewLogin')) { newLoginDialog(); return; }
    var rankBtn = event.target.closest('[data-act="rank"]');
    if (rankBtn) { rankDialog(rankBtn.getAttribute('data-id')); return; }
    var removeBtn = event.target.closest('[data-act="remove"]');
    if (removeBtn) { removeDialog(removeBtn.getAttribute('data-id')); return; }
    var delBtn = event.target.closest('[data-del-ann]');
    if (delBtn) { deleteAnnouncement(delBtn.getAttribute('data-del-ann')); return; }
    var editAnn = event.target.closest('[data-edit-ann]');
    if (editAnn) {
      var wantedAnn = editAnn.getAttribute('data-edit-ann');
      ((state.view && state.view.announcements) || []).forEach(function (a) {
        if (a.id === wantedAnn) announcementDialog(a, state.view.kind, state.view.audiences);
      });
      return;
    }
    if (event.target.id === 'btnNewAnn') {
      announcementDialog(null, (state.view && state.view.kind) || 'public',
        (state.view && state.view.audiences) || null);
      return;
    }
    if (event.target.id === 'btnNewRecord') { recordDialog(null); return; }
    var editRec = event.target.closest('[data-rec-edit]');
    if (editRec) {
      var wantedRec = editRec.getAttribute('data-rec-edit');
      ((state.view && state.view.records) || []).forEach(function (r) {
        if (r.id === wantedRec) recordDialog(r);
      });
      return;
    }
    var delRec = event.target.closest('[data-rec-del]');
    if (delRec) {
      if (!window.confirm('Delete this record? It cannot be recovered.')) return;
      api('POST', 'panel/mod/delete', {
        section: (state.view && state.view.kind) || '',
        id: delRec.getAttribute('data-rec-del')
      }).then(function () { navigate(state.route); })
        .catch(function (err) { window.alert(err.message); });
      return;
    }
    var goto = event.target.closest('[data-goto]');
    if (goto) { navigate(goto.getAttribute('data-goto')); return; }
    var decide = event.target.closest('[data-decide]');
    if (decide) {
      decideDialog(decide.getAttribute('data-ref'), decide.getAttribute('data-decide'));
      return;
    }
    if (event.target.id === 'cfgSave') {
      var cfgPayload = {};
      ((state.view && state.view.fields) || []).forEach(function (f) {
        var node = document.getElementById('rec_' + f.key);
        if (node) cfgPayload[f.key] = node.value;
      });
      api('POST', 'panel/mod/config', cfgPayload)
        .then(function () { navigate(state.route); })
        .catch(function (err) { showError('cfgErr', err.message); });
      return;
    }

    if (event.target.id === 'btnNewRole') { roleDialog(null); return; }
    var editRole = event.target.closest('[data-edit-role]');
    if (editRole) {
      var wanted = editRole.getAttribute('data-edit-role');
      api('GET', 'panel/route/applications').then(function (data) {
        (data.roles || []).forEach(function (r) { if (r.id === wanted) roleDialog(r); });
      });
      return;
    }
    var delRole = event.target.closest('[data-del-role]');
    if (delRole) {
      var roleId = delRole.getAttribute('data-del-role');
      if (!window.confirm('Delete this role? Applications already received are kept.')) return;
      api('POST', 'panel/applications/delete', { id: roleId })
        .then(function () { navigate('applications'); })
        .catch(function (err) { showError('roleErr', err.message); });
      return;
    }
    var viewApp = event.target.closest('[data-view-app]');
    if (viewApp) { viewApplication(viewApp.getAttribute('data-view-app')); return; }

    var viewMsg = event.target.closest('[data-view-msg]');
    if (viewMsg) { viewMessage(viewMsg.getAttribute('data-view-msg')); return; }

    var orderBtn = event.target.closest('[data-order]');
    if (orderBtn) {
      var ref = orderBtn.getAttribute('data-order');
      var statuses = ['pending_manual', 'awaiting_payment', 'paid', 'fulfillment_pending', 'delivered', 'cancelled'];
      orderDialog(ref, statuses);
      return;
    }

    var addPerson = event.target.closest('[data-add-person]');
    if (addPerson) {
      var gi = addPerson.getAttribute('data-add-person');
      var box = addPerson.closest('.box');
      var list = box && box.querySelector('.box__body');
      if (list) {
        var row = document.createElement('div');
        row.className = 'row';
        row.style.cssText = 'gap:.5rem;align-items:center;margin-bottom:.4rem';
        row.innerHTML =
          '<input type="text" data-field="name" placeholder="Name" style="flex:2">' +
          '<input type="text" data-field="title" placeholder="Title" style="flex:1">' +
          '<input type="text" data-field="avatar" placeholder="/assets/x.png" style="flex:2">' +
          '<input type="text" data-field="discord_id" placeholder="Discord ID" style="flex:1;min-width:10rem">' +
          '<label class="check" style="white-space:nowrap"><input type="checkbox" data-field="has_login"><span>Login</span></label>' +
          '<button class="btn btn--ghost" data-rm-person="new-' + gi + '"><span>Remove</span></button>';
        list.appendChild(row);
      }
      return;
    }
    var rmPerson = event.target.closest('[data-rm-person]');
    if (rmPerson) {
      var row = rmPerson.closest('.row');
      if (row) row.remove();
      return;
    }
    if (event.target.id === 'saveRoster') { saveRoster(); return; }

    /* --- Web Configuration: the games registry and the featured block --- */
    if (event.target.id === 'btnNewGame') { gameDialog(null); return; }
    if (event.target.id === 'btnEditFeatured') { featuredDialog(); return; }
    var editGame = event.target.closest('[data-edit-game]');
    if (editGame) {
      var gameId = editGame.getAttribute('data-edit-game');
      (state.webConfigGames || []).forEach(function (g) {
        if (String(g.id) === gameId) gameDialog(g);
      });
      return;
    }
    var featureGame = event.target.closest('[data-feature-game]');
    if (featureGame) {
      var chosen = null;
      var wantedId = featureGame.getAttribute('data-feature-game');
      (state.webConfigGames || []).forEach(function (g) { if (String(g.id) === wantedId) chosen = g; });
      if (!chosen) return;
      featureGame.disabled = true;
      /* One deliberate action: flag the game in the registry *and* copy it
         into the home page's featured block, so the two can never disagree. */
      api('PATCH', 'panel/games/' + encodeURIComponent(chosen.id), { featured: true })
        .then(function () {
          return api('POST', 'panel/featured', {
            title: chosen.title, status: chosen.status, thumb: chosen.thumb,
            description: chosen.description, url: chosen.url, discord: chosen.discord,
            release_estimate: chosen.release_estimate
          });
        })
        .then(function () { navigate('web-config'); })
        .catch(function (err) { featureGame.disabled = false; showError('webConfigErr', err.message); });
      return;
    }
    var delGame = event.target.closest('[data-del-game]');
    if (delGame) {
      var delId = delGame.getAttribute('data-del-game');
      if (!window.confirm('Remove this game from the registry?')) return;
      api('POST', 'panel/games/delete', { id: delId })
        .then(function () { navigate('web-config'); })
        .catch(function (err) { showError('webConfigErr', err.message); });
      return;
    }

    if (event.target.id === 'btnNewDiscount') { discountDialog(null); return; }
    var editDiscount = event.target.closest('[data-edit-discount]');
    if (editDiscount) {
      var wanted = editDiscount.getAttribute('data-edit-discount');
      (state.discounts || []).forEach(function (d) {
        if (String(d.id) === wanted) discountDialog(d);
      });
    }
  });

  function saveRoster() {
    showError('rosterErr', '');
    api('POST', 'panel/team', { groups: readRoster() })
      .then(function () { navigate('staff-config'); })
      .catch(function (err) { showError('rosterErr', err.message); });
  }

  function readRoster() {
    /* Read the editable rows back into the grouped shape the API expects. Each
       tier is a .box whose heading names it, and the rows inside it are people.
       Only the .row person editors are read, so the blurb field above them and
       the "nobody here yet" note below can never be mistaken for a member. */
    var groups = [];
    SI.$$('.box', el.canvas).forEach(function (box) {
      var head = box.querySelector('.box__head h3');
      var body = box.querySelector('.box__body');
      if (!head || !body) return;
      var blurb = box.querySelector('[data-blurb]');
      var members = SI.$$('.row', body).map(function (row) {
        var get = function (name) {
          var input = row.querySelector('[data-field="' + name + '"]');
          return input ? input.value.trim() : '';
        };
        var login = row.querySelector('[data-field="has_login"]');
        return {
          name: get('name'),
          title: get('title'),
          avatar: get('avatar'),
          discord_id: get('discord_id'),
          has_login: !!(login && login.checked)
        };
      }).filter(function (m) { return m.name; });
      groups.push({
        key: head.textContent.trim(),
        blurb: blurb ? blurb.value.trim() : '',
        members: members
      });
    });
    return groups;
  }

  function tierOptions(selected) {
    return (state.registry.tiers || []).map(function (tier) {
      return '<option value="' + esc(tier) + '"' + (tier === selected ? ' selected' : '') + '>' + esc(tier) + '</option>';
    }).join('');
  }

  function rankDialog(staffId) {
    var target = ((state.view && state.view.staff) || []).filter(function (member) {
      return String(member.id) === String(staffId);
    })[0] || {};
    openModal('Change rank',
      '<label class="field"><span class="field__label">New rank</span>' +
        '<select id="rankTier">' + tierOptions(target.tier || '') + '</select></label>' +
      '<label class="field"><span class="field__label">Title (optional)</span>' +
        '<input type="text" id="rankTitle" value="' + esc(target.title || '') + '" placeholder="Moderator, Developer, Lead&hellip;"></label>' +
      '<label class="field"><span class="field__label">Linked Discord user ID</span>' +
        '<input type="text" id="rankDiscordId" value="' + esc(target.discord_id || '') + '" inputmode="numeric" maxlength="20"></label>' +
      '<label class="check"><input type="checkbox" id="rankActive"' + (target.active ? ' checked' : '') + '><span>Account stays active</span></label>' +
      '<div class="form-error" id="rankErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
      '<button class="btn btn--primary" id="rankSave"><span>Save</span></button></div>',
      function () {
        document.getElementById('rankSave').addEventListener('click', function () {
          api('PATCH', 'panel/logins/' + encodeURIComponent(staffId), {
            tier: document.getElementById('rankTier').value,
            title: document.getElementById('rankTitle').value,
            discord_id: document.getElementById('rankDiscordId').value.trim(),
            active: document.getElementById('rankActive').checked
          }).then(function () {
            closeModal();
            navigate('logins-management');
          }).catch(function (err) { showError('rankErr', err.message); });
        });
      });
  }

  function newLoginDialog() {
    openModal('Create staff login',
      '<label class="field"><span class="field__label">Username</span><input id="newLoginName" maxlength="64"></label>' +
      '<label class="field"><span class="field__label">Email</span><input id="newLoginEmail" type="email" maxlength="254"></label>' +
      '<div class="form__row"><label class="field"><span class="field__label">Rank</span>' +
        '<select id="newLoginTier">' + tierOptions('Staff') + '</select></label>' +
        '<label class="field"><span class="field__label">Title</span><input id="newLoginTitle" maxlength="80"></label></div>' +
      '<label class="field"><span class="field__label">Linked Discord user ID</span>' +
        '<input id="newLoginDiscordId" inputmode="numeric" maxlength="20"></label>' +
      '<label class="field"><span class="field__label">Temporary password</span>' +
        '<input id="newLoginPassword" type="password" autocomplete="new-password"></label>' +
      '<p class="muted small">Share the temporary password separately. Email verification is required at sign-in.</p>' +
      '<div class="form-error" id="newLoginErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
        '<button class="btn btn--primary" id="newLoginSave"><span>Create login</span></button></div>',
      function () {
        document.getElementById('newLoginSave').addEventListener('click', function () {
          api('POST', 'panel/logins', {
            username: document.getElementById('newLoginName').value,
            email: document.getElementById('newLoginEmail').value,
            tier: document.getElementById('newLoginTier').value,
            title: document.getElementById('newLoginTitle').value,
            discord_id: document.getElementById('newLoginDiscordId').value.trim(),
            password: document.getElementById('newLoginPassword').value
          }).then(function () {
            closeModal();
            navigate('logins-management');
          }).catch(function (err) { showError('newLoginErr', err.message); });
        });
      });
  }

  function removeDialog(staffId) {
    openModal('Remove staff login',
      '<p class="page__desc">This deactivates the login and ends every active session for it.</p>' +
      '<div class="form-error" id="rmErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
      '<button class="btn btn--primary" id="rmGo"><span>Remove</span></button></div>',
      function () {
        document.getElementById('rmGo').addEventListener('click', function () {
          api('DELETE', 'panel/logins/' + encodeURIComponent(staffId), {}).then(function () {
            closeModal();
            navigate('logins-management');
          }).catch(function (err) { showError('rmErr', err.message); });
        });
      });
  }

  function announcementDialog(existing, audience, list) {
    var a = existing || { title: '', body: '', tag: 'Update' };
    var target = audience || 'public';
    var label = ({ public: 'web', staff: 'staff', dev: 'development' })[target] || target;
    openModal((existing ? 'Edit ' : 'New ') + label + ' announcement',
      '<label class="field"><span class="field__label">Title</span>' +
        '<input type="text" id="annTitle" maxlength="120" value="' + esc(a.title) + '"></label>' +
      '<label class="field"><span class="field__label">Body</span>' +
        '<textarea id="annBody" maxlength="2000">' + esc(a.body) + '</textarea></label>' +
      '<label class="field"><span class="field__label">Tag</span>' +
        '<input type="text" id="annTag" value="' + esc(a.tag) + '" maxlength="32"></label>' +
      (existing ? '' :
        '<label class="field"><span class="field__label">Audience</span>' +
        '<select id="annAudience">' +
        (list || [{ id: 'public' }]).map(function (o) {
          return '<option value="' + esc(o.id) + '"' + (target === o.id ? ' selected' : '') +
            '>' + esc(o.label) + '</option>';
        }).join('') + '</select></label>') +
      '<p class="muted small">Web announcements appear on the public site. The other two are ' +
        'read here in the panel and nowhere else.</p>' +
      '<div class="form-error" id="annErr" hidden></div>' +
      '<div class="wizard__actions"><button class="btn btn--ghost" data-close><span>Cancel</span></button>' +
      '<button class="btn btn--primary" id="annSave"><span>Publish</span></button></div>',
      function () {
        document.getElementById('annSave').addEventListener('click', function () {
          var payload = {
            title: document.getElementById('annTitle').value,
            body: document.getElementById('annBody').value,
            tag: document.getElementById('annTag').value,
            audience: (document.getElementById('annAudience') || {}).value || target
          };
          var call = existing
            ? api('POST', 'panel/announcements/update',
                Object.assign({ id: existing.id }, payload))
            : api('POST', 'panel/announcements', payload);
          call.then(function () {
            closeModal();
            navigate(state.route);
          }).catch(function (err) { showError('annErr', err.message); });
        });
      });
  }

  function deleteAnnouncement(id) {
    api('POST', 'panel/announcements/delete', { id: id })
      .then(function () { navigate(state.route); })
      .catch(function (err) { console.warn(err.message); });
  }

  /* ---------------------------------------------------------------- util */

  /* fx.js loads before this file and owns the one copy of the HTML escaper. */
  var esc = SI.esc;

  /* No panel behind this page.

     ``detached`` means we got here because the API answered with HTML rather
     than JSON - an editor's tunnel, a file server, or a proxy that is serving
     the project's files but not the application. That is a different situation
     from the static build and deserves a different sentence: nobody can fix it
     by reading the docs, they have to open a different address.
  */
  function noBackend(detached) {
    var view = el.loginView || document.getElementById('loginView');
    if (!view) return;
    if (detached) {
      view.innerHTML =
        '<div class="wizard">' +
          '<span class="wizard__mark"><img src="/assets/Cube.png" alt=""></span>' +
          '<h1 class="wizard__title"><span data-glitch="PHP Hosting Required">PHP Hosting Required</span></h1>' +
          '<p class="wizard__sub">This address is serving static files. Five Server does not run PHP or connect to the website database, so staff sign-in is unavailable here.</p>' +
          '<p class="wizard__sub">After the PHP site is installed on Spaceship, open the staff panel at ' +
            '<a class="mono" href="https://stitchlineinteractivegames.com/staff">stitchlineinteractivegames.com/staff</a>.</p>' +
          '<p class="muted small">If that address still shows this message, the PHP API has not been installed or its rewrite rules are not active.</p>' +
        '</div>';
      SI.bindGlitch(view);
      return;
    }
    /* The Discord invite comes from the payload that is already in the page, so
       it is the same link the header uses and it needs no request: on a static
       build SI.api.get answers from the inlined copy. */
    SI.api.get('/api/site').then(function (site) {
      render((site && site.socials && site.socials.discord) || '');
    }, function () { render(''); });

    function render(discord) {
      view.innerHTML =
        '<div class="wizard">' +
        '<span class="wizard__mark"><img src="/assets/Cube.png" alt=""></span>' +
        '<h1 class="wizard__title"><span data-glitch="Staff Access">Staff Access</span></h1>' +
        '<p class="wizard__sub">The studio panel is not on this build of the site. ' +
        'It runs on the studio server, which is where every login is checked &mdash; ' +
        'there is nothing to sign in to here.</p>' +
        (discord
          ? '<div class="wizard__actions"><a class="btn btn--primary" href="' +
            esc(discord) + '" rel="noopener noreferrer" target="_blank">' +
            '<span>Reach the studio on Discord</span></a></div>'
          : '') +
        '</div>';
      SI.bindGlitch(view);
    }
  }
})(window, document);
