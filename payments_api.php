<?php
declare(strict_types=1);

function payment_config(): array
{
    $settings = settings();
    return is_array($settings['payments'] ?? null) ? $settings['payments'] : [];
}

function payment_http(string $url, string $method, array $headers, string $body): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for hosted payment providers.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($raw)) {
        throw new RuntimeException('Payment provider connection failed: ' . $error);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Payment provider returned an unreadable response.');
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? $data['message'] ?? 'Request rejected';
        throw new RuntimeException('Payment provider HTTP ' . $status . ': ' . clean_text($message, 220, 'Provider error'));
    }
    return $data;
}

function payment_http_get(string $url, array $headers): array
{
    return payment_http($url, 'GET', $headers, '');
}

function stripe_webhook_secrets(): array
{
    $config = payment_config();
    $secrets = [];
    if (is_array($config['stripe_webhook_secrets'] ?? null)) {
        $secrets = array_merge($secrets, $config['stripe_webhook_secrets']);
    }
    if (!empty($config['stripe_webhook_secret'])) {
        $secrets[] = $config['stripe_webhook_secret'];
    }
    return array_values(array_unique(array_filter(array_map('strval', $secrets))));
}

function stripe_ready(string $method = 'stripe'): bool
{
    $config = payment_config();
    return !empty($config['stripe_secret']) && stripe_webhook_secrets() !== [] &&
        !empty($config['stripe_tax_enabled']) && !empty($config['public_base_url']);
}

function paypal_ready(): bool
{
    $config = payment_config();
    return !empty($config['paypal_client_id']) && !empty($config['paypal_client_secret']) &&
        !empty($config['paypal_webhook_id']) && !empty($config['public_base_url']) &&
        !empty($config['stripe_secret']) && !empty($config['stripe_tax_enabled']);
}

function stripe_tax_exact(int $taxableCents, string $postal): array
{
    $config = payment_config();
    if (empty($config['stripe_secret']) || empty($config['stripe_tax_enabled'])) {
        throw new RuntimeException('Enable Stripe Tax and configure a Stripe secret key to calculate exact tax.');
    }
    if (!preg_match('/^[0-9]{5}$/', $postal)) {
        throw new RuntimeException('Enter a valid five-digit US ZIP code for exact sales tax.');
    }
    $form = [
        'currency' => 'usd',
        'line_items[0][amount]' => (string)$taxableCents,
        'line_items[0][reference]' => 'stitchline-cart',
        'line_items[0][description]' => 'Digital goods and supporter roles',
        'customer_details[address][country]' => 'US',
        'customer_details[address][postal_code]' => $postal,
        'customer_details[address_source]' => 'shipping',
    ];
    if (!empty($config['stripe_tax_code'])) $form['line_items[0][tax_code]'] = (string)$config['stripe_tax_code'];
    $result = payment_http(
        'https://api.stripe.com/v1/tax/calculations',
        'POST',
        ['Authorization: Bearer ' . $config['stripe_secret'], 'Content-Type: application/x-www-form-urlencoded', 'User-Agent: StitchlineSite/1.0'],
        http_build_query($form, '', '&', PHP_QUERY_RFC3986)
    );
    $amount = $result['tax_amount_exclusive'] ?? null;
    if (!is_int($amount) && !ctype_digit((string)$amount)) {
        throw new RuntimeException('Stripe Tax did not return an exact tax amount.');
    }
    $rate = 0.0;
    $breakdown = $result['tax_breakdown'] ?? [];
    if (is_array($breakdown) && isset($breakdown[0]['tax_rate_details']['percentage_decimal'])) {
        $rate = (float)$breakdown[0]['tax_rate_details']['percentage_decimal'] / 100;
    }
    return [
        'amount_cents' => (int)$amount,
        'tax' => round(((int)$amount) / 100, 2),
        'rate' => $rate,
        'exact' => true,
        'source' => 'stripe_tax',
        'jurisdiction' => (string)($breakdown[0]['tax_rate_details']['state'] ?? 'US'),
        'detail' => 'Exact tax calculation provided by Stripe Tax.',
        'postal' => $postal,
    ];
}

