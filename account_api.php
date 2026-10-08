<?php
declare(strict_types=1);

function account_api_dispatch(string $route, string $method): never
{
    if ($route === 'connect' && $method === 'GET') account_oauth_start();
    if ($route === 'callback' && $method === 'GET') account_oauth_callback();
    if ($route === 'roblox/connect' && $method === 'GET') account_roblox_oauth_start();
    if ($route === 'roblox/callback' && $method === 'GET') account_roblox_oauth_callback();
    if ($route === 'pending' && $method === 'POST') account_pending_state(request_body());
    if ($route === 'setup' && $method === 'POST') account_setup(request_body());
    if ($route === 'resend' && $method === 'POST') account_resend(request_body());
    if ($route === 'verify' && $method === 'POST') account_verify(request_body());
    if ($route === 'state' && $method === 'GET') account_state();
    if ($route === 'dashboard' && $method === 'GET') account_dashboard();
    if ($route === 'payment-methods' && $method === 'POST') account_saved_payment_methods();
    if ($route === 'appeal' && $method === 'POST') account_appeal(request_body());
    if ($route === 'link-staff' && $method === 'POST') account_link_staff(request_body());
    if ($route === 'staff-password-reset' && $method === 'POST') account_staff_password_reset();
    if ($route === 'logout' && $method === 'POST') account_logout();
    reply(404, ['error'=>'not_found','message'=>'Unknown account endpoint.']);
}

function account_config(): array
{
    return is_array(settings()['discord_oauth'] ?? null) ? settings()['discord_oauth'] : [];
}

function account_roblox_config(): array
{
    $oauth = is_array(settings()['roblox_oauth'] ?? null) ? settings()['roblox_oauth'] : [];
    foreach ([
        'client_id'=>'ROBLOX_CLIENT_ID',
        'client_secret'=>'ROBLOX_CLIENT_SECRET',
        'redirect_uri'=>'ROBLOX_REDIRECT_URI',
    ] as $key=>$environmentVariable) {
        if (empty($oauth[$key])) {
            $value = getenv($environmentVariable);
            if ($value !== false) $oauth[$key] = $value;
        }
    }
    return $oauth;
}

function account_roblox_oauth_ready(): bool
{
    $oauth = account_roblox_config();
    return !empty($oauth['client_id']) && !empty($oauth['client_secret']) &&
        !empty($oauth['redirect_uri']) && function_exists('curl_init');
}

function account_services_ready(): bool
{
    $oauth=account_config();
    $mail=is_array(settings()['mail'] ?? null) ? settings()['mail'] : [];
    $secret=(string)(settings()['app_secret'] ?? '');
    return !empty($oauth['client_id']) && !empty($oauth['client_secret']) && !empty($oauth['redirect_uri']) &&
        !empty($mail['from']) && strlen($secret)>=32 && !str_starts_with($secret,'REPLACE_WITH_') &&
        function_exists('curl_init');
}

function account_oauth_start(): never
{
    enforce_rate('account-oauth', 20, 900);
    $oauth = account_config();
    if (!account_services_ready()) reply(503, ['error'=>'account_not_configured','message'=>'Discord and email account verification are not configured yet.']);
    $state = bin2hex(random_bytes(32));
    $staffReturn = (string)($_GET['staff'] ?? '') === '1';
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    setcookie('si_account_oauth', $state, [
        'expires'=>time()+600, 'path'=>'/api/account', 'secure'=>$secure,
        'httponly'=>true, 'samesite'=>'Lax',
    ]);
    setcookie('si_account_staff_return', $staffReturn ? '1' : '', [
        'expires'=>time()+600, 'path'=>'/api/account', 'secure'=>$secure,
        'httponly'=>true, 'samesite'=>'Lax',
    ]);
    $query = http_build_query([
        'client_id'=>(string)$oauth['client_id'],
        'redirect_uri'=>(string)$oauth['redirect_uri'],
        'response_type'=>'code',
        'scope'=>'identify',
        'state'=>$state,
    ]);
    header('Location: https://discord.com/oauth2/authorize?' . $query, true, 302);
    exit;
}

