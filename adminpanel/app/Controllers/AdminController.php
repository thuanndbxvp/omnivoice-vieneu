<?php
/**
 * AdminController — xử lý toàn bộ request của admin panel.
 * Mỗi method tương ứng 1 action, không chứa HTML.
 */

declare(strict_types=1);

namespace Controllers;

use Auth;
use Database;
use Models\AppModel;
use Models\AppAliasModel;
use Models\AgencyModel;
use Models\LicenseModel;
use Services\ReportService;
use Services\SecurityService;

class AdminController {

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function redirect(string $path, array $params = []): never {
        $qs  = $params ? ('?' . http_build_query($params)) : '';
        header("Location: {$path}{$qs}");
        exit;
    }

    private static function render(string $view, array $data = []): void {
        extract($data);
        require APP_ROOT . "/views/layouts/main.php";
    }

    private static function validateCsrf(): void {
        if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
            self::redirect('/admin/dashboard', ['msg' => 'csrf_error']);
        }
    }

    private static function generateKey(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $part  = fn() => implode('', array_map(fn() => $chars[random_int(0, 35)], range(1, 4)));
        return "{$part()}-{$part()}-{$part()}-{$part()}";
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public static function dashboard(): void {
        Auth::requireLogin();
        $search   = trim((string) ($_GET['search'] ?? ''));
        $status   = in_array($_GET['status'] ?? '', ['active', 'expired', 'revoked']) ? $_GET['status'] : '';
        $agency   = trim((string) ($_GET['agency'] ?? ''));
        $msg      = (string) ($_GET['msg'] ?? '');
        $perPage  = in_array((int) ($_GET['per_page'] ?? 10), [10, 50, 100]) ? (int) ($_GET['per_page'] ?? 10) : 10;
        $page     = max(1, (int) ($_GET['page'] ?? 1));

        $licenses      = LicenseModel::all($search, $status, $agency);
        $totalFiltered = count($licenses);
        $totalPages    = max(1, (int) ceil($totalFiltered / $perPage));
        $page          = min($page, $totalPages);
        $offset        = ($page - 1) * $perPage;
        $licensesPage  = array_slice($licenses, $offset, $perPage);

        $now     = time();
        $total   = count($licenses);
        $active  = count(array_filter($licenses, fn($l) => !$l['revoked'] && strtotime($l['expiry_date']) > $now));
        $expired = count(array_filter($licenses, fn($l) => strtotime($l['expiry_date']) <= $now));
        $revoked = count(array_filter($licenses, fn($l) => $l['revoked']));

        $appOptions  = AppModel::map(true);
        $agencyStats = Database::hasColumn('licenses', 'agency_id') ? ReportService::byAgency() : [];

        self::render('admin/dashboard', compact(
            'search', 'status', 'agency', 'msg', 'perPage', 'page',
            'licenses', 'licensesPage', 'totalFiltered', 'totalPages', 'offset',
            'total', 'active', 'expired', 'revoked',
            'appOptions', 'agencyStats'
        ));
    }

    // ── View license ──────────────────────────────────────────────────────────

    public static function view(): void {
        Auth::requireLogin();
        $id      = (int) ($_GET['id'] ?? 0);
        $msg     = (string) ($_GET['msg'] ?? '');
        $license = LicenseModel::find($id);
        if (!$license) self::redirect('/admin/dashboard');

        $isExpired   = strtotime($license['expiry_date']) <= time();
        $daysLeft    = max(0, (int) ceil((strtotime($license['expiry_date']) - time()) / 86400));
        $appOptions  = AppModel::map(true);
        $allowedApps = $license['allowed_apps_list'] ?? LicenseModel::getAllowedApps($license);
        $allowedLabels = array_map(fn($id) => $appOptions[$id] ?? $id, $allowedApps);
        $logLimit    = max(10, min(200, (int) ($_GET['log_limit'] ?? 50)));
        $requestCounter = LicenseModel::getRequestCounter($license['license_key']);
        $requestLogs    = LicenseModel::getRequestLogs($license['license_key'], $logLimit);

        self::render('admin/view', compact(
            'license', 'msg', 'isExpired', 'daysLeft',
            'appOptions', 'allowedApps', 'allowedLabels',
            'logLimit', 'requestCounter', 'requestLogs'
        ));
    }

    // ── Create license ────────────────────────────────────────────────────────

    public static function create(): void {
        Auth::requireLogin();
        $availableApps  = AppModel::map(true);
        $agencyOptions  = Database::hasColumn('licenses', 'agency_id') ? AgencyModel::all(false) : [];
        $defaultApps    = LicenseModel::normalizeAllowedApps([]);
        $error = $success = '';
        $form  = [
            'license_key'     => self::generateKey(),
            'customer_name'   => '',
            'email'           => '',
            'validity'        => DEFAULT_LICENSE_VALIDITY,
            'max_devices'     => DEFAULT_MAX_DEVICES,
            'admin_note'      => '',
            'agency_id'       => 0,
            'start_count_now' => 1,
            'allowed_apps'    => $defaultApps,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();
            $form = [
                'license_key'     => strtoupper(trim($_POST['license_key'] ?? '')),
                'customer_name'   => trim($_POST['customer_name'] ?? ''),
                'email'           => trim($_POST['email'] ?? ''),
                'validity'        => (int) ($_POST['validity'] ?? DEFAULT_LICENSE_VALIDITY),
                'max_devices'     => (int) ($_POST['max_devices'] ?? DEFAULT_MAX_DEVICES),
                'admin_note'      => trim($_POST['admin_note'] ?? ''),
                'agency_id'       => (int) ($_POST['agency_id'] ?? 0),
                'start_count_now' => isset($_POST['start_count_now']) ? 1 : 0,
                'allowed_apps'    => LicenseModel::normalizeAllowedApps($_POST['allowed_apps'] ?? []),
            ];

            $error = self::validateLicenseForm($form);
            if ($error === '') {
                try {
                    LicenseModel::create(
                        $form['license_key'], $form['customer_name'], $form['email'],
                        $form['validity'], $form['max_devices'], $form['allowed_apps'],
                        $form['admin_note'], $form['agency_id'] > 0 ? $form['agency_id'] : null,
                        (bool) $form['start_count_now']
                    );
                    $success = 'License created: ' . $form['license_key'];
                    $form['license_key'] = self::generateKey();
                } catch (\PDOException $e) {
                    $error = (string) $e->getCode() === '23000'
                        ? 'License key already exists.'
                        : 'Database error. Ref: ' . bin2hex(random_bytes(4));
                }
            }
        }

        self::render('admin/create', compact('form', 'error', 'success', 'availableApps', 'agencyOptions'));
    }

    // ── Edit license ──────────────────────────────────────────────────────────

    public static function edit(): void {
        Auth::requireLogin();
        $id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        $license = LicenseModel::find($id);
        if (!$license) self::redirect('/admin/dashboard');

        $availableApps = AppModel::map(true);
        $agencyOptions = Database::hasColumn('licenses', 'agency_id') ? AgencyModel::all(false) : [];
        $error = $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();
            $fields = [
                'customer_name' => trim($_POST['customer_name'] ?? ''),
                'email'         => trim($_POST['email'] ?? ''),
                'expiry_date'   => trim($_POST['expiry_date'] ?? ''),
                'max_devices'   => (int) ($_POST['max_devices'] ?? 1),
                'revoked'       => isset($_POST['revoked']) ? 1 : 0,
                'admin_note'    => trim($_POST['admin_note'] ?? ''),
                'allowed_apps'  => json_encode(LicenseModel::normalizeAllowedApps($_POST['allowed_apps'] ?? [])),
                'agency_id'     => (($aid = (int) ($_POST['agency_id'] ?? 0)) > 0) ? $aid : null,
            ];

            if ($fields['customer_name'] === '') { $error = 'Customer name is required.'; }
            elseif ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) { $error = 'Invalid email.'; }
            elseif ($fields['max_devices'] < 1) { $error = 'Max devices must be >= 1.'; }

            if ($error === '') {
                try {
                    LicenseModel::update($id, $fields);
                    $license = LicenseModel::find($id);
                    $success = 'Saved.';
                } catch (\Exception $e) {
                    $error = 'Save failed: ' . $e->getMessage();
                }
            }
        }

        self::render('admin/edit', compact('license', 'error', 'success', 'availableApps', 'agencyOptions'));
    }

    // ── Bulk edit ─────────────────────────────────────────────────────────────

    public static function bulkEdit(): void {
        Auth::requireLogin();
        $availableApps = AppModel::map(true);
        $agencyOptions = Database::hasColumn('licenses', 'agency_id') ? AgencyModel::all(false) : [];
        $error = $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();

            $rawIds = $_POST['ids'] ?? [];
            $selectedIds = array_values(array_filter(array_unique(array_map('intval', (array) $rawIds))));
            if (empty($selectedIds)) {
                self::redirect('/admin/dashboard');
            }

            // Read apply-flags and values
            $applyApps       = !empty($_POST['apply_apps']);
            $applyNote       = !empty($_POST['apply_note']);
            $applyMaxDevices = !empty($_POST['apply_max_devices']);
            $applyRevoked    = !empty($_POST['apply_revoked']);
            $applyAgency     = !empty($_POST['apply_agency']);

            $extendDays  = (int) ($_POST['extend_days'] ?? 0);
            $expiryDate  = trim($_POST['expiry_date'] ?? '');
            $adminNote   = trim($_POST['admin_note'] ?? '');
            $maxDevices  = max(1, min(999, (int) ($_POST['max_devices'] ?? 1)));
            $revokedVal  = (int) ($_POST['revoked_value'] ?? 0);
            $agencyId    = (int) ($_POST['agency_id'] ?? 0);
            $allowedApps = LicenseModel::normalizeAllowedApps($_POST['allowed_apps'] ?? []);

            $hasChange = $applyApps || $applyNote || $applyMaxDevices || $applyRevoked || $applyAgency
                      || $extendDays > 0 || $expiryDate !== '';
            if (!$hasChange) {
                $licenses = LicenseModel::findByIds($selectedIds);
                $error = 'Không có thay đổi nào được chọn.';
                self::render('admin/bulk_edit', compact('licenses', 'selectedIds', 'availableApps', 'agencyOptions', 'error', 'success'));
                return;
            }

            // Batch fetch all licenses (1 query)
            $licensesMap = [];
            foreach (LicenseModel::findByIds($selectedIds) as $lic) {
                $licensesMap[(int)$lic['id']] = $lic;
            }

            $updated = 0;
            foreach ($selectedIds as $sid) {
                if (!isset($licensesMap[$sid])) continue;
                $license = $licensesMap[$sid];
                $fields  = [];

                if ($applyApps && !empty($allowedApps)) {
                    $fields['allowed_apps'] = json_encode($allowedApps);
                }

                if ($expiryDate !== '') {
                    $ts = strtotime($expiryDate);
                    if ($ts > 0) $fields['expiry_date'] = date('Y-m-d H:i:s', $ts);
                } elseif ($extendDays > 0) {
                    $base = max(strtotime($license['expiry_date']), time());
                    $fields['expiry_date'] = date('Y-m-d H:i:s', $base + $extendDays * 86400);
                }

                if ($applyNote) {
                    $fields['admin_note'] = mb_substr($adminNote, 0, 2000);
                }

                if ($applyMaxDevices) {
                    $fields['max_devices'] = $maxDevices;
                }

                if ($applyRevoked) {
                    $fields['revoked'] = $revokedVal;
                }

                if ($applyAgency && Database::hasColumn('licenses', 'agency_id')) {
                    $fields['agency_id'] = $agencyId > 0 ? $agencyId : null;
                }

                if (!empty($fields)) {
                    LicenseModel::update($sid, $fields);
                    $updated++;
                }
            }

            $success  = "Đã cập nhật {$updated} license.";
            $licenses = LicenseModel::findByIds($selectedIds); // 1 query to reload
            self::render('admin/bulk_edit', compact('licenses', 'selectedIds', 'availableApps', 'agencyOptions', 'error', 'success'));
            return;
        }

        // GET — "select all in DB" shortcut
        if (!empty($_GET['select_all'])) {
            $search = trim($_GET['search'] ?? '');
            $status = $_GET['status'] ?? '';
            $allIds = LicenseModel::allIds($search, $status);
            if (empty($allIds)) self::redirect('/admin/dashboard');
            self::redirect('/admin/bulk-edit?ids=' . implode(',', $allIds));
        }

        // GET — ids passed as comma-separated query param
        $rawIds      = $_GET['ids'] ?? '';
        $selectedIds = array_values(array_filter(array_unique(array_map('intval', explode(',', (string) $rawIds)))));
        if (empty($selectedIds)) self::redirect('/admin/dashboard');

        $licenses = LicenseModel::findByIds($selectedIds); // 1 query
        if (empty($licenses)) self::redirect('/admin/dashboard');

        self::render('admin/bulk_edit', compact('licenses', 'selectedIds', 'availableApps', 'agencyOptions', 'error', 'success'));
    }

    // ── Bulk create ───────────────────────────────────────────────────────────

    public static function bulkCreate(): void {
        Auth::requireLogin();
        $availableApps = AppModel::map(true);
        $agencyOptions = Database::hasColumn('licenses', 'agency_id') ? AgencyModel::all(false) : [];
        $results = [];
        $formError = '';
        $form = [
            'mode' => 'email', 'emails' => '',
            'num_keys' => 1, 'validity' => DEFAULT_LICENSE_VALIDITY,
            'max_devices' => DEFAULT_MAX_DEVICES, 'admin_note' => '',
            'agency_id' => 0, 'start_count_now' => 1,
            'allowed_apps' => LicenseModel::normalizeAllowedApps([]),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();
            $form = [
                'mode'            => in_array($_POST['mode'] ?? '', ['email', 'count']) ? $_POST['mode'] : 'email',
                'emails'          => trim($_POST['emails'] ?? ''),
                'num_keys'        => max(1, min(500, (int) ($_POST['num_keys'] ?? 1))),
                'validity'        => (int) ($_POST['validity']    ?? DEFAULT_LICENSE_VALIDITY),
                'max_devices'     => (int) ($_POST['max_devices'] ?? DEFAULT_MAX_DEVICES),
                'admin_note'      => trim($_POST['admin_note']    ?? ''),
                'agency_id'       => (int) ($_POST['agency_id']   ?? 0),
                'start_count_now' => isset($_POST['start_count_now']) ? 1 : 0,
                'allowed_apps'    => LicenseModel::normalizeAllowedApps($_POST['allowed_apps'] ?? []),
            ];

            if ($form['validity'] < 1 || $form['validity'] > 3650) { $formError = 'Validity must be 1–3650 days.'; }
            elseif ($form['max_devices'] < 1) { $formError = 'Max devices must be >= 1.'; }
            elseif (empty($form['allowed_apps'])) { $formError = 'Select at least one app.'; }
            elseif ($form['mode'] === 'email' && $form['emails'] === '') { $formError = 'Danh sách email không được để trống.'; }
            elseif ($form['mode'] === 'count' && $form['num_keys'] < 1) { $formError = 'Số lượng key phải >= 1.'; }

            if ($formError === '') {
                if ($form['mode'] === 'email') {
                    // Mode: tạo theo danh sách email
                    $rawEmails = preg_split('/[\s,;]+/', $form['emails'], -1, PREG_SPLIT_NO_EMPTY);
                    $emails    = array_unique(array_filter(array_map('trim', $rawEmails)));
                    foreach ($emails as $email) {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $results[] = ['email' => $email, 'name' => '', 'key' => '', 'status' => 'error', 'error' => 'Invalid email'];
                            continue;
                        }
                        $name = strstr($email, '@', true) ?: $email;
                        $key  = '';
                        $err  = 'Failed after retries';
                        for ($attempt = 0; $attempt < 5; $attempt++) {
                            $key = self::generateKey();
                            try {
                                LicenseModel::create(
                                    $key, $name, $email, $form['validity'], $form['max_devices'],
                                    $form['allowed_apps'], $form['admin_note'],
                                    $form['agency_id'] > 0 ? $form['agency_id'] : null,
                                    (bool) $form['start_count_now']
                                );
                                $err = '';
                                break;
                            } catch (\PDOException $e) {
                                if ((string) $e->getCode() === '23000') { continue; }
                                $err = 'DB error';
                                break;
                            }
                        }
                        $results[] = ['email' => $email, 'name' => $name, 'key' => $key, 'status' => $err === '' ? 'ok' : 'error', 'error' => $err];
                    }
                } else {
                    // Mode: tạo theo số lượng (không cần email)
                    for ($i = 0; $i < $form['num_keys']; $i++) {
                        $key = '';
                        $err = 'Failed after retries';
                        for ($attempt = 0; $attempt < 5; $attempt++) {
                            $key = self::generateKey();
                            try {
                                LicenseModel::create(
                                    $key, '', '', $form['validity'], $form['max_devices'],
                                    $form['allowed_apps'], $form['admin_note'],
                                    $form['agency_id'] > 0 ? $form['agency_id'] : null,
                                    (bool) $form['start_count_now']
                                );
                                $err = '';
                                break;
                            } catch (\PDOException $e) {
                                if ((string) $e->getCode() === '23000') { continue; }
                                $err = 'DB error';
                                break;
                            }
                        }
                        $results[] = ['email' => '', 'name' => 'Key #' . ($i + 1), 'key' => $key, 'status' => $err === '' ? 'ok' : 'error', 'error' => $err];
                    }
                }
            }
        }

        self::render('admin/bulk_create', compact('form', 'formError', 'results', 'availableApps', 'agencyOptions'));
    }

    // ── Revoke / Unrevoke ─────────────────────────────────────────────────────

    public static function revoke(): void {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') self::redirect('/admin/dashboard');
        self::validateCsrf();
        $id     = (int) ($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($id > 0) {
            $action === 'revoke' ? LicenseModel::revoke($id) : LicenseModel::unrevoke($id);
        }
        self::redirect('/admin/dashboard');
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public static function delete(): void {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') self::redirect('/admin/dashboard');
        self::validateCsrf();
        $id     = (int) ($_POST['id'] ?? 0);
        $result = LicenseModel::delete($id, true);
        self::redirect('/admin/dashboard', ['msg' => $result['reason']]);
    }

    // ── Reset devices ─────────────────────────────────────────────────────────

    public static function resetDevices(): void {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') self::redirect('/admin/dashboard');
        self::validateCsrf();
        $id       = (int) ($_POST['id'] ?? 0);
        $returnTo = $_POST['return_to'] ?? 'dashboard';
        $result   = LicenseModel::resetDevices($id, true);
        if ($returnTo === 'view') {
            self::redirect("/admin/view", ['id' => $id, 'msg' => $result['reason']]);
        }
        self::redirect('/admin/dashboard', ['msg' => $result['reason']]);
    }

    // ── Apps management ───────────────────────────────────────────────────────

    public static function apps(): void {
        Auth::requireLogin();
        AppAliasModel::boot();
        $tab     = in_array($_GET['tab'] ?? '', ['apps', 'aliases']) ? $_GET['tab'] : 'apps';
        $editId  = (int) ($_GET['edit'] ?? 0);
        $msg     = (string) ($_GET['msg'] ?? '');
        $errText = urldecode((string) ($_GET['err'] ?? ''));
        $apps    = AppModel::all(true);
        $aliases = AppAliasModel::all();
        $editApp = $editId > 0 ? AppModel::find($editId) : null;
        $appIds  = array_column($apps, 'app_id');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();
            $action = (string) ($_POST['action'] ?? '');
            try {
                match ($action) {
                    'create_app' => AppModel::create(
                        $_POST['app_id']           ?? '',
                        $_POST['app_name']          ?? '',
                        $_POST['verify_mode']       ?? 'standard',
                        (int) ($_POST['default_max_devices'] ?? 2),
                        (int) ($_POST['default_years']       ?? 1),
                        isset($_POST['is_active'])      ? 1 : 0,
                        isset($_POST['device_tracking']) ? 1 : 0
                    ),
                    'update_app' => AppModel::update(
                        (int) ($_POST['id'] ?? 0),
                        $_POST['app_name']    ?? '',
                        $_POST['verify_mode'] ?? 'standard',
                        (int) ($_POST['default_max_devices'] ?? 2),
                        (int) ($_POST['default_years']       ?? 1),
                        isset($_POST['is_active'])      ? 1 : 0,
                        isset($_POST['device_tracking']) ? 1 : 0
                    ),
                    'toggle_app'   => AppModel::setActive((int) ($_POST['id'] ?? 0), (int) ($_POST['to'] ?? 0)),
                    'create_alias' => AppAliasModel::create($_POST['alias'] ?? '', $_POST['alias_app_id'] ?? '', $_POST['alias_note'] ?? ''),
                    'delete_alias' => AppAliasModel::delete((int) ($_POST['alias_id'] ?? 0)),
                    default        => null,
                };
                $redirectMsg = match ($action) {
                    'create_app'   => 'created',
                    'update_app'   => 'updated',
                    'toggle_app'   => 'toggled',
                    'create_alias' => 'alias_created',
                    'delete_alias' => 'alias_deleted',
                    default        => 'invalid_action',
                };
                $tab2 = str_contains($action, 'alias') ? 'aliases' : 'apps';
                $extra = $action === 'update_app' ? ['edit' => (int) ($_POST['id'] ?? 0)] : [];
                self::redirect('/admin/apps', array_merge(['msg' => $redirectMsg, 'tab' => $tab2], $extra));
            } catch (\PDOException $e) {
                $code = (string) $e->getCode() === '23000' ? 'duplicate_app_id' : 'db_error';
                self::redirect('/admin/apps', ['msg' => $code, 'tab' => $tab]);
            } catch (\Exception $e) {
                self::redirect('/admin/apps', ['msg' => 'error', 'err' => urlencode($e->getMessage()), 'tab' => $tab]);
            }
        }

        self::render('admin/apps', compact('tab', 'msg', 'errText', 'apps', 'aliases', 'editApp', 'appIds'));
    }

    // ── Report ────────────────────────────────────────────────────────────────

    public static function report(): void {
        Auth::requireLogin();
        $overview      = ReportService::overview();
        $byAgency      = ReportService::byAgency();
        $byApp         = ReportService::byApp();
        $activations   = ReportService::activationsByDay(30);
        $apiCalls      = ReportService::apiCallsByDay(30);
        $topLicenses   = ReportService::topLicensesByRequests(20);
        $expiring      = ReportService::expiringLicenses(30);
        $topErrors     = ReportService::topErrors(7);
        $devicesByApp  = ReportService::devicesByApp();

        self::render('admin/report', compact(
            'overview', 'byAgency', 'byApp', 'activations', 'apiCalls',
            'topLicenses', 'expiring', 'topErrors', 'devicesByApp'
        ));
    }

    // ── Agencies ──────────────────────────────────────────────────────────────

    public static function agencies(): void {
        Auth::requireLogin();
        AgencyModel::boot();
        $msg      = (string) ($_GET['msg'] ?? '');
        $editId   = (int)    ($_GET['edit'] ?? 0);
        $agencies = AgencyModel::all(true);
        $editItem = $editId > 0 ? AgencyModel::find($editId) : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();
            $action = (string) ($_POST['action'] ?? '');
            try {
                match ($action) {
                    'create' => AgencyModel::create($_POST['code'] ?? '', $_POST['name'] ?? '', isset($_POST['is_active']) ? 1 : 0),
                    'update' => AgencyModel::update((int) ($_POST['id'] ?? 0), $_POST['code'] ?? '', $_POST['name'] ?? '', isset($_POST['is_active']) ? 1 : 0),
                    'toggle' => AgencyModel::setActive((int) ($_POST['id'] ?? 0), (int) ($_POST['to'] ?? 0)),
                    default  => null,
                };
            } catch (\Exception $e) {
                self::redirect('/admin/agencies', ['msg' => 'error']);
            }
            self::redirect('/admin/agencies', ['msg' => 'saved']);
        }

        self::render('admin/agencies', compact('msg', 'agencies', 'editItem'));
    }

    // ── Customer reset device (public) ───────────────────────────────────────

    public static function customerResetDevice(): void {
        $msg = (string) ($_GET['msg'] ?? '');
        $form = [
            'license_key' => '',
            'email'       => '',
            'no_email'    => 0,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $licenseKey = strtoupper(trim((string) ($_POST['license_key'] ?? '')));
            $email      = strtolower(trim((string) ($_POST['email'] ?? '')));
            $noEmail    = isset($_POST['no_email']) ? 1 : 0;

            if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
                self::redirect('/reset-device', ['msg' => 'csrf_error']);
            }

            $ip = SecurityService::clientIp();
            $limit = $noEmail ? 3 : 5;
            [$allowed] = SecurityService::rateLimitAllow('customer-reset-device|' . $ip, $limit, 900);
            if (!$allowed) {
                self::redirect('/reset-device', ['msg' => 'rate_limited']);
            }

            $form['license_key'] = $licenseKey;
            $form['email']       = $email;
            $form['no_email']    = $noEmail;

            if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $licenseKey)) {
                self::redirect('/reset-device', ['msg' => 'invalid']);
            }

            $license = LicenseModel::findByKey($licenseKey);
            if (!$license) {
                self::redirect('/reset-device', ['msg' => 'invalid']);
            }

            $licenseEmail = strtolower(trim((string) ($license['email'] ?? '')));
            if ($noEmail) {
                if ($licenseEmail !== '') {
                    self::redirect('/reset-device', ['msg' => 'email_required']);
                }
                $email = '';
            } else {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    self::redirect('/reset-device', ['msg' => 'invalid']);
                }
                if ($licenseEmail !== $email) {
                    self::redirect('/reset-device', ['msg' => 'invalid']);
                }
            }

            $result = LicenseModel::resetDevicesForCustomer(
                (int) $license['id'],
                $email,
                $ip,
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                86400
            );
            if (($result['success'] ?? false) !== true) {
                if (($result['reason'] ?? '') === 'cooldown') {
                    self::redirect('/reset-device', ['msg' => 'cooldown']);
                }
                self::redirect('/reset-device', ['msg' => 'db_error']);
            }

            self::redirect('/reset-device', ['msg' => 'success']);
        }

        $csrfToken = Auth::csrfToken();
        require APP_ROOT . '/views/public/reset_device.php';
    }

    // ── Change password ───────────────────────────────────────────────────────

    public static function changePassword(): void {
        Auth::requireLogin();
        $error = $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::validateCsrf();
            $current  = $_POST['current_password']  ?? '';
            $new      = $_POST['new_password']       ?? '';
            $confirm  = $_POST['confirm_password']   ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                $error = 'All fields are required.';
            } elseif ($new !== $confirm) {
                $error = 'New passwords do not match.';
            } elseif (strlen($new) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $new)) {
                $error = 'Password must contain uppercase, lowercase, and a number.';
            } else {
                $check = Auth::login(ADMIN_USERNAME, $current);
                if (!$check['success']) {
                    $error = 'Current password is incorrect.';
                } else {
                    Auth::updatePassword($new);
                    $success = 'Password updated. You will be logged out.';
                    header('Refresh: 2; URL=/admin/logout');
                }
            }
        }

        self::render('admin/change_password', compact('error', 'success'));
    }

    // ── Export CSV ────────────────────────────────────────────────────────────

    public static function export(): void {
        Auth::requireLogin();
        $search  = trim((string) ($_GET['search'] ?? ''));
        $status  = in_array($_GET['status'] ?? '', ['active', 'expired', 'revoked']) ? $_GET['status'] : '';
        $licenses = LicenseModel::all($search, $status);
        $appOptions = AppModel::map(true);
        $agencyOptions = \Models\AgencyModel::map(true);

        // Device counts
        $devCounts = [];
        $rows = Database::getInstance()->getPdo()
            ->query('SELECT license_id, COUNT(*) AS cnt FROM devices GROUP BY license_id')
            ->fetchAll();
        foreach ($rows as $r) $devCounts[(int) $r['license_id']] = (int) $r['cnt'];

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="licenses_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-cache');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel UTF-8
        fputcsv($out, ['License Key', 'Customer', 'Email', 'Status', 'Allowed Apps',
                       'Đại lý', 'Admin Note', 'Expiry Date', 'Max Devices', 'Used Devices',
                       'Session Version', 'Created At']);

        $now = time();
        foreach ($licenses as $l) {
            $statusStr = $l['revoked'] ? 'Revoked' : (strtotime($l['expiry_date']) <= $now ? 'Expired' : 'Active');
            $appsList  = implode('+', array_map(fn($id) => $appOptions[$id] ?? $id, $l['allowed_apps_list'] ?? []));
            $agencyName = $agencyOptions[(int)($l['agency_id'] ?? 0)] ?? '-';
            fputcsv($out, [
                $l['license_key'], $l['customer_name'], $l['email'], $statusStr,
                $appsList, $agencyName, $l['admin_note'] ?? '', $l['expiry_date'],
                $l['max_devices'], $devCounts[(int) $l['id']] ?? 0,
                $l['session_version'] ?? 1, $l['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Login page ────────────────────────────────────────────────────────────

    public static function loginPage(): void {
        if (Auth::isLoggedIn()) self::redirect('/admin/dashboard');
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $result = Auth::login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '');
            if ($result['success']) self::redirect('/admin/dashboard');
            $error = $result['message'];
        }
        // Render standalone login (no main layout)
        $csrfToken = Auth::csrfToken();
        require APP_ROOT . '/views/admin/login.php';
    }

    public static function logout(): void {
        Auth::logout();
        self::redirect('/admin/login');
    }

    public static function syncTidb(): void {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect('/admin/dashboard');
        }
        if (!Auth::validateCsrf($_POST['csrf_token'] ?? '')) {
            self::redirect('/admin/dashboard', ['msg' => 'csrf_error']);
        }
        $result = \Services\SyncService::syncAll();
        if ($result['success'] ?? false) {
            $count = (int) ($result['count'] ?? 0);
            self::redirect('/admin/dashboard', ['msg' => 'sync_success', 'synced' => $count]);
        } else {
            self::redirect('/admin/dashboard', ['msg' => 'sync_failed', 'sync_err' => $result['error'] ?? 'Lỗi không xác định']);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function validateLicenseForm(array $form): string {
        if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $form['license_key'])) {
            return 'License key must be in format XXXX-XXXX-XXXX-XXXX';
        }
        if ($form['customer_name'] === '') return 'Customer name is required.';
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) return 'Invalid email format.';
        if ($form['validity'] < 1 || $form['validity'] > 3650) return 'Validity must be 1–3650 days.';
        if ($form['max_devices'] < 1) return 'Max devices must be >= 1.';
        if (empty($form['allowed_apps'])) return 'Select at least one app.';
        return '';
    }
}