function stripe_create_checkout(array $order, string $provider): array
{
    $config = payment_config();
    $base = rtrim((string)$config['public_base_url'], '/');
    $amount = (int)round(((float)($order['taxable'] ?? $order['subtotal'])) * 100);
    $form = [
        'mode' => 'payment',
        'client_reference_id' => (string)$order['reference'],
        'metadata[order_ref]' => (string)$order['reference'],
        'metadata[discord_id]' => (string)$order['discord_id'],
        'success_url' => $base . '/store.html?paid=' . rawurlencode((string)$order['reference']) . '&provider=' . rawurlencode($provider),
        'cancel_url' => $base . '/store.html?cancelled=' . rawurlencode((string)$order['reference']),
        'customer_email' => (string)$order['email'],
        'customer_creation' => 'always',
        'saved_payment_method_options[payment_method_save]' => 'enabled',
        'submit_type' => 'pay',
        'billing_address_collection' => 'required',
        'name_collection[individual][enabled]' => 'true',
        'name_collection[individual][optional]' => 'true',
        'phone_number_collection[enabled]' => 'true',
        'tax_id_collection[enabled]' => 'true',
        'tax_id_collection[required]' => 'if_supported',
        'automatic_tax[enabled]' => 'true',
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string)$amount,
        'line_items[0][price_data][tax_behavior]' => 'exclusive',
        'line_items[0][price_data][product_data][name]' => 'Stitchline order ' . $order['reference'],
        'line_items[0][price_data][product_data][description]' => implode(', ', array_map(
            static fn(array $line): string => (string)$line['label'],
            $order['lines']
        )),
    ];
    if (!empty($config['stripe_tax_code'])) $form['line_items[0][price_data][product_data][tax_code]'] = (string)$config['stripe_tax_code'];
    $session = payment_http(
        'https://api.stripe.com/v1/checkout/sessions',
        'POST',
        ['Authorization: Bearer ' . $config['stripe_secret'], 'Content-Type: application/x-www-form-urlencoded', 'User-Agent: StitchlineSite/1.0'],
        http_build_query($form, '', '&', PHP_QUERY_RFC3986)
    );
    $url = (string)($session['url'] ?? '');
    if (!str_starts_with($url, 'https://checkout.stripe.com/')) {
        throw new RuntimeException('Stripe did not return its hosted checkout URL.');
    }
    return ['id'=>(string)($session['id'] ?? ''),'url'=>$url];
}

function paypal_base(): string
{
    return (payment_config()['paypal_environment'] ?? 'sandbox') === 'live'
        ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypal_access_token(): string
{
    static $token = null;
    static $expiry = 0;
    if (is_string($token) && $expiry > time() + 60) return $token;
    $config = payment_config();
    if (empty($config['paypal_client_id']) || empty($config['paypal_client_secret'])) {
        throw new RuntimeException('PayPal client credentials are not configured.');
    }
    $basic = base64_encode($config['paypal_client_id'] . ':' . $config['paypal_client_secret']);
    $response = payment_http(
        paypal_base() . '/v1/oauth2/token',
        'POST',
        ['Authorization: Basic ' . $basic, 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        'grant_type=client_credentials'
    );
    $token = (string)($response['access_token'] ?? '');
    $expiry = time() + (int)($response['expires_in'] ?? 300);
    if ($token === '') throw new RuntimeException('PayPal returned no access token.');
    return $token;
}

function paypal_create_order(array $order): array
{
    $base = rtrim((string)payment_config()['public_base_url'], '/');
    $amount = number_format((float)$order['total'], 2, '.', '');
    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'custom_id' => (string)$order['reference'],
            'invoice_id' => (string)$order['reference'],
            'description' => 'Stitchline order ' . $order['reference'],
            'amount' => ['currency_code'=>'USD','value'=>$amount],
        ]],
        'application_context' => [
            'return_url'=>$base . '/store.html?paid=' . rawurlencode((string)$order['reference']) . '&provider=paypal',
            'cancel_url'=>$base . '/store.html?cancelled=' . rawurlencode((string)$order['reference']),
            'user_action'=>'PAY_NOW',
        ],
    ];
    $response = payment_http(
        paypal_base() . '/v2/checkout/orders',
        'POST',
        ['Authorization: Bearer ' . paypal_access_token(),'Content-Type: application/json','PayPal-Request-Id'=>(string)$order['reference']],
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    $url = '';
    foreach (($response['links'] ?? []) as $link) {
        if (is_array($link) && in_array(($link['rel'] ?? ''), ['approve','payer-action'], true)) $url = (string)($link['href'] ?? '');
    }
    if ($url === '' || !str_starts_with($url, 'https://')) throw new RuntimeException('PayPal did not return an approval URL.');
    return ['id'=>(string)($response['id'] ?? ''),'url'=>$url];
}

