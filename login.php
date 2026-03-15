<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (isLoggedIn()) { header('Location: /account.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $error = 'Invalid email or password.';
    } elseif (!$user['is_verified']) {
        $error = 'Please verify your email before logging in.';
    } else {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email']   = $user['email'];
        header('Location: /account.php');
        exit;
    }
}

$pageTitle = 'Login';
include 'includes/header.php';
?>
<div class="container" style="max-width:440px">
    <div class="card p-4 mt-4">
        <h4 class="mb-3 text-center"><i class="bi bi-box-arrow-in-right text-primary"></i> Login</h4>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <div class="mb-3">
                <label class="form-label">Email address</label>
                <input type="email" name="email" class="form-control" required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
        <p class="text-center mt-3 mb-0">No account? <a href="/register.php">Register</a></p>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
