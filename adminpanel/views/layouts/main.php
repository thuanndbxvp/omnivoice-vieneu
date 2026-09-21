<?php
/**
 * Main layout — wraps all admin views.
 * Variables available: $view (string), and all data from controller.
 */
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($pageTitle ?? 'License Manager'); ?> — Admin</title>
  <link rel="stylesheet" href="/public/css/app.css">
</head>
<body>
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand">License <span>Manager</span></div>
    <nav class="sidebar-nav">
      <?php
      $current = $_SERVER['REQUEST_URI'] ?? '';
      $nav = [
        ['/admin/dashboard',      'Dashboard',       '⊞'],
        ['/admin/create',         'Tạo License',     '+'],
        ['/admin/bulk-create',    'Tạo hàng loạt',   '≡'],
        ['/admin/apps',           'Quản lý Apps',    '⬡'],
        ['/admin/agencies',       'Đại lý',          '◈'],
        ['/admin/report',         'Báo cáo',         '◉'],
        ['/admin/change-password','Đổi mật khẩu',    '⚿'],
      ];
      foreach ($nav as [$href, $label, $icon]):
        $navActive = str_starts_with($current, $href) ? ' active' : '';
      ?>
      <a href="<?php echo $href; ?>" class="<?php echo trim($navActive); ?>">
        <span><?php echo $icon; ?></span> <?php echo $label; ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      Đăng nhập: <?php echo htmlspecialchars(Auth::currentAdmin()); ?><br>
      <a href="/admin/logout" style="color:var(--danger)">Đăng xuất</a>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <header class="topbar">
      <span class="topbar-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></span>
      <div class="topbar-actions">
        <span class="text-muted text-sm"><?php echo date('d/m/Y H:i'); ?></span>
      </div>
    </header>

    <main class="content">
      <?php require APP_ROOT . "/views/{$view}.php"; ?>
    </main>
  </div>
</div>
<script src="/public/js/app.js"></script>
</body>
</html>
