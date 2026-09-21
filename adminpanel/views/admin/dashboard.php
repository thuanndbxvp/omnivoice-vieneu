<?php $pageTitle = 'Dashboard'; $view = 'admin/dashboard'; ?>
<?php
// Message map
$msgMap = [
  'deleted'        => ['success', 'License đã xóa.'],
  'devices_reset'  => ['success', 'Đã reset thiết bị.'],
  'sync_success'   => ['success', '⚡ Đã đồng bộ thành công ' . (isset($_GET['synced']) ? intval($_GET['synced']) . ' ' : '') . 'license sang TiDB Cloud (Vercel)!'],
  'sync_failed'    => ['error',   '❌ Lỗi khi đồng bộ sang TiDB: ' . htmlspecialchars($_GET['sync_err'] ?? '')],
  'csrf_error'     => ['error',   'Lỗi CSRF. Vui lòng thử lại.'],
  'not_found'      => ['warn',    'Không tìm thấy license.'],
  'not_revoked'    => ['warn',    'License chưa bị thu hồi — không thể xóa.'],
  'db_error'       => ['error',   'Lỗi database.'],
];
[$alertType, $alertText] = $msg !== '' ? ($msgMap[$msg] ?? ['info', htmlspecialchars($msg)]) : ['', ''];
// Helper: build filter URL giữ nguyên các param khác
$filterUrl = function(array $override) use ($search, $status, $agency, $perPage): string {
  $params = array_filter(['search' => $search, 'status' => $status, 'agency' => $agency, 'per_page' => $perPage > 10 ? $perPage : ''],
    fn($v) => $v !== '' && $v !== 0);
  return '/admin/dashboard?' . http_build_query(array_merge($params, $override));
};
?>

<?php if ($alertType): ?>
<div class="alert alert-<?php echo $alertType; ?>"><?php echo $alertText; ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <a href="<?php echo $filterUrl(['status' => '', 'agency' => '']); ?>" class="stat-card stat-card-link">
    <div class="label">Tổng license</div>
    <div class="value"><?php echo $total; ?></div>
  </a>
  <a href="<?php echo $filterUrl(['status' => 'active']); ?>" class="stat-card stat-card-link success<?php echo $status === 'active' ? ' stat-card-active' : ''; ?>">
    <div class="label">Đang hoạt động</div>
    <div class="value"><?php echo $active; ?></div>
  </a>
  <a href="<?php echo $filterUrl(['status' => 'expired']); ?>" class="stat-card stat-card-link warn<?php echo $status === 'expired' ? ' stat-card-active' : ''; ?>">
    <div class="label">Hết hạn</div>
    <div class="value"><?php echo $expired; ?></div>
  </a>
  <a href="<?php echo $filterUrl(['status' => 'revoked']); ?>" class="stat-card stat-card-link danger<?php echo $status === 'revoked' ? ' stat-card-active' : ''; ?>">
    <div class="label">Đã thu hồi</div>
    <div class="value"><?php echo $revoked; ?></div>
  </a>
</div>