function paypal_capture_order(string $paypalId): array
{
    return payment_http(
        paypal_base() . '/v2/checkout/orders/' . rawurlencode($paypalId) . '/capture',
        'POST',
        ['Authorization: Bearer ' . paypal_access_token(),'Content-Type: application/json','PayPal-Request-Id'=>'capture-' . $paypalId],
        '{}'
    );
}

function verify_stripe_webhook(string $rawBody, string $signature): array
{
    $secrets = stripe_webhook_secrets();
    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $signature) as $chunk) {
        [$key,$value] = array_pad(explode('=', trim($chunk), 2), 2, '');
        if ($key === 't') $timestamp = $value;
        if ($key === 'v1') $signatures[] = $value;
    }
    if ($secrets === [] || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
        reply(400, ['error'=>'bad_webhook','message'=>'Stripe webhook signature is missing or expired.']);
    }
    $valid = false;
    foreach ($secrets as $secret) {
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        foreach ($signatures as $candidate) $valid = $valid || hash_equals($expected, $candidate);
    }
    $event = json_decode($rawBody, true);
    if (!$valid || !is_array($event)) reply(400, ['error'=>'bad_webhook','message'=>'Stripe webhook signature is invalid.']);
    return $event;
}

function verify_paypal_webhook(string $rawBody): array
{
    $config = payment_config();
    if (empty($config['paypal_webhook_id'])) reply(400, ['error'=>'bad_webhook','message'=>'PayPal webhook ID is not configured.']);
    $event = json_decode($rawBody, true);
    if (!is_array($event)) reply(400, ['error'=>'bad_webhook','message'=>'PayPal webhook body is invalid.']);
    $headers = [
        'auth_algo'=>(string)($_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? ''),
        'cert_url'=>(string)($_SERVER['HTTP_PAYPAL_CERT_URL'] ?? ''),
        'transmission_id'=>(string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? ''),
        'transmission_sig'=>(string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? ''),
        'transmission_time'=>(string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? ''),
    ];
    $response = payment_http(
        paypal_base() . '/v1/notifications/verify-webhook-signature',
        'POST',
        ['Authorization: Bearer ' . paypal_access_token(),'Content-Type: application/json'],
        json_encode($headers + ['webhook_id'=>$config['paypal_webhook_id'],'webhook_event'=>$event], JSON_UNESCAPED_SLASHES)
    );
    if (($response['verification_status'] ?? '') !== 'SUCCESS') reply(400, ['error'=>'bad_webhook','message'=>'PayPal webhook signature is invalid.']);
    return $event;
}

function payment_webhook_seen(string $provider, string $eventId): bool
{
    $query = database()->prepare(
        'SELECT 1 FROM payment_webhook_events WHERE provider = :provider AND event_id = :event LIMIT 1'
    );
    $query->execute(['provider'=>$provider,'event'=>$eventId]);
    return (bool)$query->fetchColumn();
}

