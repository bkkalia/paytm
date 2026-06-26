<?php
/**
 * PayTM — Payment Callback Handler
 * 
 * PayTM redirects here after payment (GET with query params).
 * Always double-verify via Transaction Status API.
 */

require_once __DIR__ . '/checksum.php';
require_once __DIR__ . '/config.php';

// ═══════════════════════════════════════════════════
// Extract payment data from PayTM redirect
// ═══════════════════════════════════════════════════
$orderId  = $_GET['orderId']  ?? $_GET['ORDERID']  ?? '';
$txnId    = $_GET['txnId']    ?? $_GET['TXNID']    ?? '';
$status   = $_GET['status']   ?? $_GET['STATUS']   ?? '';
$amount   = $_GET['txnAmount'] ?? $_GET['TXNAMOUNT'] ?? '0';
$respCode = $_GET['respCode'] ?? $_GET['RESPCODE']  ?? '';
$checksum = $_GET['checksumhash'] ?? $_GET['CHECKSUMHASH'] ?? '';

// Quick check
if ($status !== 'TXN_SUCCESS' || $respCode !== '01') {
    http_response_code(400);
    die("Payment was not successful. Status: $status");
}

if (!$orderId) {
    http_response_code(400);
    die("Missing order ID.");
}

// ═══════════════════════════════════════════════════
// Server-side verification via Transaction Status API
// ═══════════════════════════════════════════════════
$body = ['mid' => $ptmMid, 'orderId' => $orderId];
$bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
$signature = ptm_generate_checksum($bodyJson, $ptmKey);

$request = [
    'head' => ['signature' => $signature],
    'body' => $body,
];
$signedJson = json_encode($request, JSON_UNESCAPED_SLASHES);

$resp = ptm_http_post($statusUrl, $signedJson);

if ($resp['error'] || $resp['http_code'] < 200 || $resp['http_code'] >= 300) {
    http_response_code(502);
    die("Could not verify payment. Status API failed.");
}

$data = json_decode($resp['body'], true);
$txnStatus = $data['body']['resultInfo']['resultStatus'] ?? '';

if ($txnStatus === 'TXN_SUCCESS') {
    // ═══════════════════════════════════════════
    // ✅ PAYMENT CONFIRMED
    // Grant credits, update order, send email, etc.
    // ═══════════════════════════════════════════
    
    // Example: log to file
    $log = __DIR__ . '/payments.log';
    @file_put_contents($log, json_encode([
        'ts'        => date('Y-m-d H:i:s'),
        'order_id'  => $orderId,
        'txn_id'    => $txnId,
        'amount'    => $amount,
        'status'    => 'PAID',
    ]) . "\n", FILE_APPEND | LOCK_EX);

    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'OK',
        'message' => 'Payment verified successfully',
        'order_id' => $orderId,
        'txn_id'   => $txnId,
        'amount'   => $amount,
    ]);
} else {
    http_response_code(402);
    echo "Payment verification failed. Status: $txnStatus";
}
