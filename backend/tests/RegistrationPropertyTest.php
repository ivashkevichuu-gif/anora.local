<?php
/**
 * Property-based tests for the registration handler (Properties 14, 16, 17, 18, 20).
 *
 * Uses an in-memory SQLite database to simulate the MySQL schema.
 * Since register.php is a script (not a class), testable logic is extracted
 * as pure functions or simulated directly.
 *
 * Feature: referral-commission-system
 * Validates: Requirements 10.1, 11.5, 11.6, 12.1, 13.1, 20.1, 20.5
 */

declare(strict_types=1);

defined('LOTTERY_BET')              || define('LOTTERY_BET',              1.00);
defined('LOTTERY_COUNTDOWN')        || define('LOTTERY_COUNTDOWN',        30);
defined('LOTTERY_MIN_PLAYERS')      || define('LOTTERY_MIN_PLAYERS',      2);
defined('LOTTERY_MAX_BETS_PER_SEC') || define('LOTTERY_MAX_BETS_PER_SEC', 5);
defined('LOTTERY_HASH_FORMAT')      || define('LOTTERY_HASH_FORMAT',      '%s:%s:%d');

use PHPUnit\Framework\TestCase;

class RegistrationPropertyTest extends TestCase
{
    private PDO $db;

    // -------------------------------------------------------------------------
    // Set up in-memory SQLite database with required schema
    // -------------------------------------------------------------------------
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                password TEXT DEFAULT '',
                balance REAL DEFAULT 0,
                referral_earnings REAL DEFAULT 0,
                is_verified INTEGER DEFAULT 0,
                is_banned INTEGER DEFAULT 0,
                referred_by INTEGER DEFAULT NULL,
                referral_locked INTEGER DEFAULT 0,
                ref_code TEXT DEFAULT '',
                registration_ip TEXT DEFAULT NULL,
                referral_snapshot TEXT DEFAULT NULL,
                display_name TEXT DEFAULT NULL,
                is_bot INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                type TEXT,
                amount REAL,
                status TEXT DEFAULT 'pending',
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE registration_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // -------------------------------------------------------------------------
    // Helper: generate a ref_code using the same logic as register.php
    // -------------------------------------------------------------------------
    private function generateRefCode(): string
    {
        return strtoupper(bin2hex(random_bytes(6)));
    }

    // -------------------------------------------------------------------------
    // Helper: check disposable email domain (mirrors register.php logic)
    // -------------------------------------------------------------------------
    private function isBlockedDomain(string $email): bool
    {
        $blockedDomains = [
            'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
            'yopmail.com', 'sharklasers.com', 'trashmail.com', 'maildrop.cc',
            'dispostable.com', 'fakeinbox.com',
        ];
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($domain, $blockedDomains, true);
    }

