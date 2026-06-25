<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (Auth::isLoggedIn()) { header('Location: /index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (Auth::login($username, $password, $remember)) {
        $redirect = $_SESSION['redirect_after_login'] ?? '/index.php';
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Sign In — Forgefront</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #002f77;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 450px;
        }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header i { font-size: 4rem; color: #002f77; margin-bottom: 15px; }
        .login-header h1 { font-size: 2rem; font-weight: bold; color: #333; margin-bottom: 5px; }
        .login-header p { color: #666; margin: 0; }
        .form-control { padding: 12px 15px; font-size: 1.1rem; border-radius: 8px; border: 2px solid #ced4da; }
        .form-control:focus { border-color: #002f77; box-shadow: 0 0 0 0.25rem rgba(0,47,119,0.2); }
        .form-label { font-weight: 600; color: #495057; margin-bottom: 8px; }
        .btn-login {
            width: 100%; padding: 12px; font-size: 1.2rem; font-weight: 600;
            background-color: #002f77; border: none; border-radius: 8px; color: white;
            transition: all 0.3s ease; margin-top: 10px; min-height: 0;
        }
        .btn-login:hover { background-color: #003d99; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,47,119,0.4); }
        .btn-login:disabled { opacity: 0.65; cursor: not-allowed; }
        .input-group-text { background-color: #f8f9fa; border: 2px solid #ced4da; border-right: none; color: #6c757d; }
        .input-group .form-control { border-left: none; }
        .input-group:focus-within .input-group-text { border-color: #002f77; color: #002f77; }
        .form-check-input { width: 1.4em; height: 1.4em; cursor: pointer; }
        .form-check-input:checked { background-color: #002f77; border-color: #002f77; }
        .form-check-label { font-size: 1rem; color: #495057; padding-left: 0.4em; cursor: pointer; }
        @media (max-width: 768px) {
            .login-container { padding: 30px 20px; }
            .login-header i { font-size: 3rem; }
            .login-header h1 { font-size: 1.75rem; }
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-header">
        <i class="fas fa-laptop"></i>
        <h1>Forgefront</h1>
        <p>Please sign in to continue</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-user"></i></span>
                <input type="text" class="form-control" id="username" name="username"
                       required autofocus autocomplete="username" placeholder="Enter your username">
            </div>
        </div>
        <div class="mb-4">
            <label for="password" class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" class="form-control" id="password" name="password"
                       required autocomplete="current-password" placeholder="Enter your password">
            </div>
        </div>
        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1" checked>
            <label class="form-check-label" for="remember">Keep me signed in</label>
        </div>
        <button type="submit" class="btn btn-primary btn-login" id="loginBtn">
            <i class="fas fa-sign-in-alt me-2"></i>Sign In
        </button>
    </form>

    <div class="text-center mt-4">
        <small class="text-muted"><i class="fas fa-shield-alt me-1"></i>Secure connection</small>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.getElementById('loginForm').addEventListener('submit', function() {
        const btn = document.getElementById('loginBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Signing in…';
    });
    document.querySelectorAll('input[type="text"], input[type="password"]').forEach(i => {
        i.addEventListener('focus', function() { this.style.fontSize = '16px'; });
    });
</script>
</body>
</html>
