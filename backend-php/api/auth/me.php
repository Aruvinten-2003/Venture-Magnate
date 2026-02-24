<?php
declare(strict_types=1);

require_once __DIR__ . '/../../boot.php';
header('Content-Type: application/json; charset=utf-8');

// boot.php already session_start(), so DO NOT session_start() here

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

echo json_encode([
    'success'    => true,
    'session_id' => session_id(),
    'user_id'        => $user_id,
    'logged_in'  => isset($_SESSION['user_id']),
    'full_name'       => $_SESSION['full_name'] ?? null,
    'email'      => $_SESSION['email'] ?? null,

], JSON_PRETTY_PRINT);