    // -------------------------------------------------------------------------
    // Helper: insert a user with specific attributes, return its id
    // -------------------------------------------------------------------------
    private function insertUser(
        int $isVerified = 1,
        int $isBanned = 0,
        string $createdAt = '2000-01-01 00:00:00',
        string $registrationIp = '1.2.3.4'
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO users (email, is_verified, is_banned, created_at, registration_ip)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            'user' . mt_rand(1, 999999) . '@test.com',
            $isVerified,
            $isBanned,
            $createdAt,
            $registrationIp,
        ]);
        return (int)$this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helper: add a completed deposit for a user
    // -------------------------------------------------------------------------
    private function addCompletedDeposit(int $userId): void
    {
        $this->db->prepare(
            "INSERT INTO transactions (user_id, type, amount, status) VALUES (?, 'deposit', 10.00, 'completed')"
        )->execute([$userId]);
    }

    // -------------------------------------------------------------------------
    // Helper: simulate the referral eligibility check from register.php.
    // Returns the referrer id if eligible, or null if ineligible.
    // -------------------------------------------------------------------------
    private function checkReferralEligibility(
        PDO $db,
        int $referrerId,
        int $newUserId,
        string $newUserIp
    ): ?int {
        $refStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $refStmt->execute([$referrerId]);
        $referrer = $refStmt->fetch();

        if (!$referrer) {
            return null;
        }

        $eligible = true;

        // Not self-referral
        if ($referrerId === $newUserId) {
            $eligible = false;
        }

        // Same IP block
        if ($eligible && $referrer['registration_ip'] === $newUserIp) {
            $eligible = false;
        }

        // Must be verified
        if ($eligible && (int)$referrer['is_verified'] !== 1) {
            $eligible = false;
        }

        // Must not be banned
        if ($eligible && (int)$referrer['is_banned'] !== 0) {
            $eligible = false;
        }

        // Account must be at least 24 hours old
        if ($eligible) {
            $ageStmt = $db->prepare(
                "SELECT 1 FROM users WHERE id = ? AND created_at <= datetime('now', '-24 hours')"
            );
            $ageStmt->execute([$referrerId]);
            if (!$ageStmt->fetch()) {
                $eligible = false;
            }
        }

        // Must have at least one completed deposit
        if ($eligible) {
            $depStmt = $db->prepare(
                "SELECT 1 FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed' LIMIT 1"
            );
            $depStmt->execute([$referrerId]);
            if (!$depStmt->fetch()) {
                $eligible = false;
            }
        }

        return $eligible ? $referrerId : null;
    }

    // -------------------------------------------------------------------------
    // Helper: simulate the rate limiting check from register.php.
    // Returns true if the attempt is allowed (count <= 3), false if blocked.
    //
    // Mirrors the INSERT-then-check-then-rollback pattern in register.php:
    //   1. BEGIN TRANSACTION
    //   2. INSERT attempt row
    //   3. COUNT attempts in last hour
    //   4. If count > 3: ROLLBACK (row is not persisted), return false
    //   5. Else: COMMIT (row is persisted), return true
    // -------------------------------------------------------------------------
    private function checkRateLimit(PDO $db, string $ip): bool
    {
        $db->beginTransaction();

        // Insert attempt
        $db->prepare(
            "INSERT INTO registration_attempts (ip, created_at) VALUES (?, datetime('now'))"
        )->execute([$ip]);

        // Count attempts in last hour (includes the just-inserted row)
        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM registration_attempts WHERE ip = ? AND created_at >= datetime('now', '-1 hour')"
        );
        $countStmt->execute([$ip]);
        $count = (int)$countStmt->fetchColumn();

        if ($count > 3) {
            // Rollback — the inserted row is not persisted
            $db->rollBack();
            return false;
        }

        $db->commit();
        return true;
    }

    // =========================================================================
    // Property 14: ref_code Format
    // Feature: referral-commission-system, Property 14: ref_code Format
    //
    // For any newly registered user, ref_code must be a 12-character uppercase
    // hexadecimal string matching /^[0-9A-F]{12}$/.
    //
    // Validates: Requirements 10.1, 10.4
    // =========================================================================
    public function testProperty14_RefCodeFormat(): void
    {
        // Feature: referral-commission-system, Property 14: ref_code Format
        $iterations = 100;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $refCode = $this->generateRefCode();

            if (!preg_match('/^[0-9A-F]{12}$/', $refCode)) {
                $failures[] = sprintf(
                    'iter=%d: ref_code "%s" does not match /^[0-9A-F]{12}$/',
                    $i,
                    $refCode
                );
            }

            if (strlen($refCode) !== 12) {
                $failures[] = sprintf(
                    'iter=%d: ref_code "%s" has length %d (expected 12)',
                    $i,
                    $refCode,
                    strlen($refCode)
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 14 (ref_code Format) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 16: Referral Eligibility Enforcement
    // Feature: referral-commission-system, Property 16: Referral Eligibility Enforcement
    //
    // For any registration where the referrer fails any Eligible_Referrer criterion,
    // referred_by must be set to NULL and registration must complete without error.
    //
    // Test cases (each should result in referred_by = NULL):
    //   - Referrer with is_verified = 0
    //   - Referrer with is_banned = 1
    //   - Referrer with created_at = NOW() (too young)
    //   - Referrer with no completed deposit
    //   - Same IP as registrant
    //
    // Validates: Requirements 11.5, 11.6, 12.3, 12.4, 12.5, 12.6
    // =========================================================================
    public function testProperty16_ReferralEligibilityEnforcement(): void
    {
        // Feature: referral-commission-system, Property 16: Referral Eligibility Enforcement
        $iterations = 20;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            $newUserIp = '10.0.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);

            // Case 1: Referrer not verified (is_verified = 0)
            $referrer1 = $this->insertUser(
                isVerified: 0,
                isBanned: 0,
                createdAt: '2000-01-01 00:00:00',
                registrationIp: '192.168.1.1'
            );
            $this->addCompletedDeposit($referrer1);
            $newUser1 = $this->insertUser(registrationIp: $newUserIp);
            $result1 = $this->checkReferralEligibility($this->db, $referrer1, $newUser1, $newUserIp);
            if ($result1 !== null) {
                $failures[] = sprintf('iter=%d case=not-verified: expected null, got %d', $i, $result1);
            }

            // Case 2: Referrer is banned (is_banned = 1)
            $referrer2 = $this->insertUser(
                isVerified: 1,
                isBanned: 1,
                createdAt: '2000-01-01 00:00:00',
                registrationIp: '192.168.1.2'
            );
            $this->addCompletedDeposit($referrer2);
            $newUser2 = $this->insertUser(registrationIp: $newUserIp);
            $result2 = $this->checkReferralEligibility($this->db, $referrer2, $newUser2, $newUserIp);
            if ($result2 !== null) {
                $failures[] = sprintf('iter=%d case=banned: expected null, got %d', $i, $result2);
            }

            // Case 3: Referrer account too young (created_at = NOW())
            $referrer3 = $this->insertUser(
                isVerified: 1,
                isBanned: 0,
                createdAt: date('Y-m-d H:i:s'), // just now
                registrationIp: '192.168.1.3'
            );
            $this->addCompletedDeposit($referrer3);
            $newUser3 = $this->insertUser(registrationIp: $newUserIp);
            $result3 = $this->checkReferralEligibility($this->db, $referrer3, $newUser3, $newUserIp);
            if ($result3 !== null) {
                $failures[] = sprintf('iter=%d case=too-young: expected null, got %d', $i, $result3);
            }

            // Case 4: Referrer has no completed deposit
            $referrer4 = $this->insertUser(
                isVerified: 1,
                isBanned: 0,
                createdAt: '2000-01-01 00:00:00',
                registrationIp: '192.168.1.4'
            );
            // No deposit added for referrer4
            $newUser4 = $this->insertUser(registrationIp: $newUserIp);
            $result4 = $this->checkReferralEligibility($this->db, $referrer4, $newUser4, $newUserIp);
            if ($result4 !== null) {
                $failures[] = sprintf('iter=%d case=no-deposit: expected null, got %d', $i, $result4);
            }

            // Case 5: Same IP as registrant
            $referrer5 = $this->insertUser(
                isVerified: 1,
                isBanned: 0,
                createdAt: '2000-01-01 00:00:00',
                registrationIp: $newUserIp  // same IP
            );
            $this->addCompletedDeposit($referrer5);
            $newUser5 = $this->insertUser(registrationIp: $newUserIp);
            $result5 = $this->checkReferralEligibility($this->db, $referrer5, $newUser5, $newUserIp);
            if ($result5 !== null) {
                $failures[] = sprintf('iter=%d case=same-ip: expected null, got %d', $i, $result5);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 16 (Referral Eligibility Enforcement) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 17: referred_by Immutability
    // Feature: referral-commission-system, Property 17: referred_by Immutability
    //
    // Verify that the registration handler never updates referred_by after
    // initial set. This is a structural test: verify register.php does not
    // contain any UPDATE that changes referred_by outside of the initial
    // registration flow.
    //
    // Validates: Requirements 12.1, 20.5
    // =========================================================================
    public function testProperty17_ReferredByImmutability(): void
    {
        // Feature: referral-commission-system, Property 17: referred_by Immutability
        $registerPhpPath = __DIR__ . '/../api/auth/register.php';

        $this->assertFileExists(
            $registerPhpPath,
            'register.php must exist at backend/api/auth/register.php'
        );

        $source = file_get_contents($registerPhpPath);
        $this->assertNotFalse($source, 'Could not read register.php');

        // Find all UPDATE statements that mention referred_by
        // The only allowed UPDATE is the initial registration INSERT/UPDATE
        // which sets referred_by once. There must be no subsequent UPDATE
        // that changes referred_by (e.g., no UPDATE users SET referred_by = ? WHERE id = ?
        // outside of the registration flow).

        // Extract all UPDATE ... SET ... referred_by occurrences
        preg_match_all(
            '/UPDATE\s+\w+\s+SET\s+[^;]*referred_by[^;]*;/si',
            $source,
            $matches
        );

        $updateStatements = $matches[0];

        // There should be exactly ONE UPDATE that sets referred_by
        // (the initial registration update: UPDATE users SET referred_by = ?, referral_locked = ?, ...)
        $this->assertCount(
            1,
            $updateStatements,
            "register.php must contain exactly one UPDATE that sets referred_by (the initial registration). "
            . "Found " . count($updateStatements) . " UPDATE(s) with referred_by:\n"
            . implode("\n", $updateStatements)
        );

        // Verify the single UPDATE is the registration one (sets referred_by along with referral_locked)
        $singleUpdate = $updateStatements[0];
        $this->assertStringContainsString(
            'referral_locked',
            $singleUpdate,
            "The referred_by UPDATE must be the initial registration update (should also set referral_locked)"
        );

        // Verify there is no UPDATE that sets referred_by WITHOUT also setting referral_locked
        // (which would indicate a post-registration mutation that bypasses the combined update)
        preg_match_all(
            '/UPDATE\s+\w+\s+SET\s+referred_by\s*=[^;]*;/si',
            $source,
            $onlyRefByMatches
        );
        foreach ($onlyRefByMatches[0] as $stmt) {
            $this->assertStringContainsString(
                'referral_locked',
                $stmt,
                "Any UPDATE that sets referred_by must also set referral_locked "
                . "(no standalone referred_by mutation allowed). Found: $stmt"
            );
        }
    }

    // =========================================================================
    // Property 18: Registration Rate Limiting
    // Feature: referral-commission-system, Property 18: Registration Rate Limiting
    //
    // For any IP with more than 3 registration attempts in the last hour,
    // the next attempt must be blocked. Exactly 3 attempts must be allowed.
    //
    // Validates: Requirements 13.1
    // =========================================================================
    public function testProperty18_RegistrationRateLimiting(): void
    {
        // Feature: referral-commission-system, Property 18: Registration Rate Limiting
        $iterations = 20;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Use a fresh DB for each iteration to avoid cross-contamination
            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec("
                CREATE TABLE registration_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Generate a random IP
            $ip = mt_rand(1, 254) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);

            // Case A: 3 attempts → should be allowed (count = 3, not > 3)
            $allowed = true;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $result = $this->checkRateLimit($db, $ip);
                if (!$result) {
                    $allowed = false;
                    $failures[] = sprintf(
                        'iter=%d ip=%s: attempt %d of 3 was blocked (should be allowed)',
                        $i, $ip, $attempt
                    );
                }
            }

            // Case B: 4th attempt → should be blocked (count = 4, > 3)
            $result4 = $this->checkRateLimit($db, $ip);
            if ($result4 !== false) {
                $failures[] = sprintf(
                    'iter=%d ip=%s: 4th attempt was allowed (should be blocked)',
                    $i, $ip
                );
            }

            // Case C: 5th attempt → should also be blocked
            $result5 = $this->checkRateLimit($db, $ip);
            if ($result5 !== false) {
                $failures[] = sprintf(
                    'iter=%d ip=%s: 5th attempt was allowed (should be blocked)',
                    $i, $ip
                );
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 18 (Registration Rate Limiting) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 20: Disposable Email Rejection
    // Feature: referral-commission-system, Property 20: Disposable Email Rejection
    //
    // All 10 blocked domains must be rejected. Legitimate domains must NOT be blocked.
    //
    // Validates: Requirements 20.1
    // =========================================================================
    public function testProperty20_DisposableEmailRejection(): void
    {
        // Feature: referral-commission-system, Property 20: Disposable Email Rejection
        $blockedDomains = [
            'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
            'yopmail.com', 'sharklasers.com', 'trashmail.com', 'maildrop.cc',
            'dispostable.com', 'fakeinbox.com',
        ];

        $legitimateDomains = [
            'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com',
            'icloud.com', 'protonmail.com', 'example.com', 'test.org',
        ];

        $iterations = 50;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Test all blocked domains are rejected
            foreach ($blockedDomains as $domain) {
                $username = 'user' . mt_rand(1, 999999);
                $email    = $username . '@' . $domain;

                if (!$this->isBlockedDomain($email)) {
                    $failures[] = sprintf(
                        'iter=%d: blocked domain "%s" was NOT rejected (email: %s)',
                        $i, $domain, $email
                    );
                }
            }

            // Test legitimate domains are NOT blocked
            foreach ($legitimateDomains as $domain) {
                $username = 'user' . mt_rand(1, 999999);
                $email    = $username . '@' . $domain;

                if ($this->isBlockedDomain($email)) {
                    $failures[] = sprintf(
                        'iter=%d: legitimate domain "%s" was incorrectly blocked (email: %s)',
                        $i, $domain, $email
                    );
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 20 (Disposable Email Rejection) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
