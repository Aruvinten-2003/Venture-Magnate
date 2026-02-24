<?php
declare(strict_types=1);

/**
 * backend-php/boot.php
 * Single bootstrap for ALL backend endpoints.
 */

define('APP_ROOT', __DIR__); // backend-php/

date_default_timezone_set('Asia/Kuala_Lumpur');

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ✅ Session cookie params BEFORE session_start()
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/venture-magnate/', // must match your project folder
        'secure' => false,             // true only if https
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Load config + db exactly once
require_once APP_ROOT . '/config.php';

