<?php
require_once __DIR__ . '/_auth.php';

adminStartSession();
$next  = adminSafeNext($_GET['next'] ?? $_POST['next'] ?? '');
$error = '';

if (adminIsLoggedIn()) {
    header('Location: ' . $next);
    exit;
}

function tooManyAttemptsMessage(int $lockedSeconds): string
{
    return 'Too many attempts. Try again in ' . ceil($lockedSeconds / 60) . ' minute(s).';
}

function attemptLogin(): string
{
    if (($lockedSeconds = adminLockedSeconds()) > 0) {
        return tooManyAttemptsMessage($lockedSeconds);
    }
    if (!hash_equals(adminCsrfToken(), (string)($_POST['csrf'] ?? ''))) {
        unset($_SESSION['login_csrf']);
        return 'Session expired. Please try again.';
    }
    if (!adminIsConfigured()) {
        return 'Admin password is not set up yet.';
    }
    if (adminVerifyPassword((string)($_POST['password'] ?? ''))) {
        return '';
    }
    adminRegisterFailure();
    usleep(500000); // perlambat tebak-tebakan
    $lockedSeconds = adminLockedSeconds();
    return $lockedSeconds > 0 ? tooManyAttemptsMessage($lockedSeconds) : 'Incorrect password.';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $error = attemptLogin();
    if ($error === '') {
        adminClearFailures();
        adminLoginOk();
        header('Location: ' . $next);
        exit;
    }
}

$isLocalhost = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '') === 1;
$warnNotHttps = !adminIsHttps() && !$isLocalhost;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin Login — albrycia</title>
  <style>
    :root { --ink:#111; --line:#e4e1d9; --muted:#7a776f; --spring:cubic-bezier(.32,.72,0,1); }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px;
      background: #faf9f5; color: var(--ink); -webkit-font-smoothing: antialiased;
      font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif;
    }
    .box {
      width: 100%; max-width: 380px; padding: 36px 30px 30px; background: #fff;
      border: 1px solid var(--line); border-radius: 20px;
      box-shadow: 0 20px 60px rgba(0,0,0,.06); animation: rise .6s var(--spring) both;
    }
    .logo { display: block; height: 44px; margin: 0 auto 20px; }
    h1 { margin: 0 0 6px; text-align: center; font-size: 22px; font-weight: 600; letter-spacing: -.02em; }
    .sub { margin: 0 0 24px; text-align: center; font-size: 13.5px; color: var(--muted); }
    label { display: block; margin-bottom: 8px; font-size: 12.5px; font-weight: 500; }
    input[type=password] {
      width: 100%; height: 48px; padding: 0 14px; font-size: 15px; font-family: inherit;
      border: 1px solid var(--line); border-radius: 12px; outline: none; background: #fff;
      transition: border-color .15s, box-shadow .15s;
    }
    input[type=password]:focus { border-color: var(--ink); box-shadow: 0 0 0 1px var(--ink); }
    button {
      width: 100%; height: 50px; margin-top: 16px; border: 0; border-radius: 12px;
      background: var(--ink); color: #fff; font: 600 14px inherit; font-family: inherit; cursor: pointer;
      transition: transform .35s var(--spring), background .2s;
    }
    button:active { transform: scale(.97); transition-duration: .1s; }
    .err { margin-bottom: 16px; padding: 12px 14px; border-radius: 12px; font-size: 13px; background: #fbe4e1; color: #b3261e; }
    .warn { margin-top: 16px; padding: 10px 12px; border-radius: 12px; font-size: 12px; line-height: 1.5; background: #fff1d6; color: #8a5a00; }
    @keyframes rise { from { opacity: 0; transform: translateY(14px) scale(.98); } }
    @media (prefers-reduced-motion: reduce) { .box { animation: none; } button { transition: none; } }
  </style>
</head>
<body>
  <form class="box" method="POST" action="login.php" autocomplete="off">
    <img class="logo" src="../assets/images/brand/logo.png" alt="albrycia">
    <h1>Admin</h1>
    <p class="sub">Sign in to manage albrycia.</p>
    <?php if ($error !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(adminCsrfToken()) ?>">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autofocus required>
    <button type="submit">Sign in</button>
    <?php if ($warnNotHttps): ?><div class="warn">This connection is not HTTPS. Open the admin over https:// so your password is encrypted.</div><?php endif; ?>
  </form>
</body>
</html>
