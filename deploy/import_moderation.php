<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = getenv('STITCHLINE_PRIVATE_CONFIG') ?: __DIR__ . '/stitchline-config.php';
$sourcePath = $argv[1] ?? (__DIR__ . '/stitchline-moderation-content.json');
if (!is_file($configPath) || !is_file($sourcePath)) {
    fwrite(STDERR, "Private config or moderation source file not found.\n");
    exit(2);
}
$config = require $configPath;
$store = json_decode((string)file_get_contents($sourcePath), true);
if (!is_array($store) || !is_array($store['records'] ?? null) || !is_array($store['settings'] ?? null)) {
    fwrite(STDERR, "The moderation source file has an invalid format.\n");
    exit(2);
}

$caps = [
    'cases' => 1000, 'actions' => 1000, 'messages' => 2000, 'server' => 2000,
    'tickets' => 1000, 'giveaways' => 200, 'events' => 200, 'tasks' => 500,
    'submissions' => 1000,
];
try {
    $db = $config['database'] ?? [];
    $pdo = new PDO(
        (string)$db['dsn'],
        (string)$db['username'],
        (string)$db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->beginTransaction();
    $settings = $pdo->prepare(
        "INSERT INTO bot_store (store_key, payload_json) VALUES ('settings', :payload) " .
        'ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json)'
    );
    $settings->execute([
        'payload' => json_encode($store['settings'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO bot_records (record_kind, record_id, payload_json, created_at) ' .
        'VALUES (:kind, :id, :payload, FROM_UNIXTIME(:created))'
    );
    $added = 0;
    foreach ($caps as $kind => $cap) {
        $rows = $store['records'][$kind] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? '')));
        foreach (array_slice($rows, 0, $cap) as $record) {
            if (!is_array($record) || empty($record['id'])) {
                continue;
            }
            $at = is_numeric($record['at'] ?? null) ? (float)$record['at'] : time();
            $insert->execute([
                'kind' => $kind,
                'id' => (string)$record['id'],
                'payload' => json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'created' => $at,
            ]);
            $added += $insert->rowCount();
        }
    }
    $pdo->commit();
    fwrite(STDOUT, "Moderation data imported. {$added} new records added; existing ids were preserved.\n");
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Moderation import failed. Check the schema and private database configuration.\n");
    exit(1);
}
