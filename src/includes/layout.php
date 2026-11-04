<?php
/* Senarath Online Banking - authenticated app shell. LAB ENVIRONMENT. */
require_once __DIR__ . '/../helpers.php';

function nav_item(string $href, string $label, string $icon, string $active): string {
    $cls = $active === $href ? 'active' : '';
    return "<a class=\"$cls\" href=\"$href\">$icon<span>$label</span></a>";
}

function app_header(array $customer, string $active, string $pageTitle): void {
    $initials = strtoupper(substr($customer['first_name'] ?? 'S', 0, 1) . substr($customer['last_name'] ?? 'B', 0, 1));
    $fullname = e(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
    $ic = [
      'dash' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="2" width="6" height="6" rx="1.5"/><rect x="11" y="2" width="6" height="6" rx="1.5"/><rect x="2" y="11" width="6" height="6" rx="1.5"/><rect x="11" y="11" width="6" height="6" rx="1.5"/></svg>',
      'acct' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="4" width="15" height="11" rx="2"/><path d="M2 8h15"/></svg>',
      'tx'   => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 6h10l-3-3M16 13H6l3 3"/></svg>',
      'xfer' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 2v15M4 9l6-6 6 6"/></svg>',
      'benf' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="7" cy="6" r="3"/><path d="M2 17c0-3 2-5 5-5s5 2 5 5"/><path d="M14 8h4M16 6v4"/></svg>',
      'stmt' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 2h7l4 4v12H5z"/><path d="M12 2v4h4M7 11h6M7 14h6"/></svg>',
      'upld' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 13V3M6 7l4-4 4 4"/><path d="M3 15v2h14v-2"/></svg>',
      'prof' => '<svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="10" cy="7" r="3.2"/><path d="M4 17c0-3.3 2.7-5 6-5s6 1.7 6 5"/></svg>',
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> - Senarath Online Banking</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="logo"><img src="/assets/img/logo.png" alt="Senarath Bank"></div>
    <nav class="side-nav">
      <?= nav_item('/dashboard.php','Dashboard',$ic['dash'],$active) ?>
      <?= nav_item('/accounts.php','Accounts',$ic['acct'],$active) ?>
      <?= nav_item('/transactions.php','Transactions',$ic['tx'],$active) ?>
      <?= nav_item('/transfer.php','Transfer money',$ic['xfer'],$active) ?>
      <?= nav_item('/beneficiaries.php','Beneficiaries',$ic['benf'],$active) ?>
      <?= nav_item('/statements.php','Statements',$ic['stmt'],$active) ?>
      <?= nav_item('/upload.php','Upload documents',$ic['upld'],$active) ?>
      <?= nav_item('/profile.php','Profile',$ic['prof'],$active) ?>
    </nav>
    <div class="side-foot">
      <div class="who"><b><?= $fullname ?></b><?= e($customer['username']) ?></div>
      <a class="btn btn-ghost" style="margin-top:12px;width:100%" href="/api/logout.php">Log out</a>
    </div>
  </aside>
  <div class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:14px">
        <button class="menu-btn" onclick="toggleSB()" aria-label="Toggle menu">
          <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
        </button>
        <div class="pg-title"><?= e($pageTitle) ?></div>
      </div>
      <div class="tb-right">
        <div class="bell"><svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 2a5 5 0 00-5 5c0 6-2 7-2 7h14s-2-1-2-7a5 5 0 00-5-5zM8 17a2 2 0 004 0"/></svg><span class="dot"></span></div>
        <div class="avatar"><?= e($initials) ?></div>
      </div>
    </div>
    <div class="content">
    <?php
}

function app_footer(): void {
    ?>
    </div>
  </div>
</div>
<div class="sb-overlay" id="sbOverlay" onclick="toggleSB()"></div>
<script>
function toggleSB(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sbOverlay').classList.toggle('show');
}
// close the off-canvas sidebar after choosing a destination
document.querySelectorAll('.side-nav a').forEach(function(a){
  a.addEventListener('click',function(){
    var sb=document.getElementById('sidebar');
    if(sb.classList.contains('open')) toggleSB();
  });
});
</script>
<script src="/assets/js/app.js"></script>
</body>
</html>
    <?php
}
