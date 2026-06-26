# PayTM Payment Gateway — PHP Integration Guide

> **Complete step-by-step tutorial with working code samples.**
> Tested on PHP 7.4+ / 8.x, cPanel, and Cloudflare.
> Based on reverse-engineering the official WordPress plugin and fixing undocumented API behaviors.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [API Domain — The Hidden Trap](#2-api-domain--the-hidden-trap)
3. [Checksum Algorithm](#3-checksum-algorithm)
4. [Backend: Create Transaction Token](#4-backend-create-transaction-token)
5. [Frontend: Open Payment Popup](#5-frontend-open-payment-popup)
6. [Callback: Verify Payment](#6-callback-verify-payment)
7. [Complete File Structure](#7-complete-file-structure)
8. [Common Errors & Solutions](#8-common-errors--solutions)
9. [Downloadable Sample Code](#9-downloadable-sample-code)

---

## 1. Prerequisites

### From PayTM Dashboard
1. Log into [PayTM Dashboard](https://dashboard.paytmpayments.com/next/apikeys)
2. Get your **Merchant ID (MID)**, **Merchant Key**, and **Website Name**
   - Staging: MID `YOUR_STAGING_MID`, Website `WEBSTAGING`
   - Production: MID `YOUR_PROD_MID`, Website `DEFAULT`

### Server Requirements
- PHP 7.4+ with `openssl` and `curl` extensions
- HTTPS domain (required by PayTM JS Checkout SDK)

---

## 2. API Domain — The Hidden Trap

**This is the #1 cause of "501 System Error".** PayTM has two sets of domains and the docs reference the wrong one for newer accounts.

| | ❌ Legacy (broken) | ✅ Current (working) |
|---|---|---|
| Production | `securegw.paytm.in` | `secure.paytmpayments.com` |
| Staging | `securegw-stage.paytm.in` | `securestage.paytmpayments.com` |

```php
// ✅ CORRECT
$prodBaseUrl = "https://secure.paytmpayments.com";
$stageBaseUrl = "https://securestage.paytmpayments.com";
```

---

## 3. Checksum Algorithm

PayTM uses **AES-128-CBC + SHA-256** for request signing. Here's the exact algorithm:

```
1. Generate 4 random alphanumeric characters (the "salt")
2. Compute: hashInput = bodyJSON + "|" + salt
3. Compute: sha256Hash = SHA-256(hashInput)
4. Concatenate: toEncrypt = sha256Hash + salt
5. Encrypt: signature = AES-128-CBC(toEncrypt, merchantKey, IV="@@@@&&&&####$$$$")
```

### `checksum.php` — Full Implementation

```php
<?php
define('PTM_CHECKSUM_IV', "@@@@&&&&####$$$$");

function ptm_generate_checksum(string $body, string $key): string {
    $salt = ptm_random_string(4);
    $hashInput = $body . '|' . $salt;
    $sha256Hash = hash('sha256', $hashInput);
    $toEncrypt = $sha256Hash . $salt;
    return ptm_checksum_encrypt($toEncrypt, $key);
}

function ptm_verify_checksum(string $body, string $checksum, string $key): bool {
    $decrypted = ptm_checksum_decrypt($checksum, $key);
    if ($decrypted === false || strlen($decrypted) < 4) return false;
    $salt = substr($decrypted, -4);
    $expectedHash = substr($decrypted, 0, -4);
    $localHash = hash('sha256', $body . '|' . $salt);
    return hash_equals($expectedHash, $localHash);
}

function ptm_checksum_encrypt(string $input, string $key): string {
    return openssl_encrypt($input, 'AES-128-CBC', html_entity_decode($key), 0, PTM_CHECKSUM_IV);
}

function ptm_checksum_decrypt(string $encrypted, string $key) {
    return openssl_decrypt($encrypted, 'AES-128-CBC', html_entity_decode($key), 0, PTM_CHECKSUM_IV);
}

function ptm_random_string(int $length = 4): string {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function ptm_http_post(string $url, string $jsonBody): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $res      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['http_code' => $httpCode, 'body' => $res, 'error' => $error ?: null];
}
```

> ⚠️ **Key points**: Use `html_entity_decode($key)` before passing to OpenSSL. Use `random_int()` not `rand()`. The IV is exactly 16 bytes: `@@@@&&&&####$$$$`.

---

## 4. Backend: Create Transaction Token

The `POST /theia/api/v1/initiateTransaction` endpoint returns a `txnToken` used by the frontend.

### Critical: Request Format

```json
{
  "head": {
    "signature": "<checksum of body JSON>"
  },
  "body": {
    "requestType": "Payment",
    "mid": "YOUR_MID",
    "websiteName": "WEBSTAGING",
    "orderId": "ORDER_123",
    "callbackUrl": "https://yoursite.com/callback.php",
    "txnAmount": {
      "value": "10.00",
      "currency": "INR"
    },
    "userInfo": {
      "custId": "CUST_001"
    }
  }
}
```

> ⚠️ **The `head` must contain ONLY `signature`.** Do NOT send `requestTimestamp`, `channelId`, `clientId`, or `version` — they cause 501 errors.

> ⚠️ **Use `json_encode($data, JSON_UNESCAPED_SLASHES)` only.** Do NOT add `JSON_UNESCAPED_UNICODE` — it breaks checksum verification.

### `create-order.php` — Full Implementation

```php
<?php
header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("X-Accel-Buffering: no");

// Prevent browser timeout during PayTM API call
if (ob_get_level()) { ob_end_flush(); }
header('Connection: close');
ignore_user_abort(true);
set_time_limit(30);

require_once __DIR__ . '/checksum.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ═══════════════════════════════════════════════════
// CONFIGURATION — replace with your credentials
// ═══════════════════════════════════════════════════
$ptmMid  = "YOUR_MID_HERE";
$ptmKey  = "YOUR_MERCHANT_KEY_HERE";
$ptmWeb  = "WEBSTAGING";          // "WEBSTAGING" for staging, "DEFAULT" for production
$ptmEnv  = "staging";             // "staging" or "production"
$baseUrl = ($ptmEnv === "staging")
    ? "https://securestage.paytmpayments.com"
    : "https://secure.paytmpayments.com";

// ═══════════════════════════════════════════════════
// Build order
// ═══════════════════════════════════════════════════
$amount      = 10.00;
$orderId     = "ORDER_" . time() . "_" . bin2hex(random_bytes(3));
$callbackUrl = "https://yoursite.com/callback.php";

// Build body EXACTLY like this
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

// Sign using JSON_UNESCAPED_SLASHES ONLY
$bodyJson = json_encode($requestBody["body"], JSON_UNESCAPED_SLASHES);
$checksum = ptm_generate_checksum($bodyJson, $ptmKey);
$requestBody["head"]["signature"] = $checksum;
$fullPayload = json_encode($requestBody, JSON_UNESCAPED_SLASHES);

// Flush before API call to prevent browser timeout
echo "\n";
if (ob_get_level()) { ob_flush(); }
flush();

// ═══════════════════════════════════════════════════
// Call PayTM API
// ═══════════════════════════════════════════════════
$url  = $baseUrl . "/theia/api/v1/initiateTransaction?mid=" . $ptmMid . "&orderId=" . $orderId;
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
]);
```

---

## 5. Frontend: Open Payment Popup

### `pay.php` — Payment Page

```php
<?php
$ptmMid  = "YOUR_MID_HERE";
$ptmEnv  = "staging";
$sdkUrl  = ($ptmEnv === "staging")
    ? "https://securestage.paytmpayments.com/merchantpgpui/checkoutjs/merchants/" . $ptmMid . ".js"
    : "https://secure.paytmpayments.com/merchantpgpui/checkoutjs/merchants/" . $ptmMid . ".js";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PayTM Payment</title>
    <style>
        body { font-family: sans-serif; max-width: 400px; margin: 50px auto; }
        button { padding: 12px 24px; background: #1a56db; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .status { margin: 10px 0; padding: 10px; border-radius: 6px; display: none; }
        .status.info { display: block; background: #e0e7ff; color: #1e40af; }
        .status.error { display: block; background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
    <h2>Pay ₹10.00</h2>
    <div id="status" class="status"></div>
    <button id="payBtn" onclick="pay()">Pay with PayTM</button>

    <script src="<?php echo htmlspecialchars($sdkUrl); ?>"></script>
    <script>
    async function pay() {
        const btn = document.getElementById('payBtn');
        const status = document.getElementById('status');
        btn.disabled = true;
        btn.textContent = 'Creating order...';
        status.className = 'status info';
        status.textContent = 'Contacting PayTM...';

        try {
            // Step 1: Get txn token from your server
            const resp = await fetch('create-order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await resp.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to create order');
            }

            // Step 2: Open PayTM popup
            status.textContent = 'Opening payment page...';

            window.Paytm.CheckoutJS.init({
                "flow": "DEFAULT",
                "data": {
                    "orderId": data.order_id,
                    "token": data.txn_token,
                    "tokenType": "TXN_TOKEN",
                    "amount": data.amount
                },
                "handler": {
                    "notifyMerchant": function(eventName, data) {
                        console.log('PayTM event:', eventName, data);
                    },
                    "transactionStatus": function(paymentStatus) {
                        console.log('Payment status:', paymentStatus);
                    }
                }
            }).then(function() {
                // ⚠️ .invoke() is REQUIRED — init alone won't open the popup
                window.Paytm.CheckoutJS.invoke();
                btn.textContent = 'Payment in progress...';
            }).catch(function(err) {
                throw new Error('CheckoutJS init failed: ' + JSON.stringify(err));
            });

        } catch (err) {
            status.className = 'status error';
            status.textContent = 'Error: ' + err.message;
            btn.disabled = false;
            btn.textContent = 'Pay with PayTM';
        }
    }
    </script>
</body>
</html>
```

> ⚠️ **`.invoke()` is mandatory** — `.init()` alone silently does nothing. The popup only opens after calling `.invoke()`.

> ⚠️ **Load only ONE SDK** per page. Loading both staging and production SDKs causes conflicts.

---

## 6. Callback: Verify Payment

After payment, PayTM redirects to your `callbackUrl`. Verify the checksum and confirm via Transaction Status API.

### `callback.php` — Full Implementation

```php
<?php
require_once __DIR__ . '/checksum.php';

$ptmMid     = "YOUR_MID_HERE";
$ptmKey     = "YOUR_MERCHANT_KEY_HERE";
$statusUrl  = "https://secure.paytmpayments.com/merchant-status/api/v1/getPaymentStatus";

// PayTM redirects with query params after payment
$orderId  = $_GET['orderId']  ?? $_GET['ORDERID']  ?? '';
$txnId    = $_GET['txnId']    ?? $_GET['TXNID']    ?? '';
$status   = $_GET['status']   ?? $_GET['STATUS']   ?? '';
$checksum = $_GET['checksumhash'] ?? $_GET['CHECKSUMHASH'] ?? '';

if ($status !== 'TXN_SUCCESS') {
    die("Payment failed or pending.");
}

// Server-side verification via Transaction Status API
$body = ['mid' => $ptmMid, 'orderId' => $orderId];
$bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
$signature = ptm_generate_checksum($bodyJson, $ptmKey);

$request = [
    'head' => ['signature' => $signature],
    'body' => $body,
];
$signedJson = json_encode($request, JSON_UNESCAPED_SLASHES);
$resp = ptm_http_post($statusUrl, $signedJson);
$data = json_decode($resp['body'], true);

$txnStatus = $data['body']['resultInfo']['resultStatus'] ?? '';

if ($txnStatus === 'TXN_SUCCESS') {
    // ✅ Payment confirmed — grant credits/access here
    echo "Payment successful! Order: $orderId, Txn: $txnId";
} else {
    echo "Payment verification failed.";
}
```

> ⚠️ **Always double-verify** via the Transaction Status API. Don't trust the redirect parameters alone.

---

## 7. Complete File Structure

```
paytm-payment/
├── checksum.php       ← Checksum library (AES-128-CBC + SHA-256)
├── create-order.php   ← Creates txn token (backend endpoint)
├── pay.php            ← Payment page with CheckoutJS (frontend)
├── callback.php       ← Handles post-payment redirect (verification)
└── config.php         ← Your MID, key, website settings
```

---

## 8. Common Errors & Solutions

| Error | Cause | Fix |
|---|---|---|
| **501 System Error** | Wrong API domain | Use `paytmpayments.com`, not `paytm.in` |
| **501 System Error** | Extra head fields | Send ONLY `{"signature":"..."}` in head |
| **501 System Error** | Wrong JSON flags | Use `JSON_UNESCAPED_SLASHES` only |
| **Unexpected end of JSON input** | Browser timeout | Add `X-Accel-Buffering: no` + early flush |
| **Popup doesn't open** | Missing `.invoke()` | Call `.then(() => CheckoutJS.invoke())` |
| **Popup doesn't open** | SDK conflict | Load only ONE SDK per page |
| **2005 Checksum invalid** | Unicode encoding | Remove `JSON_UNESCAPED_UNICODE` |
| **Callback returns HTML** | PHP fatal error | Check `require_once` paths |

---

## 9. Downloadable Sample Code

The `sample/` folder contains complete, working code. Replace the placeholder values:

| File | Replace |
|---|---|
| `config.php` → `$ptmMid` | Your Merchant ID |
| `config.php` → `$ptmKey` | Your Merchant Key |
| `config.php` → `$ptmWeb` | `WEBSTAGING` or `DEFAULT` |
| `config.php` → `$callbackUrl` | Your callback URL |

### Quick Start

1. Copy all files to your server
2. Edit `config.php` with your credentials
3. Open `pay.php` in browser
4. Click "Pay with PayTM"
5. Complete payment on PayTM page
6. Verify callback fired at `callback.php`

---

## 10. Production Lessons Learned

These are real-world issues encountered deploying PayTM on a production site with Razorpay side-by-side. They apply anytime you integrate a payment gateway into a larger application.

### 10.1 JSON API Endpoints Must ALWAYS Return JSON

The `create-order.php` sample uses `ob_end_flush()` + `echo "\n"` to prevent browser timeouts during the PayTM API call. This works when the API call succeeds, but **breaks on error**:

```php
// ❌ DANGEROUS: if PayTM API fails, ob_clean() has no buffer to clean
if (ob_get_level()) { ob_end_flush(); }   // ends output buffering
echo "\n";                                // sends raw output
// ... PayTM API call fails ...
ob_clean();  // ❌ FAILS — buffer was ended by ob_end_flush()
http_response_code(502);  // ❌ FAILS — headers already sent after echo "\n"
```

**Result**: PHP warning HTML gets mixed into the JSON response → frontend crashes on `res.json()` with `"Unexpected token '<'"`.

**Fix**: Use `ob_start()` at the top instead of `ob_end_flush()`:

```php
// ✅ SAFE: ob_start() keeps the buffer alive for cleanup
ob_start();
// ... PayTM API call ...
if ($error) {
    ob_clean();  // ✅ Works — buffer is still active
    echo json_encode(["error" => "Payment initiation failed"]);
    exit;
}
ob_clean();
echo json_encode(["success" => true, ...]);
```

### 10.2 Never Use JSON_UNESCAPED_UNICODE for Checksums

PayTM's checksum is computed over the exact JSON bytes sent. `JSON_UNESCAPED_UNICODE` changes the byte encoding of non-ASCII characters, producing a different checksum than what PayTM expects:

```php
// ❌ Breaks checksum verification:
$bodyJson = json_encode($data, JSON_UNESCAPED_UNICODE);

// ✅ Correct:
$bodyJson = json_encode($data, JSON_UNESCAPED_SLASHES);
```

This applies to both `ptm_generate_checksum()` (sending) and `ptm_verify_checksum()` (callback verification). If one uses `JSON_UNESCAPED_UNICODE` and the other doesn't, verification silently fails.

### 10.3 Env Variable Names Must Match Exactly

When using a centralized environment config (like `env_config.php`), ensure the `.env` or server-level variable names match what your lookup functions expect:

```bash
# ❌ Won't be found if your config looks for T84_PROD_RZP_KEY_ID:
RZP_KEY_ID=rzp_live_xxx

# ✅ Correct:
T84_PROD_RZP_KEY_ID=rzp_live_xxx
```

A single missing prefix causes `getenv()` to return `null`, and if your code throws on empty values, the frontend gets an HTML fatal error instead of JSON.

### 10.4 Always Catch Exceptions in JSON Endpoints

Payment endpoints are called via `fetch()` and the frontend expects JSON. An uncaught exception produces an HTML error page:

```php
// ❌ If env var is missing, this throws RuntimeException → HTML error page
define('RZP_KEY_ID', t84_rzp_key_id());

// ✅ Always wrap in try/catch and return JSON:
try {
    define('RZP_KEY_ID', t84_rzp_key_id());
} catch (\RuntimeException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Payment configuration missing.']);
    exit;
}
```

### 10.5 Frontend: Handle Non-JSON Responses

Even with perfect backend code, network issues or Cloudflare error pages can return HTML. Always wrap `res.json()` in try/catch:

```javascript
let data;
try {
    data = await res.json();
} catch (e) {
    throw new Error('Payment service unavailable. Please try again.');
}
```

### 10.6 Head Must Contain ONLY signature

The `head` object in the request body must have exactly one field — `signature`. Adding `requestTimestamp`, `channelId`, `clientId`, or `version` causes a `501 System Error` with no clear error message pointing to the cause. The `ptm_build_txn_body()` helper in some SDK wrappers includes these fields by default — strip them.
