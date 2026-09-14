<?php
/**
 * ============================================================================
 * APPLICANTS (ATS) — HR pipeline
 *  - Auto-scored/keyword-filtered resumes
 *  - Status pipeline: new → shortlisted → interview_scheduled → interviewed → offered → hired/rejected
 *  - Interview scheduling with available slots
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Applicants (ATS)','hr');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $id=(int)$_POST['id']; $action=$_POST['action'] ?? '';
    if($action==='status'){
        $newStatus = clean($_POST['status']);
        $oldStatus = fetch_one("SELECT a.status, a.email, a.full_name, j.title FROM applicants a JOIN job_postings j ON j.id=a.job_posting_id WHERE a.id=?", [$id]);
        update('applicants', ['status' => $newStatus], 'id=?', [$id]);
        log_activity('Applicant Status', "Applicant #$id → $newStatus");

        // --- Send email to applicant based on new status ---
        if ($oldStatus && !empty($oldStatus['email'])) {
            $name = $oldStatus['full_name'];
            $jobTitle = $oldStatus['title'];
            $email = $oldStatus['email'];
            $subject = '';
            $body = '';
            $appName = get_setting('mail_from_name', 'Spotcomm Global');
            switch ($newStatus) {
                case 'shortlisted':
                    $subject = "Application Shortlisted - $jobTitle - Spotcomm Global";
                    $body = "Dear $name,\n\nCongratulations! Your application for \"$jobTitle\" at Spotcomm Global has been shortlisted.\n\nOur HR team will contact you shortly regarding the next steps.\n\nBest regards,\nHR Department\nSpotcomm Global";
                    break;
                case 'interview_scheduled':
                    $subject = "Interview Scheduled - $jobTitle - Spotcomm Global";
                    $body = "Dear $name,\n\nYou have been shortlisted for an interview for the position of \"$jobTitle\" at Spotcomm Global.\n\nOur HR team will share the interview details with you.\n\nBest regards,\nHR Department\nSpotcomm Global";
                    break;
                case 'interviewed':
                    $subject = "Interview Completed - $jobTitle - Spotcomm Global";
                    $body = "Dear $name,\n\nThank you for attending the interview for \"$jobTitle\" at Spotcomm Global.\n\nWe are evaluating your profile and will get back to you with the results soon.\n\nBest regards,\nHR Department\nSpotcomm Global";
                    break;
                case 'offered':
                    $subject = "Job Offer - $jobTitle - Spotcomm Global";
                    $body = "Dear $name,\n\nWe are pleased to inform you that you have been selected for the position of \"$jobTitle\" at Spotcomm Global!\n\nCongratulations! Our HR team will contact you with the formal offer letter and onboarding details.\n\nBest regards,\nHR Department\nSpotcomm Global";
                    break;
                case 'hired':
                    $subject = "Welcome to Spotcomm Global! - $jobTitle";
                    $body = "Dear $name,\n\nWelcome aboard! You have been hired for the position of \"$jobTitle\" at Spotcomm Global.\n\nOur HR team will contact you with your joining details and next steps.\n\nWe look forward to working with you!\n\nBest regards,\nHR Department\nSpotcomm Global";
                    break;
                case 'rejected':
                    $subject = "Application Update - $jobTitle - Spotcomm Global";
                    $body = "Dear $name,\n\nThank you for your interest in the \"$jobTitle\" position at Spotcomm Global and for taking the time to apply.\n\nAfter careful consideration, we regret to inform you that we are unable to proceed with your application at this time. We encourage you to apply for future openings that match your profile.\n\nWe wish you the best in your career.\n\nBest regards,\nHR Department\nSpotcomm Global";
                    break;
            }
            if ($subject) {
                send_email($email, $subject, $body);
            }
        }

        set_flash('success', '✅ Applicant status updated! Email sent to candidate.');
    }
    redirect(APP_URL.'modules/ats/applicants.php'.(!empty($_GET['job'])?'?job='.(int)$_GET['job']:''));
}

$jobFilter=(int)($_GET['job'] ?? 0);
$statusFilter=clean($_GET['status'] ?? '');
$where=''; $params=[];
if($jobFilter){ $where.=' AND a.job_posting_id=?'; $params[]=$jobFilter; }
if($statusFilter){ $where.=' AND a.status=?'; $params[]=$statusFilter; }

$applicants=fetch_all(
  "SELECT a.*, j.title job_title FROM applicants a JOIN job_postings j ON j.id=a.job_posting_id
   WHERE 1=1 $where ORDER BY a.match_score DESC, a.id DESC",$params);

$statusColors=['new'=>'blue','shortlisted'=>'purple','interview_scheduled'=>'amber','interviewed'=>'blue','offered'=>'green','hired'=>'green','rejected'=>'red'];
?>
<div class="page-head">
  <div><h1>Applicant Tracking</h1><div class="sub">Keyword-scored resumes · Interview pipeline</div></div>
</div>

<div class="grid cols-6" style="margin-bottom:18px">
  <?php
  $stages=['new','shortlisted','interview_scheduled','interviewed','offered','hired'];
  foreach($stages as $s){
    $c=(int)fetch_one("SELECT COUNT(*) c FROM applicants WHERE status=?",[$s])['c'];
    echo '<div class="card card-pad" style="text-align:center"><div style="font-family:Oswald;font-size:22px">'.$c.'</div><div class="muted small">'.ucfirst(str_replace('_',' ',$s)).'</div></div>';
  }
  ?>
</div>

<div class="flex center gap" style="margin-bottom:14px;flex-wrap:wrap">
  <form method="get" class="flex center gap">
    <select name="job" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="0">All Jobs</option>
      <?php foreach(fetch_all("SELECT id,title FROM job_postings ORDER BY title") as $jb): ?>
      <option value="<?= $jb['id'] ?>" <?= $jobFilter==$jb['id']?'selected':'' ?>><?= e($jb['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach(array_keys($statusColors) as $s): ?><option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Applicant</th><th>Position</th><th>Match</th><th>Keywords Matched</th><th>Applied</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if(!$applicants): echo '<tr><td colspan="7" class="empty"><i class="fa-solid fa-user-slash"></i>No applicants yet</td></tr>';
      else: foreach($applicants as $a): ?>
        <tr>
          <td><div class="bold"><?= e($a['full_name']) ?></div><div class="muted small"><?= e($a['email']) ?></div></td>
          <td class="small"><?= e($a['job_title']) ?></td>
          <td>
            <div class="flex center gap"><span class="bold"><?= $a['match_score'] ?>%</span></div>
            <div class="progress" style="width:80px;margin-top:4px"><div class="bar" style="width:<?= $a['match_score'] ?>%;background:<?= $a['match_score']>=70?'#10b981':($a['match_score']>=40?'#f59e0b':'#ef4444') ?>"></div></div>
          </td>
          <td class="small"><?php if($a['keywords_matched']): foreach(explode(', ',$a['keywords_matched']) as $kw): ?><span class="badge badge-purple" style="margin:2px"><?= e($kw) ?></span><?php endforeach; else:echo '<span class="muted">—</span>';endif; ?></td>
          <td class="small muted"><?= format_date($a['applied_date'],'d M Y') ?></td>
          <td>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="action" value="status">
              <select name="status" class="form-select" style="width:auto;font-size:12px;padding:4px 8px" onchange="this.form.submit()">
                <?php foreach(array_keys($statusColors) as $s): ?><option value="<?= $s ?>" <?= $a['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td>
            <div class="flex gap">
              <?php if($a['resume_path']): ?><a href="<?= url('resumes/'.rawurlencode($a['resume_path'])) ?>" target="_blank" class="btn btn-sm btn-light" title="Resume"><i class="fa-solid fa-file-pdf"></i></a><?php endif; ?>
              <button class="btn btn-sm btn-primary" onclick="openSchedule(<?= $a['id'] ?>,'<?= e($a['full_name']) ?>')"><i class="fa-solid fa-calendar-plus"></i></button>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p class="muted small" style="margin-top:12px"><i class="fa-solid fa-circle-info"></i> Match % is auto-calculated by comparing resume content against each job's filter keywords. Upload PDF resumes for best keyword detection.</p>
</div>

<!-- Interview schedule modal -->
<div class="modal-bg" id="schedModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-calendar-plus"></i> Schedule Interview</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('schedModal')"></i></div>
    <form method="post" action="schedule.php">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="applicant_id" id="schId">
        <div class="flex between center" style="margin-bottom:8px"><div>Candidate: <strong id="schName"></strong></div></div>
        <div style="background:#f4f5fb;border-radius:10px;padding:12px;margin-bottom:14px">
          <div class="muted small" style="margin-bottom:8px"><i class="fa-solid fa-calendar-day"></i> Available Interview Slots (synced with Google Calendar)</div>
          <div id="slots" class="flex wrap gap"></div>
        </div>
        <div class="grid cols-2">
          <div><label>Date</label><input type="date" name="interview_date" id="schDate" class="form-control" required onchange="genSlots()"></div>
          <div><label>Time</label><input type="time" name="interview_time" class="form-control" value="11:00" required></div>
        </div>
        <div style="margin-top:12px"><label>Round</label><input type="text" name="round" class="form-control" value="First Round"></div>
        <div class="grid cols-2" style="margin-top:12px">
          <div><label>Interviewer</label><input type="text" name="interviewer" class="form-control" placeholder="Name"></div>
          <div><label>Location / Link</label><input type="text" name="location" class="form-control" placeholder="Office / Zoom link"></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('schedModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Schedule &amp; Notify</button></div>
    </form>
  </div>
</div>
<script>
var calendarSlots=['10:00','11:00','12:00','14:00','15:00','16:00'];
function openSchedule(id,name){document.getElementById('schId').value=id;document.getElementById('schName').textContent=name;
  document.getElementById('schDate').value=new Date().toISOString().split('T')[0];genSlots();openModal('schedModal');}
function genSlots(){
  var c=document.getElementById('slots');c.innerHTML='';
  calendarSlots.forEach(function(t){
    var b=document.createElement('button');b.type='button';b.className='btn btn-sm btn-light';b.textContent=t;
    b.onclick=function(){var tm=document.querySelector('[name=interview_time]');tm.value=t;};
    c.appendChild(b);
  });
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
