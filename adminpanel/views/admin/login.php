<?php
/**
 * Standalone login page — no main layout.
 */
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login — License Manager</title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#0f172a; }
    .login-box { background:#111827; padding:36px; border-radius:12px; width:100%; max-width:380px; box-shadow:0 20px 40px rgba(0,0,0,.5); }
    h1 { font-size:20px; margin-bottom:4px; color:#f1f5f9; }
    .sub { color:#94a3b8; font-size:13px; margin-bottom:24px; }
  </style>
</head>
<body>
<div class="login-box">
  <h1>License Manager</h1>
  <div class="sub">Admin Login — Layer 2</div>

  <?php if ($error !== ''): ?>
  <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="POST" action="/admin/login">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <div class="form-row">
      <label class="form-label">Username</label>
      <input type="text" name="username" required autofocus autocomplete="username">
    </div>
    <div class="form-row">
      <label class="form-label">Password</label>
      <input type="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary w-full" style="margin-top:8px">Đăng nhập</button>
  </form>
</div>
</body>
</html>
