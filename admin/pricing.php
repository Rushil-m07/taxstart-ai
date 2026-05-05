<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits_helper.php';

requireAdmin();

$admin   = currentUser();
$success = '';
$error   = '';

// ── UPDATE PRICES ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_prices'])) {

    $silver = (float)($_POST['silver_price'] ?? 70);
    $gold   = (float)($_POST['gold_price']   ?? 120);

    if ($silver <= 0 || $gold <= 0) {
        $error = "Prices must be greater than zero.";
    } elseif ($gold <= $silver) {
        $error = "Gold price must be higher than Silver price.";
    } else {
        $pdo->prepare("
            UPDATE subscription_pricing
            SET price_yearly = ?, updated_by = ?
            WHERE tier = 'silver'
        ")->execute([$silver, $admin['id']]);

        $pdo->prepare("
            UPDATE subscription_pricing
            SET price_yearly = ?, updated_by = ?
            WHERE tier = 'gold'
        ")->execute([$gold, $admin['id']]);

        logActivity($pdo, $admin['id'], 'UPDATE_PRICING',
            "Updated prices: Silver=\$$silver, Gold=\$$gold");

        $success = "Prices updated successfully!";
    }
}

$prices = getSubscriptionPrices($pdo);

// Get credit stats across all advisors
$credit_stats = $pdo->query("
    SELECT
        u.full_name,
        u.email,
        SUM(CASE WHEN ac.tier='silver'
            THEN (ac.total_credits - ac.used_credits) ELSE 0 END)
            AS silver_available,
        SUM(CASE WHEN ac.tier='gold'
            THEN (ac.total_credits - ac.used_credits) ELSE 0 END)
            AS gold_available,
        SUM(CASE WHEN ac.tier='silver'
            THEN ac.used_credits ELSE 0 END)
            AS silver_used,
        SUM(CASE WHEN ac.tier='gold'
            THEN ac.used_credits ELSE 0 END)
            AS gold_used
    FROM users u
    LEFT JOIN advisor_credits ac ON u.user_id = ac.advisor_id
        AND ac.year = YEAR(NOW())
    WHERE u.role = 'advisor'
    GROUP BY u.user_id
    ORDER BY u.full_name ASC
")->fetchAll();

$total_revenue = $pdo->query("
    SELECT COALESCE(SUM(price_paid),0)
    FROM subscriptions WHERE is_free = 0
")->fetchColumn();

$total_silver_sold = $pdo->query("
    SELECT COALESCE(SUM(credits_bought),0)
    FROM subscriptions WHERE tier='silver' AND is_free=0
")->fetchColumn();

$total_gold_sold = $pdo->query("
    SELECT COALESCE(SUM(credits_bought),0)
    FROM subscriptions WHERE tier='gold' AND is_free=0
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing & Credits – TaxStart AI Admin</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            background:#f0f2f7; display:flex; min-height:100vh;
        }
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
            border-radius:50%; display:flex; align-items:center;
            justify-content:center; font-size:18px;
            font-weight:700; color:white;
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
            color:#e94560; border-radius:8px; text-align:center;
            text-decoration:none; font-size:14px; font-weight:600;
        }
        .main {
            margin-left:260px; flex:1; display:flex; flex-direction:column;
        }
        .topbar {
            background:#fff; padding:18px 32px;
            display:flex; align-items:center; justify-content:space-between;
            box-shadow:0 2px 8px rgba(0,0,0,0.06);
            position:sticky; top:0; z-index:50;
        }
        .topbar h1 { font-size:20px; font-weight:700; color:#1a1a2e; }
        .topbar p  { font-size:13px; color:#888; margin-top:3px; }
        .page-body { padding:30px 32px; flex:1; }

        .alert {
            padding:13px 18px; border-radius:10px;
            font-size:14px; margin-bottom:22px;
        }
        .alert-success {
            background:#f0fff4; border-left:4px solid #2ecc71; color:#1e8449;
        }
        .alert-error {
            background:#fff0f0; border-left:4px solid #e94560; color:#c0392b;
        }

        .stats-grid {
            display:grid; grid-template-columns:repeat(4,1fr);
            gap:18px; margin-bottom:26px;
        }
        .stat-card {
            background:#fff; border-radius:14px; padding:20px;
            display:flex; align-items:center; gap:14px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }
        .stat-icon {
            width:46px; height:46px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size:22px;
        }
        .i-green  { background:#e6f9f0; }
        .i-silver { background:linear-gradient(135deg,#e8e8e8,#c0c0c0); }
        .i-gold   { background:linear-gradient(135deg,#fff3cd,#f5c518); }
        .i-blue   { background:#e8f0fe; }
        .stat-info h3 {
            font-size:22px; font-weight:700; color:#1a1a2e;
            line-height:1; margin-bottom:3px;
        }
        .stat-info p { font-size:12px; color:#888; }

        .pricing-card {
            background:#fff; border-radius:14px; padding:28px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            margin-bottom:26px; border-top:4px solid #0f3460;
        }
        .pricing-card h3 {
            font-size:16px; font-weight:700; color:#1a1a2e; margin-bottom:20px;
        }

        .price-grid {
            display:grid; grid-template-columns:1fr 1fr; gap:20px;
            margin-bottom:20px;
        }
        .price-box {
            border-radius:12px; padding:20px;
        }
        .price-box.silver-box {
            background:#f8f9fa; border:2px solid #bdbdbd;
        }
        .price-box.gold-box {
            background:#fffdf0; border:2px solid #f5c518;
        }
        .price-box label {
            display:block; font-size:12px; font-weight:700;
            color:#555; margin-bottom:10px;
            text-transform:uppercase; letter-spacing:0.5px;
        }
        .price-input-wrap {
            display:flex; align-items:center; gap:8px;
        }
        .currency {
            font-size:20px; font-weight:700; color:#1a1a2e;
        }
        .price-input {
            flex:1; padding:12px 14px; border:2px solid #e8e8e8;
            border-radius:10px; font-size:20px; font-weight:700;
            color:#1a1a2e; outline:none; transition:border-color 0.3s;
        }
        .price-input:focus { border-color:#0f3460; }
        .price-note {
            font-size:12px; color:#888; margin-top:8px;
        }

        .btn-save {
            padding:13px 36px; border:none; border-radius:10px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; font-size:14px; font-weight:700;
            cursor:pointer; transition:opacity 0.2s;
        }
        .btn-save:hover { opacity:0.9; }

        .warning-box {
            background:#fff8e1; border-left:4px solid #f5a623;
            border-radius:8px; padding:12px 16px;
            font-size:13px; color:#8a6500; margin-bottom:18px; line-height:1.7;
        }

        .advisors-card {
            background:#fff; border-radius:14px; padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }
        .section-title {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:16px; display:flex;
            align-items:center; justify-content:space-between;
        }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        thead th {
            text-align:left; padding:10px 14px;
            font-size:12px; font-weight:700; color:#888;
            text-transform:uppercase; letter-spacing:0.5px;
            border-bottom:2px solid #f0f0f0;
        }
        tbody tr { border-bottom:1px solid #f7f7f7; }
        tbody tr:hover { background:#fafafa; }
        tbody td { padding:12px 14px; color:#333; vertical-align:middle; }

        .mini-bar-wrap { display:flex; align-items:center; gap:8px; }
        .mini-bar {
            flex:1; height:6px; background:#f0f0f0;
            border-radius:3px; overflow:hidden;
        }
        .mini-fill-s { height:100%; background:#9e9e9e; border-radius:3px; }
        .mini-fill-g { height:100%; background:#f5c518; border-radius:3px; }
        .mini-num { font-size:12px; font-weight:700; color:#1a1a2e; min-width:20px; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <h2>TaxStart AI</h2>
        <span>ABC Tech Ltd.</span>
    </div>
    <div class="sidebar-user">
        <div class="avatar">
            <?= strtoupper(substr($admin['name'], 0, 1)) ?>
        </div>
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
        <a href="logs.php" class="nav-item">
            <span class="icon">📋</span> Activity Logs
        </a>
        <a href="tax_rules.php" class="nav-item">
            <span class="icon">📜</span> Tax Rules
        </a>
        <a href="pricing.php" class="nav-item active">
            <span class="icon">💰</span> Pricing & Credits
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="http://localhost/taxstart/logout.php"
           class="btn-logout">Logout</a>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <div>
            <h1>💰 Pricing & Credits Management</h1>
            <p>Set subscription prices and monitor credit usage</p>
        </div>
    </div>

    <div class="page-body">

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">✅ <?= sanitize($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">⚠️ <?= sanitize($error) ?></div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon i-green">💰</div>
                <div class="stat-info">
                    <h3>$<?= number_format($total_revenue, 0) ?></h3>
                    <p>Total Revenue</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon i-silver">🥈</div>
                <div class="stat-info">
                    <h3><?= $total_silver_sold ?></h3>
                    <p>Silver Credits Sold</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon i-gold">🥇</div>
                <div class="stat-info">
                    <h3><?= $total_gold_sold ?></h3>
                    <p>Gold Credits Sold</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon i-blue">👥</div>
                <div class="stat-info">
                    <h3><?= count($credit_stats) ?></h3>
                    <p>Active Advisors</p>
                </div>
            </div>
        </div>

        <!-- PRICING FORM -->
        <div class="pricing-card">
            <h3>💲 Set Subscription Prices</h3>

            <div class="warning-box">
                ⚠️ <strong>Note:</strong> Price changes apply to
                <strong>future purchases only</strong>.
                Existing credits are not affected.
                Current prices — Silver: <strong>$<?= number_format($prices['silver'], 2) ?></strong>
                &nbsp;|&nbsp; Gold: <strong>$<?= number_format($prices['gold'], 2) ?></strong>
            </div>

            <form method="POST">
                <div class="price-grid">
                    <div class="price-box silver-box">
                        <label>🥈 Silver Tier Price (per credit/year)</label>
                        <div class="price-input-wrap">
                            <span class="currency">$</span>
                            <input type="number" name="silver_price"
                                   class="price-input"
                                   value="<?= $prices['silver'] ?>"
                                   min="1" max="999" step="0.01">
                        </div>
                        <div class="price-note">
                            For Canada/domestic scenarios
                        </div>
                    </div>
                    <div class="price-box gold-box">
                        <label>🥇 Gold Tier Price (per credit/year)</label>
                        <div class="price-input-wrap">
                            <span class="currency">$</span>
                            <input type="number" name="gold_price"
                                   class="price-input"
                                   value="<?= $prices['gold'] ?>"
                                   min="1" max="999" step="0.01">
                        </div>
                        <div class="price-note">
                            For international scenarios
                        </div>
                    </div>
                </div>
                <button type="submit" name="update_prices"
                        class="btn-save">
                    💾 Save Prices
                </button>
            </form>
        </div>

        <!-- ADVISOR CREDIT OVERVIEW -->
        <div class="advisors-card">
            <div class="section-title">
                Advisor Credit Overview — <?= date('Y') ?>
            </div>
            <?php if (empty($credit_stats)): ?>
                <p style="color:#bbb;text-align:center;padding:30px;">
                    No advisors registered yet.
                </p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Advisor</th>
                        <th>Silver Available</th>
                        <th>Silver Used</th>
                        <th>Gold Available</th>
                        <th>Gold Used</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($credit_stats as $cs): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($cs['full_name']) ?></strong>
                            <br>
                            <small style="color:#aaa;">
                                <?= sanitize($cs['email']) ?>
                            </small>
                        </td>
                        <td>
                            <div class="mini-bar-wrap">
                                <div class="mini-bar">
                                    <div class="mini-fill-s"
                                         style="width:<?= min(($cs['silver_available'] / max($cs['silver_available'] + $cs['silver_used'], 1)) * 100, 100) ?>%">
                                    </div>
                                </div>
                                <span class="mini-num">
                                    <?= (int)$cs['silver_available'] ?>
                                </span>
                            </div>
                        </td>
                        <td><?= (int)$cs['silver_used'] ?></td>
                        <td>
                            <div class="mini-bar-wrap">
                                <div class="mini-bar">
                                    <div class="mini-fill-g"
                                         style="width:<?= min(($cs['gold_available'] / max($cs['gold_available'] + $cs['gold_used'], 1)) * 100, 100) ?>%">
                                    </div>
                                </div>
                                <span class="mini-num">
                                    <?= (int)$cs['gold_available'] ?>
                                </span>
                            </div>
                        </td>
                        <td><?= (int)$cs['gold_used'] ?></td>
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