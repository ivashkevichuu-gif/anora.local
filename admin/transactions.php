<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$txs = $pdo->query(
    'SELECT t.*, u.email FROM transactions t
     JOIN users u ON u.id = t.user_id
     ORDER BY t.created_at DESC'
)->fetchAll();

$pageTitle = 'Transactions';
include 'layout.php';
?>
<h4 class="mb-4"><i class="bi bi-list-ul"></i> All Transactions</h4>
<div class="card p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Note</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($txs as $tx): ?>
                <tr>
                    <td><?= $tx['id'] ?></td>
                    <td><?= htmlspecialchars($tx['email']) ?></td>
                    <td>
                        <?php if ($tx['type'] === 'deposit'): ?>
                            <span class="badge bg-success">Deposit</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Withdrawal</span>
                        <?php endif; ?>
                    </td>
                    <td>$<?= number_format($tx['amount'], 2) ?></td>
                    <td><span class="badge badge-<?= $tx['status'] ?>"><?= ucfirst($tx['status']) ?></span></td>
                    <td><?= htmlspecialchars($tx['note'] ?? '') ?></td>
                    <td><?= $tx['created_at'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'layout_end.php'; ?>
