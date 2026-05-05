<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireAdvisor();

$advisor      = currentUser();
$analysis     = null;
$analysis_id  = null;
$report_date  = null;
$report_time  = null;
$report_title = null;
$html         = null;
require_once __DIR__ . '/../includes/credits_helper.php';
initWelcomeOffer($pdo, $advisor['id']);
$credits_summary = getCreditSummary($pdo, $advisor['id']);

// ── GENERATE AI REPORT CONTENT ───────────────────────
function generateAIReport($analysis) {
    // ── TAXSTART AI NEURO-SYMBOLIC ENGINE ─────────────
    global $pdo;
    require_once __DIR__ . '/../includes/TaxStartAI.php';
    $taxAI = new TaxStartAI($pdo);
    return $taxAI->generateReport($analysis);
    // Legacy code below is bypassed
    $api_key = 'YOUR_GROQ_API_KEY_HERE';

    $prompt = 'You are TaxStart AI. Generate a structured tax report summary for the following analysis. Return ONLY this exact format with real numbers extracted from the analysis. No extra text.

CLIENT: ' . ($analysis['client_name'] ?? 'N/A') . '
TAX YEAR: ' . ($analysis['tax_year'] ?? 'N/A') . '
AI ANALYSIS TEXT:
' . substr($analysis['ai_advice'] ?? '', 0, 3000) . '

Return ONLY in this exact format:

COUNTRY: [detected country]
GROSS_INCOME: [number only, 0 if not found]
TOTAL_DEDUCTIONS: [number only, 0 if not found]
TAXABLE_INCOME: [number only, 0 if not found]
GROSS_TAX: [number only, 0 if not found]
TAX_CREDITS: [number only, 0 if not found]
NET_TAX: [number only, 0 if not found]
TAX_WITHHELD: [number only, 0 if not found]
REFUND_OR_OWED: [number only, positive=refund, negative=owed, 0 if not found]
EFFECTIVE_RATE: [percentage number only, 0 if not found]
STATUS: [REFUND or OWED or BALANCED]
COMPLIANCE_RISK: [Low or Medium or High]
MISSING_DOCS: [comma separated list of missing documents, or None]
TOP_RECOMMENDATION_1: [one sentence]
TOP_RECOMMENDATION_2: [one sentence]
TOP_RECOMMENDATION_3: [one sentence]
FILING_DEADLINE: [date or N/A]
BRACKET_1: [income range and rate, e.g. 0-50000 at 15%]
BRACKET_2: [income range and rate, or N/A]
BRACKET_3: [income range and rate, or N/A]';

    $payload = [
        'model'    => 'llama-3.3-70b-versatile',
        'messages' => [
            [
                'role'    => 'system',
                'content' => 'You are a tax report data extractor. Return only structured key:value pairs. No markdown, no explanations.'
            ],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens'  => 600,
        'temperature' => 0.1
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 60,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data   = json_decode($response, true);
    $raw    = $data['choices'][0]['message']['content'] ?? '';
    $result = [];
    foreach (explode("\n", $raw) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v]        = explode(':', $line, 2);
            $result[trim($k)] = trim($v);
        }
    }
    return $result;
}

// ── FORMAT CURRENCY ──────────────────────────────────
function fmtr($val) {
    $n = (float) preg_replace('/[^0-9.\-]/', '', $val ?? '0');
    return '$' . number_format(abs($n), 2);
}

