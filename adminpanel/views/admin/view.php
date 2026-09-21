<?php $pageTitle = 'Chi tiết License'; $view = 'admin/view'; ?>
<?php
$msgMap = [
  'devices_reset' => ['success', 'Đã reset thiết bị.'],
  'not_found'     => ['warn', 'Không tìm thấy.'],
];
[$alertType, $alertText] = $msg !== '' ? ($msgMap[$msg] ?? ['info', htmlspecialchars($msg)]) : ['', ''];

$now2    = time();
$expTs   = strtotime($license['expiry_date']);
$isRev   = (bool) $license['revoked'];
$isExp   = $expTs <= $now2;
$statusLabel = $isRev ? 'Revoked' : ($isExp ? 'Expired' : 'Active');
$statusPill  = $isRev ? 'pill-revoked' : ($isExp ? 'pill-expired' : 'pill-active');
?>

<?php if ($alertType): ?>
<div class="alert alert-<?php echo $alertType; ?>"><?php echo $alertText; ?></div>
<?php endif; ?>

<div class="flex justify-between items-center mb-16 flex-wrap flex-gap">
  <a href="/admin/dashboard" class="btn btn-muted btn-sm">← Dashboard</a>
  <div class="flex flex-gap flex-wrap">
    <a href="/admin/edit?id=<?php echo intval($license['id']); ?>" class="btn btn-primary btn-sm">Sửa</a>
    <form method="POST" action="/admin/revoke" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo intval($license['id']); ?>">
      <input type="hidden" name="action" value="<?php echo $isRev?'unrevoke':'revoke'; ?>">
      <button class="btn btn-sm <?php echo $isRev?'btn-success':'btn-warn'; ?>"><?php echo $isRev?'Khôi phục':'Thu hồi'; ?></button>
    </form>
    <form method="POST" action="/admin/reset-devices" style="display:inline" onsubmit="return confirmAction('Reset tất cả thiết bị?')">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo intval($license['id']); ?>">
      <input type="hidden" name="return_to" value="view">
      <button class="btn btn-warn btn-sm">Reset Thiết bị</button>
    </form>
    <?php if ($isRev): ?>
    <form method="POST" action="/admin/delete" style="display:inline" onsubmit="return confirmAction('Xóa vĩnh viễn license này?')">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
      <input type="hidden" name="id" value="<?php echo intval($license['id']); ?>">
      <button class="btn btn-danger btn-sm">Xóa</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- License info -->
<div class="card">
  <div class="card-title">Thông tin License</div>
  <div class="detail-grid">
    <span class="key">License Key</span>
    <span class="val mono">
      <?php echo htmlspecialchars($license['license_key']); ?>
      <button class="btn btn-muted btn-sm" onclick="copyText('<?php echo htmlspecialchars($license['license_key']); ?>')">Copy</button>
    </span>

    <span class="key">Khách hàng</span>
    <span class="val"><?php echo htmlspecialchars($license['customer_name']); ?></span>

    <span class="key">Email</span>
    <span class="val"><?php echo htmlspecialchars($license['email'] ?: '—'); ?></span>

    <span class="key">Trạng thái</span>
    <span class="val"><span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span></span>

    <span class="key">Hết hạn</span>
    <span class="val">
      <?php echo htmlspecialchars(date('d/m/Y H:i', $expTs)); ?>
      <?php if (!$isRev && !$isExp): ?>
        <span class="text-muted text-sm">(còn <?php echo $daysLeft; ?> ngày)</span>
      <?php endif; ?>
    </span>

    <span class="key">Max Thiết bị</span>
    <span class="val"><?php echo intval($license['max_devices']); ?></span>

    <span class="key">Apps</span>
    <span class="val"><?php echo htmlspecialchars(implode(' + ', $allowedLabels)); ?></span>

    <?php if (!empty($license['agency_name'])): ?>
    <span class="key">Đại lý</span>
    <span class="val"><?php echo htmlspecialchars($license['agency_name']); ?></span>
    <?php endif; ?>

    <span class="key">Session Version</span>
    <span class="val"><?php echo intval($license['session_version'] ?? 1); ?></span>

    <span class="key">Ngày tạo</span>
    <span class="val"><?php echo htmlspecialchars($license['created_at']); ?></span>

    <?php if (!empty($license['admin_note'])): ?>
    <span class="key">Ghi chú</span>
    <span class="val"><?php echo nl2br(htmlspecialchars($license['admin_note'])); ?></span>
    <?php endif; ?>
  </div>
