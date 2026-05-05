<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits_helper.php';
require_once __DIR__ . '/../includes/tax_rules_helper.php';

requireAdvisor();

$advisor        = currentUser();
$tax_rules_text = getActiveTaxRules($pdo);
$result         = null;
$error          = '';

initWelcomeOffer($pdo, $advisor['id']);
$credits_summary = getCreditSummary($pdo, $advisor['id']);

// Fetch clients for dropdown
$clients_list = $pdo->prepare("
    SELECT client_id, full_name, tax_year
    FROM clients WHERE advisor_id = ?
    ORDER BY full_name ASC
");
$clients_list->execute([$advisor['id']]);
$clients_list = $clients_list->fetchAll();

// ── HANDLE COMPARISON ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compare'])) {

    $country   = sanitize($_POST['country'] ?? 'Canada');
    $need_gold = isInternationalScenario($country);
    $tier      = $need_gold ? 'gold' : 'silver';
    $available = getAvailableCredits($pdo, $advisor['id'], $tier);

    if ($available <= 0) {
        $error = "No " . ucfirst($tier) . " credits available. "
               . "<a href='subscription.php' style='color:#c0392b;font-weight:700;'>"
               . "Buy " . ucfirst($tier) . " credits →</a>";
    } else {
        useCredit($pdo, $advisor['id'], $tier, 'SCENARIO_COMPARE');

        $credits_summary = getCreditSummary($pdo, $advisor['id']);

        $client_id = (int)($_POST['client_id'] ?? 0);
        $country   = sanitize($_POST['country'] ?? 'Canada');

        // Scenario A
        $a = [
            'label'            => sanitize($_POST['label_a']            ?? 'Scenario A'),
            'employment'       => (float)($_POST['employment_a']        ?? 0),
            'self_employment'  => (float)($_POST['self_employment_a']   ?? 0),
            'rental'           => (float)($_POST['rental_a']            ?? 0),
            'investment'       => (float)($_POST['investment_a']        ?? 0),
            'other_income'     => (float)($_POST['other_income_a']      ?? 0),
            'rrsp'             => (float)($_POST['rrsp_a']              ?? 0),
            'childcare'        => (float)($_POST['childcare_a']         ?? 0),
            'medical'          => (float)($_POST['medical_a']           ?? 0),
            'charitable'       => (float)($_POST['charitable_a']        ?? 0),
            'business_expense' => (float)($_POST['business_expense_a']  ?? 0),
            'other_deductions' => (float)($_POST['other_deductions_a']  ?? 0),
            'tax_withheld'     => (float)($_POST['tax_withheld_a']      ?? 0),
            'filing_status'    => sanitize($_POST['filing_status_a']    ?? 'Single'),
        ];

        // Scenario B
        $b = [
            'label'            => sanitize($_POST['label_b']            ?? 'Scenario B'),
            'employment'       => (float)($_POST['employment_b']        ?? 0),
            'self_employment'  => (float)($_POST['self_employment_b']   ?? 0),
            'rental'           => (float)($_POST['rental_b']            ?? 0),
            'investment'       => (float)($_POST['investment_b']        ?? 0),
            'other_income'     => (float)($_POST['other_income_b']      ?? 0),
            'rrsp'             => (float)($_POST['rrsp_b']              ?? 0),
            'childcare'        => (float)($_POST['childcare_b']         ?? 0),
            'medical'          => (float)($_POST['medical_b']           ?? 0),
            'charitable'       => (float)($_POST['charitable_b']        ?? 0),
            'business_expense' => (float)($_POST['business_expense_b']  ?? 0),
            'other_deductions' => (float)($_POST['other_deductions_b']  ?? 0),
            'tax_withheld'     => (float)($_POST['tax_withheld_b']      ?? 0),
            'filing_status'    => sanitize($_POST['filing_status_b']    ?? 'Single'),
        ];

        // Build prompt for Groq
        $prompt = 'You are TaxStart AI, an expert international tax calculation engine.

TASK: Calculate taxes for TWO scenarios and return ONLY the results in this exact format. No explanations, no suggestions, just clean numbers and final results.
' . ($tax_rules_text ? "USE THESE OFFICIAL TAX RULES:\n" . $tax_rules_text . "\n\n" : '') . '
COUNTRY: ' . $country . '
TAX YEAR: ' . date('Y') . '

--- SCENARIO A: ' . $a['label'] . ' ---
Filing Status: ' . $a['filing_status'] . '
Employment Income: ' . $a['employment'] . '
Self Employment Income: ' . $a['self_employment'] . '
Rental Income: ' . $a['rental'] . '
Investment Income: ' . $a['investment'] . '
Other Income: ' . $a['other_income'] . '
RRSP/Retirement Deduction: ' . $a['rrsp'] . '
Childcare Deduction: ' . $a['childcare'] . '
Medical Expenses: ' . $a['medical'] . '
Charitable Donations: ' . $a['charitable'] . '
Business Expenses: ' . $a['business_expense'] . '
Other Deductions: ' . $a['other_deductions'] . '
Tax Already Withheld: ' . $a['tax_withheld'] . '

--- SCENARIO B: ' . $b['label'] . ' ---
Filing Status: ' . $b['filing_status'] . '
Employment Income: ' . $b['employment'] . '
Self Employment Income: ' . $b['self_employment'] . '
Rental Income: ' . $b['rental'] . '
Investment Income: ' . $b['investment'] . '
Other Income: ' . $b['other_income'] . '
RRSP/Retirement Deduction: ' . $b['rrsp'] . '
Childcare Deduction: ' . $b['childcare'] . '
Medical Expenses: ' . $b['medical'] . '
Charitable Donations: ' . $b['charitable'] . '
Business Expenses: ' . $b['business_expense'] . '
Other Deductions: ' . $b['other_deductions'] . '
Tax Already Withheld: ' . $b['tax_withheld'] . '

Return your response in this EXACT format with no extra text:

SCENARIO_A_LABEL: [label]
SCENARIO_A_GROSS_INCOME: [number only]
SCENARIO_A_TOTAL_DEDUCTIONS: [number only]
SCENARIO_A_TAXABLE_INCOME: [number only]
SCENARIO_A_GROSS_TAX: [number only]
SCENARIO_A_CREDITS: [number only]
SCENARIO_A_NET_TAX: [number only]
SCENARIO_A_TAX_WITHHELD: [number only]
SCENARIO_A_REFUND_OR_OWED: [number only, negative means owed]
SCENARIO_A_EFFECTIVE_RATE: [percentage number only]
SCENARIO_A_STATUS: [REFUND or OWED]

SCENARIO_B_LABEL: [label]
SCENARIO_B_GROSS_INCOME: [number only]
SCENARIO_B_TOTAL_DEDUCTIONS: [number only]
SCENARIO_B_TAXABLE_INCOME: [number only]
SCENARIO_B_GROSS_TAX: [number only]
SCENARIO_B_CREDITS: [number only]
SCENARIO_B_NET_TAX: [number only]
SCENARIO_B_TAX_WITHHELD: [number only]
SCENARIO_B_REFUND_OR_OWED: [number only, negative means owed]
SCENARIO_B_EFFECTIVE_RATE: [percentage number only]
SCENARIO_B_STATUS: [REFUND or OWED]

BETTER_SCENARIO: [A or B]
DIFFERENCE: [number only, absolute difference in tax]';

        // ── TAXSTART AI NEURO-SYMBOLIC ENGINE ────────────
        require_once __DIR__ . '/../includes/TaxStartAI.php';
        $taxAI  = new TaxStartAI($pdo);
        $result = $taxAI->compareScenarios($a, $b, $country, $tax_rules_text ?? null);
        if (empty($result)) {
            $error = "Could not get results. Please try again.";
        }
    } // closes else (credit available)
} // closes if POST