function account_roblox_oauth_start(): never
{
    enforce_rate('account-roblox-oauth', 10, 900);
    account_require_session();
    if (!account_roblox_oauth_ready()) {
        reply(503, ['error'=>'roblox_not_configured','message'=>'Roblox verification is not configured on this host yet.']);
    }

    $state = bin2hex(random_bytes(32));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    foreach (['si_roblox_oauth_state'=>$state, 'si_roblox_oauth_verifier'=>$verifier] as $name=>$value) {
        setcookie($name, $value, [
            'expires'=>time()+600, 'path'=>'/api/account', 'secure'=>$secure,
            'httponly'=>true, 'samesite'=>'Lax',
        ]);
    }
    $oauth = account_roblox_config();
    $query = http_build_query([
        'client_id'=>(string)$oauth['client_id'],
        'redirect_uri'=>(string)$oauth['redirect_uri'],
        'response_type'=>'code',
        'scope'=>'openid profile',
        'state'=>$state,
        'code_challenge'=>$challenge,
        'code_challenge_method'=>'S256',
    ]);
    header('Location: https://apis.roblox.com/oauth/v1/authorize?' . $query, true, 302);
    exit;
}

function account_roblox_oauth_callback(): never
{
    $state = (string)($_GET['state'] ?? '');
    $cookieState = (string)($_COOKIE['si_roblox_oauth_state'] ?? '');
    $verifier = (string)($_COOKIE['si_roblox_oauth_verifier'] ?? '');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    foreach (['si_roblox_oauth_state', 'si_roblox_oauth_verifier'] as $name) {
        setcookie($name, '', [
            'expires'=>time()-3600, 'path'=>'/api/account', 'secure'=>$secure,
            'httponly'=>true, 'samesite'=>'Lax',
        ]);
    }
    if ($state === '' || $cookieState === '' || !hash_equals($cookieState, $state) ||
        strlen($state) !== 64 || $verifier === '') {
        reply(400, ['error'=>'oauth_state','message'=>'The Roblox verification expired. Start again.']);
    }
    if (!empty($_GET['error']) || empty($_GET['code'])) {
        header('Location: /account.html?error=roblox_cancelled', true, 302);
        exit;
    }
    if (!account_session()) {
        header('Location: /account.html?error=roblox_session_required', true, 302);
        exit;
    }
    if (!account_roblox_oauth_ready()) {
        header('Location: /account.html?error=roblox_not_configured', true, 302);
        exit;
    }

    $oauth = account_roblox_config();
    try {
        $token = account_http('https://apis.roblox.com/oauth/v1/token', 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id'=>(string)$oauth['client_id'],
            'client_secret'=>(string)$oauth['client_secret'],
            'grant_type'=>'authorization_code',
            'code'=>clean_text($_GET['code'], 2048, 'Roblox code'),
            'redirect_uri'=>(string)$oauth['redirect_uri'],
            'code_verifier'=>$verifier,
        ]));
        $accessToken = (string)($token['access_token'] ?? '');
        if ($accessToken === '') throw new RuntimeException('Roblox did not return an access token.');
        $profile = account_http('https://apis.roblox.com/oauth/v1/userinfo', 'GET', [
            'Authorization: Bearer ' . $accessToken,
        ], '');
    } catch (Throwable $error) {
        error_log('Roblox OAuth failed: ' . $error->getMessage());
        header('Location: /account.html?error=roblox_unavailable', true, 302);
        exit;
    }

    $robloxId = (string)($profile['sub'] ?? '');
    $username = trim((string)($profile['preferred_username'] ?? $profile['nickname'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $robloxId) || $username === '' || strlen($username) > 100) {
        error_log('Roblox OAuth returned an incomplete user profile.');
        header('Location: /account.html?error=roblox_profile', true, 302);
        exit;
    }

    $account = account_require_session();
    $pdo = database();
    $existing = $pdo->prepare(
        'SELECT roblox_user_id FROM customer_roblox_links WHERE discord_id = :discord_id LIMIT 1'
    );
    $existing->execute(['discord_id'=>$account['discord_id']]);
    $linkedId = $existing->fetchColumn();
    if ($linkedId !== false && (string)$linkedId !== $robloxId) {
        header('Location: /account.html?error=roblox_already_linked', true, 302);
        exit;
    }
    $other = $pdo->prepare(
        'SELECT discord_id FROM customer_roblox_links WHERE roblox_user_id = :roblox_id LIMIT 1'
    );
    $other->execute(['roblox_id'=>$robloxId]);
    $linkedDiscordId = $other->fetchColumn();
    if ($linkedDiscordId !== false && (string)$linkedDiscordId !== (string)$account['discord_id']) {
        header('Location: /account.html?error=roblox_in_use', true, 302);
        exit;
    }

    if ($linkedId !== false) {
        $save = $pdo->prepare(
            'UPDATE customer_roblox_links SET roblox_username = :username, verified_at = UTC_TIMESTAMP() ' .
            'WHERE discord_id = :discord_id AND roblox_user_id = :roblox_id'
        );
        $save->execute([
            'username'=>$username, 'discord_id'=>$account['discord_id'], 'roblox_id'=>$robloxId,
        ]);
    } else {
        try {
            $save = $pdo->prepare(
                'INSERT INTO customer_roblox_links (discord_id, roblox_user_id, roblox_username, verified_at) ' .
                'VALUES (:discord_id, :roblox_id, :username, UTC_TIMESTAMP())'
            );
            $save->execute([
                'discord_id'=>$account['discord_id'], 'roblox_id'=>$robloxId, 'username'=>$username,
            ]);
        } catch (PDOException $error) {
            if ((string)$error->getCode() === '23000') {
                header('Location: /account.html?error=roblox_in_use', true, 302);
                exit;
            }
            throw $error;
        }
    }

    header('Location: /account.html?roblox=linked', true, 302);
    exit;
}

