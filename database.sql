-- ══════════════════════════════════════════════════════════════════════════════
-- ANORA Platform — Clean Database Schema
-- Run this on a fresh MySQL 8 instance. Creates everything from scratch.
-- ══════════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS ivash536_anora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ivash536_anora;

-- ── Users ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    email                   VARCHAR(255) NOT NULL UNIQUE,
    password                VARCHAR(255) NOT NULL,
    balance                 DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bank_details            TEXT DEFAULT NULL,
    verify_token            VARCHAR(64) DEFAULT NULL,
    is_verified             TINYINT(1) NOT NULL DEFAULT 0,
    is_bot                  TINYINT(1) NOT NULL DEFAULT 0,
    is_banned               TINYINT(1) NOT NULL DEFAULT 0,
    fraud_flagged           TINYINT(1) NOT NULL DEFAULT 0,
    nickname                VARCHAR(64) UNIQUE DEFAULT NULL,
    nickname_changed_at     DATETIME DEFAULT NULL,
    ref_code                VARCHAR(32) NOT NULL DEFAULT '',
    referred_by             INT DEFAULT NULL,
    referral_earnings       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    referral_locked         TINYINT(1) NOT NULL DEFAULT 0,
    referral_snapshot       JSON DEFAULT NULL,
    registration_ip         VARCHAR(45) DEFAULT NULL,
    default_crypto_currency VARCHAR(10) DEFAULT NULL,
    default_wallet_address  VARCHAR(255) DEFAULT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ref_code (ref_code),
    INDEX idx_referred_by (referred_by),
    INDEX idx_users_nickname (nickname)
);

-- Self-referencing FK for referrals
ALTER TABLE users ADD CONSTRAINT fk_referred_by
    FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL;

-- ── Fiat Transactions ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS transactions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    type        ENUM('deposit','withdrawal') NOT NULL,
    amount      DECIMAL(15,2) NOT NULL,
    status      ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    note        TEXT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS withdrawal_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    transaction_id  INT NOT NULL,
    amount          DECIMAL(15,2) NOT NULL,
    bank_details    TEXT NOT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- ── Legacy Lottery (retained for historical data) ────────────────────────────
CREATE TABLE IF NOT EXISTS lottery_games (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    status              ENUM('waiting','countdown','finished') NOT NULL DEFAULT 'waiting',
    started_at          DATETIME DEFAULT NULL,
    finished_at         DATETIME DEFAULT NULL,
    winner_id           INT DEFAULT NULL,
    total_pot           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    server_seed         VARCHAR(64) DEFAULT NULL,
    server_seed_hash    VARCHAR(64) DEFAULT NULL,
    final_bets_snapshot JSON DEFAULT NULL,
    final_combined_hash VARCHAR(64) DEFAULT NULL,
    final_rand_unit     DECIMAL(20,12) DEFAULT NULL,
    final_target        DECIMAL(20,12) DEFAULT NULL,
    final_total_weight  DECIMAL(15,2) DEFAULT NULL,
    room                TINYINT NOT NULL DEFAULT 1,
    payout_status       ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    payout_id           VARCHAR(36) DEFAULT NULL,
    commission          DECIMAL(12,2) DEFAULT NULL,
    referral_bonus      DECIMAL(12,2) DEFAULT NULL,
    winner_net          DECIMAL(12,2) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_room_status (room, status)
);

CREATE TABLE IF NOT EXISTS lottery_bets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    game_id     INT NOT NULL,
    user_id     INT NOT NULL,
    amount      DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    client_seed VARCHAR(64) DEFAULT NULL,
    room        TINYINT NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES lottery_games(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_lottery_bets_game_id (game_id)
);

-- ── Ledger (source of truth for all balance mutations) ───────────────────────
CREATE TABLE IF NOT EXISTS user_balances (
    user_id INT NOT NULL PRIMARY KEY,
    balance DECIMAL(20,8) NOT NULL DEFAULT 0.00000000
);

