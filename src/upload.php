<?php
/* ============================================================================
 * Senarath Online Banking - "Upload documents" (LAB ENVIRONMENT)
 * ----------------------------------------------------------------------------
 * INTENTIONAL WEAKNESS V5 - Unrestricted file upload -> RCE.
 *
 * The document-upload handler, exactly as the build guide specifies:
 *   1. Accepts ANY file - no extension allow-list, no MIME check, no content
 *      inspection.
 *   2. Stores the file INSIDE the webroot (./uploads), a path Apache serves.
 *   3. Preserves the original name/extension, and the uploads directory is left
 *      PHP-executable, so requesting an uploaded .php RUNS it.
 *
 * Reached via the origin IP (V2) the WAF upload filtering is out of the path, so
 * the upload that the WAF would block succeeds against the origin directly.
 *
 * NOTE (build guide p.23): no web shell is shipped in this application. The
 * offensive-security tester supplies their own test artifact. Verify the
 * weakness with the benign file documented in VULNERABILITIES.md, which prints
 * "LAB-RCE-OK:<service account>".
 *
 * Only basename() is applied - purely to stop path traversal OUTSIDE the
 * uploads dir. That is NOT a security control on the upload itself; the file
 * type is deliberately unrestricted.
 * ==========================================================================*/
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$msg = null; $err = null; $uploadedUrl = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $f = $_FILES['document'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload failed (error code ' . (int)$f['error'] . ').';
    } else {
        if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0775, true); }

        // === V5: NO validation of extension, MIME, or content. ===
        // Original name/extension preserved so the stored file stays executable.
        $name = basename($f['name']);                 // traversal guard only
        $dest = UPLOAD_DIR . '/' . $name;

        if (move_uploaded_file($f['tmp_name'], $dest)) {
            $uploadedUrl = UPLOAD_URL . '/' . rawurlencode($name);
            try {
                q("INSERT INTO uploads (customer_id, original_name, stored_name, stored_path, size_bytes, mime, uploaded_at)
                   VALUES (:c,:o,:s,:p,:z,:m,GETDATE())",
                   [':c'=>$customer['id'], ':o'=>$f['name'], ':s'=>$name, ':p'=>$dest,
                    ':z'=>(int)$f['size'], ':m'=>(string)($f['type'] ?? '')]);
            } catch (Throwable $e) { /* lab: ignore logging failure */ }
            $msg = 'Document "' . e($name) . '" uploaded successfully.';
        } else {
            $err = 'Could not store the uploaded file.';
        }
    }
}

// List previously uploaded documents for this customer.
$docs = [];
try { $docs = all("SELECT TOP 50 * FROM uploads WHERE customer_id=:c ORDER BY id DESC", [':c'=>$customer['id']]); }
catch (Throwable $e) { $docs = []; }

app_header($customer, '/upload.php', 'Upload documents');
?>
<p style="margin:2px 0 22px;color:var(--muted)">Upload KYC documents, cheque images or supporting files for your applications.</p>

<?php if($msg):?><div class="alert alert-ok" style="max-width:none"><?= $msg ?> <?php if($uploadedUrl):?>&nbsp;<a class="link" href="<?= e($uploadedUrl) ?>" target="_blank">View file →</a><?php endif;?></div><?php endif;?>
<?php if($err):?><div class="alert alert-err" style="max-width:none"><?= e($err) ?></div><?php endif;?>

<div class="panel">
  <div class="ph"><h3>Upload a document</h3></div>
  <div class="pb">
    <form method="post" enctype="multipart/form-data" class="appform" style="max-width:none">
      <label class="drop" for="document" style="display:block;cursor:pointer">
        <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M20 27V9M12 15l8-8 8 8"/><path d="M6 29v4h28v-4"/></svg>
        <div><strong>Click to choose a file</strong> or drag it here</div>
        <div class="note" style="margin-top:6px">PDF, JPG, PNG or document files · up to 8 MB</div>
        <input id="document" name="document" type="file" style="display:none" onchange="this.form.querySelector('.fn').textContent=this.files[0]?.name||''">
      </label>
      <div class="fn note" style="margin-top:10px"></div>
      <div class="form-actions" style="margin-top:16px">
        <button class="btn btn-gold" type="submit">Upload document</button>
      </div>
    </form>
  </div>
</div>

<div class="panel">
  <div class="ph"><h3>Your uploaded documents</h3></div>
  <div class="pb">
    <div class="filelist">
      <?php if(!$docs):?><div class="note">No documents uploaded yet.</div><?php endif;?>
      <?php foreach($docs as $d): ?>
        <div class="f">
          <div><strong><?= e($d['original_name']) ?></strong><div class="note"><?= number_format(((int)$d['size_bytes'])/1024,1) ?> KB · <?= e(date('d M Y H:i', strtotime($d['uploaded_at']))) ?></div></div>
          <a href="<?= e(UPLOAD_URL.'/'.rawurlencode($d['stored_name'])) ?>" target="_blank">Open</a>
        </div>
      <?php endforeach;?>
    </div>
  </div>
</div>
<?php app_footer(); ?>
