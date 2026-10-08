<?php
declare(strict_types=1);

function bot_authenticate(string $rawBody): string
{
    $bot = (string)($_SERVER['HTTP_X_SI_BOT'] ?? '');
    $timestamp = (string)($_SERVER['HTTP_X_SI_TIMESTAMP'] ?? '');
    $signature = (string)($_SERVER['HTTP_X_SI_SIGNATURE'] ?? '');
    $secret = (string)(settings()['bot_secrets'][$bot] ?? '');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
    $target = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($bot !== 'community' || $secret === '' ||
        !ctype_digit($timestamp) || !in_array($method, ['GET', 'POST'], true) ||
        !str_starts_with((string)parse_url($target, PHP_URL_PATH), '/api/bot/')) {
        reply(401, ['error' => 'unauthorized', 'message' => 'Bot authentication failed.']);
    }
    if (abs(time() - (int)$timestamp) > 300) {
        reply(401, ['error' => 'expired_signature', 'message' => 'The request timestamp has expired.']);
    }
    $canonical = $timestamp . "\n" . $bot . "\n" . $method . "\n" . $target . "\n" . hash('sha256', $rawBody);
    if (!hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
        reply(401, ['error' => 'unauthorized', 'message' => 'Bot authentication failed.']);
    }
    if (strlen($rawBody) > 65536) {
        reply(413, ['error' => 'payload_too_large', 'message' => 'The bot request was too large.']);
    }
    return $bot;
}

function bot_collection_caps(): array
{
    return [
        'cases' => 1000, 'actions' => 1000, 'messages' => 2000, 'server' => 2000,
        'tickets' => 1000, 'giveaways' => 200, 'events' => 200, 'tasks' => 500,
        'submissions' => 1000,
    ];
}

function bot_default_settings(): array
{
    return [
        'log_channels' => [], 'action_log_channel' => '', 'ticket_panel_channel' => '',
        'ticket_category' => '', 'ticket_role' => '', 'staff_role' => '', 'mute_role' => '',
        'guilds' => [], 'message_excerpt' => 280, 'message_retention_days' => 30,
    ];
}

function bot_read_settings(): array
{
    $query = database()->query("SELECT payload_json FROM bot_store WHERE store_key = 'settings' LIMIT 1");
    $raw = $query->fetchColumn();
    if ($raw === false) {
        $settings = bot_default_settings();
        $insert = database()->prepare("INSERT IGNORE INTO bot_store (store_key, payload_json) VALUES ('settings', :payload)");
        $insert->execute(['payload' => json_encode($settings, JSON_UNESCAPED_SLASHES)]);
        return $settings;
    }
    $saved = json_decode((string)$raw, true);
    return is_array($saved) ? array_replace(bot_default_settings(), $saved) : bot_default_settings();
}

