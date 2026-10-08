<?php
declare(strict_types=1);

require_once __DIR__ . '/webauthn.php';

function staff_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function staff_request_data(): array
{
    return request_body();
}

function staff_user(string $id): ?array
{
    $query = database()->prepare(
        'SELECT id, username, email, tier, title, active, email_verified, avatar_url, password_hash, discord_id ' .
        'FROM staff_users WHERE id = :id LIMIT 1'
    );
    $query->execute(['id' => $id]);
    $user = $query->fetch();
    return $user ?: null;
}

function staff_public_user(array $user): array
{
    return [
        'id' => (string)$user['id'],
        'username' => (string)$user['username'],
        'email' => (string)$user['email'],
        'tier' => (string)$user['tier'],
        'title' => (string)($user['title'] ?? ''),
        'rank_label' => (string)(($user['title'] ?? '') ?: ($user['tier'] ?? 'Staff')),
        'pfp' => (string)($user['avatar_url'] ?? ''),
        'discord_id' => (string)($user['discord_id'] ?? ''),
    ];
}

function staff_cookie_token(): string
{
    return (string)($_COOKIE['si_staff'] ?? '');
}

function staff_session(): ?array
{
    $cookie = staff_cookie_token();
    if ($cookie === '' || strlen($cookie) > 128) {
        return null;
    }
    $query = database()->prepare(
        'SELECT token_hash, staff_id, csrf_token, remember, expires_at FROM staff_sessions ' .
        'WHERE token_hash = :token_hash AND expires_at > UTC_TIMESTAMP() LIMIT 1'
    );
    $query->execute(['token_hash' => hash('sha256', $cookie)]);
    $session = $query->fetch();
    if (!$session) {
        return null;
    }
    $user = staff_user((string)$session['staff_id']);
    if (!$user || !(bool)$user['active']) {
        $delete = database()->prepare('DELETE FROM staff_sessions WHERE token_hash = :token_hash');
        $delete->execute(['token_hash' => $session['token_hash']]);
        return null;
    }
    $session['user'] = $user;
    return $session;
}

function staff_require_session(): array
{
    $session = staff_session();
    if (!$session) {
        reply(401, ['error' => 'no_session', 'message' => 'Sign in to continue.']);
    }
    if (!staff_account_mfa_matches($session['user'])) {
        reply(403, ['error'=>'account_mfa_required','message'=>'Connect the linked Discord account, verify your email, and link a Roblox account before opening staff tools.']);
    }
    return $session;
}

function staff_account_mfa_matches(array $user): bool
{
    if (empty($user['discord_id']) || !function_exists('account_session')) return false;
    $account = account_session();
    if (!is_array($account) || (string)$account['discord_id'] !== (string)$user['discord_id'] ||
        !(bool)$account['email_verified']) {
        return false;
    }
    $roblox = database()->prepare(
        'SELECT 1 FROM customer_roblox_links WHERE discord_id = :discord_id LIMIT 1'
    );
    $roblox->execute(['discord_id'=>$user['discord_id']]);
    return (bool)$roblox->fetchColumn();
}

function staff_require_csrf(array $session): void
{
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals((string)$session['csrf_token'], $sent)) {
        reply(403, ['error' => 'csrf', 'message' => 'Security token missing or stale. Reload the panel.']);
    }
}

