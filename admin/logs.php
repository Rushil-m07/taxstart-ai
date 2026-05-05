<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$admin = currentUser();

// ── FILTERS ──────────────────────────────────────────
$filter_action = sanitize($_GET['action'] ?? '');
$filter_user   = sanitize($_GET['user']   ?? '');
$filter_date   = sanitize($_GET['date']   ?? '');

// ── BUILD QUERY ──────────────────────────────────────
$where  = [];
$params = [];

if (!empty($filter_action)) {
    $where[]  = "al.action = ?";
    $params[] = $filter_action;
}

if (!empty($filter_user)) {
    $where[]  = "u.full_name LIKE ?";
    $params[] = "%$filter_user%";
}

if (!empty($filter_date)) {
    $where[]  = "DATE(al.logged_at) = ?";
    $params[] = $filter_date;
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$logs = $pdo->prepare("
    SELECT al.*, u.full_name, u.role, u.email
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    $where_sql
    ORDER BY al.logged_at DESC
    LIMIT 200
");
$logs->execute($params);
$logs = $logs->fetchAll();

// ── STATS ─────────────────────────────────────────────
$total_logs    = $pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$today_logs    = $pdo->query("
    SELECT COUNT(*) FROM activity_logs
    WHERE DATE(logged_at) = CURDATE()
")->fetchColumn();
$total_logins  = $pdo->query("
    SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN'
")->fetchColumn();
$total_uploads = $pdo->query("
    SELECT COUNT(*) FROM activity_logs WHERE action = 'UPLOAD_FILE'
")->fetchColumn();

// ── DISTINCT ACTIONS FOR FILTER ───────────────────────
$actions = $pdo->query("
    SELECT DISTINCT action FROM activity_logs ORDER BY action ASC
")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs – TaxStart AI</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:#f0f2f7; display:flex; min-height:100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width:260px;
            background:linear-gradient(180deg,#1a1a2e 0%,#0f3460 100%);
            color:white; display:flex; flex-direction:column;
            position:fixed; height:100vh; z-index:100;
        }

        .sidebar-brand {
            padding:28px 24px 20px;
            border-bottom:1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand h2 { font-size:20px; font-weight:700; }
        .sidebar-brand span {
            font-size:11px; opacity:0.6;
            letter-spacing:1px; text-transform:uppercase;
        }

        .sidebar-user {
            padding:20px 24px; display:flex;
            align-items:center; gap:12px;
            border-bottom:1px solid rgba(255,255,255,0.1);
        }

        .avatar {
            width:42px; height:42px;
            background:linear-gradient(135deg,#e94560,#f5a623);
            border-radius:50%; display:flex;
            align-items:center; justify-content:center;
            font-size:18px; font-weight:700; color:white;
        }

        .sidebar-user .info small {
            display:block; font-size:11px; opacity:0.6;
            text-transform:uppercase; letter-spacing:0.5px;
        }

        .sidebar-user .info strong { font-size:14px; }
        .sidebar-nav { flex:1; padding:20px 0; }

        .nav-label {
            font-size:10px; text-transform:uppercase;
            letter-spacing:1.5px; opacity:0.45; padding:10px 24px 6px;
        }

        .nav-item {
            display:flex; align-items:center; gap:12px;
            padding:13px 24px; color:rgba(255,255,255,0.75);
            text-decoration:none; font-size:14px; font-weight:500;
            transition:all 0.2s; border-left:3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background:rgba(255,255,255,0.08);
            color:#fff; border-left-color:#e94560;
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
            font-size:14px; font-weight:600; transition:background 0.2s;
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
        .topbar .date { font-size:13px; color:#888; }

        .page-body { padding:30px 32px; flex:1; }

        /* STATS */
        .stats-grid {
            display:grid; grid-template-columns:repeat(4,1fr);
            gap:18px; margin-bottom:26px;
        }

        .stat-card {
            background:#fff; border-radius:14px; padding:20px 20px;
            display:flex; align-items:center; gap:14px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .stat-icon {
            width:46px; height:46px; border-radius:12px;
            display:flex; align-items:center;
            justify-content:center; font-size:22px;
        }

        .icon-blue   { background:#e8f0fe; }
        .icon-green  { background:#e6f9f0; }
        .icon-orange { background:#fff3e0; }
        .icon-purple { background:#f3e8ff; }

        .stat-info h3 {
            font-size:24px; font-weight:700;
            color:#1a1a2e; line-height:1; margin-bottom:3px;
        }

        .stat-info p { font-size:12px; color:#888; }

        /* FILTER BAR */
        .filter-card {
            background:#fff; border-radius:14px;
            padding:20px 24px; margin-bottom:22px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .filter-card h4 {
            font-size:13px; font-weight:700; color:#555;
            margin-bottom:14px; text-transform:uppercase;
            letter-spacing:0.5px;
        }

        .filter-row {
            display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;
        }

        .filter-group {
            display:flex; flex-direction:column; gap:6px; flex:1;
            min-width:160px;
        }

        .filter-group label {
            font-size:12px; font-weight:600; color:#666;
        }

        .filter-group select,
        .filter-group input {
            padding:10px 13px; border:2px solid #e8e8e8;
            border-radius:9px; font-size:13px; color:#333;
            outline:none; transition:border-color 0.3s; background:#fff;
        }

        .filter-group select:focus,
        .filter-group input:focus { border-color:#0f3460; }

        .btn-filter {
            padding:10px 22px;
            background:#0f3460; color:#fff;
            border:none; border-radius:9px;
            font-size:13px; font-weight:600; cursor:pointer;
            transition:opacity 0.2s; white-space:nowrap;
        }

        .btn-filter:hover { opacity:0.9; }

        .btn-clear {
            padding:10px 16px;
            background:#f0f0f0; color:#555;
            border:none; border-radius:9px;
            font-size:13px; font-weight:600;
            cursor:pointer; text-decoration:none;
            white-space:nowrap;
        }

        .btn-clear:hover { background:#e0e0e0; }

        /* LOGS TABLE */
        .card {
            background:#fff; border-radius:14px; padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:16px; display:flex;
            align-items:center; justify-content:space-between;
        }

        .log-count {
            font-size:13px; color:#888; font-weight:600;
            background:#f5f5f5; padding:4px 12px; border-radius:20px;
        }

        table {
            width:100%; border-collapse:collapse; font-size:13px;
        }

        thead th {
            text-align:left; padding:10px 14px;
            font-size:11px; font-weight:700; color:#888;
            text-transform:uppercase; letter-spacing:0.5px;
            border-bottom:2px solid #f0f0f0;
        }

        tbody tr {
            border-bottom:1px solid #f7f7f7; transition:background 0.15s;
        }

        tbody tr:hover { background:#fafafa; }

        tbody td {
            padding:12px 14px; color:#333; vertical-align:middle;
        }

        /* ACTION BADGES */
        .action-badge {
            display:inline-block; padding:4px 10px;
            border-radius:6px; font-size:11px;
            font-weight:700; letter-spacing:0.3px;
        }

        .action-LOGIN          { background:#e8f0fe; color:#1a73e8; }
        .action-LOGOUT         { background:#fff3e0; color:#e67e22; }
        .action-SIGNUP         { background:#f3e8ff; color:#7b2d8b; }
        .action-ADD_CLIENT     { background:#e6f9f0; color:#1e8449; }
        .action-DELETE_CLIENT  { background:#fdecea; color:#c0392b; }
        .action-UPLOAD_FILE    { background:#e8f0fe; color:#0f3460; }
        .action-AI_ANALYSIS    { background:#fff8e1; color:#d68910; }
        .action-GENERATE_REPORT{ background:#f3e8ff; color:#7b2d8b; }
        .action-TOGGLE_ADVISOR { background:#fdecea; color:#c0392b; }

        .role-badge {
            display:inline-block; padding:3px 8px;
            border-radius:4px; font-size:10px; font-weight:700;
        }

        .role-admin  { background:#fdecea; color:#c0392b; }
        .role-advisor{ background:#e6f9f0; color:#1e8449; }

        .ip-text {
            font-family:monospace; font-size:11px; color:#aaa;
        }

        .empty-state {
            text-align:center; padding:50px 0; color:#bbb;
        }

        .empty-state .empty-icon { font-size:44px; margin-bottom:12px; }
        .empty-state p { font-size:14px; }
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
        <div class="avatar"><?= strtoupper(substr($admin['name'], 0, 1)) ?></div>
        <div class="info">
            <small>Administrator</small>
            <strong><?= sanitize($admin['name']) ?></strong>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="nav-item">
            <span class="icon">📊</span> Dashboard
        </a>
        <a href="advisors.php" class="nav-item">
            <span class="icon">👥</span> Manage Advisors
        </a>
        <a href="logs.php" class="nav-item active">
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

<!-- MAIN -->
<div class="main">

    <div class="topbar">
        <h1>📋 Activity Logs</h1>
        <span class="date">📅 <?= date('l, F j, Y') ?></span>
    </div>

    <div class="page-body">

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue">📋</div>
                <div class="stat-info">
                    <h3><?= $total_logs ?></h3>
                    <p>Total Log Entries</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green">📅</div>
                <div class="stat-info">
                    <h3><?= $today_logs ?></h3>
                    <p>Today's Activity</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-orange">🔐</div>
                <div class="stat-info">
                    <h3><?= $total_logins ?></h3>
                    <p>Total Logins</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple">📤</div>
                <div class="stat-info">
                    <h3><?= $total_uploads ?></h3>
                    <p>Files Uploaded</p>
                </div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-card">
            <h4>🔍 Filter Logs</h4>
            <form method="GET" action="logs.php">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>Action Type</label>
                        <select name="action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $a): ?>
                                <option value="<?= $a ?>"
                                    <?= $filter_action === $a ? 'selected' : '' ?>>
                                    <?= $a ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>User Name</label>
                        <input type="text" name="user"
                               placeholder="Search by name..."
                               value="<?= $filter_user ?>">
                    </div>
                    <div class="filter-group">
                        <label>Date</label>
                        <input type="date" name="date"
                               value="<?= $filter_date ?>">
                    </div>
                    <button type="submit" class="btn-filter">
                        🔍 Filter
                    </button>
                    <?php if ($filter_action || $filter_user || $filter_date): ?>
                        <a href="logs.php" class="btn-clear">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- LOGS TABLE -->
        <div class="card">
            <div class="section-title">
                📋 System Activity
                <span class="log-count">
                    <?= count($logs) ?> entr<?= count($logs) !== 1 ? 'ies' : 'y' ?>
                </span>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <p>No log entries found<?= ($filter_action || $filter_user || $filter_date) ? ' for the selected filters' : '' ?>.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $i => $log): ?>
                    <tr>
                        <td style="color:#ccc; font-size:12px;">
                            <?= $i + 1 ?>
                        </td>
                        <td>
                            <strong><?= sanitize($log['full_name']) ?></strong>
                            <br>
                            <small style="color:#aaa;">
                                <?= sanitize($log['email']) ?>
                            </small>
                        </td>
                        <td>
                            <span class="role-badge role-<?= $log['role'] ?>">
                                <?= strtoupper($log['role']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="action-badge action-<?= $log['action'] ?>">
                                <?= $log['action'] ?>
                            </span>
                        </td>
                        <td style="color:#666; font-size:13px;">
                            <?= sanitize($log['description'] ?: '—') ?>
                        </td>
                        <td>
                            <span class="ip-text">
                                <?= sanitize($log['ip_address'] ?? '—') ?>
                            </span>
                        </td>
                        <td>
                            <?= date('M d, Y', strtotime($log['logged_at'])) ?>
                            <br>
                            <small style="color:#aaa;">
                                <?= date('h:i:s A', strtotime($log['logged_at'])) ?>
                            </small>
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