CREATE TABLE IF NOT EXISTS ledger_entries (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    type            VARCHAR(32) NOT NULL,
    amount          DECIMAL(20,8) NOT NULL,
    direction       ENUM('credit','debit') NOT NULL,
    balance_after   DECIMAL(20,8) NOT NULL,
    reference_id    VARCHAR(64) DEFAULT NULL,
    reference_type  VARCHAR(32) DEFAULT NULL,
    metadata        JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_type (type),
    UNIQUE KEY uniq_reference (reference_type, reference_id, user_id, type)
);

-- ── Game State Machine (new engine) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS game_rounds (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    room                TINYINT NOT NULL,
    status              ENUM('waiting','active','spinning','finished') NOT NULL DEFAULT 'waiting',
    server_seed         VARCHAR(64) DEFAULT NULL,
    server_seed_hash    VARCHAR(64) DEFAULT NULL,
    total_pot           DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    winner_id           INT DEFAULT NULL,
    started_at          DATETIME DEFAULT NULL,
    spinning_at         DATETIME DEFAULT NULL,
    finished_at         DATETIME DEFAULT NULL,
    payout_status       ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    payout_id           VARCHAR(36) DEFAULT NULL,
    commission          DECIMAL(12,2) DEFAULT NULL,
    referral_bonus      DECIMAL(12,2) DEFAULT NULL,
    winner_net          DECIMAL(12,2) DEFAULT NULL,
    final_bets_snapshot JSON DEFAULT NULL,
    final_combined_hash VARCHAR(64) DEFAULT NULL,
    final_rand_unit     DECIMAL(20,12) DEFAULT NULL,
    final_target        DECIMAL(20,12) DEFAULT NULL,
    final_total_weight  DECIMAL(15,2) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_room_status (room, status),
    INDEX idx_status (status),
    UNIQUE KEY uniq_payout (payout_id)
);

CREATE TABLE IF NOT EXISTS game_bets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    round_id        INT NOT NULL,
    user_id         INT NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    client_seed     VARCHAR(64) DEFAULT NULL,
    ledger_entry_id INT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (round_id) REFERENCES game_rounds(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_round_id (round_id),
    INDEX idx_user_round (user_id, round_id)
);

-- ── System Balance (legacy singleton) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_balance (
    id      INT NOT NULL DEFAULT 1 PRIMARY KEY,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00
);
INSERT IGNORE INTO system_balance (id, balance) VALUES (1, 0.00);

-- ── System Transactions (legacy audit) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    game_id         INT DEFAULT NULL,
    payout_id       VARCHAR(36) DEFAULT NULL,
    amount          DECIMAL(12,2) NOT NULL,
    type            VARCHAR(32) NOT NULL,
    source_user_id  INT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_payout_type (game_id, payout_id, type),
    INDEX idx_created_at (created_at)
);

-- ── User Transactions (backward-compatible audit) ────────────────────────────
CREATE TABLE IF NOT EXISTS user_transactions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    payout_id   VARCHAR(36) DEFAULT NULL,
    type        VARCHAR(32) NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    game_id     INT DEFAULT NULL,
    note        VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id),
    INDEX idx_user_created (user_id, created_at)
);

-- ── Registration Rate Limiting ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS registration_attempts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ip          VARCHAR(45) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_created (ip, created_at)
);

