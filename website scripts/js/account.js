(function (window, document) {
  'use strict';

  var pending = '';
  var csrf = '';
  var staffLink = false;
  var notice = document.getElementById('accountNotice');
  var connect = document.getElementById('accountConnect');
  var setup = document.getElementById('accountSetup');
  var verify = document.getElementById('accountVerify');
  var dashboard = document.getElementById('accountDashboard');

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char];
    });
  }

  function request(path, method, body) {
    var headers = {'Accept':'application/json'};
    if (body) headers['Content-Type'] = 'application/json';
    if (csrf) headers['X-CSRF-Token'] = csrf;
    return fetch('/api/account/' + path, {
      method: method || 'GET', credentials: 'same-origin', headers: headers,
      body: body ? JSON.stringify(body) : undefined
    }).then(function (response) {
      return response.text().then(function (text) {
        var data;
        try { data = JSON.parse(text); } catch (err) { data = {}; }
        if (!response.ok) {
          var error = new Error(data.message || 'The account request failed.');
          error.status = response.status;
          throw error;
        }
        return data;
      });
    });
  }

  function message(text, isError) {
    notice.textContent = text || '';
    notice.classList.toggle('is-error', !!isError);
  }

  function showOnly(section) {
    connect.hidden = section !== 'connect';
    setup.hidden = section !== 'setup';
    verify.hidden = section !== 'verify';
    dashboard.hidden = section !== 'dashboard';
  }

  function disableConnect(messageText) {
    var link = document.getElementById('connectDiscord');
    link.removeAttribute('href');
    link.setAttribute('aria-disabled', 'true');
    link.tabIndex = -1;
    message(messageText, true);
  }

  function item(title, meta, body, action) {
    return '<article class="account-item"><div class="account-item__title">' + esc(title) + '</div>' +
      '<div class="account-item__body">' + (body ? esc(body) : '') + '</div>' +
      '<div class="account-item__meta">' + esc(meta || '') + '</div>' +
      (action || '') + '</article>';
  }

  function details(rows) {
    return rows.map(function (row) {
      return '<dt>' + esc(row[0]) + '</dt><dd>' + esc(row[1]) + '</dd>';
    }).join('');
  }

  function renderDashboard(data) {
    csrf = data.csrf || csrf;
    document.getElementById('accountIdentity').textContent = data.discord.username || 'Discord account';
    document.getElementById('accountDiscordId').textContent = data.discord.id || '';
    var avatar = document.getElementById('accountAvatar');
    if (avatar) avatar.src = data.discord.avatar_url || '';
    var robloxStatus = document.getElementById('robloxStatus');
    var robloxLink = document.getElementById('connectRoblox');
    if (data.roblox) {
      robloxStatus.textContent = 'Linked Roblox account: ' + data.roblox.username + '.';
      robloxLink.hidden = true;
    } else if (data.roblox_configured) {
      robloxStatus.textContent = 'Link a Roblox account to use staff tools.';
      robloxLink.href = '/api/account/roblox/connect';
      robloxLink.removeAttribute('aria-disabled');
      robloxLink.tabIndex = 0;
      robloxLink.hidden = false;
    } else {
      robloxStatus.textContent = 'Roblox verification is not configured on this host yet.';
      robloxLink.removeAttribute('href');
      robloxLink.setAttribute('aria-disabled', 'true');
      robloxLink.tabIndex = -1;
      robloxLink.hidden = false;
    }
    document.getElementById('securityDetails').innerHTML = details([
      ['Email', data.security.email || 'Not connected'],
      ['Email verification', data.security.email_verified ? 'Verified' : 'Not verified']
    ]);

    var staffSection = document.getElementById('staffSection');
    staffSection.hidden = !data.staff;
    document.getElementById('openStaffPanel').hidden = !data.staff || !data.roblox;
    if (data.staff) {
      document.getElementById('staffDetails').innerHTML = details([
        ['Rank', data.staff.rank], ['Staff ID', data.staff.id], ['Staff email', data.staff.email]
      ]);
    }

    var methods = data.payment_methods || [];
    document.getElementById('paymentMethods').innerHTML = methods.length
      ? methods.map(function (method) {
        return '<li>' + esc(method.name) + ' · ' + (method.configured ? 'available' : 'setup required') + '</li>';
      }).join('')
      : '<li>No payment methods are configured.</li>';
    var savedButton = document.getElementById('manageSavedPayments');
    if (savedButton) savedButton.disabled = !data.has_saved_payment_methods;
    var savedHint = document.getElementById('savedPaymentsHint');
    if (savedHint && !data.has_saved_payment_methods) {
      savedHint.textContent = 'Complete a Stripe Checkout purchase and choose to save a method; it will appear in Stripe Billing Portal.';
    }

    var orders = data.orders || [];
    document.getElementById('accountOrders').innerHTML = orders.length
      ? orders.map(function (order) {
        var items = (order.items || []).map(function (line) { return line.label || line.name || ''; }).join(', ');
        return item(order.reference, order.status + ' · ' + order.provider + ' · $' + Number(order.total || 0).toFixed(2), items, '');
      }).join('') : '<p class="account-empty">No orders are linked to this Discord account.</p>';

    var appeals = data.appeals || [];
    var appealByCase = {};
    appeals.forEach(function (appeal) { appealByCase[appeal.case_reference] = appeal; });
    var actions = data.moderation || [];
    document.getElementById('accountActions').innerHTML = actions.length
      ? actions.map(function (action) {
        var previous = appealByCase[action.id];
        var appealForm = action.appealable && !previous
          ? '<form class="account-appeal" data-appeal="' + esc(action.id) + '">' +
            '<textarea name="reason" maxlength="4000" minlength="30" placeholder="Explain your appeal (at least 30 characters)" required></textarea>' +
            '<button class="btn btn--ghost" type="submit"><span>Appeal</span></button></form>'
          : '';
        return item(action.command || action.type,
          action.created_at + (action.moderator ? ' · by ' + action.moderator : ''),
          (action.reason || 'No reason was recorded.') + (action.command ? '\nAction: ' + action.type : ''), appealForm);
      }).join('') : '<p class="account-empty">No moderation actions are linked to this Discord account.</p>';

    document.getElementById('accountAppeals').innerHTML = appeals.length
      ? appeals.map(function (appeal) {
        return item(appeal.reference, appeal.status, appeal.staff_reply || ('Action ' + appeal.case_reference), '');
      }).join('') : '<p class="account-empty">No appeals submitted.</p>';
    showOnly('dashboard');
  }

  function finishDashboard(data) {
    renderDashboard(data);
    window.dispatchEvent(new Event('si:account-updated'));
    if (!staffLink) return;
    request('link-staff', 'POST', {}).then(function () {
      if (!data.roblox) {
        message('Link Roblox to finish setting up staff access.');
        return;
      }
      message('Discord is linked to your staff login. Opening staff tools.');
      window.location.replace('/staff.html');
    }).catch(function (err) { message(err.message, true); });
  }

  function startPending(token) {
    pending = token;
    window.history.replaceState({}, document.title, window.location.pathname);
    request('pending', 'POST', {pending:pending}).then(function (data) {
      document.getElementById('accountDiscordName').textContent = data.discord_username;
      document.getElementById('accountDiscordNameVerify').textContent = data.discord_username;
      document.getElementById('accountPendingId').textContent = data.discord_id;
      document.getElementById('accountPendingIdVerify').textContent = data.discord_id;
      var pendingAvatar = document.getElementById('accountPendingAvatar');
      var pendingAvatarVerify = document.getElementById('accountPendingAvatarVerify');
      if (pendingAvatar) pendingAvatar.src = data.avatar_url || '';
      if (pendingAvatarVerify) pendingAvatarVerify.src = data.avatar_url || '';
      if (data.stage === 'setup') {
        document.getElementById('accountPendingProfile').hidden = false;
        showOnly('setup');
      } else {
        document.getElementById('accountPendingProfileVerify').hidden = false;
        document.getElementById('verifyHint').textContent = 'Code sent to ' + data.email_hint + '.';
        showOnly('verify');
      }
    }).catch(function (err) { message(err.message, true); showOnly('connect'); });
  }

  document.getElementById('setupForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    request('setup', 'POST', {pending:pending,email:form.email.value.trim()})
      .then(function (data) {
        document.getElementById('verifyHint').textContent = 'Code sent to ' + data.email_hint + '.';
        message('Check both channels for your codes.');
        showOnly('verify');
      }).catch(function (err) { message(err.message, true); });
  });

  document.getElementById('verifyForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var form = event.currentTarget;
    request('verify', 'POST', {pending:pending,email_code:form.email_code.value.trim()})
      .then(function (data) { csrf = data.csrf || ''; return request('dashboard'); })
      .then(function (data) { message('Both verification methods are confirmed.'); finishDashboard(data); })
      .catch(function (err) { message(err.message, true); });
  });

  document.getElementById('resendCodes').addEventListener('click', function () {
    request('resend', 'POST', {pending:pending}).then(function (data) {
      document.getElementById('verifyHint').textContent = 'Code sent to ' + data.email_hint + '.';
      message('New codes sent.');
    }).catch(function (err) { message(err.message, true); });
  });

  document.getElementById('accountActions').addEventListener('submit', function (event) {
    var form = event.target.closest('[data-appeal]');
    if (!form) return;
    event.preventDefault();
    request('appeal', 'POST', {action_id:form.getAttribute('data-appeal'),reason:form.reason.value.trim()})
      .then(function () { return request('dashboard'); })
      .then(function (data) { message('Your appeal was sent to the moderation team.'); renderDashboard(data); })
      .catch(function (err) { message(err.message, true); });
  });

  document.getElementById('staffPasswordReset').addEventListener('click', function () {
    request('staff-password-reset', 'POST', {}).then(function () {
      message('If the staff account is eligible, a one-time password reset link was sent to its email.');
    }).catch(function (err) { message(err.message, true); });
  });

  document.getElementById('manageSavedPayments').addEventListener('click', function () {
    request('payment-methods', 'POST', {}).then(function (data) {
      if (data.url) window.location.assign(data.url);
      else message('Stripe did not provide a secure payment-method page.', true);
    }).catch(function (err) { message(err.message, true); });
  });

  document.getElementById('accountLogout').addEventListener('click', function () {
    request('logout', 'POST', {}).then(function () {
      csrf = ''; message('You have signed out.'); showOnly('connect');
      window.dispatchEvent(new Event('si:account-updated'));
    }).catch(function (err) { message(err.message, true); });
  });

  var params = new URLSearchParams(window.location.search);
  var fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  var oauthNotice = '';
  if (params.get('roblox') === 'linked') {
    oauthNotice = 'Your Roblox account is linked.';
  } else if (params.get('error')) {
    var oauthErrors = {
      discord_cancelled: 'Discord verification was cancelled. You can try again.',
      roblox_cancelled: 'Roblox verification was cancelled. You can try again.',
      roblox_session_required: 'Sign in to your verified Discord account before linking Roblox.',
      roblox_not_configured: 'Roblox verification is not configured on this host yet.',
      roblox_unavailable: 'Roblox could not verify the account. Try again.',
      roblox_profile: 'Roblox returned an incomplete account profile. Try again.',
      roblox_already_linked: 'This Discord account already has a different Roblox account linked.',
      roblox_in_use: 'That Roblox account is linked to a different Discord account.'
    };
    oauthNotice = oauthErrors[params.get('error')] || '';
  }
  staffLink = fragment.get('staff') === '1';
  if (staffLink) document.getElementById('connectDiscord').href = '/api/account/connect?staff=1';
  if (fragment.get('pending')) {
    startPending(fragment.get('pending'));
  } else if (document.body.getAttribute('data-static') === '1') {
    disableConnect('Account verification needs the live website service.');
    showOnly('connect');
  } else {
    request('state').then(function (state) {
      csrf = state.csrf || '';
      if (state.authenticated) {
        return request('dashboard').then(function (data) {
          finishDashboard(data);
          if (oauthNotice) message(oauthNotice, !!params.get('error'));
        });
      }
      if (!state.configured) {
        disableConnect('Discord and email verification are not configured on this host yet.');
        if (oauthNotice) message(oauthNotice, !!params.get('error'));
        showOnly('connect');
        return;
      }
      if (oauthNotice) message(oauthNotice, !!params.get('error'));
      showOnly('connect');
    }).catch(function (err) {
      if (err.status === 404 || err.status === 405 || err.status === 503) {
        disableConnect('Account verification is not configured on this host yet.');
      } else {
        message(err.message, true);
      }
      showOnly('connect');
    });
  }
})(window, document);
