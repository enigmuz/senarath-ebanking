<?php
/* ============================================================================
 * POST /api/verify-otp.php - OTP verification (LAB ENVIRONMENT)
 * ----------------------------------------------------------------------------
 * V4 context: this endpoint marks otp_verified = 1 on the session, but NOTHING
 * in the app requires otp_verified to be 1. The session was already fully
 * authenticated at /api/login.php, so this step is cosmetic. It is kept so the
 * flow looks complete during the demo.
 * ==========================================================================*/
require_once __DIR__ . '/../helpers.php';
header('Content-Type: application/json');

$in  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$otp = trim($in['otp'] ?? '');
$t   = session_token();

if (!$t) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No session']); exit; }

$sess = one("SELECT * FROM sessions WHERE token = :t", [':t' => $t]);
if (!$sess) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No session']); exit; }

$match = hash_equals($sess['otp_code'], $otp) || hash_equals(LAB_FIXED_OTP, $otp);

// Mark verified if it matches - but note the session is usable either way (V4).
if ($match) {
    q("UPDATE sessions SET otp_verified = 1 WHERE token = :t", [':t' => $t]);
}

echo json_encode([
    'ok'          => true,
    'otpMatched'  => $match,
    // The attacker/user already has a working session regardless of this:
    'note'        => 'Session is authenticated independent of OTP (lab V4).',
]);