-- ── Crypto Payments ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crypto_invoices (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT NOT NULL,
    nowpayments_invoice_id  VARCHAR(64) DEFAULT NULL,
    amount_usd              DECIMAL(15,2) NOT NULL,
    credited_usd            DECIMAL(15,2) DEFAULT NULL,
    amount_crypto           VARCHAR(64) DEFAULT NULL,
    currency                VARCHAR(10) DEFAULT NULL,
    status                  ENUM('pending','waiting','confirming','confirmed','partially_paid','expired','failed')
                            NOT NULL DEFAULT 'pending',
    invoice_url             TEXT DEFAULT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_ci_user_id (user_id),
    INDEX idx_ci_nowpayments_id (nowpayments_invoice_id),
    INDEX idx_ci_status (status),
    INDEX idx_ci_user_created (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS crypto_payouts (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT NOT NULL,
    nowpayments_payout_id   VARCHAR(64) DEFAULT NULL,
    amount_usd              DECIMAL(15,2) NOT NULL,
    wallet_address          VARCHAR(255) NOT NULL,
    currency                VARCHAR(10) NOT NULL,
    status                  ENUM('pending','awaiting_approval','processing','completed','failed','rejected')
                            NOT NULL DEFAULT 'pending',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_cp_user_id (user_id),
    INDEX idx_cp_nowpayments_id (nowpayments_payout_id),
    INDEX idx_cp_status (status),
    INDEX idx_cp_user_date (user_id, created_at)
);

-- ── Device Fingerprints (append-only, anti-fraud) ────────────────────────────
CREATE TABLE IF NOT EXISTS device_fingerprints (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    session_id  VARCHAR(128) NOT NULL,
    ip_address  VARCHAR(45) NOT NULL,
    user_agent  TEXT NOT NULL,
    canvas_hash VARCHAR(64) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_canvas_hash (canvas_hash),
    INDEX idx_created_at (created_at)
);

-- ══════════════════════════════════════════════════════════════════════════════
-- SEED DATA
-- ══════════════════════════════════════════════════════════════════════════════

-- ── System Account (user_id = 1, first row) ─────────────────────────────────
-- Must be inserted FIRST so it gets AUTO_INCREMENT id=1.
INSERT IGNORE INTO users (id, email, password, balance, is_verified, is_bot, nickname, ref_code)
VALUES (1, 'system@anora.internal', '$2y$10$unusable_hash_system', 0.00, 1, 1, 'SYSTEM', 'SYSTEM000000');

INSERT IGNORE INTO user_balances (user_id, balance) VALUES (1, 0.00000000);

-- ── Admin Account ────────────────────────────────────────────────────────────
-- Password: admin (bcrypt hash)
INSERT IGNORE INTO users (email, password, balance, is_verified, is_bot, nickname, ref_code)
VALUES ('admin@anora.bet', '$2y$10$8KzQz3Z5G5Z5G5Z5G5Z5GOxYqYqYqYqYqYqYqYqYqYqYqYqYqYqYq', 0.00, 1, 0, 'Admin', 'ADMIN0000000');

-- ── Bot Users (10 bots, $100 each) ──────────────────────────────────────────
INSERT IGNORE INTO users (email, password, balance, is_verified, is_bot, nickname, ref_code) VALUES
    ('alex@bot.internal',    '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Bold Eagle',    'BOT000000001'),
    ('chris@bot.internal',   '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Neon Wolf',     'BOT000000002'),
    ('jordan@bot.internal',  '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Lucky Storm',   'BOT000000003'),
    ('taylor@bot.internal',  '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Fast Raven',    'BOT000000004'),
    ('sam@bot.internal',     '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Iron Blade',    'BOT000000005'),
    ('riley@bot.internal',   '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Cyber Nova',    'BOT000000006'),
    ('morgan@bot.internal',  '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Dark Prism',    'BOT000000007'),
    ('casey@bot.internal',   '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Epic Comet',    'BOT000000008'),
    ('quinn@bot.internal',   '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Sly Phoenix',   'BOT000000009'),
    ('avery@bot.internal',   '$2y$10$unusable_hash_bots_cannot_login', 100.00, 1, 1, 'Turbo Nexus',   'BOT000000010');

-- ── Bot user_balances rows (ledger source of truth) ──────────────────────────
-- These must match users.balance for the ledger system to work correctly.
-- Exclude System Account (id=1).
INSERT IGNORE INTO user_balances (user_id, balance)
SELECT id, 100.00000000 FROM users WHERE is_bot = 1 AND id > 1;

-- ── Initial ledger entries for bot balances (audit trail) ────────────────────
INSERT IGNORE INTO ledger_entries (user_id, type, amount, direction, balance_after, reference_id, reference_type, metadata)
SELECT id, 'deposit', 100.00000000, 'credit', 100.00000000, 'initial_seed', 'bot_topup',
       JSON_OBJECT('source', 'database_seed', 'is_bot', true)
FROM users WHERE is_bot = 1 AND id > 1;
