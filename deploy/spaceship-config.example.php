<?php
declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
        'username' => 'YOUR_DATABASE_USER',
        'password' => 'REPLACE_WITH_DATABASE_PASSWORD',
    ],
    'bot_secrets' => [
        'community' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
    ],
    'app_secret' => 'REPLACE_WITH_ANOTHER_LONG_RANDOM_SECRET',
    'discord_oauth' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://stitchlineinteractivegames.com/api/account/callback',
    ],
    'roblox_oauth' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://stitchlineinteractivegames.com/api/account/roblox/callback',
    ],
    'mail' => [
        'from' => 'Stitchline Interactive <no-reply@stitchlineinteractivegames.com>',
    ],
    'payments' => [
        'public_base_url' => 'https://stitchlineinteractivegames.com',
        'stripe_secret' => '',
        'stripe_webhook_secrets' => [],
        'stripe_webhook_secret' => '',
        'stripe_tax_enabled' => false,
        'stripe_tax_code' => '',
    ],
];