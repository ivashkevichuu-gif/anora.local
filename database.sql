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
