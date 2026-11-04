<?php
/* Senarath Online Banking - Profile. LAB ENVIRONMENT. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
$msg=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $email=trim($_POST['email']??''); $phone=trim($_POST['phone']??''); $addr=trim($_POST['address']??'');
    q("UPDATE customers SET email=:e, phone=:p, address=:a WHERE id=:id",
      [':e'=>$email, ':p'=>$phone, ':a'=>$addr, ':id'=>$customer['id']]);
    $customer['email']=$email; $customer['phone']=$phone; $customer['address']=$addr;
    $msg='Profile updated.';
}
app_header($customer, '/profile.php', 'Profile');
?>
<?php if($msg):?><div class="alert alert-ok" style="max-width:none"><?= e($msg) ?></div><?php endif;?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px" class="profwrap">
  <div class="panel">
    <div class="ph"><h3>Personal details</h3></div>
    <div class="pb">
      <div class="summary" style="flex-direction:column;gap:14px">
        <div class="s"><b><?= e($customer['first_name'].' '.$customer['last_name']) ?></b><span>Full name</span></div>
        <div class="s"><b><?= e($customer['customer_no']) ?></b><span>Customer number</span></div>
        <div class="s"><b><?= e($customer['username']) ?></b><span>Username</span></div>
        <div class="s"><b><?= e($customer['nic']) ?></b><span>NIC</span></div>
        <div class="s"><b><?= e(date('d M Y', strtotime($customer['dob']))) ?></b><span>Date of birth</span></div>
      </div>
    </div>
  </div>
  <div class="panel">
    <div class="ph"><h3>Contact details</h3></div>
    <div class="pb">
      <form method="post" class="appform">
        <div class="field"><label>Email</label><input name="email" type="email" value="<?= e($customer['email']) ?>"></div>
        <div class="field"><label>Mobile</label><input name="phone" value="<?= e($customer['phone']) ?>"></div>
        <div class="field"><label>Address</label><textarea name="address" rows="3"><?= e($customer['address']) ?></textarea></div>
        <button class="btn btn-gold" type="submit" style="width:auto;padding:12px 24px">Save changes</button>
      </form>
    </div>
  </div>
</div>
<style>@media(max-width:860px){.profwrap{grid-template-columns:1fr!important}}</style>
<?php app_footer(); ?>
