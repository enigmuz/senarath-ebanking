<?php
/* ============================================================================
 * Senarath Bank - session / auth helpers
 * LAB ENVIRONMENT · synthetic data only
 * ----------------------------------------------------------------------------
 * INTENTIONAL WEAKNESS V4 - Broken 2FA (pre-OTP token disclosure).
 * start_session_for() mints a FULLY AUTHENTICATED session the moment the
 * password check passes, BEFORE any OTP is verified. current_customer()
 * accepts that session for every protected page/endpoint and never checks
 * whether the OTP step was completed. The "correct" design - a short-lived
 * pre-auth challenge token, with the real session minted only AFTER OTP
 * verification - is intentionally omitted. See api/login.php and includes/auth.php.
 * ==========================================================================*/
require_once __DIR__ . '/db.php';

function new_token(): string {
    return bin2hex(random_bytes(24));
}

/* Create an already-authenticated session (V4: authenticated BEFORE OTP). */
function start_session_for(array $customer): string {
    $token = new_token();
    $otp   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q("INSERT INTO sessions (token, customer_id, authenticated, otp_verified, otp_code, created_at, expires_at)
       VALUES (:t, :cid, 1, 0, :otp, GETDATE(), DATEADD(hour, 8, GETDATE()))",
       [':t' => $token, ':cid' => $customer['id'], ':otp' => $otp]);

    // "Send" the OTP. In the lab we also log it so the OTP page is walkable.
    error_log("[LAB-OTP] customer={$customer['username']} otp={$otp} (also accepts LAB_FIXED_OTP)");

    // HttpOnly cookie so browser navigation is already authenticated (V4).
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + 8 * 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

/* Read the session token from cookie OR Authorization: Bearer header. */
function session_token(): ?string {
    if (!empty($_COOKIE[SESSION_COOKIE])) return $_COOKIE[SESSION_COOKIE];
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));
    if (!empty($_GET['token'])) return $_GET['token'];   // lab convenience
    return null;
}

/*
 * Return the logged-in customer, or null.
 * V4: a session is accepted while authenticated=1 REGARDLESS of otp_verified.
 */
function current_customer(): ?array {
    $t = session_token();
    if (!$t) return null;
    $row = one(
        "SELECT c.*, s.otp_verified
           FROM sessions s
           JOIN customers c ON c.id = s.customer_id
          WHERE s.token = :t
            AND s.authenticated = 1          -- NOTE: otp_verified is NOT required (V4)
            AND s.expires_at > GETDATE()",
        [':t' => $t]
    );
    return $row ?: null;
}

function require_auth(): array {
    $c = current_customer();
    if (!$c) { header('Location: /index.php'); exit; }
    return $c;
}

function logout(): void {
    $t = session_token();
    if ($t) { try { q("DELETE FROM sessions WHERE token = :t", [':t' => $t]); } catch (Throwable $e) {} }
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Mask an account number for display: 1234567890 -> •••• •••• 7890 */
function mask_acct(string $acct): string {
    $last = substr($acct, -4);
    return '•••• •••• ' . $last;
}
