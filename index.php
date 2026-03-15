<?php
session_start();
require_once 'includes/auth.php';
$pageTitle = 'FinanceApp – Home';
include 'includes/header.php';
?>
<div class="hero text-center">
    <div class="container">
        <h1><i class="bi bi-bank2"></i> FinanceApp</h1>
        <p class="lead mt-3">Manage your finances securely and easily.</p>
        <?php if (!isLoggedIn()): ?>
            <a href="/register.php" class="btn btn-light btn-lg me-2 mt-3">Get Started</a>
            <a href="/login.php" class="btn btn-outline-light btn-lg mt-3">Login</a>
        <?php else: ?>
            <a href="/account.php" class="btn btn-light btn-lg mt-3">Go to My Account</a>
        <?php endif; ?>
    </div>
</div>

<div class="container mt-5">
    <div class="row g-4 text-center">
        <div class="col-md-4">
            <div class="card p-4 h-100">
                <i class="bi bi-shield-lock-fill text-primary fs-1"></i>
                <h5 class="mt-3">Secure</h5>
                <p class="text-muted">Your data is protected with industry-standard encryption and email verification.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-4 h-100">
                <i class="bi bi-lightning-charge-fill text-warning fs-1"></i>
                <h5 class="mt-3">Fast</h5>
                <p class="text-muted">Instant deposits and quick withdrawal processing by our team.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card p-4 h-100">
                <i class="bi bi-graph-up-arrow text-success fs-1"></i>
                <h5 class="mt-3">Transparent</h5>
                <p class="text-muted">Full transaction history so you always know where your money is.</p>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
