<?php
/**
 * ============================================================================
 * ISSUE LETTERS — HR creates letters manually, with pagination + search
 * No template selection needed. HR types subject + body directly.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $empId = (int)$_POST['employee_id'];
    $emp = fetch_one("SELECT e.*, d.name dept_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE e.id=?", [$empId]);

    if (!$emp) {
        set_flash('danger', 'Employee not found.');
        redirect(APP_URL . 'modules/letters/issue.php');
    }

    $subject = clean($_POST['subject'] ?? 'Letter');
    $bodyContent = $_POST['body'] ?? '';
    $letterType = clean($_POST['letter_type'] ?? 'letter');

    $refNo = 'SCG/' . date('Y') . '/' . str_pad((int)fetch_one("SELECT COUNT(*) c FROM generated_letters")['c'] + 1, 5, '0', STR_PAD_LEFT);

    insert('generated_letters', [
        'employee_id' => $empId,
        'template_id' => 1,
        'subject' => $subject,
        'body' => $bodyContent,
        'reference_no' => $refNo,
        'status' => 'generated',
        'requested_by' => current_user_id(),
        'approved_by' => current_user_id(),
    ]);

    notify($empId, 'New Letter: ' . $subject, "You have received a letter. Check your dashboard to view/download.", 'modules/letters/my.php');
    if (!empty($emp['whatsapp'])) {
        send_whatsapp($emp['whatsapp'], "📄 *Letter from HR*\n\n$subject\nRef: $refNo\n\nLogin to HRIS portal to view and download.\n\n_Spotcomm Global HR_");
    }
    if (!empty($emp['email'])) {
        send_email($emp['email'], $subject . ' - Spotcomm Global', "Dear {$emp['first_name']},\n\nYou have received a letter ($subject).\n\nReference: $refNo\n\nPlease login to HRIS portal to view and download.\n\nHR Department\nSpotcomm Global");
    }

    log_activity('Letter Issued', "$subject → {$emp['full_name']} ($refNo)");
    set_flash('success', "✅ Letter issued to {$emp['full_name']}! Sent to dashboard + WhatsApp + Email.");
    redirect(APP_URL . 'modules/letters/issue.php');
}

// Search + Pagination
$search = clean($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;

$where = '';
$params = [];
if ($search) {
    $where = "WHERE gl.subject LIKE ? OR gl.reference_no LIKE ? OR e.full_name LIKE ?";
    $p = "%$search%";
    $params = [$p, $p, $p];
}

$totalLetters = (int)fetch_one("SELECT COUNT(*) c FROM generated_letters gl LEFT JOIN employees e ON e.id=gl.employee_id $where", $params)['c'];
$totalPages = max(1, ceil($totalLetters / $perPage));

$letters = fetch_all(
    "SELECT gl.*, e.full_name emp_name, e.employee_code FROM generated_letters gl
     LEFT JOIN employees e ON e.id=gl.employee_id
     $where ORDER BY gl.id DESC LIMIT $perPage OFFSET $offset",
    $params
);

$employees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");

// Get header/footer from settings
$letterHeader = get_setting('letter_header', '<div style="text-align:center;margin-bottom:20px"><img src="' . APP_URL . 'assets/img/logo.png" style="height:50px"><br><strong style="font-size:14px">SPOTCOMM GLOBAL</strong><br><span style="font-size:10px;color:#666">Outsource · Optimize · Thrive</span></div>');
$letterFooter = get_setting('letter_footer', '<div style="text-align:center;margin-top:30px;border-top:1px solid #ddd;padding-top:10px;font-size:10px;color:#666"><strong>Spotcomm Global HR Department</strong><br>Phone: +971 557015596 · Email: sales@spotcommglobal.com · Web: www.spotcommglobal.com</div>');

auth_header('Issue Letters');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-paper-plane"></i> Issue Letters</h1>
    <div class="sub">Create and send letters directly to employee dashboard</div></div>
</div>

<div class="grid cols-2">
  <!-- Create New Letter -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-plus-circle"></i> Create New Letter</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="header_html" id="headerInput" value="<?= e($letterHeader) ?>">
      <input type="hidden" name="footer_html" id="footerInput" value="<?= e($letterFooter) ?>">
      <div style="margin-bottom:12px">
        <label>Select Employee *</label>
        <select name="employee_id" class="form-select" required>
          <option value="">— Choose Employee —</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'] . ' — ' . $emp['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid cols-2" style="margin-bottom:12px">
        <div><label>Letter Type</label>
          <select name="letter_type" class="form-select">
            <option value="letter">General Letter</option>
            <option value="warning">Warning Letter</option>
            <option value="appreciation">Appreciation Letter</option>
            <option value="termination">Termination Letter</option>
            <option value="certificate">Certificate</option>
          </select>
        </div>
        <div><label>Date</label><input type="text" class="form-control" value="<?= format_date(today()) ?>" disabled></div>
      </div>
      <div style="margin-bottom:12px"><label>Subject *</label><input type="text" name="subject" class="form-control" placeholder="e.g. Warning Letter - Disciplinary Action" required></div>
      <div style="margin-bottom:12px">
        <label>Letter Body *</label>
        <input type="hidden" name="body" id="bodyInput">
        <div style="background:#fff;border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
          <div id="bodyToolbar" style="border-bottom:1px solid #e0e0e0;padding:6px;display:flex;gap:4px;flex-wrap:wrap;background:#f9f9fb">
            <button type="button" class="ql-bold" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-weight:bold">B</button>
            <button type="button" class="ql-italic" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;font-style:italic">I</button>
            <button type="button" class="ql-underline" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer;text-decoration:underline">U</button>
            <select class="ql-align" style="border:1px solid #ccc;border-radius:4px;padding:2px"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
            <button type="button" class="ql-list" value="bullet" style="border:1px solid #ccc;border-radius:4px;padding:2px 8px;cursor:pointer">•</button>
          </div>
          <div id="bodyEditor" style="min-height:200px;padding:16px;font-size:14px;line-height:1.6"></div>
        </div>
      </div>
      <button class="btn btn-primary btn-block" data-confirm="Generate and send this letter?"><i class="fa-solid fa-paper-plane"></i> Generate &amp; Send Letter</button>
    </form>
  </div>

  <!-- Letter Header/Footer Settings -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-heading"></i> Letterhead Settings</h3>
    <p class="muted small">This header &amp; footer appears on all generated letters (A4 format).</p>
    <form method="post" action="<?= url('modules/settings/index.php') ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="letter_settings">
      <div style="margin-bottom:12px">
        <label>Letter Header (Logo + Company Info)</label>
        <textarea name="letter_header" class="form-control" rows="4" style="font-size:12px;font-family:monospace" placeholder="HTML allowed"><?= e($letterHeader) ?></textarea>
      </div>
      <div style="margin-bottom:12px">
        <label>Letter Footer (Signature + Contact)</label>
        <textarea name="letter_footer" class="form-control" rows="4" style="font-size:12px;font-family:monospace" placeholder="HTML allowed"><?= e($letterFooter) ?></textarea>
      </div>
      <button class="btn btn-light btn-block"><i class="fa-solid fa-save"></i> Save Letterhead Settings</button>
    </form>
  </div>
</div>

<!-- Letters Issued -->
<div class="card card-pad" style="margin-top:18px">
  <div class="flex between center wrap gap" style="margin-bottom:14px">
    <h3 class="section-title" style="margin:0"><i class="fa-solid fa-file-circle-check"></i> Letters Issued (<?= $totalLetters ?>)</h3>
    <form method="get" class="flex gap" style="gap:8px">
      <input type="text" name="q" class="form-control" placeholder="Search by name, subject, ref..." value="<?= e($search) ?>" style="max-width:280px">
      <button class="btn btn-light"><i class="fa-solid fa-search"></i></button>
      <?php if($search): ?><a href="<?= url('modules/letters/issue.php') ?>" class="btn btn-light">Clear</a><?php endif; ?>
    </form>
  </div>

  <?php if(!$letters): ?>
    <div class="empty"><i class="fa-solid fa-inbox"></i><p>No letters issued yet</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Ref</th><th>Employee</th><th>Subject</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($letters as $l): ?>
      <tr>
        <td class="small muted"><?= e($l['reference_no']) ?></td>
        <td class="bold"><?= e($l['emp_name'] ?: '—') ?><div class="muted small"><?= e($l['employee_code'] ?: '') ?></div></td>
        <td><?= e($l['subject'] ?: '—') ?></td>
        <td class="small"><?= format_date($l['created_at']) ?></td>
        <td><a href="<?= url('modules/letters/view.php?id='.$l['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-eye"></i> View &amp; Download</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if($totalPages > 1): ?>
  <div style="display:flex;gap:4px;justify-content:center;margin-top:16px">
    <?php for($i=1;$i<=$totalPages;$i++): ?>
      <a href="?page=<?= $i ?><?= $search?'&q='.urlencode($search):'' ?>" class="btn btn-sm <?= $i==$page?'btn-primary':'btn-light' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
try {
  var bodyQuill = new Quill('#bodyEditor', { modules: { toolbar: '#bodyToolbar' }, theme: 'snow', placeholder: 'Type letter content here...' });
  document.querySelector('form').addEventListener('submit', function() {
    var bodyHtml = bodyQuill.root.innerHTML;
    var header = document.getElementById('headerInput').value;
    var footer = document.getElementById('footerInput').value;
    document.getElementById('bodyInput').value = header + bodyHtml + footer;
  });
} catch(e) { console.log('Quill error:', e); }
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