function staff_set_cookie(string $token, int $seconds): void
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    setcookie('si_staff', $token, [
        'expires' => time() + $seconds,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function staff_clear_cookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    setcookie('si_staff', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function staff_pending(string $token, string $stage): array
{
    if ($token === '' || strlen($token) > 128) {
        reply(400, ['error' => 'pending_required', 'message' => 'Start sign-in again.']);
    }
    $query = database()->prepare(
        'SELECT token_hash, staff_id, stage, email, code_hash, expires_at, resend_after, failures ' .
        'FROM staff_pending_logins WHERE token_hash = :token_hash AND expires_at > UTC_TIMESTAMP() LIMIT 1'
    );
    $query->execute(['token_hash' => hash('sha256', $token)]);
    $pending = $query->fetch();
    if (!$pending) {
        reply(401, ['error' => 'pending_expired', 'message' => 'This login attempt expired. Start again.']);
    }
    if ((string)$pending['stage'] !== $stage) {
        reply(400, ['error' => 'wrong_step', 'message' => 'Complete the previous sign-in step first.']);
    }
    return $pending;
}

function staff_auth_state(): never
{
    $session = staff_session();
    $linked = $session && staff_account_mfa_matches($session['user']);
    $mail = settings()['mail'] ?? [];
    reply(200, [
        'authenticated' => (bool)$linked,
        'account_required' => (bool)$session && !$linked,
        'csrf' => $session ? (string)$session['csrf_token'] : '',
        'staff' => $linked ? staff_public_user($session['user']) : null,
        'mail' => ['configured' => !empty($mail['from'])],
        'passkeys_supported' => function_exists('openssl_verify'),
    ]);
}

function staff_auth_login(array $body): never
{
    $username = clean_text($body['username'] ?? '', 64, 'Username');
    $password = $body['password'] ?? null;
    if (!is_string($password) || $password === '' || strlen($password) > 1024) {
        reply(400, ['error' => 'missing_password', 'message' => 'Password is required.']);
    }
    enforce_rate('staff-login', 10, 900);

    $query = database()->prepare('SELECT * FROM staff_users WHERE username_key = :username_key LIMIT 1');
    $query->execute(['username_key' => strtolower($username)]);
    $user = $query->fetch();
    static $dummyHash = null;
    $dummyHash ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $valid = password_verify($password, (string)($user['password_hash'] ?? $dummyHash));
    if (!$user || !(bool)$user['active'] || !$valid) {
        reply(401, ['error' => 'bad_credentials', 'message' => 'Those credentials were not accepted.']);
    }

    $token = bin2hex(random_bytes(32));
    $insert = database()->prepare(
        'INSERT INTO staff_pending_logins (token_hash, staff_id, stage, email, expires_at) ' .
        'VALUES (:token_hash, :staff_id, \'email\', :email, UTC_TIMESTAMP() + INTERVAL 10 MINUTE)'
    );
    $insert->execute([
        'token_hash' => hash('sha256', $token),
        'staff_id' => $user['id'],
        'email' => $user['email'],
    ]);
    reply(200, [
        'pending' => $token,
        'step' => 'email',
        'staff_id' => $user['id'],
        'hint' => 'Signed in as ' . $user['username'] . '. Confirm your email to continue.',
    ]);
}

function staff_auth_confirm_email(array $body): never
{
    $pending = staff_pending((string)($body['pending'] ?? ''), 'email');
    $email = clean_text($body['email'] ?? '', 254, 'Email');
    $user = staff_user((string)$pending['staff_id']);
    if (!$user || !hash_equals(strtolower((string)$user['email']), strtolower($email))) {
        $update = database()->prepare(
            'UPDATE staff_pending_logins SET failures = failures + 1 WHERE token_hash = :token_hash'
        );
        $update->execute(['token_hash' => $pending['token_hash']]);
        if ((int)$pending['failures'] >= 4) {
            $delete = database()->prepare('DELETE FROM staff_pending_logins WHERE token_hash = :token_hash');
            $delete->execute(['token_hash' => $pending['token_hash']]);
            reply(429, ['error' => 'email_locked', 'message' => 'Too many wrong email addresses. Start again.']);
        }
        reply(401, ['error' => 'email_mismatch', 'message' => 'That email does not match this account.']);
    }
    $update = database()->prepare(
        "UPDATE staff_pending_logins SET stage = 'code', email = :email, failures = 0 WHERE token_hash = :token_hash"
    );
    $update->execute(['email' => $user['email'], 'token_hash' => $pending['token_hash']]);
    reply(200, ['pending' => (string)$body['pending'], 'step' => 'code', 'email' => $user['email']]);
}

function staff_auth_send_code(array $body): never
{
    $pending = staff_pending((string)($body['pending'] ?? ''), 'code');
    if (!empty($pending['resend_after']) && strtotime((string)$pending['resend_after'] . ' UTC') > time()) {
        $seconds = strtotime((string)$pending['resend_after'] . ' UTC') - time();
        reply(429, ['error' => 'resend_locked', 'message' => 'Wait before requesting another code.', 'resend_in' => $seconds]);
    }
    $user = staff_user((string)$pending['staff_id']);
    $email = (string)($pending['email'] ?? '');
    $mail = settings()['mail'] ?? [];
    if (!$user || $email === '' || empty($mail['from'])) {
        reply(503, ['error' => 'mail_not_configured', 'message' => 'Staff email delivery is not configured.']);
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $appSecret = (string)(settings()['app_secret'] ?? '');
    $hash = hash_hmac('sha256', (string)$user['id'] . "\n" . $email . "\n" . $code, $appSecret);
    $update = database()->prepare(
        'UPDATE staff_pending_logins SET code_hash = :code_hash, code_expires_at = UTC_TIMESTAMP() + INTERVAL 10 MINUTE, ' .
        'resend_after = UTC_TIMESTAMP() + INTERVAL 45 SECOND, failures = 0 WHERE token_hash = :token_hash'
    );
    $update->execute(['code_hash' => $hash, 'token_hash' => $pending['token_hash']]);

    $headers = [
        'From' => (string)$mail['from'],
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'Stitchline Website',
    ];
    $sent = mail($email, 'Your Stitchline sign-in code',
        "Your sign-in code is {$code}. It expires in 10 minutes. If you did not request it, ignore this email.",
        $headers);
    if (!$sent) {
        $clear = database()->prepare(
            'UPDATE staff_pending_logins SET code_hash = NULL, code_expires_at = NULL, resend_after = NULL ' .
            'WHERE token_hash = :token_hash'
        );
        $clear->execute(['token_hash' => $pending['token_hash']]);
        reply(502, ['error' => 'mail_failed', 'message' => 'The code could not be sent. Check the hosting email configuration.']);
    }
    reply(200, ['sent' => true, 'delivery' => 'mail', 'resend_in' => 45, 'expires_in' => 600]);
}

function staff_auth_resend_available(array $body): never
{
    $pending = staff_pending((string)($body['pending'] ?? ''), 'code');
    $resend = !empty($pending['resend_after']) ? strtotime((string)$pending['resend_after'] . ' UTC') : false;
    reply(200, ['resend_in' => $resend ? max(0, $resend - time()) : 0]);
}

function staff_create_session(array $user, bool $remember): array
{
    $token = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    $seconds = $remember ? 30 * 86400 : 8 * 3600;
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $seconds);
    $insert = database()->prepare(
        'INSERT INTO staff_sessions (token_hash, staff_id, csrf_token, remember, expires_at) ' .
        'VALUES (:token_hash, :staff_id, :csrf_token, :remember, :expires_at)'
    );
    $insert->bindValue(':token_hash', hash('sha256', $token));
    $insert->bindValue(':staff_id', (string)$user['id']);
    $insert->bindValue(':csrf_token', $csrf);
    $insert->bindValue(':remember', $remember ? 1 : 0, PDO::PARAM_INT);
    $insert->bindValue(':expires_at', $expiresAt);
    $insert->execute();
    staff_set_cookie($token, $seconds);
    return ['csrf' => $csrf, 'max_age' => $seconds];
}

function staff_auth_verify_code(array $body): never
{
    $pending = staff_pending((string)($body['pending'] ?? ''), 'code');
    $code = clean_text($body['code'] ?? '', 6, 'Code');
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        reply(422, ['error' => 'bad_code', 'message' => 'Enter the six-digit code.']);
    }
    if (empty($pending['code_hash']) || empty($pending['code_expires_at']) ||
        strtotime((string)$pending['code_expires_at'] . ' UTC') < time()) {
        reply(401, ['error' => 'code_expired', 'message' => 'That code expired. Request a new one.']);
    }
    $user = staff_user((string)$pending['staff_id']);
    if (!$user || !(bool)$user['active']) {
        reply(401, ['error' => 'no_account', 'message' => 'This account is not available.']);
    }
    $appSecret = (string)(settings()['app_secret'] ?? '');
    $expected = hash_hmac('sha256', (string)$user['id'] . "\n" . (string)$pending['email'] . "\n" . $code, $appSecret);
    if (!hash_equals((string)$pending['code_hash'], $expected)) {
        $update = database()->prepare(
            'UPDATE staff_pending_logins SET failures = failures + 1 WHERE token_hash = :token_hash'
        );
        $update->execute(['token_hash' => $pending['token_hash']]);
        if ((int)$pending['failures'] >= 4) {
            $delete = database()->prepare('DELETE FROM staff_pending_logins WHERE token_hash = :token_hash');
            $delete->execute(['token_hash' => $pending['token_hash']]);
        }
        reply(401, ['error' => 'bad_code', 'message' => 'That code was not accepted.']);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM staff_pending_logins WHERE token_hash = :token_hash');
        $delete->execute(['token_hash' => $pending['token_hash']]);
        $verified = $pdo->prepare('UPDATE staff_users SET email_verified = 1, last_login = UTC_TIMESTAMP() WHERE id = :id');
        $verified->execute(['id' => $user['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $session = staff_create_session($user, (bool)($body['remember'] ?? false));
    $accountRequired = !staff_account_mfa_matches($user);
    reply(200, [
        'ok' => true,
        'csrf' => $session['csrf'],
        'staff' => staff_public_user($user),
        'account_required' => $accountRequired,
        'passkey_prompt' => function_exists('openssl_verify'),
    ]);
}

function staff_auth_refresh(array $body): never
{
    $current = staff_require_session();
    staff_require_csrf($current);
    $delete = database()->prepare('DELETE FROM staff_sessions WHERE token_hash = :token_hash');
    $delete->execute(['token_hash' => $current['token_hash']]);
    $remember = (bool)($body['remember'] ?? false);
    $new = staff_create_session($current['user'], $remember);
    reply(200, ['ok' => true, 'csrf' => $new['csrf'], 'remember' => $remember, 'max_age' => $new['max_age']]);
}

function staff_auth_logout(): never
{
    $cookie = staff_cookie_token();
    if ($cookie !== '') {
        $delete = database()->prepare('DELETE FROM staff_sessions WHERE token_hash = :token_hash');
        $delete->execute(['token_hash' => hash('sha256', $cookie)]);
    }
    staff_clear_cookie();
    reply(200, ['ok' => true]);
}

function staff_can_tier(array $user, string $minimum): bool
{
    $tiers = ['Leadership', 'Management', 'Staff', 'Development', 'Testing'];
    $actual = array_search((string)$user['tier'], $tiers, true);
    $required = array_search($minimum, $tiers, true);
    return $actual !== false && $required !== false && $actual <= $required;
}

function staff_require_tier(array $session, string $minimum): void
{
    if (!staff_can_tier($session['user'], $minimum)) {
        reply(403, ['error' => 'insufficient_rank', 'message' => 'This action requires ' . $minimum . ' or higher.']);
    }
}

function staff_collection_routes(): array
{
    $status = static fn(string $key, string $label, array $options): array =>
        ['key' => $key, 'label' => $label, 'kind' => 'status', 'options' => $options];
    $field = static fn(string $key, string $label, string $type = 'text', bool $required = false, array $options = []): array =>
        ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $required, 'options' => $options];
    $user = static fn(string $key, string $label): array =>
        ['key' => $key, 'label' => $label, 'kind' => 'user'];
    $time = static fn(string $key, string $label): array =>
        ['key' => $key, 'label' => $label, 'kind' => 'time'];

    return [
        'case-logs' => [
            'label' => 'Case Logs', 'kind' => 'cases', 'tier' => 'Staff', 'writable' => true,
            'columns' => [['key' => 'ref', 'label' => 'Ref'], $user('user_name', 'Member'),
                $status('kind', 'Action', ['warning','timeout','kick','ban','note']),
                ['key' => 'reason', 'label' => 'Reason'], $status('status','State',['open','closed']),
                ['key' => 'moderator_name','label' => 'By'], $time('at','When')],
            'form' => [$field('user_name','Member','text',true), $field('user_id','Discord id'),
                $field('kind','Action','select',false,['warning','timeout','kick','ban','note']),
                $field('reason','Reason','textarea',true), $field('status','State','select',false,['open','closed'])],
        ],
        'moderation-actions' => [
            'label' => 'Moderation Actions', 'kind' => 'actions', 'tier' => 'Staff', 'writable' => true,
            'columns' => [$user('user_name','Member'), $status('kind','Action',[]),
                ['key'=>'reason','label'=>'Reason'], ['key'=>'moderator_name','label'=>'By'],
                $status('actor','From',['bot','panel']), $time('at','When')],
            'form' => [$field('user_name','Member','text',true), $field('user_id','Discord id'),
                $field('kind','Action','select',false,['warning','timeout','kick','ban','unban','note']),
                $field('reason','Reason','textarea',true), $field('moderator_name','Moderator')],
        ],
        'message-logs' => [
            'label' => 'Message Logs', 'kind' => 'messages', 'tier' => 'Staff', 'writable' => false,
            'hint' => 'Recorded by the moderation bot from configured channels.',
            'columns' => [['key'=>'channel_name','label'=>'Channel'], $user('author_display','Author'),
                ['key'=>'content','label'=>'Message'], ['key'=>'attachments','label'=>'Files','kind'=>'number'], $time('at','When')],
        ],
        'server-logs' => [
            'label' => 'Server Logs', 'kind' => 'server', 'tier' => 'Staff', 'writable' => false,
            'columns' => [$status('kind','Event',[]), $user('user_name','Member'),
                ['key'=>'detail','label'=>'Detail'], $time('at','When')],
        ],
        'tickets' => [
            'label' => 'Tickets', 'kind' => 'tickets', 'tier' => 'Staff', 'writable' => true,
            'columns' => [['key'=>'ref','label'=>'Ref'], ['key'=>'subject','label'=>'Subject'], $user('user_name','Opened by'),
                $status('status','State',['open','claimed','closed']), ['key'=>'claimed_by','label'=>'Claimed by'], $time('at','Opened')],
            'form' => [$field('subject','Subject','text',true), $field('user_name','Opened by','text',true),
                $field('user_id','Discord id'), $field('status','State','select',false,['open','claimed','closed']),
                $field('detail','Note','textarea')],
        ],
        'ticket-logs' => [
            'label' => 'Ticket Logs', 'kind' => 'tickets', 'tier' => 'Staff', 'writable' => false,
            'filter' => ['status' => ['closed']],
            'columns' => [['key'=>'ref','label'=>'Ref'], ['key'=>'subject','label'=>'Subject'], $user('user_name','Opened by'), $time('closed_at','Closed')],
        ],
        'giveaways' => [
            'label' => 'Giveaway Hosting', 'kind' => 'giveaways', 'tier' => 'Staff', 'writable' => true,
            'columns' => [['key'=>'prize','label'=>'Prize'], ['key'=>'description','label'=>'Details'],
                ['key'=>'entries','label'=>'Entries','kind'=>'number'], ['key'=>'winners','label'=>'Winners','kind'=>'number'],
                $time('ends_at','Ends'), $status('status','State',['open','drawn','cancelled'])],
            'form' => [$field('prize','Prize','text',true), $field('description','Details','textarea'),
                $field('winners','Winners','number'), $field('ends_at','Ends (ISO 8601)'),
                $field('status','State','select',false,['open','drawn','cancelled'])],
        ],
        'events' => [
            'label' => 'Event Hosting', 'kind' => 'events', 'tier' => 'Staff', 'writable' => true,
            'columns' => [['key'=>'title','label'=>'Event'], ['key'=>'description','label'=>'Details'],
                $time('starts_at','Starts'), ['key'=>'rsvps','label'=>'RSVPs','kind'=>'number'],
                $status('status','State',['scheduled','live','done','cancelled'])],
            'form' => [$field('title','Event','text',true), $field('description','Details','textarea'),
                $field('starts_at','Starts (ISO 8601)'), $field('location','Where'),
                $field('status','State','select',false,['scheduled','live','done','cancelled'])],
        ],
        'dev-tasks' => [
            'label' => 'Development Tasks', 'kind' => 'tasks', 'tier' => 'Development', 'writable' => true,
            'columns' => [['key'=>'title','label'=>'Task'], $user('assignee','Owner'),
                $status('status','State',['backlog','doing','review','done']),
                $status('priority','Priority',['low','normal','high']), $time('updated_at','Updated')],
            'form' => [$field('title','Task','text',true), $field('detail','Detail','textarea'),
                $field('assignee','Owner'), $field('status','State','select',false,['backlog','doing','review','done']),
                $field('priority','Priority','select',false,['low','normal','high'])],
        ],
        'work-submit' => [
            'label' => 'Work Submit', 'kind' => 'submissions', 'tier' => 'Staff', 'writable' => true,
            'filter' => ['kind'=>'work'],
            'columns' => [['key'=>'title','label'=>'Submitted'], $user('user_name','From'), ['key'=>'link','label'=>'Link'],
                ['key'=>'body','label'=>'Note'], $status('status','State',['pending','approved','rejected']), $time('at','When')],
            'form' => [$field('title','Title','text',true), $field('user_name','From','text',true),
                $field('link','Link'), $field('body','Note','textarea'),
                $field('status','State','select',false,['pending','approved','rejected'])],
        ],
        'testers-posting' => [
            'label' => 'Testers Posting Area', 'kind' => 'submissions', 'tier' => 'Staff', 'writable' => true,
            'filter' => ['kind'=>'tester'],
            'columns' => [['key'=>'title','label'=>'Posted'], $user('user_name','From'), ['key'=>'link','label'=>'Link'],
                ['key'=>'body','label'=>'Note'], $status('status','State',['pending','approved','rejected']), $time('at','When')],
            'form' => [$field('title','Title','text',true), $field('user_name','From','text',true),
                $field('link','Link'), $field('body','Note','textarea'),
                $field('status','State','select',false,['pending','approved','rejected'])],
        ],
    ];
}

function staff_bot_config_sections(): array
{
    return [
        'bot-config' => ['label'=>'Bot Configuration','tier'=>'Leadership',
            'keys'=>['log_channels','action_log_channel','staff_role','mute_role','guilds','message_excerpt','message_retention_days']],
        'ticket-config' => ['label'=>'Ticket Configuration','tier'=>'Leadership',
            'keys'=>['ticket_panel_channel','ticket_category','ticket_role']],
        'dev-config' => ['label'=>'Development Configuration','tier'=>'Leadership',
            'keys'=>['mute_role','staff_role','message_excerpt']],
    ];
}

function staff_save_site(array $site): void
{
    $update = database()->prepare(
        'UPDATE site_documents SET payload_json = :payload_json WHERE document_key = \'site\''
    );
    $update->execute([
        'payload_json' => json_encode($site, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    if ($update->rowCount() === 0) {
        $exists = database()->query("SELECT 1 FROM site_documents WHERE document_key = 'site'")->fetchColumn();
        if (!$exists) {
            reply(503, ['error' => 'site_not_seeded', 'message' => 'Site content has not been imported yet.']);
        }
    }
}

function staff_panel_registry(array $session): never
{
    staff_require_session();
    $sections = [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'subs' => []],
        ['id' => 'staff-terms', 'label' => 'Staff Terms', 'subs' => []],
        ['id' => 'clock-in-out', 'label' => 'Clock In / Out', 'subs' => []],
        ['id' => 'team-chat', 'label' => 'Team Chat', 'subs' => []],
        ['id' => 'team-voice', 'label' => 'Team Voice', 'subs' => []],
        ['id' => 'break-requests', 'label' => 'Break Requests', 'subs' => []],
        ['id' => 'web-config', 'label' => 'Web Configuration', 'subs' => []],
        ['id' => 'web-announcements', 'label' => 'Web Announcements', 'subs' => []],
        ['id' => 'staff-announcements', 'label' => 'Staff Announcements', 'subs' => []],
        ['id' => 'dev-announcements', 'label' => 'Development Announcements', 'subs' => []],
        ['id' => 'staff-config', 'label' => 'Staff Configuration', 'subs' => []],
        ['id' => 'ai-chat', 'label' => 'AI Chat', 'subs' => []],
        ['id' => 'logins-management', 'label' => 'Logins Management', 'subs' => []],
        ['id' => 'applications', 'label' => 'Applications', 'subs' => []],
        ['id' => 'contact', 'label' => 'Suggestion Box', 'subs' => []],
        ['id' => 'payments', 'label' => 'Payments', 'subs' => []],
        ['id' => 'bot-config', 'label' => 'Bot Configuration', 'subs' => []],
        ['id' => 'ticket-config', 'label' => 'Ticket Configuration', 'subs' => []],
        ['id' => 'tickets', 'label' => 'Tickets', 'subs' => []],
        ['id' => 'giveaways', 'label' => 'Giveaway Hosting', 'subs' => []],
        ['id' => 'events', 'label' => 'Event Hosting', 'subs' => []],
        ['id' => 'case-logs', 'label' => 'Case Logs', 'subs' => []],
        ['id' => 'ticket-logs', 'label' => 'Ticket Logs', 'subs' => []],
        ['id' => 'message-logs', 'label' => 'Message Logs', 'subs' => []],
        ['id' => 'server-logs', 'label' => 'Server Logs', 'subs' => []],
        ['id' => 'moderation-actions', 'label' => 'Moderation Actions', 'subs' => []],
        ['id' => 'dev-config', 'label' => 'Development Configuration', 'subs' => []],
        ['id' => 'dev-tasks', 'label' => 'Development Tasks', 'subs' => []],
        ['id' => 'work-submit', 'label' => 'Work Submit', 'subs' => []],
        ['id' => 'testers-posting', 'label' => 'Testers Posting Area', 'subs' => []],
        ['id' => 'staff-announcements', 'label' => 'Staff Announcements', 'subs' => []],
        ['id' => 'dev-announcements', 'label' => 'Development Announcements', 'subs' => []],
        ['id' => 'community', 'label' => 'Community Overview', 'subs' => []],
        ['id' => 'logs', 'label' => 'Logs Overview', 'subs' => []],
        ['id' => 'staff', 'label' => 'Staff Overview', 'subs' => []],
        ['id' => 'development', 'label' => 'Development Overview', 'subs' => []],
        ['id' => 'leadership', 'label' => 'Leadership Overview', 'subs' => []],
        ['id' => 'leadership-dashboard', 'label' => 'Leadership Dashboard', 'subs' => []],
    ];
    $routes = [];
    foreach ($sections as $section) {
        $routes[$section['id']] = ['id' => $section['id'], 'label' => $section['label'], 'parent' => null];
        foreach ($section['subs'] as $sub) {
            $routes[$sub['id']] = [
                'id' => $sub['id'],
                'label' => $sub['label'],
                'parent' => $section['id'],
            ];
        }
    }
    $botRows = database()->query(
        'SELECT bot_id, received_at, payload_json FROM bot_heartbeats WHERE received_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND'
    )->fetchAll();
    $online = [];
    foreach ($botRows as $row) {
        $online[(string)$row['bot_id']] = json_decode((string)$row['payload_json'], true) ?: [];
    }
    $botList = [];
    foreach (['community' => 'Stitchline Interactive'] as $id => $label) {
        $heartbeat = $online[$id] ?? null;
        $botList[] = [
            'id' => $id,
            'label' => $label,
            'online' => $heartbeat !== null,
            'last_seen' => $heartbeat ? strtotime((string)($heartbeat['at'] ?? '')) : null,
        ];
    }
    $onlineCount = count(array_filter($botList, static fn(array $bot): bool => $bot['online']));
    reply(200, [
        'sections' => $sections,
        'routes' => $routes,
        'tiers' => ['Leadership', 'Management', 'Staff', 'Development', 'Testing'],
        'bot' => ['bots' => $botList, 'online' => $onlineCount, 'total' => 1, 'all_online' => $onlineCount === 1],
    ]);
}

function staff_contact_view(): array
{
    $messages = database()->query(
        'SELECT reference, received_at, topic, topic_label, name, email, message, status, staff_reply, decided_at, emailed ' .
        'FROM contact_messages ORDER BY received_at DESC LIMIT 200'
    )->fetchAll();
    $counts = ['pending' => 0, 'approved' => 0, 'denied' => 0, 'unreplied' => 0];
    foreach ($messages as &$message) {
        $message['ref'] = $message['reference'];
        $message['received'] = $message['received_at'];
        $message['emailed'] = (bool)$message['emailed'];
        $counts[(string)$message['status']] = ($counts[(string)$message['status']] ?? 0) + 1;
        if (in_array($message['status'], ['approved', 'denied'], true) && !$message['emailed']) {
            $counts['unreplied']++;
        }
    }
    unset($message);
    $site = site_document();
    return [
        'messages' => $messages,
        'address' => (string)($site['contact']['inbox'] ?? $site['contact']['email'] ?? ''),
        'pending' => $counts['pending'],
        'approved' => $counts['approved'],
        'denied' => $counts['denied'],
        'unreplied' => $counts['unreplied'],
        'can_decide' => staff_can_tier(staff_require_session()['user'], 'Management'),
    ];
}

function staff_orders_view(): array
{
    $orders = database()->query(
        'SELECT reference,created_at,status,provider,discord_id,discord_name,email,verification_status,total,tax_json,discount_json,lines_json ' .
        'FROM orders ORDER BY created_at DESC LIMIT 300'
    )->fetchAll();
    $gross = 0.0;
    foreach ($orders as &$order) {
        $order['ref'] = $order['reference'];
        $order['created'] = $order['created_at'];
        $order['verification'] = ['status'=>$order['verification_status']];
        $order['lines'] = json_decode((string)$order['lines_json'], true) ?: [];
        $order['tax'] = json_decode((string)$order['tax_json'], true) ?: [];
        $order['discount'] = $order['discount_json'] ? (json_decode((string)$order['discount_json'], true) ?: null) : null;
        $order['total'] = (float)$order['total'];
        if (in_array((string)$order['status'], ['paid', 'delivered'], true)) {
            $gross += $order['total'];
        }
    }
    unset($order);
    $config = settings();
    return [
        'orders'=>$orders,
        'gross'=>round($gross,2),
        'providers'=>['stripe'=>false],
    ];
}

function staff_applications_view(): array
{
    $site = site_document();
    $applications = database()->query(
        'SELECT reference, received_at, role_id, role_title, name, email, answers_json, status ' .
        'FROM applications ORDER BY received_at DESC LIMIT 500'
    )->fetchAll();
    foreach ($applications as &$application) {
        $application['ref'] = $application['reference'];
        $application['received'] = $application['received_at'];
        $application['role'] = $application['role_title'];
        $application['answers'] = json_decode((string)$application['answers_json'], true) ?: [];
    }
    unset($application);
    return [
        'roles' => is_array($site['applications'] ?? null) ? $site['applications'] : [],
        'applications' => $applications,
    ];
}

function staff_bot_records(string $kind, array $filter = [], int $limit = 300): array
{
    $collection = staff_collection_routes()[$kind] ?? null;
    if (!is_array($collection)) {
        return [];
    }
    $query = database()->prepare(
        'SELECT payload_json FROM bot_records WHERE record_kind = :kind ORDER BY record_id DESC LIMIT :limit'
    );
    $query->bindValue(':kind', $collection['kind']);
    $query->bindValue(':limit', min(2000, max($limit, $limit * 4)), PDO::PARAM_INT);
    $query->execute();
    $rows = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $record = json_decode((string)$raw, true);
        if (!is_array($record)) {
            continue;
        }
        $matches = true;
        foreach ($filter as $key => $wanted) {
            $actual = $record[$key] ?? null;
            $matches = is_array($wanted) ? in_array($actual, $wanted, true) : $actual === $wanted;
            if (!$matches) {
                break;
            }
        }
        if ($matches) {
            $rows[] = $record;
            if (count($rows) >= $limit) {
                break;
            }
        }
    }
    return $rows;
}

function staff_bot_summary(): array
{
    $counts = array_fill_keys(array_keys(bot_collection_caps()), 0);
    foreach (database()->query('SELECT record_kind, COUNT(*) AS total FROM bot_records GROUP BY record_kind') as $row) {
        $counts[(string)$row['record_kind']] = (int)$row['total'];
    }
    return $counts;
}

function staff_bot_open_counts(): array
{
    $filters = [
        'tickets' => ['open', 'claimed'], 'case-logs' => ['open'],
        'work-submit' => ['pending'], 'testers-posting' => ['pending'],
        'dev-tasks' => ['backlog', 'doing', 'review'], 'giveaways' => ['open'],
    ];
    $out = [];
    foreach ($filters as $route => $states) {
        $out[$route] = count(staff_bot_records($route, ['status' => $states], 2000));
    }
    return $out;
}

function staff_overview(string $name): never
{
    $summary = staff_bot_summary();
    $open = staff_bot_open_counts();
    $site = site_document();
    $counts = [
        'contact' => (int)database()->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn(),
        'applications' => (int)database()->query('SELECT COUNT(*) FROM applications')->fetchColumn(),
        'staff' => (int)database()->query('SELECT COUNT(*) FROM staff_users WHERE active = 1')->fetchColumn(),
    ];
    $spec = match ($name) {
        'community' => [
            'tier' => 'Staff',
            'cards' => [
                ['label' => 'Open giveaways', 'value' => $open['giveaways'] ?? 0],
                ['label' => 'Events', 'value' => $summary['events'] ?? 0],
            ],
            'links' => [['id'=>'giveaways','label'=>'Giveaways'],['id'=>'events','label'=>'Events']],
        ],
        'logs' => [
            'tier' => 'Staff',
            'cards' => [
                ['label'=>'Open cases','value'=>$open['case-logs'] ?? 0],
                ['label'=>'Messages logged','value'=>$summary['messages'] ?? 0],
                ['label'=>'Server events','value'=>$summary['server'] ?? 0],
            ],
            'links' => [['id'=>'case-logs','label'=>'Cases'],['id'=>'message-logs','label'=>'Messages'],['id'=>'server-logs','label'=>'Server']],
        ],
        'staff' => [
            'tier' => 'Staff',
            'cards' => [
                ['label'=>'Staff accounts','value'=>$counts['staff']],
                ['label'=>'Suggestions','value'=>$counts['contact']],
                ['label'=>'Applications','value'=>$counts['applications']],
            ],
            'links' => [['id'=>'logins-management','label'=>'Logins'],['id'=>'contact','label'=>'Suggestions'],['id'=>'applications','label'=>'Applications']],
        ],
        'development' => [
            'tier' => 'Development',
            'cards' => [
                ['label'=>'Tasks in progress','value'=>$open['dev-tasks'] ?? 0],
                ['label'=>'Submissions','value'=>$open['work-submit'] ?? 0],
            ],
            'links' => [['id'=>'dev-tasks','label'=>'Tasks'],['id'=>'work-submit','label'=>'Work submissions'],['id'=>'testers-posting','label'=>'Tester posts']],
        ],
        default => [
            'tier' => 'Leadership',
            'cards' => [
                ['label'=>'Games','value'=>count($site['games'] ?? [])],
                ['label'=>'Site visits','value'=>(int)(database()->query("SELECT metric_value FROM site_metrics WHERE metric_key = 'site_visits'")->fetchColumn() ?: 0)],
                ['label'=>'Open applications','value'=>count(array_filter($site['applications'] ?? [], static fn(array $role): bool => !empty($role['open'])))],
            ],
            'links' => [['id'=>'web-config','label'=>'Web configuration'],['id'=>'web-announcements','label'=>'Web announcements'],['id'=>'staff-config','label'=>'Roster']],
        ],
    };
    staff_require_tier(['user' => staff_require_session()['user']], $spec['tier']);
    reply(200, ['route'=>$name,'status'=>'ok','view'=>'overview','cards'=>$spec['cards'],'links'=>$spec['links']]);
}

function staff_panel_route(string $route, array $session): never
{
    $user = $session['user'];
    $site = site_document();
    if ($route === 'dashboard') {
        $botCount = (int)database()->query(
            'SELECT COUNT(*) FROM bot_heartbeats WHERE received_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND'
        )->fetchColumn();
        $counts = database()->query(
            'SELECT (SELECT COUNT(*) FROM staff_users WHERE active = 1) AS staff_count, ' .
            '(SELECT COUNT(*) FROM staff_sessions WHERE expires_at > UTC_TIMESTAMP()) AS session_count, ' .
            '(SELECT COUNT(*) FROM contact_messages WHERE status = \'pending\') AS contact_count, ' .
            '(SELECT COUNT(*) FROM applications) AS application_count'
        )->fetch();
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'staff' => (int)$counts['staff_count'],
            'active_sessions' => (int)$counts['session_count'],
            'games' => count($site['games'] ?? []),
            'site_visits' => (int)(database()->query("SELECT metric_value FROM site_metrics WHERE metric_key = 'site_visits'")->fetchColumn() ?: 0),
            'announcements' => count($site['announcements'] ?? []),
            'mail' => ['configured' => !empty((settings()['mail'] ?? [])['from'])],
            'ai' => ['configured' => false, 'model' => ''],
            'chat' => ['threads' => 0],
            'bot' => ['bots' => [], 'online' => $botCount, 'total' => 2, 'all_online' => $botCount === 2],
        ]);
    }
    if ($route === 'ai-chat') {
        staff_require_tier($session, 'Staff');
        reply(200, [
            'route'=>'ai-chat',
            'status'=>'ok',
            'provider'=>'Thunderbolt',
            'embedded'=>true,
            'provider_status'=>['configured'=>true,'model'=>'Thunderbolt UI'],
            'messages'=>[],
        ]);
    }
    if ($route === 'payments') {
        staff_require_tier($session, 'Leadership');
        reply(200, ['route'=>$route,'status'=>'ok',...staff_orders_view()]);
    }
    if (in_array($route, ['community','logs','staff','development','leadership'], true)) {
        staff_overview($route);
    }
    if ($route === 'contact') {
        staff_require_tier($session, 'Staff');
        reply(200, ['route' => $route, 'status' => 'ok', ...staff_contact_view()]);
    }
    if ($route === 'applications') {
        staff_require_tier($session, 'Staff');
        reply(200, ['route' => $route, 'status' => 'ok', ...staff_applications_view()]);
    }
    if ($route === 'logins-management') {
        staff_require_tier($session, 'Staff');
        $staff = database()->query(
            'SELECT id, username, email, tier, title, active, email_verified, avatar_url FROM staff_users ORDER BY username_key'
        )->fetchAll();
        foreach ($staff as &$person) {
            $person['rank_label'] = (string)($person['title'] ?: $person['tier']);
            $passkey = database()->prepare('SELECT 1 FROM staff_passkeys WHERE staff_id = :staff_id LIMIT 1');
            $passkey->execute(['staff_id' => $person['id']]);
            $person['passkey'] = (bool)$passkey->fetchColumn();
        }
        unset($person);
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'staff' => $staff,
            'can_manage' => staff_can_tier($user, 'Management'),
            'bot' => ['online' => false],
        ]);
    }
    if ($route === 'web-config') {
        staff_require_tier($session, 'Leadership');
        $site['statuses'] = ['Concept', 'In Production', 'Playtesting', 'Released', 'Paused'];
        $site['site_visits'] = (int)(database()->query("SELECT metric_value FROM site_metrics WHERE metric_key = 'site_visits'")->fetchColumn() ?: 0);
        reply(200, ['route' => $route, 'status' => 'ok', ...$site]);
    }
    if (in_array($route, ['web-announcements','staff-announcements','dev-announcements'], true)) {
        $audience = ['web-announcements'=>'public','staff-announcements'=>'staff','dev-announcements'=>'dev'][$route];
        $tier = ['public'=>'Leadership','staff'=>'Staff','dev'=>'Development'][$audience];
        staff_require_tier($session, $tier);
        $items = array_values(array_filter($site['announcements'] ?? [], static fn(array $item): bool =>
            (string)($item['audience'] ?? 'public') === $audience));
        $label = ['public'=>'Web','staff'=>'Staff','dev'=>'Development'][$audience];
        reply(200, ['route' => $route, 'status' => 'ok', 'announcements' => $items,
            'kind' => $audience, 'label' => $label, 'ttl_days' => 14,
            'audiences' => [['id'=>'public','label'=>'Web'],['id'=>'staff','label'=>'Staff'],['id'=>'dev','label'=>'Development']]]);
    }
    if ($route === 'staff-config') {
        staff_require_tier($session, 'Staff');
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'team' => is_array($site['team'] ?? null) ? $site['team'] : [],
            'tiers' => ['Leadership', 'Management', 'Staff', 'Development', 'Testing'],
            'hint' => 'Only people with an active website login can be shown on the public team page.',
        ]);
    }
    if ($route === 'staff-terms') {
        staff_require_session();
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'staff-terms',
            'kind' => $route,
            'label' => 'Staff Terms',
            'tier' => 'Staff',
            'writable' => staff_can_tier($user, 'Leadership'),
            'terms' => staff_terms_content(),
            'ranks' => staff_rank_ladder(),
        ]);
    }
    if ($route === 'clock-in-out') {
        staff_require_session();
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'clock',
            'kind' => $route,
            'label' => 'Clock In / Out',
            'tier' => 'Staff',
            'writable' => true,
            'clock' => staff_clock_state($session),
            'history' => staff_clock_history($session['user']['id'], 12),
            'active' => staff_clock_active_sessions(),
            'payRates' => staff_pay_rates(),
            'isDevelopment' => staff_is_development($session['user']),
        ]);
    }
    if ($route === 'team-chat') {
        staff_require_session();
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'chat',
            'kind' => $route,
            'label' => 'Team Chat',
            'tier' => 'Staff',
            'writable' => true,
            'threads' => staff_chat_threads($session['user']['id']),
            'people' => staff_chat_people($session['user']['id']),
        ]);
    }
    if ($route === 'team-voice') {
        staff_require_session();
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'voice',
            'kind' => $route,
            'label' => 'Team Voice',
            'tier' => 'Staff',
            'writable' => staff_can_tier($user, 'Leadership'),
            'rooms' => staff_voice_rooms(),
            'webrtc' => staff_webrtc_status(),
        ]);
    }
    if ($route === 'break-requests') {
        staff_require_session();
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'breaks',
            'kind' => $route,
            'label' => 'Break Requests',
            'tier' => 'Staff',
            'writable' => true,
            'mine' => staff_break_requests((string)$session['user']['id']),
            'queue' => staff_can_tier($user, 'Leadership') ? staff_break_requests(null) : [],
            'canApprove' => staff_can_tier($user, 'Leadership'),
        ]);
    }
    if ($route === 'leadership-dashboard') {
        staff_require_tier($session, 'Leadership');
        if (in_array((string)$user['tier'], ['Development', 'Testing'], true)) {
            reply(403, ['error' => 'insufficient_rank', 'message' => 'The leadership dashboard is for Supervisor Of Staff and above, excluding development roles.']);
        }
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'leadership-dashboard',
            'kind' => $route,
            'label' => 'Leadership Dashboard',
            'tier' => 'Leadership',
            'clock' => staff_clock_state($session),
            'active' => staff_clock_active_sessions(),
            'missing' => staff_clock_missing(),
            'breaks' => staff_break_requests(null),
            'payRates' => staff_pay_rates(),
            'terms' => staff_terms_content(),
            'counts' => staff_leadership_counts(),
        ]);
    }
    $collection = staff_collection_routes()[$route] ?? null;
    if (is_array($collection)) {
        staff_require_tier($session, (string)$collection['tier']);
        $filter = is_array($collection['filter'] ?? null) ? $collection['filter'] : [];
        if ($route === 'ticket-logs') {
            $filter['status'] = ['closed'];
        }
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'collection',
            'kind' => $route,
            'label' => $collection['label'],
            'hint' => $collection['hint'] ?? '',
            'tier' => $collection['tier'],
            'writable' => !empty($collection['writable']),
            'columns' => $collection['columns'],
            'form' => $collection['form'] ?? [],
            'records' => staff_bot_records($route, $filter),
            'summary' => staff_bot_summary(),
            'open' => staff_bot_open_counts(),
        ]);
    }
    $configSection = staff_bot_config_sections()[$route] ?? null;
    if (is_array($configSection)) {
        staff_require_tier($session, (string)$configSection['tier']);
        $settings = bot_read_settings();
        $fields = [];
        foreach ($configSection['keys'] as $key) {
            $default = bot_default_settings()[$key];
            $type = is_array($default) ? 'list' : (is_int($default) ? 'number' : 'text');
            $fields[] = ['key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'type' => $type];
        }
        $guildState = [];
        $heartbeat = database()->query(
            "SELECT payload_json FROM bot_heartbeats WHERE bot_id = 'community' AND received_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND LIMIT 1"
        )->fetchColumn();
        $status = is_string($heartbeat) ? (json_decode($heartbeat, true) ?: []) : [];
        $seen = [];
        foreach (($status['guilds'] ?? []) as $guild) {
            if (is_array($guild)) {
                $seen[(string)($guild['id'] ?? '')] = $guild;
            }
        }
        foreach (($settings['guilds'] ?? []) as $guildId) {
            $guildId = (string)$guildId;
            $guildState[] = [
                'id' => $guildId,
                'name' => $seen[$guildId]['name'] ?? null,
                'online' => isset($seen[$guildId]),
            ];
        }
        reply(200, [
            'route' => $route,
            'status' => 'ok',
            'view' => 'config',
            'kind' => $route,
            'label' => $configSection['label'],
            'tier' => $configSection['tier'],
            'fields' => $fields,
            'settings' => array_intersect_key($settings, array_flip($configSection['keys'])),
            'guild_state' => $guildState,
            'hint' => 'Configure Discord IDs here. These settings are shared with the separate bot host.',
        ]);
    }
    reply(404, ['error' => 'unknown_route', 'message' => 'That staff panel section is not available.']);
}

