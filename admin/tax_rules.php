<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$admin   = currentUser();
$error   = '';
$success = '';

// ── EXTRACT TEXT FROM UPLOADED FILE ─────────────────
function extractRulesText($file_path, $file_type) {
    $text = '';
    switch (strtoupper($file_type)) {
        case 'PDF':
            $content = file_get_contents($file_path);
            preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches);
            foreach ($matches[1] as $block) {
                preg_match_all('/\(([^)]{1,200})\)\s*Tj/s',
                    $block, $strings);
                foreach ($strings[1] as $str) {
                    $clean = preg_replace('/[^\x20-\x7E]/', '', $str);
                    if (strlen(trim($clean)) > 2) {
                        $text .= $clean . ' ';
                    }
                }
            }
            // Try decompressing streams
            if (strlen(trim($text)) < 100) {
                preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s',
                    $content, $streams);
                foreach ($streams[1] as $stream) {
                    $decoded = @gzuncompress($stream);
                    if ($decoded === false) {
                        $decoded = @gzinflate($stream);
                    }
                    if ($decoded !== false) {
                        preg_match_all('/\(([^\)]{1,200})\)\s*Tj/s',
                            $decoded, $tj);
                        foreach ($tj[1] as $t) {
                            $clean = preg_replace('/[^\x20-\x7E]/', '', $t);
                            if (strlen(trim($clean)) > 2) {
                                $text .= $clean . ' ';
                            }
                        }
                    }
                }
            }
            break;

        case 'TXT':
            $text = file_get_contents($file_path);
            break;

        case 'CSV':
            $text = file_get_contents($file_path);
            break;

        case 'DOCX':
            $zip = new ZipArchive();
            if ($zip->open($file_path) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml) {
                    $xml  = str_replace('</w:p>', "\n", $xml);
                    $xml  = str_replace('</w:r>',  ' ', $xml);
                    $text = strip_tags($xml);
                }
            }
            break;
    }

    $text = preg_replace('/[^\x20-\x7E\n]/', ' ', $text);
    $text = preg_replace('/\s{3,}/', ' ', trim($text));
    return $text;
}

// ── HANDLE UPLOAD ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['upload_rules'])) {

    $description = sanitize($_POST['description'] ?? '');

    if (!isset($_FILES['rules_file'])
        || $_FILES['rules_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please select a valid file to upload.";
    } else {
        $file      = $_FILES['rules_file'];
        $orig_name = basename($file['name']);
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed   = ['pdf', 'txt', 'csv', 'docx'];

        if (!in_array($ext, $allowed)) {
            $error = "Only PDF, TXT, CSV and DOCX files are allowed.";
        } else {
            $rules_dir = __DIR__ . '/../assets/tax_rules/';
            if (!is_dir($rules_dir)) {
                mkdir($rules_dir, 0755, true);
            }

            $safe_name = 'tax_rules_' . time() . '.' . $ext;
            $dest      = $rules_dir . $safe_name;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Extract text from file
                $extracted = extractRulesText($dest, strtoupper($ext));

                // Deactivate all previous rules
                $pdo->prepare("UPDATE tax_rules SET is_active = 0")
                    ->execute();

                // Save new rules
                $pdo->prepare("
                    INSERT INTO tax_rules
                        (file_name, file_path, extracted_text,
                         uploaded_by, description, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ")->execute([
                    $orig_name,
                    'assets/tax_rules/' . $safe_name,
                    $extracted,
                    $admin['id'],
                    $description
                ]);

                logActivity($pdo, $admin['id'], 'UPLOAD_TAX_RULES',
                    "Uploaded tax rules: $orig_name");

                $success = "Tax rules uploaded and activated successfully!";
            } else {
                $error = "Upload failed. Please check folder permissions.";
            }
        }
    }
}

