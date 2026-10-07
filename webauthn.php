<?php
declare(strict_types=1);

function wa_b64(string $value): string
{
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    if ($decoded === false) throw new RuntimeException('Invalid WebAuthn encoding.');
    return $decoded;
}

function wa_len(int $length): string
{
    if ($length < 128) return chr($length);
    $raw = ltrim(pack('N', $length), "\0");
    return chr(0x80 | strlen($raw)) . $raw;
}

function wa_der(int $tag, string $value): string
{
    return chr($tag) . wa_len(strlen($value)) . $value;
}

function wa_cbor(string $data, int &$offset = 0): mixed
{
    if (!isset($data[$offset])) throw new RuntimeException('Truncated WebAuthn CBOR.');
    $head = ord($data[$offset++]);
    $major = $head >> 5;
    $minor = $head & 31;
    if ($minor < 24) $length = $minor;
    elseif ($minor === 24) $length = ord($data[$offset++]);
    elseif ($minor === 25) { $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; }
    elseif ($minor === 26) { $length = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; }
    else throw new RuntimeException('Unsupported WebAuthn CBOR value.');
    if ($major === 0) return $length;
    if ($major === 1) return -1 - $length;
    if ($major === 2) { $value = substr($data, $offset, $length); $offset += $length; return $value; }
    if ($major === 3) { $value = substr($data, $offset, $length); $offset += $length; return $value; }
    if ($major === 4) { $items = []; for ($i = 0; $i < $length; $i++) $items[] = wa_cbor($data, $offset); return $items; }
    if ($major === 5) { $map = []; for ($i = 0; $i < $length; $i++) { $key = wa_cbor($data, $offset); $map[(string)$key] = wa_cbor($data, $offset); } return $map; }
    throw new RuntimeException('Unsupported WebAuthn CBOR type.');
}

function wa_origin(): array
{
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) throw new RuntimeException('Invalid WebAuthn host.');
    return [($secure ? 'https://' : 'http://') . $host, $host];
}

function wa_client(string $encoded, string $type, string $challenge, string $origin): string
{
    $raw = wa_b64($encoded);
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['type'] ?? '') !== $type || ($data['origin'] ?? '') !== $origin) {
        throw new RuntimeException('WebAuthn client data was rejected.');
    }
    if (!hash_equals(wa_b64((string)($data['challenge'] ?? '')), wa_b64($challenge))) {
        throw new RuntimeException('WebAuthn challenge mismatch.');
    }
    return $raw;
}

function wa_public_key(string $x, string $y): string
{
    $algorithm = wa_der(0x30, wa_der(0x06, hex2bin('2a8648ce3d0201')) . wa_der(0x06, hex2bin('2a8648ce3d030107')));
    $point = "\x04" . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n" . base64_encode(wa_der(0x30, $algorithm . wa_der(0x03, "\0" . $point))) . "\n-----END PUBLIC KEY-----";
}

function wa_verify(string $authenticator, string $client, string $credentialId, string $signature, array $key, string $challenge, string $origin, string $rpId): int
{
    $clientRaw = wa_client($client, 'webauthn.get', $challenge, $origin);
    $auth = wa_b64($authenticator);
    if (strlen($auth) < 37 || !hash_equals(substr($auth, 0, 32), hash('sha256', $rpId, true))) throw new RuntimeException('WebAuthn RP ID mismatch.');
    $count = unpack('N', substr($auth, 33, 4))[1];
    $stored = wa_b64((string)$key['credential_id']);
    if (!hash_equals($stored, wa_b64($credentialId))) throw new RuntimeException('WebAuthn credential mismatch.');
    if ($count > 0 && (int)$key['sign_count'] > 0 && $count <= (int)$key['sign_count']) throw new RuntimeException('WebAuthn signature counter did not advance.');
    $signed = $auth . hash('sha256', $clientRaw, true);
    $ok = openssl_verify($signed, wa_b64($signature), wa_public_key((string)$key['public_x'], (string)$key['public_y']), OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('WebAuthn signature was rejected.');
    return $count;
}

function wa_registration(string $attestation, string $client, string $challenge, string $origin, string $rpId): array
{
    $clientRaw = wa_client($client, 'webauthn.create', $challenge, $origin);
    $offset = 0;
    $object = wa_cbor(wa_b64($attestation), $offset);
    $auth = is_array($object) ? ($object['authData'] ?? '') : '';
    if (!is_string($auth) || strlen($auth) < 55 || !hash_equals(substr($auth, 0, 32), hash('sha256', $rpId, true)) || !(ord($auth[32]) & 0x40)) throw new RuntimeException('WebAuthn registration data was rejected.');
    $length = unpack('n', substr($auth, 53, 2))[1];
    if ($length < 1 || 55 + $length >= strlen($auth)) throw new RuntimeException('WebAuthn credential data is invalid.');
    $credentialId = substr($auth, 55, $length);
    $offset = 55 + $length;
    $cose = wa_cbor($auth, $offset);
    if (!is_array($cose) || (int)($cose['1'] ?? 0) !== 2 || (int)($cose['3'] ?? 0) !== -7) throw new RuntimeException('Only ES256 passkeys are supported.');
    $x = $cose['-2'] ?? '';
    $y = $cose['-3'] ?? '';
    if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('WebAuthn public key is invalid.');
    return ['credential_id'=>rtrim(strtr(base64_encode($credentialId), '+/', '-_'), '='), 'public_x'=>$x, 'public_y'=>$y, 'sign_count'=>unpack('N', substr($auth, 33, 4))[1]];
}