function account_oauth_callback(): never
{
    $oauth = account_config();
    $state = (string)($_GET['state'] ?? '');
    $cookie = (string)($_COOKIE['si_account_oauth'] ?? '');
    $staffReturn = (string)($_COOKIE['si_account_staff_return'] ?? '') === '1';
    setcookie('si_account_oauth', '', ['expires'=>time()-3600,'path'=>'/api/account','httponly'=>true,'samesite'=>'Lax']);
    setcookie('si_account_staff_return', '', ['expires'=>time()-3600,'path'=>'/api/account','httponly'=>true,'samesite'=>'Lax']);
    if ($state === '' || $cookie === '' || !hash_equals($cookie, $state) || strlen($state) !== 64) {
        reply(400, ['error'=>'oauth_state','message'=>'The Discord verification expired. Start again.']);
    }
    if (!empty($_GET['error']) || empty($_GET['code'])) {
        header('Location: /account.html?error=discord_cancelled' . ($staffReturn ? '#staff=1' : ''), true, 302);
        exit;
    }
    if (empty($oauth['client_id']) || empty($oauth['client_secret']) || empty($oauth['redirect_uri'])) {
        reply(503, ['error'=>'account_not_configured','message'=>'Discord account verification is not configured yet.']);
    }
    try {
        $token = account_http('https://discord.com/api/v10/oauth2/token', 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id'=>(string)$oauth['client_id'],
            'client_secret'=>(string)$oauth['client_secret'],
            'grant_type'=>'authorization_code',
            'code'=>clean_text($_GET['code'], 2048, 'Discord code'),
            'redirect_uri'=>(string)$oauth['redirect_uri'],
        ]));
        $profile = account_http('https://discord.com/api/v10/users/@me', 'GET', [
            'Authorization: Bearer ' . (string)($token['access_token'] ?? ''),
        ], '');
    } catch (Throwable $error) {
        error_log('Discord OAuth failed: ' . $error->getMessage());
        reply(502, ['error'=>'discord_unavailable','message'=>'Discord could not verify the account. Try again.']);
    }
    $discordId = (string)($profile['id'] ?? '');
    $username = trim((string)($profile['global_name'] ?? $profile['username'] ?? ''));
    if (!preg_match('/^[0-9]{17,20}$/', $discordId) || $username === '') {
        reply(502, ['error'=>'discord_profile','message'=>'Discord returned an incomplete account profile.']);
    }
    $avatarHash = (string)($profile['avatar'] ?? '');
    $avatarUrl = preg_match('/^[A-Za-z0-9_]+$/', $avatarHash)
        ? 'https://cdn.discordapp.com/avatars/' . rawurlencode($discordId) . '/' . rawurlencode($avatarHash) . '.png?size=64'
        : 'https://cdn.discordapp.com/embed/avatars/0.png';
    $query = database()->prepare(    'SELECT email, email_verified FROM customer_accounts WHERE discord_id = :id LIMIT 1');
    $query->execute(['id'=>$discordId]);
    $account = $query->fetch();
        $stage = $account && (bool)$account['email_verified'] ? 'verify' : 'setup';
    $pending = account_create_pending($discordId, $username, $avatarUrl, $stage);
    if ($stage === 'verify') {
            account_issue_codes($pending, (string)$account['email']);
    }
    header('Location: /account.html#pending=' . rawurlencode($pending) . ($staffReturn ? '&staff=1' : ''), true, 302);
    exit;
}