// ── HANDLE DELETE ────────────────────────────────────
if (isset($_GET['delete'])) {
    $rid  = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT * FROM tax_rules WHERE rule_id = ?");
    $stmt->execute([$rid]);
    $rule = $stmt->fetch();
    if ($rule) {
        $full_path = __DIR__ . '/../' . $rule['file_path'];
        if (file_exists($full_path)) {
            unlink($full_path);
        }
        $pdo->prepare("DELETE FROM tax_rules WHERE rule_id = ?")
            ->execute([$rid]);
        logActivity($pdo, $admin['id'], 'DELETE_TAX_RULES',
            "Deleted tax rules: {$rule['file_name']}");
        $success = "Tax rules file deleted successfully.";
    }
}

// ── HANDLE ACTIVATE ──────────────────────────────────
if (isset($_GET['activate'])) {
    $rid = (int)$_GET['activate'];
    $pdo->prepare("UPDATE tax_rules SET is_active = 0")->execute();
    $pdo->prepare("UPDATE tax_rules SET is_active = 1 WHERE rule_id = ?")
        ->execute([$rid]);
    logActivity($pdo, $admin['id'], 'ACTIVATE_TAX_RULES',
        "Activated tax rules ID: $rid");
    $success = "Tax rules activated successfully!";
    header("Location: tax_rules.php?success=activated");
    exit();
}