// Helper to format currency
function fmt($val) {
    $num = (float) preg_replace('/[^0-9.\-]/', '', $val ?? '0');
    return '$' . number_format(abs($num), 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compare Scenarios – TaxStart AI</title>
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
        .topbar p  { font-size:13px; color:#888; }

        .page-body { padding:30px 32px; flex:1; }

        .alert-error {
            background:#fff0f0; border-left:4px solid #e94560;
            color:#c0392b; padding:13px 18px; border-radius:10px;
            font-size:14px; margin-bottom:22px;
        }

        .top-row {
            background:#fff; border-radius:14px; padding:22px 26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            margin-bottom:24px;
            display:flex; gap:20px; align-items:flex-end;
        }

        .top-row .form-group { flex:1; }

        .form-group label {
            display:block; font-size:12px; font-weight:700;
            color:#555; margin-bottom:7px;
            text-transform:uppercase; letter-spacing:0.5px;
        }

        .form-group select,
        .form-group input {
            width:100%; padding:11px 14px;
            border:2px solid #e8e8e8; border-radius:10px;
            font-size:14px; color:#333; outline:none;
            transition:border-color 0.3s; background:#fff;
        }

        .form-group select:focus,
        .form-group input:focus { border-color:#0f3460; }

        .scenarios-grid {
            display:grid; grid-template-columns:1fr 1fr;
            gap:24px; margin-bottom:24px;
        }

        .scenario-card {
            background:#fff; border-radius:14px; padding:26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .scenario-card.scenario-a { border-top:4px solid #1a73e8; }
        .scenario-card.scenario-b { border-top:4px solid #e94560; }

        .scenario-header {
            display:flex; align-items:center;
            gap:12px; margin-bottom:22px;
        }

        .scenario-badge {
            width:36px; height:36px; border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            font-size:16px; font-weight:700; color:#fff;
        }

        .badge-a { background:linear-gradient(135deg,#1a73e8,#0f3460); }
        .badge-b { background:linear-gradient(135deg,#e94560,#7b2d8b); }

        .scenario-header input {
            flex:1; padding:8px 12px;
            border:2px solid #e8e8e8; border-radius:8px;
            font-size:14px; font-weight:600; color:#1a1a2e;
            outline:none; transition:border-color 0.3s;
        }

        .scenario-header input:focus { border-color:#0f3460; }

        .field-section {
            font-size:11px; font-weight:700; color:#aaa;
            text-transform:uppercase; letter-spacing:1px;
            padding:10px 0 8px; border-bottom:2px solid #f5f5f5;
            margin-bottom:14px; margin-top:18px;
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

        .field-row input[type="number"] {
            width:140px; padding:8px 12px;
            border:2px solid #e8e8e8; border-radius:8px;
            font-size:14px; color:#333; text-align:right;
            outline:none; transition:border-color 0.3s;
        }

        .field-row input[type="number"]:focus { border-color:#0f3460; }

        .field-row select {
            width:140px; padding:8px 12px;
            border:2px solid #e8e8e8; border-radius:8px;
            font-size:13px; color:#333; outline:none;
            transition:border-color 0.3s; background:#fff;
        }

        .field-row select:focus { border-color:#0f3460; }

        .submit-row { text-align:center; margin-bottom:30px; }

        .btn-compare {
            padding:16px 60px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; border:none; border-radius:12px;
            font-size:16px; font-weight:700; cursor:pointer;
            transition:opacity 0.2s; letter-spacing:0.5px;
        }

        .btn-compare:hover { opacity:0.9; }

        .btn-reset {
            padding:16px 30px; background:#f0f0f0; color:#555;
            border:none; border-radius:12px; font-size:15px;
            font-weight:600; cursor:pointer; margin-left:12px;
            text-decoration:none; display:inline-block;
        }

        .btn-reset:hover { background:#e0e0e0; }

        .results-section { margin-top:10px; }

        .winner-banner {
            border-radius:14px; padding:24px 30px;
            margin-bottom:24px; text-align:center; color:white;
        }

        .winner-banner.winner-a { background:linear-gradient(135deg,#1a73e8,#0f3460); }
        .winner-banner.winner-b { background:linear-gradient(135deg,#e94560,#7b2d8b); }

        .winner-banner h2 { font-size:22px; font-weight:700; margin-bottom:6px; }
        .winner-banner p  { font-size:15px; opacity:0.9; }

        .results-grid {
            display:grid; grid-template-columns:1fr 1fr;
            gap:24px; margin-bottom:24px;
        }

        .result-card {
            background:#fff; border-radius:14px; padding:26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .result-card.result-a { border-top:4px solid #1a73e8; }
        .result-card.result-b { border-top:4px solid #e94560; }
        .result-card.winner   { box-shadow:0 4px 24px rgba(0,0,0,0.12); }

        .result-card h3 {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:18px; display:flex; align-items:center; gap:10px;
        }

        .result-row {
            display:flex; justify-content:space-between;
            align-items:center; padding:10px 0;
            border-bottom:1px solid #f5f5f5; font-size:14px;
        }

        .result-row:last-child { border-bottom:none; }
        .result-row .r-label  { color:#666; font-weight:500; }
        .result-row .r-value  { font-weight:700; color:#1a1a2e; }

        .result-row.highlight {
            background:#f8f9fa; margin:0 -10px; padding:12px 10px;
            border-radius:8px; border-bottom:none; margin-bottom:4px;
        }

        .result-row.highlight .r-label { color:#0f3460; font-weight:700; }
        .result-row.highlight .r-value { font-size:18px; }

        .refund-amount {
            font-size:28px; font-weight:700;
            text-align:center; padding:16px;
            border-radius:12px; margin-top:14px;
        }

        .refund-amount.refund { background:#e6f9f0; color:#1e8449; }
        .refund-amount.owed   { background:#fdecea; color:#c0392b; }

        .refund-label {
            font-size:12px; font-weight:600;
            text-transform:uppercase; letter-spacing:1px;
            opacity:0.7; margin-bottom:4px;
        }

        .compare-table-card {
            background:#fff; border-radius:14px; padding:26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .compare-table-card h3 {
            font-size:16px; font-weight:700; color:#1a1a2e; margin-bottom:18px;
        }

        table { width:100%; border-collapse:collapse; font-size:14px; }

        thead th {
            padding:12px 16px; text-align:left;
            font-size:12px; font-weight:700; color:#888;
            text-transform:uppercase; letter-spacing:0.5px;
            border-bottom:2px solid #f0f0f0;
        }

        thead th.col-a { color:#1a73e8; }
        thead th.col-b { color:#e94560; }

        tbody tr { border-bottom:1px solid #f7f7f7; }
        tbody tr:hover { background:#fafafa; }

        tbody td { padding:12px 16px; color:#333; vertical-align:middle; }
        tbody td.col-a { color:#1a73e8; font-weight:600; }
        tbody td.col-b { color:#e94560; font-weight:600; }

        .loading-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.5); z-index:999;
            align-items:center; justify-content:center;
            flex-direction:column; color:white;
        }

        .loading-overlay.active { display:flex; }

        .spinner {
            width:60px; height:60px; border:4px solid rgba(255,255,255,0.3);
            border-top-color:#fff; border-radius:50%;
            animation:spin 0.9s linear infinite; margin-bottom:20px;
        }

        @keyframes spin { to { transform:rotate(360deg); } }

        .loading-overlay p { font-size:16px; font-weight:600; opacity:0.9; }
    </style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <p>AI is calculating both scenarios...</p>
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
        <a href="compare.php" class="nav-item active">
            <span class="icon">⚖️</span> Compare Scenarios
        </a>
        <a href="calculator.php" class="nav-item">
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
            <h1>⚖️ Tax Scenario Comparison</h1>
            <p>Enter two scenarios and AI will calculate and compare the tax results</p>
        </div>
    </div>

    <div class="page-body">

        <?php if (!empty($error)): ?>
            <div class="alert-error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="compare.php" id="compareForm">

            <div class="top-row">
                <div class="form-group">
                    <label>Select Client (Optional)</label>
                    <select name="client_id">
                        <option value="0">-- No specific client --</option>
                        <?php foreach ($clients_list as $cl): ?>
                            <option value="<?= $cl['client_id'] ?>"
                                <?= ($_POST['client_id'] ?? 0) == $cl['client_id']
                                    ? 'selected' : '' ?>>
                                <?= sanitize($cl['full_name']) ?>
                                <?= $cl['tax_year'] ? '(' . $cl['tax_year'] . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Country / Tax Jurisdiction</label>
                    <select name="country">
                        <?php
                        $countries = [
                            'Canada','United States','United Kingdom',
                            'Australia','India','Germany','France',
                            'UAE','Singapore','New Zealand','Other'
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
            </div>

            <div class="scenarios-grid">
                <?php foreach (['a', 'b'] as $s):
                    $label  = $s === 'a' ? 'Scenario A' : 'Scenario B';
                    $cls    = $s === 'a' ? 'scenario-a' : 'scenario-b';
                    $bdg    = $s === 'a' ? 'badge-a'    : 'badge-b';
                    $letter = strtoupper($s);
                    $post   = $_POST ?? [];
                ?>
                <div class="scenario-card <?= $cls ?>">
                    <div class="scenario-header">
                        <div class="scenario-badge <?= $bdg ?>"><?= $letter ?></div>
                        <input type="text" name="label_<?= $s ?>"
                               value="<?= sanitize($post["label_$s"] ?? $label) ?>"
                               placeholder="<?= $label ?> Name">
                    </div>

                    <div class="field-section">Filing Information</div>
                    <div class="field-row">
                        <label>Filing Status</label>
                        <select name="filing_status_<?= $s ?>">
                            <?php foreach ([
                                'Single','Married Filing Jointly',
                                'Married Filing Separately','Head of Household'
                            ] as $fs): ?>
                                <option value="<?= $fs ?>"
                                    <?= ($post["filing_status_$s"] ?? 'Single') === $fs
                                        ? 'selected' : '' ?>>
                                    <?= $fs ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-section">Income Sources</div>
                    <div class="field-row">
                        <label>Employment Income</label>
                        <input type="number" name="employment_<?= $s ?>"
                               value="<?= $post["employment_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Self-Employment Income</label>
                        <input type="number" name="self_employment_<?= $s ?>"
                               value="<?= $post["self_employment_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Rental Income</label>
                        <input type="number" name="rental_<?= $s ?>"
                               value="<?= $post["rental_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Investment Income</label>
                        <input type="number" name="investment_<?= $s ?>"
                               value="<?= $post["investment_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Other Income</label>
                        <input type="number" name="other_income_<?= $s ?>"
                               value="<?= $post["other_income_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>

                    <div class="field-section">Deductions</div>
                    <div class="field-row">
                        <label>RRSP / Retirement Contributions</label>
                        <input type="number" name="rrsp_<?= $s ?>"
                               value="<?= $post["rrsp_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Childcare Expenses</label>
                        <input type="number" name="childcare_<?= $s ?>"
                               value="<?= $post["childcare_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Medical Expenses</label>
                        <input type="number" name="medical_<?= $s ?>"
                               value="<?= $post["medical_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Charitable Donations</label>
                        <input type="number" name="charitable_<?= $s ?>"
                               value="<?= $post["charitable_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Business Expenses</label>
                        <input type="number" name="business_expense_<?= $s ?>"
                               value="<?= $post["business_expense_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                    <div class="field-row">
                        <label>Other Deductions</label>
                        <input type="number" name="other_deductions_<?= $s ?>"
                               value="<?= $post["other_deductions_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>

                    <div class="field-section">Tax Already Paid</div>
                    <div class="field-row">
                        <label>Tax Withheld / Already Paid</label>
                        <input type="number" name="tax_withheld_<?= $s ?>"
                               value="<?= $post["tax_withheld_$s"] ?? 0 ?>"
                               min="0" step="0.01">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="submit-row">
                <button type="submit" name="compare"
                        class="btn-compare" onclick="showLoading()">
                    Calculate &amp; Compare Both Scenarios
                </button>
                <a href="compare.php" class="btn-reset">Reset</a>
            </div>
        </form>

        <?php if ($result): ?>
        <div class="results-section">
            <?php
            $better       = $result['BETTER_SCENARIO'] ?? 'A';
            $diff         = $result['DIFFERENCE']      ?? '0';
            $winner_label = $better === 'A'
                ? ($result['SCENARIO_A_LABEL'] ?? 'Scenario A')
                : ($result['SCENARIO_B_LABEL'] ?? 'Scenario B');
            $winner_cls   = $better === 'A' ? 'winner-a' : 'winner-b';
            ?>

            <div class="winner-banner <?= $winner_cls ?>">
                <h2>Scenario <?= $better ?> is Better: <?= sanitize($winner_label) ?></h2>
                <p>Saves <?= fmt($diff) ?> more in taxes compared to the other scenario</p>
            </div>

            <div class="results-grid">
                <?php foreach (['A', 'B'] as $s):
                    $cls    = $s === 'A' ? 'result-a' : 'result-b';
                    $win    = $better === $s ? 'winner' : '';
                    $status = $result["SCENARIO_{$s}_STATUS"]       ?? 'OWED';
                    $refund = $result["SCENARIO_{$s}_REFUND_OR_OWED"] ?? '0';
                    $rfcls  = $status === 'REFUND' ? 'refund' : 'owed';
                    $rflbl  = $status === 'REFUND' ? 'Tax Refund' : 'Tax Owed';
                ?>
                <div class="result-card <?= $cls ?> <?= $win ?>">
                    <h3>
                        <?php if ($better === $s): ?>WINNER — <?php endif; ?>
                        Scenario <?= $s ?>:
                        <?= sanitize($result["SCENARIO_{$s}_LABEL"] ?? '') ?>
                    </h3>
                    <div class="result-row">
                        <span class="r-label">Gross Income</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_GROSS_INCOME"] ?? 0) ?></span>
                    </div>
                    <div class="result-row">
                        <span class="r-label">Total Deductions</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_TOTAL_DEDUCTIONS"] ?? 0) ?></span>
                    </div>
                    <div class="result-row">
                        <span class="r-label">Taxable Income</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_TAXABLE_INCOME"] ?? 0) ?></span>
                    </div>
                    <div class="result-row">
                        <span class="r-label">Gross Tax</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_GROSS_TAX"] ?? 0) ?></span>
                    </div>
                    <div class="result-row">
                        <span class="r-label">Credits Applied</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_CREDITS"] ?? 0) ?></span>
                    </div>
                    <div class="result-row highlight">
                        <span class="r-label">Net Tax Payable</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_NET_TAX"] ?? 0) ?></span>
                    </div>
                    <div class="result-row">
                        <span class="r-label">Tax Withheld</span>
                        <span class="r-value"><?= fmt($result["SCENARIO_{$s}_TAX_WITHHELD"] ?? 0) ?></span>
                    </div>
                    <div class="result-row">
                        <span class="r-label">Effective Tax Rate</span>
                        <span class="r-value"><?= $result["SCENARIO_{$s}_EFFECTIVE_RATE"] ?? '0' ?>%</span>
                    </div>
                    <div class="refund-amount <?= $rfcls ?>">
                        <div class="refund-label"><?= $rflbl ?></div>
                        <?= fmt($refund) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="compare-table-card">
                <h3>Side-by-Side Comparison</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="col-a"><?= sanitize($result['SCENARIO_A_LABEL'] ?? 'Scenario A') ?></th>
                            <th class="col-b"><?= sanitize($result['SCENARIO_B_LABEL'] ?? 'Scenario B') ?></th>
                            <th>Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rows = [
                            'Gross Income'     => 'GROSS_INCOME',
                            'Total Deductions' => 'TOTAL_DEDUCTIONS',
                            'Taxable Income'   => 'TAXABLE_INCOME',
                            'Gross Tax'        => 'GROSS_TAX',
                            'Credits'          => 'CREDITS',
                            'Net Tax Payable'  => 'NET_TAX',
                            'Tax Withheld'     => 'TAX_WITHHELD',
                            'Refund / Owed'    => 'REFUND_OR_OWED',
                            'Effective Rate'   => 'EFFECTIVE_RATE',
                        ];
                        foreach ($rows as $label => $key):
                            $va      = $result["SCENARIO_A_{$key}"] ?? '0';
                            $vb      = $result["SCENARIO_B_{$key}"] ?? '0';
                            $na      = (float)preg_replace('/[^0-9.\-]/', '', $va);
                            $nb      = (float)preg_replace('/[^0-9.\-]/', '', $vb);
                            $dif     = $na - $nb;
                            $is_rate = $key === 'EFFECTIVE_RATE';
                        ?>
                        <tr>
                            <td><strong><?= $label ?></strong></td>
                            <td class="col-a"><?= $is_rate ? $va . '%' : fmt($va) ?></td>
                            <td class="col-b"><?= $is_rate ? $vb . '%' : fmt($vb) ?></td>
                            <td style="color:<?= $dif >= 0 ? '#1e8449' : '#c0392b' ?>;font-weight:600;">
                                <?= $is_rate
                                    ? number_format(abs($dif), 2) . '%'
                                    : fmt(abs($dif)) ?>
                                <?= $dif >= 0 ? '▲' : '▼' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
function showLoading() {
    document.getElementById('loadingOverlay').classList.add('active');
}
</script>

</body>
</html>