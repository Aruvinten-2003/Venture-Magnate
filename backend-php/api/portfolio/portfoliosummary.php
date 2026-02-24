<?php
declare(strict_types=1);

require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../../db/db_connect.php';

// Validate user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// boot.php already starts session, so do NOT session_start() here

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    json_out(['success' => false, 'message' => 'Unauthorized'], 401);
}

try {
    $pdo = db();

  // 1) Portfolio
$pstmt = $pdo->prepare("
    SELECT portfolio_id, user_id, total_balance, total_invested, total_profit_loss, last_updated
    FROM portfolio
    WHERE user_id = ?
    ORDER BY portfolio_id ASC
    LIMIT 1
");
$pstmt->execute([$user_id]);
$portfolio = $pstmt->fetch(PDO::FETCH_ASSOC);

if (!$portfolio) {
    json_out(['success' => false, 'message' => 'Portfolio not found for this user'], 400);
}

$cash = (float)$portfolio['total_balance'];

  // 2) Holdings
$hstmt = $pdo->prepare("
    SELECT holding_id, symbol, quantity, average_price
    FROM holdings
    WHERE user_id = ?
    ORDER BY symbol ASC
");
$hstmt->execute([$user_id]);
$holdings = $hstmt->fetchAll(PDO::FETCH_ASSOC);

  // latest price
$priceStmt = $pdo->prepare("
    SELECT price
    FROM market_data
    WHERE symbol = ?
    ORDER BY `timestamp` DESC
    LIMIT 1
");

$positions = [];
  $equity = 0.0;              // holdings market value
$costBasisTotal = 0.0;
$unrealizedPLTotal = 0.0;

foreach ($holdings as $h) {
    $symbol = (string)$h['symbol'];
    $qty    = (float)$h['quantity'];
    $avg    = (float)$h['average_price'];

    $priceStmt->execute([$symbol]);
    $p = $priceStmt->fetchColumn();
    $lastPrice = ($p !== false) ? (float)$p : 0.0;

    if ($lastPrice <= 0) $lastPrice = $avg; // fallback

    $marketValue = $qty * $lastPrice;
    $costBasis   = $qty * $avg;
    $upl         = $marketValue - $costBasis;
    $uplPct      = ($costBasis > 0) ? ($upl / $costBasis) * 100.0 : 0.0;

    $equity += $marketValue;
    $costBasisTotal += $costBasis;
    $unrealizedPLTotal += $upl;

    $positions[] = [
        'holding_id'        => (int)$h['holding_id'],
        'symbol'            => $symbol,
        'quantity'          => $qty,
        'average_price'     => round($avg, 6),
        'current_price'     => round($lastPrice, 6),
        'market_value'      => round($marketValue, 2),
        'unrealized_pl'     => round($upl, 2),
        'unrealized_pl_pct' => round($uplPct, 2),
    ];
}

  // 3) Recent transactions (optional)
$tstmt = $pdo->prepare("
    SELECT transaction_id, symbol, trade_type, quantity, price, total_value, transaction_date
    FROM transactions
    WHERE user_id = ?
    ORDER BY transaction_date DESC
    LIMIT 20
");
$tstmt->execute([$user_id]);
$txns = $tstmt->fetchAll(PDO::FETCH_ASSOC);

$totalValue = $cash + $equity;

json_out([
    'success' => true,
    'users' => [
    'user_id' => $user_id,
    'name'    => $_SESSION['name'] ?? null,
    'email'   => $_SESSION['email'] ?? null
    ],
    'portfolio' => [
    'portfolio_id'      => (int)$portfolio['portfolio_id'],
      'total_balance'     => round($cash, 2),        // ✅ cash
      'equity'            => round($equity, 2),      // ✅ holdings value
      'total_value'       => round($totalValue, 2),  // ✅ cash + holdings
    'total_invested'    => round((float)$portfolio['total_invested'], 2),
    'total_profit_loss' => round((float)$portfolio['total_profit_loss'], 2),
    'last_updated'      => $portfolio['last_updated'],
    ],
    'holdings' => $positions,
    'recent_transactions' => $txns
]);

} catch (Throwable $e) {
json_out(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}
