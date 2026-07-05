<?php

$config = require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Csrf;

$csrf = new Csrf($config['app']['csrf_key']);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base === '/' || $base === '\\') {
    $base = '';
}
$token = $_GET['token'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf->token(), ENT_QUOTES); ?>">
  <meta name="api-base" content="<?php echo htmlspecialchars($base . '/api/index.php', ENT_QUOTES); ?>">
  <title>Reset password</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($base); ?>/../assets/css/app.css">
</head>
<body>
<main class="login-shell">
  <section class="login-panel">
    <img src="<?php echo htmlspecialchars($base); ?>/../POS_v1/_review_tivoli/tivoli/img/Tivoli.png" alt="Tivoli Guesthouse" class="brand-mark">
    <form id="resetTokenForm" class="vstack gap-3">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$token, ENT_QUOTES); ?>">
      <div><label class="form-label">New Password</label><input class="form-control" type="password" name="password" minlength="8" required></div>
      <div><label class="form-label">Confirm Password</label><input class="form-control" type="password" name="password_confirm" minlength="8" required></div>
      <div class="alert d-none" id="resetTokenStatus"></div>
      <button class="btn btn-primary w-100">Reset password</button>
      <a class="btn btn-link w-100 text-decoration-none" href="<?php echo htmlspecialchars($base); ?>/index.php">Back to sign in</a>
    </form>
  </section>
</main>
<script src="<?php echo htmlspecialchars($base); ?>/../assets/js/api.js"></script>
<script src="<?php echo htmlspecialchars($base); ?>/../assets/js/reset-password.js"></script>
</body>
</html>