// ── GENERATE REPORT ──────────────────────────────────
if (isset($_GET['generate'])) {
    $analysis_id = (int)$_GET['generate'];

    $stmt = $pdo->prepare("
        SELECT aa.*, uf.file_name, uf.file_type, uf.upload_time,
               c.full_name AS client_name, c.email AS client_email,
               c.phone AS client_phone, c.tax_year, c.sin_masked,
               u.full_name AS advisor_name
        FROM ai_analysis aa
        JOIN uploaded_files uf ON aa.file_id    = uf.file_id
        JOIN clients        c  ON aa.client_id  = c.client_id
        JOIN users          u  ON aa.advisor_id = u.user_id
        WHERE aa.analysis_id = ? AND aa.advisor_id = ?
    ");
    $stmt->execute([$analysis_id, $advisor['id']]);
    $analysis = $stmt->fetch();

    if ($analysis && !empty($analysis)) {

        $exists = $pdo->prepare("
            SELECT * FROM reports WHERE analysis_id = ?
        ");
        $exists->execute([$analysis_id]);
        $existing_report = $exists->fetch();

        if (!$existing_report) {
            $report_date  = date('F j, Y');
            $report_time  = date('h:i A');
            $report_id    = 'RPT-' . str_pad($analysis_id, 5, '0', STR_PAD_LEFT);
            $report_title = "Tax Analysis Report - "
                          . ($analysis['client_name'] ?? 'Client')
                          . " (" . ($analysis['tax_year'] ?? 'N/A') . ")";

            // Call AI to extract structured data
            $ai = generateAIReport($analysis);

            // Extract values
            $gross_income   = $ai['GROSS_INCOME']         ?? '0';
            $total_ded      = $ai['TOTAL_DEDUCTIONS']      ?? '0';
            $taxable_income = $ai['TAXABLE_INCOME']        ?? '0';
            $gross_tax      = $ai['GROSS_TAX']             ?? '0';
            $tax_credits    = $ai['TAX_CREDITS']           ?? '0';
            $net_tax        = $ai['NET_TAX']               ?? '0';
            $tax_withheld   = $ai['TAX_WITHHELD']          ?? '0';
            $refund_owed    = $ai['REFUND_OR_OWED']        ?? '0';
            $eff_rate       = $ai['EFFECTIVE_RATE']        ?? '0';
            $status         = $ai['STATUS']                ?? 'BALANCED';
            $risk           = $ai['COMPLIANCE_RISK']       ?? 'Low';
            $country        = $ai['COUNTRY']               ?? 'N/A';
            $missing        = $ai['MISSING_DOCS']          ?? 'None';
            $rec1           = $ai['TOP_RECOMMENDATION_1']  ?? '';
            $rec2           = $ai['TOP_RECOMMENDATION_2']  ?? '';
            $rec3           = $ai['TOP_RECOMMENDATION_3']  ?? '';
            $deadline       = $ai['FILING_DEADLINE']       ?? 'N/A';
            $bracket1       = $ai['BRACKET_1']             ?? 'N/A';
            $bracket2       = $ai['BRACKET_2']             ?? 'N/A';
            $bracket3       = $ai['BRACKET_3']             ?? 'N/A';

            // Colors
            $status_color = $status === 'REFUND' ? '#1e8449' : '#c0392b';
            $status_bg    = $status === 'REFUND' ? '#e6f9f0' : '#fdecea';
            $status_label = $status === 'REFUND' ? 'TAX REFUND' : 'TAX OWED';
            $risk_color   = $risk === 'Low'
                          ? '#1e8449' : ($risk === 'Medium' ? '#e67e22' : '#c0392b');
            $risk_bg      = $risk === 'Low'
                          ? '#e6f9f0' : ($risk === 'Medium' ? '#fff3e0' : '#fdecea');

            // Chart calculations
            $rate_pct    = round(min((float)$eff_rate, 100) * 3.6);
            $max_val     = max((float)$gross_income, (float)$taxable_income, (float)$gross_tax, 1);
            $bar_income  = round(((float)$gross_income   / $max_val) * 100);
            $bar_taxable = round(((float)$taxable_income / $max_val) * 100);
            $bar_tax     = round(((float)$gross_tax      / $max_val) * 100);
            $bar_ded     = min(round(((float)$total_ded  / max((float)$gross_income, 1)) * 100), 100);
            $bar_cred    = min(round(((float)$tax_credits/ max((float)$gross_income, 1)) * 100), 100);

            $missing_list = ($missing !== 'None' && !empty($missing))
                ? array_map('trim', explode(',', $missing))
                : [];

            $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>' . htmlspecialchars($report_title, ENT_QUOTES, 'UTF-8') . '</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family:"Segoe UI",Arial,sans-serif;
    background:#f0f2f7; color:#222; font-size:13px;
  }
  .header {
    background:linear-gradient(135deg,#0f3460 0%,#1a73e8 100%);
    color:white; padding:30px 50px;
    display:flex; justify-content:space-between; align-items:center;
  }
  .header h1 { font-size:24px; font-weight:700; margin-bottom:4px; }
  .header p  { font-size:12px; opacity:0.85; line-height:1.8; }
  .report-id-box {
    background:rgba(255,255,255,0.15);
    border:1px solid rgba(255,255,255,0.3);
    border-radius:10px; padding:14px 22px; text-align:center;
  }
  .report-id-box .rid  { font-size:20px; font-weight:700; }
  .report-id-box small { font-size:11px; opacity:0.75; }
  .confidential {
    background:#c0392b; color:white; text-align:center;
    padding:6px; font-size:11px; font-weight:700;
    letter-spacing:3px; text-transform:uppercase;
  }
  .body { padding:30px 50px; }
  .info-bar {
    display:grid; grid-template-columns:repeat(4,1fr);
    gap:16px; margin-bottom:24px; margin-top:24px;
  }
  .info-box {
    background:white; border-radius:12px; padding:16px 18px;
    border-top:3px solid #1a73e8;
    box-shadow:0 2px 8px rgba(0,0,0,0.06);
  }
  .info-box .ib-label {
    font-size:10px; color:#aaa; text-transform:uppercase;
    letter-spacing:1px; margin-bottom:6px; font-weight:700;
  }
  .info-box .ib-value { font-size:14px; font-weight:700; color:#1a1a2e; }
  .status-card {
    background:white; border-radius:16px; padding:28px 32px;
    margin-bottom:24px; display:flex;
    align-items:center; justify-content:space-between;
    box-shadow:0 2px 12px rgba(0,0,0,0.08);
  }
  .status-main   { display:flex; align-items:center; gap:24px; }
  .status-badge  {
    padding:10px 24px; border-radius:30px;
    font-size:13px; font-weight:700;
    text-transform:uppercase; letter-spacing:1px;
  }
  .status-amount { font-size:42px; font-weight:700; line-height:1; }
  .status-sub    { font-size:13px; color:#888; margin-top:4px; }
  .status-meta   { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .meta-pill {
    padding:8px 16px; border-radius:20px;
    font-size:12px; font-weight:600; text-align:center;
  }
  .three-col {
    display:grid; grid-template-columns:1fr 1fr 1fr;
    gap:20px; margin-bottom:20px;
  }
  .card {
    background:white; border-radius:14px; padding:22px;
    box-shadow:0 2px 8px rgba(0,0,0,0.06);
  }
  .card h3 {
    font-size:12px; font-weight:700; color:#0f3460;
    text-transform:uppercase; letter-spacing:0.5px;
    margin-bottom:16px; padding-bottom:10px;
    border-bottom:2px solid #f0f0f0;
  }
  .breakdown-row {
    display:flex; justify-content:space-between;
    padding:8px 0; border-bottom:1px solid #f5f5f5; font-size:13px;
  }
  .breakdown-row:last-child { border-bottom:none; }
  .breakdown-row .br-label  { color:#666; }
  .breakdown-row .br-value  { font-weight:700; color:#1a1a2e; }
  .breakdown-row.total {
    background:#f8f9fa; margin:8px -10px 0;
    padding:11px 10px; border-radius:8px; border-bottom:none;
  }
  .breakdown-row.total .br-label { color:#0f3460; font-weight:700; }
  .breakdown-row.total .br-value { font-size:16px; color:#0f3460; }
  .bar-item      { margin-bottom:13px; }
  .bar-label {
    display:flex; justify-content:space-between;
    font-size:12px; margin-bottom:5px;
  }
  .bar-label span:first-child { color:#555; font-weight:500; }
  .bar-label span:last-child  { font-weight:700; color:#1a1a2e; }
  .bar-track {
    height:10px; background:#f0f2f7; border-radius:5px; overflow:hidden;
  }
  .bar-fill { height:100%; border-radius:5px; }
  .donut-wrap { text-align:center; padding:10px 0; }
  .donut {
    width:130px; height:130px; border-radius:50%;
    display:inline-flex; align-items:center;
    justify-content:center; position:relative; margin-bottom:12px;
  }
  .donut::before {
    content:""; position:absolute;
    width:90px; height:90px; border-radius:50%; background:white; z-index:1;
  }
  .donut-text {
    position:relative; z-index:2;
    font-size:20px; font-weight:700; color:#0f3460;
  }
  .donut-label { font-size:12px; color:#888; margin-top:4px; }
  .deadline-box {
    background:linear-gradient(135deg,#fff3e0,#fff8f0);
    border:2px solid #f5a623; border-radius:10px;
    padding:16px 18px; text-align:center; margin-top:20px;
  }
  .deadline-box .dl-label {
    font-size:11px; color:#e67e22; text-transform:uppercase;
    letter-spacing:1px; font-weight:700; margin-bottom:6px;
  }
  .deadline-box .dl-date { font-size:18px; font-weight:700; color:#1a1a2e; }
  .bracket-item {
    display:flex; align-items:center; gap:12px;
    padding:10px 0; border-bottom:1px solid #f5f5f5;
  }
  .bracket-item:last-child { border-bottom:none; }
  .bracket-num {
    width:26px; height:26px; border-radius:8px;
    background:#e8f0fe; color:#1a73e8;
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:700; flex-shrink:0;
  }
  .bracket-info { flex:1; font-size:12px; color:#555; }
  .rec-item {
    display:flex; gap:12px; padding:10px 0;
    border-bottom:1px solid #f5f5f5; font-size:13px; align-items:flex-start;
  }
  .rec-item:last-child { border-bottom:none; }
  .rec-num {
    width:24px; height:24px; border-radius:50%;
    background:linear-gradient(135deg,#0f3460,#1a73e8);
    color:white; display:flex; align-items:center;
    justify-content:center; font-size:11px; font-weight:700;
    flex-shrink:0; margin-top:1px;
  }
  .rec-text { color:#333; line-height:1.6; }
  .missing-item {
    display:flex; align-items:center; gap:8px;
    padding:7px 0; border-bottom:1px solid #f5f5f5;
    font-size:13px; color:#c0392b;
  }
  .missing-item:last-child { border-bottom:none; }
  .sig-section {
    margin-top:20px; padding:20px 24px;
    border:2px dashed #d0e4ff; border-radius:12px; background:white;
  }
  .sig-section h4 {
    font-size:13px; font-weight:700; color:#0f3460; margin-bottom:16px;
  }
  .sig-grid { display:grid; grid-template-columns:1fr 1fr; gap:30px; }
  .sig-box  { text-align:center; }
  .sig-line { border-bottom:2px solid #333; height:40px; margin-bottom:6px; }
  .sig-box small { font-size:11px; color:#888; }
  .footer {
    background:#1a1a2e; color:white; padding:18px 50px;
    display:flex; justify-content:space-between;
    align-items:center; margin-top:30px;
  }
  .footer .logo { font-size:16px; font-weight:700; margin-bottom:3px; }
  .footer p { font-size:11px; opacity:0.7; line-height:1.7; }
  @media print {
    body { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
  }
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>TaxStart AI &mdash; Tax Analysis Report</h1>
    <p>
      Advisor: <strong>'
  . htmlspecialchars($analysis['advisor_name'] ?? '', ENT_QUOTES, 'UTF-8')
  . '</strong> &nbsp;|&nbsp; '
  . $report_date . ' at ' . $report_time
  . ' &nbsp;|&nbsp; TaxStart AI
    </p>
  </div>
  <div class="report-id-box">
    <div class="rid">' . $report_id . '</div>
    <small>OFFICIAL TAX REPORT</small>
  </div>
</div>

<div class="confidential">Confidential &mdash; For Authorized Use Only</div>

<div class="body">

  <div class="info-bar">
    <div class="info-box">
      <div class="ib-label">Client Name</div>
      <div class="ib-value">'
  . htmlspecialchars($analysis['client_name'] ?? '', ENT_QUOTES, 'UTF-8')
  . '</div>
    </div>
    <div class="info-box">
      <div class="ib-label">Tax Year</div>
      <div class="ib-value">' . ($analysis['tax_year'] ?? 'N/A') . '</div>
    </div>
    <div class="info-box">
      <div class="ib-label">Country / Jurisdiction</div>
      <div class="ib-value">'
  . htmlspecialchars($country, ENT_QUOTES, 'UTF-8')
  . '</div>
    </div>
    <div class="info-box">
      <div class="ib-label">AI Confidence</div>
      <div class="ib-value">' . ($analysis['confidence'] ?? '0') . '%</div>
    </div>
  </div>

  <div class="status-card">
    <div class="status-main">
      <div class="status-badge"
           style="background:' . $status_bg . ';color:' . $status_color . ';">
        ' . $status_label . '
      </div>
      <div>
        <div class="status-amount" style="color:' . $status_color . ';">
          ' . fmtr($refund_owed) . '
        </div>
        <div class="status-sub">
          Net Tax ' . ($status === 'REFUND' ? 'Refund' : 'Owed')
  . ' for ' . ($analysis['tax_year'] ?? 'N/A') . '
        </div>
      </div>
    </div>
    <div class="status-meta">
      <div class="meta-pill"
           style="background:' . $risk_bg . ';color:' . $risk_color . ';">
        Compliance Risk: ' . $risk . '
      </div>
      <div class="meta-pill" style="background:#e8f0fe;color:#1a73e8;">
        Effective Rate: ' . $eff_rate . '%
      </div>
      <div class="meta-pill" style="background:#f3e8ff;color:#7b2d8b;">
        ' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '
      </div>
    </div>
  </div>

  <div class="three-col">

    <div class="card">
      <h3>Tax Breakdown</h3>
      <div class="breakdown-row">
        <span class="br-label">Gross Income</span>
        <span class="br-value">' . fmtr($gross_income) . '</span>
      </div>
      <div class="breakdown-row">
        <span class="br-label">Total Deductions</span>
        <span class="br-value" style="color:#1e8449;">
          &minus; ' . fmtr($total_ded) . '</span>
      </div>
      <div class="breakdown-row">
        <span class="br-label">Taxable Income</span>
        <span class="br-value">' . fmtr($taxable_income) . '</span>
      </div>
      <div class="breakdown-row">
        <span class="br-label">Gross Tax</span>
        <span class="br-value" style="color:#c0392b;">'
  . fmtr($gross_tax) . '</span>
      </div>
      <div class="breakdown-row">
        <span class="br-label">Tax Credits</span>
        <span class="br-value" style="color:#1e8449;">
          &minus; ' . fmtr($tax_credits) . '</span>
      </div>
      <div class="breakdown-row">
        <span class="br-label">Net Tax</span>
        <span class="br-value">' . fmtr($net_tax) . '</span>
      </div>
      <div class="breakdown-row">
        <span class="br-label">Tax Withheld</span>
        <span class="br-value" style="color:#1e8449;">
          &minus; ' . fmtr($tax_withheld) . '</span>
      </div>
      <div class="breakdown-row total">
        <span class="br-label">' . $status_label . '</span>
        <span class="br-value" style="color:' . $status_color . ';">
          ' . fmtr($refund_owed) . '</span>
      </div>
    </div>

    <div class="card">
      <h3>Financial Overview</h3>
      <div class="bar-item">
        <div class="bar-label">
          <span>Gross Income</span>
          <span>' . fmtr($gross_income) . '</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill"
               style="width:' . $bar_income . '%;
                      background:linear-gradient(90deg,#1a73e8,#0f3460);">
          </div>
        </div>
      </div>
      <div class="bar-item">
        <div class="bar-label">
          <span>Taxable Income</span>
          <span>' . fmtr($taxable_income) . '</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill"
               style="width:' . $bar_taxable . '%;
                      background:linear-gradient(90deg,#f5a623,#e67e22);">
          </div>
        </div>
      </div>
      <div class="bar-item">
        <div class="bar-label">
          <span>Gross Tax</span>
          <span>' . fmtr($gross_tax) . '</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill"
               style="width:' . $bar_tax . '%;
                      background:linear-gradient(90deg,#e94560,#c0392b);">
          </div>
        </div>
      </div>
      <div class="bar-item">
        <div class="bar-label">
          <span>Total Deductions</span>
          <span>' . fmtr($total_ded) . '</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill"
               style="width:' . $bar_ded . '%;
                      background:linear-gradient(90deg,#2ecc71,#1e8449);">
          </div>
        </div>
      </div>
      <div class="bar-item">
        <div class="bar-label">
          <span>Tax Credits</span>
          <span>' . fmtr($tax_credits) . '</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill"
               style="width:' . $bar_cred . '%;
                      background:linear-gradient(90deg,#7b2d8b,#e94560);">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Effective Tax Rate</h3>
      <div class="donut-wrap">
        <div class="donut"
             style="background:conic-gradient(
               #1a73e8 0deg ' . $rate_pct . 'deg,
               #f0f2f7 ' . $rate_pct . 'deg 360deg);">
          <span class="donut-text">' . $eff_rate . '%</span>
        </div>
        <div class="donut-label">
          You pay ' . $eff_rate . '% of gross income in taxes
        </div>
      </div>
      <div class="deadline-box">
        <div class="dl-label">Filing Deadline</div>
        <div class="dl-date">'
  . htmlspecialchars($deadline, ENT_QUOTES, 'UTF-8') . '</div>
      </div>
    </div>

  </div>

  <div class="three-col">

    <div class="card">
      <h3>Tax Brackets Applied</h3>
      ' . ($bracket1 !== 'N/A' ? '
      <div class="bracket-item">
        <div class="bracket-num">1</div>
        <div class="bracket-info">'
  . htmlspecialchars($bracket1, ENT_QUOTES, 'UTF-8') . '</div>
      </div>' : '') . '
      ' . ($bracket2 !== 'N/A' ? '
      <div class="bracket-item">
        <div class="bracket-num">2</div>
        <div class="bracket-info">'
  . htmlspecialchars($bracket2, ENT_QUOTES, 'UTF-8') . '</div>
      </div>' : '') . '
      ' . ($bracket3 !== 'N/A' ? '
      <div class="bracket-item">
        <div class="bracket-num">3</div>
        <div class="bracket-info">'
  . htmlspecialchars($bracket3, ENT_QUOTES, 'UTF-8') . '</div>
      </div>' : '') . '
      <div style="margin-top:16px;padding:12px;background:#f8f9fa;
                  border-radius:8px;text-align:center;">
        <div style="font-size:11px;color:#aaa;margin-bottom:4px;">
          NET TAX PAYABLE
        </div>
        <div style="font-size:20px;font-weight:700;color:#0f3460;">
          ' . fmtr($net_tax) . '
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Top Recommendations</h3>
      ' . ($rec1 ? '
      <div class="rec-item">
        <div class="rec-num">1</div>
        <div class="rec-text">'
  . htmlspecialchars($rec1, ENT_QUOTES, 'UTF-8') . '</div>
      </div>' : '') . '
      ' . ($rec2 ? '
      <div class="rec-item">
        <div class="rec-num">2</div>
        <div class="rec-text">'
  . htmlspecialchars($rec2, ENT_QUOTES, 'UTF-8') . '</div>
      </div>' : '') . '
      ' . ($rec3 ? '
      <div class="rec-item">
        <div class="rec-num">3</div>
        <div class="rec-text">'
  . htmlspecialchars($rec3, ENT_QUOTES, 'UTF-8') . '</div>
      </div>' : '') . '
    </div>

    <div class="card">
      <h3>Missing Documents</h3>
      ' . (empty($missing_list)
        ? '<div style="text-align:center;padding:20px;color:#1e8449;">
             <div style="font-size:30px;margin-bottom:8px;">&#10003;</div>
             <div style="font-weight:600;">All documents provided</div>
           </div>'
        : implode('', array_map(function($doc) {
            return '<div class="missing-item">
              <span style="font-size:16px;">&#9888;</span>
              <span>' . htmlspecialchars(trim($doc), ENT_QUOTES, 'UTF-8') . '</span>
            </div>';
          }, $missing_list))
      ) . '
      <div style="margin-top:16px;padding:12px;background:#f3e8ff;
                  border-radius:8px;text-align:center;">
        <div style="font-size:11px;color:#7b2d8b;margin-bottom:4px;">
          COMPLIANCE RISK
        </div>
        <div style="font-size:18px;font-weight:700;color:' . $risk_color . ';">
          ' . $risk . '
        </div>
      </div>
    </div>

  </div>

  <div class="sig-section">
    <h4>Authorization &amp; Signatures</h4>
    <div class="sig-grid">
      <div class="sig-box">
        <div class="sig-line"></div>
        <small>Tax Advisor &nbsp;|&nbsp;
          ' . htmlspecialchars($analysis['advisor_name'] ?? '', ENT_QUOTES, 'UTF-8') . '
        </small>
      </div>
      <div class="sig-box">
        <div class="sig-line"></div>
        <small>Client &nbsp;|&nbsp;
          ' . htmlspecialchars($analysis['client_name'] ?? '', ENT_QUOTES, 'UTF-8') . '
        </small>
      </div>
    </div>
  </div>

</div>

<div class="footer">
  <div>
    <div class="logo">TaxStart AI</div>
    <p>ABC Tech Ltd. &nbsp;|&nbsp; Neuro-Symbolic Tax Analysis Platform<br>
    AI-generated report. Review with a certified tax professional.</p>
  </div>
  <div style="text-align:right;">
    <p>
      Report: <strong>' . $report_id . '</strong><br>
      ' . $report_date . ' at ' . $report_time . '<br>
      &copy; 2026 TaxStart AI
    </p>
  </div>
</div>

</body>
</html>';

            // Save report file
            $report_dir = __DIR__ . '/../assets/reports/';
            if (!is_dir($report_dir)) {
                mkdir($report_dir, 0755, true);
            }

            $report_filename = 'report_' . $analysis_id . '_' . time() . '.html';
            $report_path     = 'assets/reports/' . $report_filename;
            file_put_contents($report_dir . $report_filename, $html);

            $pdo->prepare("
                INSERT INTO reports
                    (analysis_id, client_id, advisor_id,
                     report_title, report_path)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $analysis_id,
                $analysis['client_id'],
                $advisor['id'],
                $report_title,
                $report_path
            ]);

            logActivity($pdo, $advisor['id'], 'GENERATE_REPORT',
                "Report generated for: " . ($analysis['client_name'] ?? 'Client'));
        }

        header("Location: reports.php?success=1");
        exit();

    } else {
        header("Location: reports.php?error=notfound");
        exit();
    }
}

// ── VIEW REPORT ──────────────────────────────────────
if (isset($_GET['view'])) {
    $report_id = (int)$_GET['view'];
    $stmt = $pdo->prepare("
        SELECT * FROM reports
        WHERE report_id = ? AND advisor_id = ?
    ");
    $stmt->execute([$report_id, $advisor['id']]);
    $report = $stmt->fetch();

    if ($report) {
        $full_path = __DIR__ . '/../' . $report['report_path'];
        if (file_exists($full_path)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($full_path);
            exit();
        }
    }
    header("Location: reports.php?error=notfound");
    exit();
}

// ── FETCH ALL REPORTS ────────────────────────────────
$reports = $pdo->prepare("
    SELECT r.*, c.full_name AS client_name, c.tax_year,
           aa.confidence, uf.file_name
    FROM reports r
    JOIN clients        c  ON r.client_id   = c.client_id
    JOIN ai_analysis    aa ON r.analysis_id = aa.analysis_id
    JOIN uploaded_files uf ON aa.file_id    = uf.file_id
    WHERE r.advisor_id = ?
    ORDER BY r.generated_at DESC
");
$reports->execute([$advisor['id']]);
$reports = $reports->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports – TaxStart AI</title>
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
        .topbar .date { font-size:13px; color:#888; }

        .page-body { padding:30px 32px; flex:1; }

        .alert {
            padding:13px 18px; border-radius:10px;
            font-size:14px; margin-bottom:22px;
        }

        .alert-success {
            background:#f0fff4;
            border-left:4px solid #2ecc71; color:#1e8449;
        }

        .alert-error {
            background:#fff0f0;
            border-left:4px solid #e94560; color:#c0392b;
        }

        .stats-row {
            display:grid; grid-template-columns:repeat(3,1fr);
            gap:20px; margin-bottom:28px;
        }

        .stat-card {
            background:#fff; border-radius:14px; padding:20px 22px;
            display:flex; align-items:center; gap:16px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .stat-icon {
            width:48px; height:48px; border-radius:12px;
            display:flex; align-items:center;
            justify-content:center; font-size:22px;
        }

        .icon-purple { background:#f3e8ff; }
        .icon-blue   { background:#e8f0fe; }
        .icon-green  { background:#e6f9f0; }

        .stat-info h3 {
            font-size:24px; font-weight:700;
            color:#1a1a2e; line-height:1; margin-bottom:4px;
        }

        .stat-info p { font-size:13px; color:#888; }

        .card {
            background:#fff; border-radius:14px; padding:26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:18px; display:flex;
            align-items:center; justify-content:space-between;
        }

        .report-count {
            font-size:13px; color:#888; font-weight:600;
            background:#f5f5f5; padding:4px 12px; border-radius:20px;
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

        tbody tr {
            border-bottom:1px solid #f7f7f7; transition:background 0.15s;
        }

        tbody tr:hover { background:#fafafa; }

        tbody td {
            padding:14px; color:#333; vertical-align:middle;
        }

        .report-id {
            font-family:monospace; font-size:12px;
            color:#aaa; font-weight:600;
        }

        .confidence-pill {
            display:inline-flex; align-items:center; gap:6px;
            background:#e8f0fe; color:#1a73e8;
            border-radius:20px; padding:4px 12px;
            font-size:12px; font-weight:600;
        }

        .btn-view {
            padding:7px 16px; border:none; border-radius:8px;
            font-size:12px; font-weight:600; cursor:pointer;
            text-decoration:none; display:inline-block;
            background:linear-gradient(135deg,#7b2d8b,#e94560);
            color:#fff; transition:opacity 0.2s;
        }

        .btn-view:hover { opacity:0.85; }

        .btn-print {
            padding:7px 14px; border:none; border-radius:8px;
            font-size:12px; font-weight:600; cursor:pointer;
            text-decoration:none; display:inline-block;
            background:#f0f0f0; color:#555;
            transition:background 0.2s; margin-left:8px;
        }

        .btn-print:hover { background:#e0e0e0; }

        .empty-state {
            text-align:center; padding:60px 0; color:#bbb;
        }

        .empty-state .empty-icon { font-size:50px; margin-bottom:14px; }
        .empty-state p { font-size:15px; line-height:1.8; }
        .empty-state a {
            color:#1a73e8; font-weight:600; text-decoration:none;
        }
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
        <a href="reports.php" class="nav-item active">
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
        <a href="http://localhost/taxstart/logout.php"
           class="btn-logout">Logout</a>
    </div>
</aside>

<div class="main">

    <div class="topbar">
        <h1>📄 Generated Reports</h1>
        <span class="date">📅 <?= date('l, F j, Y') ?></span>
    </div>

    <div class="page-body">

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                Report generated successfully!
                You can now view or print it below.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                Could not generate report.
                Please make sure the file has been analyzed first.
            </div>
        <?php endif; ?>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon icon-purple">📄</div>
                <div class="stat-info">
                    <h3><?= count($reports) ?></h3>
                    <p>Total Reports</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-blue">🧑‍💼</div>
                <div class="stat-info">
                    <h3><?= count(array_unique(
                        array_column($reports, 'client_id'))) ?></h3>
                    <p>Clients Covered</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green">🤖</div>
                <div class="stat-info">
                    <h3><?= !empty($reports)
                        ? round(array_sum(
                            array_column($reports, 'confidence'))
                            / count($reports), 1) . '%'
                        : '0%' ?></h3>
                    <p>Avg AI Confidence</p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-title">
                All Reports
                <span class="report-count">
                    <?= count($reports) ?>
                    report<?= count($reports) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($reports)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📄</div>
                    <p>
                        No reports generated yet.<br>
                        Upload a file, run AI analysis, then click
                        <strong>Generate Report</strong>.<br><br>
                        <a href="upload.php">Go to Upload</a>
                    </p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Report ID</th>
                        <th>Client</th>
                        <th>Tax Year</th>
                        <th>Source File</th>
                        <th>AI Confidence</th>
                        <th>Generated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td>
                            <span class="report-id">
                                RPT-<?= str_pad(
                                    $r['report_id'], 5, '0', STR_PAD_LEFT) ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= sanitize($r['client_name']) ?></strong>
                        </td>
                        <td><?= $r['tax_year'] ?: '—' ?></td>
                        <td>
                            <span style="font-size:13px;color:#666;">
                                <?= sanitize($r['file_name']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="confidence-pill">
                                <?= $r['confidence'] ?>%
                            </span>
                        </td>
                        <td>
                            <?= date('M d, Y',
                                strtotime($r['generated_at'])) ?>
                            <br>
                            <small style="color:#aaa;">
                                <?= date('h:i A',
                                    strtotime($r['generated_at'])) ?>
                            </small>
                        </td>
                        <td>
                            <a href="reports.php?view=<?= $r['report_id'] ?>"
                               target="_blank" class="btn-view">
                               View
                            </a>
                            <a href="reports.php?view=<?= $r['report_id'] ?>"
                               target="_blank" class="btn-print"
                               onclick="setTimeout(()=>{
                                   window.open(this.href).print();
                               },800); return false;">
                               Print
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