CREATE TABLE IF NOT EXISTS economy_balances (
    discord_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    balance BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS player_profiles (
    discord_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    experience BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS suggestions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    suggestion_text TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX suggestions_status_created (status, created_at)
);

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    discord_channel_id BIGINT UNSIGNED NOT NULL,
    ticket_type VARCHAR(64) NOT NULL DEFAULT 'general',
    minimum_role_id BIGINT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    claimed_by BIGINT UNSIGNED NULL,
    first_viewed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX tickets_status_created (status, created_at)
);

CREATE TABLE IF NOT EXISTS discord_users (
    discord_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    avatar_url VARCHAR(512) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roblox_links (
    discord_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    roblox_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    roblox_username VARCHAR(100) NOT NULL,
    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discord_user_id) REFERENCES discord_users(discord_user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS staff_credentials (
    discord_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(254) NOT NULL,
    email_verified_at TIMESTAMP NULL,
    two_factor_secret_ciphertext VARBINARY(512) NULL,
    passkey_credentials JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discord_user_id) REFERENCES discord_users(discord_user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS staff_sessions (
    id BINARY(16) NOT NULL PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    session_token_hash BINARY(32) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    revoked_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discord_user_id) REFERENCES staff_credentials(discord_user_id) ON DELETE CASCADE,
    INDEX sessions_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS staff_clock_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    clocked_in_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clock_in_reason TEXT NOT NULL,
    clocked_out_at TIMESTAMP NULL,
    clock_out_reason TEXT NULL,
    INDEX clock_staff_active (discord_user_id, clocked_out_at)
);

CREATE TABLE IF NOT EXISTS staff_terms_acceptances (
    discord_user_id BIGINT UNSIGNED NOT NULL,
    terms_version VARCHAR(64) NOT NULL,
    accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (discord_user_id, terms_version)
);

CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_discord_user_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    INDEX ticket_messages_order (ticket_id, created_at)
);

CREATE TABLE IF NOT EXISTS applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    questions JSON NOT NULL,
    minimum_reviewer_role_id BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS application_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    responses JSON NOT NULL,
    applicant_role_ids JSON NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewer_role_ids JSON NULL,
    decision_note TEXT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    FOREIGN KEY (application_id) REFERENCES applications(id),
    INDEX application_review_queue (status, submitted_at)
);

CREATE TABLE IF NOT EXISTS break_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    starts_at TIMESTAMP NULL,
    ends_at TIMESTAMP NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX break_request_queue (status, created_at)
);

CREATE TABLE IF NOT EXISTS store_products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    price_minor_units BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    discord_role_id BIGINT UNSIGNED NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS store_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    provider_payment_id VARCHAR(190) NULL UNIQUE,
    amount_minor_units BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    fulfilled_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES store_products(id),
    INDEX order_user_created (discord_user_id, created_at)
);

CREATE TABLE IF NOT EXISTS saved_payment_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    provider_method_id VARCHAR(190) NOT NULL,
    display_brand VARCHAR(32) NULL,
    display_last_four CHAR(4) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY saved_method_provider_id (provider, provider_method_id)
);

CREATE TABLE IF NOT EXISTS invite_attributions (
    guild_id BIGINT UNSIGNED NOT NULL,
    invited_discord_user_id BIGINT UNSIGNED NOT NULL,
    inviter_discord_user_id BIGINT UNSIGNED NULL,
    invite_code VARCHAR(32) NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (guild_id, invited_discord_user_id),
    INDEX invite_leaderboard (guild_id, inviter_discord_user_id)
);

CREATE TABLE IF NOT EXISTS oauth_states (
    state_hash BINARY(32) NOT NULL PRIMARY KEY,
    provider VARCHAR(24) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX oauth_state_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS email_security_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    code_hash BINARY(32) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX email_code_expiry (discord_user_id, purpose, expires_at)
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    token_hash BINARY(32) NOT NULL PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    consumed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX reset_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS contact_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    discord_username VARCHAR(100) NOT NULL,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    message_body TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    reviewed_by BIGINT UNSIGNED NULL,
    staff_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    INDEX contact_review_queue (status, created_at)
);

CREATE TABLE IF NOT EXISTS team_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    rank_name VARCHAR(120) NOT NULL,
    rank_role_id BIGINT UNSIGNED NULL,
    category VARCHAR(80) NOT NULL,
    avatar_path VARCHAR(512) NULL,
    display_order INT NOT NULL DEFAULT 0,
    visible BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX team_category_order (category, display_order)
);

CREATE TABLE IF NOT EXISTS development_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'open',
    assigned_to BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    due_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX development_task_queue (status, due_at)
);

CREATE TABLE IF NOT EXISTS developer_payment_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount_minor_units BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    created_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    INDEX developer_payment_queue (status, created_at)
);

CREATE TABLE IF NOT EXISTS staff_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    room_key VARCHAR(100) NOT NULL,
    discord_user_id BIGINT UNSIGNED NOT NULL,
    message_body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX staff_chat_history (room_key, created_at)
);

CREATE TABLE IF NOT EXISTS managed_voice_rooms (
    discord_channel_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    owner_discord_user_id BIGINT UNSIGNED NOT NULL,
    configuration JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL
);
