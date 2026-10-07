<?php
declare(strict_types=1);

require_once __DIR__ . '/db_compat.php';
require_once __DIR__ . '/staff_api.php';
require_once __DIR__ . '/bot_api.php';
require_once __DIR__ . '/payments_api.php';
require_once __DIR__ . '/account_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function settings(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $dirs = [dirname(__DIR__), __DIR__];
    $explicit = getenv('STITCHLINE_PRIVATE_CONFIG');
    $path = '';
    if ($explicit && is_file($explicit)) {
        $path = $explicit;
        $dirs = [dirname($explicit)];
    } else {
        foreach ($dirs as $dir) {
            if (is_file($dir . '/stitchline-config.php')) {
                $path = $dir . '/stitchline-config.php';
                break;
            }
        }
    }
    if ($path === '') {
        reply(503, ['error' => 'not_configured', 'message' => 'The site database is not configured.']);
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        reply(503, ['error' => 'not_configured', 'message' => 'The site configuration is invalid.']);
    }
    // Optional second file for login, mail and payment credentials.
    foreach ($dirs as $dir) {
        $extra = $dir . '/stitchline-logins.php';
        if (is_file($extra)) {
            $more = require $extra;
            if (is_array($more)) {
                $loaded = array_replace_recursive($loaded, $more);
            }
            break;
        }
    }
    $config = $loaded;
    return $config;
}

function database(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $database = settings()['database'] ?? [];
    if (empty($database['dsn']) || !array_key_exists('username', $database) || !array_key_exists('password', $database)) {
        reply(503, ['error' => 'not_configured', 'message' => 'The site database is not configured.']);
    }

    try {
        $connection = db_connect((string)$database['dsn'], (string)$database['username'], (string)$database['password'], $database);
    } catch (PDOException $error) {
        error_log('Stitchline database connection failed: ' . $error->getMessage());
        reply(503, ['error' => 'database_unavailable', 'message' => 'The site data is temporarily unavailable.']);
    }
    return $connection;
}

function site_document(): array
{
    $query = database()->prepare(
        'SELECT payload_json FROM site_documents WHERE document_key = :document_key LIMIT 1'
    );
    $query->execute(['document_key' => 'site']);
    $row = $query->fetch();
    if (!$row) {
        reply(503, ['error' => 'site_not_seeded', 'message' => 'Site content has not been imported yet.']);
    }

    $payload = json_decode((string)$row['payload_json'], true);
    if (!is_array($payload)) {
        reply(503, ['error' => 'site_data_invalid', 'message' => 'Site content could not be read.']);
    }
    return $payload;
}

function site_payload(): array
{
    $payload = site_document();

    $announcements = [];
    foreach (($payload['announcements'] ?? []) as $announcement) {
        if (!is_array($announcement)) {
            continue;
        }
        $audience = strtolower(trim((string)($announcement['audience'] ?? 'public')));
        if (in_array($audience, ['staff', 'dev'], true)) {
            continue;
        }
        $created = strtotime((string)($announcement['created_at'] ?? ''));
        if ($created !== false && time() - $created > 14 * 86400) {
            continue;
        }
        unset($announcement['audience']);
        $announcements[] = $announcement;
    }
    usort(
        $announcements,
        static fn(array $left, array $right): int => strcmp(
            (string)($right['created_at'] ?? ''),
            (string)($left['created_at'] ?? '')
        )
    );
    if (is_array($payload['store'] ?? null)) {
        unset($payload['store']['discounts']);
    }
    $activeUsers = array_fill_keys(array_map(
        'strtolower',
        database()->query('SELECT username_key FROM staff_users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)
    ), true);
    if (is_array($payload['team']['groups'] ?? null)) {
        foreach ($payload['team']['groups'] as &$group) {
            if (!is_array($group) || !is_array($group['members'] ?? null)) {
                continue;
            }
            foreach ($group['members'] as &$member) {
                if (!is_array($member)) {
                    continue;
                }
                $username = strtolower((string)($member['name'] ?? ''));
                $member['has_login'] = !empty($member['has_login']) && isset($activeUsers[$username]);
            }
            unset($member);
        }
        unset($group);
    }

    $games = is_array($payload['games'] ?? null) ? $payload['games'] : [];
    $groups = is_array($payload['team']['groups'] ?? null) ? $payload['team']['groups'] : [];
    $members = [];
    foreach ($groups as $group) {
        if (is_array($group) && is_array($group['members'] ?? null)) {
            $members = array_merge($members, $group['members']);
        }
    }

    $payload['stats'] = [
        'games' => count($games),
        'games_in_production' => count(array_filter(
            $games,
            static fn(array $game): bool => ($game['status'] ?? '') === 'In Production'
        )),
        'team_members' => count(array_filter(
            $members,
            static fn(array $member): bool => !empty($member['has_login'])
        )),
        'team_total' => count($members),
        'site_visits' => (int)(database()->query(
            "SELECT metric_value FROM site_metrics WHERE metric_key = 'site_visits' LIMIT 1"
        )->fetchColumn() ?: 0),
    ];

    $heartbeat = database()->query(
        "SELECT payload_json FROM bot_heartbeats " .
        "WHERE bot_id = 'community' AND received_at >= UTC_TIMESTAMP() - INTERVAL 90 SECOND LIMIT 1"
    )->fetch();
    $guilds = [];
    $seenGuilds = [];
    if ($heartbeat) {
        $status = json_decode((string)$heartbeat['payload_json'], true);
        foreach (($status['guilds'] ?? []) as $guild) {
            if (!is_array($guild)) {
                continue;
            }
            $id = (string)($guild['id'] ?? '');
            if ($id === '' || isset($seenGuilds[$id])) {
                continue;
            }
            $seenGuilds[$id] = true;
            $guilds[] = [
                'id' => $id,
                'name' => $guild['name'] ?? $id,
                'total' => (int)($guild['members'] ?? $guild['total'] ?? 0),
                'online' => (int)($guild['online'] ?? 0),
            ];
        }
    }

    $payload['community'] = [
        'source' => $heartbeat ? 'bot' : 'offline',
        'total' => array_sum(array_column($guilds, 'total')),
        'online' => array_sum(array_column($guilds, 'online')),
        'guilds' => $guilds,
        'error' => null,
    ];
    $payload['announcement_ttl_days'] = 14;
    return $payload;
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > 65536) {
        reply(413, ['error' => 'payload_too_large', 'message' => 'The request was too large.']);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        reply(400, ['error' => 'bad_request', 'message' => 'Send a valid JSON object.']);
    }
    return $body;
}