function staff_panel_contact_decide(array $body, array $session): never
{
    staff_require_tier($session, 'Management');
    $reference = clean_text($body['ref'] ?? '', 12, 'Reference');
    $decision = clean_text($body['decision'] ?? '', 12, 'Decision');
    $message = clean_text($body['reply'] ?? '', 4000, 'Reply');
    if (!in_array($decision, ['approved', 'denied'], true) || strlen($message) < 10) {
        reply(422, ['error' => 'bad_decision', 'message' => 'Choose approve or deny and write at least ten characters.']);
    }
    $query = database()->prepare('SELECT name, email, topic_label FROM contact_messages WHERE reference = :reference LIMIT 1');
    $query->execute(['reference' => $reference]);
    $contact = $query->fetch();
    if (!$contact) {
        reply(404, ['error' => 'not_found', 'message' => 'That suggestion no longer exists.']);
    }
    $update = database()->prepare(
        'UPDATE contact_messages SET status = :status, staff_reply = :staff_reply, decided_at = UTC_TIMESTAMP(), emailed = 0 ' .
        'WHERE reference = :reference'
    );
    $update->execute(['status' => $decision, 'staff_reply' => $message, 'reference' => $reference]);
    $mail = settings()['mail'] ?? [];
    $sent = false;
    if (!empty($mail['from'])) {
        $name = (string)($contact['name'] ?: 'there');
        $subjectText = $decision === 'approved' ? 'we are doing it' : 'we are not doing this one';
        $sent = mail(
            (string)$contact['email'],
            'Your suggestion: ' . $subjectText,
            "Hello {$name},\n\nThank you for sending us this suggestion ({$reference}).\n\n{$message}\n\nStitchline Interactive",
            ['From' => (string)$mail['from'], 'Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
    $deliveryUpdate = database()->prepare(
        'UPDATE contact_messages SET emailed = :emailed WHERE reference = :reference'
    );
    $deliveryUpdate->execute(['emailed' => $sent ? 1 : 0, 'reference' => $reference]);
    reply(200, ['ok' => true, 'emailed' => $sent, 'delivery' => ['error' => $sent ? null : 'Mail could not be sent.']]);
}

function staff_panel_save_application(array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    $site = site_document();
    $roles = is_array($site['applications'] ?? null) ? $site['applications'] : [];
    $id = clean_text($body['id'] ?? '', 60, 'Role id');
    $roleName = clean_text($body['role'] ?? '', 120, 'Role name');
    if ($roleName === '') {
        reply(422, ['error' => 'missing_role', 'message' => 'A role needs a name.']);
    }
    $questions = [];
    foreach (($body['questions'] ?? []) as $question) {
        if (!is_array($question)) {
            continue;
        }
        $label = clean_text($question['label'] ?? '', 300, 'Question');
        if ($label === '') {
            continue;
        }
        $type = (string)($question['type'] ?? 'text');
        if (!in_array($type, ['text', 'textarea', 'email', 'url', 'number', 'select'], true)) {
            $type = 'text';
        }
        $questions[] = [
            'id' => (string)($question['id'] ?? ('q-' . bin2hex(random_bytes(4)))),
            'label' => $label,
            'type' => $type,
            'required' => !empty($question['required']),
            'options' => is_array($question['options'] ?? null) ? array_values($question['options']) : [],
        ];
    }
    $record = [
        'id' => $id !== '' ? $id : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $roleName), '-')),
        'role' => $roleName,
        'summary' => clean_text($body['summary'] ?? '', 1200, 'Summary'),
        'responsibilities' => array_values(array_filter(array_map('strval', $body['responsibilities'] ?? []))),
        'requirements' => array_values(array_filter(array_map('strval', $body['requirements'] ?? []))),
        'open' => !empty($body['open']),
        'closes_at' => clean_text($body['closes_at'] ?? '', 40, 'Closing date') ?: null,
        'questions' => $questions,
    ];
    $found = false;
    foreach ($roles as $index => $role) {
        if (is_array($role) && (string)($role['id'] ?? '') === $record['id']) {
            $roles[$index] = $record;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $roles[] = $record;
    }
    $site['applications'] = $roles;
    staff_save_site($site);
    reply(200, ['ok' => true, 'roles' => $roles]);
}

function staff_panel_delete_application(array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    $id = clean_text($body['id'] ?? '', 60, 'Role id');
    $site = site_document();
    $site['applications'] = array_values(array_filter(
        $site['applications'] ?? [],
        static fn(array $role): bool => (string)($role['id'] ?? '') !== $id
    ));
    staff_save_site($site);
    reply(200, ['ok' => true]);
}

function staff_panel_announcements(string $route, array $body, array $session): never
{
    $site = site_document();
    $items = is_array($site['announcements'] ?? null) ? $site['announcements'] : [];
    if ($route === 'panel/announcements/delete') {
        $id = clean_text($body['id'] ?? '', 80, 'Announcement id');
        $found = null;
        foreach ($items as $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === $id) $found = $item;
        }
        $audience = (string)($found['audience'] ?? 'public');
        staff_require_tier($session, ['public'=>'Leadership','staff'=>'Staff','dev'=>'Development'][$audience] ?? 'Leadership');
        $site['announcements'] = array_values(array_filter(
            $items,
            static fn(array $item): bool => (string)($item['id'] ?? '') !== $id
        ));
        staff_save_site($site);
        reply(200, ['ok' => true]);
    }
    $title = clean_text($body['title'] ?? '', 120, 'Title');
    $text = clean_text($body['body'] ?? '', 4000, 'Announcement');
    if ($title === '' || $text === '') {
        reply(422, ['error' => 'missing_field', 'message' => 'Title and announcement text are required.']);
    }
    $id = clean_text($body['id'] ?? '', 80, 'Announcement id');
    $audience = clean_text($body['audience'] ?? 'public', 16, 'Audience');
    if (!in_array($audience, ['public','staff','dev'], true)) {
        reply(422, ['error'=>'bad_audience','message'=>'Choose Web, Staff, or Development.']);
    }
    staff_require_tier($session, ['public'=>'Leadership','staff'=>'Staff','dev'=>'Development'][$audience]);
    $now = gmdate('c');
    $record = [
        'id' => $id !== '' ? $id : 'ann-' . bin2hex(random_bytes(6)),
        'title' => $title,
        'body' => $text,
        'tag' => clean_text($body['tag'] ?? 'Update', 32, 'Tag') ?: 'Update',
        'audience' => $audience,
        'created_at' => $now,
        'expires_at' => gmdate('c', time() + 14 * 86400),
    ];
    if ($route === 'panel/announcements/update') {
        foreach ($items as $index => $item) {
            if (is_array($item) && (string)($item['id'] ?? '') === $id) {
                $record['created_at'] = (string)($item['created_at'] ?? $now);
                $items[$index] = $record;
                $site['announcements'] = $items;
                staff_save_site($site);
                reply(200, ['ok' => true]);
            }
        }
        reply(404, ['error' => 'not_found', 'message' => 'Announcement not found.']);
    }
    array_unshift($items, $record);
    $site['announcements'] = $items;
    staff_save_site($site);
    reply(200, ['ok' => true, 'announcement' => $record]);
}

