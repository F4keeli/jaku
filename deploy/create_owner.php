<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = getenv('STITCHLINE_PRIVATE_CONFIG') ?: __DIR__ . '/stitchline-config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Private configuration not found.\n");
    exit(2);
}
$config = require $configPath;
$database = $config['database'] ?? [];
$username = trim((string)getenv('STITCHLINE_OWNER_USERNAME'));
$email = trim((string)getenv('STITCHLINE_OWNER_EMAIL'));
$discordId = trim((string)getenv('STITCHLINE_OWNER_DISCORD_ID'));
$password = (string)getenv('STITCHLINE_OWNER_PASSWORD');

if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 ||
    ($discordId !== '' && !preg_match('/^[0-9]{17,20}$/', $discordId))) {
    fwrite(STDERR, "Set a valid owner username/email, optional numeric Discord ID, and a new password of at least 12 characters in the environment.\n");
    exit(2);
}

try {
    $pdo = new PDO(
        (string)$database['dsn'],
        (string)$database['username'],
        (string)$database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ((int)$pdo->query('SELECT COUNT(*) FROM staff_users')->fetchColumn() !== 0) {
        fwrite(STDERR, "Owner setup is one-time only; staff_users is not empty.\n");
        exit(2);
    }
    $insert = $pdo->prepare(
        'INSERT INTO staff_users (id, username, username_key, email, password_hash, tier, title, active, discord_id, created_at) ' .
        'VALUES (:id, :username, :username_key, :email, :password_hash, \'Leadership\', \'Owner\', 1, :discord_id, UTC_TIMESTAMP())'
    );
    $insert->execute([
        'id' => bin2hex(random_bytes(16)),
        'username' => $username,
        'username_key' => strtolower($username),
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'discord_id' => $discordId !== '' ? $discordId : null,
    ]);
    fwrite(STDOUT, "Owner account created. Remove the owner credentials from the shell environment now.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Owner setup failed. Confirm the schema and database configuration.\n");
    exit(1);
}