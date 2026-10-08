CREATE TABLE IF NOT EXISTS site_documents (
    document_key VARCHAR(64) NOT NULL PRIMARY KEY,
    payload_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT site_documents_payload_json CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_heartbeats (
    bot_id VARCHAR(24) NOT NULL PRIMARY KEY,
    received_at DATETIME NOT NULL,
    payload_json LONGTEXT NOT NULL,
    CONSTRAINT bot_heartbeats_payload_json CHECK (JSON_VALID(payload_json)),
    INDEX bot_heartbeats_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_store (
    store_key VARCHAR(32) NOT NULL PRIMARY KEY,
    payload_json LONGTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT bot_store_payload_json CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_records (
    record_kind VARCHAR(24) NOT NULL,
    record_id VARCHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (record_kind, record_id),
    INDEX bot_records_created_at (record_kind, created_at),
    CONSTRAINT bot_records_payload_json CHECK (JSON_VALID(payload_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_metrics (
    metric_key VARCHAR(64) NOT NULL PRIMARY KEY,
    metric_value BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS human_checks (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    answer_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX human_checks_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_rate_limits (
    bucket VARCHAR(32) NOT NULL,
    identity_hash CHAR(64) NOT NULL,
    window_started DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, identity_hash),
    INDEX api_rate_limits_window_started (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference CHAR(9) NOT NULL UNIQUE,
    received_at DATETIME NOT NULL,
    topic VARCHAR(32) NOT NULL,
    topic_label VARCHAR(120) NOT NULL,
    discord_id VARCHAR(20) NOT NULL,
    discord_username VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    notified TINYINT(1) NOT NULL DEFAULT 0,
    customer_emailed TINYINT(1) NOT NULL DEFAULT 0,
    staff_reply TEXT NULL,
    decided_at DATETIME NULL,
    emailed TINYINT(1) NOT NULL DEFAULT 0,
    case_reference VARCHAR(64) NOT NULL DEFAULT '',
    INDEX contact_messages_received_at (received_at),
    INDEX contact_messages_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference CHAR(9) NOT NULL UNIQUE,
    received_at DATETIME NOT NULL,
    role_id VARCHAR(60) NOT NULL,
    role_title VARCHAR(120) NOT NULL,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL,
    answers_json LONGTEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    notified TINYINT(1) NOT NULL DEFAULT 0,
    applicant_notified TINYINT(1) NOT NULL DEFAULT 0,
    INDEX applications_role_id (role_id),
    INDEX applications_received_at (received_at),
    CONSTRAINT applications_answers_json CHECK (JSON_VALID(answers_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference CHAR(9) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending_manual',
    provider VARCHAR(24) NOT NULL,
    discord_id VARCHAR(20) NOT NULL,
    discord_name VARCHAR(32) NOT NULL,
    email VARCHAR(254) NOT NULL,
    verification_status VARCHAR(24) NOT NULL DEFAULT 'pending_staff',
    subtotal DECIMAL(12,2) NOT NULL,
    discount_json LONGTEXT NULL,
    tax_json LONGTEXT NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    lines_json LONGTEXT NOT NULL,
    taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
    stripe_session_id VARCHAR(255) NULL,
    stripe_customer_id VARCHAR(255) NULL,
    paypal_order_id VARCHAR(64) NULL,
    paid_at DATETIME NULL,
    paid_cents BIGINT UNSIGNED NULL,
    payment_error TEXT NULL,
    fulfillment_error TEXT NULL,
    fulfilled_at DATETIME NULL,
    staff_note TEXT NULL,
    notified TINYINT(1) NOT NULL DEFAULT 0,
    buyer_notified TINYINT(1) NOT NULL DEFAULT 0,
    INDEX orders_created_at (created_at),
    INDEX orders_status (status),
    INDEX orders_discord (discord_id),
    CONSTRAINT orders_discount_json CHECK (discount_json IS NULL OR JSON_VALID(discount_json)),
    CONSTRAINT orders_tax_json CHECK (JSON_VALID(tax_json)),
    CONSTRAINT orders_lines_json CHECK (JSON_VALID(lines_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_users (
    id CHAR(32) NOT NULL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    username_key VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    tier VARCHAR(24) NOT NULL DEFAULT 'Staff',
    title VARCHAR(80) NOT NULL DEFAULT 'Staff',
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    discord_id VARCHAR(20) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    last_login DATETIME NULL,
    UNIQUE KEY staff_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_login_attempts (
    identity_hash CHAR(64) NOT NULL PRIMARY KEY,
    window_started DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX staff_login_attempts_window (window_started)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_pending_logins (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    staff_id CHAR(32) NOT NULL,
    stage VARCHAR(16) NOT NULL,
    email VARCHAR(254) NOT NULL,
    code_hash CHAR(64) NULL,
    code_expires_at DATETIME NULL,
    resend_after DATETIME NULL,
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    INDEX staff_pending_expiry (expires_at),
    CONSTRAINT staff_pending_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_sessions (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    staff_id CHAR(32) NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    remember TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX staff_sessions_expiry (expires_at),
    CONSTRAINT staff_session_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    provider VARCHAR(16) NOT NULL,
    event_id VARCHAR(128) NOT NULL,
    received_at DATETIME NOT NULL,
    PRIMARY KEY (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_passkey_challenges (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    staff_id CHAR(32) NULL,
    ceremony VARCHAR(16) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX staff_passkey_challenge_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_passkeys (
    credential_id VARCHAR(255) NOT NULL PRIMARY KEY,
    staff_id CHAR(32) NOT NULL,
    public_x VARBINARY(32) NOT NULL,
    public_y VARBINARY(32) NOT NULL,
    sign_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX staff_passkeys_staff (staff_id),
    CONSTRAINT staff_passkeys_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_password_resets (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    staff_id CHAR(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX staff_password_reset_expiry (expires_at),
    CONSTRAINT staff_password_reset_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_accounts (
    discord_id VARCHAR(20) NOT NULL PRIMARY KEY,
    discord_username VARCHAR(80) NOT NULL,
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    stripe_customer_id VARCHAR(255) NULL,
    email VARCHAR(254) NOT NULL,
    phone_number VARCHAR(24) NOT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    phone_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    last_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_roblox_links (
    discord_id VARCHAR(20) NOT NULL PRIMARY KEY,
    roblox_user_id VARCHAR(20) NOT NULL UNIQUE,
    roblox_username VARCHAR(100) NOT NULL,
    verified_at DATETIME NOT NULL,
    CONSTRAINT customer_roblox_link_user FOREIGN KEY (discord_id) REFERENCES customer_accounts(discord_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_pending_logins (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    discord_id VARCHAR(20) NOT NULL,
    discord_username VARCHAR(80) NOT NULL,
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    stage VARCHAR(16) NOT NULL,
    email VARCHAR(254) NOT NULL DEFAULT '',
    phone_number VARCHAR(24) NOT NULL DEFAULT '',
    email_code_hash CHAR(64) NULL,
    phone_code_hash CHAR(64) NULL,
    failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    resend_after DATETIME NULL,
    expires_at DATETIME NOT NULL,
    INDEX customer_pending_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_sessions (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    discord_id VARCHAR(20) NOT NULL,
    csrf_token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX customer_sessions_expiry (expires_at),
    CONSTRAINT customer_session_user FOREIGN KEY (discord_id) REFERENCES customer_accounts(discord_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_role_checks (
    discord_id VARCHAR(20) NOT NULL PRIMARY KEY,
    discord_username VARCHAR(100) NOT NULL DEFAULT '',
    requested_at DATETIME NOT NULL,
    checked_at DATETIME NULL,
    roles_json JSON NULL,
    matched_role VARCHAR(100) NULL,
    staff_tier VARCHAR(24) NULL,
    INDEX discord_role_checks_pending (checked_at, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_clock_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    clocked_in_at DATETIME NOT NULL,
    clock_in_reason VARCHAR(500) NOT NULL,
    clocked_out_at DATETIME NULL,
    clock_out_reason VARCHAR(500) NULL,
    hourly_rate DECIMAL(10,2) UNSIGNED NULL,
    task_title VARCHAR(180) NULL,
    task_approved TINYINT(1) NOT NULL DEFAULT 0,
    task_deadline DATETIME NULL,
    payment_minor_units BIGINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX clock_staff_open (staff_id, clocked_out_at),
    INDEX clock_history (staff_id, clocked_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_break_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    reviewed_by VARCHAR(32) NULL,
    review_note VARCHAR(1000) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX break_queue (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_chat_rooms (
    room_key VARCHAR(64) NOT NULL PRIMARY KEY,
    staff_a VARCHAR(32) NOT NULL,
    staff_b VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    last_at DATETIME NOT NULL,
    INDEX chat_room_a (staff_a, last_at),
    INDEX chat_room_b (staff_b, last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_key VARCHAR(64) NOT NULL,
    staff_id VARCHAR(32) NOT NULL,
    body TEXT NOT NULL,
    at DATETIME NOT NULL,
    read_by TEXT NULL,
    INDEX chat_history (room_key, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_voice_rooms (
    room_key VARCHAR(64) NOT NULL PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    created_by VARCHAR(32) NOT NULL,
    configuration TEXT NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id VARCHAR(20) NOT NULL,
    kind VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    INDEX notification_queue (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;