<?php
declare(strict_types=1);

/**
 * File: backend-php/api/prices.php
 * GET /venture-magnate/backend-php/api/prices.php?symbols=AAPL,BTCUSDT,EURUSD
 * Returns: [{symbol, price, source, ts}]
 */

require_once __DIR__ . '/../boot.php';

header('Content-Type: application/json; charset=utf-8');

function out_json($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    out_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

/* -------------------------------------------------------
cURL helper
------------------------------------------------------- */
function curlGetJson(string $url, ?string &$err = null, ?int &$http = null): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'VentureMagnate/1.0',
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300) return null;

    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/* -------------------------------------------------------
Optional DB cache (market_data: data_id, symbol, price, timestamp)
------------------------------------------------------- */
function cache_price(string $symbol, float $price): void {
    if ($price <= 0) return;

    // Only cache if db() exists and works
    if (!function_exists('db')) return;

    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO market_data (symbol, price) VALUES (?, ?)");
        $stmt->execute([$symbol, $price]);
    } catch (Throwable $e) {
        // ignore caching errors (prices endpoint should still work)
    }
}

/* -------------------------------------------------------
STOCK price: Finnhub -> AlphaVantage
------------------------------------------------------- */
function price_stock(string $sym, array &$why): float {
    if (defined('FINNHUB_API_KEY') && FINNHUB_API_KEY) {
        $e=''; $h=0;
        $j = curlGetJson(
            'https://finnhub.io/api/v1/quote?symbol=' . urlencode($sym) .
            '&token=' . urlencode((string)FINNHUB_API_KEY),
            $e, $h
        );
        if ($j && isset($j['c']) && is_numeric($j['c'])) return (float)$j['c'];
        $why[] = "Finnhub fail (http=$h err=$e)";
    }

    if (defined('ALPHAVANTAGE_API_KEY') && ALPHAVANTAGE_API_KEY) {
        $e=''; $h=0;
        $j = curlGetJson(
            'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=' .
            urlencode($sym) . '&apikey=' . urlencode((string)ALPHAVANTAGE_API_KEY),
            $e, $h
        );
        if (isset($j['Note'])) $why[] = 'Alpha rate-limited';
        $p = $j['Global Quote']['05. price'] ?? null;
        if ($p && is_numeric($p)) return (float)$p;
        $why[] = "Alpha fail (http=$h err=$e)";
    }

    return 0.0;
}

/* -------------------------------------------------------
CRYPTO price: Binance -> CoinGecko -> Finnhub candle
------------------------------------------------------- */
function price_crypto(string $symbol, array &$why): float {
    // 1) Binance
    $e=''; $h=0;
    $j = curlGetJson("https://api.binance.com/api/v3/ticker/price?symbol=" . urlencode($symbol), $e, $h);
    if ($j && isset($j['price']) && is_numeric($j['price'])) return (float)$j['price'];
    $why[] = "Binance fail (http=$h err=$e)";

    // 2) CoinGecko mapping
    $map = [
        'BTCUSDT' => 'bitcoin',
        'ETHUSDT' => 'ethereum',
        'SOLUSDT' => 'solana',
        'BNBUSDT' => 'binancecoin',
        'ADAUSDT' => 'cardano',
        'XRPUSDT' => 'ripple'
    ];

    if (isset($map[$symbol])) {
        $id = $map[$symbol];
        $e=''; $h=0;
        $j = curlGetJson("https://api.coingecko.com/api/v3/simple/price?ids=$id&vs_currencies=usd", $e, $h);
        if ($j && isset($j[$id]['usd']) && is_numeric($j[$id]['usd'])) return (float)$j[$id]['usd'];
        $why[] = "CoinGecko fail (http=$h err=$e)";
    } else {
        $why[] = "No CoinGecko mapping for $symbol";
    }

    // 3) Finnhub candle (optional)
    if (defined('FINNHUB_API_KEY') && FINNHUB_API_KEY) {
        $sym = "BINANCE:$symbol";
        $to = time();
        $from = $to - 5 * 60;

        $e=''; $h=0;
        $url = 'https://finnhub.io/api/v1/crypto/candle?symbol=' . urlencode($sym) .
            "&resolution=1&from=$from&to=$to&token=" . urlencode((string)FINNHUB_API_KEY);

        $j = curlGetJson($url, $e, $h);
        if ($j && ($j['s'] ?? '') === 'ok' && !empty($j['c'])) {
            return (float) end($j['c']);
        }
        $why[] = "Finnhub candle fail (http=$h err=$e)";
    }

    return 0.0;
}

/* -------------------------------------------------------
FOREX price: Frankfurter -> Finnhub forex
------------------------------------------------------- */
function price_fx(string $pair, array &$why): float {
    $pair = strtoupper($pair);
    if (!preg_match('/^[A-Z]{6}$/', $pair)) {
        $why[] = 'Bad FX pair';
        return 0.0;
    }

    $from = substr($pair, 0, 3);
    $to   = substr($pair, 3, 3);

    // 1) Frankfurter
    $e=''; $h=0;
    $j = curlGetJson("https://api.frankfurter.app/latest?from=$from&to=$to", $e, $h);
    if ($j && isset($j['rates'][$to]) && is_numeric($j['rates'][$to])) {
        return (float)$j['rates'][$to];
    }
    $why[] = "Frankfurter fail (http=$h err=$e)";

    // 2) Finnhub fallback
    if (defined('FINNHUB_API_KEY') && FINNHUB_API_KEY) {
        $e=''; $h=0;
        $j = curlGetJson(
            'https://finnhub.io/api/v1/forex/rates?base=' . urlencode($from) .
            '&token=' . urlencode((string)FINNHUB_API_KEY),
            $e, $h
        );
        if ($j && isset($j['quote'][$to]) && is_numeric($j['quote'][$to])) {
            return (float)$j['quote'][$to];
        }
        $why[] = "Finnhub forex fail (http=$h err=$e)";
    }

    return 0.0;
}

/* -------------------------------------------------------
Router
------------------------------------------------------- */
$symbols = [];
if (isset($_GET['symbols'])) {
    $symbols = array_values(array_filter(array_map('trim', explode(',', strtoupper((string)$_GET['symbols'])))));
} elseif (isset($_GET['symbol'])) {
    $symbols = [strtoupper(trim((string)$_GET['symbol']))];
}
if (!$symbols) $symbols = ['AAPL'];

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

$result = [];
foreach ($symbols as $sym) {
    $why = [];
    $price = 0.0;
    $source = '';

    if (preg_match('/USDT$/', $sym)) {
        $price  = price_crypto($sym, $why);
        $source = 'crypto';
    } elseif (preg_match('/^[A-Z]{6}$/', $sym)) {
        $price  = price_fx($sym, $why);
        $source = 'forex';
    } else {
        $price  = price_stock($sym, $why);
        $source = 'stock';
    }

    if ($price > 0) cache_price($sym, $price);

    $row = [
        'symbol' => $sym,
        'price'  => (float)$price,
        'source' => $source,
        'ts'     => time(),
    ];
    if ($debug) $row['_debug'] = $why;

    $result[] = $row;
}

out_json($result);
