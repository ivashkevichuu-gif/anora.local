<?php
/**
 * Migration: Create oauth_accounts table for OAuth Social Login.
 *
 * Stores links between users and OAuth providers (Google, Apple).
 * Also updates users.password to DEFAULT '' for OAuth-only users.
 *
 * Feature: oauth-social-login
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 13.1, 13.2, 13.3
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "Running migration: add_oauth_accounts\n";

// Create oauth_accounts table
$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS oauth_accounts (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    provider         ENUM('google', 'apple') NOT NULL,
    provider_user_id VARCHAR(255) NOT NULL,
    provider_email   VARCHAR(255) DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_provider_user (provider, provider_user_id),
    INDEX idx_user_id (user_id),
    INDEX idx_provider_email (provider, provider_email),

    CONSTRAINT fk_oauth_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

try {
    $pdo->exec($sql);
    echo "  ✓ oauth_accounts table created.\n";
} catch (PDOException $e) {
    echo "  ✗ Failed to create oauth_accounts: " . $e->getMessage() . "\n";
    exit(1);
}

// Alter users.password to DEFAULT '' for OAuth-only users
try {
    $pdo->exec("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL DEFAULT ''");
    echo "  ✓ users.password updated to DEFAULT ''.\n";
} catch (PDOException $e) {
    // Ignore if already modified
    echo "  ⚠ users.password alter skipped: " . $e->getMessage() . "\n";
}

echo "Migration complete: add_oauth_accounts\n";
