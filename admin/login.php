<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/bootstrap.php';

if (is_logged_in()) {
    header('Location: ' . url('admin/index.php'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $user = (string) ($_POST['username'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        if (attempt_login($user, $pass)) {
            header('Location: ' . url('admin/index.php'));
            exit;
        }
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login — Smart Parking</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= e(url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="admin-body d-flex align-items-center" style="min-height:100vh">
  <div class="container" style="max-width:420px">
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <h1 class="h4 mb-1">Admin Login</h1>
        <p class="text-secondary small mb-3">
          After login you can open Dashboard, Scan Plate / Check-in, Exit / Payment, and Check-in Records.
        </p>

        <div class="alert alert-info py-2 mb-3">
          <div class="fw-semibold mb-1">Demo Account</div>
          <div class="small mb-2">
            Username: <code>admin</code><br>
            Password: <code>admin123</code>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" id="btnFillDemo">Use Demo Account</button>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off" id="loginForm">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
          </div>
          <button class="btn btn-primary w-100">Login</button>
        </form>
      </div>
    </div>
  </div>
  <script>
    document.getElementById('btnFillDemo').addEventListener('click', function () {
      document.getElementById('username').value = 'admin';
      document.getElementById('password').value = 'admin123';
      document.getElementById('password').focus();
    });
  </script>
</body>
</html>