<!-- Agency stats -->
<?php if (!empty($agencyStats)): ?>
<div class="card mb-16">
  <div class="card-title">Thống kê theo đại lý</div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>Đại lý</th><th>Tổng</th><th>Hoạt động</th><th>Hết hạn</th><th>Thu hồi</th>
      </tr></thead>
      <tbody>
        <?php foreach ($agencyStats as $ag):
          $agKey  = $ag['agency_id'] === null ? 'none' : (string) $ag['agency_id'];
          $isSelAgency = $agency === $agKey;
        ?>
        <tr class="<?php echo $isSelAgency ? 'row-highlight' : ''; ?>">
          <td>
            <a href="<?php echo $filterUrl(['agency' => $agKey, 'status' => '']); ?>"
               class="filter-link<?php echo $isSelAgency && $status === '' ? ' active' : ''; ?>">
              <?php echo htmlspecialchars((string)($ag['agency_name'] ?? '')); ?>
            </a>
          </td>
          <td>
            <a href="<?php echo $filterUrl(['agency' => $agKey, 'status' => '']); ?>"
               class="filter-link<?php echo $isSelAgency && $status === '' ? ' active' : ''; ?>">
              <?php echo intval($ag['total']); ?>
            </a>
          </td>
          <td>
            <a href="<?php echo $filterUrl(['agency' => $agKey, 'status' => 'active']); ?>">
              <span class="pill pill-active<?php echo $isSelAgency && $status === 'active' ? ' pill-selected' : ''; ?>">
                <?php echo intval($ag['active']); ?>
              </span>
            </a>
          </td>
          <td>
            <a href="<?php echo $filterUrl(['agency' => $agKey, 'status' => 'expired']); ?>">
              <span class="pill pill-expired<?php echo $isSelAgency && $status === 'expired' ? ' pill-selected' : ''; ?>">
                <?php echo intval($ag['expired']); ?>
              </span>
            </a>
          </td>
          <td>
            <a href="<?php echo $filterUrl(['agency' => $agKey, 'status' => 'revoked']); ?>">
              <span class="pill pill-revoked<?php echo $isSelAgency && $status === 'revoked' ? ' pill-selected' : ''; ?>">
                <?php echo intval($ag['revoked']); ?>
              </span>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Active filter notice -->
<?php if ($status !== '' || $agency !== ''): ?>
<div class="filter-notice">
  <?php if ($agency !== ''): ?>
    <?php $agLabel = $agency === 'none' ? 'Không có đại lý' : ($agency); ?>
    <span>Đại lý: <strong><?php
      foreach ($agencyStats as $ag) {
        if (($ag['agency_id'] === null ? 'none' : (string)$ag['agency_id']) === $agency) {
          echo htmlspecialchars($ag['agency_name']); break;
        }
      }
    ?></strong></span>
  <?php endif; ?>
  <?php if ($status !== ''): ?>
    <?php $sLabel = ['active'=>'Hoạt động','expired'=>'Hết hạn','revoked'=>'Thu hồi']; ?>
    <span>Trạng thái: <strong><?php echo $sLabel[$status] ?? $status; ?></strong></span>
  <?php endif; ?>
  <a href="/admin/dashboard" class="btn btn-muted btn-sm">✕ Xóa bộ lọc</a>
</div>
<?php endif; ?>

<!-- Search & filter -->
<div class="card">
<div class="search-bar">
  <form method="GET" action="/admin/dashboard" style="display:contents">
    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm theo key / tên / email…">
    <input type="hidden" name="agency" value="<?php echo htmlspecialchars($agency); ?>">
    <select name="status">
      <option value="" <?php echo $status===''?'selected':''; ?>>Tất cả trạng thái</option>
      <option value="active"  <?php echo $status==='active'?'selected':''; ?>>Hoạt động</option>
      <option value="expired" <?php echo $status==='expired'?'selected':''; ?>>Hết hạn</option>
      <option value="revoked" <?php echo $status==='revoked'?'selected':''; ?>>Thu hồi</option>
    </select>
    <select name="per_page">
      <?php foreach ([10,50,100] as $pp): ?>
      <option value="<?php echo $pp; ?>" <?php echo $perPage===$pp?'selected':''; ?>><?php echo $pp; ?>/trang</option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Lọc</button>
    <?php if ($search !== '' || $status !== '' || $agency !== ''): ?>
    <a href="/admin/dashboard" class="btn btn-muted btn-sm">Xóa lọc</a>
    <?php endif; ?>
  </form>
  <a href="/admin/export?<?php echo http_build_query(['search'=>$search,'status'=>$status,'agency'=>$agency]); ?>" class="btn btn-muted btn-sm">↓ Xuất CSV</a>
  <?php /* Tạm thời disable nút đồng bộ TiDB theo yêu cầu
  <form method="POST" action="/admin/sync-tidb" style="display:inline" onsubmit="return confirm('Bạn có chắc muốn đồng bộ toàn bộ License sang TiDB (Vercel)?')">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
    <button type="submit" class="btn btn-sm" style="background:#0284c7;color:#fff;border-color:#0284c7" title="Đồng bộ toàn bộ dữ liệu License sang TiDB Cloud (Vercel)">⚡ Đồng bộ TiDB</button>
  </form>
  */ ?>
  <a href="/admin/create" class="btn btn-primary btn-sm">+ Tạo license</a>
