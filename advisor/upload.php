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

// ── Fetch this advisor's clients for dropdown ───────
$clients_list = $pdo->prepare("
    SELECT client_id, full_name, tax_year, tax_type,
           credit_used, credit_expires_at
    FROM clients WHERE advisor_id = ?
    ORDER BY full_name ASC
");
$clients_list->execute([$advisor['id']]);
$clients_list = $clients_list->fetchAll();

// ── Pre-select client if coming from clients page ───
$preselect_client = (int)($_GET['client_id'] ?? 0);

// ── HANDLE FILE UPLOAD ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    $client_id = (int)($_POST['client_id'] ?? 0);

    if ($client_id === 0) {
        $error = "Please select a client before uploading.";
    } elseif (!isset($_FILES['tax_file']) || $_FILES['tax_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please select a valid file to upload.";
    } else {
        $file      = $_FILES['tax_file'];
        $orig_name = basename($file['name']);
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed   = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xlsx', 'csv'];
        $max_bytes = MAX_FILE_MB * 1024 * 1024;

        if (!in_array($ext, $allowed)) {
            $error = "File type not allowed. Accepted: PDF, JPG, PNG, DOC, DOCX, XLSX, CSV.";
        } elseif ($file['size'] > $max_bytes) {
            $error = "File too large. Maximum size is " . MAX_FILE_MB . " MB.";
        } else {
            // Verify client belongs to this advisor
            $check = $pdo->prepare("
                SELECT client_id FROM clients
                WHERE client_id = ? AND advisor_id = ?
            ");
            $check->execute([$client_id, $advisor['id']]);

            if (!$check->fetch()) {
                $error = "Invalid client selected.";
            } else {
                // ── CREDIT CHECK ─────────────────────────
                $client_info = $pdo->prepare("
                    SELECT tax_type, credit_used, credit_expires_at
                    FROM clients WHERE client_id = ?
                ");
                $client_info->execute([$client_id]);
                $cl = $client_info->fetch();

                $credit_active = $cl && $cl['credit_used']
                    && $cl['credit_expires_at']
                    && strtotime($cl['credit_expires_at']) >= strtotime('today');

                $credit_expired = $cl && $cl['credit_used']
                    && $cl['credit_expires_at']
                    && strtotime($cl['credit_expires_at']) < strtotime('today');

                if (!$credit_active && $credit_expired) {
                    $tier_needed = ($cl['tax_type'] === 'international') ? 'Gold' : 'Silver';
                    $error = "This client's credit has expired. "
                           . "You need 1 $tier_needed credit to renew. "
                           . "<a href='subscription.php' style='color:#1a73e8;font-weight:600;'>Buy Credits →</a>";
                } elseif (!$credit_active) {
                    $error = "This client does not have an active credit. "
                           . "Please add the client again through My Clients with a valid credit.";
                } else {
                // Build unique filename & save
                $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig_name);
                $dest      = UPLOAD_PATH . $safe_name;

                if (!is_dir(UPLOAD_PATH)) {
                    mkdir(UPLOAD_PATH, 0755, true);
                }

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO uploaded_files
                            (client_id, advisor_id, file_name, file_path,
                             file_type, file_size, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $client_id,
                        $advisor['id'],
                        $orig_name,
                        'assets/uploads/' . $safe_name,
                        strtoupper($ext),
                        round($file['size'] / 1024)   // KB
                    ]);

                    $file_id = $pdo->lastInsertId();

                    logActivity($pdo, $advisor['id'], 'UPLOAD_FILE',
                        "Uploaded: $orig_name for client ID $client_id");

                    $success  = "File uploaded successfully! ";
                    $success .= "<a href='../ai/analyze.php?file_id=$file_id' "
                              . "style='color:#1e8449;font-weight:700;'>"
                              . "▶ Run AI Analysis Now →</a>";
                } else {
                    $error = "Upload failed. Please check folder permissions.";
                }
            } // end credit active
            }
        }
    }
}

