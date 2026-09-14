<?php
/**
 * ============================================================================
 * PERFORMANCE MANAGEMENT — HR & Managers
 *  - Manage KPI templates (HR, per department)
 *  - Assign KPIs to employees
 *  - Conduct / view performance reviews
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Performance Management','manager');
$role=current_role();

// handle KPI template add (HR)
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_template' && is_hr()){
  verify_csrf();
  insert('kpi_templates',['department_id'=>(int)$_POST['department_id']?:null,'name'=>clean($_POST['name']),
    'description'=>clean($_POST['description']),'weight'=>(float)$_POST['weight'],'max_score'=>(int)$_POST['max_score']]);
  set_flash('success','KPI template created.');
  redirect(APP_URL.'modules/performance/index.php');
}

// employees list for the manager
if($role==='manager'){
  $emps=fetch_all("SELECT e.id,e.full_name,e.employee_code,d.name dept FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.manager_id=? AND e.status='Active'",[current_employee_id()]);
} else {
  $emps=fetch_all("SELECT e.id,e.full_name,e.employee_code,d.name dept FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.status='Active'");
}
$kpis=fetch_all("SELECT * FROM kpi_templates ORDER BY department_id,name");
$reviews=fetch_all("SELECT pr.*, e.full_name emp_name, rv.full_name reviewer FROM performance_reviews pr JOIN employees e ON e.id=pr.employee_id LEFT JOIN employees rv ON rv.id=pr.reviewer_id ORDER BY pr.id DESC LIMIT 20");
?>
<div class="page-head">
  <div><h1>Performance Management</h1><div class="sub">KPI templates · Assign goals · Conduct performance reviews</div></div>
  <?php if(is_hr()): ?>
  <div class="flex gap">
    <button class="btn btn-outline" onclick="openModal('kpiModal')"><i class="fa-solid fa-plus"></i> KPI Template</button>
    <button class="btn btn-primary" onclick="openModal('assignModal')"><i class="fa-solid fa-user-tag"></i> Assign KPIs</button>
  </div>
  <?php endif; ?>
</div>

<div class="tabs">
  <a class="active" href="#" onclick="return false">Team &amp; Reviews</a>
</div>

<div class="card card-pad">
  <h3 class="section-title" style="margin-bottom:14px"><?= $role==='manager'?'My Team':'All Employees' ?> — Performance Actions</h3>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Code</th><th>Dept</th><th>KPIs</th><th>Last Review</th><th>Action</th></tr></thead>
      <tbody>
      <?php if(!$emps): echo '<tr><td colspan="6" class="empty">No employees</td></tr>'; endif;
      foreach($emps as $e):
        $kpiCount=(int)fetch_one("SELECT COUNT(*) c FROM employee_kpis WHERE employee_id=?",[$e['id']])['c'];
        $lr=fetch_one("SELECT overall_score,review_period FROM performance_reviews WHERE employee_id=? ORDER BY id DESC LIMIT 1",[$e['id']]);
      ?>
        <tr>
          <td class="bold"><?= e($e['full_name']) ?></td>
          <td class="small muted"><?= e($e['employee_code']) ?></td>
          <td class="small"><?= e($e['dept']) ?></td>
          <td><span class="badge badge-purple"><?= $kpiCount ?> KPIs</span></td>
          <td class="small"><?= $lr?(number_format($lr['overall_score'],0).'/100 · '.$lr['review_period']):'<span class="muted">None</span>' ?></td>
          <td><a href="<?= url('modules/performance/review.php?emp='.$e['id']) ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-clipboard-check"></i> Review</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px">Recent Reviews</h3>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Employee</th><th>Period</th><th>Reviewer</th><th>Score</th><th>Rating</th><th>Status</th></tr></thead>
    <tbody>
    <?php if(!$reviews): echo '<tr><td colspan="6" class="empty">No reviews yet</td></tr>'; endif;
    foreach($reviews as $r): ?>
      <tr>
        <td class="bold"><?= e($r['emp_name']) ?></td>
        <td><?= e($r['review_period']) ?></td>
        <td class="small"><?= e($r['reviewer']) ?></td>
        <td class="bold"><?= number_format($r['overall_score'],0) ?>/100</td>
        <td><span class="badge badge-blue"><?= ucfirst(str_replace('_',' ',$r['rating'])) ?></span></td>
        <td><span class="badge badge-<?= $r['status']==='acknowledged'?'green':'amber' ?>"><?= ucfirst($r['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- KPI template modal -->
<?php if(is_hr()): ?>
<div class="modal-bg" id="kpiModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-plus"></i> New KPI Template</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('kpiModal')"></i></div>
    <form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="add_template">
      <div class="grid cols-2">
        <div><label>KPI Name</label><input type="text" name="name" class="form-control" required></div>
        <div><label>Department (optional)</label><select name="department_id" class="form-select"><option value="0">All Departments</option><?php foreach(departments_list() as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Weight</label><input type="number" name="weight" class="form-control" value="10" step="0.5"></div>
        <div><label>Max Score</label><input type="number" name="max_score" class="form-control" value="100"></div>
      </div>
      <div style="margin-top:12px"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('kpiModal')">Cancel</button><button class="btn btn-primary">Create</button></div></form>
  </div>
</div>

<!-- Assign KPI modal -->
<div class="modal-bg" id="assignModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-user-tag"></i> Assign KPIs to Employee</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('assignModal')"></i></div>
    <form method="post" action="assign.php"><div class="modal-body"><?= csrf_field() ?>
      <div style="margin-bottom:12px"><label>Employee</label><select name="employee_id" class="form-select" required><?php foreach($emps as $e): ?><option value="<?= $e['id'] ?>"><?= e($e['employee_code'].' — '.$e['full_name']) ?></option><?php endforeach; ?></select></div>
      <label>Select KPIs to assign</label>
      <div style="max-height:200px;overflow:auto;border:1px solid var(--border);border-radius:10px;padding:8px">
        <?php foreach($kpis as $k): ?>
        <label style="display:block;padding:6px"><input type="checkbox" name="kpi_ids[]" value="<?= $k['id'] ?>"> <?= e($k['name']) ?> <span class="muted small">(weight <?= $k['weight'] ?>)</span></label>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px"><label>Period</label><input type="text" name="period" class="form-control" value="<?= date('Y') ?>-H2" placeholder="e.g. 2026-H2 or 2026-Q3"></div>
    </div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('assignModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Assign</button></div></form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
