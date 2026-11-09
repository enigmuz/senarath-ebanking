<?php
/* Senarath Online Banking | login page. LAB ENVIRONMENT.
 * If already authenticated (V4 session), go straight to the dashboard. */
require_once __DIR__ . '/helpers.php';
if (current_customer()) { header('Location: /dashboard.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in | Senarath Online Banking</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="icon" href="/assets/img/logo.png">
</head>
<body>
<div id="nprogress"><div class="bar"></div></div>
<div id="splash">
  <div class="splash-inner">
    <div class="splash-logo"><img src="/assets/img/logo.png" alt="Senarath Bank"></div>
    <div class="splash-bar"><span></span></div>
    <div class="splash-tag">Secure Online Banking</div>
  </div>
</div>
<script>try{if(sessionStorage.getItem('sb_splash')==='1'){var _s=document.getElementById('splash');if(_s)_s.style.display='none';}}catch(e){}</script>

<!-- Training disclaimer marquee -->
<div class="disclaimer-marquee" role="note" aria-label="Training disclaimer">
  <div class="dm-track">
    <span class="dm-item">This website has been created solely for training purposes and is intended for Awareness use only. &ldquo;Senarath Bank&rdquo; is a fictional bank; any resemblance to a real bank, institution, or organization of the same or similar name is entirely coincidental. The contents of this website are for educational use only, and no responsibility will be accepted for any similarity to any real person, organization, or entity.</span>
    <span class="dm-item" aria-hidden="true">This website has been created solely for training purposes and is intended for Awareness use only. &ldquo;Senarath Bank&rdquo; is a fictional bank; any resemblance to a real bank, institution, or organization of the same or similar name is entirely coincidental. The contents of this website are for educational use only, and no responsibility will be accepted for any similarity to any real person, organization, or entity.</span>
  </div>
</div>

<div class="login-stage">
  <div class="login-brand">
    <h1 class="eb-title"><span class="t-navy">Easy</span> <span class="t-gold">Banking</span></h1>
    <p class="eb-sub">Anytime &bull; Anywhere &bull; Secure</p>
  </div>

  <!-- phone mockup (desktop decoration) -->
  <div class="login-phone" aria-hidden="true">
    <div class="lp-frame">
      <div class="lp-notch"></div>
      <div class="lp-logo"><img src="/assets/img/logo.png" alt=""></div>
      <div class="lp-menu">
        <div class="lp-item"><span class="lp-ic"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="3" width="11" height="8" rx="1.5"/><path d="M2 6h11"/></svg></span>Accounts</div>
        <div class="lp-item"><span class="lp-ic"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 5h8l-2-2M12 10H4l2 2"/></svg></span>Transfers</div>
        <div class="lp-item"><span class="lp-ic"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="2" width="9" height="11" rx="1.5"/><path d="M5 5h5M5 8h4"/></svg></span>Bill Payments</div>
        <div class="lp-item"><span class="lp-ic"><svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 5l5.5-3L13 5v5l-5.5 3L2 10z"/></svg></span>Savings</div>
      </div>
    </div>
  </div>

  <div class="login-main">
    <div class="login-card">
      <div class="login-logo"><img src="/assets/img/logo.png" alt="Senarath Bank"></div>
      <h2>Log in</h2>
      <p class="login-sub">Enter your online banking credentials to continue.</p>
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
        <div class="login-row">
          <label class="rem"><input type="checkbox">Remember this device</label>
          <a class="login-link" href="#">Forgot password?</a>
        </div>
        <button class="btn-cta" type="submit" id="loginBtn">Continue</button>
      </form>
      <p class="login-new">New to Senarath Bank? <a class="login-link" href="https://senarathbank.com/personal.php#open">Open an account</a></p>
    </div>

    <div class="login-pills">
      <div class="pill">
        <span class="pill-ic"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="6" y="2" width="11" height="18" rx="2"/><path d="M9 17h5"/></svg></span>
        <div class="pill-tx"><b>Manage</b><span>Your Accounts</span></div>
      </div>
      <div class="pill">
        <span class="pill-ic"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h13l-3-3M18 14H5l3 3"/></svg></span>
        <div class="pill-tx"><b>Transfer</b><span>Funds Instantly</span></div>
      </div>
      <div class="pill">
        <span class="pill-ic"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="3" width="14" height="16" rx="2"/><path d="M8 8h6M8 12h5"/></svg></span>
        <div class="pill-tx"><b>Pay Bills</b><span>with Ease</span></div>
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
  btn.disabled = true; btn.textContent = 'Checking...';
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
<script src="/assets/js/app.js"></script>
</body>
</html>
