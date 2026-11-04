<?php
/* Senarath Online Banking — Transactions. LAB ENVIRONMENT. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$accounts = all("SELECT * FROM accounts WHERE customer_id = :c ORDER BY id", [':c' => $customer['id']]);
$acctIds  = array_column($accounts, 'id');
$sel      = isset($_GET['acct']) ? (int)$_GET['acct'] : 0;

$rows = [];
if ($acctIds) {
    $in = implode(',', array_map('intval', $acctIds));
    if ($sel && in_array($sel, array_map('intval',$acctIds), true)) {
        $rows = all("SELECT TOP 100 t.*, a.account_no FROM transactions t JOIN accounts a ON a.id=t.account_id
                      WHERE t.account_id = :a ORDER BY t.txn_date DESC, t.id DESC", [':a'=>$sel]);
    } else {
        $rows = all("SELECT TOP 100 t.*, a.account_no FROM transactions t JOIN accounts a ON a.id=t.account_id
                      WHERE t.account_id IN ($in) ORDER BY t.txn_date DESC, t.id DESC");
    }
}
app_header($customer, '/transactions.php', 'Transactions');
?>
<div class="panel">
  <div class="ph">
    <h3>Transaction history</h3>
    <form method="get" style="display:flex;gap:10px;align-items:center">
      <select name="acct" onchange="this.form.submit()" style="padding:9px 12px;border:1px solid var(--line);border-radius:9px;font:inherit">
        <option value="0">All accounts</option>
        <?php foreach ($accounts as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= $sel===(int)$a['id']?'selected':'' ?>><?= e($a['account_type']) ?> · <?= e(mask_acct($a['account_no'])) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="pb flush">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th>Channel</th><th>Account</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:26px">No transactions.</td></tr>
        <?php else: foreach ($rows as $t): $neg=(float)$t['amount']<0; ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($t['txn_date']))) ?></td>
            <td><?= e($t['description']) ?><?php if($t['counterparty']): ?><div class="note"><?= e($t['counterparty']) ?></div><?php endif; ?></td>
            <td class="note"><?= e($t['reference']) ?></td>
            <td><?= e($t['channel']) ?></td>
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
