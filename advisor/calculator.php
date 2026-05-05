<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits_helper.php';
require_once __DIR__ . '/../includes/tax_rules_helper.php';
require_once __DIR__ . '/../includes/TaxStartAI.php';

requireAdvisor();

$advisor         = currentUser();
$tax_rules_text  = getActiveTaxRules($pdo);
$result          = null;
$error           = '';

// Initialize welcome offer AFTER advisor is loaded
initWelcomeOffer($pdo, $advisor['id']);
$credits_summary = getCreditSummary($pdo, $advisor['id']);

// Fetch clients
$clients_list = $pdo->prepare("
    SELECT client_id, full_name, tax_year
    FROM clients WHERE advisor_id = ?
    ORDER BY full_name ASC
");
$clients_list->execute([$advisor['id']]);
$clients_list = $clients_list->fetchAll();

// ── HANDLE CALCULATION ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate'])) {

    $client_id      = (int)($_POST['client_id']      ?? 0);
    $country        = sanitize($_POST['country']      ?? 'Canada');
    $tax_year       = sanitize($_POST['tax_year']     ?? date('Y'));
    $filing_status  = sanitize($_POST['filing_status']?? 'Single');

    // Income
    $employment      = (float)($_POST['employment']      ?? 0);
    $self_employment = (float)($_POST['self_employment'] ?? 0);
    $rental          = (float)($_POST['rental']          ?? 0);
    $investment      = (float)($_POST['investment']      ?? 0);
    $other_income    = (float)($_POST['other_income']    ?? 0);

    // Deductions
    $rrsp            = (float)($_POST['rrsp']            ?? 0);
    $childcare       = (float)($_POST['childcare']       ?? 0);
    $medical         = (float)($_POST['medical']         ?? 0);
    $charitable      = (float)($_POST['charitable']      ?? 0);
    $business_exp    = (float)($_POST['business_exp']    ?? 0);
    $other_ded       = (float)($_POST['other_ded']       ?? 0);

    // Tax paid
    $tax_withheld    = (float)($_POST['tax_withheld']    ?? 0);

    // ── CREDIT CHECK ─────────────────────────────────
    $need_gold = isInternationalScenario($country);
    $tier      = $need_gold ? 'gold' : 'silver';
    $available = getAvailableCredits($pdo, $advisor['id'], $tier);

    if ($available <= 0) {
        $error = "No " . ucfirst($tier) . " credits available. "
               . "<a href='subscription.php' "
               . "style='color:#c0392b;font-weight:700;'>"
               . "Purchase " . ucfirst($tier) . " credits here →</a>";
    } else {
        // Use one credit
        useCredit($pdo, $advisor['id'], $tier, 'TAX_CALCULATOR', null);

        // Refresh credit summary after use
        $credits_summary = getCreditSummary($pdo, $advisor['id']);

        // ── BUILD PROMPT AND CALL AI ──────────────────
        $prompt = 'You are TaxStart AI, a precise international tax calculation engine.

TASK: Calculate the exact tax for the following information. Return ONLY the structured result in the exact format below. No explanations, no extra text, just the numbers.

TAX INFORMATION:
' . ($tax_rules_text ? "USE THESE OFFICIAL TAX RULES:\n" . $tax_rules_text . "\n\n" : '') . '
Country: ' . $country . '
Tax Year: ' . $tax_year . '
Filing Status: ' . $filing_status . '

INCOME:
Employment Income: ' . $employment . '
Self-Employment Income: ' . $self_employment . '
Rental Income: ' . $rental . '
Investment Income: ' . $investment . '
Other Income: ' . $other_income . '

DEDUCTIONS:
RRSP / Retirement Contributions: ' . $rrsp . '
Childcare Expenses: ' . $childcare . '
Medical Expenses: ' . $medical . '
Charitable Donations: ' . $charitable . '
Business Expenses: ' . $business_exp . '
Other Deductions: ' . $other_ded . '

TAX ALREADY PAID:
Tax Withheld: ' . $tax_withheld . '

Return ONLY in this exact format:

GROSS_INCOME: [number]
TOTAL_DEDUCTIONS: [number]
TAXABLE_INCOME: [number]
GROSS_TAX: [number]
TAX_CREDITS: [number]
NET_TAX: [number]
TAX_WITHHELD: [number]
REFUND_OR_OWED: [number, positive means refund, negative means owed]
EFFECTIVE_RATE: [percentage number only]
STATUS: [REFUND or OWED]
BRACKET_1_INCOME: [number]
BRACKET_1_RATE: [number]
BRACKET_1_TAX: [number]
BRACKET_2_INCOME: [number]
BRACKET_2_RATE: [number]
BRACKET_2_TAX: [number]
BRACKET_3_INCOME: [number]
BRACKET_3_RATE: [number]
BRACKET_3_TAX: [number]';

        // ── TAXSTART AI NEURO-SYMBOLIC ENGINE ────────────
        require_once __DIR__ . '/../includes/TaxStartAI.php';
        $taxAI  = new TaxStartAI($pdo);
        $inputs = [
            'country'         => $country,
            'tax_year'        => $tax_year,
            'filing_status'   => $filing_status,
            'employment'      => $employment,
            'self_employment' => $self_employment,
            'rental'          => $rental,
            'investment'      => $investment,
            'other_income'    => $other_income,
            'rrsp'            => $rrsp,
            'childcare'       => $childcare,
            'medical'         => $medical,
            'charitable'      => $charitable,
            'business_exp'    => $business_exp,
            'other_ded'       => $other_ded,
            'tax_withheld'    => $tax_withheld,
            '_tax_rules'      => $tax_rules_text ?? '',
        ];
        $result = $taxAI->calculateTax($inputs);
echo '<div style="background:#1a1a2e;color:#00FF41;padding:20px;font-family:monospace;font-size:13px;position:fixed;bottom:0;left:0;right:0;z-index:9999;">
<strong style="color:#f5c518;">🤖 TaxStartAI Neuro-Symbolic Engine v1.0</strong><br>
Confidence: ' . $taxAI->getConfidence() . '%  |  
Retries: ' . $taxAI->getRetries() . '  |  
Rules Checked: ' . count($taxAI->getRuleChecks()) . '  |  
Engine: TaxStartAI → Groq API
</div>';
        if (empty($result) || isset($result['_error'])) {
            $error = "Could not calculate. Please try again.";
        }
    } // closes else (credit available)
} // closes if POST

