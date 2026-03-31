CREATE DATABASE IF NOT EXISTS ivash536_anora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ivash536_anora;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    bank_details TEXT DEFAULT NULL,
    verify_token VARCHAR(64) DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('deposit','withdrawal') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE withdrawal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    bank_details TEXT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- ── Lottery ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lottery_games (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    status      ENUM('waiting','countdown','finished') NOT NULL DEFAULT 'waiting',
    started_at  DATETIME DEFAULT NULL,   -- when countdown began
    finished_at DATETIME DEFAULT NULL,
    winner_id   INT DEFAULT NULL,
    total_pot   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (winner_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS lottery_bets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    game_id    INT NOT NULL,
    user_id    INT NOT NULL,
    amount     DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES lottery_games(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_bet (game_id, user_id)   -- one bet per user per game
);


ALTER TABLE lottery_bets ADD UNIQUE KEY unique_user_game (game_id, user_id);

-- ── Multi-bet migration ───────────────────────────────────────────────────────
-- UPDATED: remove single-bet restriction
ALTER TABLE lottery_bets DROP INDEX IF EXISTS unique_bet;

-- UPDATED: performance index on game_id
CREATE INDEX IF NOT EXISTS idx_lottery_bets_game_id ON lottery_bets(game_id);

-- ── Provably fair: add server_seed columns ───────────────────────────────────
ALTER TABLE lottery_games
    ADD COLUMN IF NOT EXISTS server_seed      VARCHAR(64)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS server_seed_hash VARCHAR(64)  DEFAULT NULL;

-- ── Production improvements ───────────────────────────────────────────────────
-- Store client_seed per bet for provably fair combined entropy
ALTER TABLE lottery_bets
    ADD COLUMN IF NOT EXISTS client_seed VARCHAR(64) DEFAULT NULL;

-- ── Final stabilization: immutable result snapshot ───────────────────────────
ALTER TABLE lottery_games
    ADD COLUMN IF NOT EXISTS final_bets_snapshot  JSON         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS final_combined_hash  VARCHAR(64)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS final_rand_unit      DECIMAL(20,12) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS final_target         DECIMAL(20,12) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS final_total_weight   DECIMAL(15,2)  DEFAULT NULL;

-- ── Bot liquidity system ──────────────────────────────────────────────────────
-- BOT: add is_bot flag to users table
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_bot TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS display_name VARCHAR(64) DEFAULT NULL;

-- BOT: bot users (email must be unique — use bot.internal domain)
-- Balance is topped up automatically by bot_runner.php
INSERT IGNORE INTO users (email, password, balance, is_verified, is_bot, display_name) VALUES
    ('alex@bot.internal',   '$2y$10$unusable_hash_bots_cannot_login', 10000.00, 1, 1, 'Alex'),
    ('chris@bot.internal',  '$2y$10$unusable_hash_bots_cannot_login', 10000.00, 1, 1, 'Chris'),
    ('jordan@bot.internal', '$2y$10$unusable_hash_bots_cannot_login', 10000.00, 1, 1, 'Jordan'),
    ('taylor@bot.internal', '$2y$10$unusable_hash_bots_cannot_login', 10000.00, 1, 1, 'Taylor'),
    ('sam@bot.internal',    '$2y$10$unusable_hash_bots_cannot_login', 10000.00, 1, 1, 'Sam');

-- ── Referral & audit columns on users (task 1.1) ─────────────────────────────
-- Add ref_code WITHOUT unique constraint first, so existing rows get empty string
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS ref_code          VARCHAR(32)   NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS referred_by       INT           NULL,
    ADD COLUMN IF NOT EXISTS referral_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS registration_ip   VARCHAR(45)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_banned         TINYINT(1)    NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS referral_locked   TINYINT(1)    NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS referral_snapshot JSON          DEFAULT NULL;

-- Backfill unique ref_codes for any existing users that have an empty ref_code
UPDATE users SET ref_code = UPPER(SUBSTRING(MD5(CONCAT(id, email, RAND())), 1, 12)) WHERE ref_code = '';

-- Now add the unique index (safe because all rows have distinct non-empty codes)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND INDEX_NAME   = 'ref_code'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE users ADD UNIQUE KEY ref_code (ref_code)',
    'SELECT 1 -- ref_code unique index already exists'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Foreign key: referred_by → users.id (only add if constraint doesn't already exist)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'users'
      AND CONSTRAINT_NAME   = 'fk_referred_by'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1 -- fk_referred_by already exists'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index: idx_referred_by (only add if index doesn't already exist)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'users'
      AND INDEX_NAME   = 'idx_referred_by'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE users ADD INDEX idx_referred_by (referred_by)',
    'SELECT 1 -- idx_referred_by already exists'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Multi-room & payout columns on lottery_games (task 1.2) ──────────────────
ALTER TABLE lottery_games
    ADD COLUMN IF NOT EXISTS room           TINYINT        NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS payout_status  ENUM('pending','paid') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS payout_id      VARCHAR(36)    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS commission     DECIMAL(12,2)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS referral_bonus DECIMAL(12,2)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS winner_net     DECIMAL(12,2)  DEFAULT NULL;

-- Index: idx_room_status (only add if index doesn't already exist)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'lottery_games'
      AND INDEX_NAME   = 'idx_room_status'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE lottery_games ADD INDEX idx_room_status (room, status)',
    'SELECT 1 -- idx_room_status already exists'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Room column on lottery_bets (task 1.3) ────────────────────────────────────
ALTER TABLE lottery_bets ADD COLUMN IF NOT EXISTS room TINYINT NOT NULL DEFAULT 1;

-- ── System balance singleton (task 1.4) ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_balance (
  id      INT           NOT NULL DEFAULT 1 PRIMARY KEY,
  balance DECIMAL(15,2) NOT NULL DEFAULT 0.00
);
INSERT IGNORE INTO system_balance (id, balance) VALUES (1, 0.00);

-- ── System transactions log (task 1.4) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS system_transactions (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  game_id        INT           NULL,
  payout_id      VARCHAR(36)   DEFAULT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  type           VARCHAR(32)   NOT NULL,
  source_user_id INT           NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_payout_type (game_id, payout_id, type),
  INDEX idx_created_at (created_at)
);

-- ── User transactions log (task 1.4) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_transactions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT           NOT NULL,
  payout_id  VARCHAR(36)   DEFAULT NULL,
  type       VARCHAR(32)   NOT NULL,
  amount     DECIMAL(12,2) NOT NULL,
  game_id    INT           NULL,
  note       VARCHAR(255)  DEFAULT NULL,
  created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_game_user_type (game_id, user_id, type, payout_id),
  INDEX idx_user_created (user_id, created_at)
);

-- ── Registration attempts (task 1.4) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS registration_attempts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_created (ip, created_at)
);
