<?php
/* Senarath Online Banking - OTP step (LAB ENVIRONMENT).
 *
 * This page exists so the login flow LOOKS complete. But because the session
 * is already authenticated (V4), the OTP is never actually required: the user
 * - or an attacker holding the pre-OTP token - can navigate straight to
 * /dashboard.php and it works. Submitting the OTP here just marks otp_verified,
 * which nothing enforces.
 */
require_once __DIR__ . '/helpers.php';
$cust = current_customer();
if (!$cust) { header('Location: /index.php'); exit; }
// If somehow already fully verified, go to dashboard.
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify - Senarath Online Banking</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
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

<div class="auth-wrap">
  <div class="auth-brandside">
    <div class="z">
      <img src="/assets/img/logo.png" alt="Senarath Bank">
      <h1>One more step</h1>
      <p>We&rsquo;ve sent a 6-digit one-time passcode to your registered mobile ending in ••78.</p>
      <ul class="auth-points">
        <li><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9l3 3 8-9"/></svg> Codes expire in 5 minutes</li>
        <li><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9l3 3 8-9"/></svg> Never share your OTP with anyone</li>
      </ul>
    </div>
    <div class="auth-foot">Didn&rsquo;t get a code? Call 1988.</div>
  </div>
  <div class="auth-formside">
    <div class="auth-card">
      <div class="mobile-logo"><img src="/assets/img/logo.png" alt="Senarath Bank"></div>
      <h2>Enter your passcode</h2>
      <p class="sub">Signed in as <strong><?= e($cust['username']) ?></strong>.</p>
      <div id="msg" class="alert alert-info">Lab: the OTP is written to the app log. The fixed demo code also works.</div>
      <form id="otpForm">
        <div class="field">
          <label>6-digit one-time passcode</label>
          <div class="otp-inputs">
            <input maxlength="1" inputmode="numeric" class="otp-d"><input maxlength="1" inputmode="numeric" class="otp-d">
            <input maxlength="1" inputmode="numeric" class="otp-d"><input maxlength="1" inputmode="numeric" class="otp-d">
            <input maxlength="1" inputmode="numeric" class="otp-d"><input maxlength="1" inputmode="numeric" class="otp-d">
          </div>
        </div>
        <button class="btn btn-gold" type="submit">Verify &amp; continue</button>
      </form>
      <p class="note" style="margin-top:18px;text-align:center">
        <a class="link" href="/dashboard.php">Skip to dashboard →</a>
      </p>
    </div>
  </div>
</div>
<script>
const ds = Array.from(document.querySelectorAll('.otp-d'));
ds.forEach((d,i)=>d.addEventListener('input',()=>{ if(d.value && i<ds.length-1) ds[i+1].focus(); }));
document.getElementById('otpForm').addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const code = ds.map(d=>d.value).join('');
  const res = await fetch('/api/verify-otp.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({otp:code})});
  // Regardless of the result we can proceed - the session is already valid (V4).
  window.location = '/dashboard.php';
});
</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
