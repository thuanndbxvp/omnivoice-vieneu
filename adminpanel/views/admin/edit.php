<?php $pageTitle = 'Sửa License'; $view = 'admin/edit'; ?>

<?php if ($error !== ''): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card" style="max-width:700px">
<div class="flex justify-between items-center mb-16">
  <a href="/admin/view?id=<?php echo intval($license['id']); ?>" class="btn btn-muted btn-sm">← Quay lại</a>
</div>

<form method="POST" action="/admin/edit">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
  <input type="hidden" name="id" value="<?php echo intval($license['id']); ?>">

  <div class="form-row">
    <label class="form-label">License Key (không thể sửa)</label>
    <input type="text" value="<?php echo htmlspecialchars($license['license_key']); ?>" disabled style="font-family:monospace;opacity:.7">
  </div>

  <div class="form-row">
    <label class="form-label">Tên khách hàng <span style="color:var(--danger)">*</span></label>
    <input type="text" name="customer_name" value="<?php echo htmlspecialchars($license['customer_name']); ?>" required>
  </div>

  <div class="form-row">
    <label class="form-label">Email</label>
    <input type="email" name="email" value="<?php echo htmlspecialchars($license['email'] ?? ''); ?>">
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div class="form-row">
      <label class="form-label">Ngày hết hạn</label>
      <input type="datetime-local" name="expiry_date"
             value="<?php echo date('Y-m-d\TH:i', strtotime($license['expiry_date'])); ?>" required>
    </div>
    <div class="form-row">
      <label class="form-label">Max thiết bị</label>
      <input type="number" name="max_devices" min="1" max="999" value="<?php echo intval($license['max_devices']); ?>" required>
    </div>
  </div>

  <?php if (!empty($agencyOptions)): ?>
  <div class="form-row">
    <label class="form-label">Đại lý</label>
    <select name="agency_id">
      <option value="0">— Không có —</option>
      <?php foreach ($agencyOptions as $ag): ?>
      <option value="<?php echo intval($ag['id']); ?>"
        <?php echo intval($license['agency_id'] ?? 0)==intval($ag['id'])?'selected':''; ?>>
        <?php echo htmlspecialchars($ag['name'] . ' (' . $ag['code'] . ')'); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="form-row">
    <label class="form-label">Apps được phép</label>
    <div class="app-grid">
      <?php
      $currentApps = $license['allowed_apps_list'] ?? [];
      foreach ($availableApps as $appId => $appName): ?>
      <label class="app-grid-item">
        <input type="checkbox" name="allowed_apps[]" value="<?php echo htmlspecialchars($appId); ?>"
          <?php echo in_array($appId, $currentApps) ? 'checked' : ''; ?>>
        <span><?php echo htmlspecialchars($appName); ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="form-row">
    <label class="form-label">Ghi chú admin</label>
    <textarea name="admin_note" rows="3"><?php echo htmlspecialchars($license['admin_note'] ?? ''); ?></textarea>
  </div>

  <div class="form-row">
    <label class="check-label">
      <input type="checkbox" name="revoked" value="1" <?php echo $license['revoked']?'checked':''; ?>>
      Thu hồi license này
    </label>
  </div>

  <div class="flex flex-gap mt-16">
    <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
    <a href="/admin/dashboard" class="btn btn-muted">Hủy</a>
  </div>
</form>
</div>