function bot_write_settings(array $body): never
{
    $settings = bot_read_settings();
    $defaults = bot_default_settings();
    $patch = is_array($body['patch'] ?? null) ? $body['patch'] : [];
    foreach ($patch as $key => $value) {
        if (!array_key_exists($key, $defaults)) {
            continue;
        }
        if (is_array($defaults[$key])) {
            if (is_array($value)) {
                $values = $value;
            } elseif (is_string($value)) {
                $values = preg_split('/\R/', $value) ?: [];
            } else {
                $values = [];
            }
            $settings[$key] = array_values(array_filter(array_map(
                static fn(mixed $item): string => trim((string)$item),
                $values
            ), static fn(string $item): bool => $item !== ''));
        } elseif (is_int($defaults[$key])) {
            $settings[$key] = max(0, (int)$value);
        } else {
            $settings[$key] = clean_text($value, 300, $key);
        }
    }
    $write = database()->prepare(
        "INSERT INTO bot_store (store_key, payload_json) VALUES ('settings', :payload) " .
        'ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json)'
    );
    $write->execute(['payload' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    reply(200, ['ok' => true, 'settings' => $settings]);
}

function bot_validate_collection(string $kind): int
{
    $caps = bot_collection_caps();
    if (!isset($caps[$kind])) {
        reply(400, ['error' => 'bad_collection', 'message' => 'Unknown moderation record collection.']);
    }
    return $caps[$kind];
}

function bot_prune_collection(string $kind, int $cap): void
{
    $query = database()->prepare(
        'SELECT record_id FROM bot_records WHERE record_kind = :kind ORDER BY record_id DESC LIMIT 10000'
    );
    $query->execute(['kind' => $kind]);
    $remove = array_slice($query->fetchAll(PDO::FETCH_COLUMN), $cap);
    if ($kind === 'messages') {
        $days = max(0, (int)(bot_read_settings()['message_retention_days'] ?? 30));
        if ($days > 0) {
            $purge = database()->prepare(
                "DELETE FROM bot_records WHERE record_kind = 'messages' AND created_at < UTC_TIMESTAMP() - INTERVAL " . $days . ' DAY'
            );
            $purge->execute();
        }
    }
    if ($remove) {
        $marks = implode(',', array_fill(0, count($remove), '?'));
        $delete = database()->prepare('DELETE FROM bot_records WHERE record_kind = ? AND record_id IN (' . $marks . ')');
        $delete->execute(array_merge([$kind], $remove));
    }
}

function bot_add_record(string $kind, array $record, mixed $stamp = null): array
{
    $cap = bot_validate_collection($kind);
    $at = is_numeric($stamp) ? (float)$stamp : microtime(true);
    $id = sprintf('%016d-%03d', (int)($at * 1000000), random_int(0, 999));
    $record['id'] = $id;
    $record['at'] = $record['at'] ?? $at;
    $record['created_at'] = $record['created_at'] ?? $record['at'];
    $insert = database()->prepare(
        'INSERT INTO bot_records (record_kind, record_id, payload_json, created_at) ' .
        'VALUES (:kind, :id, :payload, FROM_UNIXTIME(:created))'
    );
    $insert->execute([
        'kind' => $kind,
        'id' => $id,
        'payload' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        'created' => $at,
    ]);
    bot_prune_collection($kind, $cap);
    return $record;
}

function bot_read_records(): never
{
    $kind = clean_text($_GET['kind'] ?? '', 24, 'Collection');
    $cap = bot_validate_collection($kind);
    $limit = max(1, min((int)($_GET['limit'] ?? 100), $cap));
    $filters = json_decode((string)($_GET['filters'] ?? '{}'), true);
    $filters = is_array($filters) ? $filters : [];
    $query = database()->prepare(
        'SELECT payload_json FROM bot_records WHERE record_kind = :kind ORDER BY record_id DESC LIMIT :limit'
    );
    $query->bindValue(':kind', $kind);
    $query->bindValue(':limit', $cap, PDO::PARAM_INT);
    $query->execute();
    $records = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $record = json_decode((string)$raw, true);
        if (!is_array($record)) {
            continue;
        }
        $matches = true;
        foreach ($filters as $field => $wanted) {
            $actual = $record[$field] ?? null;
            $matches = $field === 'status' && is_array($wanted)
                ? in_array($actual, $wanted, true)
                : $actual === $wanted;
            if (!$matches) {
                break;
            }
        }
        if ($matches) {
            $records[] = $record;
            if (count($records) >= $limit) {
                break;
            }
        }
    }
    reply(200, ['records' => $records]);
}

function bot_write_records(array $body): never
{
    $kind = clean_text($body['kind'] ?? '', 24, 'Collection');
    $cap = bot_validate_collection($kind);
    $operation = clean_text($body['operation'] ?? '', 16, 'Operation');
    if ($operation === 'add' || $operation === 'add_many') {
        $source = $operation === 'add' ? [$body['record'] ?? null] : ($body['records'] ?? []);
        if (!is_array($source)) {
            reply(422, ['error' => 'bad_record', 'message' => 'Records must be an array.']);
        }
        $added = [];
        foreach (array_slice($source, 0, $cap) as $record) {
            if (is_array($record)) {
                $added[] = bot_add_record($kind, $record, $body['at'] ?? null);
            }
        }
        reply(200, $operation === 'add' ? ['ok' => true, 'record' => ($added[0] ?? null)]
            : ['ok' => true, 'added' => count($added)]);
    }

    $id = clean_text($body['id'] ?? '', 64, 'Record id');
    $find = database()->prepare(
        'SELECT payload_json FROM bot_records WHERE record_kind = :kind AND record_id = :id LIMIT 1'
    );
    $find->execute(['kind' => $kind, 'id' => $id]);
    $raw = $find->fetchColumn();
    $record = $raw === false ? null : json_decode((string)$raw, true);
    if ($operation === 'get') {
        reply(200, ['record' => is_array($record) ? $record : null]);
    }
    if ($operation === 'remove') {
        $delete = database()->prepare('DELETE FROM bot_records WHERE record_kind = :kind AND record_id = :id');
        $delete->execute(['kind' => $kind, 'id' => $id]);
        reply(200, ['removed' => $delete->rowCount() > 0]);
    }
    if ($operation === 'patch') {
        if (!is_array($record)) {
            reply(200, ['record' => null]);
        }
        $record = array_replace($record, is_array($body['changes'] ?? null) ? $body['changes'] : []);
        $record['updated_at'] = microtime(true);
        $write = database()->prepare(
            'UPDATE bot_records SET payload_json = :payload WHERE record_kind = :kind AND record_id = :id'
        );
        $write->execute([
            'payload' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'kind' => $kind,
            'id' => $id,
        ]);
        reply(200, ['record' => $record]);
    }
    reply(400, ['error' => 'bad_operation', 'message' => 'Unknown moderation store operation.']);
}

function bot_fulfillment_orders(): never
{
    $query = database()->query(
        "SELECT reference, discord_id, discord_name, lines_json FROM orders " .
        "WHERE status = 'paid' ORDER BY paid_at ASC LIMIT 25"
    );
    $orders = [];
    foreach ($query->fetchAll() as $order) {
        $orders[] = [
            'ref' => (string)$order['reference'],
            'discord_id' => (string)$order['discord_id'],
            'discord_name' => (string)$order['discord_name'],
            'lines' => json_decode((string)$order['lines_json'], true) ?: [],
        ];
    }
    reply(200, ['orders' => $orders]);
}

function bot_claim_fulfillment(array $body): never
{
    $reference = strtoupper(clean_text($body['ref'] ?? '', 12, 'Order reference'));
    if (!preg_match('/^SI-[A-F0-9]{6}$/', $reference)) {
        reply(422, ['error' => 'bad_reference', 'message' => 'The order reference is invalid.']);
    }
    $update = database()->prepare(
        "UPDATE orders SET status = 'fulfillment_pending', payment_error = NULL " .
        "WHERE reference = :reference AND status = 'paid'"
    );
    $update->execute(['reference' => $reference]);
    if ($update->rowCount() === 0) {
        reply(409, ['error' => 'already_claimed', 'message' => 'That order is already being fulfilled or is not paid.']);
    }
    reply(200, ['ok' => true, 'ref' => $reference]);
}

function bot_finish_fulfillment(array $body): never
{
    $reference = strtoupper(clean_text($body['ref'] ?? '', 12, 'Order reference'));
    $success = !empty($body['success']);
    $error = clean_text($body['error'] ?? '', 500, 'Fulfillment error');
    $status = $success ? 'delivered' : 'paid';
    $query = database()->prepare(
        'UPDATE orders SET status = :status, fulfillment_error = :error, fulfilled_at = ' .
        ($success ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE reference = :reference AND status = \'fulfillment_pending\''
    );
    $query->execute(['status' => $status, 'error' => $success ? null : ($error ?: 'The bot could not complete delivery.') ,'reference' => $reference]);
    if ($query->rowCount() === 0) {
        reply(409, ['error' => 'not_claimed', 'message' => 'That order is not currently claimed for fulfillment.']);
    }
    reply(200, ['ok' => true, 'ref' => $reference, 'status' => $status]);
}

/* ---------------------------------------------------------
   Discord role checks

   The website cannot see guild roles, but the bot can. When a
   member verifies their account the site queues their Discord
   ID here; the bot reports the roles the member holds, the
   highest studio role wins, and a matching member is linked to
   a staff login with the email they verified. */
function bot_role_checks_pending(): never
{
    $rows = database()->query(
        'SELECT discord_id, discord_username FROM discord_role_checks ' .
        'WHERE checked_at IS NULL ORDER BY requested_at LIMIT 100'
    )->fetchAll();
    $checks = [];
    foreach ($rows as $row) {
        $checks[] = [
            'discord_id' => (string)$row['discord_id'],
            'discord_username' => (string)$row['discord_username'],
        ];
    }
    reply(200, ['checks' => $checks]);
}

function bot_role_checks_report(array $body): never
{
    $discordId = (string)($body['discord_id'] ?? '');
    if (!preg_match('/^[0-9]{17,20}$/', $discordId)) {
        reply(400, ['error' => 'bad_discord_id', 'message' => 'A numeric Discord user ID is required.']);
    }
    $roles = $body['roles'] ?? [];
    if (!is_array($roles)) {
        reply(400, ['error' => 'bad_roles', 'message' => 'Roles must be a list of role IDs.']);
    }
    $roleIds = [];
    foreach ($roles as $role) {
        $roleId = is_scalar($role) ? trim((string)$role) : '';
        if (preg_match('/^[0-9]{15,20}$/', $roleId)) {
            $roleIds[] = $roleId;
        }
    }

    /* Match the member's roles against the studio roster. The
       tier ladder decides which role wins when a member holds
       several: Leadership outranks Management, which outranks
       Staff, Development and Testing. */
    $ladder = ['Leadership', 'Management', 'Staff', 'Development', 'Testing'];
    $matched = null;
    foreach ((site_document()['staffRoles'] ?? []) as $staffRole) {
        if (!is_array($staffRole)) {
            continue;
        }
        $roleId = (string)($staffRole['id'] ?? '');
        if ($roleId === '' || !in_array($roleId, $roleIds, true)) {
            continue;
        }
        $tier = (string)($staffRole['tier'] ?? 'Staff');
        if (!in_array($tier, $ladder, true)) {
            $tier = 'Staff';
        }
        if ($matched === null || array_search($tier, $ladder, true) < array_search($matched['tier'], $ladder, true)) {
            $matched = ['role' => (string)($staffRole['name'] ?? ''), 'tier' => $tier];
        }
    }

    $pdo = database();
    $pdo->prepare(
        'UPDATE discord_role_checks SET checked_at = UTC_TIMESTAMP(), roles_json = :roles, ' .
        'matched_role = :matched_role, staff_tier = :staff_tier WHERE discord_id = :discord_id'
    )->execute([
        'roles' => json_encode($roleIds),
        'matched_role' => $matched['role'] ?? null,
        'staff_tier' => $matched['tier'] ?? null,
        'discord_id' => $discordId,
    ]);
    if ($matched === null) {
        reply(200, ['ok' => true, 'staff' => false]);
    }
    bot_link_staff_account($discordId, $matched['role'], $matched['tier']);
    reply(200, ['ok' => true, 'staff' => true, 'role' => $matched['role'], 'tier' => $matched['tier']]);
}

function bot_link_staff_account(string $discordId, string $roleName, string $tier): void
{
    $pdo = database();
    $account = $pdo->prepare(
        'SELECT discord_username, email FROM customer_accounts WHERE discord_id = :discord_id LIMIT 1'
    );
    $account->execute(['discord_id' => $discordId]);
    $customer = $account->fetch();
    $username = $customer ? (string)$customer['discord_username'] : '';
    /* Staff usernames sign in with the same characters the owner
       setup allows, so anything else a Discord name may contain
       is folded to an underscore. */
    $username = trim((string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $username));
    if ($username === '') {
        $username = 'staff-' . substr($discordId, -8);
    }
    $username = substr($username, 0, 64);
    $email = $customer ? strtolower((string)$customer['email']) : '';

    $existing = $pdo->prepare(
        'SELECT id FROM staff_users WHERE discord_id = :discord_id LIMIT 1'
    );
    $existing->execute(['discord_id' => $discordId]);
    $staffId = $existing->fetchColumn();
    if ($staffId !== false) {
        /* Already linked: keep the login, refresh the role. */
        $pdo->prepare(
            'UPDATE staff_users SET tier = :tier, title = :title, active = 1 WHERE id = :id'
        )->execute(['tier' => $tier, 'title' => $roleName, 'id' => (string)$staffId]);
        return;
    }

    if ($email !== '') {
        $byEmail = $pdo->prepare('SELECT id, discord_id FROM staff_users WHERE email = :email LIMIT 1');
        $byEmail->execute(['email' => $email]);
        $owner = $byEmail->fetch();
        if ($owner !== false) {
            if ((string)$owner['discord_id'] === '') {
                /* The member verified this email, so it belongs to
                   them: link their Discord ID to the existing login. */
                $pdo->prepare(
                    'UPDATE staff_users SET discord_id = :discord_id, tier = :tier, ' .
                    'title = :title, active = 1 WHERE id = :id'
                )->execute([
                    'discord_id' => $discordId, 'tier' => $tier, 'title' => $roleName,
                    'id' => (string)$owner['id'],
                ]);
            }
            /* Another account already owns this email; never hijack
               it. The check is recorded, the link is not. */
            return;
        }
    }

    $staffId = bin2hex(random_bytes(16));
    $insert = $pdo->prepare(
        'INSERT INTO staff_users (id, username, username_key, email, password_hash, tier, title, active, discord_id, created_at) ' .
        'VALUES (:id, :username, :username_key, :email, :password_hash, :tier, :title, 1, :discord_id, UTC_TIMESTAMP())'
    );
    $insert->execute([
        'id' => $staffId,
        'username' => $username,
        'username_key' => strtolower($username),
        'email' => $email,
        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'tier' => $tier,
        'title' => $roleName,
        'discord_id' => $discordId,
    ]);
    if ($email === '') {
        return;
    }
    /* The login exists but the member has no password yet: send
       the reset link to the email they verified so they can set
       one and sign in to the panel. */
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO staff_password_resets (token_hash, staff_id, expires_at) ' .
        'VALUES (:token, :staff_id, UTC_TIMESTAMP() + INTERVAL 20 MINUTE)'
    )->execute(['token' => hash('sha256', $token), 'staff_id' => $staffId]);
    $mail = settings()['mail'] ?? [];
    $base = rtrim((string)(settings()['payments']['public_base_url'] ?? ''), '/');
    if (!empty($mail['from']) && $base !== '') {
        mail($email, 'Your Stitchline staff login',
            'Welcome to the Stitchline staff panel. Set your password here: ' . $base . '/staff?reset=' . rawurlencode($token),
            ['From' => (string)$mail['from'], 'Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

function bot_api_dispatch(string $route, string $method): never
{
    $raw = file_get_contents('php://input');
    $raw = is_string($raw) ? $raw : '';
    $bot = bot_authenticate($raw);
    if ($route === 'api/bot/heartbeat' && $method === 'POST') {
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            reply(400, ['error' => 'bad_payload', 'message' => 'The heartbeat payload is invalid.']);
        }
        $write = database()->prepare(
            'INSERT INTO bot_heartbeats (bot_id, received_at, payload_json) VALUES (:bot_id, UTC_TIMESTAMP(), :payload) ' .
            'ON DUPLICATE KEY UPDATE received_at = VALUES(received_at), payload_json = VALUES(payload_json)'
        );
        $write->execute([
            'bot_id' => $bot,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        reply(200, ['ok' => true]);
    }
    if ($route === 'api/bot/site' && $method === 'GET') {
        reply(200, ['site' => site_payload()]);
    }
    if ($route === 'api/bot/settings' && $method === 'GET') {
        reply(200, ['settings' => bot_read_settings()]);
    }
    if ($route === 'api/bot/settings' && $method === 'POST') {
        $body = json_decode($raw, true);
        bot_write_settings(is_array($body) ? $body : []);
    }
    if ($bot === 'community' && $route === 'api/bot/role-checks' && $method === 'GET') {
        bot_role_checks_pending();
    }
    if ($bot === 'community' && $route === 'api/bot/role-checks' && $method === 'POST') {
        $body = json_decode($raw, true);
        bot_role_checks_report(is_array($body) ? $body : []);
    }
    if ($route === 'api/bot/records' && $method === 'GET') {
        bot_read_records();
    }
    if ($route === 'api/bot/records' && $method === 'POST') {
        $body = json_decode($raw, true);
        bot_write_records(is_array($body) ? $body : []);
    }
    if ($bot === 'community' && $route === 'api/bot/fulfillment' && $method === 'GET') {
        bot_fulfillment_orders();
    }
    if ($bot === 'community' && $route === 'api/bot/fulfillment/claim' && $method === 'POST') {
        $body = json_decode($raw, true);
        bot_claim_fulfillment(is_array($body) ? $body : []);
    }
    if ($bot === 'community' && $route === 'api/bot/fulfillment/finish' && $method === 'POST') {
        $body = json_decode($raw, true);
        bot_finish_fulfillment(is_array($body) ? $body : []);
    }
    if ($route === 'api/bot/summary' && $method === 'GET') {
        $summary = array_fill_keys(array_keys(bot_collection_caps()), 0);
        foreach (database()->query('SELECT record_kind, COUNT(*) AS total FROM bot_records GROUP BY record_kind') as $row) {
            $summary[(string)$row['record_kind']] = (int)$row['total'];
        }
        reply(200, ['summary' => $summary]);
    }
    reply(404, ['error' => 'not_found', 'message' => 'Unknown bot API route.']);
}
