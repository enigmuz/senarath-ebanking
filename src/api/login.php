<?php
/* ============================================================================
 * POST /api/login.php   - password step of the login flow
 * LAB ENVIRONMENT · synthetic data only
 * ----------------------------------------------------------------------------
 * V3 - a valid (synthetic) leaked credential authenticates here and reaches
 *      the OTP step.
 * V4 - BROKEN 2FA / pre-OTP token disclosure. The moment the password check
 *      passes, this endpoint mints a FULLY AUTHENTICATED session and returns a
 *      usable sessionToken + user data, BEFORE the OTP is validated. Every
 *      protected page/endpoint then accepts that token without ever checking
 *      the OTP (see includes/auth.php + helpers.php). The correct design - a
 *      short-lived pre-auth challenge token, real session minted only AFTER
 *      OTP verification - is intentionally omitted.
 *
 * Flawed response shape (from the build guide, page 21):
 *   POST /api/login -> 200
 *   { "otpSent": true, "sessionToken": "<valid authenticated token>",
 *     "user": { "id": 10293, "name": "...", "accountNo": "..." } }
 * ==========================================================================*/
require_once __DIR__ . '/../helpers.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Accept JSON body or form-encoded.
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$username = trim($in['username'] ?? '');
$password = (string)($in['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Username and password are required.']);
    exit;
}

// Look up by username OR customer number.
$cust = one(
    "SELECT * FROM customers WHERE (username = :u OR customer_no = :u) AND is_active = 1",
    [':u' => $username]
);

$hash = hash('sha256', $password);
if (!$cust || !hash_equals($cust['password_hash'], $hash)) {
    // Generic error (don't disclose which field was wrong).
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid username or password.']);
    exit;
}

// Password OK (V3). Mint an ALREADY-AUTHENTICATED session now (V4).
$token = start_session_for($cust);

$primary = one(
    "SELECT TOP 1 account_no FROM accounts WHERE customer_id = :cid ORDER BY id",
    [':cid' => $cust['id']]
);

// V4: return the usable token + user data BEFORE OTP verification.
echo json_encode([
    'ok'          => true,
    'otpSent'     => true,
    'sessionToken'=> $token,                       // <-- already authenticated
    'user'        => [
        'id'        => (int)$cust['customer_no'],
        'name'      => trim($cust['first_name'] . ' ' . $cust['last_name']),
        'accountNo' => $primary['account_no'] ?? null,
    ],
]);
