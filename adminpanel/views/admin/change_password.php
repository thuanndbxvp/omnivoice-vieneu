<?php $pageTitle = 'Đổi mật khẩu'; $view = 'admin/change_password'; ?>

<?php if ($error !== ''): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card" style="max-width:480px">
<form method="POST" action="/admin/change-password">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">

  <div class="form-row">
    <label class="form-label">Mật khẩu hiện tại</label>
    <input type="password" name="current_password" required autocomplete="current-password">
  </div>
  <div class="form-row">
    <label class="form-label">Mật khẩu mới</label>
    <input type="password" name="new_password" required autocomplete="new-password">
    <div class="form-hint">Tối thiểu 8 ký tự, gồm chữ hoa, chữ thường và số.</div>
  </div>
  <div class="form-row">
    <label class="form-label">Xác nhận mật khẩu mới</label>
    <input type="password" name="confirm_password" required autocomplete="new-password">
  </div>

  <div class="flex flex-gap mt-16">
    <button type="submit" class="btn btn-primary">Cập nhật</button>
    <a href="/admin/dashboard" class="btn btn-muted">Hủy</a>
  </div>
</form>
</div>
