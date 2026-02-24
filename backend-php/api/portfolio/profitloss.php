<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../../db/db_connect.php';

if(!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$live = isset($_GET['live']) && $_GET['live'] ===  1 ;
$start = isset($_GET['start']) ? trim($_GET['start']) : null; // YYYY-MM-DD
$end   = isset($_GET['end'])   ? trim($_GET['end'])   : null; // YYYY-MM-DD

try{

    $pdo = db();

    //Get user's first portfolio
    $stmt = $pdo->prepare("SELECT id FROM portfolios WHERE user_id = :user_id ORDER BY id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $portfolio = $stmt->fetch();
    
    if (!$portfolio) {
        http_response_code(404);
        echo json_encode(['error' => 'No portfolio found']);
        exit;
    }
    

    
    echo json_encode(['success' => true, 'data' => []]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

?>