function fmtc($val) {
    $num = (float) preg_replace('/[^0-9.\-]/', '', $val ?? '0');
    return '$' . number_format(abs($num), 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Calculator – TaxStart AI</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            font-size:14px; font-weight:600; transition:background 0.2s;
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
        .alert-error {
            background:#fff0f0; border-left:4px solid #e94560;
            color:#c0392b; padding:13px 18px; border-radius:10px;
            font-size:14px; margin-bottom:22px;
        }
        .calc-layout {
            display:grid; grid-template-columns:420px 1fr;
            gap:26px; align-items:start;
        }
        .form-card {
            background:#fff; border-radius:14px; padding:28px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            border-top:4px solid #1a73e8;
        }
        .form-card h3 {
            font-size:16px; font-weight:700;
            color:#1a1a2e; margin-bottom:20px;
        }
        .field-section {
            font-size:11px; font-weight:700; color:#1a73e8;
            text-transform:uppercase; letter-spacing:1px;
            padding:10px 0 8px; border-bottom:2px solid #e8f0fe;
            margin-bottom:14px; margin-top:20px;
        }
        .field-section:first-of-type { margin-top:0; }
        .field-row {
            display:flex; align-items:center;
            justify-content:space-between;
            padding:8px 0; border-bottom:1px solid #f9f9f9;
        }
        .field-row:last-child { border-bottom:none; }
        .field-row label {
            font-size:13px; color:#555; font-weight:500; flex:1;
        }
        .field-row input[type="number"],
        .field-row select {
            width:150px; padding:9px 12px;
            border:2px solid #e8e8e8; border-radius:8px;
            font-size:14px; color:#333; text-align:right;
            outline:none; transition:border-color 0.3s; background:#fff;
        }
        .field-row select { text-align:left; }
        .field-row input:focus,
        .field-row select:focus { border-color:#1a73e8; }
        .top-fields {
            display:grid; grid-template-columns:1fr 1fr;
            gap:14px; margin-bottom:6px;
        }
        .top-field label {
            display:block; font-size:12px; font-weight:700;
            color:#555; margin-bottom:7px;
            text-transform:uppercase; letter-spacing:0.5px;
        }
        .top-field select,
        .top-field input {
            width:100%; padding:10px 13px;
            border:2px solid #e8e8e8; border-radius:9px;
            font-size:13px; color:#333; outline:none;
            transition:border-color 0.3s; background:#fff;
        }
        .top-field select:focus,
        .top-field input:focus { border-color:#1a73e8; }
        .btn-calculate {
            width:100%; padding:15px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; border:none; border-radius:10px;
            font-size:15px; font-weight:700; cursor:pointer;
            margin-top:22px; transition:opacity 0.2s;
        }
        .btn-calculate:hover { opacity:0.9; }
        .btn-reset {
            width:100%; padding:12px; background:#f0f0f0; color:#555;
            border:none; border-radius:10px; font-size:14px;
            font-weight:600; cursor:pointer; margin-top:10px;
            text-decoration:none; display:block; text-align:center;
        }
        .btn-reset:hover { background:#e0e0e0; }
        .results-col { display:flex; flex-direction:column; gap:20px; }
        .main-result { border-radius:14px; padding:30px; text-align:center; color:white; }
        .main-result.refund { background:linear-gradient(135deg,#1e8449,#2ecc71); }
        .main-result.owed   { background:linear-gradient(135deg,#c0392b,#e94560); }
        .main-result .status-label {
            font-size:13px; font-weight:700; text-transform:uppercase;
            letter-spacing:2px; opacity:0.85; margin-bottom:10px;
        }
        .main-result .amount { font-size:52px; font-weight:700; line-height:1; margin-bottom:8px; }
        .main-result .sub   { font-size:14px; opacity:0.85; }
        .breakdown-card {
            background:#fff; border-radius:14px; padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }
        .breakdown-card h3 {
            font-size:15px; font-weight:700; color:#1a1a2e;
            margin-bottom:16px; padding-bottom:10px;
            border-bottom:2px solid #f0f0f0;
        }
        .breakdown-row {
            display:flex; justify-content:space-between;
            align-items:center; padding:10px 0;
            border-bottom:1px solid #f7f7f7; font-size:14px;
        }
        .breakdown-row:last-child { border-bottom:none; }
        .breakdown-row .b-label { color:#666; font-weight:500; }
        .breakdown-row .b-value { font-weight:700; color:#1a1a2e; }
        .breakdown-row.total {
            background:#f8f9fa; margin:0 -10px; padding:12px 10px;
            border-radius:8px; border-bottom:none; margin-top:4px;
        }
        .breakdown-row.total .b-label { color:#0f3460; font-weight:700; font-size:15px; }
        .breakdown-row.total .b-value { font-size:18px; color:#0f3460; }
        .brackets-card {
            background:#fff; border-radius:14px; padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }
        .brackets-card h3 {
            font-size:15px; font-weight:700; color:#1a1a2e;
            margin-bottom:16px; padding-bottom:10px;
            border-bottom:2px solid #f0f0f0;
        }
        .bracket-row {
            display:flex; align-items:center; gap:12px;
            padding:10px 0; border-bottom:1px solid #f7f7f7;
        }
        .bracket-row:last-child { border-bottom:none; }
        .bracket-num {
            width:28px; height:28px; border-radius:8px;
            background:#e8f0fe; color:#1a73e8;
            display:flex; align-items:center; justify-content:center;
            font-size:13px; font-weight:700; flex-shrink:0;
        }
        .bracket-info { flex:1; }
        .bracket-info .b-range { font-size:13px; color:#555; font-weight:500; }
        .bracket-info .b-rate  { font-size:12px; color:#aaa; }
        .bracket-tax { font-size:14px; font-weight:700; color:#1a1a2e; }
        .rate-card {
            background:#fff; border-radius:14px; padding:24px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06); text-align:center;
        }
        .rate-card h3 {
            font-size:15px; font-weight:700; color:#1a1a2e; margin-bottom:20px;
        }
        .rate-circle {
            width:120px; height:120px; border-radius:50%;
            background:conic-gradient(#1a73e8 var(--pct), #f0f2f7 var(--pct));
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 16px; position:relative;
        }
        .rate-circle::before {
            content:''; position:absolute;
            width:90px; height:90px; border-radius:50%; background:#fff;
        }
        .rate-circle .rate-text {
            position:relative; z-index:1;
            font-size:20px; font-weight:700; color:#0f3460;
        }
        .rate-card p { font-size:13px; color:#888; }
        .empty-results {
            background:#fff; border-radius:14px; padding:60px 40px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            text-align:center; color:#bbb;
        }
        .empty-results .icon { font-size:50px; margin-bottom:16px; }
        .empty-results h3    { font-size:18px; color:#ccc; margin-bottom:8px; }
        .empty-results p     { font-size:14px; }
        .loading-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.55); z-index:999;
            align-items:center; justify-content:center;
            flex-direction:column; color:white;
        }
        .loading-overlay.active { display:flex; }
        .spinner {
            width:60px; height:60px;
            border:4px solid rgba(255,255,255,0.3);
            border-top-color:#fff; border-radius:50%;
            animation:spin 0.9s linear infinite; margin-bottom:20px;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        .loading-overlay p { font-size:16px; font-weight:600; opacity:0.9; }

        /* CREDIT WARNING */
        .credit-warning {
            background:#fff0f0; border:2px solid #e94560;
            border-radius:12px; padding:16px 20px;
            margin-bottom:20px; font-size:14px; color:#c0392b;
        }
    </style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <p>AI is calculating your tax...</p>
</div>

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
        <a href="calculator.php" class="nav-item active">
            <span class="icon">🧮</span> Tax Calculator
        </a>
        <a href="subscription.php" class="nav-item">
            <span class="icon">💳</span> Subscription
        </a>

        <!-- CREDIT BAR -->
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
                                    / $credits_summary['silver_total'])*100),100)
                                    : 0 ?>%;">
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
                                    / $credits_summary['gold_total'])*100),100)
                                    : 0 ?>%;">
                    </div>
                </div>
            </div>
            <a href="subscription.php"
               style="display:block;text-align:center;font-size:11px;
                      color:rgba(255,255,255,0.6);text-decoration:none;">
                + Buy Credits
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <a href="http://localhost/taxstart/logout.php"
           class="btn-logout">Logout</a>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <div>
            <h1>🧮 Tax Calculator</h1>
            <p>Enter income and deductions — AI calculates your exact tax</p>
        </div>
    </div>

    <div class="page-body">

        <?php if (!empty($error)): ?>
            <div class="alert-error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <div class="calc-layout">

            <div>
                <form method="POST" action="calculator.php" id="calcForm">
                    <div class="form-card">
                        <h3>Tax Information</h3>

                        <!-- Credit indicator on form -->
                        <div style="background:#f8f9fa;border-radius:8px;
                                    padding:10px 14px;margin-bottom:16px;
                                    font-size:13px;display:flex;gap:16px;">
                            <span>🥈 <strong>
                                <?= $credits_summary['silver_available'] ?>
                            </strong> Silver</span>
                            <span>🥇 <strong>
                                <?= $credits_summary['gold_available'] ?>
                            </strong> Gold</span>
                            <span style="color:#888;font-size:12px;">
                                Canada = Silver · International = Gold
                            </span>
                        </div>

                        <div class="top-fields">
                            <div class="top-field">
                                <label>Country</label>
                                <select name="country">
                                    <?php
                                    $countries = [
                                        'Canada','United States',
                                        'United Kingdom','Australia',
                                        'India','Germany','France',
                                        'UAE','Singapore',
                                        'New Zealand','Other'
                                    ];
                                    foreach ($countries as $c):
                                    ?>
                                    <option value="<?= $c ?>"
                                        <?= ($_POST['country'] ?? 'Canada') === $c
                                            ? 'selected' : '' ?>>
                                        <?= $c ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="top-field">
                                <label>Tax Year</label>
                                <select name="tax_year">
                                    <?php for ($y = date('Y'); $y >= 2018; $y--): ?>
                                        <option value="<?= $y ?>"
                                            <?= ($_POST['tax_year'] ?? date('Y')) == $y
                                                ? 'selected' : '' ?>>
                                            <?= $y ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="top-field">
                                <label>Client (Optional)</label>
                                <select name="client_id">
                                    <option value="0">-- None --</option>
                                    <?php foreach ($clients_list as $cl): ?>
                                        <option value="<?= $cl['client_id'] ?>"
                                            <?= ($_POST['client_id'] ?? 0) == $cl['client_id']
                                                ? 'selected' : '' ?>>
                                            <?= sanitize($cl['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="top-field">
                                <label>Filing Status</label>
                                <select name="filing_status">
                                    <?php foreach ([
                                        'Single',
                                        'Married Filing Jointly',
                                        'Married Filing Separately',
                                        'Head of Household'
                                    ] as $fs): ?>
                                        <option value="<?= $fs ?>"
                                            <?= ($_POST['filing_status'] ?? 'Single') === $fs
                                                ? 'selected' : '' ?>>
                                            <?= $fs ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="field-section">Income Sources</div>
                        <div class="field-row">
                            <label>Employment Income</label>
                            <input type="number" name="employment"
                                   value="<?= $_POST['employment'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Self-Employment Income</label>
                            <input type="number" name="self_employment"
                                   value="<?= $_POST['self_employment'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Rental Income</label>
                            <input type="number" name="rental"
                                   value="<?= $_POST['rental'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Investment Income</label>
                            <input type="number" name="investment"
                                   value="<?= $_POST['investment'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Other Income</label>
                            <input type="number" name="other_income"
                                   value="<?= $_POST['other_income'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>

                        <div class="field-section">Deductions</div>
                        <div class="field-row">
                            <label>RRSP / Retirement</label>
                            <input type="number" name="rrsp"
                                   value="<?= $_POST['rrsp'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Childcare Expenses</label>
                            <input type="number" name="childcare"
                                   value="<?= $_POST['childcare'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Medical Expenses</label>
                            <input type="number" name="medical"
                                   value="<?= $_POST['medical'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Charitable Donations</label>
                            <input type="number" name="charitable"
                                   value="<?= $_POST['charitable'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Business Expenses</label>
                            <input type="number" name="business_exp"
                                   value="<?= $_POST['business_exp'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>
                        <div class="field-row">
                            <label>Other Deductions</label>
                            <input type="number" name="other_ded"
                                   value="<?= $_POST['other_ded'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>

                        <div class="field-section">Tax Already Paid</div>
                        <div class="field-row">
                            <label>Tax Withheld / Already Paid</label>
                            <input type="number" name="tax_withheld"
                                   value="<?= $_POST['tax_withheld'] ?? 0 ?>"
                                   min="0" step="0.01">
                        </div>

                        <button type="submit" name="calculate"
                                class="btn-calculate"
                                onclick="showLoading()">
                            Calculate Tax
                        </button>
                        <a href="calculator.php" class="btn-reset">Reset</a>
                    </div>
                </form>
            </div>

            <div class="results-col">
                <?php if ($result): ?>
                    <?php
                    $status = $result['STATUS'] ?? 'OWED';
                    $refund = $result['REFUND_OR_OWED'] ?? '0';
                    $rate   = (float)($result['EFFECTIVE_RATE'] ?? 0);
                    $rfcls  = $status === 'REFUND' ? 'refund' : 'owed';
                    $rflbl  = $status === 'REFUND' ? 'Tax Refund' : 'Tax Owed';
                    $pct    = min(round($rate), 100);
                    ?>
                    <div class="main-result <?= $rfcls ?>">
                        <div class="status-label"><?= $rflbl ?></div>
                        <div class="amount"><?= fmtc($refund) ?></div>
                        <div class="sub">
                            <?= $result['country'] ?> &nbsp;|&nbsp;
                            <?= $result['tax_year'] ?> &nbsp;|&nbsp;
                            <?= $result['filing_status'] ?>
                        </div>
                    </div>
                    <div class="breakdown-card">
                        <h3>Tax Breakdown</h3>
                        <div class="breakdown-row">
                            <span class="b-label">Gross Income</span>
                            <span class="b-value"><?= fmtc($result['GROSS_INCOME'] ?? 0) ?></span>
                        </div>
                        <div class="breakdown-row">
                            <span class="b-label">Total Deductions</span>
                            <span class="b-value" style="color:#1e8449;">
                                - <?= fmtc($result['TOTAL_DEDUCTIONS'] ?? 0) ?>
                            </span>
                        </div>
                        <div class="breakdown-row">
                            <span class="b-label">Taxable Income</span>
                            <span class="b-value"><?= fmtc($result['TAXABLE_INCOME'] ?? 0) ?></span>
                        </div>
                        <div class="breakdown-row">
                            <span class="b-label">Gross Tax</span>
                            <span class="b-value" style="color:#c0392b;">
                                <?= fmtc($result['GROSS_TAX'] ?? 0) ?>
                            </span>
                        </div>
                        <div class="breakdown-row">
                            <span class="b-label">Tax Credits</span>
                            <span class="b-value" style="color:#1e8449;">
                                - <?= fmtc($result['TAX_CREDITS'] ?? 0) ?>
                            </span>
                        </div>
                        <div class="breakdown-row total">
                            <span class="b-label">Net Tax Payable</span>
                            <span class="b-value"><?= fmtc($result['NET_TAX'] ?? 0) ?></span>
                        </div>
                        <div class="breakdown-row">
                            <span class="b-label">Tax Already Paid</span>
                            <span class="b-value" style="color:#1e8449;">
                                - <?= fmtc($result['TAX_WITHHELD'] ?? 0) ?>
                            </span>
                        </div>
                        <div class="breakdown-row total">
                            <span class="b-label"><?= $rflbl ?></span>
                            <span class="b-value"
                                  style="color:<?= $status === 'REFUND'
                                      ? '#1e8449' : '#c0392b' ?>;">
                                <?= fmtc($refund) ?>
                            </span>
                        </div>
                    </div>
                    <div class="brackets-card">
                        <h3>Tax Bracket Breakdown</h3>
                        <?php for ($i = 1; $i <= 3; $i++):
                            $bi = $result["BRACKET_{$i}_INCOME"] ?? '0';
                            $br = $result["BRACKET_{$i}_RATE"]   ?? '0';
                            $bt = $result["BRACKET_{$i}_TAX"]    ?? '0';
                            if ((float)preg_replace('/[^0-9.]/', '', $bi) <= 0)
                                continue;
                        ?>
                        <div class="bracket-row">
                            <div class="bracket-num"><?= $i ?></div>
                            <div class="bracket-info">
                                <div class="b-range"><?= fmtc($bi) ?> taxable income</div>
                                <div class="b-rate">at <?= $br ?>% tax rate</div>
                            </div>
                            <div class="bracket-tax"><?= fmtc($bt) ?></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="rate-card">
                        <h3>Effective Tax Rate</h3>
                        <div class="rate-circle" style="--pct: <?= $pct ?>%">
                            <span class="rate-text"><?= $rate ?>%</span>
                        </div>
                        <p>You pay <?= $rate ?>% of your gross income in taxes</p>
                    </div>
                <?php else: ?>
                    <div class="empty-results">
                        <div class="icon">🧮</div>
                        <h3>Results will appear here</h3>
                        <p>Fill in the fields and click Calculate Tax</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}
</script>

</body>
</html>