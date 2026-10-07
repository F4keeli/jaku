<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$sourcePath = $argv[1] ?? (__DIR__ . '/stitchline-site-content.json');
$configPath = getenv('STITCHLINE_PRIVATE_CONFIG') ?: __DIR__ . '/stitchline-config.php';
if ($sourcePath === '' || !is_file($sourcePath) || !is_file($configPath)) {
    fwrite(STDERR, "Expected stitchline-site-content.json and stitchline-config.php in the account home directory.\n");
    exit(2);
}

$content = file_get_contents($sourcePath);
$site = is_string($content) ? json_decode($content, true) : null;
if (!is_array($site) || !is_array($site['studio'] ?? null) || !is_array($site['games'] ?? null)) {
    fwrite(STDERR, "The source file is not valid public site content.\n");
    exit(2);
}

$config = require $configPath;
$database = $config['database'] ?? [];
try {
    $pdo = new PDO(
        (string)$database['dsn'],
        (string)$database['username'],
        (string)$database['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $statement = $pdo->prepare(
        'INSERT INTO site_documents (document_key, payload_json) VALUES (\'site\', :payload) ' .
        'ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json)'
    );
    $statement->execute([
        'payload' => json_encode($site, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    fwrite(STDOUT, "Imported public site content into MariaDB.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Import failed. Check the private database configuration and schema.\n");
    exit(1);
}