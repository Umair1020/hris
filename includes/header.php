<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - SHARED LAYOUT (HEADER + SIDEBAR)
 * Usage:  require_auth_header('Dashboard', 'manager');
 *         ... page content ...
 *         require __DIR__ . '/../includes/footer.php';
 * ============================================================================
 */
require_once __DIR__ . '/auth.php';

/** Renders the sidebar navigation, filtering items by role. */
function render_sidebar()
{
    $role = current_role();
    $empId = current_employee_id();

    // [label, icon, url, min_role]
    $groups = [
        'Main' => [
            ['Dashboard',           'fa-gauge-high',      'dashboard.php',       'employee'],
            ['EPAD Analytics',      'fa-chart-pie',       'modules/analytics/index.php', 'admin'],
        ],
        'My Self-Service' => [
            ['My Attendance',       'fa-clock',            'modules/attendance/my.php',       'employee'],
            ['My Leaves',           'fa-calendar-days',    'modules/leave/my.php',            'employee'],
            ['My Payslips',         'fa-file-invoice-dollar', 'modules/payroll/my.php',     'employee'],
            ['My Performance',      'fa-chart-line',       'modules/performance/my.php',     'employee'],
            ['My Letters',          'fa-file-signature',   'modules/letters/my.php',         'employee'],
            ['Request Letter',      'fa-envelope',         'modules/letters/my.php?action=new', 'employee'],
            ['Grievances',          'fa-shield-halved',    'modules/grievances/',            'employee'],
        ],
        'Recruitment' => [
            ['Job Postings',        'fa-briefcase',        'modules/ats/jobs.php',           'employee'],
            ['Applicants (ATS)',    'fa-users-gear',       'modules/ats/applicants.php',     'hr'],
        ],
        'HR Administration' => [
            ['Employees',           'fa-id-card',          'modules/employees/index.php',    'hr'],
            ['Departments',         'fa-building',         'modules/employees/departments.php','hr'],
            ['QR ID Cards',         'fa-qrcode',           'modules/employees/qr-card.php',  'hr'],
            ['Attendance Mgmt',     'fa-clipboard-check',  'modules/attendance/index.php',   'manager'],
            ['Leave Approvals',     'fa-circle-check',     'modules/leave/approvals.php',    'manager'],
            ['Payroll',             'fa-sack-dollar',      'modules/payroll/index.php',      'hr'],
            ['Loans',               'fa-hand-holding-dollar','modules/payroll/loans.php',    'hr'],
            ['Benefits',            'fa-gift',             'modules/payroll/benefits.php',   'hr'],
            ['Performance',         'fa-award',            'modules/performance/index.php',  'manager'],
            ['Letter Requests',     'fa-envelope-open-text','modules/letters/approvals.php', 'hr'],
            ['Issue Letters',       'fa-paper-plane',      'modules/letters/issue.php',      'hr'],
            ['Letter Templates',    'fa-file-lines',       'modules/letters/templates.php',  'hr'],
            ['Leave Types',         'fa-calendar-plus',    'modules/leave/types.php',        'hr'],
            ['Policy Management',   'fa-book',             'modules/policy/index.php',       'hr'],
            ['Separation',          'fa-door-open',        'modules/separation/index.php',   'manager'],
        ],
        'Company' => [
            ['Company Policy',      'fa-book',             'modules/policy/index.php',       'employee'],
        ],
        'System' => [
            ['User Management',    'fa-users-gear',       'modules/settings/users.php',     'admin'],
            ['Settings',            'fa-gear',             'modules/settings/index.php',     'admin'],
            ['Broadcast',           'fa-tower-broadcast',  'modules/settings/whatsapp.php',  'hr'],
        ],
    ];

    // Always allow employees their own profile / separation request
    $groups['Main'][] = ['My Profile', 'fa-user', 'modules/profile/index.php', 'employee'];

    echo '<div class="sidebar" id="sidebar">';
    echo '  <div class="brand"><img src="' . asset('img/logo.png') . '" alt="Spotcomm Global" style="height:30px;width:auto;max-width:170px;filter:brightness(0) invert(1)"></div>';
    echo '  <nav class="nav">';
    foreach ($groups as $label => $items) {
        // Hide "My Self-Service" for admin role
        if ($label === 'My Self-Service' && current_role() === 'admin') continue;
        // Hide employee-only sections for admin/HR
        if ($label === 'Company' && role_at_least('hr')) continue;
        $visible = array_filter($items, fn($it) => role_at_least($it[3]));
        if (!$visible) continue;
        echo '<div class="nav-label">' . $label . '</div>';
        foreach ($visible as $it) {
            [$name, $icon, $link, $min] = $it;
            $active = is_active($link) ? ' active' : '';
            echo '<a class="nav-item' . $active . '" href="' . url($link) . '"><i class="fa-solid ' . $icon . '"></i><span>' . $name . '</span></a>';
        }
    }
    echo '  </nav>';
    echo '  <div class="sidebar-footer">Spotcomm HRIS v' . APP_VERSION . '<br>© ' . date('Y') . ' ' . APP_COMPANY . '</div>';
    echo '</div>';
}

