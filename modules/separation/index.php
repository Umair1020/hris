<?php
/**
 * ============================================================================
 * SEPARATION MANAGEMENT — HR & managers
 *  - View resignation / separation requests
 *  - Manage clearance checklist (HR, IT, Finance, Admin)
 *  - Complete exit process
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Separation Management','manager');
$role=current_role();

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $action=$_POST['action']??'';
  if($action==='clear_item'){
    update('clearance_items',['status'=>'cleared','remarks'=>clean($_POST['remarks']??''),'cleared_at'=>now()],'id=?',[(int)$_POST['item_id']]);
    set_flash('success','Clearance item marked as cleared.');
  } elseif($action==='add_item'){
    $sepId=(int)$_POST['separation_id'];
    insert('clearance_items',['separation_id'=>$sepId,'item_name'=>clean($_POST['item_name']),'department'=>clean($_POST['department']),'responsible_user'=>clean($_POST['responsible_user'])]);
    set_flash('success','Clearance item added.');
  } elseif($action==='complete'){
    $sepId=(int)$_POST['separation_id'];
    $pending=(int)fetch_one("SELECT COUNT(*) c FROM clearance_items WHERE separation_id=? AND status!='cleared'",[$sepId])['c'];
    if($pending>0){ set_flash('warning','Cannot complete — clearance has pending items.'); }
    else {
      update('separation_requests',['status'=>'completed','exit_interview'=>clean($_POST['exit_interview']??'')],'id=?',[$sepId]);
      update('employees',['status'=>'Resigned'],'id=?',[(int)fetch_one("SELECT employee_id FROM separation_requests WHERE id=?",[$sepId])['employee_id']]);
      set_flash('success','Separation completed. Employee status updated to Resigned.');
    }
  } elseif($action==='ack'){
    update('separation_requests',['status'=>'clearance_in_progress'],'id=?',[(int)$_POST['separation_id']]);
    set_flash('success','Separation acknowledged. Clearance checklist initiated.');
  }
  redirect(APP_URL.'modules/separation/index.php');
}

$view=clean($_GET['view']??'list');
if($view==='detail'){
  $sepId=(int)$_GET['id'];
  $sep=fetch_one("SELECT s.*, e.full_name emp_name, e.employee_code, e.designation, e.joining_date FROM separation_requests s JOIN employees e ON e.id=s.employee_id WHERE s.id=?",[$sepId]);
  if(!$sep){set_flash('danger','Not found.');redirect(APP_URL.'modules/separation/index.php');}
  $items=fetch_all("SELECT * FROM clearance_items WHERE separation_id=? ORDER BY id",[$sepId]);
  auth_header('Separation Detail');
  ?>
  <div class="page-head"><div><h1>Separation Detail</h1><div class="sub"><?= e($sep['emp_name']) ?></div></div>
    <a href="<?= url('modules/separation/index.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a></div>

  <div class="grid cols-2">
    <div class="card card-pad">
      <h3 class="section-title" style="margin-bottom:12px">Exit Information</h3>
      <div class="kv"><span class="k">Employee</span><span class="v"><?= e($sep['emp_name']) ?> (<?= e($sep['employee_code']) ?>)</span></div>
      <div class="kv"><span class="k">Designation</span><span class="v"><?= e($sep['designation']) ?></span></div>
      <div class="kv"><span class="k">Type</span><span class="v"><?= ucfirst(str_replace('_',' ',$sep['separation_type'])) ?></span></div>
      <div class="kv"><span class="k">Notice Date</span><span class="v"><?= format_date($sep['notice_date']) ?></span></div>
      <div class="kv"><span class="k">Last Working Day</span><span class="v"><?= format_date($sep['last_working_day']) ?></span></div>
      <div class="kv"><span class="k">Status</span><span class="v"><span class="badge badge-amber"><?= ucfirst(str_replace('_',' ',$sep['status'])) ?></span></span></div>
      <div class="divider"></div>
      <div class="bold small" style="margin-bottom:6px">Reason:</div>
      <p class="small"><?= nl2br(e($sep['reason'])) ?></p>
      <div class="bold small" style="margin:10px 0 6px">Handover Notes:</div>
      <p class="small muted"><?= nl2br(e($sep['handover_notes']?:'No handover notes provided.')) ?></p>
    </div>
    <div class="card card-pad">
      <div class="flex between center" style="margin-bottom:12px"><h3 class="section-title">Clearance Checklist</h3>
        <?php if($sep['status']==='acknowledged'||$sep['status']==='submitted'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ack"><input type="hidden" name="separation_id" value="<?= $sepId ?>"><button class="btn btn-sm btn-primary"><i class="fa-solid fa-play"></i> Start Clearance</button></form>
        <?php endif; ?>
      </div>
      <?php if($items): foreach($items as $it): ?>
        <div class="flex between center" style="padding:10px 0;border-bottom:1px solid var(--border)">
          <div>
            <div class="bold small"><?= e($it['item_name']) ?> <span class="muted">(<?= e($it['department']) ?>)</span></div>
            <div class="muted small"><?= e($it['responsible_user']) ?> · <?= $it['remarks']?e($it['remarks']):'—' ?></div>
          </div>
          <?php if($it['status']==='cleared'): ?><span class="badge badge-green"><i class="fa-solid fa-check"></i> Cleared</span>
          <?php else: ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_item"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
            <input type="text" name="remarks" placeholder="remarks" class="form-control" style="width:120px;display:inline-block;margin-right:6px;font-size:12px">
            <button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i></button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; else: echo '<p class="muted small">No clearance items yet.</p>'; endif; ?>

      <form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="add_item"><input type="hidden" name="separation_id" value="<?= $sepId ?>">
        <div class="flex gap" style="gap:6px">
          <input type="text" name="item_name" class="form-control" placeholder="Item (e.g. Laptop return)" required>
          <input type="text" name="department" class="form-control" placeholder="Dept" style="max-width:100px">
          <input type="text" name="responsible_user" class="form-control" placeholder="Responsible" style="max-width:120px">
          <button class="btn btn-sm btn-light"><i class="fa-solid fa-plus"></i></button>
        </div>
      </form>

      <?php if($sep['status']==='clearance_in_progress'): ?>
      <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="separation_id" value="<?= $sepId ?>">
        <label>Exit Interview Notes (optional)</label>
        <textarea name="exit_interview" class="form-control" rows="2" style="margin-bottom:10px"></textarea>
        <button class="btn btn-success btn-block" data-confirm="Complete this separation and mark employee as Resigned?"><i class="fa-solid fa-flag-checkered"></i> Complete Separation</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php require __DIR__ . '/../../includes/footer.php'; exit;
}

// list view
$where=$role==='manager'?' AND e.manager_id=?':'';
$params=$role==='manager'?[current_employee_id()]:[];
$reqs=fetch_all("SELECT s.*, e.full_name emp_name, e.employee_code FROM separation_requests s JOIN employees e ON e.id=s.employee_id WHERE 1=1 $where ORDER BY s.id DESC",$params);
?>
<div class="page-head"><div><h1>Separation Management</h1><div class="sub">Resignations, exit handovers &amp; clearance checklists</div></div></div>

<div class="card card-pad">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Employee</th><th>Code</th><th>Type</th><th>Notice Date</th><th>Last Working Day</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php if(!$reqs): echo '<tr><td colspan="7" class="empty"><i class="fa-solid fa-door-open"></i>No separation requests</td></tr>'; endif;
    foreach($reqs as $r): ?>
      <tr>
        <td class="bold"><?= e($r['emp_name']) ?></td>
        <td class="small muted"><?= e($r['employee_code']) ?></td>
        <td><span class="badge badge-blue"><?= ucfirst(str_replace('_',' ',$r['separation_type'])) ?></span></td>
        <td class="small"><?= format_date($r['notice_date']) ?></td>
        <td class="small"><?= format_date($r['last_working_day']) ?></td>
        <td><span class="badge badge-<?= $r['status']==='completed'?'green':($r['status']==='submitted'||$r['status']==='clearance_in_progress'?'amber':'gray') ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
        <td><a href="<?= url('modules/separation/index.php?view=detail&id='.$r['id']) ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-eye"></i> Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
