<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$reqs = $pdo->query(
    'SELECT wr.*, u.email FROM withdrawal_requests wr
     JOIN users u ON u.id = wr.user_id
     ORDER BY wr.created_at DESC'
)->fetchAll();

$pageTitle = 'Withdrawal Requests';
include 'layout.php';
?>
<h4 class="mb-4"><i class="bi bi-arrow-up-circle"></i> Withdrawal Requests</h4>
<div id="actionMsg"></div>
<div class="card p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Amount</th>
                    <th>Bank Details</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="reqTable">
            <?php foreach ($reqs as $r): ?>
                <tr id="row-<?= $r['id'] ?>">
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td>$<?= number_format($r['amount'], 2) ?></td>
                    <td style="max-width:200px;white-space:pre-wrap;font-size:.85rem"><?= htmlspecialchars($r['bank_details']) ?></td>
                    <td><span class="badge badge-<?= $r['status'] ?>" id="status-<?= $r['id'] ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td><?= $r['created_at'] ?></td>
                    <td>
                        <?php if ($r['status'] === 'pending'): ?>
                            <button class="btn btn-sm btn-success me-1" onclick="doAction(<?= $r['id'] ?>,'approve')">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="doAction(<?= $r['id'] ?>,'reject')">
                                <i class="bi bi-x-lg"></i> Reject
                            </button>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function doAction(id, action) {
    if (!confirm(`${action.charAt(0).toUpperCase()+action.slice(1)} this request?`)) return;
    const res = await fetch('/api/admin_actions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id, action })
    });
    const data = await res.json();
    const msg = document.getElementById('actionMsg');
    msg.innerHTML = `<div class="alert alert-${data.ok ? 'success' : 'danger'}">${data.message}</div>`;
    if (data.ok) {
        const badge = document.getElementById('status-' + id);
        badge.textContent = action === 'approve' ? 'Approved' : 'Rejected';
        badge.className = 'badge badge-' + (action === 'approve' ? 'approved' : 'rejected');
        const row = document.getElementById('row-' + id);
        row.querySelector('td:last-child').innerHTML = '<span class="text-muted small">—</span>';
    }
}
</script>
<?php include 'layout_end.php'; ?>
