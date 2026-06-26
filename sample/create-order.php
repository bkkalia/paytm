<?php
/**
 * PayTM — Create Transaction Token
 * 
 * POST endpoint that returns txnToken for the frontend.
 * Access: POST /create-order.php
 */

header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("X-Accel-Buffering: no");  // prevent Cloudflare/nginx buffering

// Start output buffer so ob_clean() works in ALL code paths (success AND error)
ob_start();

// Prevent browser timeout during PayTM API call
header('Connection: close');
ignore_user_abort(true);
set_time_limit(30);

require_once __DIR__ . '/checksum.php';
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ═══════════════════════════════════════════════════
// Create order
// ═══════════════════════════════════════════════════
$amount  = 10.00;
$orderId = "ORDER_" . time() . "_" . bin2hex(random_bytes(3));

$requestBody = [
    "body" => [
        "requestType" => "Payment",
        "mid"         => $ptmMid,
        "websiteName" => $ptmWeb,
        "orderId"     => $orderId,
        "callbackUrl" => $callbackUrl,
        "txnAmount"   => [
            "value"    => number_format($amount, 2, '.', ''),
            "currency" => "INR",
        ],
        "userInfo" => [
            "custId" => "CUST_001",
        ],
    ],
    "head" => [
        "signature" => "",
    ],
];

// Sign — JSON_UNESCAPED_SLASHES only, NO JSON_UNESCAPED_UNICODE
$bodyJson = json_encode($requestBody["body"], JSON_UNESCAPED_SLASHES);
$checksum = ptm_generate_checksum($bodyJson, $ptmKey);
$requestBody["head"]["signature"] = $checksum;
$fullPayload = json_encode($requestBody, JSON_UNESCAPED_SLASHES);

// ═══════════════════════════════════════════════════
// Call PayTM
// ═══════════════════════════════════════════════════
$url = $baseUrl . "/theia/api/v1/initiateTransaction?mid=" . $ptmMid . "&orderId=" . $orderId;
$resp = ptm_http_post($url, $fullPayload);
$data = $resp['body'] ? json_decode($resp['body'], true) : null;
$txnToken = $data['body']['txnToken'] ?? null;

if (!$txnToken) {
    http_response_code(502);
    ob_clean();
    echo json_encode([
        "error"       => "Payment initiation failed",
        "paytm_code"  => $data['body']['resultInfo']['resultCode'] ?? 'N/A',
        "paytm_msg"   => $data['body']['resultInfo']['resultMsg'] ?? 'N/A',
    ]);
    exit;
}

ob_clean();
echo json_encode([
    "success"   => true,
    "txn_token" => $txnToken,
    "order_id"  => $orderId,
    "amount"    => number_format($amount, 2, '.', ''),
    "mid"       => $ptmMid,
    "callback_url" => $callbackUrl,
]);
