<?php $pageTitle = 'Báo cáo & Thống kê'; $view = 'admin/report'; ?>

<!-- Overview stats -->
<div class="stats-grid">
  <div class="stat-card">         <div class="label">Tổng license</div>        <div class="value"><?php echo $overview['total']; ?></div></div>
  <div class="stat-card success"> <div class="label">Đang hoạt động</div>     <div class="value"><?php echo $overview['active']; ?></div></div>
  <div class="stat-card warn">    <div class="label">Hết hạn</div>            <div class="value"><?php echo $overview['expired']; ?></div></div>
  <div class="stat-card danger">  <div class="label">Thu hồi</div>            <div class="value"><?php echo $overview['revoked']; ?></div></div>
  <div class="stat-card info">    <div class="label">Slots thiết bị (tổng)</div><div class="value"><?php echo $overview['total_device_slots']; ?></div></div>
  <div class="stat-card info">    <div class="label">Thiết bị đã đăng ký</div><div class="value"><?php echo $overview['used_device_slots']; ?></div></div>
</div>

<!-- License sắp hết hạn -->
<?php if (!empty($expiring)): ?>
<div class="card">
  <div class="card-title">⚠ License sắp hết hạn trong 30 ngày (<?php echo count($expiring); ?>)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>License Key</th><th>Khách hàng</th><th>Đại lý</th><th>Hết hạn</th><th>Thiết bị</th></tr></thead>
      <tbody>
      <?php foreach ($expiring as $l): ?>
      <tr>
        <td class="mono"><a href="/admin/view?id=<?php echo intval($l['id']); ?>"><?php echo htmlspecialchars($l['license_key']); ?></a></td>
        <td><?php echo htmlspecialchars($l['customer_name']); ?></td>
        <td><?php echo htmlspecialchars($l['agency_name'] ?? '—'); ?></td>
        <td><?php echo htmlspecialchars(date('d/m/Y', strtotime($l['expiry_date']))); ?></td>
        <td><?php echo intval($l['device_count'] ?? 0); ?> / <?php echo intval($l['max_devices']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- License dùng nhiều nhất -->
<?php if (!empty($topLicenses)): ?>
<div class="card">
  <div class="card-title">Top License theo lượt gọi API</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>License Key</th><th>Khách hàng</th><th>Tổng</th><th>Thành công</th><th>Thất bại</th><th>App gần nhất</th><th>Trạng thái</th></tr></thead>
      <tbody>
      <?php foreach ($topLicenses as $l):
        $isRev = (bool)($l['revoked'] ?? 0);
        $isExp = strtotime($l['expiry_date'] ?? '') <= time();
        $sp = $isRev ? 'pill-revoked' : ($isExp ? 'pill-expired' : 'pill-active');
        $sl = $isRev ? 'Revoked' : ($isExp ? 'Expired' : 'Active');
      ?>
      <tr>
        <td class="mono"><a href="/admin/view?id=<?php echo intval($l['id'] ?? 0); ?>"><?php echo htmlspecialchars($l['license_key']); ?></a></td>
        <td><?php echo htmlspecialchars($l['customer_name'] ?? '—'); ?></td>
        <td><?php echo intval($l['total_requests']); ?></td>
        <td><span class="pill pill-active"><?php echo intval($l['success_requests']); ?></span></td>
        <td><span class="pill pill-revoked"><?php echo intval($l['failed_requests']); ?></span></td>
        <td class="text-sm"><?php echo htmlspecialchars($l['last_app_id'] ?? '—'); ?></td>
        <td><span class="pill <?php echo $sp; ?>"><?php echo $sl; ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

<!-- Theo đại lý -->
<?php if (!empty($byAgency)): ?>
<div class="card">
  <div class="card-title">Thống kê theo Đại lý</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Đại lý</th><th>Tổng</th><th>Active</th><th>Expired</th></tr></thead>
      <tbody>
      <?php foreach ($byAgency as $ag): ?>
      <tr>
        <td><?php echo htmlspecialchars($ag['agency_name']); ?></td>
        <td><?php echo intval($ag['total']); ?></td>
        <td><span class="pill pill-active"><?php echo intval($ag['active']); ?></span></td>
        <td><span class="pill pill-expired"><?php echo intval($ag['expired']); ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Theo App -->
<?php if (!empty($byApp)): ?>
<div class="card">
  <div class="card-title">Thống kê theo App (API calls)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>App ID</th><th>Tổng</th><th>Thành công</th><th>Thất bại</th></tr></thead>
      <tbody>
      <?php foreach ($byApp as $row): ?>
      <tr>
        <td class="mono text-sm"><?php echo htmlspecialchars($row['app_id']); ?></td>
        <td><?php echo intval($row['total_requests']); ?></td>
        <td><span class="pill pill-active"><?php echo intval($row['success_requests']); ?></span></td>
        <td><span class="pill pill-revoked"><?php echo intval($row['failed_requests']); ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div>

<!-- Lỗi phổ biến -->
<?php if (!empty($topErrors)): ?>
<div class="card">
  <div class="card-title">Lỗi phổ biến nhất (7 ngày qua)</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Error Code</th><th>Số lần</th></tr></thead>
      <tbody>
      <?php foreach ($topErrors as $err): ?>
      <tr>
        <td class="mono"><?php echo htmlspecialchars($err['error_code']); ?></td>
        <td><?php echo intval($err['count']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Thiết bị theo App -->
<?php if (!empty($devicesByApp)): ?>
<div class="card">
  <div class="card-title">Thiết bị đã đăng ký theo App</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>App</th><th>Unique Devices</th><th>Tổng kích hoạt</th></tr></thead>
      <tbody>
      <?php foreach ($devicesByApp as $row): ?>
      <tr>
        <td class="mono text-sm"><?php echo htmlspecialchars($row['app_id']); ?></td>
        <td><?php echo intval($row['unique_devices']); ?></td>
        <td><?php echo intval($row['total_activations']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- API calls / ngày (30 ngày) -->
<?php if (!empty($apiCalls)): ?>
<div class="card">
  <div class="card-title">Lượt gọi API — 30 ngày qua</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Ngày</th><th>Tổng</th><th>Thành công</th><th>Thất bại</th></tr></thead>
      <tbody>
      <?php foreach ($apiCalls as $row): ?>
      <tr>
        <td><?php echo htmlspecialchars($row['day']); ?></td>
        <td><?php echo intval($row['total']); ?></td>
        <td><?php echo intval($row['success']); ?></td>
        <td><?php echo intval($row['failed']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- License tạo / ngày -->
<?php if (!empty($activations)): ?>
<div class="card">
  <div class="card-title">License được tạo — 30 ngày qua</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Ngày</th><th>Số license tạo</th></tr></thead>
      <tbody>
      <?php foreach ($activations as $row): ?>
      <tr>
        <td><?php echo htmlspecialchars($row['day']); ?></td>
        <td><?php echo intval($row['count']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