function is_active($link)
{
    $here = ltrim($_SERVER['SCRIPT_NAME'] ?? '', '/');
    // strip any subfolder portion up to spotcomm-hris/
    if (preg_match('#spotcomm-hris/(.*)$#i', $here, $m)) $here = $m[1];
    $link = ltrim($link, '/');
    return rtrim($here, '/') === rtrim($link, '/');
}

function render_topbar($pageTitle = '')
{
    $notifCount = (int) fetch_one(
        "SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0",
        [current_user_id()]
    )['c'];
    ?>
    <header class="topbar">
      <i class="fa-solid fa-bars toggle" onclick="toggleSidebar()"></i>
      <div class="search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" placeholder="Search employees, requests..." onkeydown="if(event.key==='Enter')window.location.href='<?=url('modules/employees/index.php?q=')?>'+encodeURIComponent(this.value)">
      </div>
      <div class="topbar-actions">
        <div class="topbar-icon" id="notifBell" title="Notifications" onclick="var d=document.getElementById('notifDropdown');d.style.display=(d.style.display==='block')?'none':'block';">
          <i class="fa-solid fa-bell"></i>
          <?php if ($notifCount): ?><span class="dot"><?= $notifCount ?></span><?php endif; ?>
        </div>
        <div class="notif-dropdown" id="notifDropdown" style="display:none;position:absolute;top:60px;right:24px;width:340px;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.2);z-index:200;overflow:hidden;border:1px solid #ebe6f0">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #ebe6f0">
            <strong>Notifications</strong>
            <a href="<?= APP_URL ?>notifications-api.php?action=mark_read" class="small" style="color:#7F3E98" onclick="var d=document.querySelectorAll('.notif-item.unread');d.forEach(function(e){e.classList.remove('unread')});var dot=document.querySelector('.topbar-icon .dot');if(dot)dot.style.display='none'">Mark all read</a>
          </div>
          <div style="max-height:360px;overflow-y:auto">
            <?php
            $notifs = fetch_all("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 10", [current_user_id()]);
            if (!$notifs) echo '<div style="text-align:center;padding:30px;color:#6b7280;font-size:13px">No notifications</div>';
            foreach ($notifs as $n): ?>
              <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" style="padding:12px 16px;border-bottom:1px solid #ebe6f0;cursor:pointer">
                <div style="font-weight:700;font-size:13px"><?= e($n['title']) ?></div>
                <div style="font-size:12px;color:#6b7280;margin:3px 0"><?= e($n['message']) ?></div>
                <div style="font-size:11px;color:#6b7280"><?= time_ago($n['created_at']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (role_at_least('admin')): ?>
        <a href="<?= url('modules/settings/users.php') ?>" class="topbar-icon" title="User Management"><i class="fa-solid fa-users-gear"></i></a>
        <a href="<?= url('modules/settings/index.php') ?>" class="topbar-icon" title="Settings"><i class="fa-solid fa-gear"></i></a>
        <?php endif; ?>
        <a href="<?= url('modules/profile/index.php') ?>" class="user-chip" style="color:inherit">
          <div class="avatar sm"><?= e(initials(current_name())) ?></div>
          <div class="meta">
            <div class="nm"><?= e(current_name()) ?></div>
            <div class="rl"><?= e(ucfirst(current_role())) ?></div>
          </div>
        </a>
        <a href="<?= url('logout.php') ?>" class="topbar-icon" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>
    <?php
}

/**
 * Start a page: opens HTML, head, layout, requires auth.
 * @param string $title  page title
 * @param string $minRole minimum role required
 */
function auth_header($title = '', $minRole = 'employee')
{
    require_login($minRole);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title ?: APP_NAME) ?> · <?= APP_COMPANY ?></title>
  <link rel="icon" type="image/png" href="<?= asset('img/favicon.png') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&family=Mulish:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= asset('css/style.css') ?>?v=<?= filemtime(APP_ROOT.'/assets/css/style.css') ?>">
  <script src="<?= asset('js/app.js') ?>?v=<?= filemtime(APP_ROOT.'/assets/js/app.js') ?>"></script>
</head>
<body>
  <div class="app">
    <?php render_sidebar(); ?>
    <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>
    <div class="main">
      <?php render_topbar(); ?>
      <main class="content">
        <?php foreach (get_flash() as $f): ?>
          <div class="alert alert-<?= e($f['type']) ?>"><i class="fa-solid fa-<?= $f['type']==='success'?'circle-check':($f['type']==='danger'?'circle-exclamation':'circle-info') ?>"></i> <?= e($f['message']) ?></div>
        <?php endforeach; ?>
  <?php
}
