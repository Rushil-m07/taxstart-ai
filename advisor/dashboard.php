<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdvisor();

$advisor = currentUser();
require_once __DIR__ . '/../includes/credits_helper.php';
initWelcomeOffer($pdo, $advisor['id']);
$cs = getCreditSummary($pdo, $advisor['id']);

// ── Stats for this advisor ──────────────────────────
$total_clients = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE advisor_id = ?");
$total_clients->execute([$advisor['id']]);
$total_clients = $total_clients->fetchColumn();

$total_files = $pdo->prepare("SELECT COUNT(*) FROM uploaded_files WHERE advisor_id = ?");
$total_files->execute([$advisor['id']]);
$total_files = $total_files->fetchColumn();

$total_reports = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE advisor_id = ?");
$total_reports->execute([$advisor['id']]);
$total_reports = $total_reports->fetchColumn();

$pending_files = $pdo->prepare("
    SELECT COUNT(*) FROM uploaded_files
    WHERE advisor_id = ? AND status = 'pending'
");
$pending_files->execute([$advisor['id']]);
$pending_files = $pending_files->fetchColumn();

// ── Recent Clients ──────────────────────────────────
$recent_clients = $pdo->prepare("
    SELECT * FROM clients
    WHERE advisor_id = ?
    ORDER BY created_at DESC
    LIMIT 6
");
$recent_clients->execute([$advisor['id']]);
$recent_clients = $recent_clients->fetchAll();

// ── Recent Uploaded Files ───────────────────────────
$recent_files = $pdo->prepare("
    SELECT uf.*, c.full_name AS client_name
    FROM uploaded_files uf
    JOIN clients c ON uf.client_id = c.client_id
    WHERE uf.advisor_id = ?
    ORDER BY uf.upload_time DESC
    LIMIT 5
");
$recent_files->execute([$advisor['id']]);
$recent_files = $recent_files->fetchAll();

// ── Advisor Profile ─────────────────────────────────
$profile = $pdo->prepare("
    SELECT * FROM advisor_profiles WHERE user_id = ?
");
$profile->execute([$advisor['id']]);
$profile = $profile->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advisor Dashboard – TaxStart AI</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            display: flex;
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
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

        .sidebar-brand h2 {
            font-size: 20px;
            font-weight: 700;
        }

        .sidebar-brand span {
            font-size: 11px;
            opacity: 0.6;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .sidebar-user {
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #2ecc71, #1a73e8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: white;
        }

        .sidebar-user .info small {
            display: block;
            font-size: 11px;
            opacity: 0.6;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sidebar-user .info strong {
            font-size: 14px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 0;
        }

        .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            opacity: 0.45;
            padding: 10px 24px 6px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 24px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }

        .nav-item:hover,
        .nav-item.active {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border-left-color: #2ecc71;
        }

        .nav-item .icon { font-size: 18px; }

        .sidebar-footer {
            padding: 20px 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .btn-logout {
            display: block;
            width: 100%;
            padding: 11px;
            background: rgba(233,69,96,0.2);
            border: 1px solid rgba(233,69,96,0.4);
            color: #e94560;
            border-radius: 8px;
            text-align: center;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }

        .btn-logout:hover { background: rgba(233,69,96,0.35); }

        /* ── MAIN ── */
        .main {
            margin-left: 260px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            background: #fff;
            padding: 18px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .topbar .date {
            font-size: 13px;
            color: #888;
        }

        .page-body {
            padding: 30px 32px;
            flex: 1;
        }

        /* WELCOME BANNER */
        .welcome-banner {
            background: linear-gradient(135deg, #0f3460, #1a73e8);
            border-radius: 16px;
            padding: 28px 32px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .welcome-banner h2 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .welcome-banner p {
            font-size: 14px;
            opacity: 0.85;
        }

        .welcome-banner .spec-badge {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 30px;
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 600;
        }

        /* STAT CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 22px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: transform 0.2s;
        }

        .stat-card:hover { transform: translateY(-3px); }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .icon-blue   { background: #e8f0fe; }
        .icon-green  { background: #e6f9f0; }
        .icon-orange { background: #fff3e0; }
        .icon-red    { background: #fdecea; }

        .stat-info h3 {
            font-size: 26px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-info p {
            font-size: 13px;
            color: #888;
        }

        /* TWO COL */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 24px;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title a {
            font-size: 13px;
            font-weight: 600;
            color: #1a73e8;
            text-decoration: none;
        }

        .section-title a:hover { text-decoration: underline; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        thead th {
            text-align: left;
            padding: 10px 12px;
            font-size: 12px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #f0f0f0;
        }

        tbody tr {
            border-bottom: 1px solid #f7f7f7;
            transition: background 0.15s;
        }

        tbody tr:hover { background: #fafafa; }

        tbody td {
            padding: 12px 12px;
            color: #333;
            vertical-align: middle;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-pending  { background: #fff3e0; color: #e67e22; }
        .badge-analyzed { background: #e6f9f0; color: #1e8449; }
        .badge-failed   { background: #fdecea; color: #c0392b; }

        /* QUICK ACTIONS */
        .quick-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: transform 0.2s, opacity 0.2s;
        }

        .action-btn:hover {
            transform: translateX(4px);
            opacity: 0.9;
        }

        .action-btn .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .action-btn .action-text small {
            display: block;
            font-size: 12px;
            font-weight: 400;
            opacity: 0.75;
            margin-top: 2px;
        }

        .btn-add-client {
            background: #e8f0fe;
            color: #1a73e8;
        }

        .btn-upload {
            background: #e6f9f0;
            color: #1e8449;
        }

        .btn-reports {
            background: #f3e8ff;
            color: #7b2d8b;
        }

        .empty-state {
            text-align: center;
            padding: 30px 0;
            color: #bbb;
            font-size: 14px;
        }

        .empty-state .empty-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
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
        <a href="dashboard.php" class="nav-item active">
            <span class="icon">📊</span> Dashboard
        </a>
        <a href="clients.php" class="nav-item">
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
		<a href="subscription.php" class="nav-item">
    <span class="icon">💳</span> Subscription
		</a>

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
                <?= $cs['silver_available'] ?> left
            </span>
        </div>
        <div style="background:rgba(255,255,255,0.1);
                    border-radius:4px;height:5px;">
            <div style="height:100%;border-radius:4px;
                        background:#9e9e9e;
                        width:<?= $cs['silver_total'] > 0
                            ? min(round(($cs['silver_available']
                            / $cs['silver_total'])*100),100) : 0 ?>%;">
            </div>
        </div>
    </div>
    <div style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;
                    font-size:12px;margin-bottom:4px;">
            <span style="opacity:0.75;">🥇 Gold</span>
            <span style="font-weight:700;">
                <?= $cs['gold_available'] ?> left
            </span>
        </div>
        <div style="background:rgba(255,255,255,0.1);
                    border-radius:4px;height:5px;">
            <div style="height:100%;border-radius:4px;
                        background:#f5c518;
                        width:<?= $cs['gold_total'] > 0
                            ? min(round(($cs['gold_available']
                            / $cs['gold_total'])*100),100) : 0 ?>%;">
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

<!-- ══ MAIN ══ -->
<div class="main">

    <div class="topbar">
        <h1>Advisor Dashboard</h1>
        <span class="date">📅 <?= date('l, F j, Y') ?></span>
    </div>

    <div class="page-body">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div>
                <h2>Welcome back, <?= sanitize($advisor['name']) ?>! 👋</h2>
                <p>Here's an overview of your activity on TaxStart AI.</p>
            </div>
            <?php if ($profile && $profile['specialization']): ?>
                <div class="spec-badge">
                    💼 <?= sanitize($profile['specialization']) ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- STAT CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue">🧑‍💼</div>
                <div class="stat-info">
                    <h3><?= $total_clients ?></h3>
                    <p>My Clients</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-orange">📁</div>
                <div class="stat-info">
                    <h3><?= $total_files ?></h3>
                    <p>Files Uploaded</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green">📄</div>
                <div class="stat-info">
                    <h3><?= $total_reports ?></h3>
                    <p>Reports Generated</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red">⏳</div>
                <div class="stat-info">
                    <h3><?= $pending_files ?></h3>
                    <p>Pending Analysis</p>
                </div>
            </div>
        </div>

        <!-- TWO COL -->
        <div class="two-col">

            <!-- RECENT CLIENTS TABLE -->
            <div class="card">
                <div class="section-title">
                    🧑‍💼 Recent Clients
                    <a href="clients.php">View All →</a>
                </div>

                <?php if (empty($recent_clients)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🧑‍💼</div>
                        <p>No clients added yet.<br>
                        <a href="clients.php" style="color:#1a73e8;">Add your first client →</a></p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Client Name</th>
                            <th>Email</th>
                            <th>Tax Year</th>
                            <th>Added</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_clients as $c): ?>
                        <tr>
                            <td><strong><?= sanitize($c['full_name']) ?></strong></td>
                            <td><?= sanitize($c['email'] ?: '—') ?></td>
                            <td><?= $c['tax_year'] ?: '—' ?></td>
                            <td><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN -->
            <div style="display:flex; flex-direction:column; gap:24px;">

                <!-- QUICK ACTIONS -->
                <div class="card">
                    <div class="section-title">⚡ Quick Actions</div>
                    <div class="quick-actions">
                        <a href="clients.php?action=add" class="action-btn btn-add-client">
                            <div class="action-icon">➕</div>
                            <div class="action-text">
                                Add New Client
                                <small>Register a new tax client</small>
                            </div>
                        </a>
                        <a href="upload.php" class="action-btn btn-upload">
                            <div class="action-icon">📤</div>
                            <div class="action-text">
                                Upload & Analyze
                                <small>Upload files for AI analysis</small>
                            </div>
                        </a>
                        <a href="reports.php" class="action-btn btn-reports">
                            <div class="action-icon">📄</div>
                            <div class="action-text">
                                View Reports
                                <small>See all generated reports</small>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- RECENT FILES -->
                <div class="card">
                    <div class="section-title">
                        📁 Recent Uploads
                        <a href="upload.php">View All →</a>
                    </div>
                    <?php if (empty($recent_files)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📁</div>
                            <p>No files uploaded yet.</p>
                        </div>
                    <?php else: ?>
                    <?php foreach ($recent_files as $f): ?>
                        <div style="display:flex; align-items:center;
                                    justify-content:space-between;
                                    padding: 10px 0;
                                    border-bottom: 1px solid #f5f5f5;">
                            <div>
                                <strong style="font-size:13px;">
                                    <?= sanitize($f['file_name']) ?>
                                </strong><br>
                                <small style="color:#aaa;">
                                    <?= sanitize($f['client_name']) ?> &nbsp;·&nbsp;
                                    <?= date('M d', strtotime($f['upload_time'])) ?>
                                </small>
                            </div>
                            <span class="badge badge-<?= $f['status'] ?>">
                                <?= ucfirst($f['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

</body>
</html>