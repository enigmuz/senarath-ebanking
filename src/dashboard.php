<?php
/* Senarath Online Banking — Dashboard. LAB ENVIRONMENT.
 * Reached with a pre-OTP session (V4) just as well as a fully-verified one. */
require_once __DIR__ . '/includes/auth.php';      // $customer
require_once __DIR__ . '/includes/layout.php';

$accounts = all("SELECT * FROM accounts WHERE customer_id = :c ORDER BY id", [':c' => $customer['id']]);
$acctIds  = array_column($accounts, 'id');
$total    = 0.0;
foreach ($accounts as $a) { if ($a['account_type'] !== 'Credit') $total += (float)$a['balance']; }

$recent = [];
if ($acctIds) {
    $in = implode(',', array_map('intval', $acctIds));
    $recent = all("SELECT TOP 7 t.*, a.account_no, a.account_type
                     FROM transactions t JOIN accounts a ON a.id = t.account_id
                    WHERE t.account_id IN ($in)
                    ORDER BY t.txn_date DESC, t.id DESC");
}

$cardClass = ['Current'=>'primary','Savings'=>'savings','Credit'=>'card'];
app_header($customer, '/dashboard.php', 'Dashboard');
?>
<p style="margin:2px 0 22px;color:var(--muted)">Welcome back, <strong style="color:var(--ink)"><?= e($customer['first_name']) ?></strong>. Here&rsquo;s your money at a glance.</p>

<div class="summary panel" style="padding:20px 22px">
  <div class="s"><b><?= money($total) ?></b><span>Total available across accounts</span></div>
  <div class="s"><b><?= count($accounts) ?></b><span>Active accounts</span></div>
  <div class="s"><b><?= e($customer['customer_no']) ?></b><span>Customer number</span></div>
</div>

<div class="cards-row">
  <?php foreach (array_slice($accounts,0,3) as $a):
      $cls = $cardClass[$a['account_type']] ?? 'primary'; ?>
    <div class="acct-card <?= $cls ?>">
      <div class="chip"></div>
      <div class="type"><?= e($a['account_type']) ?> account</div>
      <div class="num"><?= e(mask_acct($a['account_no'])) ?></div>
      <div class="bal"><?= money((float)$a['balance']) ?></div>
      <div class="sub"><?= $a['account_type']==='Credit' ? 'Outstanding balance' : 'Available balance' ?> · <?= e($a['currency']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="ph"><h3>Quick actions</h3></div>
  <div class="pb">
    <div class="quick">
      <a href="/transfer.php"><span class="qi"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 3v14M5 9l5-5 5 5"/></svg></span>Transfer money</a>
      <a href="/transfer.php#bill"><span class="qi"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="14" height="14" rx="2"/><path d="M7 8h6M7 12h4"/></svg></span>Pay a bill</a>
      <a href="/beneficiaries.php"><span class="qi"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="8" cy="7" r="3"/><path d="M3 17c0-3 2-5 5-5"/><path d="M14 9h4M16 7v4"/></svg></span>Add payee</a>
      <a href="/upload.php"><span class="qi"><svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 13V3M6 7l4-4 4 4"/><path d="M3 15v2h14v-2"/></svg></span>Upload document</a>
    </div>
  </div>
</div>

<div class="panel">
  <div class="ph"><h3>Recent transactions</h3><a class="link" href="/transactions.php">View all →</a></div>
  <div class="pb flush">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Description</th><th>Account</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance</th></tr></thead>
      <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="5" style="color:var(--muted);text-align:center;padding:26px">No transactions yet.</td></tr>
        <?php else: foreach ($recent as $t): $neg = (float)$t['amount'] < 0; ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($t['txn_date']))) ?></td>
            <td><?= e($t['description']) ?><?php if($t['counterparty']): ?><div class="note"><?= e($t['counterparty']) ?></div><?php endif; ?></td>
            <td><?= e(mask_acct($t['account_no'])) ?></td>
            <td style="text-align:right" class="<?= $neg?'amt-neg':'amt-pos' ?>"><?= ($neg?'− ':'+ ').number_format(abs((float)$t['amount']),2) ?></td>
            <td style="text-align:right"><?= number_format((float)$t['balance_after'],2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php app_footer(); ?>
