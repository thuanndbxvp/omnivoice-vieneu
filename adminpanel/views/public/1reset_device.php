<?php
/**
 * Customer reset device page — standalone (no admin layout).
 */
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Device</title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#0f172a; }
    .box { background:#111827; padding:36px; border-radius:12px; width:100%; max-width:420px; box-shadow:0 20px 40px rgba(0,0,0,.5); }
    h1 { font-size:20px; margin-bottom:8px; color:#f1f5f9; }
    .sub { color:#94a3b8; font-size:13px; margin-bottom:20px; }
  </style>
</head>
<body>
<div class="box">
  <h1>Reset Device</h1>
  <div class="sub">Nhập key và email để reset thiết bị đã active về 0 (mỗi 24h/1 lần).</div>

  <?php if ($msg === 'success'): ?>
    <div class="alert alert-success">Reset thành công. Bạn có thể active lại thiết bị.</div>
  <?php elseif ($msg === 'cooldown'): ?>
    <div class="alert alert-error">Bạn chỉ có thể reset 1 lần trong 24 giờ.</div>
  <?php elseif ($msg === 'invalid'): ?>
    <div class="alert alert-error">Thông tin key hoặc email không hợp lệ.</div>
  <?php elseif ($msg === 'csrf_error'): ?>
    <div class="alert alert-error">Phiên làm việc không hợp lệ. Vui lòng thử lại.</div>
  <?php elseif ($msg === 'rate_limited'): ?>
    <div class="alert alert-error">Bạn thao tác quá nhanh. Vui lòng thử lại sau.</div>
  <?php elseif ($msg === 'db_error'): ?>
    <div class="alert alert-error">Không thể reset lúc này. Vui lòng thử lại sau.</div>
  <?php endif; ?>

  <form method="POST" action="/reset-device">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="form-row">
      <label class="form-label">License key</label>
      <input
        type="text"
        name="license_key"
        value="<?php echo htmlspecialchars((string) ($form['license_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        placeholder="XXXX-XXXX-XXXX-XXXX"
        required
        maxlength="19"
      >
    </div>

    <div class="form-row">
      <label class="form-label">Email</label>
      <input
        type="email"
        name="email"
        value="<?php echo htmlspecialchars((string) ($form['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
        placeholder="you@example.com"
        required
      >
    </div>

    <button type="submit" class="btn btn-primary w-full" style="margin-top:8px">Reset device</button>
  </form>
</div>
</body>
</html>
