<?php
session_start();
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
// Already logged in? Redirect
if (isLoggedIn()) {
    if ($_SESSION['role'] === 'admin') {
        redirectTo('admin/dashboard.php');
    } else {
        redirectTo('advisor/dashboard.php');
    }
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = sanitize($_POST['full_name']        ?? '');
    $email            = sanitize($_POST['email']            ?? '');
    $phone            = sanitize($_POST['phone']            ?? '');
    $specialization   = sanitize($_POST['specialization']   ?? '');
    $license_number   = sanitize($_POST['license_number']   ?? '');
    $years_experience = (int)($_POST['years_experience']    ?? 0);
    $password         = $_POST['password']                  ?? '';
    $confirm_password = $_POST['confirm_password']          ?? '';

    // Validation
    if (empty($full_name) || empty($email) || empty($password)) {
        $error = "Full name, email, and password are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "This email is already registered. Please log in.";
        } else {
            // Insert into users table
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, email, password_hash, role, phone)
                VALUES (?, ?, ?, 'advisor', ?)
            ");
            $stmt->execute([$full_name, $email, $hash, $phone]);
            $new_user_id = $pdo->lastInsertId();

            // Insert into advisor_profiles table
            $stmt2 = $pdo->prepare("
                INSERT INTO advisor_profiles
                    (user_id, specialization, license_number, years_experience)
                VALUES (?, ?, ?, ?)
            ");
            $stmt2->execute([
                $new_user_id,
                $specialization,
                $license_number,
                $years_experience
            ]);

            // Log activity
            logActivity($pdo, $new_user_id, 'SIGNUP', 'New advisor account created');
            // Initialize welcome offer credits for new advisor
            require_once __DIR__ . '/includes/credits_helper.php';
            initWelcomeOffer($pdo, $new_user_id);
            $success = "Account created successfully! You can now log in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up – TaxStart AI</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .signup-wrapper {
            display: flex;
            width: 950px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
        }

        /* LEFT PANEL */
        .left-panel {
            width: 300px;
            background: linear-gradient(160deg, #0f3460, #e94560);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 50px 30px;
            color: white;
            text-align: center;
        }

        .left-panel .logo-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .left-panel h1 {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .left-panel p {
            font-size: 13px;
            opacity: 0.85;
            line-height: 1.7;
            margin-bottom: 30px;
        }

        .company-badge {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 30px;
            padding: 8px 18px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        .perks {
            margin-top: 30px;
            text-align: left;
            width: 100%;
        }

        .perks p {
            font-size: 13px;
            margin-bottom: 10px;
            opacity: 0.9;
        }

        /* RIGHT PANEL */
        .right-panel {
            flex: 1;
            background: #ffffff;
            padding: 45px 50px;
            overflow-y: auto;
        }

        .right-panel h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 5px;
        }

        .right-panel .subtitle {
            font-size: 14px;
            color: #888;
            margin-bottom: 25px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fff0f0;
            border-left: 4px solid #e94560;
            color: #c0392b;
        }

        .alert-success {
            background: #f0fff4;
            border-left: 4px solid #2ecc71;
            color: #1e8449;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 18px;
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .form-group.full {
            flex: 100%;
            margin-bottom: 18px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 7px;
        }

        .form-group input,
        .form-group select {
            padding: 12px 14px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            font-size: 14px;
            color: #333;
            outline: none;
            transition: border-color 0.3s;
            background: #fff;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #0f3460;
        }

        .section-divider {
            font-size: 12px;
            font-weight: 700;
            color: #0f3460;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 8px;
            margin: 20px 0 18px;
        }

        .btn-signup {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #0f3460, #e94560);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: 0.5px;
            transition: opacity 0.3s;
            margin-top: 5px;
        }

        .btn-signup:hover {
            opacity: 0.9;
        }

        .login-link {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
            color: #888;
        }

        .login-link a {
            color: #0f3460;
            font-weight: 600;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="signup-wrapper">

    <!-- LEFT PANEL -->
    <div class="left-panel">
        <div class="logo-icon">🧾</div>
        <h1>TaxStart AI</h1>
        <p>Join ABC Tech Ltd. as a certified tax advisor and power your workflow with AI.</p>
        <div class="company-badge">ABC TECH LTD.</div>
        <div class="perks">
            <p>✅ AI-powered file analysis</p>
            <p>✅ Auto-generated reports</p>
            <p>✅ Manage all your clients</p>
            <p>✅ Secure & encrypted data</p>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">
        <h2>Create Advisor Account 🖊️</h2>
        <p class="subtitle">Fill in your details to get started with TaxStart AI</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                ✅ <?= $success ?>
                <a href="index.php" style="font-weight:600; color:#1e8449;">Click here to login →</a>
            </div>
        <?php endif; ?>

        <?php if (empty($success)): ?>
        <form method="POST" action="">

            <div class="section-divider">👤 Personal Information</div>

            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name"
                           placeholder="John Doe"
                           value="<?= sanitize($_POST['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone"
                           placeholder="+1 604-000-0000"
                           value="<?= sanitize($_POST['phone'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group full">
                <label>Email Address *</label>
                <input type="email" name="email"
                       placeholder="you@abctech.com"
                       value="<?= sanitize($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="section-divider">💼 Professional Details</div>

            <div class="form-row">
                <div class="form-group">
                    <label>Specialization</label>
                    <select name="specialization">
                        <option value="">-- Select --</option>
                        <option value="Personal Tax"       <?= ($_POST['specialization'] ?? '') === 'Personal Tax'       ? 'selected' : '' ?>>Personal Tax</option>
                        <option value="Corporate Tax"      <?= ($_POST['specialization'] ?? '') === 'Corporate Tax'      ? 'selected' : '' ?>>Corporate Tax</option>
                        <option value="GST/HST"            <?= ($_POST['specialization'] ?? '') === 'GST/HST'            ? 'selected' : '' ?>>GST / HST</option>
                        <option value="Tax Planning"       <?= ($_POST['specialization'] ?? '') === 'Tax Planning'       ? 'selected' : '' ?>>Tax Planning</option>
                        <option value="Estate Tax"         <?= ($_POST['specialization'] ?? '') === 'Estate Tax'         ? 'selected' : '' ?>>Estate Tax</option>
                        <option value="International Tax"  <?= ($_POST['specialization'] ?? '') === 'International Tax'  ? 'selected' : '' ?>>International Tax</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Years of Experience</label>
                    <input type="number" name="years_experience" min="0" max="50"
                           placeholder="e.g. 5"
                           value="<?= (int)($_POST['years_experience'] ?? 0) ?>">
                </div>
            </div>

            <div class="form-group full">
                <label>License / Registration Number</label>
                <input type="text" name="license_number"
                       placeholder="e.g. CPA-2024-00123"
                       value="<?= sanitize($_POST['license_number'] ?? '') ?>">
            </div>

            <div class="section-divider">🔒 Account Security</div>

            <div class="form-row">
                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password"
                           placeholder="Min. 8 characters" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password"
                           placeholder="Repeat password" required>
                </div>
            </div>

            <button type="submit" class="btn-signup">🚀 Create My Account</button>
        </form>
        <?php endif; ?>

        <div class="login-link">
            Already have an account? <a href="index.php">Sign in here</a>
        </div>
    </div>

</div>

</body>
</html>
