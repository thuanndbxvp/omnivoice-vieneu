<?php $pageTitle = 'Quản lý Apps'; $view = 'admin/apps'; ?>
<?php
$msgMap = [
  'created'         => ['success', 'App đã được tạo.'],
  'updated'         => ['success', 'Đã lưu thay đổi.'],
  'toggled'         => ['success', 'Trạng thái đã cập nhật.'],
  'alias_created'   => ['success', 'Alias đã được tạo.'],
  'alias_deleted'   => ['success', 'Alias đã xóa.'],
  'duplicate_app_id'=> ['error',   'App ID đã tồn tại.'],
  'db_error'        => ['error',   'Lỗi database.'],
  'error'           => ['error',   $errText ?: 'Lỗi không xác định.'],
];
[$alertType, $alertText] = $msg !== '' ? ($msgMap[$msg] ?? ['info', htmlspecialchars($msg)]) : ['', ''];
?>

<?php if ($alertType): ?>
<div class="alert alert-<?php echo $alertType; ?>"><?php echo $alertText; ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs">
  <a href="/admin/apps?tab=apps"    class="tab-link <?php echo $tab==='apps'?'active':''; ?>">Apps</a>
  <a href="/admin/apps?tab=aliases" class="tab-link <?php echo $tab==='aliases'?'active':''; ?>">Aliases</a>
</div>

<?php if ($tab === 'apps'): ?>
<!-- Form tạo / sửa App -->
<div class="card" style="max-width:600px">
  <div class="card-title"><?php echo $editApp ? 'Sửa App' : 'Tạo App mới'; ?></div>
  <form method="POST" action="/admin/apps?tab=apps<?php echo $editApp?'&edit='.intval($editApp['id']):''; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="<?php echo $editApp ? 'update_app' : 'create_app'; ?>">
    <?php if ($editApp): ?>
    <input type="hidden" name="id" value="<?php echo intval($editApp['id']); ?>">
    <?php endif; ?>

    <?php if (!$editApp): ?>
    <div class="form-row">
      <label class="form-label">App ID <span style="color:var(--danger)">*</span></label>
      <input type="text" name="app_id" placeholder="ví dụ: my_app_v2" pattern="[a-z0-9._-]{3,100}" required>
      <div class="form-hint">Chỉ chữ thường, số, dấu . _ - (3–100 ký tự). Không thể thay đổi sau khi tạo.</div>
    </div>
    <?php else: ?>
    <div class="form-row">
      <label class="form-label">App ID</label>
      <input type="text" value="<?php echo htmlspecialchars($editApp['app_id']); ?>" disabled style="opacity:.7;font-family:monospace">
    </div>
    <?php endif; ?>

    <div class="form-row">
      <label class="form-label">Tên hiển thị <span style="color:var(--danger)">*</span></label>
      <input type="text" name="app_name" value="<?php echo htmlspecialchars($editApp['app_name'] ?? ''); ?>" required>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
      <div class="form-row">
        <label class="form-label">Verify Mode</label>
        <input type="text" name="verify_mode" value="<?php echo htmlspecialchars($editApp['verify_mode'] ?? 'standard'); ?>">
      </div>
      <div class="form-row">
        <label class="form-label">Max Devices mặc định</label>
        <input type="number" name="default_max_devices" min="1" max="999" value="<?php echo intval($editApp['default_max_devices'] ?? 2); ?>">
      </div>
      <div class="form-row">
        <label class="form-label">Số năm mặc định</label>
        <input type="number" name="default_years" min="1" max="100" value="<?php echo intval($editApp['default_years'] ?? 1); ?>">
      </div>
    </div>

    <div class="form-row">
      <label class="check-label">
        <input type="checkbox" name="is_active" value="1" <?php echo (!$editApp || intval($editApp['is_active'])===1)?'checked':''; ?>>
        Kích hoạt (Active) cho license mới
      </label>
    </div>
    <div class="form-row">
      <label class="check-label">
        <input type="checkbox" name="device_tracking" value="1" <?php echo (!$editApp || intval($editApp['device_tracking']??1)===1)?'checked':''; ?>>
        Đếm thiết bị (Device Tracking)
      </label>
      <div class="form-hint">Bỏ chọn nếu app này không giới hạn số thiết bị.</div>
    </div>

    <div class="flex flex-gap mt-16">
      <button type="submit" class="btn btn-primary"><?php echo $editApp ? 'Lưu' : 'Tạo App'; ?></button>
      <?php if ($editApp): ?>
      <a href="/admin/apps?tab=apps" class="btn btn-muted">Tạo mới thay thế</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- App list -->
