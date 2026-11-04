<?php
/* Senarath Online Banking - Transfer money. LAB ENVIRONMENT.
 * Functional: debits the source account and records a transaction. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$accounts = all("SELECT * FROM accounts WHERE customer_id = :c AND account_type <> 'Credit' ORDER BY id", [':c'=>$customer['id']]);
$benes    = all("SELECT * FROM beneficiaries WHERE customer_id = :c ORDER BY name", [':c'=>$customer['id']]);
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $srcId  = (int)($_POST['from'] ?? 0);
    $toName = trim($_POST['to_name'] ?? '');
    $toAcct = trim($_POST['to_acct'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $ref    = trim($_POST['ref'] ?? 'Online transfer');

    $src = one("SELECT * FROM accounts WHERE id=:id AND customer_id=:c", [':id'=>$srcId, ':c'=>$customer['id']]);

    if (!$src)                 $err = 'Please choose a valid source account.';
    elseif ($amount <= 0)      $err = 'Enter an amount greater than zero.';
    elseif ($amount > (float)$src['balance']) $err = 'Insufficient funds in the selected account.';
    elseif ($toName === '' || $toAcct === '') $err = 'Enter the payee name and account number.';
    else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $newBal = (float)$src['balance'] - $amount;
            $reference = 'TRF' . date('ymd') . random_int(1000,9999);
            q("UPDATE accounts SET balance=:b1, available_balance=:b2 WHERE id=:id", [':b1'=>$newBal, ':b2'=>$newBal, ':id'=>$src['id']]);
            q("INSERT INTO transactions (account_id, txn_date, description, counterparty, amount, balance_after, channel, status, reference)
               VALUES (:a, GETDATE(), :d, :cp, :amt, :bal, 'Online', 'Completed', :ref)",
               [':a'=>$src['id'], ':d'=>$ref, ':cp'=>$toName.' · '.$toAcct, ':amt'=>-$amount, ':bal'=>$newBal, ':ref'=>$reference]);
            $pdo->commit();
            $msg = 'Transfer of ' . money($amount) . ' to ' . e($toName) . ' completed. Reference ' . $reference . '.';
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $err = 'The transfer could not be completed. Please try again.';
        }
    }
}
app_header($customer, '/transfer.php', 'Transfer money');
?>
<?php if ($msg): ?><div class="alert alert-ok" style="max-width:none"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-err" style="max-width:none"><?= e($err) ?></div><?php endif; ?>

<div class="panel">
  <div class="ph"><h3>New transfer</h3></div>
  <div class="pb">
    <form method="post" class="appform">
      <div class="field">
        <label>From account</label>
        <select name="from" required>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= e($a['account_type']) ?> · <?= e(mask_acct($a['account_no'])) ?> - <?= money((float)$a['balance']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Payee</label>
        <select onchange="fill(this)">
          <option value="">- New payee -</option>
          <?php foreach ($benes as $b): ?>
            <option value="<?= e($b['name']) ?>|<?= e($b['account_no']) ?>"><?= e($b['nickname'] ?: $b['name']) ?> · <?= e($b['bank']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Payee name</label><input name="to_name" id="to_name" required placeholder="e.g. Nimal Perera"></div>
      <div class="field"><label>Payee account number</label><input name="to_acct" id="to_acct" required placeholder="10-digit account number"></div>
      <div class="field"><label>Amount (LKR)</label><input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00"></div>
      <div class="field"><label>Reference</label><input name="ref" placeholder="e.g. Rent - November" value="Online transfer"></div>
      <div class="form-actions">
        <button class="btn btn-gold" type="submit">Send transfer</button>
        <a class="btn btn-ghost" href="/dashboard.php">Cancel</a>
      </div>
      <p class="note" style="margin-top:14px">Transfers via LankaPay are usually instant. Daily limit LKR 5,000,000 (lab).</p>
    </form>
  </div>
</div>
<script>
function fill(sel){ const v=sel.value; if(!v) return; const [n,a]=v.split('|'); document.getElementById('to_name').value=n; document.getElementById('to_acct').value=a; }
</script>
<?php app_footer(); ?>
