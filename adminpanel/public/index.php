<?php
/**
 * Public entry point — tất cả request đi qua đây.
 * Web server phải rewrite mọi request về file này (xem .htaccess).
 */

declare(strict_types=1);

// Bootstrap
require_once dirname(__DIR__) . '/bootstrap/app.php';

// Load non-namespaced classes
require_once APP_ROOT . '/app/Auth.php';
require_once APP_ROOT . '/app/Router.php';

// Register routes
require_once APP_ROOT . '/routes.php';

// Dispatch
Router::dispatch();
