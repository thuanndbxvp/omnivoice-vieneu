<?php $pageTitle = 'Tạo License hàng loạt'; $view = 'admin/bulk_create'; ?>

<?php if ($formError !== ''): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($formError); ?></div>
<?php endif; ?>

<!-- Results -->
<?php if (!empty($results)):
  $okCount  = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
  $errCount = count($results) - $okCount;
  $allKeys  = implode("\n", array_map(fn($r) => $r['key'], array_filter($results, fn($r) => $r['status'] === 'ok')));
?>
<div class="card">
  <div class="card-title">Kết quả: <?php echo $okCount; ?> thành công / <?php echo $errCount; ?> lỗi</div>
  <?php if ($okCount): ?>
  <div class="flex flex-gap mb-8">
    <button class="btn btn-muted btn-sm" onclick="copyAllKeys('allKeysArea')">Copy tất cả key</button>
  </div>
  <textarea id="allKeysArea" readonly rows="3" style="font-family:monospace;font-size:12px"><?php echo htmlspecialchars($allKeys); ?></textarea>
  <?php endif; ?>
  <div class="table-wrap mt-16">
    <table>
      <thead><tr><th>Email / Tên</th><th>License Key</th><th>Trạng thái</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
      <tr>
        <td><?php echo htmlspecialchars($r['email'] ?: ($r['name'] ?? '—')); ?></td>
        <td class="mono"><?php echo htmlspecialchars($r['key']); ?></td>
        <td>
          <?php if ($r['status'] === 'ok'): ?>
            <span class="pill pill-active">OK</span>
          <?php else: ?>
            <span class="pill pill-revoked"><?php echo htmlspecialchars($r['error']); ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['key']): ?>
          <button class="btn btn-muted btn-sm" onclick="copyText('<?php echo htmlspecialchars($r['key']); ?>')">Copy</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Form -->
<div class="card" style="max-width:700px">
<div class="card-title">Tạo license hàng loạt</div>
<form method="POST" action="/admin/bulk-create" id="bulkCreateForm">
  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
  <input type="hidden" name="mode" id="modeInput" value="<?php echo htmlspecialchars($form['mode'] ?? 'email'); ?>">

  <!-- Mode toggle -->
  <div class="form-row">
    <label class="form-label">Chế độ tạo</label>
    <div class="flex flex-gap" style="gap:8px">
      <button type="button" id="btnModeEmail" onclick="setMode('email')"
        class="btn btn-sm <?php echo ($form['mode'] ?? 'email') === 'email' ? 'btn-primary' : 'btn-muted'; ?>">
        Theo danh sách Email
      </button>
      <button type="button" id="btnModeCount" onclick="setMode('count')"
        class="btn btn-sm <?php echo ($form['mode'] ?? 'email') === 'count' ? 'btn-primary' : 'btn-muted'; ?>">
        Theo số lượng key
      </button>
    </div>
  </div>

  <!-- Mode: Email -->
  <div id="sectionEmail" <?php echo ($form['mode'] ?? 'email') === 'count' ? 'style="display:none"' : ''; ?>>
    <div class="form-row">
      <label class="form-label">Danh sách Email <span style="color:var(--danger)">*</span></label>
      <textarea name="emails" rows="6" placeholder="Mỗi email trên 1 dòng (hoặc cách nhau bằng dấu phẩy)&#10;user1@email.com&#10;user2@email.com"><?php echo htmlspecialchars($form['emails']); ?></textarea>
      <div class="form-hint">Phân cách bằng xuống dòng, dấu phẩy, hoặc chấm phẩy.</div>
    </div>
  </div>

  <!-- Mode: Count -->
  <div id="sectionCount" <?php echo ($form['mode'] ?? 'email') !== 'count' ? 'style="display:none"' : ''; ?>>
    <div class="form-row">
      <label class="form-label">Số lượng key cần tạo <span style="color:var(--danger)">*</span></label>
      <input type="number" name="num_keys" id="numKeysInput" min="1" max="500"
        value="<?php echo intval($form['num_keys'] ?? 1); ?>"
        placeholder="VD: 10">
      <div class="form-hint">Tối đa 500 key mỗi lần. Key sẽ được tạo không gắn email.</div>
    </div>
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
    <textarea name="admin_note" rows="2"><?php echo htmlspecialchars($form['admin_note']); ?></textarea>
  </div>

  <div class="form-row">
    <label class="check-label">
      <input type="checkbox" name="start_count_now" value="1" <?php echo $form['start_count_now']?'checked':''; ?>>
      Bắt đầu tính từ bây giờ
    </label>
  </div>

  <div class="flex flex-gap mt-16">
    <button type="submit" class="btn btn-primary">Tạo License</button>
    <a href="/admin/dashboard" class="btn btn-muted">Hủy</a>
  </div>
</form>
</div>

<script>
function setMode(mode) {
  document.getElementById('modeInput').value = mode;
  var isEmail = mode === 'email';
  document.getElementById('sectionEmail').style.display = isEmail ? '' : 'none';
  document.getElementById('sectionCount').style.display = isEmail ? 'none' : '';
  var btnEmail = document.getElementById('btnModeEmail');
  var btnCount = document.getElementById('btnModeCount');
  btnEmail.className = 'btn btn-sm ' + (isEmail ? 'btn-primary' : 'btn-muted');
  btnCount.className = 'btn btn-sm ' + (isEmail ? 'btn-muted' : 'btn-primary');
  if (!isEmail) {
    var ni = document.getElementById('numKeysInput');
    if (ni && !ni.value) ni.value = '1';
  }
}
</script>
