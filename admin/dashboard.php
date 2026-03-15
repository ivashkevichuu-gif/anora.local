<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$users = $pdo->query(
    'SELECT id, email, balance, bank_details, is_verified, created_at FROM users ORDER BY created_at DESC'
)->fetchAll();

$pageTitle = 'Users';
include 'layout.php';
?>
<h4 class="mb-4"><i class="bi bi-people-fill"></i> All Users</h4>
<div class="card p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Email</th>
                    <th>Balance</th>
                    <th>Verified</th>
                    <th>Bank Details</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>$<?= number_format($u['balance'], 2) ?></td>
                    <td>
                        <?php if ($u['is_verified']): ?>
                            <span class="badge bg-success">Yes</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['bank_details']): ?>
                            <button class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="popover"
                                    data-bs-content="<?= htmlspecialchars($u['bank_details']) ?>"
                                    data-bs-trigger="focus" tabindex="0">View</button>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $u['created_at'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => new bootstrap.Popover(el));
</script>
<?php include 'layout_end.php'; ?>
