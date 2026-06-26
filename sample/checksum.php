<?php
/**
 * PayTM Checksum Utility — AES-128-CBC + SHA-256
 * 
 * Matches the official PayTM PHP checksum algorithm byte-for-byte.
 * Do NOT modify this file — it is the exact algorithm PayTM expects.
 */

define('PTM_CHECKSUM_IV', "@@@@&&&&####$$$$");

/**
 * Generate PayTM checksum for a request body JSON string.
 */
function ptm_generate_checksum(string $body, string $key): string {
    $salt = ptm_random_string(4);
    $hashInput = $body . '|' . $salt;
    $sha256Hash = hash('sha256', $hashInput);
    $toEncrypt = $sha256Hash . $salt;
    return ptm_checksum_encrypt($toEncrypt, $key);
}

/**
 * Verify PayTM checksum from callback or response.
 */
function ptm_verify_checksum(string $body, string $checksum, string $key): bool {
    $decrypted = ptm_checksum_decrypt($checksum, $key);
    if ($decrypted === false || strlen($decrypted) < 4) {
        return false;
    }
    $salt = substr($decrypted, -4);
    $expectedHash = substr($decrypted, 0, -4);
    $localHash = hash('sha256', $body . '|' . $salt);
    return hash_equals($expectedHash, $localHash);
}

/**
 * AES-128-CBC encrypt.
 */
function ptm_checksum_encrypt(string $input, string $key): string {
    return openssl_encrypt($input, 'AES-128-CBC', html_entity_decode($key), 0, PTM_CHECKSUM_IV);
}

/**
 * AES-128-CBC decrypt.
 */
function ptm_checksum_decrypt(string $encrypted, string $key) {
    return openssl_decrypt($encrypted, 'AES-128-CBC', html_entity_decode($key), 0, PTM_CHECKSUM_IV);
}

/**
 * Generate random alphanumeric salt (matching PayTM SDK charset).
 */
function ptm_random_string(int $length = 4): string {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/**
 * HTTP POST helper — prefers cURL.
 */
function ptm_http_post(string $url, string $jsonBody): array {
    if (function_exists('curl_init')) {
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
    // Fallback to PHP streams
    $opts = ['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => $jsonBody, 'timeout' => 15, 'ignore_errors' => true]];
    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    return ['http_code' => $res !== false ? 200 : 0, 'body' => $res, 'error' => $res === false ? 'stream error' : null];
}