function payment_webhook_record(string $provider, string $eventId): void
{
    $insert = database()->prepare(
        'INSERT IGNORE INTO payment_webhook_events (provider, event_id, received_at) VALUES (:provider,:event,UTC_TIMESTAMP())'
    );
    $insert->execute(['provider'=>$provider,'event'=>$eventId]);
}

function payment_settle_order(string $reference, string $provider, string $providerId, int $paidCents, int $taxCents): bool
{
    $providerColumn = $provider === 'stripe' ? 'stripe_session_id' : 'paypal_order_id';
    $providerPredicate = $provider === 'stripe'
        ? "provider IN ('stripe','card','cashapp')"
        : "provider = 'paypal'";
    $query = database()->prepare(
        'SELECT status FROM orders WHERE reference = :reference AND ' . $providerPredicate . ' AND ' . $providerColumn . ' = :provider_id LIMIT 1'
    );
    $query->execute(['reference'=>$reference,'provider_id'=>$providerId]);
    $status = $query->fetchColumn();
    if ($status === false || $status === 'paid' || $status === 'delivered') return false;
    $tax = [
        'tax' => round(max(0, $taxCents) / 100, 2),
        'rate' => 0.0,
        'exact' => true,
        'source' => $provider,
        'jurisdiction' => 'Payment provider calculation',
        'detail' => 'Final tax and amount confirmed by ' . ucfirst($provider) . '.',
    ];
    $update = database()->prepare(
        "UPDATE orders SET status = 'paid', paid_at = UTC_TIMESTAMP(), paid_cents = :paid_cents, total = :total, tax_json = :tax " .
        'WHERE reference = :reference AND ' . $providerPredicate . ' AND ' . $providerColumn . ' = :provider_id AND status NOT IN (\'paid\',\'delivered\')'
    );
    $update->execute([
        'paid_cents'=>$paidCents,
        'total'=>round($paidCents / 100, 2),
        'tax'=>json_encode($tax, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        'reference'=>$reference,
        'provider_id'=>$providerId,
    ]);
    return $update->rowCount() > 0;
}

function payment_handle_stripe_webhook(string $rawBody, string $signature): never
{
    $event = verify_stripe_webhook($rawBody, $signature);
    $eventId = (string)($event['id'] ?? '');
    if ($eventId === '') reply(400, ['error'=>'bad_webhook','message'=>'Stripe event has no ID.']);
    if (payment_webhook_seen('stripe', $eventId)) reply(200, ['ok'=>true,'duplicate'=>true]);
    $type = (string)($event['type'] ?? '');
    $object = $event['data']['object'] ?? [];
    if (!is_array($object)) reply(200, ['ok'=>true,'ignored'=>'no checkout object']);
    $reference = (string)($object['metadata']['order_ref'] ?? $object['client_reference_id'] ?? '');
    $sessionId = (string)($object['id'] ?? '');
    if ($reference === '' || $sessionId === '') reply(200, ['ok'=>true,'ignored'=>'no order reference']);
    if (in_array($type, ['checkout.session.completed','checkout.session.async_payment_succeeded'], true)) {
        if (($object['payment_status'] ?? '') !== 'paid') reply(200, ['ok'=>true,'ignored'=>'payment not completed']);
        $totalCents = (int)($object['amount_total'] ?? 0);
        $taxCents = (int)($object['total_details']['amount_tax'] ?? 0);
        payment_settle_order($reference,'stripe',$sessionId,$totalCents,$taxCents);
        $customerId=(string)($object['customer'] ?? '');
        $discordId=(string)($object['metadata']['discord_id'] ?? '');
        if ($customerId!=='' && preg_match('/^[0-9]{17,20}$/',$discordId)) {
            database()->prepare('UPDATE orders SET stripe_customer_id=:customer WHERE reference=:reference AND stripe_session_id=:session')
                ->execute(['customer'=>$customerId,'reference'=>$reference,'session'=>$sessionId]);
            database()->prepare('UPDATE customer_accounts SET stripe_customer_id=:customer WHERE discord_id=:discord')
                ->execute(['customer'=>$customerId,'discord'=>$discordId]);
        }
    } elseif ($type === 'checkout.session.expired') {
        $update = database()->prepare(
            "UPDATE orders SET status = 'cancelled' WHERE reference = :reference AND stripe_session_id = :session " .
            "AND status NOT IN ('paid','delivered')"
        );
        $update->execute(['reference'=>$reference,'session'=>$sessionId]);
    }
    payment_webhook_record('stripe', $eventId);
    reply(200, ['ok'=>true]);
}

function payment_handle_paypal_webhook(string $rawBody): never
{
    $event = verify_paypal_webhook($rawBody);
    $eventId = (string)($event['id'] ?? '');
    if ($eventId === '') reply(400, ['error'=>'bad_webhook','message'=>'PayPal event has no ID.']);
    if (payment_webhook_seen('paypal', $eventId)) reply(200, ['ok'=>true,'duplicate'=>true]);
    if (($event['event_type'] ?? '') !== 'PAYMENT.CAPTURE.COMPLETED') reply(200, ['ok'=>true,'ignored'=>'event does not settle an order']);
    $resource = $event['resource'] ?? [];
    $related = $resource['supplementary_data']['related_ids'] ?? [];
    $paypalId = (string)($related['order_id'] ?? '');
    $query = database()->prepare('SELECT reference FROM orders WHERE paypal_order_id = :paypal_id LIMIT 1');
    $query->execute(['paypal_id'=>$paypalId]);
    $reference = (string)($query->fetchColumn() ?: '');
    if ($reference === '') reply(200, ['ok'=>true,'ignored'=>'unknown order']);
    $paid = (int)round((float)($resource['amount']['value'] ?? 0) * 100);
    payment_settle_order($reference,'paypal',$paypalId,$paid,0);
    payment_webhook_record('paypal', $eventId);
    reply(200, ['ok'=>true]);
}

function payment_capture_paypal(array $body): never
{
    $reference = strtoupper(clean_text($body['ref'] ?? '', 12, 'Order reference'));
    $discordId = clean_text($body['discord_id'] ?? '', 20, 'Discord ID');
    if (!preg_match('/^[0-9]{17,20}$/', $discordId)) reply(422, ['error'=>'bad_discord_id','message'=>'Enter your Discord ID.']);
    $query = database()->prepare('SELECT * FROM orders WHERE reference = :reference AND discord_id = :discord LIMIT 1');
    $query->execute(['reference'=>$reference,'discord'=>$discordId]);
    $order = $query->fetch();
    if (!$order || $order['provider'] !== 'paypal' || empty($order['paypal_order_id'])) {
        reply(404, ['error'=>'not_found','message'=>'No matching PayPal order was found.']);
    }
    if (in_array($order['status'], ['paid','delivered'], true)) {
        reply(200, ['ok'=>true,'ref'=>$reference,'status'=>$order['status'],'message'=>'This order is already paid.']);
    }
    try {
        $capture = paypal_capture_order((string)$order['paypal_order_id']);
    } catch (Throwable $error) {
        reply(502, ['error'=>'capture_pending','message'=>'PayPal has not confirmed capture yet. Check the order again shortly.']);
    }
    if (($capture['status'] ?? '') !== 'COMPLETED') {
        reply(409, ['error'=>'capture_pending','message'=>'PayPal has not completed this capture.']);
    }
    $captureDetails = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];
    $paidCents = (int)round((float)($captureDetails['amount']['value'] ?? $order['total']) * 100);
    payment_settle_order($reference,'paypal',(string)$order['paypal_order_id'],$paidCents,0);
    reply(200, ['ok'=>true,'ref'=>$reference,'status'=>'paid','message'=>'PayPal confirmed your payment. Staff will fulfill the order.']);
}