function staff_panel_games(string $route, array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    $site = site_document();
    $games = is_array($site['games'] ?? null) ? $site['games'] : [];
    if ($route === 'panel/games/delete') {
        $id = clean_text($body['id'] ?? '', 80, 'Game id');
        $site['games'] = array_values(array_filter(
            $games,
            static fn(array $game): bool => (string)($game['id'] ?? '') !== $id
        ));
        staff_save_site($site);
        reply(200, ['ok' => true, 'games' => $site['games']]);
    }

    $id = clean_text($body['id'] ?? '', 80, 'Game id');
    if ($id !== '') {
        foreach ($games as $existing) {
            if (is_array($existing) && (string)($existing['id'] ?? '') === $id) {
                $body = array_replace($existing, $body);
                break;
            }
        }
    }
    $title = clean_text($body['title'] ?? '', 120, 'Game title');
    if ($title === '') {
        reply(422, ['error' => 'missing_title', 'message' => 'A game needs a title.']);
    }
    $status = clean_text($body['status'] ?? 'Concept', 32, 'Status');
    if (!in_array($status, ['Concept', 'In Production', 'Playtesting', 'Released', 'Paused'], true)) {
        reply(422, ['error' => 'bad_status', 'message' => 'Choose a listed game status.']);
    }
    $record = [
        'id' => $id !== '' ? $id : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-')),
        'title' => $title,
        'status' => $status,
        'tags' => is_array($body['tags'] ?? null) ? array_slice(array_values($body['tags']), 0, 8) : [],
        'description' => clean_text($body['description'] ?? '', 2000, 'Description'),
        'thumb' => clean_text($body['thumb'] ?? '', 200, 'Thumbnail'),
        'url' => clean_text($body['url'] ?? '', 300, 'Game URL'),
        'discord' => clean_text($body['discord'] ?? '', 300, 'Discord URL'),
        'release_estimate' => clean_text($body['release_estimate'] ?? '', 60, 'Release estimate'),
        'plays' => $body['plays'] ?? null,
        'featured' => false,
        'sneakpeeks' => [],
    ];
    $updated = false;
    foreach ($games as $index => $game) {
        if (is_array($game) && (string)($game['id'] ?? '') === $record['id']) {
            $record['featured'] = !empty($game['featured']);
            $record['sneakpeeks'] = is_array($game['sneakpeeks'] ?? null) ? $game['sneakpeeks'] : [];
            $games[$index] = array_replace($game, $record);
            $record = $games[$index];
            $updated = true;
            break;
        }
    }
    if (!$updated) {
        $games[] = $record;
    }
    if (!empty($body['featured'])) {
        foreach ($games as &$game) {
            $game['featured'] = (string)($game['id'] ?? '') === $record['id'];
        }
        unset($game);
        $site['featured'] = array_replace($site['featured'] ?? [], [
            'slug' => $record['id'],
            'title' => $record['title'],
            'status' => $record['status'],
            'description' => $record['description'],
            'thumb' => $record['thumb'],
            'url' => $record['url'],
            'discord' => $record['discord'],
            'release_estimate' => $record['release_estimate'],
        ]);
    }
    $site['games'] = $games;
    staff_save_site($site);
    reply(200, ['ok' => true, 'game' => $record, 'games' => $games]);
}

