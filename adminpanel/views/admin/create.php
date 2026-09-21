<?php $pageTitle = 'Tạo License'; $view = 'admin/create'; ?>

<?php if ($error !== ''): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card" style="max-width:700px">
<form method="POST" action="/admin/create">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">

  <div class="form-row">
    <label class="form-label">License Key</label>
    <div class="flex flex-gap">
      <input type="text" name="license_key" id="license_key_input"
             value="<?php echo htmlspecialchars($form['license_key']); ?>"
             pattern="[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}" required
             style="font-family:monospace;letter-spacing:.1em">
      <button type="button" class="btn btn-muted btn-sm" onclick="generateLicenseKey('license_key_input')">Generate</button>
    </div>
  </div>

  <div class="form-row">
    <label class="form-label">Tên khách hàng <span style="color:var(--danger)">*</span></label>
    <input type="text" name="customer_name" value="<?php echo htmlspecialchars($form['customer_name']); ?>" required>
  </div>

  <div class="form-row">
    <label class="form-label">Email</label>
    <input type="email" name="email" value="<?php echo htmlspecialchars($form['email']); ?>">
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div class="form-row">
      <label class="form-label">Số ngày hiệu lực</label>
      <input type="number" name="validity" min="1" max="3650" value="<?php echo intval($form['validity']); ?>" required>
    </div>
    <div class="form-row">
      <label class="form-label">Max thiết bị</label>
      <input type="number" name="max_devices" min="1" max="999" value="<?php echo intval($form['max_devices']); ?>" required>
    </div>
  </div>

  <?php if (!empty($agencyOptions)): ?>
  <div class="form-row">
    <label class="form-label">Đại lý</label>
    <select name="agency_id">
      <option value="0">— Không có —</option>
      <?php foreach ($agencyOptions as $ag): ?>
      <option value="<?php echo intval($ag['id']); ?>" <?php echo intval($form['agency_id'])==intval($ag['id'])?'selected':''; ?>>
        <?php echo htmlspecialchars($ag['name'] . ' (' . $ag['code'] . ')'); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="form-row">
    <label class="form-label">Apps được phép</label>
    <div class="app-grid">
      <?php foreach ($availableApps as $appId => $appName): ?>
      <label class="app-grid-item">
        <input type="checkbox" name="allowed_apps[]" value="<?php echo htmlspecialchars($appId); ?>"
          <?php echo in_array($appId, $form['allowed_apps']) ? 'checked' : ''; ?>>
        <span><?php echo htmlspecialchars($appName); ?></span>
      </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="form-row">
    <label class="form-label">Ghi chú admin</label>
    <textarea name="admin_note" rows="3"><?php echo htmlspecialchars($form['admin_note']); ?></textarea>
  </div>

  <div class="form-row">
    <label class="check-label">
      <input type="checkbox" name="start_count_now" value="1" <?php echo $form['start_count_now']?'checked':''; ?>>
      Bắt đầu tính từ bây giờ (bỏ chọn = bắt đầu khi kích hoạt lần đầu)
    </label>
  </div>

  <div class="flex flex-gap mt-16">
    <button type="submit" class="btn btn-primary">Tạo License</button>
    <a href="/admin/dashboard" class="btn btn-muted">Hủy</a>
  </div>
</form>
</div>
