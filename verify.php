<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

$token = trim($_GET['token'] ?? '');
$msg   = '';
$type  = 'danger';

if ($token) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE verify_token = ? AND is_verified = 0');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        $pdo->prepare('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?')
            ->execute([$user['id']]);
        $msg  = 'Your email has been verified. You can now <a href="/login.php">login</a>.';
        $type = 'success';
    } else {
        $msg = 'Invalid or already used verification link.';
    }
} else {
    $msg = 'No token provided.';
}

$pageTitle = 'Email Verification';
include 'includes/header.php';
?>
<div class="container" style="max-width:480px">
    <div class="alert alert-<?= $type ?> mt-4"><?= $msg ?></div>
</div>
<?php include 'includes/footer.php'; ?>
