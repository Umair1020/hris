<?php
/**
 * ============================================================================
 * LEAVE APPROVALS — Admin/HR can approve/reject directly, managers approve team
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Leave Approvals', 'manager');
$role = current_role();
$tab = clean($_GET['tab'] ?? 'pending');

if (is_hr()) {
    $sql = "SELECT lr.*, e.full_name, e.employee_code, lt.name type_name, lt.color, lt.code,
                   m.full_name manager_name
            FROM leave_requests lr
            JOIN employees e ON e.id=lr.employee_id
            JOIN leave_types lt ON lt.id=lr.leave_type_id
            LEFT JOIN employees m ON m.id=e.manager_id";
    $where = " WHERE 1=1 ";
    if ($tab === 'pending') { $where .= " AND lr.status='pending'"; }
    elseif ($tab === 'emergency') { $where .= " AND lr.is_emergency=1 AND lr.status='pending'"; }
    elseif ($tab === 'history') { $where .= " AND lr.status IN('approved','rejected')"; }
    $sql .= $where . " ORDER BY lr.id DESC";
    $rows = fetch_all($sql, []);
} else {
    $params = [current_employee_id()];
    $where = " AND lr.manager_status='pending' AND lr.is_emergency=0 AND e.manager_id=? ";
    if ($tab === 'history') { $where = " AND lr.manager_status IN('approved','rejected') AND e.manager_id=? "; }
    $rows = fetch_all(
        "SELECT lr.*, e.full_name, e.employee_code, lt.name type_name, lt.color, lt.code
         FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id
         JOIN leave_types lt ON lt.id=lr.leave_type_id
         WHERE 1=1 $where ORDER BY lr.id DESC", $params);
}
?>
<div class="page-head"><div><h1>Leave Approvals</h1><div class="sub"><?= is_hr()?'Approve or reject leave requests':'Approve your team members\' leave requests' ?></div></div></div>

<div class="tabs">
  <a class="<?= $tab==='pending'?'active':'' ?>" href="?tab=pending">Pending</a>
  <?php if(is_hr()): ?><a class="<?= $tab==='emergency'?'active':'' ?>" href="?tab=emergency"><i class="fa-solid fa-bolt"></i> Emergency</a><?php endif; ?>
  <a class="<?= $tab==='history'?'active':'' ?>" href="?tab=history">History</a>
</div>

<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Approvals</th><th>Action</th></tr></thead>
      <tbody>
      <?php if(!$rows): echo '<tr><td colspan="7" class="empty"><i class="fa-solid fa-check-double"></i>No requests in this view</td></tr>';
      else: foreach($rows as $r):
        // FIX: Admin/HR can always act on pending leaves. Manager acts on their pending items.
        $canAct = ($r['status'] === 'pending');
        if (!is_hr()) {
            $canAct = ($r['manager_status'] === 'pending' && $r['status'] === 'pending');
        }
      ?>
        <tr>
          <td><div class="bold"><?= e($r['full_name']) ?></div><div class="muted small"><?= e($r['employee_code']) ?></div></td>
          <td><span class="badge" style="background:<?= e($r['color']) ?>20;color:<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?php if($r['is_emergency']): ?> <span class="badge badge-red"><i class="fa-solid fa-bolt"></i></span><?php endif; ?></td>
          <td class="small"><?= format_date($r['start_date'],'d M') ?> - <?= format_date($r['end_date'],'d M Y') ?></td>
          <td><?= $r['days'] ?></td>
          <td class="small muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['reason']) ?></td>
          <td class="small">
            <div>MGR: <span class="badge badge-<?= $r['manager_status']==='approved'?'green':($r['manager_status']==='rejected'?'red':'amber') ?>"><?= ucfirst($r['manager_status']) ?></span></div>
            <div style="margin-top:3px">HR: <span class="badge badge-<?= $r['hr_status']==='approved'?'green':($r['hr_status']==='rejected'?'red':'amber') ?>"><?= ucfirst($r['hr_status']) ?></span></div>
          </td>
          <td>
            <?php if($canAct): ?>
            <form method="post" action="approve.php" style="display:inline-flex;gap:4px">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="step" value="<?= is_hr()?'hr':'manager' ?>">
              <button name="decision" value="approve" class="btn btn-sm btn-success" data-confirm="Approve this leave?"><i class="fa-solid fa-check"></i></button>
              <button name="decision" value="reject" class="btn btn-sm btn-danger" data-confirm="Reject this leave?"><i class="fa-solid fa-xmark"></i></button>
            </form>
            <?php else: echo '<span class="muted small">—</span>'; endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
