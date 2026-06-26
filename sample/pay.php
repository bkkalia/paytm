<?php
/**
 * PayTM — Payment Page
 * 
 * Frontend page with PayTM CheckoutJS popup.
 * Edit config.php with your credentials first.
 */

require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayTM Payment</title>
    <style>
        :root { --primary: #1a56db; --bg: #f8fafc; --card: #fff; --text: #1e293b; --muted: #6b7280; --border: #e5e7eb; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 32px; max-width: 400px; width: 100%; box-shadow: 0 2px 12px rgba(0,0,0,0.06); text-align: center; }
        .amount { font-size: 48px; font-weight: 800; color: var(--primary); margin-bottom: 8px; }
        .label { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
        button { width: 100%; padding: 14px; background: var(--primary); color: #fff; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        button:hover { background: #1e40af; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .status { margin-bottom: 16px; padding: 10px; border-radius: 8px; font-size: 14px; display: none; }
        .status.info { display: block; background: #e0e7ff; color: #1e40af; }
        .status.error { display: block; background: #fef2f2; color: #991b1b; }
        .status.success { display: block; background: #dcfce7; color: #166534; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; vertical-align: middle; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="card">
    <div class="amount">&#x20B9;10.00</div>
    <div class="label">Test payment — <?php echo htmlspecialchars($ptmEnv); ?> environment</div>
    <div id="status" class="status"></div>
    <button id="payBtn" onclick="pay()">Pay with PayTM</button>
</div>

<!-- PayTM JS Checkout SDK -->
<script src="<?php echo htmlspecialchars($sdkUrl, ENT_QUOTES); ?>"></script>

<script>
const STATUS = document.getElementById('status');
const PAY_BTN = document.getElementById('payBtn');

function showStatus(msg, type) {
    STATUS.textContent = msg;
    STATUS.className = 'status ' + type;
}

async function pay() {
    PAY_BTN.disabled = true;
    PAY_BTN.innerHTML = '<span class="spinner"></span> Creating order...';
    showStatus('Contacting PayTM...', 'info');

    try {
        // Step 1: Get txn_token from your server
        const resp = await fetch('create-order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const data = await resp.json();

        if (!data.success) {
            throw new Error(data.error || 'Failed to create order');
        }

        // Step 2: Open PayTM CheckoutJS popup
        showStatus('Opening payment page...', 'info');

        window.Paytm.CheckoutJS.init({
            "flow": "DEFAULT",
            "data": {
                "orderId": data.order_id,
                "token": data.txn_token,
                "tokenType": "TXN_TOKEN",
                "amount": data.amount
            },
            "handler": {
                "notifyMerchant": function(eventName, evtData) {
                    console.log('PayTM event [' + eventName + ']:', evtData);
                    if (eventName === 'SESSION_EXPIRED') {
                        showStatus('Session expired. Please reload and try again.', 'error');
                    }
                },
                "transactionStatus": function(paymentStatus) {
                    console.log('Payment status:', paymentStatus);
                }
            }
        }).then(function() {
            // ⚠️ .invoke() is REQUIRED — init alone won't open the popup
            window.Paytm.CheckoutJS.invoke();
            PAY_BTN.innerHTML = 'Payment in progress...';
        }).catch(function(err) {
            throw new Error('CheckoutJS error: ' + JSON.stringify(err));
        });

    } catch (err) {
        showStatus('Error: ' + err.message, 'error');
        PAY_BTN.disabled = false;
        PAY_BTN.innerHTML = 'Pay with PayTM';
    }
}
</script>
</body>
</html>
