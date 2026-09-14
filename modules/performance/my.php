<?php
/**
 * ============================================================================
 * MY PERFORMANCE — employee view of assigned KPIs and review history
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Performance');
$empId=current_employee_id();
$year=date('Y');

$kpis=fetch_all("SELECT ek.*, kt.name, kt.description, kt.weight, kt.max_score
                 FROM employee_kpis ek JOIN kpi_templates kt ON kt.id=ek.kpi_template_id
                 WHERE ek.employee_id=? ORDER BY kt.weight DESC",[$empId]);
$reviews=fetch_all("SELECT pr.*, e.full_name reviewer FROM performance_reviews pr LEFT JOIN employees e ON e.id=pr.reviewer_id
                    WHERE pr.employee_id=? ORDER BY pr.id DESC",[$empId]);
$latest=$reviews[0] ?? null;
?>
<div class="page-head"><div><h1>My Performance</h1><div class="sub">Your KPIs, goals and performance reviews</div></div></div>

<?php if($latest): ?>
<div class="grid cols-3" style="margin-bottom:18px">
  <div class="card card-pad" style="text-align:center">
    <div class="muted small">Latest Overall Score</div>
    <div style="font-family:Oswald;font-size:40px;color:var(--primary)"><?= number_format($latest['overall_score'],0) ?>/100</div>
    <span class="badge badge-<?= $latest['rating']==='outstanding'||$latest['rating']==='exceeds'?'green':($latest['rating']==='meets'?'blue':'amber') ?>"><?= ucfirst(str_replace('_',' ',$latest['rating'])) ?></span>
  </div>
  <div class="card card-pad" style="grid-column:span 2">
    <h3 class="section-title" style="margin-bottom:8px">Latest Review (<?= e($latest['review_period']) ?>)</h3>
    <?php if($latest['achievements']): ?><p class="small"><strong>Achievements:</strong> <?= e($latest['achievements']) ?></p><?php endif; ?>
    <?php if($latest['areas_to_improve']): ?><p class="small"><strong>Areas to improve:</strong> <?= e($latest['areas_to_improve']) ?></p><?php endif; ?>
    <?php if($latest['reviewer_comments']): ?><p class="small muted"><i class="fa-solid fa-comment"></i> <?= e($latest['reviewer_comments']) ?></p><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid cols-2">
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px">My Assigned KPIs (<?= $year ?>)</h3>
    <?php if(!$kpis): echo '<div class="empty"><i class="fa-solid fa-bullseye"></i>No KPIs assigned yet</div>';
    else: foreach($kpis as $k): ?>
      <div style="padding:12px 0;border-bottom:1px solid var(--border)">
        <div class="flex between center"><div class="bold"><?= e($k['name']) ?></div><span class="badge badge-purple">Weight <?= $k['weight'] ?></span></div>
        <div class="muted small"><?= e($k['description']) ?></div>
        <?php if($k['target']): ?><div class="small" style="margin-top:4px"><i class="fa-solid fa-flag"></i> Target: <?= e($k['target']) ?></div><?php endif; ?>
        <div class="muted small">Period: <?= e($k['period']) ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px">Review History</h3>
    <?php if(!$reviews): echo '<div class="empty"><i class="fa-solid fa-clock-rotate-left"></i>No reviews yet</div>';
    else: foreach($reviews as $r): ?>
      <div class="flex between center" style="padding:12px 0;border-bottom:1px solid var(--border)">
        <div>
          <div class="bold"><?= e($r['review_period']) ?></div>
          <div class="muted small">By <?= e($r['reviewer']) ?> · <?= format_date($r['review_date']) ?></div>
        </div>
        <div class="right">
          <div class="bold"><?= number_format($r['overall_score'],0) ?>/100</div>
          <span class="badge badge-<?= $r['status']==='acknowledged'?'green':'amber' ?>"><?= ucfirst($r['status']) ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
