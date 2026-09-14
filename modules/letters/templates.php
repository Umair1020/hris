<?php
/**
 * ============================================================================
 * LETTER TEMPLATES MANAGEMENT — HR can create/edit/delete templates
 * Supports: letters, certificates, warnings, appreciation, termination
 * Rich text editor, custom header/footer, A4 formatting
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['tpl_action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $data = [
            'name' => clean($_POST['name']),
            'code' => strtoupper(clean($_POST['code'])),
            'category' => clean($_POST['category']),
            'type' => clean($_POST['type'] ?? 'letter'),
            'subject' => clean($_POST['subject']),
            'body' => $_POST['body'] ?? '',
            'header_html' => $_POST['header_html'] ?? '',
            'footer_html' => $_POST['footer_html'] ?? '',
            'is_active' => 1,
        ];
        if ($action === 'add') {
            insert('letter_templates', $data);
            set_flash('success', 'Template created!');
        } else {
            update('letter_templates', $data, 'id = ?', [(int)$_POST['id']]);
            set_flash('success', 'Template updated!');
        }
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM letter_templates WHERE id=?")->execute([(int)$_POST['id']]);
        set_flash('success', 'Template deleted.');
    } elseif ($action === 'toggle') {
        $t = fetch_one("SELECT is_active FROM letter_templates WHERE id=?", [(int)$_POST['id']]);
        update('letter_templates', ['is_active' => $t['is_active'] ? 0 : 1], 'id=?', [(int)$_POST['id']]);
    }
    redirect(APP_URL . 'modules/letters/templates.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$editTpl = $editId ? fetch_one("SELECT * FROM letter_templates WHERE id=?", [$editId]) : null;
$templates = fetch_all("SELECT * FROM letter_templates ORDER BY type, name");
auth_header('Letter Templates');
$typeColors = ['letter'=>'blue','certificate'=>'purple','warning'=>'red','termination'=>'red','appreciation'=>'green'];
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-file-lines"></i> Letter &amp; Certificate Templates</h1>
    <div class="sub">Manage all document templates — letters, certificates, warnings, etc.</div></div>
  <?php if (!$editTpl): ?><button class="btn btn-primary" onclick="initModalEditor();openModal('tplModal')"><i class="fa-solid fa-plus"></i> Add Template</button><?php endif; ?>
</div>

<?php if ($editTpl): ?>
<div class="card card-pad" style="margin-bottom:18px">
  <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-pen"></i> Edit Template</h3>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="tpl_action" value="edit"><input type="hidden" name="id" value="<?= $editTpl['id'] ?>">
    <div class="grid cols-4" style="margin-bottom:12px">
      <div><label>Name *</label><input type="text" name="name" class="form-control" value="<?= e($editTpl['name']) ?>" required></div>
      <div><label>Type</label><select name="type" class="form-select">
        <?php foreach(['letter'=>'Letter','certificate'=>'Certificate','warning'=>'Warning Letter','termination'=>'Termination','appreciation'=>'Appreciation'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $editTpl['type']===$v?'selected':'' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></div>
      <div><label>Category</label><input type="text" name="category" class="form-control" value="<?= e($editTpl['category']) ?>"></div>
      <div><label>Code</label><input type="text" name="code" class="form-control" value="<?= e($editTpl['code']) ?>"></div>
    </div>
    <div style="margin-bottom:12px"><label>Subject</label><input type="text" name="subject" class="form-control" value="<?= e($editTpl['subject']) ?>"></div>

    <div style="margin-bottom:12px">
      <label>Header (logo, company info — appears on top of every letter)</label>
      <input type="hidden" name="header_html" id="headerInput">
      <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
        <div id="headerToolbar" style="border-bottom:1px solid #e0e0e0;padding:6px;display:flex;gap:4px;background:#f9f9fb">
          <button type="button" class="ql-bold" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-weight:bold">B</button>
          <button type="button" class="ql-italic" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-style:italic">I</button>
          <select class="ql-align" style="border:1px solid #ccc;border-radius:4px;padding:2px"><option value="center" selected>Center</option><option value="left">Left</option><option value="right">Right</option></select>
        </div>
        <div id="headerEditor" style="min-height:60px;padding:12px;font-size:13px"><?= $editTpl['header_html'] ?: '<p style="text-align:center"><strong>SPOTCOMM GLOBAL</strong><br>Outsource · Optimize · Thrive</p>' ?></div>
      </div>
    </div>

    <div style="margin-bottom:12px">
      <label>Letter Body <span class="muted small" style="text-transform:none">(type normally — use {{employee_name}} for auto-replace)</span></label>
      <input type="hidden" name="body" id="bodyInput">
      <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
        <div id="bodyToolbar" style="border-bottom:1px solid #e0e0e0;padding:6px;display:flex;gap:4px;flex-wrap:wrap;background:#f9f9fb">
          <select class="ql-size" style="border:1px solid #ccc;border-radius:4px;padding:2px"><option value="small">Small</option><option selected>Normal</option><option value="large">Large</option></select>
          <button type="button" class="ql-bold" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-weight:bold">B</button>
          <button type="button" class="ql-italic" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-style:italic">I</button>
          <button type="button" class="ql-underline" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;text-decoration:underline">U</button>
          <select class="ql-align" style="border:1px solid #ccc;border-radius:4px;padding:2px"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option><option value="justify">Justify</option></select>
          <button type="button" class="ql-list" value="bullet" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer">•</button>
        </div>
        <div id="bodyEditor" style="min-height:250px;padding:16px;font-size:14px;line-height:1.6"><?= $editTpl['body'] ?: '' ?></div>
      </div>
      <p class="muted small" style="margin-top:6px">Auto-replace: {{employee_name}}, {{designation}}, {{date}}, {{department}}, {{salary}}, {{joining_date}}, {{employee_code}}, {{company}}</p>
    </div>

    <div style="margin-bottom:12px">
      <label>Footer (signature, contact — appears at bottom of every letter)</label>
      <input type="hidden" name="footer_html" id="footerInput">
      <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
        <div id="footerToolbar" style="border-bottom:1px solid #e0e0e0;padding:6px;display:flex;gap:4px;background:#f9f9fb">
          <button type="button" class="ql-bold" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-weight:bold">B</button>
          <select class="ql-align" style="border:1px solid #ccc;border-radius:4px;padding:2px"><option value="left" selected>Left</option><option value="center">Center</option></select>
        </div>
        <div id="footerEditor" style="min-height:60px;padding:12px;font-size:13px"><?= $editTpl['footer_html'] ?: '<p>For Spotcomm Global<br>Human Resources Department<br>hr@spotcomm.pk</p>' ?></div>
      </div>
    </div>

    <button class="btn btn-primary"><i class="fa-solid fa-save"></i> Update Template</button>
    <a href="<?= url('modules/letters/templates.php') ?>" class="btn btn-light">Cancel</a>
  </form>
</div>
<?php if ($editTpl): ?>
<script>
var he=new Quill('#headerEditor',{modules:{toolbar:'#headerToolbar'},theme:'snow'});
var be=new Quill('#bodyEditor',{modules:{toolbar:'#bodyToolbar'},theme:'snow'});
var fe=new Quill('#footerEditor',{modules:{toolbar:'#footerToolbar'},theme:'snow'});
document.querySelector('form').addEventListener('submit',function(){
  document.getElementById('headerInput').value=he.root.innerHTML;
  document.getElementById('bodyInput').value=be.root.innerHTML;
  document.getElementById('footerInput').value=fe.root.innerHTML;
});
</script>
<?php endif; ?>

<?php endif; ?>

<!-- Quill Rich Text Editor (ALWAYS loaded, needed for both Add and Edit) -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

<div class="card card-pad">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Name</th><th>Type</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
      <tr>
        <td class="bold"><?= e($t['name']) ?></td>
        <td><span class="badge badge-<?= $typeColors[$t['type']] ?? 'gray' ?>"><?= ucfirst($t['type']) ?></span></td>
        <td class="small"><?= e($t['category']) ?></td>
        <td><span class="badge badge-<?= $t['is_active']?'green':'gray' ?>"><?= $t['is_active']?'Active':'Inactive' ?></span></td>
        <td>
          <a href="<?= url('modules/letters/templates.php?edit='.$t['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-pen"></i></a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="tpl_action" value="toggle"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-sm btn-light"><i class="fa-solid fa-toggle-<?= $t['is_active']?'on':'off' ?>"></i></button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="tpl_action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn-sm btn-light" data-confirm="Delete?"><i class="fa-solid fa-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Add Template Modal -->
<div class="modal-bg" id="tplModal"><div class="modal" style="max-width:680px"><div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Add Template</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('tplModal')"></i></div>
<form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="tpl_action" value="add"><input type="hidden" name="body" id="mBody"><input type="hidden" name="header_html" id="mHeader"><input type="hidden" name="footer_html" id="mFooter">
  <div class="grid cols-2">
    <div><label>Name *</label><input type="text" name="name" class="form-control" placeholder="e.g. Warning Letter" required></div>
    <div><label>Type</label><select name="type" class="form-select">
      <option value="letter">Letter</option>
      <option value="certificate">Certificate</option>
      <option value="warning">Warning Letter</option>
      <option value="termination">Termination</option>
      <option value="appreciation">Appreciation</option>
    </select></div>
  </div>
  <div class="grid cols-2" style="margin-top:10px">
    <div><label>Category</label><input type="text" name="category" class="form-control" value="general"></div>
    <div><label>Subject</label><input type="text" name="subject" class="form-control" placeholder="Letter subject"></div>
  </div>
  <div style="margin-top:12px">
    <label>Letter Body <span class="muted small" style="text-transform:none">(type normally)</span></label>
    <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
      <div id="mBodyToolbar" style="border-bottom:1px solid #e0e0e0;padding:6px;display:flex;gap:4px;flex-wrap:wrap;background:#f9f9fb">
        <button type="button" class="ql-bold" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-weight:bold">B</button>
        <button type="button" class="ql-italic" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-style:italic">I</button>
        <button type="button" class="ql-underline" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;text-decoration:underline">U</button>
        <select class="ql-align" style="border:1px solid #ccc;border-radius:4px;padding:2px"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
      </div>
      <div id="mBodyEditor" style="min-height:200px;padding:16px;font-size:14px;line-height:1.6"></div>
    </div>
    <p class="muted small" style="margin-top:6px">Use {{employee_name}}, {{designation}}, {{date}}, {{department}}, {{salary}}, {{joining_date}} — auto-replaced when issued</p>
  </div>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('tplModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Create</button></div></form>
</div></div>
<script>
var modalQuill=null;
function initModalEditor(){
  if(!modalQuill){
    modalQuill=new Quill('#mBodyEditor',{modules:{toolbar:'#mBodyToolbar'},theme:'snow',placeholder:'Type letter content...'});
    document.querySelector('#tplModal form').addEventListener('submit',function(){
      document.getElementById('mBody').value=modalQuill.root.innerHTML;
    });
  }
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
