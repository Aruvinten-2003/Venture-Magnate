<?php
declare(strict_types=1);

require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../../db/db_connect.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
session_start();
}

header('Content-Type: application/json; charset=utf-8');



if(!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([['success' => false, 'message' => 'Unauthorized']]);
    exit;
}

$user_id= (int)$_SESSION['user_id'];

//query parameters

$portfolio_id = isset($_GET['portfolio_id']) ? (int)$_GET['portfolio_id'] : 0;
$symbol      = isset($_GET['symbol']) ? strtoupper(trim($_GET['symbol'])) : '';
$side        = isset($_GET['side']) ? strtoupper(trim($_GET['side'])) : '';          // BUY|SELL
$orderType   = isset($_GET['order_type']) ? ucfirst(strtolower(trim($_GET['order_type']))) : ''; // Market|Limit|Stop
$orderDir    = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

$start       = isset($_GET['start']) ? trim($_GET['start']) : null; // YYYY-MM-DD or YYYY-MM-DD HH:MM:SS
$end         = isset($_GET['end'])   ? trim($_GET['end'])   : null; // same

$page        = max(1, (int)($_GET['page'] ?? 1));
$limit       = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset      = ($page - 1) * $limit;


// build filters

$conds = ["portfolio_id = ?"];
$params = [$portfolioId];

if($symbol !== '') {
    $conds[] = "symbol = ?";
    $params[] = $symbol;
}

if($side === 'BUY' || $side === 'SELL') {
    $conds[] = "side = ?";
    $params[] = $side;
}

if($orderType === 'Market' || $orderType === 'Limit' || $orderType === 'Stop') {
    $conds[] = "order_type = ?";
    $params[] = $orderType;
}

if($start !== null) {
    $conds[] = "created_at >= ?";
    $params[] = $start;
}

if($end !== null) {
    $conds[] = "created_at <= ?";
    $params[] = $end;
}

$where = $conds ? ("WHERE" . implode("AND", $conds)): '';

try {
    //Total count for pagination

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM transactions $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

     // Fetch page
    $sql = "SELECT id, symbol, side, order_type, quantity, price, created_at
            FROM transactions
            $where
            ORDER BY created_at $orderDir, id $orderDir
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    // bind positional first
    foreach ($params as $i => $v) { $stmt->bindValue($i+1, $v); }
    // bind named for limit/offset as integers
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();



    $rows = [];
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $value = (float)$r['quantity'] * (float)$r['price'];
        $rows[] = [
            'user_id'         => (int)$r['id'],
            'symbol'     => $r['symbol'],
            'side'       => $r['side'],
            'order_type' => $r['order_type'],
            'quantity'   => (float)$r['quantity'],
            'price'      => (float)$r['price'],
            'value'      => round($value, 2),
            'created_at' => $r['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'meta' => [
            'portfolio_id' => $portfolio_id,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $limit,
            'has_more'     => ($offset + $limit) < $total
        ],
        'data' => $rows
    ]);

    } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}