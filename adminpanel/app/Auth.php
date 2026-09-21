<?php
/**
 * Auth — session management, CSRF, login.
 */

declare(strict_types=1);

require_once __DIR__ . '/DbSessionHandler.php';

class Auth {

    // ── Session ───────────────────────────────────────────────────────────────

    public static function startSession(): void {
        if (session_status() !== PHP_SESSION_NONE) return;
        
        // Vercel Serverless Fix: Use Database Session Handler
        $db = Database::getInstance()->getPdo();
        session_set_save_handler(new DbSessionHandler($db), true);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function isLoggedIn(): bool {
        self::startSession();
        if (empty($_SESSION['admin_id'])) return false;
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > SESSION_TIMEOUT) {
            self::logout();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: /admin/login');
            exit;
        }
    }

    // ── Login / logout ────────────────────────────────────────────────────────

    public static function login(string $username, string $password): array {
        self::startSession();
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
        $lockUntil = (int) ($_SESSION['login_lock_until'] ?? 0);

        if ($lockUntil > time()) {
            return ['success' => false, 'message' => 'Too many attempts. Try again later.'];
        }

        $trimmedUser = trim($username);
        $authenticated = false;
        $authedUsername = '';

        // 1. Check in database `admins` table first
        try {
            $pdo = Database::getInstance()->getPdo();
            $stmt = $pdo->prepare('SELECT * FROM admins WHERE LOWER(username) = LOWER(?) AND is_active = 1 LIMIT 1');
            $stmt->execute([$trimmedUser]);
            $adminRow = $stmt->fetch();
            if ($adminRow && !empty($adminRow['password_hash'])) {
                if (password_verify($password, $adminRow['password_hash'])) {
                    $authenticated = true;
                    $authedUsername = $adminRow['username'];
                }
            }
        } catch (\Exception $e) {
            // Fallback to built-in accounts if DB not accessible
        }

        // 2. Check built-in root & admin accounts from .env / defaults
        if (!$authenticated) {
            $builtInAccounts = [
                'admin' => self::passwordHash(),
                'root'  => _cfg_env('ROOT_PASSWORD_HASH', '$argon2id$v=19$m=65536,t=4,p=1$fDdSD2P3Rlw8F/r40VxKIg$IOxENdRUwf3BiYQWUfLcj50XSS90Z/kUsFniq/kHPDI'),
            ];

            $lowerInput = strtolower($trimmedUser);
            if (array_key_exists($lowerInput, $builtInAccounts)) {
                if (password_verify($password, $builtInAccounts[$lowerInput])) {
                    $authenticated = true;
                    $authedUsername = ($lowerInput === 'root') ? 'Root' : ADMIN_USERNAME;
                }
            }
        }

        if (!$authenticated) {
            $attempts++;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $_SESSION['login_lock_until'] = time() + LOGIN_COOLDOWN;
                $_SESSION['login_attempts']   = 0;
            }
            return ['success' => false, 'message' => 'Invalid credentials.'];
        }

        session_regenerate_id(true);
        $_SESSION['admin_id']       = 1;
        $_SESSION['admin_username'] = $authedUsername;
        $_SESSION['last_activity']  = time();
        $_SESSION['login_attempts'] = 0;
        return ['success' => true, 'message' => ''];
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ── Password ──────────────────────────────────────────────────────────────

