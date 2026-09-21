<?php $pageTitle = 'Sửa hàng loạt'; $view = 'admin/bulk_edit'; ?>

<?php if ($error !== ''): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="flex justify-between items-center mb-16 flex-wrap flex-gap">
  <a href="/admin/dashboard" class="btn btn-muted btn-sm">← Dashboard</a>
  <span class="text-muted text-sm">Đang sửa <strong><?php echo count($licenses); ?></strong> license</span>
</div>

<!-- Selected keys summary -->
<div class="card mb-16">
  <div class="card-title">Danh sách key được chọn</div>
  <div style="display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($licenses as $l): ?>
    <span class="pill pill-info mono"><?php echo htmlspecialchars($l['license_key']); ?></span>
    <?php endforeach; ?>
  </div>
</div>

<!-- Bulk edit form -->
<div class="card" style="max-width:700px">
<form method="POST" action="/admin/bulk-edit">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
  <?php foreach ($selectedIds as $sid): ?>
  <input type="hidden" name="ids[]" value="<?php echo intval($sid); ?>">
  <?php endforeach; ?>

  <p class="text-muted text-sm mb-16" style="margin-top:0">
    Chỉ điền vào các trường bạn muốn thay đổi. Trường để trống hoặc không tick checkbox áp dụng sẽ <strong>giữ nguyên</strong> giá trị hiện tại của từng key.
  </p>

  <!-- Apps -->
  <div class="form-row">
    <label class="form-label">
      Apps được phép
      <span class="text-muted text-sm" style="font-weight:normal">(để không tick = giữ nguyên)</span>
    </label>
    <div class="app-grid">
      <?php foreach ($availableApps as $appId => $appName): ?>
      <label class="app-grid-item">
        <input type="checkbox" name="allowed_apps[]" value="<?php echo htmlspecialchars($appId); ?>">
        <span><?php echo htmlspecialchars($appName); ?></span>
      </label>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:6px">
      <label class="check-label">
        <input type="checkbox" name="apply_apps" value="1">
        <span>Áp dụng thay đổi Apps cho tất cả key đã chọn</span>
      </label>
    </div>
  </div>

  <!-- Max thiết bị -->
  <div class="form-row">
    <label class="form-label">
      Max thiết bị
      <span class="text-muted text-sm" style="font-weight:normal">(để trống = giữ nguyên)</span>
    </label>
    <input type="number" name="max_devices" min="1" max="999" placeholder="Ví dụ: 2">
    <div style="margin-top:6px">
      <label class="check-label">
        <input type="checkbox" name="apply_max_devices" value="1">
        <span>Áp dụng max thiết bị cho tất cả key đã chọn</span>
      </label>
    </div>
  </div>

  <!-- Gia hạn thêm -->
  <div class="form-row">
    <label class="form-label">
      Gia hạn thêm (ngày)
      <span class="text-muted text-sm" style="font-weight:normal">(để trống = giữ nguyên)</span>
    </label>
    <input type="number" name="extend_days" min="1" max="3650" placeholder="Ví dụ: 365">
    <div class="text-muted text-sm" style="margin-top:4px">Ngày sẽ được cộng thêm vào ngày hết hạn hiện tại của từng key.</div>
  </div>

  <!-- Hoặc đặt ngày hết hạn cụ thể -->
  <div class="form-row">
    <label class="form-label">
      Hoặc đặt ngày hết hạn cụ thể
      <span class="text-muted text-sm" style="font-weight:normal">(để trống = giữ nguyên)</span>
    </label>
    <input type="datetime-local" name="expiry_date">
    <div class="text-muted text-sm" style="margin-top:4px">Nếu điền cả 2, ưu tiên "Đặt ngày cụ thể".</div>
  </div>

  <!-- Trạng thái revoke -->
  <div class="form-row">
    <label class="form-label">
      Trạng thái license
      <span class="text-muted text-sm" style="font-weight:normal">(giữ nguyên nếu không tick áp dụng)</span>
    </label>
    <select name="revoked_value">
      <option value="0">Hoạt động (unrevoke)</option>
      <option value="1">Thu hồi (revoke)</option>
    </select>
    <div style="margin-top:6px">
      <label class="check-label">
        <input type="checkbox" name="apply_revoked" value="1">
        <span>Áp dụng trạng thái này cho tất cả key đã chọn</span>
      </label>
    </div>
  </div>

  <!-- Đại lý -->
  <?php if (!empty($agencyOptions)): ?>
  <div class="form-row">
    <label class="form-label">
      Đại lý
      <span class="text-muted text-sm" style="font-weight:normal">(giữ nguyên nếu không tick áp dụng)</span>
    </label>
    <select name="agency_id">
      <option value="0">— Không có —</option>
      <?php foreach ($agencyOptions as $ag): ?>
      <option value="<?php echo intval($ag['id']); ?>">
        <?php echo htmlspecialchars($ag['name'] . ' (' . $ag['code'] . ')'); ?>
      </option>
      <?php endforeach; ?>
    </select>
    <div style="margin-top:6px">
      <label class="check-label">
        <input type="checkbox" name="apply_agency" value="1">
        <span>Áp dụng đại lý này cho tất cả key đã chọn</span>
      </label>
    </div>
  </div>
  <?php endif; ?>

  <!-- Ghi chú -->
  <div class="form-row">
    <label class="form-label">
      Ghi chú admin
      <span class="text-muted text-sm" style="font-weight:normal">(để trống = giữ nguyên)</span>
    </label>
    <textarea name="admin_note" rows="3" placeholder="Để trống nếu không muốn thay đổi ghi chú"></textarea>
    <div style="margin-top:6px">
      <label class="check-label">
        <input type="checkbox" name="apply_note" value="1">
        <span>Áp dụng ghi chú này cho tất cả key đã chọn</span>
      </label>
    </div>
  </div>

  <div class="flex flex-gap mt-16">
    <button type="submit" class="btn btn-primary">Lưu thay đổi hàng loạt</button>
    <a href="/admin/dashboard" class="btn btn-muted">Hủy</a>
  </div>
</form>
</div>