function account_http(string $url, string $method, array $headers, string $body): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('cURL is required for Discord account verification.');
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_POSTFIELDS=>$body,
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Provider request failed (' . $status . '): ' . substr($error, 0, 120));
    }
    return $data;
}

function account_create_pending(string $discordId, string $username, string $avatarUrl, string $stage): string
{
    $token = bin2hex(random_bytes(32));
    $insert = database()->prepare(
        'INSERT INTO customer_pending_logins (token_hash, discord_id, discord_username, avatar_url, stage, expires_at) ' .
        'VALUES (:token, :id, :username, :avatar, :stage, UTC_TIMESTAMP() + INTERVAL 15 MINUTE)'
    );
    $insert->execute(['token'=>hash('sha256',$token),'id'=>$discordId,'username'=>$username,'avatar'=>$avatarUrl,'stage'=>$stage]);
    return $token;
}

function account_pending(string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) reply(400, ['error'=>'pending_required','message'=>'Start Discord verification again.']);
    $query = database()->prepare('SELECT * FROM customer_pending_logins WHERE token_hash = :token AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $query->execute(['token'=>hash('sha256',$token)]);
    $pending = $query->fetch();
    if (!$pending) reply(401, ['error'=>'pending_expired','message'=>'This verification expired. Start again.']);
    return $pending;
}

function account_pending_state(array $body): never
{
    $pending = account_pending((string)($body['pending'] ?? ''));
    reply(200, [
        'stage'=>(string)$pending['stage'],
        'discord_username'=>(string)$pending['discord_username'],
        'avatar_url'=>(string)$pending['avatar_url'],
        'email_hint'=>account_mask_email((string)$pending['email']),
        'resend_after'=>$pending['resend_after'],
    ]);
}

function account_mask_email(string $email): string
{
    if (!str_contains($email, '@')) return '';
    [$name,$domain] = explode('@',$email,2);
    return substr($name,0,1) . str_repeat('*',max(2,min(8,strlen($name)-1))) . '@' . $domain;
}

function account_setup(array $body): never
{
    enforce_rate('account-setup', 8, 900);
    $pending = account_pending((string)($body['pending'] ?? ''));
    if ((string)$pending['stage'] !== 'setup') reply(409, ['error'=>'wrong_step','message'=>'This account is already set up.']);
    $email = strtolower(require_email($body['email'] ?? ''));
    account_issue_codes((string)$body['pending'],$email);
    reply(200, ['ok'=>true,'email_hint'=>account_mask_email($email),'expires_in'=>600]);
}

function account_issue_codes(string $pendingToken, string $email): void
{
    $pending = account_pending($pendingToken);
    if (!empty($pending['resend_after']) && strtotime((string)$pending['resend_after'] . ' UTC') > time()) {
        reply(429, ['error'=>'cooldown','message'=>'Wait before requesting another verification code.']);
    }
    $emailCode = (string)random_int(100000,999999);
    $secret = (string)(settings()['app_secret'] ?? '');
    if (strlen($secret) < 32) reply(503, ['error'=>'not_configured','message'=>'Account verification is not configured securely.']);
    $emailHash = hash_hmac('sha256',(string)$pending['discord_id'] . "\nemail\n" . $emailCode,$secret);
    $mail = send_site_mail($email,'Verify your Stitchline account','Your email verification code is ' . $emailCode . '. It expires in 10 minutes.');
    if (!$mail) reply(502, ['error'=>'email_delivery','message'=>'The email code could not be sent. Check the mail configuration.']);
    $update = database()->prepare(
        'UPDATE customer_pending_logins SET email = :email, email_code_hash = :email_hash, ' .
        'failures = 0, resend_after = UTC_TIMESTAMP() + INTERVAL 45 SECOND, stage = \'verify\' ' .
        'WHERE token_hash = :token'
    );
    $update->execute([
        'email'=>$email,'email_hash'=>$emailHash,
        'token'=>hash('sha256',$pendingToken),
    ]);
}

