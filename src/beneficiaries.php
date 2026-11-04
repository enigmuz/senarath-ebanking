<?php
/* Senarath Online Banking — Beneficiaries. LAB ENVIRONMENT. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
$msg=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (($_POST['action'] ?? '')==='add') {
        $name=trim($_POST['name']??''); $bank=trim($_POST['bank']??'Senarath Bank');
        $acct=trim($_POST['acct']??''); $nick=trim($_POST['nick']??'');
        if ($name && $acct) {
            q("INSERT INTO beneficiaries (customer_id,name,bank,account_no,nickname,created_at)
               VALUES (:c,:n,:b,:a,:k,GETDATE())",
               [':c'=>$customer['id'],':n'=>$name,':b'=>$bank,':a'=>$acct,':k'=>$nick]);
            $msg='Payee added.';
        }
    } elseif (($_POST['action'] ?? '')==='del') {
        q("DELETE FROM beneficiaries WHERE id=:id AND customer_id=:c", [':id'=>(int)$_POST['id'],':c'=>$customer['id']]);
        $msg='Payee removed.';
    }
}
$benes = all("SELECT * FROM beneficiaries WHERE customer_id=:c ORDER BY name", [':c'=>$customer['id']]);
app_header($customer, '/beneficiaries.php', 'Beneficiaries');
?>
<?php if($msg):?><div class="alert alert-ok" style="max-width:none"><?= e($msg) ?></div><?php endif;?>
<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:24px" class="benwrap">
  <div class="panel">
    <div class="ph"><h3>Saved payees</h3></div>
    <div class="pb flush">
      <table class="tbl">
        <thead><tr><th>Name</th><th>Bank</th><th>Account</th><th></th></tr></thead>
        <tbody>
          <?php if(!$benes):?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">No payees yet.</td></tr><?php endif;?>
          <?php foreach($benes as $b):?>
          <tr>
            <td><strong><?= e($b['nickname'] ?: $b['name']) ?></strong><div class="note"><?= e($b['name']) ?></div></td>
            <td><?= e($b['bank']) ?></td>
            <td><?= e(mask_acct($b['account_no'])) ?></td>
            <td style="text-align:right"><form method="post" onsubmit="return confirm('Remove this payee?')"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form></td>
          </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="panel">
    <div class="ph"><h3>Add a payee</h3></div>
    <div class="pb">
      <form method="post" class="appform">
        <input type="hidden" name="action" value="add">
        <div class="field"><label>Payee name</label><input name="name" required></div>
        <div class="field"><label>Bank</label><input name="bank" value="Senarath Bank"></div>
        <div class="field"><label>Account number</label><input name="acct" required></div>
        <div class="field"><label>Nickname (optional)</label><input name="nick"></div>
        <button class="btn btn-gold" type="submit" style="width:auto;padding:12px 24px">Add payee</button>
      </form>
    </div>
  </div>
</div>
<style>@media(max-width:860px){.benwrap{grid-template-columns:1fr!important}}</style>
<?php app_footer(); ?>
