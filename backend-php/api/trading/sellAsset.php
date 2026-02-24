<?php
declare(strict_types=1);

require_once __DIR__ . '/../../boot.php';
require_once __DIR__ . '/../../db/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// Start session safely
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

// IMPORTANT: must match what login.php sets
if (empty($_SESSION['user_id'])) {
    json_out(['success' => false, 'message' => 'Unauthorized'], 401);
}

$user_id = (int)$_SESSION['user_id'];

// Inputs (form-urlencoded)
$symbol    = strtoupper(trim((string)($_POST['symbol'] ?? $_POST['symbols'] ?? '')));
$quantity  = (int)($_POST['quantity'] ?? 0);
$orderType = strtolower(trim((string)($_POST['order_type'] ?? 'market'))); // market/limit
$priceIn   = isset($_POST['price']) ? (float)$_POST['price'] : 0.0;
$useMarket = (string)($_POST['market'] ?? '') === '1' || $orderType === 'market';

if ($symbol === '' || $quantity <= 0) {
    json_out(['success' => false, 'message' => 'Invalid symbol or quantity'], 400);
}

try {
    $pdo = db();

    // 1) Resolve price
    $price = $priceIn;

    if ($price <= 0) {
        if ($useMarket) {
            // latest cached price from market_data
            $pStmt = $pdo->prepare("SELECT price FROM market_data WHERE symbol = ? ORDER BY `timestamp` DESC LIMIT 1");
            $pStmt->execute([$symbol]);
            $p = $pStmt->fetchColumn();
            $price = $p ? (float)$p : 0.0;
        } else {
            json_out(['success' => false, 'message' => 'Price required for limit orders'], 400);
        }
    }

    if ($price <= 0) {
        json_out(['success' => false, 'message' => 'Unable to resolve market price for this symbol'], 400);
    }

    $proceeds = $quantity * $price;

    // 2) Check holding (holdings table uses user_id + symbol)
    $hStmt = $pdo->prepare("SELECT holding_id, quantity, average_price
                            FROM holdings
                            WHERE user_id = ? AND symbol = ?
                            LIMIT 1");
    $hStmt->execute([$user_id, $symbol]);
    $holding = $hStmt->fetch(PDO::FETCH_ASSOC);

    if (!$holding) {
        json_out(['success' => false, 'message' => 'No holdings found for this symbol'], 400);
    }

    $heldQty = (int)$holding['quantity'];
    if ($heldQty < $quantity) {
        json_out(['success' => false, 'message' => 'Not enough holdings to sell'], 400);
    }

    // 3) Update holdings + portfolio balance + insert transaction (atomic)
    $pdo->beginTransaction();

    // update holdings quantity / delete if 0
    $newQty = $heldQty - $quantity;

    if ($newQty <= 0) {
        $delH = $pdo->prepare("DELETE FROM holdings WHERE holding_id = ?");
        $delH->execute([(int)$holding['holding_id']]);
    } else {
        $updH = $pdo->prepare("UPDATE holdings SET quantity = ? WHERE holding_id = ?");
        $updH->execute([$newQty, (int)$holding['holding_id']]);
    }

    // Add proceeds to portfolio total_balance (table name = portfolio)
    $updPf = $pdo->prepare("UPDATE portfolio
                            SET total_balance = total_balance + ?
                            WHERE user_id = ?");
    $updPf->execute([$proceeds, $userId]);

    // Insert transaction (transactions table)
    $insT = $pdo->prepare("INSERT INTO transactions (user_id, symbol, trade_type, quantity, price)
                        VALUES (?, ?, 'SELL', ?, ?)");
    $insT->execute([$userId, $symbol, $quantity, $price]);

    $pdo->commit();

    // return updated balance + holding
    $pfStmt = $pdo->prepare("SELECT portfolio_id, total_balance, total_invested, total_profit_loss
                            FROM portfolio
                            WHERE user_id = ?
                            ORDER BY portfolio_id ASC
                            LIMIT 1");
    $pfStmt->execute([$userId]);
    $pfNow = $pfStmt->fetch(PDO::FETCH_ASSOC);

    json_out([
        'success' => true,
        'message' => 'Sell executed',
        'order' => [
            'symbol'     => $symbol,
            'trade_type' => 'SELL',
            'order_type' => $useMarket ? 'market' : 'limit',
            'quantity'   => $quantity,
            'price'      => round($price, 6),
            'proceeds'   => round($proceeds, 2),
        ],
        'portfolio' => [
            'portfolio_id'      => $pfNow ? (int)$pfNow['portfolio_id'] : null,
            'total_balance'     => $pfNow ? round((float)$pfNow['total_balance'], 2) : null,
            'total_invested'    => $pfNow ? round((float)$pfNow['total_invested'], 2) : null,
            'total_profit_loss' => $pfNow ? round((float)$pfNow['total_profit_loss'], 2) : null,
        ],
        'holding' => [
            'symbol'        => $symbol,
            'quantity_left' => max(0, $newQty),
        ],
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}