function clean_text(mixed $value, int $limit, string $field): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        reply(422, ['error' => 'bad_field', 'message' => $field . ' is invalid.']);
    }
    $text = trim((string)$value);
    if (strlen($text) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
        reply(422, ['error' => 'bad_field', 'message' => $field . ' is invalid.']);
    }
    return $text;
}

function require_email(mixed $value): string
{
    $email = clean_text($value, 254, 'Email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_contains($email, '..')) {
        reply(422, ['error' => 'bad_email', 'message' => 'Enter a valid email address.']);
    }
    return $email;
}

function enforce_rate(string $bucket, int $limit, int $window): void
{
    $secret = (string)(settings()['app_secret'] ?? '');
    if (strlen($secret) < 32) {
        reply(503, ['error' => 'not_configured', 'message' => 'Form protection is not configured.']);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $identity = hash_hmac('sha256', $ip, $secret);
    $start = gmdate('Y-m-d H:i:s', intdiv(time(), $window) * $window);
    $write = database()->prepare(
        'INSERT INTO api_rate_limits (bucket, identity_hash, window_started, hits) ' .
        'VALUES (:bucket, :identity_hash, :window_started, 1) ' .
        'ON DUPLICATE KEY UPDATE hits = IF(window_started < VALUES(window_started), 1, hits + 1), ' .
        'window_started = GREATEST(window_started, VALUES(window_started))'
    );
    $write->execute([
        'bucket' => $bucket,
        'identity_hash' => $identity,
        'window_started' => $start,
    ]);
    $check = database()->prepare(
        'SELECT hits FROM api_rate_limits WHERE bucket = :bucket AND identity_hash = :identity_hash'
    );
    $check->execute(['bucket' => $bucket, 'identity_hash' => $identity]);
    if ((int)$check->fetchColumn() > $limit) {
        reply(429, ['error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.']);
    }
}

function issue_human_check(): never
{
    enforce_rate('human', 30, 300);
    $values = ['Progression', 'Fun Games', 'Player Happiness', 'Attempted Lag Free Games'];
    $decoys = [
        'Frame rate measured in polls per hour',
        'Ping budgeted in frames of lag',
        'Netcode tuned by vibes',
        'Servers hosted in the parking lot',
        'Hitboxes drawn with a paintbrush',
    ];
    $answer = $values[random_int(0, count($values) - 1)];
    $choices = [$answer];
    while (count($choices) < 4) {
        $choice = $decoys[random_int(0, count($decoys) - 1)];
        if (!in_array($choice, $choices, true)) {
            $choices[] = $choice;
        }
    }
    for ($index = count($choices) - 1; $index > 0; $index--) {
        $swap = random_int(0, $index);
        [$choices[$index], $choices[$swap]] = [$choices[$swap], $choices[$index]];
    }

    $token = bin2hex(random_bytes(16));
    $write = database()->prepare(
        'INSERT INTO human_checks (token_hash, answer_hash, expires_at) ' .
        'VALUES (:token_hash, :answer_hash, UTC_TIMESTAMP() + INTERVAL 15 MINUTE)'
    );
    $write->execute([
        'token_hash' => hash('sha256', $token),
        'answer_hash' => hash('sha256', strtolower($answer)),
    ]);
    reply(200, [
        'token' => $token,
        'ttl' => 900,
        'kind' => 'choice',
        'prompt' => 'Which of these is a Stitchline studio value?',
        'choices' => $choices,
    ]);
}

function verify_human_check(mixed $token, mixed $answer): void
{
    $token = clean_text($token, 64, 'Human check');
    $answer = strtolower(clean_text($answer, 80, 'Human check answer'));
    if ($token === '' || $answer === '') {
        reply(422, ['error' => 'human_required', 'message' => 'Complete the human check first.']);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare(
            'SELECT answer_hash, expires_at FROM human_checks WHERE token_hash = :token_hash FOR UPDATE'
        );
        $query->execute(['token_hash' => hash('sha256', $token)]);
        $record = $query->fetch();
        $remove = $pdo->prepare('DELETE FROM human_checks WHERE token_hash = :token_hash');
        $remove->execute(['token_hash' => hash('sha256', $token)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    if (!$record || strtotime((string)$record['expires_at']) < time() ||
        !hash_equals((string)$record['answer_hash'], hash('sha256', $answer))) {
        reply(422, ['error' => 'human_failed', 'message' => 'That check was wrong or expired. Please try again.']);
    }
}

function send_site_mail(string $recipient, string $subject, string $body, string $replyTo = ''): bool
{
    $recipient = trim($recipient);
    $sender = trim((string)((settings()['mail'] ?? [])['from'] ?? ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || $sender === '') {
        return false;
    }
    $headers = [
        'From' => $sender,
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'Stitchline Website',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers['Reply-To'] = $replyTo;
    }
    return @mail($recipient, $subject, $body, $headers);
}

function notify_studio(string $subject, string $body, string $replyTo = ''): bool
{
    $site = site_document();
    $contact = is_array($site['contact'] ?? null) ? $site['contact'] : [];
    $recipient = trim((string)($contact['inbox'] ?? $contact['email'] ?? ''));
    if ($recipient === '') return false;
    return send_site_mail($recipient, $subject, $body, $replyTo);
}

function public_applications(): never
{
    $site = site_document();
    $roles = is_array($site['applications'] ?? null) ? $site['applications'] : [];
    $counts = [];
    foreach (database()->query('SELECT role_id, COUNT(*) AS total FROM applications GROUP BY role_id') as $row) {
        $counts[(string)$row['role_id']] = (int)$row['total'];
    }
    $now = time();
    $result = [];
    foreach ($roles as $role) {
        if (!is_array($role)) {
            continue;
        }
        $closesAt = trim((string)($role['closes_at'] ?? ''));
        $closeTime = $closesAt !== '' ? strtotime($closesAt) : false;
        $expired = $closeTime !== false && $closeTime <= $now;
        $isOpen = !empty($role['open']) && !$expired;
        $questions = [];
        foreach (($role['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }
            $questions[] = [
                'id' => (string)($question['id'] ?? ''),
                'label' => (string)($question['label'] ?? ''),
                'type' => in_array(($question['type'] ?? ''), ['text', 'textarea', 'email', 'url', 'number', 'select'], true)
                    ? $question['type'] : 'text',
                'required' => !empty($question['required']),
                'hint' => $question['hint'] ?? null,
                'options' => ($question['type'] ?? '') === 'select' && is_array($question['options'] ?? null)
                    ? array_values($question['options']) : [],
            ];
        }
        $result[] = [
            'id' => (string)($role['id'] ?? ''),
            'role' => (string)($role['role'] ?? ''),
            'summary' => (string)($role['summary'] ?? ''),
            'responsibilities' => is_array($role['responsibilities'] ?? null) ? $role['responsibilities'] : [],
            'requirements' => is_array($role['requirements'] ?? null) ? $role['requirements'] : [],
            'open' => $isOpen,
            'closed_reason' => $isOpen ? null : ($expired ? 'Applications have closed.' : 'This role is not accepting applications.'),
            'closes_at' => $closesAt !== '' ? $closesAt : null,
            'applicants' => $counts[(string)($role['id'] ?? '')] ?? 0,
            'questions' => $questions,
        ];
    }
    reply(200, ['roles' => $result]);
}

function submit_contact(array $body): never
{
    if (clean_text($body['website'] ?? '', 200, 'Website') !== '') {
        reply(422, ['error' => 'spam', 'message' => 'That submission was rejected.']);
    }
    $contact = site_document()['contact'] ?? [];
    $topics = [];
    foreach (($contact['topics'] ?? []) as $topic) {
        if (is_array($topic) && isset($topic['id'], $topic['label'])) {
            $topics[(string)$topic['id']] = (string)$topic['label'];
        }
    }
    $topic = clean_text($body['topic'] ?? '', 32, 'Topic');
    $discordId = clean_text($body['discord_id'] ?? '', 20, 'Discord user ID');
    $discordUsername = clean_text($body['discord_username'] ?? '', 32, 'Discord username');
    $name = clean_text($body['name'] ?? '', 80, 'Name');
    $email = require_email($body['email'] ?? '');
    $message = clean_text($body['message'] ?? '', 8000, 'Message');
    if (!isset($topics[$topic])) {
        reply(422, ['error' => 'bad_topic', 'message' => 'Pick what your message is about.']);
    }
    if (!preg_match('/^[0-9]{17,20}$/', $discordId)) {
        reply(422, ['error' => 'bad_discord_id', 'message' => 'Enter your numeric Discord user ID.']);
    }
    if (!preg_match('/^[A-Za-z0-9._-]{2,32}$/', $discordUsername)) {
        reply(422, ['error' => 'bad_discord_username', 'message' => 'Enter your Discord username.']);
    }
    if (strlen($name) < 2) {
        reply(422, ['error' => 'missing_name', 'message' => 'Tell us your name.']);
    }
    if (strlen($message) < 20) {
        reply(422, ['error' => 'short_message', 'message' => 'Give us at least 20 characters.']);
    }
    verify_human_check($body['human'] ?? '', $body['answer'] ?? '');
    enforce_rate('contact', 8, 900);

    $reference = 'SI-' . strtoupper(bin2hex(random_bytes(3)));
    $insert = database()->prepare(
        'INSERT INTO contact_messages (reference, received_at, topic, topic_label, discord_id, discord_username, name, email, message, notified) ' .
        'VALUES (:reference, UTC_TIMESTAMP(), :topic, :topic_label, :discord_id, :discord_username, :name, :email, :message, 0)'
    );
    $insert->execute([
        'reference' => $reference,
        'topic' => $topic,
        'topic_label' => $topics[$topic],
        'discord_id' => $discordId,
        'discord_username' => $discordUsername,
        'name' => $name,
        'email' => $email,
        'message' => $message,
    ]);
    $notified = notify_studio(
        '[' . $topics[$topic] . '] Website suggestion ' . $reference,
        $message . "\n\nFrom: {$name} <{$email}>\nDiscord: {$discordUsername} ({$discordId})\nReference: {$reference}",
        $email
    );
    $deliveryUpdate = database()->prepare('UPDATE contact_messages SET notified = :notified WHERE reference = :reference');
    $deliveryUpdate->execute(['notified'=>$notified ? 1 : 0,'reference'=>$reference]);
    $receiptSent = send_site_mail(
        $email,
        'We received your Stitchline suggestion (' . $reference . ')',
        "Hello {$name},\n\nYour message has been received by Stitchline Interactive. Its reference is {$reference}. We will email you after the studio reviews it.\n\nStitchline Interactive",
        (string)($contact['email'] ?? '')
    );
    $receiptUpdate = database()->prepare('UPDATE contact_messages SET customer_emailed = :sent WHERE reference = :reference');
    $receiptUpdate->execute(['sent'=>$receiptSent ? 1 : 0,'reference'=>$reference]);
    reply(200, [
        'ok' => true,
        'ref' => $reference,
        'topic' => $topics[$topic],
        'delivery' => $receiptSent ? 'mail' : 'stored',
        'message' => $receiptSent
            ? 'Your suggestion is saved and a receipt was emailed to you. Keep this reference for follow-up.'
            : 'Your suggestion is saved for studio review. Keep this reference for follow-up; email confirmation was unavailable.',
    ]);
}

function submit_application(array $body): never
{
    if (clean_text($body['website'] ?? '', 200, 'Website') !== '') {
        reply(422, ['error' => 'spam', 'message' => 'That submission was rejected.']);
    }
    $site = site_document();
    $roles = is_array($site['applications'] ?? null) ? $site['applications'] : [];
    $roleId = clean_text($body['role'] ?? '', 60, 'Role');
    $role = null;
    foreach ($roles as $candidate) {
        if (is_array($candidate) && (string)($candidate['id'] ?? '') === $roleId) {
            $role = $candidate;
            break;
        }
    }
    if ($role === null) {
        reply(404, ['error' => 'unknown_role', 'message' => 'That role is not on the list.']);
    }
    $closeTime = !empty($role['closes_at']) ? strtotime((string)$role['closes_at']) : false;
    if (empty($role['open']) || ($closeTime !== false && $closeTime <= time())) {
        reply(409, ['error' => 'role_closed', 'message' => 'Applications for that role have closed.']);
    }
    $name = clean_text($body['name'] ?? '', 80, 'Name');
    $email = require_email($body['email'] ?? '');
    if (strlen($name) < 2) {
        reply(422, ['error' => 'missing_name', 'message' => 'Tell us your name.']);
    }
    $answers = $body['answers'] ?? null;
    if (!is_array($answers)) {
        reply(422, ['error' => 'bad_answers', 'message' => 'The application form was invalid.']);
    }
    $cleanAnswers = [];
    foreach (($role['questions'] ?? []) as $question) {
        if (!is_array($question)) {
            continue;
        }
        $id = (string)($question['id'] ?? '');
        $answer = clean_text($answers[$id] ?? '', 3000, 'Answer');
        if (!empty($question['required']) && $answer === '') {
            reply(422, ['error' => 'missing_answer', 'field' => $id, 'message' => 'Answer the required question.']);
        }
        if ($answer !== '' && ($question['type'] ?? '') === 'select' &&
            !in_array($answer, $question['options'] ?? [], true)) {
            reply(422, ['error' => 'bad_answer', 'field' => $id, 'message' => 'Choose one of the listed options.']);
        }
        if ($answer !== '') {
            $cleanAnswers[] = [
                'id' => $id,
                'label' => (string)($question['label'] ?? ''),
                'answer' => $answer,
            ];
        }
    }
    verify_human_check($body['human'] ?? '', $body['answer_human'] ?? '');
    enforce_rate('application', 8, 900);

    $reference = 'SI-' . strtoupper(bin2hex(random_bytes(3)));
    $insert = database()->prepare(
        'INSERT INTO applications (reference, received_at, role_id, role_title, name, email, answers_json, notified) ' .
        'VALUES (:reference, UTC_TIMESTAMP(), :role_id, :role_title, :name, :email, :answers_json, 0)'
    );
    $insert->execute([
        'reference' => $reference,
        'role_id' => $roleId,
        'role_title' => (string)($role['role'] ?? ''),
        'name' => $name,
        'email' => $email,
        'answers_json' => json_encode($cleanAnswers, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    $answerText = implode("\n\n", array_map(
        static fn(array $answer): string => $answer['label'] . ': ' . $answer['answer'],
        $cleanAnswers
    ));
    $notified = notify_studio(
        'Website application: ' . $role['role'] . ' (' . $reference . ')',
        "Applicant: {$name} <{$email}>\nRole: {$role['role']}\nReference: {$reference}\n\n{$answerText}"
    );
    $deliveryUpdate = database()->prepare('UPDATE applications SET notified = :notified WHERE reference = :reference');
    $deliveryUpdate->execute(['notified'=>$notified ? 1 : 0,'reference'=>$reference]);
    $receiptSent = send_site_mail(
        $email,
        'Application received: ' . (string)$role['role'],
        "Hello {$name},\n\nYour application for {$role['role']} has been received by Stitchline Interactive. Its reference is {$reference}. We will contact you at this address after review.\n\nStitchline Interactive"
    );
    $receiptUpdate = database()->prepare('UPDATE applications SET applicant_notified = :sent WHERE reference = :reference');
    $receiptUpdate->execute(['sent'=>$receiptSent ? 1 : 0,'reference'=>$reference]);
    reply(200, [
        'ok' => true,
        'ref' => $reference,
        'role' => (string)($role['role'] ?? ''),
        'delivery' => $receiptSent ? 'mail' : 'stored',
        'message' => $receiptSent
            ? 'Your application is saved and a receipt was emailed to you. Keep this reference for follow-up.'
            : 'Your application is saved for studio review. Keep this reference for follow-up; email confirmation was unavailable.',
    ]);
}

function store_price_cart(mixed $rawCart): array
{
    if (!is_array($rawCart) || !$rawCart || count($rawCart) > 12) {
        reply(422, ['error'=>'empty_cart','message'=>'Add items to your cart first.']);
    }
    $store = site_document()['store'] ?? [];
    $currency = $store['currency'] ?? [];
    $custom = $currency['custom'] ?? [];
    $bundles = [];
    foreach (($currency['bundles'] ?? []) as $bundle) {
        if (is_array($bundle)) $bundles[(string)($bundle['id'] ?? '')] = $bundle;
    }
    $roles = [];
    foreach (($store['roles'] ?? []) as $role) {
        if (is_array($role)) $roles[(string)($role['id'] ?? '')] = $role;
    }
    $shop = [];
    foreach (($store['shop'] ?? []) as $item) {
        if (is_array($item)) $shop[(string)($item['id'] ?? '')] = $item;
    }
    $reserved = array_map(static fn(array $bundle): int => (int)($bundle['amount'] ?? 0), array_values($bundles));
    $seen = [];
    $lines = [];
    $subtotalCents = 0;
    foreach ($rawCart as $entry) {
        if (!is_array($entry)) reply(422, ['error'=>'bad_cart','message'=>'The cart was invalid.']);
        $id = clean_text($entry['id'] ?? '', 40, 'Item');
        $qty = filter_var($entry['qty'] ?? 1, FILTER_VALIDATE_INT);
        if ($qty === false || $qty < 1 || $qty > 99) reply(422, ['error'=>'bad_qty','message'=>'Choose a quantity from 1 to 99.']);
        $amount = (int)($entry['amount'] ?? 0);
        $key = $id . ':' . $amount;
        if (isset($seen[$key])) reply(422, ['error'=>'dup_line','message'=>'The same cart item was included twice.']);
        $seen[$key] = true;
        $lineCents = null;

        if (isset($bundles[$id])) {
            $item = $bundles[$id];
            $unitCents = (int)round((float)($item['price'] ?? 0) * 100);
            $label = number_format((int)($item['amount'] ?? 0)) . ' ' . (string)($currency['name'] ?? 'StitchBlocks');
            $delivery = ['kind'=>'currency','amount'=>(int)($item['amount'] ?? 0) * $qty];
        } elseif ($id === (string)($custom['id'] ?? 'sb-custom')) {
            if (empty($custom['enabled'])) reply(422, ['error'=>'not_for_sale','message'=>'Custom amounts are turned off.']);
            $min = max(1, (int)($custom['min_amount'] ?? 1));
            $totalAmount = $amount * $qty;
            if ($amount < $min || $totalAmount > 1000000 || in_array($totalAmount, $reserved, true)) {
                reply(422, ['error'=>'bad_amount','message'=>'That custom amount is invalid or duplicates a listed bundle.']);
            }
            $unitCents = (int)round((float)($custom['price_per_unit'] ?? 0) * $amount * 100);
            $lineCents = (int)round((float)($custom['price_per_unit'] ?? 0) * $totalAmount * 100);
            $label = number_format($totalAmount) . ' ' . (string)($currency['name'] ?? 'StitchBlocks') . ' (custom)';
            $delivery = ['kind'=>'currency','amount'=>$totalAmount];
        } elseif (isset($roles[$id])) {
            $item = $roles[$id];
            if (!empty($item['custom'])) {
                if ($amount < 1 || $amount > 10000) reply(422, ['error'=>'bad_amount','message'=>'Enter a supporter amount between $1 and $10,000.']);
                $unitCents = $amount * 100;
                $label = (string)($item['name'] ?? 'Supporter') . ' ($' . number_format($amount * $qty) . ')';
                $delivery = ['kind'=>'role','role'=>(string)($item['name'] ?? ''),'custom_amount'=>$amount * $qty];
            } else {
                if (!is_numeric($item['price'] ?? null)) reply(422, ['error'=>'no_price','message'=>'This item is not priced.']);
                $unitCents = (int)round((float)$item['price'] * 100);
                $label = (string)($item['name'] ?? 'Role');
                $delivery = ['kind'=>'role','role'=>$label];
            }
        } elseif (isset($shop[$id])) {
            $item = $shop[$id];
            $unitCents = (int)round((float)($item['price'] ?? 0) * 100);
            $label = (string)($item['name'] ?? 'Item');
            $delivery = ['kind'=>'shop','item'=>$label];
        } else {
            reply(422, ['error'=>'unknown_item','message'=>'A cart item is no longer available.']);
        }
        $lineCents ??= $unitCents * $qty;
        $subtotalCents += $lineCents;
        $lines[] = [
            'id'=>$id,'qty'=>$qty,'label'=>$label,'unit'=>round($unitCents/100,2),
            'line_total'=>round($lineCents/100,2),'deliver'=>$delivery,
        ];
    }
    return ['lines'=>$lines,'subtotal'=>round($subtotalCents/100,2),'total'=>round($subtotalCents/100,2)];
}

function store_apply_discount(array $priced, mixed $rawCode): array
{
    $code = strtoupper(clean_text($rawCode ?? '', 40, 'Discount code'));
    if ($code === '') return $priced;
    $store = site_document()['store'] ?? [];
    $now = time();
    foreach (($store['discounts'] ?? []) as $discount) {
        if (!is_array($discount) || strtoupper((string)($discount['code'] ?? '')) !== $code) continue;
        if (empty($discount['active'])) reply(422, ['error'=>'discount_unavailable','message'=>'That discount code is not available.']);
        $expiry = trim((string)($discount['expires'] ?? ''));
        if ($expiry !== '' && ($stamp = strtotime($expiry)) !== false && $stamp <= $now) {
            reply(422, ['error'=>'discount_expired','message'=>'That discount code has expired.']);
        }
        $maxUses = (int)($discount['max_uses'] ?? 0);
        if ($maxUses > 0 && (int)($discount['used'] ?? 0) >= $maxUses) {
            reply(422, ['error'=>'discount_exhausted','message'=>'That discount code has reached its limit.']);
        }
        $subtotalCents = (int)round((float)$priced['total'] * 100);
        $minimumCents = (int)round((float)($discount['min_subtotal'] ?? 0) * 100);
        if ($subtotalCents < $minimumCents) reply(422, ['error'=>'discount_minimum','message'=>'Your cart does not meet that code’s minimum.']);
        $value = max(0.0, (float)($discount['value'] ?? 0));
        if (($discount['kind'] ?? 'percent') === 'fixed') {
            $offCents = min($subtotalCents, (int)round($value * 100));
            $label = '$' . number_format($offCents / 100, 2) . ' off';
        } else {
            $percent = min(90.0, $value);
            $offCents = min($subtotalCents, (int)round($subtotalCents * $percent / 100));
            $label = rtrim(rtrim(number_format($percent, 2), '0'), '.') . '% off';
        }
        $priced['subtotal'] = round($subtotalCents / 100, 2);
        $priced['total'] = round(($subtotalCents - $offCents) / 100, 2);
        $priced['discount'] = [
            'label'=>$label,'amount_off'=>round($offCents/100,2),
            'subtotal'=>$priced['subtotal'],'total'=>$priced['total'],
            'note'=>(string)($discount['note'] ?? ''),
            '_id'=>(string)($discount['id'] ?? ''),
        ];
        return $priced;
    }
    reply(422, ['error'=>'invalid_discount','message'=>'That discount code is not valid.']);
}

function store_consume_discount(PDO $pdo, ?array $discount): void
{
    $id = (string)($discount['_id'] ?? '');
    if ($id === '') return;
    $query = $pdo->query("SELECT payload_json FROM site_documents WHERE document_key = 'site' FOR UPDATE");
    $raw = $query->fetchColumn();
    $site = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($site) || !is_array($site['store']['discounts'] ?? null)) {
        $pdo->rollBack();
        reply(503, ['error'=>'discount_unavailable','message'=>'The discount could not be reserved. Please retry.']);
    }
    $found = false;
    foreach ($site['store']['discounts'] as &$entry) {
        if (!is_array($entry) || (string)($entry['id'] ?? '') !== $id) continue;
        $found = true;
        $maxUses = (int)($entry['max_uses'] ?? 0);
        $expiry = trim((string)($entry['expires'] ?? ''));
        $expiresAt = $expiry !== '' ? strtotime($expiry) : false;
        if (empty($entry['active']) || ($expiresAt !== false && $expiresAt <= time()) ||
            ($maxUses > 0 && (int)($entry['used'] ?? 0) >= $maxUses)) {
            unset($entry);
            $pdo->rollBack();
            reply(422, ['error'=>'discount_unavailable','message'=>'That discount is no longer available.']);
        }
        $entry['used'] = (int)($entry['used'] ?? 0) + 1;
        break;
    }
    unset($entry);
    if (!$found) {
        $pdo->rollBack();
        reply(422, ['error'=>'discount_unavailable','message'=>'That discount is no longer available.']);
    }
    $update = $pdo->prepare("UPDATE site_documents SET payload_json = :payload WHERE document_key = 'site'");
    $update->execute(['payload'=>json_encode($site, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
}

function store_release_discount(?array $discount): void
{
    $id = (string)($discount['_id'] ?? '');
    if ($id === '') return;
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $raw = $pdo->query("SELECT payload_json FROM site_documents WHERE document_key = 'site' FOR UPDATE")->fetchColumn();
        $site = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($site) && is_array($site['store']['discounts'] ?? null)) {
            foreach ($site['store']['discounts'] as &$entry) {
                if (is_array($entry) && (string)($entry['id'] ?? '') === $id) {
                    $entry['used'] = max(0, (int)($entry['used'] ?? 0) - 1);
                    break;
                }
            }
            unset($entry);
            $update = $pdo->prepare("UPDATE site_documents SET payload_json = :payload WHERE document_key = 'site'");
            $update->execute(['payload'=>json_encode($site, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Could not release discount after checkout failure: ' . $error->getMessage());
    }
}

function store_tax_estimate(mixed $postalRaw, float $subtotal): array
{
    $postal = preg_replace('/[^0-9]/', '', (string)$postalRaw) ?? '';
    if (strlen($postal) === 9) $postal = substr($postal, 0, 5);
    $tax = ['tax'=>0.0,'rate'=>0.0,'exact'=>false,'source'=>'manual-review','jurisdiction'=>'',
        'postal'=>$postal,'detail'=>'Tax is not configured for automatic checkout; staff will confirm the final amount before payment.'];
    return ['tax'=>$tax,'taxable'=>round($subtotal,2),'total'=>round($subtotal,2)];
}

function public_store_quote(array $body, string $action): never
{
    enforce_rate($action === 'tax' ? 'tax' : 'quote', $action === 'tax' ? 40 : 60, $action === 'tax' ? 120 : 300);
    if ($action === 'discount') {
        $subtotal = $body['subtotal'] ?? null;
        if (!is_numeric($subtotal) || (float)$subtotal < 0) reply(422, ['error'=>'bad_subtotal','message'=>'Enter a valid cart subtotal.']);
        $priced = store_apply_discount(['lines'=>[],'subtotal'=>(float)$subtotal,'total'=>(float)$subtotal], $body['code'] ?? '');
        $discount = $priced['discount'];
        unset($discount['_id']);
        reply(200, ['ok'=>true,'discount'=>$discount]);
    }
    $priced = store_price_cart($body['cart'] ?? null);
    $priced = store_apply_discount($priced, $body['code'] ?? '');
    if ($action === 'tax') {
        if (!empty(payment_config()['stripe_tax_enabled']) && !empty(payment_config()['stripe_secret'])) {
            try {
                $exact = stripe_tax_exact((int)round((float)$priced['total'] * 100), (string)($body['postal_code'] ?? ''));
                $tax = [
                    'tax' => [
                        'tax' => (float)$exact['tax'], 'rate' => (float)$exact['rate'], 'exact' => true,
                        'source' => 'stripe_tax', 'jurisdiction' => (string)$exact['jurisdiction'],
                        'postal' => (string)$exact['postal'], 'detail' => (string)$exact['detail'],
                    ],
                    'taxable' => round((float)$priced['total'], 2),
                    'total' => round((float)$priced['total'] + (float)$exact['tax'], 2),
                ];
            } catch (Throwable $error) {
                $tax = store_tax_estimate($body['postal_code'] ?? '', (float)$priced['total']);
                $tax['tax']['detail'] = 'Exact Stripe Tax is not available right now. ' . $tax['tax']['detail'];
            }
        } else {
            $tax = store_tax_estimate($body['postal_code'] ?? '', (float)$priced['total']);
        }
        $tax['discount'] = $priced['discount'] ?? null;
        $tax['subtotal'] = $priced['subtotal'];
        reply(200, $tax);
    }
    if (isset($priced['discount'])) unset($priced['discount']['_id']);
    reply(200, $priced);
}

function public_store_order(array $body): never
{
    if (clean_text($body['website'] ?? '', 200, 'Website') !== '') reply(422, ['error'=>'spam','message'=>'That order was rejected.']);
    $provider = clean_text($body['provider'] ?? '', 24, 'Payment method');
    if (in_array($provider, ['card', 'cashapp'], true)) $provider = 'stripe';
    if ($provider !== 'stripe') {
        reply(422, ['error'=>'bad_provider','message'=>'Choose Stripe Checkout.']);
    }
    if (!stripe_ready()) {
        reply(422, ['error'=>'provider_unavailable','message'=>'Stripe Checkout is not fully configured on this site.']);
    }
    $discordId = clean_text($body['discord_id'] ?? '', 20, 'Discord ID');
    $discordName = clean_text($body['discord_name'] ?? '', 32, 'Discord username');
    if (!preg_match('/^[0-9]{17,20}$/', $discordId) || !preg_match('/^[A-Za-z0-9._-]{2,32}$/', $discordName)) {
        reply(422, ['error'=>'bad_discord_id','message'=>'Enter a valid Discord ID and username.']);
    }
    $email = require_email($body['email'] ?? '');
    $priced = store_apply_discount(store_price_cart($body['cart'] ?? null), $body['code'] ?? '');
    try {
        $exact = stripe_tax_exact((int)round((float)$priced['total'] * 100), (string)($body['postal_code'] ?? ''));
        $tax = [
            'tax' => [
                'tax' => (float)$exact['tax'], 'rate' => (float)$exact['rate'],
                'exact' => true, 'source' => 'stripe_tax',
                'jurisdiction' => (string)$exact['jurisdiction'],
                'postal' => (string)$exact['postal'], 'detail' => (string)$exact['detail'],
            ],
            'taxable' => round((float)$priced['total'], 2),
            'total' => round((float)$priced['total'] + (float)$exact['tax'], 2),
        ];
    } catch (Throwable $error) {
        reply(422, ['error'=>'tax_unavailable','message'=>'Exact tax could not be calculated. Check the ZIP code or contact support.']);
    }
    verify_human_check($body['human'] ?? '', $body['answer_human'] ?? '');
    enforce_rate('order', 8, 900);

    $reference = 'SI-' . strtoupper(bin2hex(random_bytes(3)));
    $discount = $priced['discount'] ?? null;
    $taxJson = json_encode($tax['tax'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $linesJson = json_encode($priced['lines'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $initialStatus = 'awaiting_provider';
        $insert = $pdo->prepare(
            'INSERT INTO orders (reference, created_at, status, provider, discord_id, discord_name, email, verification_status, subtotal, taxable, discount_json, tax_json, total, lines_json) ' .
            'VALUES (:reference, UTC_TIMESTAMP(), :status, :provider, :discord_id, :discord_name, :email, \'pending_staff\', :subtotal, :taxable, :discount, :tax, :total, :lines)'
        );
        $insert->execute([
            'reference'=>$reference,'status'=>$initialStatus,'provider'=>$provider,'discord_id'=>$discordId,'discord_name'=>$discordName,'email'=>$email,
            'subtotal'=>$priced['subtotal'],'taxable'=>$tax['taxable'],'discount'=>$discount ? json_encode($discount, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            'tax'=>$taxJson,'total'=>$tax['total'],'lines'=>$linesJson,
        ]);
        store_consume_discount($pdo, $discount);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    {
        try {
            $checkout = stripe_create_checkout([
                'reference'=>$reference,'total'=>$tax['total'],'subtotal'=>$priced['subtotal'],
                'taxable'=>$tax['taxable'],'lines'=>$priced['lines'],'discord_id'=>$discordId,
                'email'=>$email,
            ], $provider);
        } catch (Throwable $error) {
            $update = database()->prepare("UPDATE orders SET status = 'awaiting_provider', payment_error = :error WHERE reference = :reference");
            $update->execute(['error'=>clean_text($error->getMessage(),500,'Payment error'),'reference'=>$reference]);
            store_release_discount($discount);
            reply(200, [
                'ok'=>true,'ref'=>$reference,'total'=>$tax['total'],'provider'=>$provider,
                'status'=>'awaiting_provider','tax'=>['amount'=>$tax['tax']['tax'],'rate'=>$tax['tax']['rate'],'exact'=>true,
                    'jurisdiction'=>$tax['tax']['jurisdiction'],'detail'=>$tax['tax']['detail']],
                'discount'=>$discount ? array_diff_key($discount, ['_id'=>true]) : null,
                'message'=>'The order was saved but checkout could not be started. Nothing was charged. Contact staff with this reference.',
                'detail'=>clean_text($error->getMessage(),500,'Payment error'),
            ]);
        }
        $update = database()->prepare("UPDATE orders SET status = 'awaiting_payment', stripe_session_id = :provider_id WHERE reference = :reference");
        $update->execute(['provider_id'=>$checkout['id'],'reference'=>$reference]);
        reply(200, [
            'ok'=>true,'ref'=>$reference,'total'=>$tax['total'],'provider'=>$provider,
            'status'=>'awaiting_payment','checkout_url'=>$checkout['url'],
            'tax'=>['amount'=>$tax['tax']['tax'],'rate'=>$tax['tax']['rate'],'exact'=>true,
                'jurisdiction'=>$tax['tax']['jurisdiction'],'detail'=>$tax['tax']['detail']],
            'discount'=>$discount ? array_diff_key($discount, ['_id'=>true]) : null,
            'message'=>'Continue to the secure payment provider to complete this order.',
        ]);
    }
}

function public_store_lookup(array $body): never
{
    enforce_rate('lookup', 30, 300);
    $reference = strtoupper(clean_text($body['ref'] ?? '', 12, 'Order reference'));
    $discordId = clean_text($body['discord_id'] ?? '', 20, 'Discord ID');
    $query = database()->prepare(
        'SELECT reference,status,provider,total,tax_json,discount_json,lines_json,created_at ' .
        'FROM orders WHERE reference = :reference AND discord_id = :discord_id LIMIT 1'
    );
    $query->execute(['reference'=>$reference,'discord_id'=>$discordId]);
    $order = $query->fetch();
    if (!$order) reply(404, ['error'=>'not_found','message'=>'No order matches that reference and Discord ID.']);
    reply(200, [
        'ok'=>true,'ref'=>$order['reference'],'status'=>$order['status'],'provider'=>$order['provider'],
        'total'=>(float)$order['total'],'lines'=>json_decode((string)$order['lines_json'],true) ?: [],
    ]);
}

function public_catalog(): never
{
    $store = site_document()['store'] ?? [];
    $currency = is_array($store['currency'] ?? null) ? $store['currency'] : [];
    $custom = is_array($currency['custom'] ?? null) ? $currency['custom'] : [];
    $payments = is_array($store['payments'] ?? null) ? $store['payments'] : [];
    $roles = is_array($store['roles'] ?? null) ? $store['roles'] : [];
    $shop = is_array($store['shop'] ?? null) ? $store['shop'] : [];
    $stripeCardReady = stripe_ready();
    $discounts = is_array($store['discounts'] ?? null) ? $store['discounts'] : [];
    $activeDiscounts = count(array_filter(
        $discounts,
        static fn(array $discount): bool => !array_key_exists('active', $discount) || !empty($discount['active'])
    )) > 0;

    $catalogRoles = [];
    foreach ($roles as $role) {
        if (is_array($role)) {
            $catalogRoles[] = [
                'id' => (string)($role['id'] ?? ''),
                'name' => (string)($role['name'] ?? ''),
                'price' => isset($role['price']) ? (float)$role['price'] : null,
                'custom' => !empty($role['custom']),
                'blurb' => (string)($role['blurb'] ?? ''),
            ];
        }
    }
    $catalogShop = [];
    foreach ($shop as $item) {
        if (is_array($item)) {
            $catalogShop[] = [
                'id' => (string)($item['id'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'blurb' => (string)($item['blurb'] ?? ''),
                'price' => round((float)($item['price'] ?? 0), 2),
                'kind' => (string)($item['kind'] ?? 'custom'),
            ];
        }
    }

    reply(200, [
        'currency' => [
            'name' => (string)($currency['name'] ?? 'StitchBlocks'),
            'icon' => (string)($currency['icon'] ?? '/assets/Cube.png'),
            'bundles' => array_values(array_map(
                static fn(array $bundle): array => [
                    'id' => (string)($bundle['id'] ?? ''),
                    'amount' => (int)($bundle['amount'] ?? 0),
                    'price' => round((float)($bundle['price'] ?? 0), 2),
                ],
                array_values(array_filter($currency['bundles'] ?? [], 'is_array'))
            )),
            'custom' => [
                'id' => (string)($custom['id'] ?? 'sb-custom'),
                'enabled' => !empty($custom['enabled']),
                'price_per_unit' => round((float)($custom['price_per_unit'] ?? 0), 4),
                'min' => max(1, (int)($custom['min_amount'] ?? 1)),
                'max' => 1000000,
                'note' => (string)($custom['note'] ?? ''),
            ],
        ],
        'roles' => $catalogRoles,
        'shop' => $catalogShop,
        'payments' => [
            'providers' => ['stripe'],
            'manual_review' => [],
            'note' => (string)($payments['note'] ?? ''),
            'liability_note' => (string)($payments['liability_note'] ?? ''),
            'require_discord_id' => !empty($payments['require_discord_id']),
        ],
        'has_discounts' => $activeDiscounts,
        'provider_availability' => [
            'stripe' => [
                'available' => $stripeCardReady,
                'instant' => $stripeCardReady,
                'reason' => $stripeCardReady
                    ? 'Stripe Checkout will show all eligible methods enabled in the Stripe Dashboard.'
                    : 'Configure Stripe, Stripe Tax, the webhook secret, and the public URL.',
            ],
        ],
        'providers_configured' => ['stripe' => $stripeCardReady],
        'stripe_blocked' => $stripeCardReady ? null : 'Stripe checkout is not fully configured.',
    ]);
}

function record_visit(): never
{
    database()->exec(
        "INSERT INTO site_metrics (metric_key, metric_value) VALUES ('site_visits', 1) " .
        'ON DUPLICATE KEY UPDATE metric_value = metric_value + 1'
    );
    reply(200, ['ok' => true]);
}

$route = trim((string)($_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if (str_starts_with($route, 'staff/api/')) {
        staff_api_dispatch($route, $method);
    }
    if ($route === 'api/health' && $method === 'GET') {
        database()->query('SELECT 1');
        reply(200, ['ok' => true, 'service' => 'Stitchline website']);
    }
    if ($route === 'api/site' && $method === 'GET') {
        reply(200, site_payload());
    }
    if ($route === 'api/human' && $method === 'GET') {
        issue_human_check();
    }
    if ($route === 'api/applications' && $method === 'GET') {
        public_applications();
    }
    if ($route === 'api/store' && $method === 'GET') {
        public_catalog();
    }
    if ($route === 'api/store/quote' && $method === 'POST') {
        public_store_quote(request_body(), 'quote');
    }
    if ($route === 'api/store/discount' && $method === 'POST') {
        public_store_quote(request_body(), 'discount');
    }
    if ($route === 'api/store/tax' && $method === 'POST') {
        public_store_quote(request_body(), 'tax');
    }
    if ($route === 'api/store/order' && $method === 'POST') {
        public_store_order(request_body());
    }
    if ($route === 'api/store/order/lookup' && $method === 'POST') {
        public_store_lookup(request_body());
    }
    if ($route === 'api/stripe/webhook' && $method === 'POST') {
        $raw = file_get_contents('php://input');
        payment_handle_stripe_webhook(is_string($raw) ? $raw : '', (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
    }
    if ($route === 'api/contact' && $method === 'POST') {
        submit_contact(request_body());
    }
    if ($route === 'api/application' && $method === 'POST') {
        submit_application(request_body());
    }
    if ($route === 'api/visits' && $method === 'POST') {
        record_visit();
    }
    if (str_starts_with($route, 'api/bot/')) {
        bot_api_dispatch($route, $method);
    }
    if (str_starts_with($route, 'api/account/')) {
        account_api_dispatch(substr($route, strlen('api/account/')), $method);
    }
    reply(404, ['error' => 'not_found', 'message' => 'Unknown API route.']);
} catch (Throwable $error) {
    error_log('Stitchline API failure: ' . $error->getMessage());
    reply(500, ['error' => 'server_error', 'message' => 'The request could not be completed.']);
}