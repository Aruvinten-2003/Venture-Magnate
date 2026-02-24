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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    json_out(['success' => false, 'message' => 'Unauthorized'], 401);
}



// Start session safely (avoid "session already active" warnings)


// Read JSON (or form) inputs ONCE
$input = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
} else {
    $input = $_POST;
}

$symbol = strtoupper(trim((string)($input['symbol'] ?? '')));
$quantity = (float)($input['quantity'] ?? 0);

$order_type = strtolower(trim((string)($input['order_type'] ?? $input['orderType'] ?? 'market')));
$price = isset($input['price']) ? (float)$input['price'] : 0.0;

$useMarket = ((string)($input['market'] ?? '') === '1') || ($order_type === 'market');

if ($symbol === '' || $quantity <= 0) {
    json_out(['success' => false, 'message' => 'Invalid symbol or quantity'], 400);
}


try {
    $pdo = db();

    // 1) Get user's portfolio row (table name = portfolio)
    $pfStmt = $pdo->prepare("
        SELECT portfolio_id, total_balance
        FROM portfolio
        WHERE user_id = ?
        ORDER BY portfolio_id ASC
        LIMIT 1
    ");
    $pfStmt->execute([$user_id]);
    $portfolio = $pfStmt->fetch(PDO::FETCH_ASSOC);

    if (!$portfolio) {
        json_out(['success' => false, 'message' => 'Portfolio not found for this user'], 400);
    }

    $portfolio_id = (int)$portfolio['portfolio_id'];
    $total_balance = (float)$portfolio['total_balance'];

    // 2) Resolve price (market_data)
    if ($price <= 0) {
        if ($useMarket) {
            $pStmt = $pdo->prepare("SELECT price FROM market_data WHERE symbol = ? ORDER BY `timestamp` DESC LIMIT 1");
            $pStmt->execute([$symbol]);
            $p = $pStmt->fetchColumn();
            $price = $p !== false ? (float)$p : 0.0;
        } else {
            json_out(['success' => false, 'message' => 'Price required for limit orders'], 400);
        }
    }

    if ($price <= 0) {
        json_out(['success' => false, 'message' => 'Unable to resolve market price for this symbol'], 400);
    }

    $cost = $quantity * $price;

    // 3) Ensure enough cash (total_balance is CASH)
    if ($cost > $total_balance) {
        json_out(['success' => false, 'message' => 'Insufficient balance'], 400);
    }

    // 4) Transaction
    $pdo->beginTransaction();

    // holdings table uses user_id
    $hStmt = $pdo->prepare("
        SELECT holding_id, quantity, average_price
        FROM holdings
        WHERE user_id = ? AND symbol = ?
        LIMIT 1
    ");
    $hStmt->execute([$user_id, $symbol]);
    $holding = $hStmt->fetch(PDO::FETCH_ASSOC);

    if ($holding) {
        $oldQty = (float)$holding['quantity'];
        $oldAvg = (float)$holding['average_price'];

        $newQty = $oldQty + $quantity;
        $newAvg = ($newQty > 0)
            ? (($oldQty * $oldAvg) + ($quantity * $price)) / $newQty
            : 0.0;

        $updH = $pdo->prepare("UPDATE holdings SET quantity = ?, average_price = ? WHERE holding_id = ?");
        $updH->execute([$newQty, $newAvg, (int)$holding['holding_id']]);

        $holdingOut = [
            'holding_id'    => (int)$holding['holding_id'],
            'symbol'        => $symbol,
            'quantity'      => $newQty,
            'average_price' => round($newAvg, 6),
        ];
    } else {
        $insH = $pdo->prepare("INSERT INTO holdings (user_id, symbol, quantity, average_price) VALUES (?, ?, ?, ?)");
        $insH->execute([$user_id, $symbol, $quantity, $price]);

        $holdingOut = [
            'holding_id'    => (int)$pdo->lastInsertId(),
            'symbol'        => $symbol,
            'quantity'      => $quantity,
            'average_price' => round($price, 6),
        ];
    }

    // Deduct cash
    $updPf = $pdo->prepare("
        UPDATE portfolio
        SET total_balance = total_balance - ?
        WHERE portfolio_id = ? AND user_id = ?
    ");
    $updPf->execute([$cost, $portfolio_id, $user_id]);

    // Record transaction (your table uses user_id)
$insT = $pdo->prepare("
    INSERT INTO transactions (user_id, symbol, trade_type, quantity, price, total_value)
    VALUES (?, ?, 'BUY', ?, ?, ?)
");
$insT->execute([$user_id, $symbol, $quantity, $price, $cost]);


    $pdo->commit();

    // Fetch new balance
    $balStmt = $pdo->prepare("SELECT total_balance FROM portfolio WHERE portfolio_id = ? LIMIT 1");
    $balStmt->execute([$portfolio_id]);
    $newBal = (float)$balStmt->fetchColumn();

    json_out([
        'success' => true,
        'message' => 'Buy executed',
        'order' => [
            'symbol'     => $symbol,
            'trade_type' => 'BUY',
            'order_type' => $useMarket ? 'market' : 'limit',
            'quantity'   => $quantity,
            'price'      => round($price, 6),
            'cost'       => round($cost, 2),
        ],
        'portfolio' => [
            'portfolio_id'  => $portfolio_id,
            'total_balance' => round($newBal, 2),
        ],
        'holding' => $holdingOut
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}