function staff_panel_featured(array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    $site = site_document();
    $featured = is_array($site['featured'] ?? null) ? $site['featured'] : [];
    foreach (['title' => 120, 'status' => 32, 'release_estimate' => 60, 'description' => 2000,
              'thumb' => 200, 'url' => 300, 'discord' => 300, 'slug' => 60] as $field => $limit) {
        if (array_key_exists($field, $body)) {
            $featured[$field] = clean_text($body[$field], $limit, $field);
        }
    }
    $stats = is_array($featured['stats'] ?? null) ? $featured['stats'] : [];
    foreach (['rating', 'rating_count', 'active_players', 'total_visits'] as $field) {
        if (!array_key_exists($field, $body['stats'] ?? [])) {
            continue;
        }
        $raw = $body['stats'][$field];
        if ($raw === '' || $raw === null) {
            $stats[$field] = null;
            continue;
        }
        if (!is_numeric($raw) || (float)$raw < 0 || ($field === 'rating' && (float)$raw > 5)) {
            reply(422, ['error' => 'bad_stat', 'message' => 'Enter a valid value for ' . $field . '.']);
        }
        $stats[$field] = $field === 'rating' ? (float)$raw : (int)$raw;
    }
    $featured['stats'] = $stats;
    $site['featured'] = $featured;
    staff_save_site($site);
    reply(200, ['ok' => true, 'featured' => $featured]);
}

