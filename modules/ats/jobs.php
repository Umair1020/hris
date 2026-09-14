<?php
/**
 * ============================================================================
 * JOB POSTINGS (ATS) — internal/portal job board + HR management
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Job Postings');

// HR create job
if ($_SERVER['REQUEST_METHOD']==='POST' && is_hr()) {
    verify_csrf();
    $jobAction = $_POST['job_action'] ?? '';
    
    if ($jobAction === 'delete') {
        $jobId = (int)$_POST['job_id'];
        db()->prepare("DELETE FROM applicants WHERE job_posting_id=?")->execute([$jobId]);
        db()->prepare("DELETE FROM job_postings WHERE id=?")->execute([$jobId]);
        log_activity('Job Deleted', "Job #$jobId");
        set_flash('success','Job posting deleted.');
        redirect(APP_URL.'modules/ats/jobs.php');
    }
    
    insert('job_postings', [
        'title'=>clean($_POST['title']),'department_id'=>(int)$_POST['department_id'],
        'description'=>clean($_POST['description']),'requirements'=>clean($_POST['requirements']),
        'keywords'=>strtolower(clean($_POST['keywords'])),'location'=>clean($_POST['location']),
        'employment_type'=>clean($_POST['employment_type']),'openings'=>(int)$_POST['openings'],
        'status'=>'open','posted_date'=>today(),'closing_date'=>clean($_POST['closing_date'])?:null,
    ]);
    log_activity('Job Posted', clean($_POST['title']));
    set_flash('success','Job posting published.');
    redirect(APP_URL.'modules/ats/jobs.php');
}

$q = clean($_GET['q'] ?? '');
$where = $q ? "WHERE title LIKE ? OR keywords LIKE ?" : '';
$params = $q ? ["%$q%","%$q%"] : [];
$jobs = fetch_all("SELECT j.*, d.name dept, (SELECT COUNT(*) FROM applicants a WHERE a.job_posting_id=j.id) applicants
                   FROM job_postings j LEFT JOIN departments d ON d.id=j.department_id
                   $where ORDER BY j.status DESC, j.id DESC", $params);
?>
<div class="page-head">
  <div><h1>Job Openings</h1><div class="sub"><?= is_hr()?'Post &amp; manage job openings':'Current openings at Spotcomm Global' ?></div></div>
  <?php if(is_hr()): ?><button class="btn btn-primary" onclick="openModal('jobModal')"><i class="fa-solid fa-plus"></i> Post a Job</button><?php endif; ?>
</div>

<div class="grid cols-3">
  <?php if(!$jobs): echo '<div class="card card-pad empty"><i class="fa-solid fa-briefcase"></i>No job openings right now. Check back soon.</div>';
  else: foreach($jobs as $j): ?>
  <div class="card card-pad">
    <div class="flex between center" style="margin-bottom:8px">
      <span class="badge badge-<?= $j['status']==='open'?'green':'gray' ?>"><?= ucfirst($j['status']) ?></span>
      <?php if(is_hr()): ?><span class="muted small"><i class="fa-solid fa-users"></i> <?= $j['applicants'] ?> applicants</span><?php endif; ?>
    </div>
    <h3 style="margin:6px 0;font-size:18px"><?= e($j['title']) ?></h3>
    <div class="muted small" style="margin-bottom:10px">
      <i class="fa-solid fa-building"></i> <?= e($j['dept']??'General') ?> ·
      <i class="fa-solid fa-location-dot"></i> <?= e($j['location']?:'On-site') ?> ·
      <i class="fa-solid fa-clock"></i> <?= e($j['employment_type']) ?>
    </div>
    <p class="small" style="max-height:60px;overflow:hidden"><?= e(substr($j['description'],0,160)) ?>...</p>
    <?php if($j['keywords']): ?>
    <div class="flex wrap gap" style="margin:10px 0">
      <?php foreach(array_slice(explode(',',$j['keywords']),0,5) as $kw): $kw=trim($kw); if(!$kw)continue;?>
        <span class="badge badge-purple"><?= e($kw) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="flex between center" style="margin-top:12px">
      <span class="muted small">Posted <?= format_date($j['posted_date'],'d M Y') ?></span>
      <div class="flex gap">
        <a href="<?= url('modules/ats/apply.php?job='.$j['id']) ?>" class="btn btn-sm btn-primary"><i class="fa-solid fa-paper-plane"></i> Apply</a>
        <?php if(is_hr()): ?>
          <a href="<?= url('modules/ats/applicants.php?job='.$j['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-eye"></i> View</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="job_action" value="delete"><input type="hidden" name="job_id" value="<?= $j['id'] ?>"><button class="btn btn-sm btn-light" data-confirm="Delete this job posting and all its applicants?"><i class="fa-solid fa-trash"></i></button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Post job modal -->
<?php if(is_hr()): ?>
<div class="modal-bg" id="jobModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Post New Job Opening</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('jobModal')"></i></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <div class="grid cols-2">
          <div><label>Job Title</label><input type="text" name="title" class="form-control" required></div>
          <div><label>Department</label><select name="department_id" class="form-select"><?php foreach(departments_list() as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Location</label><input type="text" name="location" class="form-control" placeholder="e.g. Karachi"></div>
          <div><label>Employment Type</label><select name="employment_type" class="form-select"><option>Full-Time</option><option>Part-Time</option><option>Contract</option><option>Internship</option></select></div>
          <div><label>Number of Openings</label><input type="number" name="openings" class="form-control" value="1" min="1"></div>
          <div><label>Closing Date</label><input type="date" name="closing_date" class="form-control"></div>
        </div>
        <div style="margin-top:12px"><label>Description</label><textarea name="description" class="form-control" rows="3" required></textarea></div>
        <div style="margin-top:12px"><label>Requirements</label><textarea name="requirements" class="form-control" rows="3"></textarea></div>
        <div style="margin-top:12px"><label>Filter Keywords <span class="muted">(comma-separated — used to auto-score resumes)</span></label><input type="text" name="keywords" class="form-control" placeholder="e.g. php, mysql, networking, ccna, laravel"></div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('jobModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Publish Job</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
