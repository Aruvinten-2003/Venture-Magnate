<?php
declare(strict_types=1);

require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../../db/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$body = [];

if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];
} else {
    $body = $_POST;
}

$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    json_out(['success' => false, 'message' => 'Email and password are required'], 400);
}

try {
    $pdo = db();

    // Your DB columns: user_id, full_name, email, password
    $stmt = $pdo->prepare("SELECT user_id, full_name, email, password FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        json_out(['success' => false, 'message' => 'Invalid email or password'], 401);
    }

    $dbPass = (string)$u['password'];

    // Accept hashed or plain (plain is only for dev)
    $ok = password_verify($password, $dbPass);
    if (!$ok && hash_equals($dbPass, $password)) {
        $ok = true;
    }

    if (!$ok) {
        json_out(['success' => false, 'message' => 'Invalid email or password'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['user_id'];
    $_SESSION['full_name']    = (string)$u['full_name'];
    $_SESSION['email']   = (string)$u['email'];

    json_out([
        'success' => true,
        'message' => 'Logged in',
        'user' => [
            'user_id' => (int)$u['user_id'],
            'full_name' => (string)$u['full_name'],
            'email' => (string)$u['email'],
        ]
    ]);

} catch (Throwable $e) {
    json_out(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}