function staff_panel_save_team(array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    if (!is_array($body['groups'] ?? null)) {
        reply(422, ['error' => 'bad_roster', 'message' => 'The roster did not arrive in a valid format.']);
    }
    $site = site_document();
    $team = is_array($site['team'] ?? null) ? $site['team'] : [];
    $previous = [];
    foreach (($team['groups'] ?? []) as $group) {
        if (is_array($group)) {
            $previous[(string)($group['key'] ?? '')] = $group;
        }
    }
    $activeUsers = array_fill_keys(array_map(
        'strtolower',
        database()->query('SELECT username_key FROM staff_users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)
    ), true);
    $groups = [];
    foreach ($body['groups'] as $incoming) {
        if (!is_array($incoming)) {
            continue;
        }
        $key = clean_text($incoming['key'] ?? '', 40, 'Tier');
        if ($key === '') {
            continue;
        }
        $old = $previous[$key] ?? [];
        $members = [];
        foreach (($incoming['members'] ?? []) as $member) {
            if (!is_array($member)) {
                continue;
            }
            $name = clean_text($member['name'] ?? '', 80, 'Name');
            if ($name === '') {
                continue;
            }
            $hasLogin = !empty($member['has_login']) && isset($activeUsers[strtolower($name)]);
            $members[] = [
                'name' => $name,
                'title' => clean_text($member['title'] ?? '', 80, 'Title'),
                'avatar' => clean_text($member['avatar'] ?? '', 200, 'Avatar'),
                'has_login' => $hasLogin,
            ];
        }
        $groups[] = [
            'key' => $key,
            'label' => (string)($old['label'] ?? $incoming['label'] ?? $key),
            'blurb' => clean_text($incoming['blurb'] ?? '', 300, 'Tier description'),
            'roles' => is_array($old['roles'] ?? null) ? $old['roles'] : [],
            'members' => $members,
        ];
    }
    $team['groups'] = $groups;
    $site['team'] = $team;
    staff_save_site($site);
    reply(200, ['ok' => true, 'team' => $team]);
}

function staff_mod_write(array $body, array $session, string $operation): never
{
    $section = clean_text($body['section'] ?? '', 40, 'Section');
    $descriptor = staff_collection_routes()[$section] ?? null;
    if (!is_array($descriptor)) {
        reply(404, ['error' => 'unknown_section', 'message' => 'Unknown moderation section.']);
    }
    if (empty($descriptor['writable'])) {
        reply(403, ['error' => 'read_only', 'message' => 'This log is read-only.']);
    }
    staff_require_tier($session, (string)$descriptor['tier']);
    $kind = (string)$descriptor['kind'];
    $recordId = clean_text($body['id'] ?? '', 64, 'Record id');

    if ($operation === 'delete') {
        $delete = database()->prepare('DELETE FROM bot_records WHERE record_kind = :kind AND record_id = :id');
        $delete->execute(['kind' => $kind, 'id' => $recordId]);
        if (!$delete->rowCount()) {
            reply(404, ['error' => 'not_found', 'message' => 'That record no longer exists.']);
        }
        reply(200, ['ok' => true]);
    }

    $existing = null;
    if ($operation === 'update') {
        $query = database()->prepare('SELECT payload_json FROM bot_records WHERE record_kind = :kind AND record_id = :id LIMIT 1');
        $query->execute(['kind' => $kind, 'id' => $recordId]);
        $raw = $query->fetchColumn();
        $existing = $raw === false ? null : json_decode((string)$raw, true);
        if (!is_array($existing)) {
            reply(404, ['error' => 'not_found', 'message' => 'That record no longer exists.']);
        }
    }

    $record = is_array($existing) ? $existing : [];
    foreach (($descriptor['form'] ?? []) as $field) {
        $key = (string)$field['key'];
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $value = $body[$key];
        $type = (string)($field['type'] ?? 'text');
        if ($type === 'number') {
            if (!is_numeric($value)) {
                reply(422, ['error' => 'bad_number', 'message' => $field['label'] . ' must be a number.']);
            }
            $record[$key] = (float)$value;
        } elseif ($type === 'select') {
            $selected = clean_text($value, 80, (string)$field['label']);
            if ($selected !== '' && !in_array($selected, $field['options'] ?? [], true)) {
                reply(422, ['error' => 'bad_option', 'message' => 'Choose a listed ' . strtolower((string)$field['label']) . '.']);
            }
            $record[$key] = $selected;
        } else {
            $record[$key] = clean_text($value, 2000, (string)$field['label']);
        }
        if (!empty($field['required']) && trim((string)$record[$key]) === '') {
            reply(422, ['error' => 'missing_field', 'message' => $field['label'] . ' is required.']);
        }
    }

    if ($operation === 'create') {
        $record['actor'] = 'panel';
        $record['moderator_name'] = (string)$session['user']['username'];
        if ($kind === 'cases') {
            $record['ref'] = 'C-' . ((int)database()->query("SELECT COUNT(*) FROM bot_records WHERE record_kind = 'cases'")->fetchColumn() + 1);
        } elseif ($kind === 'tickets') {
            $record['ref'] = 'T-' . ((int)database()->query("SELECT COUNT(*) FROM bot_records WHERE record_kind = 'tickets'")->fetchColumn() + 1);
        }
        if (isset($descriptor['filter']['kind'])) {
            $record['kind'] = $descriptor['filter']['kind'];
        }
        reply(200, ['ok' => true, 'record' => bot_add_record($kind, $record)]);
    }

    $record['updated_at'] = microtime(true);
    $update = database()->prepare('UPDATE bot_records SET payload_json = :payload WHERE record_kind = :kind AND record_id = :id');
    $update->execute([
        'payload' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        'kind' => $kind,
        'id' => $recordId,
    ]);
    reply(200, ['ok' => true, 'record' => $record]);
}

function staff_mod_config(array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    bot_write_settings(['patch' => $body]);
}

function staff_panel_create_login(array $body, array $session): never
{
    staff_require_tier($session, 'Management');
    $username = clean_text($body['username'] ?? '', 64, 'Username');
    $email = require_email($body['email'] ?? '');
    $password = $body['password'] ?? null;
    $tier = clean_text($body['tier'] ?? '', 24, 'Rank');
    $title = clean_text($body['title'] ?? $tier, 80, 'Title');
    $discordId = trim((string)($body['discord_id'] ?? ''));
    if ($discordId !== '' && !preg_match('/^[0-9]{17,20}$/', $discordId)) {
        reply(422, ['error'=>'bad_discord_id','message'=>'Enter a valid numeric Discord user ID.']);
    }
    $tiers = ['Leadership', 'Management', 'Staff', 'Development', 'Testing'];
    $actorRank = array_search((string)$session['user']['tier'], $tiers, true);
    $targetRank = array_search($tier, $tiers, true);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
        reply(422, ['error'=>'bad_username','message'=>'Use 3-64 letters, numbers, dots, underscores, or hyphens.']);
    }
    if (!is_string($password) || strlen($password) < 12) {
        reply(422, ['error'=>'weak_password','message'=>'Set a temporary password with at least 12 characters.']);
    }
    if ($actorRank === false || $targetRank === false || $targetRank <= $actorRank) {
        reply(403, ['error'=>'not_outranked','message'=>'You may only create an account below your own rank.']);
    }
    $insert = database()->prepare(
        'INSERT INTO staff_users (id, username, username_key, email, password_hash, tier, title, active, discord_id, created_at) ' .
        'VALUES (:id, :username, :username_key, :email, :password_hash, :tier, :title, 1, :discord_id, UTC_TIMESTAMP())'
    );
    try {
        $id = bin2hex(random_bytes(16));
        $insert->execute([
            'id' => $id,
            'username' => $username,
            'username_key' => strtolower($username),
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'tier' => $tier,
            'title' => $title,
            'discord_id' => $discordId !== '' ? $discordId : null,
        ]);
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'Duplicate')) {
            reply(409, ['error'=>'duplicate','message'=>'That username or email already has a login.']);
        }
        throw $error;
    }
    reply(200, ['ok'=>true,'staff'=>staff_public_user(staff_user($id) ?? [])]);
}

