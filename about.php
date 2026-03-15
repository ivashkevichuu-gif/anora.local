<?php
session_start();
require_once 'includes/auth.php';
$pageTitle = 'About Us';
include 'includes/header.php';
?>
<div class="container" style="max-width:760px">
    <h2 class="mb-4">About Us</h2>
    <div class="card p-4 mb-4">
        <h5><i class="bi bi-building text-primary"></i> Who We Are</h5>
        <p class="text-muted">FinanceApp is a modern personal finance platform designed to give you full control over your money. We believe managing finances should be simple, transparent, and secure.</p>
    </div>
    <div class="card p-4 mb-4">
        <h5><i class="bi bi-bullseye text-success"></i> Our Mission</h5>
        <p class="text-muted">Our mission is to provide a reliable and easy-to-use platform for depositing, managing, and withdrawing funds — with full transaction transparency and fast support.</p>
    </div>
    <div class="card p-4 mb-4">
        <h5><i class="bi bi-shield-check text-warning"></i> Security</h5>
        <p class="text-muted">All accounts are protected with email verification, hashed passwords, and session-based authentication. Withdrawal requests are manually reviewed by our team before processing.</p>
    </div>
    <div class="card p-4">
        <h5><i class="bi bi-envelope text-info"></i> Contact</h5>
        <p class="text-muted mb-0">Have questions? Reach us at <a href="mailto:support@financeapp.example">support@financeapp.example</a></p>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
