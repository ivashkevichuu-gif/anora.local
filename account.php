<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';
requireLogin();

$userId = $_SESSION['user_id'];
$user   = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$user->execute([$userId]);
$user = $user->fetch();

$msg  = '';
$type = 'success';

// Save bank details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bank'])) {
    $bank = trim($_POST['bank_details'] ?? '');
    $pdo->prepare('UPDATE users SET bank_details = ? WHERE id = ?')->execute([$bank, $userId]);
    header('Location: /account.php?saved=1');
    exit;
}

if (isset($_GET['saved'])) { $msg = 'Bank details saved.'; $type = 'success'; }

$transactions = $pdo->prepare(
    'SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC'
);
$transactions->execute([$userId]);
$transactions = $transactions->fetchAll();

$pageTitle = 'My Account';
include 'includes/header.php';
?>
<div class="container">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $type ?> mt-2"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Balance card -->
    <div class="balance-card card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <div class="text-white-50 small">Current Balance</div>
                <div class="amount">$<?= number_format($user['balance'], 2) ?></div>
            </div>
            <i class="bi bi-wallet2 fs-1 opacity-50"></i>
        </div>
        <div class="mt-2 text-white-50 small"><?= htmlspecialchars($user['email']) ?></div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="accountTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-deposit">
                <i class="bi bi-plus-circle"></i> Deposit
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-withdraw">
                <i class="bi bi-arrow-up-circle"></i> Withdraw
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bank">
                <i class="bi bi-building"></i> Bank Details
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history">
                <i class="bi bi-clock-history"></i> History
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Deposit -->
        <div class="tab-pane fade show active" id="tab-deposit">
            <div class="card p-4" style="max-width:480px">
                <h5 class="mb-3">Deposit Funds</h5>
                <div id="depositMsg"></div>
                <form id="depositForm">
                    <div class="mb-3">
                        <label class="form-label">Amount ($)</label>
                        <input type="number" id="depositAmount" class="form-control" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Card Number</label>
                        <input type="text" id="cardNumber" class="form-control" maxlength="19" placeholder="1234 5678 9012 3456" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label">Expiry</label>
                            <input type="text" id="cardExpiry" class="form-control" placeholder="MM/YY" maxlength="5" required>
                        </div>
                        <div class="col">
                            <label class="form-label">CVV</label>
                            <input type="password" id="cardCvv" class="form-control" maxlength="4" placeholder="•••" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Deposit</button>
                </form>
            </div>
        </div>

        <!-- Withdraw -->
        <div class="tab-pane fade" id="tab-withdraw">
            <div class="card p-4" style="max-width:480px">
                <h5 class="mb-3">Request Withdrawal</h5>
                <p class="text-muted small">Available: <strong>$<?= number_format($user['balance'], 2) ?></strong></p>
                <div id="withdrawMsg"></div>
                <form id="withdrawForm">
                    <div class="mb-3">
                        <label class="form-label">Amount ($)</label>
                        <input type="number" id="withdrawAmount" class="form-control"
                               min="0.01" step="0.01" max="<?= $user['balance'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bank Details for this withdrawal</label>
                        <textarea id="withdrawBank" class="form-control" rows="3"
                                  placeholder="Account number, routing number, bank name…"><?= htmlspecialchars($user['bank_details'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">Request Withdrawal</button>
                </form>
            </div>
        </div>

        <!-- Bank Details -->
        <div class="tab-pane fade" id="tab-bank">
            <div class="card p-4" style="max-width:480px">
                <h5 class="mb-3">Bank Details</h5>
                <p class="text-muted small">Saved bank details will be pre-filled in withdrawal requests.</p>
                <form method="post">
                    <input type="hidden" name="save_bank" value="1">
                    <div class="mb-3">
                        <label class="form-label">Bank Requisites</label>
                        <textarea name="bank_details" class="form-control" rows="5"
                                  placeholder="Account number, routing number, bank name, SWIFT/IBAN…"><?= htmlspecialchars($user['bank_details'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-secondary w-100">Save</button>
                </form>
            </div>
        </div>

        <!-- History -->
        <div class="tab-pane fade" id="tab-history">
            <div class="card p-3">
                <h5 class="mb-3">Transaction History</h5>
                <?php if (empty($transactions)): ?>
                    <p class="text-muted">No transactions yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Note</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td><?= $tx['id'] ?></td>
                                <td>
                                    <?php if ($tx['type'] === 'deposit'): ?>
                                        <span class="badge bg-success">Deposit</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Withdrawal</span>
                                    <?php endif; ?>
                                </td>
                                <td>$<?= number_format($tx['amount'], 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= $tx['status'] ?>">
                                        <?= ucfirst($tx['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($tx['note'] ?? '') ?></td>
                                <td><?= $tx['created_at'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Card number formatting
document.getElementById('cardNumber').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'').replace(/(.{4})/g,'$1 ').trim().slice(0,19);
});
document.getElementById('cardExpiry').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'').replace(/^(\d{2})(\d)/,'$1/$2').slice(0,5);
});

// Deposit
document.getElementById('depositForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const amount = document.getElementById('depositAmount').value;
    const res = await fetch('/api/deposit.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ amount })
    });
    const data = await res.json();
    const el = document.getElementById('depositMsg');
    el.innerHTML = `<div class="alert alert-${data.ok ? 'success' : 'danger'}">${data.message}</div>`;
    if (data.ok) {
        document.querySelector('.amount').textContent = '$' + parseFloat(data.balance).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        this.reset();
    }
});

// Withdraw
document.getElementById('withdrawForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const amount = document.getElementById('withdrawAmount').value;
    const bank   = document.getElementById('withdrawBank').value;
    const res = await fetch('/api/withdraw.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ amount, bank_details: bank })
    });
    const data = await res.json();
    const el = document.getElementById('withdrawMsg');
    el.innerHTML = `<div class="alert alert-${data.ok ? 'success' : 'danger'}">${data.message}</div>`;
    if (data.ok) this.reset();
});
</script>
<?php include 'includes/footer.php'; ?>
