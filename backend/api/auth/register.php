<?php
session_start();
require_once __DIR__ . '/../../includes/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mailer.php';

$input    = json_decode(file_get_contents('php://input'), true);
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address.']); exit;
}

// Disposable email domain check (Requirement 20.1)
$blockedDomains = [
    'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email',
    'yopmail.com', 'sharklasers.com', 'trashmail.com', 'maildrop.cc',
    'dispostable.com', 'fakeinbox.com',
];
$domain = strtolower(substr(strrchr($email, '@'), 1));
if (in_array($domain, $blockedDomains, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email domain not allowed.']); exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 6 characters.']); exit;
}

// Rate limiting: INSERT-then-check pattern (Requirement 13.1, 13.2)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO registration_attempts (ip, created_at) VALUES (?, NOW())')
    ->execute([$ip]);

$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM registration_attempts WHERE ip = ? AND created_at >= NOW() - INTERVAL 1 HOUR'
);
$countStmt->execute([$ip]);
$attemptCount = (int) $countStmt->fetchColumn();

if ($attemptCount > 3) {
    $pdo->rollBack();
    http_response_code(429);
    echo json_encode(['error' => 'Too many registration attempts. Please try again later.']); exit;
}

$pdo->commit();

// Check for existing email
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Email already registered.']); exit;
}

$hash  = password_hash($password, PASSWORD_DEFAULT);
$token = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO users (email, password, verify_token) VALUES (?, ?, ?)')
    ->execute([$email, $hash, $token]);

$userId = (int)$pdo->lastInsertId();

// Generate ref_code with retry loop (Requirement 10.1, 10.2, 10.3)
$refCodeSet = false;
for ($attempt = 0; $attempt < 3; $attempt++) {
    $refCode = strtoupper(bin2hex(random_bytes(6)));
    try {
        $pdo->prepare('UPDATE users SET ref_code = ? WHERE id = ?')
            ->execute([$refCode, $userId]);
        $refCodeSet = true;
        break;
    } catch (PDOException $e) {
        // DUPLICATE KEY error (23000) — retry with new code
        if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            continue;
        }
        throw $e;
    }
}

if (!$refCodeSet) {
    error_log("[Registration] Failed to generate unique ref_code for user #$userId after 3 attempts");
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed. Please try again.']); exit;
}

// Store registration IP (Requirement 12.2)
$pdo->prepare('UPDATE users SET registration_ip = ? WHERE id = ?')
    ->execute([$ip, $userId]);

// Referral code capture and eligibility check (Requirements 11.4-11.6, 12.1-12.6, 20.2-20.4)
$referralCode = trim($input['referral_code'] ?? '');
$referredBy   = null;
$referralLocked = 0;
$referralSnapshot = null;

if ($referralCode !== '') {
    // Look up referrer by ref_code (case-insensitive)
    $refStmt = $pdo->prepare(
        'SELECT * FROM users WHERE ref_code = ? COLLATE utf8mb4_unicode_ci'
    );
    $refStmt->execute([$referralCode]);
    $referrer = $refStmt->fetch();

    if (!$referrer) {
        error_log("[Referral] ref_code not found: $referralCode");
    } else {
        $referrerId = (int)$referrer['id'];
        $eligible   = true;
        $reason     = '';

        // Check: not self-referral
        if ($referrerId === $userId) {
            $eligible = false;
            $reason   = 'self-referral';
        }

        // Check: same IP block (Requirement 12.3, 20.3)
        if ($eligible && $referrer['registration_ip'] === $ip) {
            $eligible = false;
            $reason   = 'same-ip';
            error_log("[Referral] Same-IP block: referrer_id=$referrerId ip=$ip");
        }

        // Check: referrer must be verified (Requirement 12.4)
        if ($eligible && (int)$referrer['is_verified'] !== 1) {
            $eligible = false;
            $reason   = 'not-verified';
        }

        // Check: referrer must not be banned (Requirement 12.6)
        if ($eligible && (int)$referrer['is_banned'] !== 0) {
            $eligible = false;
            $reason   = 'banned';
        }

        // Check: referrer account must be at least 24 hours old (Requirement 12.4)
        if ($eligible) {
            $ageStmt = $pdo->prepare(
                'SELECT 1 FROM users WHERE id = ? AND created_at <= NOW() - INTERVAL 24 HOUR'
            );
            $ageStmt->execute([$referrerId]);
            if (!$ageStmt->fetch()) {
                $eligible = false;
                $reason   = 'account-too-young';
            }
        }

        // Check: referrer must have at least one completed deposit (Requirement 12.5)
        if ($eligible) {
            $depStmt = $pdo->prepare(
                "SELECT 1 FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed' LIMIT 1"
            );
            $depStmt->execute([$referrerId]);
            if (!$depStmt->fetch()) {
                $eligible = false;
                $reason   = 'no-deposit';
            }
        }

        if ($eligible) {
            $referredBy     = $referrerId;
            $referralLocked = 1;
            $referralSnapshot = json_encode([
                'referrer_id'  => $referrerId,
                'is_verified'  => (bool)$referrer['is_verified'],
                'had_deposit'  => true,
                'created_at'   => $referrer['created_at'],
                'locked_at'    => date('Y-m-d H:i:s'),
            ]);
        } else {
            error_log("[Referral] Eligibility failed for referrer_id=$referrerId reason=$reason");
        }
    }
}

// Update user with referral data
$pdo->prepare(
    'UPDATE users SET referred_by = ?, referral_locked = ?, referral_snapshot = ? WHERE id = ?'
)->execute([$referredBy, $referralLocked, $referralSnapshot, $userId]);

sendVerificationEmail($email, $token);
echo json_encode(['message' => 'Registration successful. Please check your email to verify your account.']);
