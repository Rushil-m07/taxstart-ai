<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits_helper.php';

requireAdvisor();

$advisor = currentUser();

// Initialize welcome offer if not already done
initWelcomeOffer($pdo, $advisor['id']);

// Get current prices from admin settings
$prices       = getSubscriptionPrices($pdo);
$silver_price = $prices['silver'] ?? 70.00;
$gold_price   = $prices['gold']   ?? 120.00;

// Get credit summary
$credits = getCreditSummary($pdo, $advisor['id']);

// Get purchase history
$history = $pdo->prepare("
    SELECT s.*, u.full_name AS advisor_name
    FROM subscriptions s
    JOIN users u ON s.advisor_id = u.user_id
    WHERE s.advisor_id = ?
    ORDER BY s.purchased_at DESC
");
$history->execute([$advisor['id']]);
$history = $history->fetchAll();

$error   = '';
$success = '';

// ── HANDLE PAID SUBSCRIPTION ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['subscribe_paid'])) {

    $tier    = sanitize($_POST['tier']    ?? 'silver');
    $qty     = max(1, (int)($_POST['qty'] ?? 1));
    $price   = ($tier === 'silver' ? $silver_price : $gold_price) * $qty;
    $pay_id  = 'DEMO-' . strtoupper(uniqid());

    addCredits($pdo, $advisor['id'], $tier, $qty, $price, $pay_id);

    // Log payment
    $pdo->prepare("
        INSERT INTO payments
            (advisor_id, amount, tier, credits, stripe_id, status)
        VALUES (?, ?, ?, ?, ?, 'completed')
    ")->execute([$advisor['id'], $price, $tier, $qty, $pay_id]);

    logActivity($pdo, $advisor['id'], 'PURCHASE_CREDITS',
        "Bought $qty $tier credit(s) for \$$price");

    // Refresh
    $credits = getCreditSummary($pdo, $advisor['id']);
    $success = "Successfully purchased $qty $tier credit(s)!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription & Credits – TaxStart AI</title>
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
            background:linear-gradient(135deg,#2ecc71,#1a73e8);
            border-radius:50%; display:flex; align-items:center;
            justify-content:center; font-size:18px;
            font-weight:700; color:white;
        }
        .sidebar-user .info small {
            display:block; font-size:11px; opacity:0.6;
            text-transform:uppercase; letter-spacing:0.5px;
        }
        .sidebar-user .info strong { font-size:14px; }
        .sidebar-nav { flex:1; padding:20px 0; overflow-y:auto; }
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
            color:#e94560; border-radius:8px; text-align:center;
            text-decoration:none; font-size:14px; font-weight:600;
        }
        .btn-logout:hover { background:rgba(233,69,96,0.35); }
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
        .topbar p  { font-size:13px; color:#888; margin-top:3px; }
        .page-body { padding:30px 32px; flex:1; }

        /* ALERTS */
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

        /* CREDIT DASHBOARD */
        .credit-dashboard {
            display:grid; grid-template-columns:1fr 1fr;
            gap:20px; margin-bottom:26px;
        }
        .credit-card {
            border-radius:16px; padding:26px;
            position:relative; overflow:hidden;
        }
        .credit-card.silver {
            background:linear-gradient(135deg,#f8f9fa,#fff);
            border:2px solid #bdbdbd;
            box-shadow:0 4px 20px rgba(0,0,0,0.08);
        }
        .credit-card.gold {
            background:linear-gradient(135deg,#fffdf0,#fff);
            border:2px solid #f5c518;
            box-shadow:0 4px 20px rgba(245,197,24,0.15);
        }
        .credit-card .tier-badge {
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 16px; border-radius:20px;
            font-size:13px; font-weight:700; margin-bottom:18px;
        }
        .tier-badge.silver {
            background:linear-gradient(135deg,#9e9e9e,#757575); color:white;
        }
        .tier-badge.gold {
            background:linear-gradient(135deg,#f5c518,#f39c12); color:#1a1a2e;
        }
        .credit-numbers {
            display:flex; gap:20px; margin-bottom:18px;
        }
        .cn-item { text-align:center; flex:1; }
        .cn-item .cn-val {
            font-size:32px; font-weight:700; color:#1a1a2e; line-height:1;
        }
        .cn-item .cn-label {
            font-size:11px; color:#aaa; text-transform:uppercase;
            letter-spacing:0.5px; margin-top:4px;
        }
        .cn-item.available .cn-val { color:#1e8449; }
        .cn-item.used      .cn-val { color:#e67e22; }

        /* PROGRESS BAR */
        .credit-progress {
            background:#f0f0f0; border-radius:8px;
            height:10px; overflow:hidden; margin-bottom:12px;
        }
        .credit-progress .fill {
            height:100%; border-radius:8px; transition:width 0.5s;
        }
        .silver .fill { background:linear-gradient(90deg,#9e9e9e,#616161); }
        .gold   .fill { background:linear-gradient(90deg,#f5c518,#f39c12); }

        .credit-scope {
            font-size:12px; color:#888; margin-bottom:16px;
            padding:8px 12px; background:#f8f9fa; border-radius:8px;
        }

        .credit-scope strong { color:#0f3460; }

        .btn-buy {
            width:100%; padding:12px; border:none;
            border-radius:10px; font-size:14px; font-weight:700;
            cursor:pointer; transition:opacity 0.2s;
        }
        .btn-buy:hover { opacity:0.9; }
        .silver .btn-buy {
            background:linear-gradient(135deg,#9e9e9e,#616161); color:white;
        }
        .gold .btn-buy {
            background:linear-gradient(135deg,#f5c518,#f39c12); color:#1a1a2e;
        }

        /* WELCOME OFFER BANNER */
        .welcome-banner {
            background:linear-gradient(135deg,#1a1a2e,#0f3460);
            border-radius:16px; padding:22px 28px;
            margin-bottom:26px; color:white;
            display:flex; align-items:center;
            justify-content:space-between; gap:20px;
        }
        .welcome-banner h2 { font-size:17px; font-weight:700; margin-bottom:6px; }
        .welcome-banner p  { font-size:13px; opacity:0.85; line-height:1.6; }
        .offer-pills { display:flex; gap:12px; flex-shrink:0; }
        .offer-pill {
            text-align:center; padding:12px 18px;
            border-radius:12px; min-width:90px;
        }
        .offer-pill.s {
            background:linear-gradient(135deg,#9e9e9e,#bdbdbd); color:white;
        }
        .offer-pill.g {
            background:linear-gradient(135deg,#f5c518,#f39c12); color:#1a1a2e;
        }
        .offer-pill .op-num { font-size:26px; font-weight:700; line-height:1; }
        .offer-pill .op-lbl { font-size:11px; font-weight:600; margin-top:3px; }

        /* YEAR BADGE */
        .year-badge {
            background:#e8f0fe; color:#1a73e8;
            border-radius:20px; padding:5px 14px;
            font-size:12px; font-weight:700;
            display:inline-block; margin-bottom:20px;
        }

        /* PAYMENT MODAL */
        .modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.65); z-index:1000;
            align-items:center; justify-content:center;
        }
        .modal-overlay.active { display:flex; }
        .modal {
            background:#fff; border-radius:20px;
            width:460px; overflow:hidden;
            box-shadow:0 20px 60px rgba(0,0,0,0.3);
            position:relative;
        }
        .modal-header {
            padding:22px 28px;
            background:linear-gradient(135deg,#0f3460,#1a73e8); color:white;
        }
        .modal-header h3 { font-size:18px; font-weight:700; }
        .modal-header p  { font-size:13px; opacity:0.85; margin-top:4px; }
        .modal-body { padding:26px 28px; }

        .amount-box {
            background:#f8f9fa; border-radius:12px;
            padding:14px 18px; margin-bottom:20px;
            display:flex; justify-content:space-between; align-items:center;
        }
        .amount-box .ab-label { font-size:13px; color:#666; }
        .amount-box .ab-val   { font-size:26px; font-weight:700; color:#1a1a2e; }
        .amount-box .ab-sub   { font-size:11px; color:#aaa; margin-top:2px; text-align:right; }

        .qty-selector {
            display:flex; align-items:center; gap:10px; margin-bottom:18px;
        }
        .qty-selector label {
            font-size:12px; font-weight:700; color:#555;
            text-transform:uppercase; letter-spacing:0.5px;
            min-width:80px;
        }
        .qty-btn {
            width:36px; height:36px; border-radius:8px;
            border:2px solid #e8e8e8; background:#fff;
            font-size:18px; font-weight:700; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            transition:all 0.2s;
        }
        .qty-btn:hover { border-color:#0f3460; color:#0f3460; }
        .qty-display {
            font-size:20px; font-weight:700; color:#1a1a2e;
            min-width:40px; text-align:center;
        }

        .fg { margin-bottom:14px; }
        .fg label {
            display:block; font-size:12px; font-weight:700;
            color:#555; margin-bottom:7px;
            text-transform:uppercase; letter-spacing:0.5px;
        }
        .fg input {
            width:100%; padding:11px 14px;
            border:2px solid #e8e8e8; border-radius:10px;
            font-size:14px; color:#333; outline:none;
            transition:border-color 0.3s;
        }
        .fg input:focus { border-color:#0f3460; }

        .card-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

        .demo-hint {
            background:#fff8e1; border-left:3px solid #f5a623;
            border-radius:6px; padding:10px 14px;
            font-size:12px; color:#8a6500; margin-bottom:16px; line-height:1.6;
        }

        .btn-pay {
            width:100%; padding:13px; border:none; border-radius:10px;
            font-size:14px; font-weight:700; cursor:pointer;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; transition:opacity 0.2s; margin-bottom:10px;
        }
        .btn-pay:hover { opacity:0.9; }
        .btn-pay:disabled { opacity:0.6; cursor:not-allowed; }
        .btn-cancel-modal {
            width:100%; padding:11px; border:none; border-radius:10px;
            font-size:14px; font-weight:600; cursor:pointer;
            background:#f5f5f5; color:#555;
        }
        .btn-cancel-modal:hover { background:#e8e8e8; }

        .processing-overlay {
            display:none; position:absolute; inset:0;
            background:rgba(255,255,255,0.95); border-radius:20px;
            z-index:10; align-items:center; justify-content:center;
            flex-direction:column;
        }
        .processing-overlay.active { display:flex; }
        .proc-spinner {
            width:50px; height:50px; border:4px solid #f0f0f0;
            border-top-color:#1a73e8; border-radius:50%;
            animation:spin 0.8s linear infinite; margin-bottom:14px;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        .proc-text { font-size:15px; font-weight:600; color:#1a1a2e; }

        .success-overlay {
            display:none; position:absolute; inset:0;
            background:#fff; border-radius:20px; z-index:11;
            align-items:center; justify-content:center;
            flex-direction:column; text-align:center; padding:40px;
        }
        .success-overlay.active { display:flex; }
        .success-circle {
            width:70px; height:70px; border-radius:50%;
            background:linear-gradient(135deg,#2ecc71,#1e8449);
            display:flex; align-items:center; justify-content:center;
            font-size:30px; margin-bottom:16px;
            animation:popIn 0.4s ease;
        }
        @keyframes popIn {
            0%  { transform:scale(0); }
            70% { transform:scale(1.1); }
            100%{ transform:scale(1); }
        }
        .success-overlay h3 {
            font-size:20px; font-weight:700; color:#1a1a2e; margin-bottom:8px;
        }
        .success-overlay p {
            font-size:13px; color:#888; margin-bottom:20px; line-height:1.6;
        }
        .txn-pill {
            background:#f0f2f7; border-radius:8px;
            padding:8px 16px; font-family:monospace;
            font-size:12px; color:#555; margin-bottom:20px;
        }
        .btn-success-ok {
            padding:12px 36px; border:none; border-radius:10px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:white; font-size:14px; font-weight:700; cursor:pointer;
        }

        /* HISTORY TABLE */
        .history-card {
            background:#fff; border-radius:14px; padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-top:24px;
        }
        .section-title {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:16px; display:flex;
            align-items:center; justify-content:space-between;
        }
        table {
            width:100%; border-collapse:collapse; font-size:14px;
        }
        thead th {
            text-align:left; padding:10px 14px;
            font-size:12px; font-weight:700; color:#888;
            text-transform:uppercase; letter-spacing:0.5px;
            border-bottom:2px solid #f0f0f0;
        }
        tbody tr { border-bottom:1px solid #f7f7f7; }
        tbody tr:hover { background:#fafafa; }
        tbody td { padding:12px 14px; color:#333; vertical-align:middle; }

        .tier-pill {
            display:inline-block; padding:4px 12px;
            border-radius:20px; font-size:11px; font-weight:700;
        }
        .tp-silver {
            background:linear-gradient(135deg,#e8e8e8,#bdbdbd); color:#555;
        }
        .tp-gold {
            background:linear-gradient(135deg,#fff3cd,#f5c518); color:#7d6608;
        }
        .empty-state {
            text-align:center; padding:40px 0; color:#bbb;
        }
        .ei { font-size:40px; margin-bottom:10px; }

        /* CREDIT WARNING */
        .credit-warning {
            background:#fff0f0; border:2px solid #e94560;
            border-radius:12px; padding:16px 20px;
            display:flex; align-items:center; gap:14px;
            margin-bottom:20px;
        }
        .credit-warning .cw-icon { font-size:28px; flex-shrink:0; }
        .credit-warning h4 { font-size:14px; font-weight:700; color:#c0392b; }
        .credit-warning p  { font-size:13px; color:#666; margin-top:3px; }
    </style>
</head>
<body>

<!-- PAYMENT MODAL -->
<div class="modal-overlay" id="payModal">
    <div class="modal" style="position:relative;">

        <div class="processing-overlay" id="procOverlay">
            <div class="proc-spinner"></div>
            <div class="proc-text">Processing Payment...</div>
        </div>

        <div class="success-overlay" id="succOverlay">
            <div class="success-circle">✓</div>
            <h3>Payment Successful!</h3>
            <p>
                Your credits have been added.<br>
                Start using them right away!
            </p>
            <div class="txn-pill" id="txnPill">TXN: DEMO-XXXXXXX</div>
            <form method="POST" id="payForm">
                <input type="hidden" name="subscribe_paid" value="1">
                <input type="hidden" name="tier" id="formTier">
                <input type="hidden" name="qty"  id="formQty">
                <button type="submit" class="btn-success-ok">
                    Continue
                </button>
            </form>
        </div>

        <div class="modal-header">
            <h3 id="modalTitle">Purchase Credits</h3>
            <p id="modalSubtitle">Annual credits — valid for <?= date('Y') ?></p>
        </div>

        <div class="modal-body">
            <div class="amount-box">
                <div>
                    <div class="ab-label">Total Amount</div>
                </div>
                <div style="text-align:right;">
                    <div class="ab-val" id="modalTotal">$0.00</div>
                    <div class="ab-sub" id="modalBreakdown">
                        1 credit × $0.00
                    </div>
                </div>
            </div>

            <div class="qty-selector">
                <label>Quantity</label>
                <button type="button" class="qty-btn"
                        onclick="changeQty(-1)">−</button>
                <div class="qty-display" id="qtyDisplay">1</div>
                <button type="button" class="qty-btn"
                        onclick="changeQty(1)">+</button>
                <span style="font-size:12px;color:#888;margin-left:8px;">
                    credits
                </span>
            </div>

            <div class="demo-hint">
                Demo Mode: Enter any card details to complete payment.
            </div>

            <div class="fg">
                <label>Card Holder Name</label>
                <input type="text" id="cardName"
                       placeholder="John Smith">
            </div>
            <div class="fg">
                <label>Card Number</label>
                <input type="text" id="cardNum"
                       placeholder="1234 5678 9012 3456"
                       maxlength="19" oninput="fmtCard(this)">
            </div>
            <div class="card-row">
                <div class="fg">
                    <label>Expiry</label>
                    <input type="text" id="cardExp"
                           placeholder="MM/YY" maxlength="5"
                           oninput="fmtExp(this)">
                </div>
                <div class="fg">
                    <label>CVV</label>
                    <input type="text" id="cardCvv"
                           placeholder="123" maxlength="3"
                           oninput="this.value=this.value.replace(/\D/g,'')">
                </div>
            </div>

            <button class="btn-pay" id="payBtn"
                    onclick="processPayment()">
                Pay &amp; Add Credits
            </button>
            <button class="btn-cancel-modal" onclick="closeModal()">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <h2>TaxStart AI</h2>
        <span>ABC Tech Ltd.</span>
    </div>
    <div class="sidebar-user">
        <div class="avatar">
            <?= strtoupper(substr($advisor['name'], 0, 1)) ?>
        </div>
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
        <a href="subscription.php" class="nav-item active">
            <span class="icon">💳</span> Credits & Subscription
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
            <h1>💳 Credits & Subscription</h1>
            <p>Per-client credits — Silver for Canada, Gold for International</p>
        </div>
    </div>

    <div class="page-body">

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">✅ <?= sanitize($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error">⚠️ <?= sanitize($error) ?></div>
        <?php endif; ?>

        <!-- YEAR BADGE -->
        <div class="year-badge">
            📅 <?= $credits['year'] ?> Credit Year
            — Credits reset every January 1st
        </div>

        <!-- WELCOME OFFER BANNER -->
        <div class="welcome-banner">
            <div>
                <h2>🎁 Welcome Offer — Included for Every New Advisor</h2>
                <p>
                    Every new advisor gets
                    <strong>2 Free Silver</strong> and
                    <strong>1 Free Gold</strong> credit to try
                    TaxStart AI risk-free.<br>
                    Silver = Canada/Domestic &nbsp;|&nbsp;
                    Gold = International scenarios
                </p>
            </div>
            <div class="offer-pills">
                <div class="offer-pill s">
                    <div class="op-num">2</div>
                    <div class="op-lbl">Silver Free</div>
                </div>
                <div class="offer-pill g">
                    <div class="op-num">1</div>
                    <div class="op-lbl">Gold Free</div>
                </div>
            </div>
        </div>

        <!-- LOW CREDITS WARNING -->
        <?php if ($credits['silver_available'] === 0
               && $credits['gold_available'] === 0): ?>
        <div class="credit-warning">
            <div class="cw-icon">⚠️</div>
            <div>
                <h4>No Credits Available</h4>
                <p>
                    You have used all your credits for <?= $credits['year'] ?>.
                    Purchase more below to continue using AI features.
                </p>
            </div>
        </div>
        <?php elseif ($credits['silver_available'] <= 1
                   || $credits['gold_available'] === 0): ?>
        <div class="credit-warning" style="border-color:#f5a623;background:#fff8e1;">
            <div class="cw-icon">🔔</div>
            <div>
                <h4 style="color:#e67e22;">Low Credits Warning</h4>
                <p>
                    You are running low on credits.
                    Consider purchasing more to avoid interruptions.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- CREDIT CARDS -->
        <div class="credit-dashboard">

            <!-- SILVER CARD -->
            <div class="credit-card silver">
                <div class="tier-badge silver">🥈 Silver Tier</div>
                <div class="credit-numbers">
                    <div class="cn-item available">
                        <div class="cn-val"><?= $credits['silver_available'] ?></div>
                        <div class="cn-label">Available</div>
                    </div>
                    <div class="cn-item used">
                        <div class="cn-val"><?= $credits['silver_used'] ?></div>
                        <div class="cn-label">Used</div>
                    </div>
                    <div class="cn-item">
                        <div class="cn-val"><?= $credits['silver_total'] ?></div>
                        <div class="cn-label">Total</div>
                    </div>
                </div>

                <?php
                $sp = $credits['silver_total'] > 0
                    ? round(($credits['silver_used'] / $credits['silver_total']) * 100)
                    : 0;
                ?>
                <div class="credit-progress">
                    <div class="fill" style="width:<?= $sp ?>%"></div>
                </div>

                <div class="credit-scope">
                    <strong>For:</strong> Canada (CRA) domestic scenarios,
                    AI analysis, tax calculations &amp; reports
                    <br><strong>Price:</strong>
                    $<?= number_format($silver_price, 2) ?> / credit / year
                </div>

                <button class="btn-buy"
                        onclick="openModal('silver',
                            <?= $silver_price ?>)">
                    Buy Silver Credits —
                    $<?= number_format($silver_price, 2) ?>/credit
                </button>
            </div>

            <!-- GOLD CARD -->
            <div class="credit-card gold">
                <div class="tier-badge gold">🥇 Gold Tier</div>
                <div class="credit-numbers">
                    <div class="cn-item available">
                        <div class="cn-val"><?= $credits['gold_available'] ?></div>
                        <div class="cn-label">Available</div>
                    </div>
                    <div class="cn-item used">
                        <div class="cn-val"><?= $credits['gold_used'] ?></div>
                        <div class="cn-label">Used</div>
                    </div>
                    <div class="cn-item">
                        <div class="cn-val"><?= $credits['gold_total'] ?></div>
                        <div class="cn-label">Total</div>
                    </div>
                </div>

                <?php
                $gp = $credits['gold_total'] > 0
                    ? round(($credits['gold_used'] / $credits['gold_total']) * 100)
                    : 0;
                ?>
                <div class="credit-progress">
                    <div class="fill" style="width:<?= $gp ?>%"></div>
                </div>

                <div class="credit-scope">
                    <strong>For:</strong> International scenarios
                    (USA, UK, India, Australia, all countries),
                    includes all Silver features
                    <br><strong>Price:</strong>
                    $<?= number_format($gold_price, 2) ?> / credit / year
                </div>

                <button class="btn-buy"
                        onclick="openModal('gold',
                            <?= $gold_price ?>)">
                    Buy Gold Credits —
                    $<?= number_format($gold_price, 2) ?>/credit
                </button>
            </div>
        </div>

        <!-- PURCHASE HISTORY -->
        <div class="history-card">
            <div class="section-title">
                Purchase History
                <span style="font-size:13px;color:#888;font-weight:600;
                             background:#f5f5f5;padding:4px 12px;
                             border-radius:20px;">
                    <?= count($history) ?> transactions
                </span>
            </div>
            <?php if (empty($history)): ?>
                <div class="empty-state">
                    <div class="ei">📋</div>
                    <p>No purchase history yet.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Tier</th>
                        <th>Credits</th>
                        <th>Amount Paid</th>
                        <th>Type</th>
                        <th>Year</th>
                        <th>Payment ID</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td>
                            <?= date('M d, Y',
                                strtotime($h['purchased_at'])) ?>
                        </td>
                        <td>
                            <span class="tier-pill tp-<?= $h['tier'] ?>">
                                <?= $h['tier'] === 'silver' ? '🥈' : '🥇' ?>
                                <?= ucfirst($h['tier']) ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= $h['credits_bought'] ?></strong>
                            credit<?= $h['credits_bought'] > 1 ? 's' : '' ?>
                        </td>
                        <td>
                            <?php if ($h['is_free']): ?>
                                <span style="color:#1a73e8;font-weight:700;">
                                    FREE
                                </span>
                            <?php else: ?>
                                <strong>
                                    $<?= number_format($h['price_paid'], 2) ?>
                                </strong>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size:12px;color:#888;">
                                <?= $h['is_free'] ? 'Welcome Offer' : 'Purchased' ?>
                            </span>
                        </td>
                        <td><?= $h['year'] ?></td>
                        <td>
                            <span style="font-family:monospace;
                                         font-size:11px;color:#aaa;">
                                <?= $h['payment_id']
                                    ? sanitize(substr($h['payment_id'],0,20))
                                    : '—' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
let modalTier  = '';
let modalPrice = 0;
let modalQty   = 1;

function openModal(tier, price) {
    modalTier  = tier;
    modalPrice = price;
    modalQty   = 1;
    updateModalAmount();

    document.getElementById('modalTitle').textContent =
        'Buy ' + tier.charAt(0).toUpperCase() + tier.slice(1)
        + ' Credits';

    document.getElementById('procOverlay').classList.remove('active');
    document.getElementById('succOverlay').classList.remove('active');
    document.getElementById('payModal').classList.add('active');
}

function closeModal() {
    document.getElementById('payModal').classList.remove('active');
}

function changeQty(delta) {
    modalQty = Math.max(1, Math.min(20, modalQty + delta));
    document.getElementById('qtyDisplay').textContent = modalQty;
    updateModalAmount();
}

function updateModalAmount() {
    const total = (modalPrice * modalQty).toFixed(2);
    document.getElementById('modalTotal').textContent = '$' + total;
    document.getElementById('modalBreakdown').textContent =
        modalQty + ' credit' + (modalQty > 1 ? 's' : '')
        + ' × $' + modalPrice.toFixed(2);
}

function fmtCard(input) {
    let v = input.value.replace(/\D/g,'').substring(0,16);
    v = v.match(/.{1,4}/g)?.join(' ') || v;
    input.value = v;
}

function fmtExp(input) {
    let v = input.value.replace(/\D/g,'').substring(0,4);
    if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2);
    input.value = v;
}

function processPayment() {
    const name = document.getElementById('cardName').value.trim();
    const num  = document.getElementById('cardNum').value.trim();
    const exp  = document.getElementById('cardExp').value.trim();
    const cvv  = document.getElementById('cardCvv').value.trim();

    if (!name) { alert('Please enter card holder name.'); return; }
    if (num.replace(/\s/g,'').length < 16) {
        alert('Please enter a valid 16-digit card number.'); return;
    }
    if (exp.length < 5) {
        alert('Please enter expiry date.'); return;
    }
    if (cvv.length < 3) {
        alert('Please enter CVV.'); return;
    }

    document.getElementById('payBtn').disabled = true;
    document.getElementById('procOverlay').classList.add('active');

    setTimeout(() => {
        document.getElementById('procOverlay').classList.remove('active');

        const txn = 'DEMO-' + Math.random().toString(36)
                    .substring(2,10).toUpperCase();
        document.getElementById('txnPill').textContent = 'TXN: ' + txn;
        document.getElementById('formTier').value = modalTier;
        document.getElementById('formQty').value  = modalQty;
        document.getElementById('succOverlay').classList.add('active');
    }, 2000);
}

document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

</body>
</html>