</div>

<!-- Bulk action bar (hidden until at least 1 checked) -->
<div id="bulk-bar" style="display:none;align-items:center;gap:10px;margin-bottom:10px;padding:8px 12px;background:var(--surface2,#f0f4ff);border-radius:6px;border:1px solid var(--border)">
  <span id="bulk-count" class="text-sm text-muted">0 key đã chọn</span>
  <button type="button" class="btn btn-primary btn-sm" onclick="bulkEditSelected()">Sửa hàng loạt</button>
  <button type="button" class="btn btn-muted btn-sm" onclick="bulkClearAll()">Bỏ chọn tất cả</button>
  <?php if ($totalFiltered > 0): ?>
  <button type="button" class="btn btn-muted btn-sm" onclick="selectAllInDB()"
    title="Chọn tất cả kết quả phù hợp trong database, không chỉ trang này">
    Chọn tất cả <?php echo $totalFiltered; ?> trong DB
  </button>
  <?php endif; ?>
</div>

<div class="text-muted text-sm mb-8">
  Hiển thị <?php echo ($totalFiltered>0?$offset+1:0); ?>–<?php echo min($offset+$perPage,$totalFiltered); ?>
  trong tổng số <?php echo $totalFiltered; ?> license
</div>

<!-- Table -->
<div class="table-wrap">
<table>
  <thead><tr>
    <th style="width:36px"><input type="checkbox" id="bulk-check-all" title="Chọn tất cả" onchange="bulkToggleAll(this)"></th>
    <th>License Key</th>
    <th>Khách hàng</th>
    <th>Apps</th>
    <th>Hết hạn</th>
    <th>Thiết bị</th>
    <th>Trạng thái</th>
    <th>Hành động</th>
  </tr></thead>
  <tbody>
  <?php if (empty($licensesPage)): ?>
    <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text2)">Không có license nào.</td></tr>
  <?php else: foreach ($licensesPage as $l):
    $now2      = time();
    $expTs     = strtotime($l['expiry_date']);
    $isRev     = (bool) $l['revoked'];
    $isExp     = $expTs <= $now2;
    $status2   = $isRev ? 'revoked' : ($isExp ? 'expired' : 'active');
    $pills     = ['active'=>'pill-active','expired'=>'pill-expired','revoked'=>'pill-revoked'];
    $labels    = ['active'=>'Active','expired'=>'Expired','revoked'=>'Revoked'];
    $devCount  = isset($l['device_count']) ? (int)$l['device_count'] : count($l['devices'] ?? []);
    $appsLabel = implode('<br>', array_map(fn($id) => htmlspecialchars($appOptions[$id] ?? $id), $l['allowed_apps_list'] ?? []));
  ?>
  <tr>
    <td><input type="checkbox" class="bulk-chk" value="<?php echo intval($l['id']); ?>" onchange="bulkUpdateBar()"></td>
    <td class="mono"><a href="/admin/view?id=<?php echo intval($l['id']); ?>"><?php echo htmlspecialchars($l['license_key']); ?></a></td>
    <td>
      <div><?php echo htmlspecialchars($l['customer_name']); ?></div>
      <?php if ($l['email']): ?><div class="text-muted text-sm"><?php echo htmlspecialchars($l['email']); ?></div><?php endif; ?>
    </td>
    <td class="text-sm"><?php echo $appsLabel; ?></td>
    <td class="text-sm">
      <?php echo htmlspecialchars(date('d/m/Y', $expTs)); ?>
      <?php if (!$isRev && !$isExp):
        $dl = ceil(($expTs - $now2)/86400);
        if ($dl <= 30): ?><div class="text-sm" style="color:var(--warn)"><?php echo $dl; ?> ngày</div><?php endif;
      endif; ?>
    </td>
    <td class="text-sm"><?php echo $devCount; ?> / <?php echo intval($l['max_devices']); ?></td>
    <td><span class="pill <?php echo $pills[$status2]; ?>"><?php echo $labels[$status2]; ?></span></td>
    <td>
      <div class="flex flex-gap flex-wrap">
        <a href="/admin/view?id=<?php echo intval($l['id']); ?>" class="btn btn-muted btn-sm">Chi tiết</a>
        <a href="/admin/edit?id=<?php echo intval($l['id']); ?>" class="btn btn-muted btn-sm">Sửa</a>
        <form method="POST" action="/admin/revoke" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo intval($l['id']); ?>">
          <input type="hidden" name="action" value="<?php echo $isRev?'unrevoke':'revoke'; ?>">
          <button class="btn btn-sm <?php echo $isRev?'btn-success':'btn-warn'; ?>"><?php echo $isRev?'Khôi phục':'Thu hồi'; ?></button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="pagination mt-16">
  <?php
  $baseQ = ['search'=>$search,'status'=>$status,'agency'=>$agency,'per_page'=>$perPage];
  if ($page > 1): ?>
    <a href="?<?php echo http_build_query(array_merge($baseQ,['page'=>$page-1])); ?>">‹</a>
  <?php endif;
  $from = max(1, $page-3); $to = min($totalPages, $page+3);
  for ($i=$from; $i<=$to; $i++):
    if ($i===$page): ?><span class="current"><?php echo $i; ?></span>
    <?php else: ?><a href="?<?php echo http_build_query(array_merge($baseQ,['page'=>$i])); ?>"><?php echo $i; ?></a>
    <?php endif;
  endfor;
  if ($page < $totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($baseQ,['page'=>$page+1])); ?>">›</a>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>

