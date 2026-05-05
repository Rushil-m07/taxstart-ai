<?php
session_start();
require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
// If already logged in, redirect to correct dashboard
if (isLoggedIn()) {
    if ($_SESSION['role'] === 'admin') {
        redirectTo('admin/dashboard.php');
    } else {
        redirectTo('advisor/dashboard.php');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // Update last login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")
                ->execute([$user['user_id']]);

            // Log activity
            logActivity($pdo, $user['user_id'], 'LOGIN', 'User logged in successfully');

            if ($user['role'] === 'admin') {
                redirectTo('admin/dashboard.php');
            } else {
                redirectTo('advisor/dashboard.php');
            }
        } else {
            $error = "Invalid email or password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – TaxStart AI</title>
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
        }

        .login-wrapper {
            display: flex;
            width: 900px;
            min-height: 520px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
        }

        /* LEFT PANEL */
        .left-panel {
            flex: 1;
            background: linear-gradient(160deg, #0f3460, #e94560);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 50px 40px;
            color: white;
            text-align: center;
        }

        .left-panel .logo-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .left-panel h1 {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .left-panel p {
            font-size: 14px;
            opacity: 0.85;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .company-badge {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 30px;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* RIGHT PANEL */
        .right-panel {
            flex: 1;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px 45px;
        }

        .right-panel h2 {
            font-size: 26px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 6px;
        }

        .right-panel .subtitle {
            font-size: 14px;
            color: #888;
            margin-bottom: 30px;
        }

        .error-box {
            background: #fff0f0;
            border-left: 4px solid #e94560;
            color: #c0392b;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            font-size: 14px;
            color: #333;
            transition: border-color 0.3s;
            outline: none;
        }

        .form-group input:focus {
            border-color: #0f3460;
        }

        .btn-login {
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

        .btn-login:hover {
            opacity: 0.9;
        }

        .signup-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #888;
        }

        .signup-link a {
            color: #0f3460;
            font-weight: 600;
            text-decoration: none;
        }

        .signup-link a:hover {
            text-decoration: underline;
        }

        .footer-note {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #bbb;
        }
    </style>
</head>
<body>

<div class="login-wrapper">

    <!-- LEFT PANEL -->
    <div class="left-panel">
        <div class="logo-icon">🧾</div>
        <h1>TaxStart AI</h1>
        <p>Smart tax advisory powered by AI.<br>Upload. Analyze. Generate Reports.</p>
        <div class="company-badge">ABC TECH LTD.</div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">
        <h2>Welcome Back 👋</h2>
        <p class="subtitle">Sign in to your TaxStart AI account</p>

        <?php if (!empty($error)): ?>
            <div class="error-box">⚠️ <?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@abctech.com"
                       value="<?= sanitize($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn-login">🔐 Sign In</button>
        </form>

        <div class="signup-link">
            New advisor? <a href="signup.php">Create an account</a>
        </div>

        <div class="footer-note">
            <?= SITE_NAME ?> &copy; 2026
        </div>
    </div>

</div>

</body>
</html>
