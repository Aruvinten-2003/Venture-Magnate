<?php
declare(strict_types=1);

// From backend-php/api/trading -> go up 2 levels to backend-php
require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../db/db_connect.php';
require_once __DIR__ . '/../../utils/auth.php';

header('Content-Type: application/json');

echo json_encode(['ping' => 'OK', 'app_root' => APP_ROOT]);