// ── Fetch uploaded files for this advisor ───────────
$files = $pdo->prepare("
    SELECT uf.*, c.full_name AS client_name
    FROM uploaded_files uf
    JOIN clients c ON uf.client_id = c.client_id
    WHERE uf.advisor_id = ?
    ORDER BY uf.upload_time DESC
");
$files->execute([$advisor['id']]);
$files = $files->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Files – TaxStart AI</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }

        body {
            font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:#f0f2f7;
            display:flex; min-height:100vh;
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

        /* ALERTS */
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

        /* TWO COL */
        .two-col {
            display:grid;
            grid-template-columns:420px 1fr;
            gap:26px;
        }

        .card {
            background:#fff; border-radius:14px;
            padding:26px; box-shadow:0 2px 12px rgba(0,0,0,0.06);
        }

        .section-title {
            font-size:16px; font-weight:700;
            color:#1a1a2e; margin-bottom:18px;
        }

        /* UPLOAD FORM */
        .form-group { margin-bottom:18px; }

        .form-group label {
            display:block; font-size:12px; font-weight:700;
            color:#555; margin-bottom:8px;
            text-transform:uppercase; letter-spacing:0.5px;
        }

        .form-group select,
        .form-group input[type="text"] {
            width:100%; padding:12px 14px;
            border:2px solid #e8e8e8; border-radius:10px;
            font-size:14px; color:#333; outline:none;
            transition:border-color 0.3s; background:#fff;
        }

        .form-group select:focus,
        .form-group input:focus { border-color:#0f3460; }

        /* DROP ZONE */
        .drop-zone {
            border:2px dashed #c5d0e0;
            border-radius:12px; padding:36px 20px;
            text-align:center; cursor:pointer;
            transition:all 0.3s; background:#fafcff;
            position:relative;
        }

        .drop-zone:hover,
        .drop-zone.dragover {
            border-color:#1a73e8;
            background:#f0f6ff;
        }

        .drop-zone input[type="file"] {
            position:absolute; inset:0;
            opacity:0; cursor:pointer; width:100%; height:100%;
        }

        .drop-zone .dz-icon { font-size:44px; margin-bottom:12px; }

        .drop-zone h4 {
            font-size:15px; font-weight:700;
            color:#1a1a2e; margin-bottom:6px;
        }

        .drop-zone p {
            font-size:13px; color:#888; line-height:1.6;
        }

        .drop-zone .file-types {
            display:flex; gap:8px; justify-content:center;
            flex-wrap:wrap; margin-top:14px;
        }

        .file-type-badge {
            background:#e8f0fe; color:#1a73e8;
            border-radius:6px; padding:3px 10px;
            font-size:11px; font-weight:700;
        }

        .selected-file {
            margin-top:14px; padding:10px 14px;
            background:#f0fff4; border-radius:8px;
            font-size:13px; color:#1e8449;
            font-weight:600; display:none;
        }

        .btn-upload {
            width:100%; padding:14px;
            background:linear-gradient(135deg,#0f3460,#1a73e8);
            color:#fff; border:none; border-radius:10px;
            font-size:15px; font-weight:700;
            cursor:pointer; margin-top:18px;
            transition:opacity 0.2s; letter-spacing:0.3px;
        }

        .btn-upload:hover { opacity:0.9; }

        .info-box {
            background:#fff8e1; border-left:4px solid #f5a623;
            border-radius:8px; padding:12px 16px;
            font-size:13px; color:#8a6500;
            margin-top:16px; line-height:1.7;
        }

        /* FILES TABLE */
        table {
            width:100%; border-collapse:collapse; font-size:14px;
        }

        thead th {
            text-align:left; padding:10px 12px;
            font-size:12px; font-weight:700; color:#888;
            text-transform:uppercase; letter-spacing:0.5px;
            border-bottom:2px solid #f0f0f0;
        }

        tbody tr {
            border-bottom:1px solid #f7f7f7;
            transition:background 0.15s;
        }

        tbody tr:hover { background:#fafafa; }

        tbody td {
            padding:12px 12px; color:#333; vertical-align:middle;
        }

        .badge {
            display:inline-block; padding:4px 10px;
            border-radius:20px; font-size:11px; font-weight:600;
        }

        .badge-pending  { background:#fff3e0; color:#e67e22; }
        .badge-analyzed { background:#e6f9f0; color:#1e8449; }
        .badge-failed   { background:#fdecea; color:#c0392b; }

        .file-type-tag {
            background:#f0f2f7; color:#555;
            border-radius:6px; padding:3px 8px;
            font-size:11px; font-weight:700;
            font-family:monospace;
        }

        .btn-analyze {
            padding:6px 14px; border:none;
            border-radius:7px; font-size:12px;
            font-weight:600; cursor:pointer;
            text-decoration:none; display:inline-block;
            background:#e8f0fe; color:#1a73e8;
            transition:opacity 0.2s;
        }

        .btn-analyze:hover { opacity:0.8; }

        .btn-delete {
            padding:6px 12px; border:none;
            border-radius:7px; font-size:12px;
            font-weight:600; cursor:pointer;
            text-decoration:none; display:inline-block;
            background:#fdecea; color:#c0392b;
            transition:opacity 0.2s;
        }

        .btn-delete:hover { opacity:0.8; }

        .empty-state {
            text-align:center; padding:40px 0; color:#bbb;
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
        <a href="clients.php" class="nav-item">
            <span class="icon">🧑‍💼</span> My Clients
        </a>
        <a href="upload.php" class="nav-item active">
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
        <h1>📁 Upload Files for AI Analysis</h1>
        <span class="date">📅 <?= date('l, F j, Y') ?></span>
    </div>

    <div class="page-body">

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <div class="two-col">

            <!-- UPLOAD FORM -->
            <div class="card">
                <div class="section-title">📤 Upload Tax Document</div>

                <?php if (empty($clients_list)): ?>
                    <div class="info-box">
                        ⚠️ You have no clients yet.
                        <a href="clients.php?action=add"
                           style="color:#8a6500; font-weight:700;">
                           Add a client first →
                        </a>
                    </div>
                <?php else: ?>
                <form method="POST" action="upload.php"
                      enctype="multipart/form-data">

                    <div class="form-group">
                        <label>Select Client *</label>
                        <select name="client_id" required>
                            <option value="">-- Choose a client --</option>
                            <?php foreach ($clients_list as $cl): ?>
                                <option value="<?= $cl['client_id'] ?>"
                                    <?= ($preselect_client == $cl['client_id']
                                        || ($_POST['client_id'] ?? 0) == $cl['client_id'])
                                        ? 'selected' : '' ?>>
                                    <?= sanitize($cl['full_name']) ?>
                                    <?= $cl['tax_year'] ? '(' . $cl['tax_year'] . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tax Document *</label>
                        <div class="drop-zone" id="dropZone">
                            <input type="file" name="tax_file"
                                   id="fileInput" required
                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xlsx,.csv">
                            <div class="dz-icon">📄</div>
                            <h4>Drag & Drop your file here</h4>
                            <p>or click to browse from your computer</p>
                            <div class="file-types">
                                <span class="file-type-badge">PDF</span>
                                <span class="file-type-badge">JPG</span>
                                <span class="file-type-badge">PNG</span>
                                <span class="file-type-badge">DOC</span>
                                <span class="file-type-badge">DOCX</span>
                                <span class="file-type-badge">XLSX</span>
                                <span class="file-type-badge">CSV</span>
                            </div>
                        </div>
                        <div class="selected-file" id="selectedFile">
                            📎 <span id="fileName"></span>
                        </div>
                    </div>

                    <div class="info-box">
                        💡 <strong>AI Analysis:</strong> After uploading, click
                        <strong>"Run AI Analysis"</strong> to extract key tax
                        information and generate automated advice and a report.
                        Max file size: <?= MAX_FILE_MB ?>MB.
                    </div>

                    <button type="submit" name="upload_file"
                            class="btn-upload">
                        📤 Upload Document
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- UPLOADED FILES LIST -->
            <div class="card">
                <div class="section-title">
                    📋 Uploaded Files
                    <span style="font-size:13px; color:#888; font-weight:600;
                                 background:#f5f5f5; padding:4px 12px;
                                 border-radius:20px;">
                        <?= count($files) ?> file<?= count($files) !== 1 ? 's' : '' ?>
                    </span>
                </div>

                <?php if (empty($files)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📂</div>
                        <p>No files uploaded yet.<br>
                        Upload your first tax document!</p>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td>
                                <strong style="font-size:13px;">
                                    <?= sanitize($f['file_name']) ?>
                                </strong><br>
                                <small style="color:#aaa;">
                                    <?= date('M d, Y h:i A',
                                        strtotime($f['upload_time'])) ?>
                                </small>
                            </td>
                            <td><?= sanitize($f['client_name']) ?></td>
                            <td>
                                <span class="file-type-tag">
                                    <?= sanitize($f['file_type']) ?>
                                </span>
                            </td>
                            <td><?= $f['file_size'] ?> KB</td>
                            <td>
                                <span class="badge badge-<?= $f['status'] ?>">
                                    <?= ucfirst($f['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($f['status'] === 'pending'): ?>
                                <a href="../ai/analyze.php?file_id=<?= $f['file_id'] ?>"
                                   class="btn-analyze">
                                   🤖 Analyze
                                </a>
                                <?php else: ?>
                                <a href="reports.php?file_id=<?= $f['file_id'] ?>"
                                   class="btn-analyze">
                                   📄 Report
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
    // Drag & drop + file name display
    const dropZone  = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const selFile   = document.getElementById('selectedFile');
    const fileName  = document.getElementById('fileName');

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            fileName.textContent = fileInput.files[0].name;
            selFile.style.display = 'block';
        }
    });

    dropZone.addEventListener('dragover', e => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            fileName.textContent = e.dataTransfer.files[0].name;
            selFile.style.display = 'block';
        }
    });
</script>

</body>
</html>