function staff_panel_update_login(string $id, array $body, array $session): never
{
    staff_require_tier($session, 'Management');
    if (hash_equals((string)$session['user']['id'], $id)) {
        reply(403, ['error'=>'self_edit','message'=>'You cannot change your own staff login here.']);
    }
    $target = staff_user($id);
    if (!$target) {
        reply(404, ['error'=>'not_found','message'=>'No such staff login.']);
    }
    $tiers = ['Leadership', 'Management', 'Staff', 'Development', 'Testing'];
    $actorRank = array_search((string)$session['user']['tier'], $tiers, true);
    $oldRank = array_search((string)$target['tier'], $tiers, true);
    $newTier = array_key_exists('tier', $body) ? clean_text($body['tier'], 24, 'Rank') : (string)$target['tier'];
    $newRank = array_search($newTier, $tiers, true);
    if ($actorRank === false || $oldRank === false || $newRank === false ||
        $oldRank <= $actorRank || $newRank <= $actorRank) {
        reply(403, ['error'=>'not_outranked','message'=>'You may only manage accounts below your own rank.']);
    }
    $title = array_key_exists('title', $body) ? clean_text($body['title'], 80, 'Title') : (string)$target['title'];
    $active = array_key_exists('active', $body) ? (!empty($body['active']) ? 1 : 0) : (int)$target['active'];
    $discordId = array_key_exists('discord_id', $body) ? trim((string)$body['discord_id']) : (string)($target['discord_id'] ?? '');
    if ($discordId !== '' && !preg_match('/^[0-9]{17,20}$/', $discordId)) {
        reply(422, ['error'=>'bad_discord_id','message'=>'Enter a valid numeric Discord user ID.']);
    }
    $update = database()->prepare('UPDATE staff_users SET tier = :tier, title = :title, active = :active, discord_id = :discord_id WHERE id = :id');
    $update->execute(['tier'=>$newTier,'title'=>$title,'active'=>$active,'discord_id'=>$discordId !== '' ? $discordId : null,'id'=>$id]);
    if (!$active) {
        $delete = database()->prepare('DELETE FROM staff_sessions WHERE staff_id = :id');
        $delete->execute(['id'=>$id]);
        $pending = database()->prepare('DELETE FROM staff_pending_logins WHERE staff_id = :id');
        $pending->execute(['id'=>$id]);
    }
    reply(200, ['ok'=>true,'staff'=>staff_public_user(staff_user($id) ?? $target)]);
}

function staff_panel_delete_login(string $id, array $session): never
{
    staff_panel_update_login($id, ['active' => false], $session);
}

function staff_panel_update_order(array $body, array $session): never
{
    staff_require_tier($session, 'Leadership');
    $reference = strtoupper(clean_text($body['ref'] ?? '', 12, 'Order reference'));
    $status = clean_text($body['status'] ?? '', 24, 'Order status');
    if (!in_array($status, ['pending_manual','awaiting_payment','paid','fulfillment_pending','delivered','cancelled'], true)) {
        reply(422, ['error'=>'bad_status','message'=>'Choose a valid order status.']);
    }
    $note = clean_text($body['note'] ?? '', 1000, 'Staff note');
    $update = database()->prepare(
        'UPDATE orders SET status = :status, staff_note = :staff_note WHERE reference = :reference'
    );
    $update->execute(['status'=>$status,'staff_note'=>$note,'reference'=>$reference]);
    if (!$update->rowCount()) {
        $check = database()->prepare('SELECT 1 FROM orders WHERE reference = :reference');
        $check->execute(['reference'=>$reference]);
        if (!$check->fetchColumn()) reply(404, ['error'=>'not_found','message'=>'Order not found.']);
    }
    reply(200, ['ok'=>true]);
}

function staff_api_dispatch(string $route, string $method): void
{
    $body = in_array($method, ['POST', 'PATCH'], true) ? staff_request_data() : [];
    $route = trim(substr($route, strlen('staff/api/')), '/');
    if ($route === 'auth/state' && $method === 'GET') {
        staff_auth_state();
    }
    if ($route === 'auth/login' && $method === 'POST') {
        staff_auth_login($body);
    }
    if ($route === 'auth/confirm-email' && $method === 'POST') {
        staff_auth_confirm_email($body);
    }
    if ($route === 'auth/request-code' && $method === 'POST') {
        staff_auth_send_code($body);
    }
    if ($route === 'auth/resend-available' && $method === 'POST') {
        staff_auth_resend_available($body);
    }
    if ($route === 'auth/verify-code' && $method === 'POST') {
        staff_auth_verify_code($body);
    }
    if ($route === 'auth/session/refresh' && $method === 'POST') {
        staff_auth_refresh($body);
    }
    if ($route === 'auth/logout' && $method === 'POST') {
        staff_auth_logout();
    }
    if ($route === 'auth/password-reset/request' && $method === 'POST') {
        staff_password_reset_request($body);
    }
    if ($route === 'auth/password-reset/complete' && $method === 'POST') {
        staff_password_reset_complete($body);
    }
    if ($route === 'auth/passkey/options' && $method === 'POST') {
        staff_auth_passkey_options($body);
    }
    if ($route === 'auth/passkey/verify' && $method === 'POST') {
        staff_auth_passkey_verify($body);
    }
    if ($route === 'auth/passkey/register/options' && $method === 'POST') {
        staff_auth_passkey_register_options();
    }
    if ($route === 'auth/passkey/register/verify' && $method === 'POST') {
        staff_auth_passkey_register_verify($body);
    }
    if (str_starts_with($route, 'panel/')) {
        $session = staff_require_session();
        if ($method !== 'GET') {
            staff_require_csrf($session);
        }
        if ($route === 'panel/registry' && $method === 'GET') {
            staff_panel_registry($session);
        }
        if (str_starts_with($route, 'panel/route/') && $method === 'GET') {
            staff_panel_route(substr($route, strlen('panel/route/')), $session);
        }
        if ($route === 'panel/logins' && $method === 'POST') {
            staff_panel_create_login($body, $session);
        }
        if ($route === 'panel/orders' && $method === 'POST') {
            staff_panel_update_order($body, $session);
        }
        if ($route === 'panel/contact/decide' && $method === 'POST') {
            staff_panel_contact_decide($body, $session);
        }
        if ($route === 'panel/contact' && $method === 'POST') {
            staff_require_tier($session, 'Staff');
            $reference = clean_text($body['ref'] ?? '', 12, 'Reference');
            $status = clean_text($body['status'] ?? 'pending', 16, 'Status');
            if (!in_array($status, ['pending', 'approved', 'denied'], true)) {
                reply(422, ['error' => 'bad_status', 'message' => 'Unknown suggestion status.']);
            }
            $update = database()->prepare(
                'UPDATE contact_messages SET status = :status WHERE reference = :reference'
            );
            $update->execute(['status' => $status, 'reference' => $reference]);
        }
        if (str_starts_with($route, 'panel/logins/') && $method === 'PATCH') {
            staff_panel_update_login(substr($route, strlen('panel/logins/')), $body, $session);
        }
        if (str_starts_with($route, 'panel/logins/') && $method === 'DELETE') {
            staff_panel_delete_login(substr($route, strlen('panel/logins/')), $session);
        }
        if ($route === 'panel/logins' && $method === 'GET') {
            reply(200, ['ok' => true]);
        }
        if ($route === 'panel/contact/delete' && $method === 'POST') {
            staff_require_tier($session, 'Staff');
            $delete = database()->prepare('DELETE FROM contact_messages WHERE reference = :reference');
            $delete->execute(['reference' => clean_text($body['ref'] ?? '', 12, 'Reference')]);
            reply(200, ['ok' => true]);
        }
        if ($route === 'panel/applications' && $method === 'POST') {
            staff_panel_save_application($body, $session);
        }
        if ($route === 'panel/applications/delete' && $method === 'POST') {
            staff_panel_delete_application($body, $session);
        }
        if ($route === 'panel/applications' && $method === 'GET') {
            staff_require_tier($session, 'Staff');
            reply(200, staff_applications_view());
        }
        if ($route === 'panel/contact' && $method === 'GET') {
            staff_require_tier($session, 'Staff');
            reply(200, staff_contact_view());
        }
        if ($route === 'panel/games' && $method === 'POST') {
            staff_panel_games($route, $body, $session);
        }
        if ($route === 'panel/games/delete' && $method === 'POST') {
            staff_panel_games($route, $body, $session);
        }
        if (str_starts_with($route, 'panel/games/') && $method === 'PATCH') {
            $body['id'] = substr($route, strlen('panel/games/'));
            staff_panel_games('panel/games', $body, $session);
        }
        if ($route === 'panel/featured' && $method === 'POST') {
            staff_panel_featured($body, $session);
        }
        if ($route === 'panel/team' && $method === 'POST') {
            staff_panel_save_team($body, $session);
        }
        if ($route === 'panel/mod' && $method === 'POST') {
            staff_mod_write($body, $session, 'create');
        }
        if ($route === 'panel/mod/update' && $method === 'POST') {
            staff_mod_write($body, $session, 'update');
        }
        if ($route === 'panel/mod/delete' && $method === 'POST') {
            staff_mod_write($body, $session, 'delete');
        }
        if ($route === 'panel/mod/config' && $method === 'POST') {
            staff_mod_config($body, $session);
        }
        if (in_array($route, ['panel/announcements', 'panel/announcements/update', 'panel/announcements/delete'], true)
            && $method === 'POST') {
            staff_panel_announcements($route, $body, $session);
        }
        reply(404, ['error' => 'not_found', 'message' => 'Unknown staff panel route.']);
    }
    reply(404, ['error' => 'not_found', 'message' => 'Unknown staff API route.']);
}