</div>

<!-- Devices -->
<div class="card">
  <div class="card-title">Thiết bị đã đăng ký (<?php echo count($license['devices'] ?? []); ?>)</div>
  <?php if (empty($license['devices'])): ?>
    <p class="text-muted">Chưa có thiết bị nào.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Device ID</th><th>Kích hoạt lúc</th><th>Truy cập gần nhất</th></tr></thead>
      <tbody>
      <?php foreach ($license['devices'] as $i => $d): ?>
      <tr>
        <td><?php echo $i+1; ?></td>
        <td class="mono"><?php echo htmlspecialchars($d['device_id']); ?></td>
        <td><?php echo htmlspecialchars($d['activated_at'] ?? '—'); ?></td>
        <td><?php echo htmlspecialchars($d['last_seen'] ?? '—'); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Request counter -->
<?php if ($requestCounter): ?>
<div class="card">
  <div class="card-title">Thống kê API</div>
  <div class="stats-grid">
    <div class="stat-card"><div class="label">Tổng requests</div><div class="value"><?php echo intval($requestCounter['total_requests']); ?></div></div>
    <div class="stat-card success"><div class="label">Thành công</div><div class="value"><?php echo intval($requestCounter['success_requests']); ?></div></div>
    <div class="stat-card danger"><div class="label">Thất bại</div><div class="value"><?php echo intval($requestCounter['failed_requests']); ?></div></div>
    <div class="stat-card"><div class="label">Kích hoạt</div><div class="value"><?php echo intval($requestCounter['activate_requests'] ?? 0); ?></div></div>
  </div>
  <p class="text-muted text-sm">IP gần nhất: <?php echo htmlspecialchars($requestCounter['last_ip'] ?? '—'); ?> | App: <?php echo htmlspecialchars($requestCounter['last_app_id'] ?? '—'); ?></p>
</div>
<?php endif; ?>

<!-- Request logs -->
<?php if (!empty($requestLogs)): ?>
<div class="card">
  <div class="flex justify-between items-center mb-16">
    <div class="card-title" style="margin-bottom:0">Log API (<?php echo count($requestLogs); ?>)</div>
    <form method="GET" class="flex flex-gap items-center" style="margin-bottom:0">
      <input type="hidden" name="id" value="<?php echo intval($license['id']); ?>">
      <select name="log_limit" style="width:auto">
        <?php foreach([10,25,50,100,200] as $lim): ?>
        <option value="<?php echo $lim; ?>" <?php echo $logLimit===$lim?'selected':''; ?>><?php echo $lim; ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-muted btn-sm" type="submit">Lấy</button>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Thời gian</th><th>Action</th><th>Outcome</th><th>App</th><th>IP</th><th>Error</th></tr></thead>
      <tbody>
      <?php foreach ($requestLogs as $log): ?>
      <tr>
        <td class="text-sm"><?php echo htmlspecialchars($log['created_at'] ?? ''); ?></td>
        <td><span class="pill pill-info"><?php echo htmlspecialchars($log['action'] ?? ''); ?></span></td>
        <td><?php
          $out = $log['outcome'] ?? '';
          $cls = in_array($out, ['valid','ok','active']) ? 'pill-active' : 'pill-revoked';
          echo '<span class="pill ' . $cls . '">' . htmlspecialchars($out) . '</span>';
        ?></td>
        <td class="text-sm"><?php echo htmlspecialchars($log['app_id'] ?? '—'); ?></td>
        <td class="text-sm mono"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
        <td class="text-sm text-muted"><?php echo htmlspecialchars($log['error_code'] ?? ''); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
