<?php
declare(strict_types=1);

/**
 * PostgreSQL support. The site's SQL is written for MySQL; this PDO subclass
 * rewrites the few MySQL-only constructs when the connection is PostgreSQL.
 */
final class StitchPgPdo extends PDO
{
    private const CONFLICT_KEYS = [
        'site_documents' => 'document_key',
        'bot_store' => 'store_key',
        'bot_heartbeats' => 'bot_id',
        'bot_records' => 'record_kind, record_id',
        'api_rate_limits' => 'bucket, identity_hash',
        'site_metrics' => 'metric_key',
        'customer_accounts' => 'discord_id',
        'staff_passkeys' => 'credential_id',
        'payment_webhook_events' => 'provider, event_id',
    ];

    public static function translate(string $sql): string
    {
        $sql = str_replace('UTC_TIMESTAMP()', "date_trunc('second', NOW() AT TIME ZONE 'UTC')", $sql);
        $sql = preg_replace('/\bINTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY)\b/i', "INTERVAL '$1 $2'", $sql) ?? $sql;
        $sql = preg_replace('/\bFROM_UNIXTIME\(\s*(:\w+)\s*\)/i', "(to_timestamp($1) AT TIME ZONE 'UTC')", $sql) ?? $sql;

        $ignore = false;
        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO\b/i', $sql)) {
            $sql = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO\b/i', 'INSERT INTO', $sql) ?? $sql;
            $ignore = true;
        }

        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $table = preg_match('/^\s*INSERT\s+INTO\s+(\w+)/i', $sql, $m) ? strtolower($m[1]) : '';
            $key = self::CONFLICT_KEYS[$table] ?? null;
            if ($key === null) {
                throw new RuntimeException('No conflict key is defined for ' . $table . '.');
            }
            $sql = preg_replace('/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT (' . $key . ') DO UPDATE SET', $sql) ?? $sql;
            $sql = preg_replace('/\bVALUES\((\w+)\)/i', 'EXCLUDED.$1', $sql) ?? $sql;
            $sql = preg_replace('/\bIF\(([^,]+),\s*([^,]+),\s*([^)]+)\)/', 'CASE WHEN $1 THEN $2 ELSE $3 END', $sql) ?? $sql;
        }

        if ($ignore) {
            $sql = rtrim($sql, " \t\r\n;") . ' ON CONFLICT DO NOTHING';
        }
        return $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(self::translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = self::translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::translate($statement));
    }
}

function db_is_pgsql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}

/** Binary values are stored as base64 text on PostgreSQL. */
function db_bin_pack(PDO $pdo, string $raw): string
{
    return db_is_pgsql($pdo) ? base64_encode($raw) : $raw;
}

function db_bin_unpack(PDO $pdo, string $stored): string
{
    return db_is_pgsql($pdo) ? (string)base64_decode($stored, true) : $stored;
}

/** Opens the database for either MySQL or PostgreSQL based on the DSN. */
function db_connect(string $dsn, string $user, string $pass, array $database = []): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (str_starts_with($dsn, 'pgsql:')) {
        $pdo = new StitchPgPdo($dsn, $user, $pass, $options);
        $pdo->exec("SET TIME ZONE 'UTC'");
        return $pdo;
    }
    if (!empty($database['ssl'])) {
        $bundle = (string)($database['ssl_ca'] ?? '');
        foreach ($bundle !== '' ? [$bundle] : ['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/ssl/cert.pem'] as $candidate) {
            if (is_readable($candidate)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $candidate;
                break;
            }
        }
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = isset($options[PDO::MYSQL_ATTR_SSL_CA]);
        if (!isset($options[PDO::MYSQL_ATTR_SSL_CA])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = '';
        }
    }
    return new PDO($dsn, $user, $pass, $options);
}