function staff_passkey_challenge(?string $staffId, string $ceremony): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $query = database()->prepare(
        'INSERT INTO staff_passkey_challenges (token_hash, staff_id, ceremony, expires_at) ' .
        'VALUES (:token_hash, :staff_id, :ceremony, UTC_TIMESTAMP() + INTERVAL 5 MINUTE)'
    );
    $query->execute(['token_hash'=>hash('sha256', $token), 'staff_id'=>$staffId, 'ceremony'=>$ceremony]);
    return $token;
}

function staff_consume_passkey(string $token, string $ceremony): array
{
    $query = database()->prepare(
        'SELECT token_hash, staff_id FROM staff_passkey_challenges ' .
        'WHERE token_hash = :token_hash AND ceremony = :ceremony AND expires_at > UTC_TIMESTAMP() LIMIT 1'
    );
    $query->execute(['token_hash'=>hash('sha256', $token), 'ceremony'=>$ceremony]);
    $row = $query->fetch();
    $delete = database()->prepare('DELETE FROM staff_passkey_challenges WHERE token_hash = :token_hash');
    $delete->execute(['token_hash'=>hash('sha256', $token)]);
    if (!$row) reply(401, ['error'=>'challenge_expired','message'=>'That passkey request expired. Try again.']);
    return $row;
}

function staff_passkey_origin(): array
{
    try { return wa_origin(); }
    catch (Throwable $error) { reply(400, ['error'=>'bad_origin','message'=>'Passkeys require the secure site address.']); }
}

function staff_auth_passkey_options(array $body): never
{
    enforce_rate('staff-passkey', 8, 900);
    $username = clean_text($body['username'] ?? '', 64, 'Username');
    $query = database()->prepare('SELECT id, username FROM staff_users WHERE username_key = :key AND active = 1 LIMIT 1');
    $query->execute(['key'=>strtolower($username)]);
    $user = $query->fetch();
    if (!$user) reply(401, ['error'=>'no_passkey','message'=>'No passkey is registered for that account.']);
    $key = database()->prepare('SELECT credential_id FROM staff_passkeys WHERE staff_id = :id LIMIT 1');
    $key->execute(['id'=>$user['id']]);
    if (!$key->fetchColumn()) reply(401, ['error'=>'no_passkey','message'=>'No passkey is registered for that account.']);
    $challenge = staff_passkey_challenge((string)$user['id'], 'get');
    [$origin, $rpId] = staff_passkey_origin();
    reply(200, ['challenge'=>$challenge, 'rp'=>['id'=>$rpId], 'timeout'=>120000, 'user'=>$user['username'], 'origin'=>$origin]);
}

function staff_auth_passkey_verify(array $body): never
{
    $username = clean_text($body['username'] ?? '', 64, 'Username');
    $response = is_array($body['response'] ?? null) ? $body['response'] : [];
    $query = database()->prepare('SELECT * FROM staff_users WHERE username_key = :key AND active = 1 LIMIT 1');
    $query->execute(['key'=>strtolower($username)]);
    $user = $query->fetch();
    $challenge = (string)($response['challenge'] ?? '');
    $issued = staff_consume_passkey($challenge, 'get');
    if (!$user || (string)$issued['staff_id'] !== (string)$user['id']) reply(401, ['error'=>'passkey_invalid','message'=>'The passkey was not accepted.']);
    $keyQuery = database()->prepare('SELECT * FROM staff_passkeys WHERE staff_id = :staff_id AND credential_id = :credential LIMIT 1');
    $keyQuery->execute(['staff_id'=>$user['id'], 'credential'=>(string)($response['credentialId'] ?? '')]);
    $key = $keyQuery->fetch();
    if (!$key) reply(401, ['error'=>'passkey_invalid','message'=>'The passkey was not accepted.']);
    [$origin, $rpId] = staff_passkey_origin();
    $key['public_x'] = db_bin_unpack(database(), (string)$key['public_x']);
    $key['public_y'] = db_bin_unpack(database(), (string)$key['public_y']);
    try { $count = wa_verify((string)$response['authenticatorData'], (string)$response['clientDataJSON'], (string)$response['credentialId'], (string)$response['signature'], $key, $challenge, $origin, $rpId); }
    catch (Throwable $error) { reply(401, ['error'=>'passkey_invalid','message'=>'The passkey was not accepted.']); }
    $update = database()->prepare('UPDATE staff_passkeys SET sign_count = :count, last_used_at = UTC_TIMESTAMP() WHERE credential_id = :credential');
    $update->execute(['count'=>$count, 'credential'=>$key['credential_id']]);
    $session = staff_create_session($user, (bool)($body['remember'] ?? false));
    reply(200, [
        'ok'=>true, 'csrf'=>$session['csrf'], 'staff'=>staff_public_user($user),
        'account_required'=>!staff_account_mfa_matches($user),
    ]);
}

function staff_auth_passkey_register_options(): never
{
    $session = staff_require_session();
    $challenge = staff_passkey_challenge((string)$session['staff_id'], 'create');
    [$origin, $rpId] = staff_passkey_origin();
    $userId = rtrim(strtr(base64_encode(hash('sha256', (string)$session['staff_id'], true)), '+/', '-_'), '=');
    reply(200, ['challenge'=>$challenge, 'rp'=>['id'=>$rpId, 'name'=>'Stitchline Interactive'], 'user'=>['id'=>$userId, 'name'=>(string)$session['user']['username'], 'displayName'=>(string)$session['user']['username']], 'pubKeyCredParams'=>[['type'=>'public-key','alg'=>-7]], 'timeout'=>120000, 'attestation'=>'none', 'authenticatorSelection'=>['residentKey'=>'preferred','userVerification'=>'preferred'], 'origin'=>$origin]);
}

function staff_auth_passkey_register_verify(array $body): never
{
    $session = staff_require_session();
    staff_require_csrf($session);
    $response = is_array($body['response'] ?? null) ? $body['response'] : [];
    $challenge = (string)($response['challenge'] ?? '');
    $issued = staff_consume_passkey($challenge, 'create');
    if ((string)$issued['staff_id'] !== (string)$session['staff_id']) reply(401, ['error'=>'passkey_invalid','message'=>'The passkey request was not issued for this account.']);
    [$origin, $rpId] = staff_passkey_origin();
    try { $key = wa_registration((string)$response['attestationObject'], (string)$response['clientDataJSON'], $challenge, $origin, $rpId); }
    catch (Throwable $error) { reply(422, ['error'=>'passkey_invalid','message'=>'The passkey could not be registered.']); }
    $insert = database()->prepare('INSERT INTO staff_passkeys (credential_id, staff_id, public_x, public_y, sign_count) VALUES (:credential, :staff_id, :x, :y, :count) ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id), public_x = VALUES(public_x), public_y = VALUES(public_y), sign_count = VALUES(sign_count)');
    $insert->execute(['credential'=>$key['credential_id'], 'staff_id'=>$session['staff_id'], 'x'=>db_bin_pack(database(), (string)$key['public_x']), 'y'=>db_bin_pack(database(), (string)$key['public_y']), 'count'=>$key['sign_count']]);
    reply(200, ['ok'=>true]);
}

function staff_password_reset_request(array $body): never
{
    enforce_rate('staff-password-reset', 5, 900);
    $email = strtolower(clean_text($body['email'] ?? '', 254, 'Email'));
    $generic = ['ok'=>true, 'message'=>'If that address belongs to a staff account, a reset link has been sent.'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) reply(200, $generic);
    $query = database()->prepare('SELECT id FROM staff_users WHERE email = :email AND active = 1 LIMIT 1');
    $query->execute(['email'=>$email]);
    $staffId = $query->fetchColumn();
    if (!$staffId) reply(200, $generic);
    $token = bin2hex(random_bytes(32));
    $insert = database()->prepare('INSERT INTO staff_password_resets (token_hash, staff_id, expires_at) VALUES (:token, :staff, UTC_TIMESTAMP() + INTERVAL 20 MINUTE)');
    $insert->execute(['token'=>hash('sha256', $token), 'staff'=>$staffId]);
    $mail = settings()['mail'] ?? [];
    $base = rtrim((string)(settings()['payments']['public_base_url'] ?? ''), '/');
    if (!empty($mail['from']) && $base !== '') mail($email, 'Reset your Stitchline staff password', 'Reset your password here: ' . $base . '/staff?reset=' . rawurlencode($token), ['From'=>(string)$mail['from'], 'Content-Type'=>'text/plain; charset=UTF-8']);
    reply(200, $generic);
}

function staff_password_reset_complete(array $body): never
{
    $token = clean_text($body['token'] ?? '', 128, 'Reset token');
    $password = (string)($body['password'] ?? '');
    if (strlen($password) < 12 || strlen($password) > 1024) reply(422, ['error'=>'weak_password','message'=>'Use a password of at least 12 characters.']);
    $query = database()->prepare('SELECT staff_id FROM staff_password_resets WHERE token_hash = :token AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $query->execute(['token'=>hash('sha256', $token)]);
    $staffId = $query->fetchColumn();
    if (!$staffId) reply(401, ['error'=>'reset_expired','message'=>'That reset link has expired. Request another.']);
    $pdo = database(); $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE staff_users SET password_hash = :password, email_verified = 1 WHERE id = :id');
        $update->execute(['password'=>password_hash($password, PASSWORD_DEFAULT), 'id'=>$staffId]);
        $pdo->prepare('DELETE FROM staff_password_resets WHERE token_hash = :token')->execute(['token'=>hash('sha256', $token)]);
        $pdo->prepare('DELETE FROM staff_sessions WHERE staff_id = :id')->execute(['id'=>$staffId]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    reply(200, ['ok'=>true, 'message'=>'Password changed. Sign in with the new password.']);
}