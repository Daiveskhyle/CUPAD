<?php
require_once __DIR__.'/../vendor/autoload.php';
use OTPHP\TOTP;

function generateSecret() { return bin2hex(random_bytes(10)); }

function getTOTP($secret) {
    return TOTP::create($secret, 30, 'sha1', 6);
}

function verifyCode($secret, $code) {
    return getTOTP($secret)->verify($code);
}

function QR($username, $secret) {
    $totp = getTOTP($secret);
    $totp->setLabel("CUPAD:$username");
    return $totp->getQrCodeUri();   // returns data:image/png;base64,...
}