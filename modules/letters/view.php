<?php
/**
 * ============================================================================
 * LETTER VIEW — A4 formatted, printable, downloadable as PDF
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Letter');
$id = (int)($_GET['id'] ?? 0);
$gl = fetch_one("SELECT gl.*, e.full_name emp_name FROM generated_letters gl JOIN employees e ON e.id=gl.employee_id WHERE gl.id=?", [$id]);
if (!$gl) { set_flash('danger', 'Letter not found.'); redirect(APP_URL . 'dashboard.php'); }
if (!can_edit_all() && $gl['employee_id'] != current_employee_id()) { require_login('hr'); }
?>
<div class="page-head">
  <div><h1><?= e($gl['subject'] ?: 'Letter') ?></h1>
    <div class="sub">Ref: <?= e($gl['reference_no']) ?> · <?= e($gl['emp_name']) ?></div></div>
  <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-download"></i> Download / Print</button>
</div>

<div class="a4-page" id="a4Page">
  <?= $gl['body'] ?>
</div>

<style>
.a4-page {
  background: #fff;
  max-width: 210mm;
  min-height: 297mm;
  margin: 0 auto;
  padding: 20mm 20mm;
  box-shadow: 0 4px 30px rgba(0,0,0,.1);
  border-radius: 4px;
  font-family: 'Mulish', Arial, sans-serif;
  font-size: 11pt;
  line-height: 1.8;
  color: #2A1B3D;
}
@media print {
  body * { visibility: hidden; }
  .a4-page, .a4-page * { visibility: visible; }
  .a4-page { position: absolute; left: 0; top: 0; box-shadow: none; max-width: 100%; padding: 0; }
  .sidebar, .topbar, .page-head, .overlay, .notif-dropdown { display: none !important; }
  @page { size: A4; margin: 15mm; }
}
</style>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
