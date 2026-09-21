<?php $pageTitle = 'Quản lý Đại lý'; $view = 'admin/agencies'; ?>

<?php if ($msg !== ''): ?>
<div class="alert alert-<?php echo $msg==='saved'?'success':($msg==='error'?'error':'info'); ?>">
  <?php echo $msg==='saved'?'Đã lưu.':($msg==='error'?'Lỗi. Vui lòng thử lại.':htmlspecialchars($msg)); ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px">
<!-- Form -->
<div class="card">
  <div class="card-title"><?php echo $editItem ? 'Sửa Đại lý' : 'Thêm Đại lý'; ?></div>
  <form method="POST" action="/admin/agencies">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
    <input type="hidden" name="action" value="<?php echo $editItem ? 'update' : 'create'; ?>">
    <?php if ($editItem): ?><input type="hidden" name="id" value="<?php echo intval($editItem['id']); ?>"><?php endif; ?>

    <?php if ($editItem): ?>
    <div class="form-row">
      <label class="form-label">ID</label>
      <input type="text" value="<?php echo intval($editItem['id']); ?>" disabled style="opacity:.6">
    </div>
    <?php endif; ?>

    <div class="form-row">
      <label class="form-label">Mã đại lý <span style="color:var(--danger)">*</span></label>
      <input type="text" name="code" value="<?php echo htmlspecialchars($editItem['code'] ?? ''); ?>"
             pattern="[a-z0-9._-]{2,80}" placeholder="ví dụ: agency01" required>
      <div class="form-hint">Chỉ chữ thường, số, dấu . _ - (2–80 ký tự)</div>
    </div>
    <div class="form-row">
      <label class="form-label">Tên đại lý <span style="color:var(--danger)">*</span></label>
      <input type="text" name="name" value="<?php echo htmlspecialchars($editItem['name'] ?? ''); ?>" required>
    </div>
    <div class="form-row">
      <label class="check-label">
        <input type="checkbox" name="is_active" value="1" <?php echo (!$editItem || intval($editItem['is_active'])===1)?'checked':''; ?>>
        Kích hoạt
      </label>
    </div>
    <div class="flex flex-gap mt-16">
      <button type="submit" class="btn btn-primary"><?php echo $editItem?'Lưu':'Thêm'; ?></button>
      <?php if ($editItem): ?>
      <a href="/admin/agencies" class="btn btn-muted">Hủy</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- List -->
<div class="card">
  <div class="card-title">Danh sách Đại lý</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Mã</th><th>Tên</th><th>Trạng thái</th><th>Hành động</th></tr></thead>
      <tbody>
      <?php foreach ($agencies as $ag): ?>
      <tr>
        <td><?php echo intval($ag['id']); ?></td>
        <td class="mono text-sm"><?php echo htmlspecialchars($ag['code']); ?></td>
        <td><?php echo htmlspecialchars($ag['name']); ?></td>
        <td><span class="pill <?php echo intval($ag['is_active'])===1?'on':'off'; ?>"><?php echo intval($ag['is_active'])===1?'Active':'Inactive'; ?></span></td>
        <td>
          <div class="flex flex-gap">
            <a href="/admin/agencies?edit=<?php echo intval($ag['id']); ?>" class="btn btn-muted btn-sm">Sửa</a>
            <form method="POST" action="/admin/agencies" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Auth::csrfToken()); ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?php echo intval($ag['id']); ?>">
              <input type="hidden" name="to" value="<?php echo intval($ag['is_active'])===1?0:1; ?>">
              <button class="btn btn-sm <?php echo intval($ag['is_active'])===1?'btn-warn':'btn-success'; ?>">
                <?php echo intval($ag['is_active'])===1?'Disable':'Enable'; ?>
              </button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($agencies)): ?>
      <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text2)">Chưa có đại lý nào.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>
