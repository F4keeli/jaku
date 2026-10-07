CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE IF NOT EXISTS site_documents (
    document_key VARCHAR(64) NOT NULL PRIMARY KEY,
    payload_json TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC')
);

CREATE TABLE IF NOT EXISTS bot_heartbeats (
    bot_id VARCHAR(24) NOT NULL PRIMARY KEY,
    received_at TIMESTAMP NOT NULL,
    payload_json TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS bot_heartbeats_received_at ON bot_heartbeats (received_at);

CREATE TABLE IF NOT EXISTS bot_store (
    store_key VARCHAR(32) NOT NULL PRIMARY KEY,
    payload_json TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC')
);

CREATE TABLE IF NOT EXISTS bot_records (
    record_kind VARCHAR(24) NOT NULL,
    record_id VARCHAR(64) NOT NULL,
    payload_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL,
    PRIMARY KEY (record_kind, record_id)
);

CREATE INDEX IF NOT EXISTS bot_records_created_at ON bot_records (record_kind, created_at);

CREATE TABLE IF NOT EXISTS site_metrics (
    metric_key VARCHAR(64) NOT NULL PRIMARY KEY,
    metric_value BIGINT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS human_checks (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    answer_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS human_checks_expires_at ON human_checks (expires_at);

CREATE TABLE IF NOT EXISTS api_rate_limits (
    bucket VARCHAR(32) NOT NULL,
    identity_hash VARCHAR(64) NOT NULL,
    window_started TIMESTAMP NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, identity_hash)
);

CREATE INDEX IF NOT EXISTS api_rate_limits_window_started ON api_rate_limits (window_started);

CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference VARCHAR(9) NOT NULL UNIQUE,
    received_at TIMESTAMP NOT NULL,
    topic VARCHAR(32) NOT NULL,
    topic_label VARCHAR(120) NOT NULL,
    discord_id VARCHAR(20) NOT NULL,
    discord_username VARCHAR(32) NOT NULL,
    name VARCHAR(80) NOT NULL,
    email CITEXT NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    notified SMALLINT NOT NULL DEFAULT 0,
    customer_emailed SMALLINT NOT NULL DEFAULT 0,
    staff_reply TEXT NULL,
    decided_at TIMESTAMP NULL,
    emailed SMALLINT NOT NULL DEFAULT 0,
    case_reference VARCHAR(64) NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS contact_messages_received_at ON contact_messages (received_at);

CREATE INDEX IF NOT EXISTS contact_messages_status ON contact_messages (status);

CREATE TABLE IF NOT EXISTS applications (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference VARCHAR(9) NOT NULL UNIQUE,
    received_at TIMESTAMP NOT NULL,
    role_id VARCHAR(60) NOT NULL,
    role_title VARCHAR(120) NOT NULL,
    name VARCHAR(80) NOT NULL,
    email CITEXT NOT NULL,
    answers_json TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    notified SMALLINT NOT NULL DEFAULT 0,
    applicant_notified SMALLINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS applications_role_id ON applications (role_id);

CREATE INDEX IF NOT EXISTS applications_received_at ON applications (received_at);

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference VARCHAR(9) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending_manual',
    provider VARCHAR(24) NOT NULL,
    discord_id VARCHAR(20) NOT NULL,
    discord_name VARCHAR(32) NOT NULL,
    email CITEXT NOT NULL,
    verification_status VARCHAR(24) NOT NULL DEFAULT 'pending_staff',
    subtotal DECIMAL(12,2) NOT NULL,
    discount_json TEXT NULL,
    tax_json TEXT NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    lines_json TEXT NOT NULL,
    taxable DECIMAL(12,2) NOT NULL DEFAULT 0,
    stripe_session_id VARCHAR(255) NULL,
    stripe_customer_id VARCHAR(255) NULL,
    paypal_order_id VARCHAR(64) NULL,
    paid_at TIMESTAMP NULL,
    paid_cents BIGINT NULL,
    payment_error TEXT NULL,
    fulfillment_error TEXT NULL,
    fulfilled_at TIMESTAMP NULL,
    staff_note TEXT NULL,
    notified SMALLINT NOT NULL DEFAULT 0,
    buyer_notified SMALLINT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS orders_created_at ON orders (created_at);

CREATE INDEX IF NOT EXISTS orders_status ON orders (status);

CREATE INDEX IF NOT EXISTS orders_discord ON orders (discord_id);

CREATE TABLE IF NOT EXISTS staff_users (
    id VARCHAR(32) NOT NULL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    username_key CITEXT NOT NULL UNIQUE,
    email CITEXT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    tier VARCHAR(24) NOT NULL DEFAULT 'Staff',
    title VARCHAR(80) NOT NULL DEFAULT 'Staff',
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    discord_id VARCHAR(20) NULL,
    active SMALLINT NOT NULL DEFAULT 1,
    email_verified SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    last_login TIMESTAMP NULL,
    CONSTRAINT staff_users_email UNIQUE (email)
);

CREATE TABLE IF NOT EXISTS staff_login_attempts (
    identity_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    window_started TIMESTAMP NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS staff_login_attempts_window ON staff_login_attempts (window_started);

CREATE TABLE IF NOT EXISTS staff_pending_logins (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    stage VARCHAR(16) NOT NULL,
    email CITEXT NOT NULL,
    code_hash VARCHAR(64) NULL,
    code_expires_at TIMESTAMP NULL,
    resend_after TIMESTAMP NULL,
    failures SMALLINTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    CONSTRAINT staff_pending_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS staff_pending_expiry ON staff_pending_logins (expires_at);

CREATE TABLE IF NOT EXISTS staff_sessions (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    csrf_token VARCHAR(64) NOT NULL,
    remember SMALLINT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
    CONSTRAINT staff_session_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS staff_sessions_expiry ON staff_sessions (expires_at);

CREATE TABLE IF NOT EXISTS payment_webhook_events (
    provider VARCHAR(16) NOT NULL,
    event_id VARCHAR(128) NOT NULL,
    received_at TIMESTAMP NOT NULL,
    PRIMARY KEY (provider, event_id)
);

CREATE TABLE IF NOT EXISTS staff_passkey_challenges (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NULL,
    ceremony VARCHAR(16) NOT NULL,
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS staff_passkey_challenge_expiry ON staff_passkey_challenges (expires_at);

CREATE TABLE IF NOT EXISTS staff_passkeys (
    credential_id VARCHAR(255) NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    public_x TEXT NOT NULL,
    public_y TEXT NOT NULL,
    sign_count INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
    last_used_at TIMESTAMP NULL,
    CONSTRAINT staff_passkeys_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS staff_passkeys_staff ON staff_passkeys (staff_id);

CREATE TABLE IF NOT EXISTS staff_password_resets (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    staff_id VARCHAR(32) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
    CONSTRAINT staff_password_reset_user FOREIGN KEY (staff_id) REFERENCES staff_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS staff_password_reset_expiry ON staff_password_resets (expires_at);

CREATE TABLE IF NOT EXISTS customer_accounts (
    discord_id VARCHAR(20) NOT NULL PRIMARY KEY,
    discord_username VARCHAR(80) NOT NULL,
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    stripe_customer_id VARCHAR(255) NULL,
    email CITEXT NOT NULL,
    phone_number VARCHAR(24) NOT NULL,
    email_verified SMALLINT NOT NULL DEFAULT 0,
    phone_verified SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL,
    last_login TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS customer_roblox_links (
    discord_id VARCHAR(20) NOT NULL PRIMARY KEY,
    roblox_user_id VARCHAR(20) NOT NULL UNIQUE,
    roblox_username VARCHAR(100) NOT NULL,
    verified_at TIMESTAMP NOT NULL,
    CONSTRAINT customer_roblox_link_user FOREIGN KEY (discord_id) REFERENCES customer_accounts(discord_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS customer_pending_logins (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    discord_id VARCHAR(20) NOT NULL,
    discord_username VARCHAR(80) NOT NULL,
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    stage VARCHAR(16) NOT NULL,
    email CITEXT NOT NULL DEFAULT '',
    phone_number VARCHAR(24) NOT NULL DEFAULT '',
    email_code_hash VARCHAR(64) NULL,
    phone_code_hash VARCHAR(64) NULL,
    failures SMALLINTEGER NOT NULL DEFAULT 0,
    resend_after TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS customer_pending_expiry ON customer_pending_logins (expires_at);

CREATE TABLE IF NOT EXISTS customer_sessions (
    token_hash VARCHAR(64) NOT NULL PRIMARY KEY,
    discord_id VARCHAR(20) NOT NULL,
    csrf_token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL,
    CONSTRAINT customer_session_user FOREIGN KEY (discord_id) REFERENCES customer_accounts(discord_id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS customer_sessions_expiry ON customer_sessions (expires_at);

-- Supabase exposes public tables through its REST API; RLS with no policies blocks that.
ALTER TABLE site_documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE bot_heartbeats ENABLE ROW LEVEL SECURITY;
ALTER TABLE bot_store ENABLE ROW LEVEL SECURITY;
ALTER TABLE bot_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE site_metrics ENABLE ROW LEVEL SECURITY;
ALTER TABLE human_checks ENABLE ROW LEVEL SECURITY;
ALTER TABLE api_rate_limits ENABLE ROW LEVEL SECURITY;
ALTER TABLE contact_messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE applications ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_users ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_login_attempts ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_pending_logins ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_webhook_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_passkey_challenges ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_passkeys ENABLE ROW LEVEL SECURITY;
ALTER TABLE staff_password_resets ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_roblox_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_pending_logins ENABLE ROW LEVEL SECURITY;
ALTER TABLE customer_sessions ENABLE ROW LEVEL SECURITY;

