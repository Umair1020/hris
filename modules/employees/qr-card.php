<?php
/**
 * ============================================================================
 * EMPLOYEE QR CARD — Printable ID card with QR code
 * HR accesses this to print and laminate for employee.
 * Features: Search + Pagination (10 per page)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

// Search + Pagination
$search = clean($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = "WHERE status='Active'";
$params = [];
if ($search) {
    $where .= " AND (full_name LIKE ? OR employee_code LIKE ? OR designation LIKE ?)";
    $p = "%$search%";
    $params = [$p, $p, $p];
}

$total = (int)fetch_one("SELECT COUNT(*) c FROM employees $where", $params)['c'];
$totalPages = max(1, ceil($total / $perPage));

$employees = fetch_all(
    "SELECT * FROM employees $where ORDER BY full_name LIMIT $perPage OFFSET $offset",
    $params
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee QR Cards · <?= APP_COMPANY ?></title>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Mulish:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<style>
body{background:#f0f0f0;padding:20px;font-family:Mulish,sans-serif}
.toolbar{max-width:900px;margin:0 auto 20px}
.toolbar-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.toolbar-search{display:flex;gap:8px;margin:12px 0}
.toolbar-search input{flex:1;padding:10px 14px;border:1.5px solid #ddd;border-radius:10px;font-size:14px}
.toolbar .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;border:none;background:#7F3E98;color:#fff;font-family:Oswald;cursor:pointer;text-decoration:none;font-size:14px}
.pagination{display:flex;gap:4px;justify-content:center;margin-top:20px;flex-wrap:wrap}
.pagination a{padding:8px 14px;border:1px solid #ddd;border-radius:8px;background:#fff;color:#333;text-decoration:none;font-size:13px;transition:.15s}
.pagination a:hover{background:#7F3E98;color:#fff}
.pagination a.active{background:#7F3E98;color:#fff}
.pagination .info{padding:8px 14px;font-size:12px;color:#666;align-self:center}
.cards-grid{max-width:900px;margin:0 auto;display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
@media print{
  body{background:#fff;padding:0;margin:0}
  .toolbar,.pagination{display:none!important}
  .cards-grid{grid-template-columns:repeat(2,1fr);gap:8mm;max-width:none}
  .id-card{box-shadow:none;break-inside:avoid;page-break-inside:avoid}
  @page{size:A4;margin:10mm}
}
.id-card{width:100%;max-width:340px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.12);border:1px solid #e0e0e0;margin:0 auto}
.id-card .card-header{background:linear-gradient(135deg,#7F3E98,#9B59B6);color:#fff;padding:14px 18px;text-align:center}
.id-card .card-header .company{font-family:Oswald;font-size:20px;font-weight:700;letter-spacing:1px}
.id-card .card-header .tagline{font-size:9px;opacity:.8;letter-spacing:2px;text-transform:uppercase}
.id-card .card-body{padding:16px;text-align:center}
.id-card .photo-area{width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,#7F3E98,#9B59B6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:Oswald;font-size:24px;font-weight:600;margin:0 auto 10px}
.id-card .emp-name{font-family:Oswald;font-size:16px;font-weight:600;color:#2A1B3D;margin:0}
.id-card .emp-designation{font-size:11px;color:#666;margin:2px 0 8px}
.id-card .emp-code{display:inline-block;background:#f4f0f8;color:#7F3E98;font-weight:700;font-size:12px;padding:3px 12px;border-radius:12px;margin-bottom:12px}
.id-card .qr-area{display:flex;justify-content:center;margin:8px 0}
.id-card .qr-area canvas,.id-card .qr-area img{width:120px;height:120px}
.id-card .dept-row{display:flex;justify-content:center;gap:12px;font-size:10px;color:#666;margin-top:8px}
.id-card .scan-hint{font-size:9px;color:#999;margin-top:6px;text-align:center}
.id-card .card-footer{background:#f8f6fa;padding:8px 18px;text-align:center;font-size:9px;color:#999;border-top:1px solid #eee}
.id-card .card-footer strong{color:#7F3E98}
</style>
</head>
<body>

<div class="toolbar">
  <div class="toolbar-top">
    <h2 style="font-family:Oswald;margin:0"><i class="fa-solid fa-id-card"></i> Employee QR ID Cards</h2>
    <div style="display:flex;gap:10px">
      <a href="<?= url('modules/employees/index.php') ?>" class="btn" style="background:#666"><i class="fa-solid fa-arrow-left"></i> Back</a>
      <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Page</button>
    </div>
  </div>
  <form method="get" class="toolbar-search">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name, code, or designation...">
    <button class="btn" type="submit"><i class="fa-solid fa-search"></i></button>
    <?php if($search): ?><a href="<?= url('modules/employees/qr-card.php') ?>" class="btn" style="background:#666">Clear</a><?php endif; ?>
  </form>
</div>

<div class="cards-grid">
  <?php if(!$employees): ?>
    <div style="grid-column:span 2;text-align:center;padding:40px;color:#666">
      <i class="fa-solid fa-users-slash" style="font-size:40px;opacity:.3"></i>
      <p>No employees found.</p>
    </div>
  <?php else: foreach($employees as $e): ?>
  <div class="id-card">
    <div class="card-header">
      <div class="company">SPOTCOMM GLOBAL</div>
      <div class="tagline">Outsource · Optimize · Thrive</div>
    </div>
    <div class="card-body">
      <div class="photo-area"><?= e(initials($e['full_name'])) ?></div>
      <div class="emp-name"><?= e($e['full_name']) ?></div>
      <div class="emp-designation"><?= e($e['designation'] ?: 'Employee') ?></div>
      <div class="emp-code"><?= e($e['employee_code']) ?></div>
      <div class="qr-area" data-code="<?= e($e['employee_code']) ?>"></div>
      <div class="dept-row">
        <span><i class="fa-solid fa-building"></i> <?= e(department_name($e['department_id'])) ?></span>
        <span><i class="fa-solid fa-calendar"></i> Since <?= format_date($e['joining_date'],'M Y') ?></span>
      </div>
      <div class="scan-hint">Scan QR to mark attendance</div>
    </div>
    <div class="card-footer">
      <strong>hris.spotcomm.pk</strong><br>
      If found, please return to Spotcomm Global HR Department
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php if($totalPages > 1): ?>
<div class="pagination">
  <span class="info">Page <?= $page ?> of <?= $totalPages ?> (<?= $total ?> employees)</span>
  <?php for($i=1;$i<=$totalPages;$i++): ?>
    <a href="?page=<?= $i ?><?= $search?'&q='.urlencode($search):'' ?>" class="<?= $i==$page?'active':'' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr-area').forEach(function(el) {
  var code = el.getAttribute('data-code');
  new QRCode(el, {
    text: code,
    width: 120,
    height: 120,
    colorDark: '#2A1B3D',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.H
  });
});
</script>
</body>
</html>
