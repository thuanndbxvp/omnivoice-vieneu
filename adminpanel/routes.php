<?php
/**
 * Route definitions.
 * All admin routes go through Layer-1 → Layer-2 (admin login).
 */

declare(strict_types=1);

use Controllers\AdminController;

// Enforce Layer-1 gate on every admin request
Auth::startSession();
if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/admin')) {
    Auth::enforceLayer1();
}

// ── Auth routes ───────────────────────────────────────────────────────────────
Router::add('ANY', '/admin/login',  [AdminController::class, 'loginPage']);
Router::add('ANY', '/admin/logout', [AdminController::class, 'logout']);

// ── Admin panel routes ────────────────────────────────────────────────────────
Router::add('ANY', '/admin/dashboard',      [AdminController::class, 'dashboard']);
Router::add('ANY', '/admin/view',           [AdminController::class, 'view']);
Router::add('ANY', '/admin/create',         [AdminController::class, 'create']);
Router::add('ANY', '/admin/edit',           [AdminController::class, 'edit']);
Router::add('ANY', '/admin/bulk-create',    [AdminController::class, 'bulkCreate']);
Router::add('ANY', '/admin/bulk-edit',      [AdminController::class, 'bulkEdit']);
Router::add('POST','/admin/revoke',         [AdminController::class, 'revoke']);
Router::add('POST','/admin/delete',         [AdminController::class, 'delete']);
Router::add('POST','/admin/reset-devices',  [AdminController::class, 'resetDevices']);
Router::add('ANY', '/admin/apps',           [AdminController::class, 'apps']);
Router::add('ANY', '/admin/agencies',       [AdminController::class, 'agencies']);
Router::add('ANY', '/admin/report',         [AdminController::class, 'report']);
Router::add('ANY', '/admin/change-password',[AdminController::class, 'changePassword']);
Router::add('GET', '/admin/export',         [AdminController::class, 'export']);
Router::add('POST','/admin/sync-tidb',      [AdminController::class, 'syncTidb']);

// ── Customer routes ───────────────────────────────────────────────────────────
Router::add('ANY', '/reset-device',         [AdminController::class, 'customerResetDevice']);

// ── API routes ────────────────────────────────────────────────────────────────
Router::add('ANY', '/api/verify',           fn() => require APP_ROOT . '/api/verify.php');
Router::add('ANY', '/api/verify.php',       fn() => require APP_ROOT . '/api/verify.php');
Router::add('ANY', '/api/license/activate', fn() => require APP_ROOT . '/api/license/activate.php');
Router::add('ANY', '/api/license/activate.php', fn() => require APP_ROOT . '/api/license/activate.php');
Router::add('ANY', '/api/license/verify',   fn() => require APP_ROOT . '/api/license/verify.php');
Router::add('ANY', '/api/license/verify.php', fn() => require APP_ROOT . '/api/license/verify.php');
Router::add('POST', '/api/sync_receiver',    fn() => require APP_ROOT . '/api/sync_receiver.php');
Router::add('POST', '/api/sync_receiver.php',fn() => require APP_ROOT . '/api/sync_receiver.php');

// ── Root redirect ─────────────────────────────────────────────────────────────
Router::add('GET', '/', function () {
    header('Location: /admin/dashboard');
    exit;
});
