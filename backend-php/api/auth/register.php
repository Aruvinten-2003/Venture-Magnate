<?php
declare(strict_types=1);

// From backend-php/api/trading -> go up 2 levels to backend-php
require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db/db_connect.php';
require_once __DIR__ . '/../../utils/auth.php';

header('Content-Type: application/json');


// Helpers
function wants_json(): bool {
    return (isset($_GET['json']) && ($_GET['json'] === '1' || $_GET['json'] === 'true'))
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /venture-magnate/frontend/js/Register.html');
    exit;
}

// Inputs
$name    = trim($_POST['full_name'] ?? '');
$email   = strtolower(trim($_POST['email'] ?? ''));
$pass    = $_POST['password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

// Validate
if ($name === '' || mb_strlen($name) > 120) {
    if (wants_json()) json_out(['success'=>false,'message'=>'Invalid full name'], 400);
    header('Location: /venture-magnate/frontend/js/Register.html?error=name');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if (wants_json()) json_out(['success'=>false,'message'=>'Invalid email'], 400);
    header('Location: /venture-magnate/frontend/js/Register.html?error=email');
    exit;
}

if (mb_strlen($pass) < 6 || $pass !== $confirm) {
    if (wants_json()) json_out(['success'=>false,'message'=>'Password must be ≥ 6 chars and match confirmation'], 400);
    header('Location: /venture-magnate/frontend/js/Register.html?error=password');
    exit;
}

$pdo = null;

try {
    $pdo = db(); // from boot.php (your helper)

    // 1) Check duplicate email (YOUR column is user_id, not id)
    $chk = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetch()) {
        if (wants_json()) json_out(['success'=>false,'message'=>'Email already registered'], 409);
        header('Location: /venture-magnate/frontend/js/Register.html?error=duplicate');
        exit;
    }

    // 2) Hash password and store into YOUR column `password`
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    // 3) Insert into YOUR table columns
    $ins = $pdo->prepare('INSERT INTO users (full_name, email, password) VALUES (?,?,?)');
    $ins->execute([$name, $email, $hash]);

    $user_id = (int)$pdo->lastInsertId();

$start = 10000.00;
$stmt = $pdo->prepare("INSERT INTO portfolio (user_id, total_balance) VALUES (?, ?)");
$stmt->execute([$user_id, $start]);

    $pdo->commit();

    // 4) IMPORTANT: set the SAME session key your auth expects (user_id)
    session_regenerate_id(true);
$_SESSION['user_id']   = (int)$u['user_id'];
$_SESSION['full_name'] = (string)$u['full_name'];
$_SESSION['email']     = (string)$u['email'];

    if (wants_json()) {
        json_out([
            'success' => true,
            'message' => 'Registered successfully',
            'user'    => ['user_id'=>$user_id, 'full_name'=>$name, 'email'=>$email]
        ]);
    }

    header('Location: /venture-magnate/frontend/js/Portfolio.html');
    exit;

} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();

    if (wants_json()) json_out(['success'=>false,'message'=>'Server error: '.$e->getMessage()], 500);
    header('Location: /venture-magnate/frontend/js/Register.html?error=server');
    exit;
}