    public static function updatePassword(string $newPassword): void {
        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $file = APP_ROOT . '/storage/admin_password.php';
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0750, true);
        file_put_contents($file, "<?php\nreturn " . var_export($hash, true) . ";\n", LOCK_EX);
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    public static function csrfToken(): string {
        self::startSession();
        $generated = (int) ($_SESSION['csrf_generated_at'] ?? 0);
        if (empty($_SESSION['csrf_token']) || (time() - $generated) > CSRF_TOKEN_TTL) {
            $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
            $_SESSION['csrf_generated_at'] = time();
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(string $token): bool {
        self::startSession();
        $stored = $_SESSION['csrf_token'] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }

    public static function currentAdmin(): string {
        return (string) ($_SESSION['admin_username'] ?? '');
    }

    // ── Layer-1 gate (cổng bảo mật trước khi vào admin login) ────────────────

    /**
     * Enforce a hardcoded Layer-1 gate.
     * Credentials loaded from env: LAYER1_USER / LAYER1_PASS.
     * Falls back to built-in defaults if not set in .env.
     * Blocks IP for 12 h after 3 failed attempts.
     */
    public static function enforceLayer1(): void {
        self::startSession();
        if (!empty($_SESSION['admin_layer1_ok'])) return;

        $l1User = _cfg_env('LAYER1_USER', 'Admin@2026');
        $l1Pass = _cfg_env('LAYER1_PASS', 'Khong!biet2026');
        $error  = '';
        $ip     = \Services\SecurityService::clientIp();

        // Check if IP is blocked
        if (\Services\SecurityService::isIpBlocked($ip)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden';
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_layer1_submit'])) {
            $failBucket = 'admin-layer1-fail|' . $ip;

            if (!self::validateCsrf($_POST['csrf_token'] ?? '')) {
                $error = 'Invalid CSRF token. Please refresh and try again.';
            } else {
                $u = (string) ($_POST['layer1_username'] ?? '');
                $p = (string) ($_POST['layer1_password'] ?? '');

                if (hash_equals($l1User, $u) && hash_equals($l1Pass, $p)) {
                    $_SESSION['admin_layer1_ok'] = 1;
                    \Services\SecurityService::rateLimitReset($failBucket);
                    $target = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard';
                    header("Location: {$target}");
                    exit;
                }

                [$failAllowed] = \Services\SecurityService::rateLimitAllow($failBucket, 2, 43200);
                if (!$failAllowed) {
                    \Services\SecurityService::blockIp($ip, 43200);
                    http_response_code(403);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'Forbidden';
                    exit;
                }
                $error = 'Layer-1 credentials are invalid.';
            }
        }

        // Render gate form (standalone page — no layout)
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        $csrf = htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8');
        $errHtml = $error !== '' ? '<div class="err">' . htmlspecialchars($error, ENT_QUOTES) . '</div>' : '';
        echo <<<HTML
        <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1.0">
        <title>Security Gate</title>
        <link rel="stylesheet" href="/public/css/app.css">
        <style>
          body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f172a}
          .gate-box{background:#111827;padding:32px;border-radius:12px;width:100%;max-width:400px;box-shadow:0 20px 40px rgba(0,0,0,.5)}
          h1{margin:0 0 6px;font-size:20px;color:#f1f5f9}
          .sub{color:#94a3b8;font-size:13px;margin-bottom:20px}
          .err{background:#7f1d1d;color:#fecaca;padding:10px 12px;border-radius:6px;margin-bottom:14px;font-size:13px}
          label{display:block;font-size:13px;color:#94a3b8;margin-bottom:5px}
          input{width:100%;padding:10px;border-radius:6px;border:1px solid #334155;background:#0b1220;color:#e2e8f0;font-size:14px;box-sizing:border-box;margin-bottom:12px}
          button{width:100%;padding:11px;border:0;border-radius:6px;background:#2563eb;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
        </style></head>
        <body><div class="gate-box">
          <h1>Admin Security Gate</h1>
          <div class="sub">Layer 1 authentication required before admin login.</div>
          {$errHtml}
          <form method="POST">
            <input type="hidden" name="_layer1_submit" value="1">
            <input type="hidden" name="csrf_token" value="{$csrf}">
            <label>Username</label><input type="text" name="layer1_username" required autofocus autocomplete="off">
            <label>Password</label><input type="password" name="layer1_password" required autocomplete="off">
            <button type="submit">Continue</button>
          </form>
        </div></body></html>
        HTML;
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function passwordHash(): string {
        $file = APP_ROOT . '/storage/admin_password.php';
        if (is_file($file)) {
            $hash = @include $file;
            if (is_string($hash) && $hash !== '') return $hash;
        }
        return ADMIN_PASSWORD_HASH;
    }
}
