<?php
/**
 * ============================================================================
 * LETTER REQUESTS — HR approves & generates letters with variable substitution.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Letter Requests','hr');

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $id=(int)$_POST['id']; $action=$_POST['action']??'';
  $gl=fetch_one("SELECT gl.*, e.* FROM generated_letters gl JOIN employees e ON e.id=gl.employee_id WHERE gl.id=?",[$id]);
  if(!$gl){set_flash('danger','Request not found.');redirect(APP_URL.'modules/letters/approvals.php');}

  if($action==='generate'){
    $body=$gl['body'];
    $vars=['employee_name'=>$gl['full_name'],'designation'=>$gl['designation']?:'',
           'department'=>department_name($gl['department_id']),
           'joining_date'=>format_date($gl['joining_date']),'date'=>format_date(today()),
           'employee_code'=>$gl['employee_code'],'salary'=>money($gl['basic_salary']),
           'confirmation_date'=>format_date(today()),'start_date'=>format_date($gl['joining_date']),
           'end_date'=>format_date(today())];
    foreach($vars as $k=>$v){ $body=str_replace('{{'.$k.'}}',$v,$body); }
    $custom=json_decode($_POST['custom_vars']??'{}',true);
    if(is_array($custom)) foreach($custom as $k=>$v){ $body=str_replace('{{'.$k.'}}',e($v),$body); }
    update('generated_letters',['body'=>$body,'status'=>'generated','approved_by'=>current_user_id()],'id=?',[$id]);
    log_activity('Letter Generated',$gl['reference_no']);
    set_flash('success','Letter generated successfully.');
  } elseif($action==='reject'){
    update('generated_letters',['status'=>'rejected','approved_by'=>current_user_id()],'id=?',[$id]);
    set_flash('success','Letter request rejected.');
  }
  redirect(APP_URL.'modules/letters/approvals.php');
}

$reqs=fetch_all("SELECT gl.*, lt.name tpl_name, lt.variables, e.full_name emp_name, e.employee_code FROM generated_letters gl JOIN letter_templates lt ON lt.id=gl.template_id JOIN employees e ON e.id=gl.employee_id ORDER BY gl.id DESC");
?>
<div class="page-head"><div><h1>Letter Requests</h1><div class="sub">Review, approve &amp; generate company letters</div></div></div>

<div class="card card-pad">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Reference</th><th>Employee</th><th>Letter</th><th>Requested</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(!$reqs): echo '<tr><td colspan="6" class="empty"><i class="fa-solid fa-inbox"></i>No letter requests</td></tr>'; endif;
    foreach($reqs as $r): ?>
      <tr>
        <td class="small muted"><?= e($r['reference_no']) ?></td>
        <td><div class="bold"><?= e($r['emp_name']) ?></div><div class="muted small"><?= e($r['employee_code']) ?></div></td>
        <td><?= e($r['tpl_name']) ?></td>
        <td class="small"><?= format_date($r['created_at']) ?></td>
        <td><span class="badge badge-<?= $r['status']==='generated'?'green':($r['status']==='rejected'?'red':'amber') ?>"><?= ucfirst($r['status']) ?></span></td>
        <td>
          <?php if($r['status']==='requested'): ?>
          <button class="btn btn-sm btn-primary" onclick='openGen(<?= json_encode($r) ?>)'><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
          <?php elseif($r['status']==='generated'): ?>
          <a href="<?= url('modules/letters/view.php?id='.$r['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-eye"></i> View</a>
          <?php else: echo '<span class="muted small">—</span>'; endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="modal-bg" id="genModal">
  <div class="modal" style="max-width:600px">
    <div class="modal-head"><h3><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Letter</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('genModal')"></i></div>
    <form method="post"><div class="modal-body"><?= csrf_field() ?>
      <input type="hidden" name="id" id="gId"><input type="hidden" name="action" value="generate">
      <input type="hidden" name="custom_vars" id="gCustom">
      <p class="muted small">Fill any missing placeholders for <strong id="gEmp"></strong> requesting <strong id="gTpl"></strong>:</p>
      <div id="gVars"></div>
    </div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('genModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Generate Letter</button></div></form>
  </div>
</div>
<script>
function openGen(r){
  document.getElementById('gId').value=r.id;
  document.getElementById('gEmp').textContent=r.emp_name;
  document.getElementById('gTpl').textContent=r.tpl_name;
  var vars=(r.variables||'').split(',').filter(v=>v.trim());
  var html=''; var custom={};
  vars.forEach(function(v){ v=v.trim(); if(!v) return;
    html+='<div style="margin-bottom:8px"><label>'+v+'</label><input type="text" class="form-control" data-var="'+v+'"></div>';
  });
  document.getElementById('gVars').innerHTML=html||'<p class="muted small">No extra variables needed.</p>';
  // store values on submit
  document.querySelector('#genModal form').onsubmit=function(){
    var c={}; document.querySelectorAll('#gVars input').forEach(function(i){c[i.dataset.var]=i.value;});
    document.getElementById('gCustom').value=JSON.stringify(c);
  };
  openModal('genModal');
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