// ── FETCH ALL RULES ───────────────────────────────────
$all_rules = $pdo->query("
    SELECT tr.*, u.full_name AS uploaded_by_name
    FROM tax_rules tr
    JOIN users u ON tr.uploaded_by = u.user_id
    ORDER BY tr.uploaded_at DESC
")->fetchAll();

// ── ACTIVE RULE ───────────────────────────────────────
$active_rule = $pdo->query("
    SELECT * FROM tax_rules WHERE is_active = 1 LIMIT 1
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Rules – TaxStart AI Admin</title>
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

        /* ACTIVE RULE BANNER */
        .active-banner {
            border-radius:14px; padding:22px 26px;
            margin-bottom:26px; display:flex;
            align-items:center; justify-content:space-between;
            gap:16px;
        }

        .active-banner.has-rules {
            background:linear-gradient(135deg,#e6f9f0,#f0fff4);
            border:2px solid #2ecc71;
        }

        .active-banner.no-rules {
            background:linear-gradient(135deg,#fff3e0,#fff8f0);
            border:2px solid #f5a623;
        }

        .active-banner .ab-icon {
            font-size:36px; flex-shrink:0;
        }

        .active-banner .ab-info h3 {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:4px;
        }

        .active-banner .ab-info p {
            font-size:13px; color:#666; line-height:1.6;
        }

        .active-banner .ab-badge {
            padding:6px 16px; border-radius:20px;
            font-size:12px; font-weight:700; flex-shrink:0;
        }

        .badge-active   { background:#e6f9f0; color:#1e8449; }
        .badge-inactive { background:#fff3e0; color:#e67e22; }

        /* UPLOAD FORM */
        .upload-card {
            background:#fff; border-radius:14px; padding:28px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
            margin-bottom:26px; border-top:4px solid #0f3460;
        }

        .upload-card h3 {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:20px;
        }

        .form-row {
            display:grid; grid-template-columns:1fr 1fr;
            gap:18px; margin-bottom:18px;
        }

        .form-group { display:flex; flex-direction:column; }
        .form-group.full { grid-column:1/-1; }

        .form-group label {
            font-size:12px; font-weight:700; color:#555;
            margin-bottom:8px; text-transform:uppercase;
            letter-spacing:0.5px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            padding:11px 14px; border:2px solid #e8e8e8;
            border-radius:10px; font-size:14px; color:#333;
            outline:none; transition:border-color 0.3s;
            background:#fff; font-family:inherit;
        }

        .form-group input:focus,
        .form-group textarea:focus { border-color:#0f3460; }

        .form-group textarea { resize:vertical; min-height:80px; }

        /* DROP ZONE */
        .drop-zone {
            border:2px dashed #c5d0e0; border-radius:12px;
            padding:30px 20px; text-align:center;
            cursor:pointer; transition:all 0.3s;
            background:#fafcff; position:relative;
        }

        .drop-zone:hover { border-color:#1a73e8; background:#f0f6ff; }
        .drop-zone input[type="file"] {
            position:absolute; inset:0; opacity:0;
            cursor:pointer; width:100%; height:100%;
        }

        .drop-zone .dz-icon { font-size:36px; margin-bottom:10px; }
        .drop-zone h4 { font-size:14px; font-weight:700; color:#1a1a2e; }
        .drop-zone p  { font-size:12px; color:#888; margin-top:4px; }

        .file-types {
            display:flex; gap:8px; justify-content:center;
            flex-wrap:wrap; margin-top:12px;
        }

        .ft-badge {
            background:#e8f0fe; color:#1a73e8;
            border-radius:6px; padding:3px 10px;
            font-size:11px; font-weight:700;
        }

        .selected-file {
            margin-top:12px; padding:10px 14px;
            background:#f0fff4; border-radius:8px;
            font-size:13px; color:#1e8449;
            font-weight:600; display:none;
        }

        .btn-upload {
            padding:13px 32px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; border:none; border-radius:10px;
            font-size:14px; font-weight:700; cursor:pointer;
            transition:opacity 0.2s; margin-top:16px;
        }

        .btn-upload:hover { opacity:0.9; }

        .info-box {
            background:#fff8e1; border-left:4px solid #f5a623;
            border-radius:8px; padding:12px 16px;
            font-size:13px; color:#8a6500;
            margin-top:16px; line-height:1.7;
        }

        /* RULES TABLE */
        .rules-card {
            background:#fff; border-radius:14px; padding:26px;
            box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size:16px; font-weight:700; color:#1a1a2e;
            margin-bottom:18px; display:flex;
            align-items:center; justify-content:space-between;
        }

        .count-badge {
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
        tbody td { padding:13px 14px; color:#333; vertical-align:middle; }

        .status-pill {
            display:inline-block; padding:4px 12px;
            border-radius:20px; font-size:11px; font-weight:700;
        }

        .status-active   { background:#e6f9f0; color:#1e8449; }
        .status-inactive { background:#f5f5f5; color:#999; }

        .text-preview {
            font-size:12px; color:#aaa; font-style:italic;
            max-width:300px; overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap;
        }

        .btn-action {
            padding:6px 14px; border:none; border-radius:7px;
            font-size:12px; font-weight:600; cursor:pointer;
            text-decoration:none; display:inline-block;
            transition:opacity 0.2s; margin-right:6px;
        }

        .btn-action:hover { opacity:0.8; }

        .btn-activate { background:#e6f9f0; color:#1e8449; }
        .btn-view     { background:#e8f0fe; color:#1a73e8; }
        .btn-delete   { background:#fdecea; color:#c0392b; }

        /* TEXT PREVIEW MODAL */
        .preview-modal {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,0.6); z-index:1000;
            align-items:center; justify-content:center;
        }

        .preview-modal.active { display:flex; }

        .preview-box {
            background:#fff; border-radius:16px;
            width:700px; max-height:80vh;
            display:flex; flex-direction:column;
            box-shadow:0 20px 60px rgba(0,0,0,0.3);
        }

        .preview-header {
            padding:20px 26px;
            border-bottom:2px solid #f0f0f0;
            display:flex; align-items:center;
            justify-content:space-between;
        }

        .preview-header h3 {
            font-size:16px; font-weight:700; color:#1a1a2e;
        }

        .btn-close {
            background:#f0f0f0; border:none; border-radius:8px;
            padding:8px 16px; font-size:13px; font-weight:600;
            cursor:pointer; color:#555;
        }

        .preview-content {
            padding:20px 26px; overflow-y:auto; flex:1;
            font-size:13px; color:#444; line-height:1.8;
            white-space:pre-wrap; font-family:monospace;
        }

        .empty-state {
            text-align:center; padding:40px 0; color:#bbb;
        }

        .empty-state .ei { font-size:44px; margin-bottom:12px; }
        .empty-state p   { font-size:14px; }
    </style>
</head>
<body>

<!-- TEXT PREVIEW MODAL -->
<div class="preview-modal" id="previewModal">
    <div class="preview-box">
        <div class="preview-header">
            <h3 id="previewTitle">Tax Rules Content</h3>
            <button class="btn-close"
                    onclick="closePreview()">Close</button>
        </div>
        <div class="preview-content" id="previewContent"></div>
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
        <a href="pricing.php" class="nav-item">
        <span class="icon">💰</span> Pricing & Credits
        </a>
        <a href="tax_rules.php" class="nav-item active">
            <span class="icon">📜</span> Tax Rules
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="http://localhost/taxstart/logout.php"
           class="btn-logout">Logout</a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">

    <div class="topbar">
        <div>
            <h1>📜 Tax Rules Management</h1>
            <p>Upload tax rules documents used by AI for all calculations</p>
        </div>
    </div>

    <div class="page-body">

        <?php if (!empty($success) || isset($_GET['success'])): ?>
            <div class="alert alert-success">
                ✅ <?= !empty($success) ? sanitize($success) : 'Operation successful!' ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                ⚠️ <?= sanitize($error) ?>
            </div>
        <?php endif; ?>

        <!-- ACTIVE RULES STATUS -->
        <?php if ($active_rule): ?>
        <div class="active-banner has-rules">
            <div class="ab-icon">✅</div>
            <div class="ab-info">
                <h3>Active Tax Rules: <?= sanitize($active_rule['file_name']) ?></h3>
                <p>
                    <?= sanitize($active_rule['description'] ?: 'No description provided.') ?>
                    &nbsp;·&nbsp;
                    Uploaded: <?= date('M d, Y', strtotime($active_rule['uploaded_at'])) ?>
                    &nbsp;·&nbsp;
                    <?= number_format(strlen($active_rule['extracted_text'])) ?> chars extracted
                </p>
            </div>
            <span class="active-banner ab-badge badge-active">
                ACTIVE
            </span>
        </div>
        <?php else: ?>
        <div class="active-banner no-rules">
            <div class="ab-icon">⚠️</div>
            <div class="ab-info">
                <h3>No Active Tax Rules</h3>
                <p>
                    AI is using built-in knowledge only.
                    Upload a tax rules document to improve accuracy
                    for all calculations, reports and analysis.
                </p>
            </div>
            <span class="active-banner ab-badge badge-inactive">
                NOT SET
            </span>
        </div>
        <?php endif; ?>

        <!-- UPLOAD FORM -->
        <div class="upload-card">
            <h3>📤 Upload New Tax Rules Document</h3>
            <form method="POST" enctype="multipart/form-data">

                <div class="form-row">
                    <div class="form-group full">
                        <label>Description (Optional)</label>
                        <textarea name="description"
                            placeholder="e.g. Canadian CRA Tax Rates 2024-2025, USA IRS Tax Brackets 2024, International Tax Rates Worldwide..."></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label>Tax Rules Document</label>
                    <div class="drop-zone" id="dropZone">
                        <input type="file" name="rules_file"
                               id="rulesFile" required
                               accept=".pdf,.txt,.csv,.docx">
                        <div class="dz-icon">📜</div>
                        <h4>Drag & Drop tax rules document</h4>
                        <p>or click to browse</p>
                        <div class="file-types">
                            <span class="ft-badge">PDF</span>
                            <span class="ft-badge">TXT</span>
                            <span class="ft-badge">CSV</span>
                            <span class="ft-badge">DOCX</span>
                        </div>
                    </div>
                    <div class="selected-file" id="selectedFile">
                        📎 <span id="fileName"></span>
                    </div>
                </div>

                <div class="info-box">
                    💡 <strong>What to include in the tax rules document:</strong><br>
                    • Tax brackets and rates for different countries<br>
                    • Standard deductions and credits by country<br>
                    • Filing deadlines for each jurisdiction<br>
                    • Special rules for different income types<br>
                    • Any custom rules specific to your firm<br><br>
                    <strong>Tip:</strong> A TXT or CSV file works best for
                    reliable text extraction. Uploading a new file will
                    automatically replace the currently active rules.
                </div>

                <button type="submit" name="upload_rules"
                        class="btn-upload">
                    📤 Upload & Activate Rules
                </button>
            </form>
        </div>

        <!-- ALL RULES TABLE -->
        <div class="rules-card">
            <div class="section-title">
                All Tax Rules Documents
                <span class="count-badge">
                    <?= count($all_rules) ?> file<?= count($all_rules) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($all_rules)): ?>
                <div class="empty-state">
                    <div class="ei">📜</div>
                    <p>No tax rules uploaded yet.<br>
                    Upload your first document above!</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Description</th>
                        <th>Extracted Text</th>
                        <th>Status</th>
                        <th>Uploaded</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_rules as $rule): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($rule['file_name']) ?></strong>
                        </td>
                        <td>
                            <span style="font-size:13px;color:#666;">
                                <?= sanitize($rule['description'] ?: '—') ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-preview">
                                <?= sanitize(
                                    substr($rule['extracted_text'] ?? '', 0, 60)
                                ) ?>...
                            </span>
                        </td>
                        <td>
                            <span class="status-pill
                                <?= $rule['is_active']
                                    ? 'status-active' : 'status-inactive' ?>">
                                <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= date('M d, Y',
                                strtotime($rule['uploaded_at'])) ?>
                        </td>
                        <td>
                            <?php if (!$rule['is_active']): ?>
                            <a href="tax_rules.php?activate=<?= $rule['rule_id'] ?>"
                               class="btn-action btn-activate">
                               Activate
                            </a>
                            <?php endif; ?>
                            <button class="btn-action btn-view"
                                    onclick="previewRules(
                                        '<?= addslashes(sanitize($rule['file_name'])) ?>',
                                        '<?= addslashes(
                                            substr($rule['extracted_text'] ?? 'No text extracted.', 0, 3000)
                                        ) ?>'
                                    )">
                                View Text
                            </button>
                            <a href="tax_rules.php?delete=<?= $rule['rule_id'] ?>"
                               class="btn-action btn-delete"
                               onclick="return confirm('Delete this tax rules file?')">
                               Delete
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

<script>
// File input display
document.getElementById('rulesFile').addEventListener('change', function() {
    if (this.files.length > 0) {
        document.getElementById('fileName').textContent = this.files[0].name;
        document.getElementById('selectedFile').style.display = 'block';
    }
});

// Drag and drop
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => {
    e.preventDefault(); dz.style.borderColor = '#1a73e8';
});
dz.addEventListener('dragleave', () => {
    dz.style.borderColor = '#c5d0e0';
});
dz.addEventListener('drop', e => {
    e.preventDefault(); dz.style.borderColor = '#c5d0e0';
    if (e.dataTransfer.files.length > 0) {
        document.getElementById('rulesFile').files = e.dataTransfer.files;
        document.getElementById('fileName').textContent =
            e.dataTransfer.files[0].name;
        document.getElementById('selectedFile').style.display = 'block';
    }
});

// Preview modal
function previewRules(title, content) {
    document.getElementById('previewTitle').textContent = title;
    document.getElementById('previewContent').textContent = content;
    document.getElementById('previewModal').classList.add('active');
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('active');
}

document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});
</script>

</body>
</html>