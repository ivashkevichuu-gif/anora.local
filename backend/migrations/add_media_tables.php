<?php
/**
 * Migration: Add media system tables for Instagram Reels + Telegram autopost management.
 *
 * Tables: media_settings, instagram_settings, telegram_settings, social_links, media_posts
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "=== Migration: add_media_tables ===\n";

$queries = [
    // Global media toggle
    "CREATE TABLE IF NOT EXISTS media_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        instagram_enabled TINYINT(1) NOT NULL DEFAULT 0,
        telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Instagram Reels configuration
    "CREATE TABLE IF NOT EXISTS instagram_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        allowed_rooms JSON NOT NULL DEFAULT ('[]'),
        min_win_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        max_posts_per_day INT UNSIGNED NOT NULL DEFAULT 10,
        posts_today INT UNSIGNED NOT NULL DEFAULT 0,
        last_reset_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        access_token TEXT DEFAULT NULL,
        ig_user_id VARCHAR(64) DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Telegram autopost configuration
    "CREATE TABLE IF NOT EXISTS telegram_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        post_new_rooms TINYINT(1) NOT NULL DEFAULT 1,
        post_finished_rooms TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Social links for footer
    "CREATE TABLE IF NOT EXISTS social_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        telegram_url VARCHAR(512) DEFAULT '',
        instagram_url VARCHAR(512) DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Media post log (dedup + analytics)
    "CREATE TABLE IF NOT EXISTS media_posts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        round_id INT UNSIGNED NOT NULL,
        platform ENUM('instagram','telegram') NOT NULL,
        post_type ENUM('new_room','finished_room') NOT NULL,
        status ENUM('queued','rendering','publishing','published','failed') NOT NULL DEFAULT 'queued',
        video_path VARCHAR(512) DEFAULT NULL,
        external_id VARCHAR(256) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        published_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_round_platform (round_id, platform),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Seed default rows
    "INSERT IGNORE INTO media_settings (id, instagram_enabled, telegram_enabled) VALUES (1, 0, 1)",
    "INSERT IGNORE INTO instagram_settings (id, enabled, allowed_rooms, min_win_amount, max_posts_per_day) VALUES (1, 0, '[\"1\",\"10\",\"100\"]', 5.00, 10)",
    "INSERT IGNORE INTO telegram_settings (id, enabled, post_new_rooms, post_finished_rooms) VALUES (1, 1, 1, 1)",
    "INSERT IGNORE INTO social_links (id, telegram_url, instagram_url) VALUES (1, '', '')",
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "  ✓ " . substr($sql, 0, 60) . "...\n";
    } catch (PDOException $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        echo "    SQL: " . substr($sql, 0, 100) . "\n";
    }
}

echo "=== Migration complete ===\n";