function account_resend(array $body): never
{
    enforce_rate('account-resend', 5, 900);
    $pending = account_pending((string)($body['pending'] ?? ''));
    if (empty($pending['email'])) reply(409,['error'=>'setup_required','message'=>'Enter your email first.']);
    account_issue_codes((string)$body['pending'],(string)$pending['email']);
    reply(200,['ok'=>true,'email_hint'=>account_mask_email((string)$pending['email']),'expires_in'=>600]);
}

function account_verify(array $body): never
{
    enforce_rate('account-verify', 10, 900);
    $raw = (string)($body['pending'] ?? '');
    $pending = account_pending($raw);
    if ((string)$pending['stage'] !== 'verify' || empty($pending['email_code_hash'])) {
        reply(409,['error'=>'wrong_step','message'=>'Request a verification code first.']);
    }
    $emailCode = clean_text($body['email_code'] ?? '',6,'Email code');
    if (!preg_match('/^[0-9]{6}$/',$emailCode)) reply(422,['error'=>'bad_code','message'=>'Enter the six-digit code.']);
    $secret=(string)(settings()['app_secret'] ?? '');
    $expectedEmail=hash_hmac('sha256',(string)$pending['discord_id'] . "\nemail\n" . $emailCode,$secret);
    if (!hash_equals((string)$pending['email_code_hash'],$expectedEmail)) {
        $update=database()->prepare('UPDATE customer_pending_logins SET failures = failures + 1 WHERE token_hash = :token');
        $update->execute(['token'=>hash('sha256',$raw)]);
        if ((int)$pending['failures'] >= 4) {
            database()->prepare('DELETE FROM customer_pending_logins WHERE token_hash = :token')->execute(['token'=>hash('sha256',$raw)]);
            reply(429,['error'=>'too_many_attempts','message'=>'Too many incorrect codes. Restart verification.']);
        }
        reply(401,['error'=>'bad_code','message'=>'That code was incorrect.']);
    }
    $pdo=database();
    $pdo->beginTransaction();
    try {
        $save=$pdo->prepare(
            'INSERT INTO customer_accounts (discord_id, discord_username, avatar_url, email, phone_number, email_verified, phone_verified, created_at, last_login) ' .
            'VALUES (:id,:username,:avatar,:email,:phone,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ' .
            'ON DUPLICATE KEY UPDATE discord_username=VALUES(discord_username), avatar_url=VALUES(avatar_url), email=VALUES(email), ' .
            'email_verified=1, phone_verified=1, last_login=UTC_TIMESTAMP()'
        );
        $save->execute(['id'=>$pending['discord_id'],'username'=>$pending['discord_username'],'avatar'=>$pending['avatar_url'],'email'=>$pending['email'],'phone'=>'']);
        $pdo->prepare('DELETE FROM customer_pending_logins WHERE token_hash = :token')->execute(['token'=>hash('sha256',$raw)]);
        /* The bot watches this queue and reports the member's Discord
           roles back, so a verified member who holds a studio role is
           linked to a staff login without anyone editing the roster by
           hand. Re-verifying re-queues the check. */
        $pdo->prepare(
            'INSERT INTO discord_role_checks (discord_id, discord_username, requested_at) ' .
            'VALUES (:id, :username, UTC_TIMESTAMP()) ' .
            'ON DUPLICATE KEY UPDATE discord_username = VALUES(discord_username), ' .
            'requested_at = UTC_TIMESTAMP(), checked_at = NULL, roles_json = NULL, ' .
            'matched_role = NULL, staff_tier = NULL'
        )->execute(['id'=>$pending['discord_id'],'username'=>$pending['discord_username']]);
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    $session=account_create_session((string)$pending['discord_id']);
    reply(200,['ok'=>true,'csrf'=>$session['csrf']]);
}

function account_create_session(string $discordId): array
{
    $token=bin2hex(random_bytes(32)); $csrf=bin2hex(random_bytes(32)); $seconds=8*3600;
    $insert=database()->prepare('INSERT INTO customer_sessions (token_hash,discord_id,csrf_token,expires_at,created_at) VALUES (:token,:id,:csrf,UTC_TIMESTAMP()+INTERVAL 8 HOUR,UTC_TIMESTAMP())');
    $insert->execute(['token'=>hash('sha256',$token),'id'=>$discordId,'csrf'=>$csrf]);
    $secure=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))==='https';
    setcookie('si_account',$token,['expires'=>time()+$seconds,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    return ['csrf'=>$csrf];
}

function account_session(): ?array
{
    $token=(string)($_COOKIE['si_account'] ?? '');
    if ($token==='' || strlen($token)!==64) return null;
    $query=database()->prepare(
        'SELECT s.token_hash,s.discord_id,s.csrf_token,a.discord_username,a.avatar_url,a.stripe_customer_id,a.email,a.phone_number,a.email_verified,a.phone_verified ' .
        'FROM customer_sessions s JOIN customer_accounts a ON a.discord_id=s.discord_id ' .
        'WHERE s.token_hash=:token AND s.expires_at>UTC_TIMESTAMP() LIMIT 1'
    );
    $query->execute(['token'=>hash('sha256',$token)]);
    $row=$query->fetch(); return $row ?: null;
}

function account_require_session(): array
{
    $session=account_session();
    if (!$session) reply(401,['error'=>'no_session','message'=>'Connect Discord and verify both contact methods to continue.']);
    return $session;
}

function account_require_csrf(array $session): void
{
    $sent=(string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent==='' || !hash_equals((string)$session['csrf_token'],$sent)) reply(403,['error'=>'csrf','message'=>'Reload your account dashboard and try again.']);
}

function account_state(): never
{
    $session=account_session();
    $staff=null;
    if ($session) {
        $query=database()->prepare('SELECT id,username,title,tier FROM staff_users WHERE discord_id=:id AND active=1 LIMIT 1');
        $query->execute(['id'=>$session['discord_id']]);
        $staff=$query->fetch() ?: null;
    }
    reply(200,[
        'authenticated'=>(bool)$session,
        'configured'=>account_services_ready(),
        'roblox_configured'=>account_roblox_oauth_ready(),
        'csrf'=>$session ? (string)$session['csrf_token'] : '',
        'discord'=>$session ? [
            'id'=>(string)$session['discord_id'],
            'username'=>(string)$session['discord_username'],
            'avatar_url'=>(string)$session['avatar_url'],
        ] : null,
        'staff'=>(bool)$staff,
    ]);
}

function account_dashboard(): never
{
    $session=account_require_session();
    $id=(string)$session['discord_id'];
    $stripeCustomer=account_stripe_customer($session);
    $orders=database()->prepare('SELECT reference,created_at,status,provider,subtotal,total,lines_json FROM orders WHERE discord_id=:id ORDER BY created_at DESC LIMIT 100');
    $orders->execute(['id'=>$id]);
    $orderRows=[];
    foreach ($orders->fetchAll() as $order) {
        $orderRows[]=[
            'reference'=>(string)$order['reference'],'created_at'=>(string)$order['created_at'],'status'=>(string)$order['status'],
            'provider'=>(string)$order['provider'],'subtotal'=>(float)$order['subtotal'],'total'=>(float)$order['total'],
            'items'=>json_decode((string)$order['lines_json'],true) ?: [],
        ];
    }
    $actions=account_user_actions($id);
    $appeals=database()->prepare('SELECT reference,case_reference,status,staff_reply,received_at FROM contact_messages WHERE discord_id=:id AND topic=\'appeal\' ORDER BY received_at DESC LIMIT 100');
    $appeals->execute(['id'=>$id]);
    $payment=payment_config();
    $methods=[];
    $methods[]=['name'=>'Stripe Checkout','configured'=>stripe_ready()];
    $staffQuery=database()->prepare('SELECT id,username,email,tier,title FROM staff_users WHERE discord_id=:id AND active=1 LIMIT 1');
    $staffQuery->execute(['id'=>$id]);
    $staff=$staffQuery->fetch();
    $robloxQuery=database()->prepare(
        'SELECT roblox_user_id,roblox_username FROM customer_roblox_links WHERE discord_id=:id LIMIT 1'
    );
    $robloxQuery->execute(['id'=>$id]);
    $roblox=$robloxQuery->fetch();
    reply(200,[
        'discord'=>['id'=>$id,'username'=>(string)$session['discord_username'],'avatar_url'=>(string)$session['avatar_url']],
        'roblox'=>$roblox ? [
            'id'=>(string)$roblox['roblox_user_id'],'username'=>(string)$roblox['roblox_username'],
        ] : null,
        'roblox_configured'=>account_roblox_oauth_ready(),
        'security'=>['email'=>account_mask_email((string)$session['email']),'email_verified'=>(bool)$session['email_verified']],
        'payment_methods'=>$methods,'has_saved_payment_methods'=>$stripeCustomer!=='','orders'=>$orderRows,'moderation'=>$actions,'appeals'=>$appeals->fetchAll(),
        'staff'=>$staff ? ['id'=>(string)$staff['id'],'username'=>(string)$staff['username'],'email'=>(string)$staff['email'],'rank'=>(string)(($staff['title'] ?: $staff['tier'])),'tier'=>(string)$staff['tier']] : null,
        'csrf'=>(string)$session['csrf_token'],
    ]);
}

function account_stripe_customer(array $session): string
{
    $customer=trim((string)($session['stripe_customer_id'] ?? ''));
    if ($customer!=='') return $customer;
    $query=database()->prepare(
        'SELECT stripe_customer_id FROM orders WHERE discord_id=:id AND stripe_customer_id IS NOT NULL AND stripe_customer_id<>\'\' ' .
        'ORDER BY created_at DESC LIMIT 1'
    );
    $query->execute(['id'=>$session['discord_id']]);
    $customer=(string)($query->fetchColumn() ?: '');
    if ($customer!=='') {
        database()->prepare('UPDATE customer_accounts SET stripe_customer_id=:customer WHERE discord_id=:id')
            ->execute(['customer'=>$customer,'id'=>$session['discord_id']]);
    }
    return $customer;
}

function account_saved_payment_methods(): never
{
    $session=account_require_session(); account_require_csrf($session);
    $customer=account_stripe_customer($session);
    if ($customer==='') reply(409,['error'=>'no_saved_methods','message'=>'Complete a Stripe Checkout purchase and choose to save your payment method first.']);
    $base=rtrim((string)(payment_config()['public_base_url'] ?? ''),'/');
    if ($base==='') reply(503,['error'=>'not_configured','message'=>'The Stripe return URL is not configured.']);
    try {
        $portal=payment_http(
            'https://api.stripe.com/v1/billing_portal/sessions',
            'POST',
            ['Authorization: Bearer ' . (string)payment_config()['stripe_secret'],'Content-Type: application/x-www-form-urlencoded','User-Agent: StitchlineSite/1.0'],
            http_build_query(['customer'=>$customer,'return_url'=>$base . '/account.html#paymentHeading'],'','&',PHP_QUERY_RFC3986)
        );
    } catch (Throwable $error) {
        error_log('Stripe Billing Portal session failed: ' . $error->getMessage());
        reply(503,['error'=>'portal_unavailable','message'=>'Saved payment methods are unavailable. Check Stripe Billing Portal settings.']);
    }
    $url=(string)($portal['url'] ?? '');
    if (!str_starts_with($url,'https://billing.stripe.com/')) reply(502,['error'=>'portal_unavailable','message'=>'Stripe did not return a secure Billing Portal URL.']);
    reply(200,['url'=>$url]);
}

function account_user_actions(string $discordId): array
{
    $query=database()->prepare("SELECT record_kind,record_id,payload_json,created_at FROM bot_records WHERE record_kind='actions' ORDER BY created_at DESC LIMIT 2000");
    $query->execute(); $results=[];
    foreach ($query->fetchAll() as $row) {
        $record=json_decode((string)$row['payload_json'],true);
        if (!is_array($record) || (string)($record['user_id'] ?? $record['discord_id'] ?? $record['target_id'] ?? '') !== $discordId) continue;
        $results[]=[
            'id'=>(string)$row['record_id'],'type'=>(string)($record['kind'] ?? $row['record_kind']),
            'command'=>(string)($record['command'] ?? $record['kind'] ?? $row['record_kind']),
            'reason'=>(string)($record['reason'] ?? ''),'moderator'=>(string)($record['moderator_name'] ?? $record['actor'] ?? ''),
            'created_at'=>(string)($record['at'] ?? $row['created_at']),'appealable'=>true,
        ];
    }
    return $results;
}

function account_appeal(array $body): never
{
    $session=account_require_session(); account_require_csrf($session);
    enforce_rate('account-appeal',5,3600);
    $actionId=clean_text($body['action_id'] ?? '',64,'Action');
    $reason=clean_text($body['reason'] ?? '',4000,'Appeal');
    if (strlen($reason)<30) reply(422,['error'=>'appeal_too_short','message'=>'Explain your appeal in at least 30 characters.']);
    $action=null;
    foreach (account_user_actions((string)$session['discord_id']) as $record) if ($record['id']===$actionId) {$action=$record;break;}
    if (!$action) reply(404,['error'=>'action_not_found','message'=>'That action is not linked to your verified Discord account.']);
    $existing=database()->prepare('SELECT reference FROM contact_messages WHERE discord_id=:id AND topic=\'appeal\' AND case_reference=:case LIMIT 1');
    $existing->execute(['id'=>$session['discord_id'],'case'=>$actionId]);
    if ($existing->fetchColumn()) reply(409,['error'=>'appeal_exists','message'=>'You have already appealed this action.']);
    $reference='SI-' . strtoupper(bin2hex(random_bytes(3)));
    $message='Appeal for moderation action ' . $actionId . ' (' . $action['type'] . "):\n\n" . $reason;
    $insert=database()->prepare(
        'INSERT INTO contact_messages (reference,received_at,topic,topic_label,case_reference,discord_id,discord_username,name,email,message,notified) ' .
        'VALUES (:ref,UTC_TIMESTAMP(),\'appeal\',\'Community / moderation appeals\',:case,:id,:username,:name,:email,:message,0)'
    );
    $insert->execute([
        'ref'=>$reference,'case'=>$actionId,'id'=>$session['discord_id'],'username'=>$session['discord_username'],
        'name'=>$session['discord_username'],'email'=>$session['email'],'message'=>$message,
    ]);
    notify_studio('New account appeal ' . $reference,$message . "\n\nDiscord: " . $session['discord_username'] . ' (' . $session['discord_id'] . ')');
    reply(201,['ok'=>true,'reference'=>$reference]);
}

function account_staff_password_reset(): never
{
    $session=account_require_session(); account_require_csrf($session);
    $query=database()->prepare('SELECT email FROM staff_users WHERE discord_id=:id AND active=1 LIMIT 1');
    $query->execute(['id'=>$session['discord_id']]);
    $email=$query->fetchColumn();
    if (!is_string($email) || $email==='') reply(404,['error'=>'no_staff_account','message'=>'No linked staff account was found.']);
    staff_password_reset_request(['email'=>$email]);
}

function account_link_staff(array $body): never
{
    $account=account_require_session(); account_require_csrf($account);
    $staff=staff_session();
    if (!$staff) reply(401,['error'=>'staff_session_required','message'=>'Sign in to the staff account first, then connect Discord.']);
    $staffId=(string)$staff['user']['id'];
    $linkedId=(string)($staff['user']['discord_id'] ?? '');
    if ($linkedId!=='' && $linkedId!==(string)$account['discord_id']) {
        reply(409,['error'=>'discord_already_linked','message'=>'This staff account is already linked to a different Discord account.']);
    }
    $other=database()->prepare('SELECT id FROM staff_users WHERE discord_id=:discord AND id<>:staff LIMIT 1');
    $other->execute(['discord'=>$account['discord_id'],'staff'=>$staffId]);
    if ($other->fetchColumn()) reply(409,['error'=>'discord_in_use','message'=>'That Discord account is linked to another staff login.']);
    $update=database()->prepare('UPDATE staff_users SET discord_id=:discord WHERE id=:staff AND active=1');
    $update->execute(['discord'=>$account['discord_id'],'staff'=>$staffId]);
    if (!$update->rowCount()) {
        $verify=database()->prepare('SELECT 1 FROM staff_users WHERE id=:staff AND discord_id=:discord AND active=1');
        $verify->execute(['staff'=>$staffId,'discord'=>$account['discord_id']]);
        if (!$verify->fetchColumn()) reply(404,['error'=>'staff_unavailable','message'=>'The staff account is not available.']);
    }
    reply(200,['ok'=>true,'staff_id'=>$staffId]);
}

function account_logout(): never
{
    $session=account_require_session(); account_require_csrf($session);
    database()->prepare('DELETE FROM customer_sessions WHERE token_hash=:token')->execute(['token'=>$session['token_hash']]);
    $secure=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))==='https';
    setcookie('si_account','',['expires'=>time()-3600,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    reply(200,['ok'=>true]);
}
