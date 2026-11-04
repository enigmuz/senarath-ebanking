<?php
/* Senarath Online Banking — login page. LAB ENVIRONMENT.
 * If already authenticated (V4 session), go straight to the dashboard. */
require_once __DIR__ . '/helpers.php';
if (current_customer()) { header('Location: /dashboard.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — Senarath Online Banking</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="icon" href="/assets/img/logo.png">
</head>
<body>
<div class="lab-banner">LAB ENVIRONMENT — <b>Senarath Bank</b> online banking. Fictional institution, synthetic data. CrowdStrike demonstration only.</div>
<div class="auth-wrap">
  <div class="auth-brandside">
    <div class="z">
      <img src="/assets/img/logo.png" alt="Senarath Bank">
      <h1>Welcome back to<br>Senarath Online Banking</h1>
      <p>Bank securely, anytime. Move money, pay bills, and manage every account from one place.</p>
      <ul class="auth-points">
        <li><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9l3 3 8-9"/></svg> 256-bit encrypted connection</li>
        <li><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9l3 3 8-9"/></svg> One-time passcode verification</li>
        <li><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9l3 3 8-9"/></svg> Real-time fraud monitoring</li>
      </ul>
    </div>
    <div class="auth-foot">SWIFT SENBLKLX · Hotline 1988 · We never ask for your password or OTP.</div>
  </div>

  <div class="auth-formside">
    <div class="auth-card">
      <div class="mobile-logo"><img src="/assets/img/logo.png" alt="Senarath Bank"></div>
      <h2>Log in</h2>
      <p class="sub">Enter your online banking credentials to continue.</p>
      <div id="err" class="alert alert-err" style="display:none"></div>
      <form id="loginForm" autocomplete="on">
        <div class="field">
          <label for="username">Username or customer number</label>
          <input id="username" name="username" required autocomplete="username" placeholder="e.g. customer01">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Your password">
        </div>
        <div class="row-between" style="margin-bottom:22px">
          <label style="font-size:.85rem;color:var(--muted)"><input type="checkbox" style="width:auto;margin-right:6px">Remember this device</label>
          <a class="link" href="#">Forgot password?</a>
        </div>
        <button class="btn btn-gold" type="submit" id="loginBtn">Continue</button>
      </form>
      <p class="note" style="margin-top:22px;text-align:center">
        New to Senarath Bank? <a class="link" href="https://www.senarathbank.com/personal.php#open">Open an account</a>
      </p>
      <!-- LAB-ONLY: seeded synthetic breach accounts (V1/V3). Remove for any non-lab use. -->
      <div style="margin-top:26px;padding:12px 14px;border:1px dashed var(--line);border-radius:10px;background:var(--mist)">
        <div style="font-size:.74rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:6px">Lab test accounts (synthetic)</div>
        <div class="note">customer01 / Welcome123! &nbsp;·&nbsp; customer02 / Summer123!</div>
      </div>
    </div>
  </div>
</div>

<script>
const form = document.getElementById('loginForm');
const err  = document.getElementById('err');
const btn  = document.getElementById('loginBtn');
form.addEventListener('submit', async (ev) => {
  ev.preventDefault();
  err.style.display = 'none';
  btn.disabled = true; btn.textContent = 'Checking…';
  try {
    const res = await fetch('/api/login.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ username: username.value, password: password.value })
    });
    const data = await res.json();
    if (res.ok && data.ok) {
      // NOTE (V4): the response already contains a usable session token here,
      // BEFORE the OTP is verified. The browser simply proceeds to the OTP page.
      window.location = '/otp.php';
    } else {
      err.textContent = data.error || 'Invalid username or password.';
      err.style.display = 'block';
    }
  } catch (e) {
    err.textContent = 'Could not reach the server. Please try again.';
    err.style.display = 'block';
  } finally {
    btn.disabled = false; btn.textContent = 'Continue';
  }
});
</script>
</body>
</html>
