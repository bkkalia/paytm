<?php
/**
 * PayTM Payment Gateway — Configuration
 * 
 * Replace the placeholders below with your PayTM Dashboard credentials.
 * Get them at: https://dashboard.paytmpayments.com/next/apikeys
 */

// ═══════════════════════════════════════════════════
// YOUR CREDENTIALS — REPLACE THESE
// ═══════════════════════════════════════════════════
// ⚠️ If using a centralized env config (like env_config.php), make sure the
//    variable names match exactly — a missing prefix like T84_PROD_ will
//    cause getenv() to return null even though keys are in .env file.
$ptmMid     = "YOUR_MID_HERE";        // e.g. "jMvXdg33674602636638"
$ptmKey     = "YOUR_MERCHANT_KEY_HERE"; // e.g. "GS0@mxp0D5UhZTuz"
$ptmWeb     = "WEBSTAGING";           // "WEBSTAGING" for staging, "DEFAULT" for production
$ptmEnv     = "staging";              // "staging" or "production"

// ═══════════════════════════════════════════════════
// AUTO-CONFIGURED (no need to change)
// ═══════════════════════════════════════════════════
$callbackUrl = "https://yoursite.com/callback.php";  // ⚠️ CHANGE THIS

$baseUrl = ($ptmEnv === "staging")
    ? "https://securestage.paytmpayments.com"
    : "https://secure.paytmpayments.com";

$sdkUrl = ($ptmEnv === "staging")
    ? "https://securestage.paytmpayments.com/merchantpgpui/checkoutjs/merchants/" . rawurlencode($ptmMid) . ".js"
    : "https://secure.paytmpayments.com/merchantpgpui/checkoutjs/merchants/" . rawurlencode($ptmMid) . ".js";

$statusUrl = ($ptmEnv === "staging")
    ? "https://securestage.paytmpayments.com/merchant-status/api/v1/getPaymentStatus"
    : "https://secure.paytmpayments.com/merchant-status/api/v1/getPaymentStatus";
