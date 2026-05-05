<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdvisor();

$advisor = currentUser();
$error   = '';
$success = '';
require_once __DIR__ . '/../includes/credits_helper.php';
initWelcomeOffer($pdo, $advisor['id']);
$credits_summary = getCreditSummary($pdo, $advisor['id']);

// ── ADD CLIENT ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_client'])) {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email']     ?? '');
    $phone     = sanitize($_POST['phone']     ?? '');
    $sin       = sanitize($_POST['sin']       ?? '');
    $tax_year  = (int)($_POST['tax_year']     ?? date('Y'));
    $tax_type  = ($_POST['tax_type'] ?? 'domestic') === 'international' ? 'international' : 'domestic';
    $notes     = sanitize($_POST['notes']     ?? '');

    // Determine which credit tier is needed
    $required_tier = ($tax_type === 'international') ? 'gold' : 'silver';
    $tier_label    = ($tax_type === 'international') ? 'Gold' : 'Silver';

    if (empty($full_name)) {
        $error = "Client full name is required.";
    } elseif (getAvailableCredits($pdo, $advisor['id'], $required_tier) <= 0) {
        $error = "You need at least 1 $tier_label credit to add a "
               . ($tax_type === 'international' ? 'international' : 'Canadian')
               . " client. <a href='subscription.php' style='color:#1a73e8;font-weight:600;'>Buy Credits →</a>";
    } else {
        // Store only last 3 digits of SIN for reference
        $sin_masked = !empty($sin) ? 'XXX-XXX-' . substr(preg_replace('/\D/', '', $sin), -3) : '';
        $expires_at = date('Y-m-d', strtotime('+1 year'));

        $stmt = $pdo->prepare("
            INSERT INTO clients
                (advisor_id, full_name, email, phone, sin_masked,
                 tax_year, tax_type, credit_used, credit_expires_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->execute([
            $advisor['id'], $full_name, $email,
            $phone, $sin_masked, $tax_year,
            $tax_type, $expires_at, $notes
        ]);

        $new_client_id = $pdo->lastInsertId();

        // Deduct 1 credit
        useCredit($pdo, $advisor['id'], $required_tier,
            'ADD_CLIENT', $new_client_id);

        logActivity($pdo, $advisor['id'], 'ADD_CLIENT',
            "Added $tax_type client: $full_name (1 $tier_label credit used, expires $expires_at)");
        $success = "Client \"$full_name\" added! 1 $tier_label credit used — valid until $expires_at.";

        // Refresh credit summary
        $credits_summary = getCreditSummary($pdo, $advisor['id']);
    }
}

// ── DELETE CLIENT ───────────────────────────────────
if (isset($_GET['delete'])) {
    $cid  = (int)$_GET['delete'];
    $stmt = $pdo->prepare("
        SELECT client_id FROM clients
        WHERE client_id = ? AND advisor_id = ?
    ");
    $stmt->execute([$cid, $advisor['id']]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM clients WHERE client_id = ?")
            ->execute([$cid]);
        logActivity($pdo, $advisor['id'], 'DELETE_CLIENT',
            "Deleted client ID: $cid");
        $success = "Client removed successfully.";
    }
}

// ── SEARCH & FETCH CLIENTS ──────────────────────────
$search = sanitize($_GET['search'] ?? '');
$show_add = isset($_GET['action']) && $_GET['action'] === 'add';

if (!empty($search)) {
    $stmt = $pdo->prepare("
        SELECT * FROM clients
        WHERE advisor_id = ?
          AND (full_name LIKE ? OR email LIKE ? OR tax_year LIKE ?)
        ORDER BY created_at DESC
    ");
    $like = "%$search%";
    $stmt->execute([$advisor['id'], $like, $like, $like]);
} else {
    $stmt = $pdo->prepare("
        SELECT * FROM clients
        WHERE advisor_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$advisor['id']]);
}
$clients = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Clients – TaxStart AI</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #1a1a2e 0%, #0f3460 100%);
            color: white;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 100;
        }

        .sidebar-brand {
            padding: 28px 24px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand h2 { font-size:20px; font-weight:700; }
        .sidebar-brand span {
            font-size:11px; opacity:0.6;
            letter-spacing:1px; text-transform:uppercase;
        }

        .sidebar-user {
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .avatar {
            width:42px; height:42px;
            background: linear-gradient(135deg, #2ecc71, #1a73e8);
            border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:18px; font-weight:700; color:white;
        }

        .sidebar-user .info small {
            display:block; font-size:11px;
            opacity:0.6; text-transform:uppercase; letter-spacing:0.5px;
        }

        .sidebar-user .info strong { font-size:14px; }

        .sidebar-nav { flex:1; padding:20px 0; }

        .nav-label {
            font-size:10px; text-transform:uppercase;
            letter-spacing:1.5px; opacity:0.45;
            padding:10px 24px 6px;
        }

        .nav-item {
            display:flex; align-items:center; gap:12px;
            padding:13px 24px;
            color:rgba(255,255,255,0.75);
            text-decoration:none;
            font-size:14px; font-weight:500;
            transition:all 0.2s;
            border-left:3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background:rgba(255,255,255,0.08);
            color:#fff; border-left-color:#2ecc71;
        }

        .nav-item .icon { font-size:18px; }

        .sidebar-footer {
            padding:20px 24px;
            border-top:1px solid rgba(255,255,255,0.1);
        }

        .btn-logout {
            display:block; width:100%; padding:11px;
            background:rgba(233,69,96,0.2);
            border:1px solid rgba(233,69,96,0.4);
            color:#e94560; border-radius:8px;
            text-align:center; text-decoration:none;
            font-size:14px; font-weight:600;
            transition:background 0.2s;
        }

        .btn-logout:hover { background:rgba(233,69,96,0.35); }

        /* MAIN */
        .main {
            margin-left:260px; flex:1;
            display:flex; flex-direction:column;
        }

        .topbar {
            background:#fff; padding:18px 32px;
            display:flex; align-items:center;
            justify-content:space-between;
            box-shadow:0 2px 8px rgba(0,0,0,0.06);
            position:sticky; top:0; z-index:50;
        }

        .topbar h1 { font-size:20px; font-weight:700; color:#1a1a2e; }

        .btn-primary {
            padding:10px 20px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; border:none; border-radius:10px;
            font-size:14px; font-weight:600;
            cursor:pointer; text-decoration:none;
            transition:opacity 0.2s;
        }

        .btn-primary:hover { opacity:0.9; }

        .page-body { padding:30px 32px; flex:1; }

        /* ALERTS */
        .alert {
            padding:13px 18px; border-radius:10px;
            font-size:14px; margin-bottom:20px;
        }

        .alert-success {
            background:#f0fff4;
            border-left:4px solid #2ecc71;
            color:#1e8449;
        }

        .alert-error {
            background:#fff0f0;
            border-left:4px solid #e94560;
            color:#c0392b;
        }

        /* ADD CLIENT FORM */
        .add-form-card {
            background:#fff; border-radius:14px;
            padding:28px 30px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            margin-bottom:28px;
        }

        .add-form-card h3 {
            font-size:16px; font-weight:700;
            color:#1a1a2e; margin-bottom:20px;
        }

        .form-row {
            display:flex; gap:18px; margin-bottom:16px;
        }

        .form-group {
            flex:1; display:flex; flex-direction:column;
        }

        .form-group.full {
            width:100%; margin-bottom:16px;
        }

        .form-group label {
            font-size:12px; font-weight:700;
            color:#555; margin-bottom:7px;
            text-transform:uppercase; letter-spacing:0.5px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding:11px 14px;
            border:2px solid #e8e8e8;
            border-radius:10px;
            font-size:14px; color:#333;
            outline:none;
            transition:border-color 0.3s;
            font-family:inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color:#0f3460;
        }

        .form-group textarea { resize:vertical; min-height:80px; }

        .form-actions {
            display:flex; gap:12px; margin-top:6px;
        }

        .btn-cancel {
            padding:11px 22px;
            background:#f0f0f0; color:#555;
            border:none; border-radius:10px;
            font-size:14px; font-weight:600;
            cursor:pointer; text-decoration:none;
            transition:background 0.2s;
        }

        .btn-cancel:hover { background:#e0e0e0; }

        /* SEARCH BAR */
        .search-bar {
            display:flex; gap:12px;
            margin-bottom:20px; align-items:center;
        }

        .search-bar input {
            flex:1; padding:11px 16px;
            border:2px solid #e8e8e8;
            border-radius:10px; font-size:14px;
            outline:none; transition:border-color 0.3s;
        }

        .search-bar input:focus { border-color:#0f3460; }

        .btn-search {
            padding:11px 22px;
            background:#0f3460; color:#fff;
            border:none; border-radius:10px;
            font-size:14px; font-weight:600;
            cursor:pointer;
        }

        .btn-clear {
            padding:11px 18px;
            background:#f0f0f0; color:#555;
            border:none; border-radius:10px;
            font-size:14px; font-weight:600;
            cursor:pointer; text-decoration:none;
        }

        /* CLIENTS TABLE */
        .card {
            background:#fff; border-radius:14px;
            padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size:16px; font-weight:700;
            color:#1a1a2e; margin-bottom:16px;
            display:flex; align-items:center;
            justify-content:space-between;
        }

        .client-count {
            font-size:13px; font-weight:600;
            color:#888; background:#f5f5f5;
            padding:4px 12px; border-radius:20px;
        }

        table {
            width:100%; border-collapse:collapse; font-size:14px;
        }

        thead th {
            text-align:left; padding:10px 14px;
            font-size:12px; font-weight:700;
            color:#888; text-transform:uppercase;
            letter-spacing:0.5px;
            border-bottom:2px solid #f0f0f0;
        }

        tbody tr {
            border-bottom:1px solid #f7f7f7;
            transition:background 0.15s;
        }

        tbody tr:hover { background:#fafafa; }

        tbody td {
            padding:13px 14px;
            color:#333; vertical-align:middle;
        }

        .btn-action {
            padding:6px 14px;
            border:none; border-radius:7px;
            font-size:12px; font-weight:600;
            cursor:pointer; text-decoration:none;
            display:inline-block;
            transition:opacity 0.2s;
        }

        .btn-action:hover { opacity:0.8; }

        .btn-upload-file {
            background:#e8f0fe; color:#1a73e8;
        }

        .btn-delete {
            background:#fdecea; color:#c0392b;
        }

        .empty-state {
            text-align:center; padding:50px 0;
            color:#bbb;
        }

        .empty-state .empty-icon { font-size:50px; margin-bottom:12px; }
        .empty-state p { font-size:15px; }
        .empty-state a { color:#1a73e8; font-weight:600; text-decoration:none; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <h2>🧾 TaxStart AI</h2>
        <span>ABC Tech Ltd.</span>
    </div>

    <div class="sidebar-user">
        <div class="avatar"><?= strtoupper(substr($advisor['name'], 0, 1)) ?></div>
        <div class="info">
            <small>Tax Advisor</small>
            <strong><?= sanitize($advisor['name']) ?></strong>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="nav-item">
            <span class="icon">📊</span> Dashboard
        </a>
        <a href="clients.php" class="nav-item active">
            <span class="icon">🧑‍💼</span> My Clients
        </a>
        <a href="upload.php" class="nav-item">
            <span class="icon">📁</span> Upload Files
        </a>
        <a href="reports.php" class="nav-item">
            <span class="icon">📄</span> Reports
        </a>
        <a href="compare.php" class="nav-item">
    <span class="icon">⚖️</span> Compare Scenarios
		</a>
		<a href="calculator.php" class="nav-item">
    <span class="icon">🧮</span> Tax Calculator
		</a>
        <?php

?>
<div style="padding:14px 24px;margin-top:10px;
            border-top:1px solid rgba(255,255,255,0.1);">
    <div style="font-size:10px;text-transform:uppercase;
                letter-spacing:1px;opacity:0.5;margin-bottom:10px;">
        <?= date('Y') ?> Credits
    </div>
    <div style="margin-bottom:8px;">
        <div style="display:flex;justify-content:space-between;
                    font-size:12px;margin-bottom:4px;">
            <span style="opacity:0.75;">🥈 Silver</span>
            <span style="font-weight:700;">
                <?= $credits_summary['silver_available'] ?> left
            </span>
        </div>
        <div style="background:rgba(255,255,255,0.1);
                    border-radius:4px;height:5px;">
            <div style="height:100%;border-radius:4px;
                        background:#9e9e9e;
                        width:<?= $credits_summary['silver_total'] > 0
                            ? min(round(($credits_summary['silver_available']
                            / $credits_summary['silver_total'])*100),100) : 0 ?>%;">
            </div>
        </div>
    </div>
    <div style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;
                    font-size:12px;margin-bottom:4px;">
            <span style="opacity:0.75;">🥇 Gold</span>
            <span style="font-weight:700;">
                <?= $credits_summary['gold_available'] ?> left
            </span>
        </div>
        <div style="background:rgba(255,255,255,0.1);
                    border-radius:4px;height:5px;">
            <div style="height:100%;border-radius:4px;
                        background:#f5c518;
                        width:<?= $credits_summary['gold_total'] > 0
                            ? min(round(($credits_summary['gold_available']
                            / $credits_summary['gold_total'])*100),100) : 0 ?>%;">
            </div>
        </div>
    </div>
    <a href="<?= basename(__DIR__) === 'advisor'
        ? '' : '../advisor/' ?>subscription.php"
       style="display:block;text-align:center;font-size:11px;
              color:rgba(255,255,255,0.6);text-decoration:none;">
        + Buy Credits
    </a>
</div>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="btn-logout">🚪 Logout</a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">

    <div class="topbar">
        <h1>🧑‍💼 My Clients</h1>
        <a href="clients.php?action=add" class="btn-primary">➕ Add New Client</a>
    </div>

    <div class="page-body">

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <!-- ADD CLIENT FORM -->
        <?php if ($show_add || !empty($error)): ?>
        <div class="add-form-card">
            <h3>➕ Add New Client</h3>
            <form method="POST" action="clients.php">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name"
                               placeholder="e.g. John Smith"
                               value="<?= sanitize($_POST['full_name'] ?? '') ?>"
                               required>
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email"
                               placeholder="client@email.com"
                               value="<?= sanitize($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone"
                               placeholder="+1 604-000-0000"
                               value="<?= sanitize($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>SIN (last 3 digits stored only)</label>
                        <input type="text" name="sin"
                               placeholder="XXX-XXX-XXX"
                               maxlength="11">
                    </div>
                    <div class="form-group">
                        <label>Tax Year</label>
                        <select name="tax_year">
                            <?php for ($y = date('Y'); $y >= 2018; $y--): ?>
                                <option value="<?= $y ?>"
                                    <?= (($_POST['tax_year'] ?? date('Y')) == $y) ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Tax Type *</label>
                        <select name="tax_type" id="tax_type_select" style="font-size:14px;">
                            <option value="domestic"
                                <?= (($_POST['tax_type'] ?? '') !== 'international') ? 'selected' : '' ?>>
                                🇨🇦 Canada (uses 1 Silver credit)
                            </option>
                            <option value="international"
                                <?= (($_POST['tax_type'] ?? '') === 'international') ? 'selected' : '' ?>>
                                🌍 International (uses 1 Gold credit)
                            </option>
                        </select>
                    </div>
                    <div class="form-group" style="justify-content:flex-end;">
                        <div id="credit_check" style="padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;">
                        </div>
                    </div>
                </div>

                <script>
                function updateCreditCheck() {
                    var sel = document.getElementById('tax_type_select');
                    var box = document.getElementById('credit_check');
                    var silverLeft = <?= $credits_summary['silver_available'] ?>;
                    var goldLeft = <?= $credits_summary['gold_available'] ?>;

                    if (sel.value === 'international') {
                        if (goldLeft > 0) {
                            box.innerHTML = '🥇 ' + goldLeft + ' Gold credit(s) available';
                            box.style.background = '#fffdf0';
                            box.style.color = '#8a6500';
                        } else {
                            box.innerHTML = '⚠️ No Gold credits — <a href="subscription.php" style="color:#1a73e8;">Buy Credits</a>';
                            box.style.background = '#fff0f0';
                            box.style.color = '#c0392b';
                        }
                    } else {
                        if (silverLeft > 0) {
                            box.innerHTML = '🥈 ' + silverLeft + ' Silver credit(s) available';
                            box.style.background = '#f8f9fa';
                            box.style.color = '#555';
                        } else {
                            box.innerHTML = '⚠️ No Silver credits — <a href="subscription.php" style="color:#1a73e8;">Buy Credits</a>';
                            box.style.background = '#fff0f0';
                            box.style.color = '#c0392b';
                        }
                    }
                }
                document.getElementById('tax_type_select').addEventListener('change', updateCreditCheck);
                updateCreditCheck();
                </script>

                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes"
                              placeholder="Any additional notes about this client..."
                              ><?= sanitize($_POST['notes'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" name="add_client" class="btn-primary">
                        ✅ Save Client
                    </button>
                    <a href="clients.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- SEARCH BAR -->
        <form method="GET" action="clients.php">
            <div class="search-bar">
                <input type="text" name="search"
                       placeholder="🔍  Search by name, email or tax year..."
                       value="<?= sanitize($search) ?>">
                <button type="submit" class="btn-search">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="clients.php" class="btn-clear">✕ Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- CLIENTS TABLE -->
        <div class="card">
            <div class="section-title">
                📋 Client List
                <span class="client-count"><?= count($clients) ?> client<?= count($clients) !== 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🧑‍💼</div>
                    <p>
                        <?= !empty($search) ? "No clients found for \"$search\"." : "No clients yet." ?>
                        <?php if (empty($search)): ?>
                            <br><a href="clients.php?action=add">Add your first client →</a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client Name</th>
                        <th>Email</th>
                        <th>Tax Type</th>
                        <th>Credit Status</th>
                        <th>Tax Year</th>
                        <th>Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $i => $c):
                    $is_intl = ($c['tax_type'] ?? 'domestic') === 'international';
                    $has_credit = !empty($c['credit_expires_at'])
                        && strtotime($c['credit_expires_at']) >= strtotime('today');
                    $is_expired = !empty($c['credit_expires_at'])
                        && strtotime($c['credit_expires_at']) < strtotime('today');
                ?>
                    <tr>
                        <td style="color:#bbb;"><?= $i + 1 ?></td>
                        <td>
                            <strong><?= sanitize($c['full_name']) ?></strong>
                            <?php if ($c['notes']): ?>
                                <br><small style="color:#aaa;" title="<?= sanitize($c['notes']) ?>">
                                    📝 <?= substr(sanitize($c['notes']), 0, 30) ?>...
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($c['email'] ?: '—') ?></td>
                        <td>
                            <?php if ($is_intl): ?>
                                <span style="background:#fffdf0;color:#8a6500;padding:3px 10px;
                                    border-radius:20px;font-size:11px;font-weight:700;">
                                    🌍 International
                                </span>
                            <?php else: ?>
                                <span style="background:#f0f7ff;color:#0f3460;padding:3px 10px;
                                    border-radius:20px;font-size:11px;font-weight:700;">
                                    🇨🇦 Canada
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($has_credit): ?>
                                <span style="background:#f0fff4;color:#1e8449;padding:3px 10px;
                                    border-radius:20px;font-size:11px;font-weight:700;">
                                    ✅ Active until <?= date('M Y', strtotime($c['credit_expires_at'])) ?>
                                </span>
                            <?php elseif ($is_expired): ?>
                                <span style="background:#fff0f0;color:#c0392b;padding:3px 10px;
                                    border-radius:20px;font-size:11px;font-weight:700;">
                                    ⚠️ Expired
                                </span>
                                <a href="subscription.php" style="font-size:11px;color:#1a73e8;
                                    font-weight:600;margin-left:4px;">Renew</a>
                            <?php else: ?>
                                <span style="color:#bbb;font-size:11px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $c['tax_year'] ?: '—' ?></td>
                        <td><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                        <td>
                            <a href="upload.php?client_id=<?= $c['client_id'] ?>"
                               class="btn-action btn-upload-file">📤 Upload</a>
                            &nbsp;
                            <a href="clients.php?delete=<?= $c['client_id'] ?>"
                               class="btn-action btn-delete"
                               onclick="return confirm('Delete this client and all their files?')">
                               🗑️ Delete
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>