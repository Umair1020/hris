<?php
/**
 * ============================================================================
 * COMPANY POLICY — employees view policies; HR manages them
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Company Policy');

if($_SERVER['REQUEST_METHOD']==='POST' && is_hr()){
  verify_csrf();
  $action=$_POST['action']??'';
  if($action==='add'){
    insert('company_policies',['title'=>clean($_POST['title']),'category'=>clean($_POST['category'] ?? 'General'),
      'content'=>$_POST['content'] ?? '','version'=>clean($_POST['version']?:'1.0'),
      'effective_date'=>clean($_POST['effective_date'])?:today(),'created_by'=>current_user_id()]);
    set_flash('success','Policy published.');
  } elseif($action==='delete'){
    $stmt=db()->prepare("DELETE FROM company_policies WHERE id=?");$stmt->execute([(int)$_POST['id']]);
    set_flash('success','Policy deleted.');
  }
  redirect(APP_URL.'modules/policy/index.php');
}

$policies=fetch_all("SELECT * FROM company_policies WHERE status='active' ORDER BY title");
$sel=clean($_GET['p']??'');
$selPolicy=$sel?fetch_one("SELECT * FROM company_policies WHERE id=?",[(int)$sel]):($policies[0]??null);
?>
<div class="page-head">
  <div><h1>Company Policies</h1><div class="sub">Rules &amp; regulations for all Spotcomm Global employees</div></div>
  <?php if(is_hr()): ?><button class="btn btn-primary" onclick="openModal('polModal')"><i class="fa-solid fa-plus"></i> Add Policy</button><?php endif; ?>
</div>

<div class="grid" style="grid-template-columns:300px 1fr">
  <div class="card card-pad">
    <div class="muted small" style="text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">Company Policies</div>
    <?php if(!$policies): echo '<p class="muted small">No policies yet.</p>'; endif; ?>
    <?php foreach($policies as $p): ?>
      <a href="?p=<?= $p['id'] ?>" class="small" style="display:block;padding:8px;border-radius:8px;<?= ($selPolicy&&$selPolicy['id']==$p['id'])?'background:#f0eefe;color:var(--primary);font-weight:600':'' ?>"><i class="fa-solid fa-file-lines"></i> <?= e($p['title']) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card card-pad">
    <?php if($selPolicy): ?>
      <div class="flex between center wrap">
        <div><h2 style="margin:0 0 4px;font-size:20px"><?= e($selPolicy['title']) ?></h2>
        <div class="muted small"><?= e($selPolicy['category']) ?> · v<?= e($selPolicy['version']) ?> · Effective <?= format_date($selPolicy['effective_date']) ?></div></div>
        <?php if(is_hr()): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $selPolicy['id'] ?>"><button class="btn btn-sm btn-light" data-confirm="Delete this policy?"><i class="fa-solid fa-trash"></i></button></form>
        <?php endif; ?>
      </div>
      <div class="divider"></div>
      <?php
        $content = $selPolicy['content'] ?? '';
        if (empty(trim($content))) {
            echo '<p class="muted">No content available.</p>';
        } elseif (strpos($content, '<') !== false) {
            echo '<div style="line-height:1.8;font-size:14px">' . $content . '</div>';
        } else {
            echo '<div style="line-height:1.8;font-size:14px">' . nl2br(e($content)) . '</div>';
        }
      ?>
    <?php else: echo '<div class="empty"><i class="fa-solid fa-book"></i>No policies published yet.</div>'; endif; ?>
  </div>
</div>

<?php if(is_hr()): ?>
<div class="modal-bg" id="polModal"><div class="modal" style="max-width:600px">
  <div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Add Company Policy</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('polModal')"></i></div>
  <form method="post" id="polForm"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="grid cols-2">
      <div><label>Title</label><input type="text" name="title" class="form-control" required></div>
      <div><label>Category</label><input type="text" name="category" class="form-control" value="General"></div>
      <div><label>Version</label><input type="text" name="version" class="form-control" value="1.0"></div>
      <div><label>Effective Date</label><input type="date" name="effective_date" class="form-control" value="<?= today() ?>"></div>
    </div>
    <div style="margin-top:12px">
      <label>Content</label>
      <input type="hidden" name="content" id="policyInput">
      <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
        <div id="policyToolbar" style="border-bottom:1px solid #e0e0e0;padding:8px;display:flex;gap:4px;flex-wrap:wrap;background:#f9f9fb">
          <button type="button" class="ql-bold" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-weight:bold">B</button>
          <button type="button" class="ql-italic" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-style:italic">I</button>
          <button type="button" class="ql-list" value="bullet" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer">•</button>
        </div>
        <div id="policyEditor" style="min-height:200px;padding:16px;font-size:14px;line-height:1.6"></div>
      </div>
    </div>
  </div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('polModal')">Cancel</button><button class="btn btn-primary">Publish</button></div></form>
</div></div>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
try {
  var policyQuill = new Quill('#policyEditor', { modules: { toolbar: '#policyToolbar' }, theme: 'snow', placeholder: 'Type policy content...' });
  document.getElementById('polForm').addEventListener('submit', function() {
    document.getElementById('policyInput').value = policyQuill.root.innerHTML;
  });
} catch(e) {}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
