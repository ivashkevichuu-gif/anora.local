<?php
/**
 * Migration: Add manual_mode to instagram_settings + ready_for_download status to media_posts.
 *
 * When manual_mode=1, the pipeline renders video but stops before publishing.
 * Admin downloads the rendered reel and posts manually to Instagram.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

echo "=== Migration: add_instagram_manual_mode ===\n";

$queries = [
    "ALTER TABLE instagram_settings ADD COLUMN manual_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled",

    "ALTER TABLE media_posts MODIFY COLUMN status ENUM('queued','rendering','ready_for_download','publishing','published','failed') NOT NULL DEFAULT 'queued'",
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "  ✓ " . substr($sql, 0, 80) . "...\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "  ⊘ Column already exists, skipping\n";
        } else {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "=== Migration complete ===\n";