<div class="card">
  <div class="card-title">Danh sách Apps</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>App ID</th><th>Tên</th><th>Verify</th><th>Mặc định</th><th>Tracking</th><th>Trạng thái</th><th>Hành động</th></tr></thead>
      <tbody>
      <?php foreach ($apps as $app): ?>
      <tr>
        <td><?php echo intval($app['id']); ?></td>
        <td class="mono text-sm"><?php echo htmlspecialchars($app['app_id']); ?></td>
        <td><?php echo htmlspecialchars($app['app_name']); ?></td>
        <td class="text-sm"><?php echo htmlspecialchars($app['verify_mode']); ?></td>
        <td class="text-sm"><?php echo intval($app['default_max_devices']); ?>d / <?php echo intval($app['default_years']); ?>y</td>
        <td><span class="pill <?php echo intval($app['device_tracking']??1)===1?'on':'off'; ?>"><?php echo intval($app['device_tracking']??1)===1?'Có đếm':'Không đếm'; ?></span></td>
        <td><span class="pill <?php echo intval($app['is_active'])===1?'on':'off'; ?>"><?php echo intval($app['is_active'])===1?'Active':'Inactive'; ?></span></td>
        <td>
          <div class="flex flex-gap">
            <a href="/admin/apps?tab=apps&edit=<?php echo intval($app['id']); ?>" class="btn btn-muted btn-sm">Sửa</a>
            <form method="POST" action="/admin/apps" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="action" value="toggle_app">
              <input type="hidden" name="id" value="<?php echo intval($app['id']); ?>">
              <input type="hidden" name="to" value="<?php echo intval($app['is_active'])===1?0:1; ?>">
              <button class="btn btn-sm <?php echo intval($app['is_active'])===1?'btn-warn':'btn-success'; ?>">
                <?php echo intval($app['is_active'])===1?'Disable':'Enable'; ?>
              </button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: /* aliases tab */ ?>
<!-- Create alias -->
<div class="card" style="max-width:500px">
  <div class="card-title">Thêm Alias</div>
  <form method="POST" action="/admin/apps?tab=aliases">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="create_alias">
    <div class="form-row">
      <label class="form-label">Alias</label>
      <input type="text" name="alias" pattern="[a-z0-9._-]{2,100}" placeholder="ví dụ: my_old_app_id" required>
    </div>
    <div class="form-row">
      <label class="form-label">Trỏ tới App ID</label>
      <select name="alias_app_id" required>
        <option value="">— Chọn app —</option>
        <?php foreach ($appIds as $aid): ?>
        <option value="<?php echo htmlspecialchars($aid); ?>"><?php echo htmlspecialchars($aid); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label class="form-label">Ghi chú</label>
      <input type="text" name="alias_note" placeholder="Tuỳ chọn">
    </div>
    <button type="submit" class="btn btn-primary">Thêm</button>
  </form>
</div>

<!-- Alias list -->
<div class="card">
  <div class="card-title">Danh sách Aliases</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Alias</th><th>Trỏ tới App ID</th><th>Ghi chú</th><th>Hành động</th></tr></thead>
      <tbody>
      <?php foreach ($aliases as $al): ?>
      <tr>
        <td class="mono"><?php echo htmlspecialchars($al['alias']); ?></td>
        <td class="mono text-sm"><?php echo htmlspecialchars($al['app_id']); ?></td>
        <td class="text-sm text-muted"><?php echo htmlspecialchars($al['note'] ?? ''); ?></td>
        <td>
          <form method="POST" action="/admin/apps?tab=aliases" onsubmit="return confirmAction('Xóa alias này?')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
            <input type="hidden" name="action" value="delete_alias">
            <input type="hidden" name="alias_id" value="<?php echo intval($al['id']); ?>">
            <button class="btn btn-danger btn-sm">Xóa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
