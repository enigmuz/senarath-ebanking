<?php
/* Senarath Online Banking - Accounts. LAB ENVIRONMENT. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
$accounts = all("SELECT * FROM accounts WHERE customer_id = :c ORDER BY id", [':c' => $customer['id']]);
app_header($customer, '/accounts.php', 'Accounts');
?>
<p style="margin:2px 0 22px;color:var(--muted)">All your Senarath Bank accounts in one place.</p>
<div class="panel">
  <div class="ph"><h3>Your accounts</h3></div>
  <div class="pb flush">
    <table class="tbl">
      <thead><tr><th>Account</th><th>Type</th><th>Currency</th><th>Opened</th><th>Status</th><th style="text-align:right">Balance</th></tr></thead>
      <tbody>
        <?php foreach ($accounts as $a): ?>
        <tr>
          <td><strong><?= e($a['account_no']) ?></strong></td>
          <td><?= e($a['account_type']) ?></td>
          <td><?= e($a['currency']) ?></td>
          <td><?= e(date('d M Y', strtotime($a['opened_at']))) ?></td>
          <td><span class="tag done"><?= e($a['status']) ?></span></td>
          <td style="text-align:right"><strong><?= money((float)$a['balance']) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="ph"><h3>Account details</h3></div>
  <div class="pb">
    <?php $first = $accounts[0] ?? null; if ($first): ?>
    <div class="summary">
      <div class="s"><b><?= e($first['account_no']) ?></b><span>Primary account number</span></div>
      <div class="s"><b><?= e(BANK_SWIFT) ?></b><span>SWIFT / BIC</span></div>
      <div class="s"><b>7010<?= e(str_pad((string)$first['id'],4,'0',STR_PAD_LEFT)) ?></b><span>Branch code (Colombo)</span></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php app_footer(); ?>
