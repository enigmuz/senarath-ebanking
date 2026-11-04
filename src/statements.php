<?php
/* Senarath Online Banking - Statements. LAB ENVIRONMENT.
 * Download exports a CSV of the selected account's transactions. */
require_once __DIR__ . '/includes/auth.php';
$accounts = all("SELECT * FROM accounts WHERE customer_id = :c ORDER BY id", [':c'=>$customer['id']]);

// CSV export
if (isset($_GET['download'])) {
    $aid = (int)$_GET['download'];
    $own = one("SELECT * FROM accounts WHERE id=:id AND customer_id=:c", [':id'=>$aid, ':c'=>$customer['id']]);
    if ($own) {
        $rows = all("SELECT * FROM transactions WHERE account_id=:a ORDER BY txn_date DESC, id DESC", [':a'=>$aid]);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="statement_'.$own['account_no'].'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['Date','Description','Counterparty','Reference','Channel','Amount','Balance']);
        foreach ($rows as $t) fputcsv($out, [$t['txn_date'],$t['description'],$t['counterparty'],$t['reference'],$t['channel'],$t['amount'],$t['balance_after']]);
        fclose($out); exit;
    }
}
require_once __DIR__ . '/includes/layout.php';
$months = [];
for ($i=0;$i<6;$i++){ $months[] = date('F Y', strtotime("-$i month")); }
app_header($customer, '/statements.php', 'Statements');
?>
<p style="margin:2px 0 22px;color:var(--muted)">Download e-statements for any of your accounts.</p>
<div class="panel">
  <div class="ph"><h3>e-Statements</h3></div>
  <div class="pb flush">
    <table class="tbl">
      <thead><tr><th>Account</th><th>Type</th><th>Period</th><th style="text-align:right">Export</th></tr></thead>
      <tbody>
        <?php foreach ($accounts as $a): ?>
          <tr>
            <td><strong><?= e($a['account_no']) ?></strong></td>
            <td><?= e($a['account_type']) ?></td>
            <td class="note">Full history · to <?= e($months[0]) ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="/statements.php?download=<?= (int)$a['id'] ?>">Download CSV</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php app_footer(); ?>