<script>
function bulkUpdateBar() {
  var checked = document.querySelectorAll('.bulk-chk:checked');
  var bar = document.getElementById('bulk-bar');
  var count = document.getElementById('bulk-count');
  if (checked.length > 0) {
    bar.style.display = 'flex';
    count.textContent = checked.length + ' key đã chọn';
  } else {
    bar.style.display = 'none';
  }
  // Update "select all" indeterminate state
  var all = document.querySelectorAll('.bulk-chk');
  var ca = document.getElementById('bulk-check-all');
  if (ca) {
    ca.checked = checked.length === all.length && all.length > 0;
    ca.indeterminate = checked.length > 0 && checked.length < all.length;
  }
}

function bulkToggleAll(masterChk) {
  document.querySelectorAll('.bulk-chk').forEach(function(chk) {
    chk.checked = masterChk.checked;
  });
  bulkUpdateBar();
}

function bulkClearAll() {
  document.querySelectorAll('.bulk-chk').forEach(function(chk) { chk.checked = false; });
  var ca = document.getElementById('bulk-check-all');
  if (ca) { ca.checked = false; ca.indeterminate = false; }
  bulkUpdateBar();
}

function bulkEditSelected() {
  var checked = document.querySelectorAll('.bulk-chk:checked');
  if (checked.length === 0) return;
  var ids = Array.from(checked).map(function(c) { return c.value; });
  window.location.href = '/admin/bulk-edit?ids=' + ids.join(',');
}

function selectAllInDB() {
  var search = <?php echo json_encode($search); ?>;
  var status = <?php echo json_encode($status); ?>;
  var agency = <?php echo json_encode($agency); ?>;
  var url = '/admin/bulk-edit?select_all=1';
  if (search) url += '&search=' + encodeURIComponent(search);
  if (status) url += '&status=' + encodeURIComponent(status);
  if (agency) url += '&agency=' + encodeURIComponent(agency);
  window.location.href = url;
}
</script>
