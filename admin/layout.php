<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> – Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/style.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
    <div class="admin-sidebar d-flex flex-column p-3" style="width:220px;min-width:220px">
        <div class="text-white fw-bold fs-5 mb-4 mt-1"><i class="bi bi-bank2"></i> Admin</div>
        <nav class="nav flex-column gap-1">
            <a href="/admin/dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-people-fill me-2"></i>Users
            </a>
            <a href="/admin/transactions.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'transactions.php' ? 'active' : '' ?>">
                <i class="bi bi-list-ul me-2"></i>Transactions
            </a>
            <a href="/admin/withdrawals.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'withdrawals.php' ? 'active' : '' ?>">
                <i class="bi bi-arrow-up-circle me-2"></i>Withdrawals
            </a>
        </nav>
        <div class="mt-auto">
            <a href="/admin/logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i>Logout</a>
        </div>
    </div>
    <div class="flex-grow-1 p-4">
