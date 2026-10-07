<?php
declare(strict_types=1);

// One-time browser installer. It removes itself after a successful run.
$root = __DIR__;
$configFile = $root . '/stitchline-config.php';
if (is_file($configFile) || !is_dir($root . '/setup')) {
    http_response_code(404);
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function field(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function run_install(string $root, string $configFile): void
{
    $dbName = field('db_name');
    $dbUser = field('db_user');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $dbHost = field('db_host') ?: 'localhost';
    if ($dbName === '' || $dbUser === '') {
        throw new RuntimeException('Enter the database name and user.');
    }
    $ownerUser = field('owner_user');
    $ownerEmail = field('owner_email');
    $ownerPass = (string)($_POST['owner_pass'] ?? '');
    $ownerDiscord = field('owner_discord');
    $wantOwner = $ownerUser !== '' || $ownerEmail !== '' || $ownerPass !== '';
    if ($wantOwner && (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $ownerUser) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) ||
        strlen($ownerPass) < 12 || ($ownerDiscord !== '' && !preg_match('/^[0-9]{17,20}$/', $ownerDiscord)))) {
        throw new RuntimeException('Owner needs a username (3+ chars), a valid email, a password of 12+ characters, and a numeric Discord ID if given.');
    }

    $dbPort = field('db_port');
    $useSsl = ($_POST['db_ssl'] ?? '') === '1';
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $dbHost) || ($dbPort !== '' && !ctype_digit($dbPort))) {
        throw new RuntimeException('The database host or port is not valid.');
    }
    $isPg = field('db_type') === 'pgsql';
    if ($isPg) {
        $dsn = 'pgsql:host=' . $dbHost . ';port=' . ($dbPort !== '' ? $dbPort : '5432') . ';dbname=' . $dbName . ';sslmode=' . ($useSsl ? 'require' : 'prefer');
    } else {
        $dsn = 'mysql:host=' . $dbHost . ($dbPort !== '' ? ';port=' . $dbPort : '') . ';dbname=' . $dbName . ';charset=utf8mb4';
    }
    require_once $root . '/db_compat.php';
    try {
        $pdo = db_connect($dsn, $dbUser, $dbPass, ['ssl' => $useSsl]);
    } catch (Throwable $error) {
        throw new RuntimeException('Could not connect to the database. Check the host, port, name, user, password and TLS setting. Driver said: ' . substr(strtok($error->getMessage(), "\n") ?: '', 0, 200));
    }
    $schema = (string)file_get_contents($root . ($isPg ? '/setup/schema.pg.sql' : '/setup/schema.sql'));
    $schema = preg_replace('/^\s*--.*$/m', '', $schema) ?? $schema;
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    $site = json_decode((string)file_get_contents($root . '/setup/site.json'), true);
    if (is_array($site)) {
        $pdo->prepare("INSERT INTO site_documents (document_key, payload_json) VALUES ('site', :p) ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json)")
            ->execute(['p' => json_encode($site, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }

    $store = json_decode((string)file_get_contents($root . '/setup/moderation.json'), true);
    if (is_array($store) && is_array($store['settings'] ?? null)) {
        $pdo->prepare("INSERT INTO bot_store (store_key, payload_json) VALUES ('settings', :p) ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json)")
            ->execute(['p' => json_encode($store['settings'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $insert = $pdo->prepare('INSERT IGNORE INTO bot_records (record_kind, record_id, payload_json, created_at) VALUES (:k, :i, :p, FROM_UNIXTIME(:c))');
        foreach ((array)($store['records'] ?? []) as $kind => $rows) {
            foreach (is_array($rows) ? array_slice($rows, 0, 2000) : [] as $record) {
                if (is_array($record) && !empty($record['id'])) {
                    $insert->execute([
                        'k' => (string)$kind, 'i' => (string)$record['id'],
                        'p' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        'c' => is_numeric($record['at'] ?? null) ? (float)$record['at'] : time(),
                    ]);
                }
            }
        }
    }

    if ($wantOwner && (int)$pdo->query('SELECT COUNT(*) FROM staff_users')->fetchColumn() === 0) {
        $pdo->prepare(
            "INSERT INTO staff_users (id, username, username_key, email, password_hash, tier, title, active, discord_id, created_at) " .
            "VALUES (:id, :u, :k, :e, :h, 'Leadership', 'Owner', 1, :d, UTC_TIMESTAMP())"
        )->execute([
            'id' => bin2hex(random_bytes(16)), 'u' => $ownerUser, 'k' => strtolower($ownerUser), 'e' => $ownerEmail,
            'h' => password_hash($ownerPass, PASSWORD_DEFAULT), 'd' => $ownerDiscord !== '' ? $ownerDiscord : null,
        ]);
    }

    $config = [
        'database' => ['dsn' => $dsn, 'username' => $dbUser, 'password' => $dbPass, 'ssl' => $useSsl],
        'bot_secrets' => ['community' => bin2hex(random_bytes(32))],
        'app_secret' => bin2hex(random_bytes(32)),
        'discord_oauth' => [
            'client_id' => field('discord_id'), 'client_secret' => field('discord_secret'),
            'redirect_uri' => 'https://stitchlineinteractivegames.com/api/account/callback',
        ],
        'roblox_oauth' => [
            'client_id' => field('roblox_id'), 'client_secret' => field('roblox_secret'),
            'redirect_uri' => 'https://stitchlineinteractivegames.com/api/account/roblox/callback',
        ],
        'mail' => ['from' => field('mail_from') ?: 'Stitchline Interactive <no-reply@stitchlineinteractivegames.com>'],
        'payments' => [
            'public_base_url' => 'https://stitchlineinteractivegames.com',
            'stripe_secret' => field('stripe_secret'), 'stripe_webhook_secrets' => [],
            'stripe_webhook_secret' => field('stripe_webhook_secret'),
            'stripe_tax_enabled' => ($_POST['stripe_tax_enabled'] ?? '') === '1',
            'stripe_tax_code' => field('stripe_tax_code'),
        ],
    ];
    $written = file_put_contents($configFile, "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Could not write stitchline-config.php in the site folder.');
    }
    @chmod($configFile, 0600);

    foreach (['schema.sql', 'schema.pg.sql', 'site.json', 'moderation.json'] as $name) {
        @unlink($root . '/setup/' . $name);
    }
    @rmdir($root . '/setup');
    @unlink(__FILE__);
}

$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        run_install($root, $configFile);
        $done = true;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Install failed. Check the database details and try again.';
    }
}
$input = static fn(string $label, string $name, string $type = 'text', string $hint = ''): string =>
    '<label>' . h($label) . '<input type="' . $type . '" name="' . h($name) . '" value="' . ($type === 'password' ? '' : h(field($name))) .
    '" autocomplete="off">' . ($hint !== '' ? '<small>' . h($hint) . '</small>' : '') . '</label>';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Stitchline setup</title>
<style>body{font:16px system-ui;background:#05070b;color:#e8ecf3;max-width:560px;margin:40px auto;padding:0 16px}
label{display:block;margin:12px 0}input{display:block;width:100%;padding:10px;margin-top:4px;background:#0d121a;border:1px solid #2a3342;color:#fff;border-radius:6px;box-sizing:border-box}
small{color:#8a96aa}h2{margin-top:32px;font-size:18px}button{padding:12px 20px;background:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer;margin-top:16px}
.err{background:#3a1218;border:1px solid #8a2a3a;padding:10px;border-radius:6px}.ok{background:#10301c;border:1px solid #2a8a4a;padding:12px;border-radius:6px}</style></head>
<body><h1>Stitchline setup</h1>
<?php if ($done): ?>
<p class="ok">Installed. The database is ready, the site content is loaded, and this installer deleted itself.
<a href="/" style="color:#9cf">Open the site</a>.</p>
<?php else: ?>
<p>Fill this in once. Only the database details are required.</p>
<?php if ($error !== ''): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<h2>Database (required)</h2>
<?= $input('Database name', 'db_name', 'text', 'Supabase: postgres') ?>
<?= $input('Database user', 'db_user') ?>
<?= $input('Database password', 'db_pass', 'password') ?>
<label>Database type <select name="db_type" style="display:block;width:100%;padding:10px;margin-top:4px;background:#0d121a;color:#fff;border:1px solid #2a3342;border-radius:6px"><option value="pgsql">PostgreSQL (Supabase)</option><option value="mysql">MySQL</option></select></label>
<?= $input('Database host', 'db_host', 'text', 'Supabase: the Session pooler host, e.g. aws-0-xx.pooler.supabase.com') ?>
<?= $input('Database port', 'db_port', 'text', 'Supabase session pooler: 5432') ?>
<label><input type="checkbox" name="db_ssl" value="1"> Use TLS (required by most remote providers)</label>
<h2>Owner staff account (optional)</h2>
<?= $input('Username', 'owner_user') ?>
<?= $input('Email', 'owner_email', 'email') ?>
<?= $input('Password', 'owner_pass', 'password', 'At least 12 characters') ?>
<?= $input('Discord user ID', 'owner_discord', 'text', 'Numeric ID, optional') ?>
<h2>Logins (optional, can be added later)</h2>
<?= $input('Discord client ID', 'discord_id') ?>
<?= $input('Discord client secret', 'discord_secret', 'password') ?>
<?= $input('Roblox client ID', 'roblox_id') ?>
<?= $input('Roblox client secret', 'roblox_secret', 'password') ?>
<?= $input('Email sender', 'mail_from', 'text', 'Optional, e.g. Stitchline Interactive <no-reply@stitchlineinteractivegames.com>') ?>
<h2>Payments (optional)</h2>
<?= $input('Stripe secret key', 'stripe_secret', 'password') ?>
<?= $input('Stripe webhook signing secret', 'stripe_webhook_secret', 'password', 'Use the signing secret for https://stitchlineinteractivegames.com/api/stripe/webhook') ?>
<label><input type="checkbox" name="stripe_tax_enabled" value="1" <?= ($_POST['stripe_tax_enabled'] ?? '') === '1' ? 'checked' : '' ?>> Stripe Tax is enabled in my Stripe account</label>
<?= $input('Stripe product tax code', 'stripe_tax_code', 'text', 'Optional. Leave blank to use your Stripe default tax code.') ?>
<p>Checkout requires the secret key, webhook signing secret and Stripe Tax. You can finish these settings later in stitchline-config.php.</p>
<button type="submit">Install</button>
</form>
<?php endif; ?>
</body></html>
