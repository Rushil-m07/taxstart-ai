<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

// ── Stats ──────────────────────────────────────────
$total_advisors  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='advisor'")->fetchColumn();
$total_clients   = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$total_files     = $pdo->query("SELECT COUNT(*) FROM uploaded_files")->fetchColumn();
$total_reports   = $pdo->query("SELECT COUNT(*) FROM reports")->fetchColumn();

// ── Recent Advisors ────────────────────────────────
$advisors = $pdo->query("
    SELECT u.user_id, u.full_name, u.email, u.phone, u.is_active, u.last_login,
           ap.specialization, ap.years_experience,
           COUNT(DISTINCT c.client_id) AS client_count
    FROM users u
    LEFT JOIN advisor_profiles ap ON u.user_id = ap.user_id
    LEFT JOIN clients c           ON u.user_id = c.advisor_id
    WHERE u.role = 'advisor'
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
")->fetchAll();

// ── Recent Activity Logs ───────────────────────────
$logs = $pdo->query("
    SELECT al.action, al.description, al.logged_at, u.full_name
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    ORDER BY al.logged_at DESC
    LIMIT 8
")->fetchAll();

$admin = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard – TaxStart AI</title>
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
            letter-spacing: 0.5px;
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
            background: linear-gradient(135deg, #e94560, #f5a623);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
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
            border-left-color: #e94560;
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

        /* ── MAIN CONTENT ── */
        .main {
            margin-left: 260px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* TOP BAR */
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

        /* PAGE BODY */
        .page-body {
            padding: 30px 32px;
            flex: 1;
        }

        /* STAT CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 24px 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            transition: transform 0.2s;
        }

        .stat-card:hover { transform: translateY(-3px); }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .icon-blue   { background: #e8f0fe; }
        .icon-green  { background: #e6f9f0; }
        .icon-orange { background: #fff3e0; }
        .icon-purple { background: #f3e8ff; }

        .stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-info p {
            font-size: 13px;
            color: #888;
        }

        /* SECTION TITLES */
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* TWO COLUMN LAYOUT */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
        }

        /* TABLE CARD */
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        thead th {
            text-align: left;
            padding: 10px 14px;
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
            padding: 12px 14px;
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

        .badge-active   { background: #e6f9f0; color: #1e8449; }
        .badge-inactive { background: #fdecea; color: #c0392b; }
        .badge-login    { background: #e8f0fe; color: #1a73e8; }
        .badge-logout   { background: #fef9e7; color: #d68910; }
        .badge-signup   { background: #f3e8ff; color: #7b2d8b; }

        /* ACTIVITY LOG */
        .log-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #f5f5f5;
        }

        .log-item:last-child { border-bottom: none; }

        .log-dot {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .log-info strong {
            font-size: 13px;
            color: #1a1a2e;
            display: block;
        }

        .log-info small {
            font-size: 12px;
            color: #999;
        }

        .toggle-btn {
            padding: 5px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .toggle-btn:hover { opacity: 0.8; }

        .btn-deactivate { background: #fdecea; color: #c0392b; }
        .btn-activate   { background: #e6f9f0; color: #1e8449; }
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
        <div class="avatar"><?= strtoupper(substr($admin['name'], 0, 1)) ?></div>
        <div class="info">
            <small>Administrator</small>
            <strong><?= sanitize($admin['name']) ?></strong>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="nav-item active">
            <span class="icon">📊</span> Dashboard
        </a>
        <a href="advisors.php" class="nav-item">
            <span class="icon">👥</span> Manage Advisors
        </a>
        <a href="logs.php" class="nav-item">
            <span class="icon">📋</span> Activity Logs
        </a>
        <a href="pricing.php" class="nav-item">
        <span class="icon">💰</span> Pricing & Credits
        </a>
        <a href="tax_rules.php" class="nav-item">
            <span class="icon">📜</span> Tax Rules
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="btn-logout">🚪 Logout</a>
    </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">

    <!-- TOP BAR -->
    <div class="topbar">
        <h1>Admin Dashboard</h1>
        <span class="date">📅 <?= date('l, F j, Y') ?></span>
    </div>

    <div class="page-body">

        <!-- STAT CARDS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue">👥</div>
                <div class="stat-info">
                    <h3><?= $total_advisors ?></h3>
                    <p>Total Advisors</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green">🧑‍💼</div>
                <div class="stat-info">
                    <h3><?= $total_clients ?></h3>
                    <p>Total Clients</p>
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
                <div class="stat-icon icon-purple">📄</div>
                <div class="stat-info">
                    <h3><?= $total_reports ?></h3>
                    <p>Reports Generated</p>
                </div>
            </div>
        </div>

        <!-- TWO COLUMN -->
        <div class="two-col">

            <!-- ADVISORS TABLE -->
            <div class="card">
                <div class="section-title">👥 Registered Advisors</div>
                <?php if (empty($advisors)): ?>
                    <p style="color:#aaa; font-size:14px;">No advisors registered yet.</p>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Specialization</th>
                            <th>Clients</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($advisors as $a): ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($a['full_name']) ?></strong><br>
                                <small style="color:#aaa;"><?= sanitize($a['email']) ?></small>
                            </td>
                            <td><?= sanitize($a['specialization'] ?: '—') ?></td>
                            <td style="text-align:center;"><?= $a['client_count'] ?></td>
                            <td>
                                <span class="badge <?= $a['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $a['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <a href="advisors.php?toggle=<?= $a['user_id'] ?>"
                                   class="toggle-btn <?= $a['is_active'] ? 'btn-deactivate' : 'btn-activate' ?>">
                                    <?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- ACTIVITY LOG -->
            <div class="card">
                <div class="section-title">📋 Recent Activity</div>
                <?php if (empty($logs)): ?>
                    <p style="color:#aaa; font-size:14px;">No activity yet.</p>
                <?php else: ?>
                <?php foreach ($logs as $log):
                    $icon  = '🔵';
                    $cls   = 'icon-blue';
                    $badge = 'badge-login';
                    if ($log['action'] === 'LOGOUT') { $icon='🟡'; $cls='icon-orange'; $badge='badge-logout'; }
                    if ($log['action'] === 'SIGNUP')  { $icon='🟣'; $cls='icon-purple'; $badge='badge-signup'; }
                ?>
                <div class="log-item">
                    <div class="log-dot <?= $cls ?>"><?= $icon ?></div>
                    <div class="log-info">
                        <strong><?= sanitize($log['full_name']) ?>
                            <span class="badge <?= $badge ?>"><?= $log['action'] ?></span>
                        </strong>
                        <small><?= sanitize($log['description']) ?></small><br>
                        <small><?= formatDate($log['logged_at']) ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

</body>
</html>
