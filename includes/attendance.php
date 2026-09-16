<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - ATTENDANCE ENGINE (shared business rules)
 * ============================================================================
 * Single source of truth for:
 *   - the list of attendance statuses (labels + colours)
 *   - Off Day / Public Holiday detection
 *   - Late calculation (per-employee shift + grace)
 *   - Work-hours / overtime / undertime calculation on clock-out
 *   - Night-shift handling (clock-out after midnight)
 *   - Auto "Absent" marking for past working days with no record
 *   - Leave → attendance status mapping (Casual / Medical / Half Day ...)
 *
 * Loaded automatically by includes/functions.php
 * ============================================================================
 */

/* ----------------------------------------------------------------------------
 * STATUS REGISTRY
 *   key => [label, badge colour, group]
 *   group: work | leave | off | absent
 * -------------------------------------------------------------------------- */
function att_statuses()
{
    static $list = null;
    if ($list === null) {
        $list = [
            'present'           => ['Present',              'green',  'work'],
            'hours_completed'   => ['Hours Completed',      'green',  'work'],
            'late'              => ['Late',                 'amber',  'work'],
            'half_day'          => ['Half Day',             'amber',  'work'],
            'work_from_home'    => ['Work From Home',       'blue',   'work'],
            'official_duty'     => ['Official Duty / Field','blue',   'work'],
            'leave'             => ['Leave',                'purple', 'leave'],
            'casual_leave'      => ['Casual Leave',         'purple', 'leave'],
            'medical_leave'     => ['Medical Leave',        'purple', 'leave'],
            'annual_leave'      => ['Annual Leave',         'purple', 'leave'],
            'emergency_leave'   => ['Emergency Leave',      'purple', 'leave'],
            'unpaid_leave'      => ['Unpaid Leave',         'gray',   'leave'],
            'compensated_leave' => ['Compensated Leave',    'purple', 'leave'],
            'off_day'           => ['Off Day',              'gray',   'off'],
            'public_holiday'    => ['Public Holiday',       'blue',   'off'],
            'absent'            => ['Absent',               'red',    'absent'],
        ];
    }
    return $list;
}

/** Statuses that count as "present / worked" in dashboards & payroll */
function att_present_statuses()
{
    return ['present', 'late', 'hours_completed', 'half_day', 'work_from_home', 'official_duty'];
}

/** Statuses that count as leave */
function att_leave_statuses()
{
    $out = [];
    foreach (att_statuses() as $k => $v) if ($v[2] === 'leave') $out[] = $k;
    return $out;
}

/** SQL fragment: quoted, comma-separated list ("'present','late',...") */
function att_sql_in(array $statuses)
{
    return implode(',', array_map(fn($s) => "'" . $s . "'", $statuses));
}

function att_label($status)
{
    $s = att_statuses();
    if (isset($s[$status])) return $s[$status][0];
    return $status ? ucwords(str_replace('_', ' ', $status)) : '—';
}

function att_color($status)
{
    $s = att_statuses();
    return $s[$status][1] ?? 'gray';
}

/** "7h 30m" formatting for decimal hours */
function fmt_hours($hours, $emptyDash = true)
{
    $hours = (float)$hours;
    if ($hours <= 0) return $emptyDash ? '—' : '0h';
    $total = (int)round($hours * 60);
    $h = intdiv($total, 60);
    $m = $total % 60;
    if ($h && $m) return "{$h}h {$m}m";
    if ($h) return "{$h}h";
    return "{$m}m";
}

/**
 * Render the status cell for an attendance row (or a virtual status).
 * Shows worked hours next to Present/Hours Completed (issue: "only Present shown")
 * and a separate "Late Xm" chip so lateness is never hidden.
 *
 * @param array|string $rec  attendance row, or a status string
 */
function att_badge($rec, $withHours = true)
{
    if (!is_array($rec)) $rec = ['status' => (string)$rec];
    $status = $rec['status'] ?? '';
    if ($status === '' || $status === null) return '<span class="badge badge-gray">—</span>';

    $label = e(att_label($status));
    $color = att_color($status);
    $hours = (float)($rec['work_hours'] ?? 0);
    $lateMin = (int)($rec['late_minutes'] ?? 0);

    $html = '<span class="badge badge-' . $color . '">' . $label;
    if ($withHours && in_array($status, ['present', 'hours_completed', 'half_day', 'work_from_home', 'official_duty', 'late']) && $hours > 0) {
        $html .= ' · ' . e(fmt_hours($hours));
    }
    $html .= '</span>';

    // Late chip (always visible, even when status later became "hours_completed")
    if ($lateMin > 0 && $status !== 'late') {
        $html .= ' <span class="badge badge-amber" title="Clocked in ' . $lateMin . ' min after shift start"><i class="fa-solid fa-clock"></i> Late ' . e(fmt_hours($lateMin / 60)) . '</span>';
    } elseif ($status === 'late' && $lateMin > 0) {
        $html = '<span class="badge badge-amber" title="Clocked in ' . $lateMin . ' min after shift start">Late ' . e(fmt_hours($lateMin / 60));
        if ($withHours && $hours > 0) $html .= ' · worked ' . e(fmt_hours($hours));
        $html .= '</span>';
    }

    if (!empty($rec['clock_in']) && empty($rec['clock_out']) && in_array($status, ['present', 'late']) && ($rec['attendance_date'] ?? '') === today()) {
        $html .= ' <span class="badge badge-blue" title="Currently clocked in"><i class="fa-solid fa-circle-dot"></i> In office</span>';
    }
    return $html;
}

/* ----------------------------------------------------------------------------
 * HOLIDAYS & WEEKLY OFF
 * -------------------------------------------------------------------------- */

/** Returns holiday title for the date, or null. Handles recurring (yearly) holidays. */
function att_holiday($date)
{
    if (!isset($GLOBALS['__att_holidays'])) {
        $cache = ['exact' => [], 'recurring' => []];
        try {
            foreach (fetch_all("SELECT title, holiday_date, is_recurring FROM holidays") as $h) {
                if (!$h['holiday_date']) continue;
                if ((int)$h['is_recurring'] === 1) $cache['recurring'][substr($h['holiday_date'], 5, 5)] = $h['title'];
                else $cache['exact'][$h['holiday_date']] = $h['title'];
            }
        } catch (Exception $e) {}
        $GLOBALS['__att_holidays'] = $cache;
    }
    $cache = $GLOBALS['__att_holidays'];
    if (isset($cache['exact'][$date])) return $cache['exact'][$date] ?: 'Public Holiday';
    $md = substr($date, 5, 5);
    if (isset($cache['recurring'][$md])) return $cache['recurring'][$md] ?: 'Public Holiday';
    return null;
}

/** Force holiday cache reload (after add/delete) */
function att_holiday_cache_reset()
{
    unset($GLOBALS['__att_holidays']);
}

/** Is this date a weekly off for the employee? (employee.weekly_off = "Sunday,Saturday") */
function att_is_weekly_off($employee, $date)
{
    $dayName = date('l', strtotime($date));
    $offs = [];
    $raw = is_array($employee) ? ($employee['weekly_off'] ?? 'Sunday') : 'Sunday';
    if ($raw === null || trim($raw) === '') $raw = 'Sunday';
    foreach (explode(',', $raw) as $d) { $d = trim($d); if ($d !== '') $offs[] = $d; }
    if (get_setting('saturday_off', '0') === '1' && !in_array('Saturday', $offs)) $offs[] = 'Saturday';
    return in_array($dayName, $offs);
}

/**
 * What kind of day is this for the employee?  'holiday' | 'off' | 'work'
 */
function att_day_kind($employee, $date)
{
    if (att_holiday($date) !== null) return 'holiday';
    if (att_is_weekly_off($employee, $date)) return 'off';
    return 'work';
}

/**
 * Virtual status for a day that has NO attendance record.
 *   future           → null (nothing to show)
 *   public holiday   → 'public_holiday'
 *   weekly off       → 'off_day'
 *   before joining   → null
 *   today            → 'not_marked'
 *   past working day → 'absent'
 */
function att_expected_status($employee, $date)
{
    $today = today();
    if ($date > $today) return null;
    $kind = att_day_kind($employee, $date);
    if ($kind === 'holiday') return 'public_holiday';
    if ($kind === 'off') return 'off_day';
    $join = is_array($employee) ? ($employee['joining_date'] ?? '') : '';
    if ($join && $date < $join) return null;
    if ($date === $today) return 'not_marked';
    return 'absent';
}

/** Badge for a virtual (no-record) day */
function att_virtual_badge($status, $date = null)
{
    if ($status === null) return '<span class="muted small">—</span>';
    if ($status === 'not_marked') return '<span class="badge badge-gray"><i class="fa-solid fa-hourglass-half"></i> Not Marked</span>';
    $title = '';
    if ($status === 'public_holiday' && $date) $title = att_holiday($date);
    return '<span class="badge badge-' . att_color($status) . '"' . ($title ? ' title="' . e($title) . '"' : '') . '>' . e(att_label($status)) . ($title ? ' · ' . e($title) : '') . '</span>';
}

/* ----------------------------------------------------------------------------
 * LATE / HOURS CALCULATION
 * -------------------------------------------------------------------------- */

/** Employee's shift start "HH:MM" (falls back to WORK_START) */
function att_shift_start($employee)
{
    $s = is_array($employee) ? ($employee['shift_start'] ?? '') : '';
    $s = $s ? substr($s, 0, 5) : '';
    return ($s && $s !== '00:00') ? $s : (defined('WORK_START') ? WORK_START : '09:00');
}

function att_grace_minutes()
{
    $g = get_setting('late_grace_minutes', '');
    if ($g === '' || $g === null) return defined('GRACE_MINUTES') ? (int)GRACE_MINUTES : 15;
    return max(0, (int)$g);
}

/**
 * Minutes late for a clock-in datetime ("Y-m-d H:i:s") against employee shift + grace.
 * Returns 0 when on time (within grace).
 */
function att_late_minutes($employee, $clockIn)
{
    $date = substr($clockIn, 0, 10);
    $shiftTs = strtotime($date . ' ' . att_shift_start($employee) . ':00 UTC');
    $inTs = strtotime($clockIn . ' UTC');
    if ($shiftTs === false || $inTs === false) return 0;
    $diff = (int)floor(($inTs - $shiftTs) / 60);
    if ($diff <= att_grace_minutes()) return 0;
    return $diff; // minutes after shift start (grace already exceeded)
}

/**
 * Status + late minutes for a clock-in.
 * $existing = today's row if any (e.g. an approved Half Day keeps its status).
 */
function att_clock_in_status($employee, $clockIn, $existing = null)
{
    if ($existing && ($existing['status'] ?? '') === 'half_day') {
        // First-half leave → arriving late is expected; second-half leave → normal lateness applies
        $secondHalf = stripos((string)($existing['notes'] ?? ''), 'second half') !== false;
        $late = $secondHalf ? att_late_minutes($employee, $clockIn) : 0;
        return ['status' => 'half_day', 'late_minutes' => $late];
    }
    $late = att_late_minutes($employee, $clockIn);
    return ['status' => $late > 0 ? 'late' : 'present', 'late_minutes' => $late];
}

/** Can the employee clock in on top of this existing row? (null row = yes) */
function att_can_clock_in($existing)
{
    if (!$existing) return true;
    if (!empty($existing['clock_in'])) return false;
    return in_array($existing['status'], ['present', 'late', 'absent', 'hours_completed', 'half_day', 'work_from_home', 'official_duty']);
}

/**
 * Compute hours / OT / undertime / final status when clocking out.
 * $rec = existing attendance row (needs clock_in, status, late_minutes)
 */
function att_compute_clock_out($employee, $rec, $clockOut)
{
    $hours = calc_hours($rec['clock_in'], $clockOut);
    $req = get_required_hours($employee);
    $isHalf = (($rec['status'] ?? '') === 'half_day');
    if ($isHalf) $req = round($req / 2, 2); // approved half day → only half the shift is required
    $utGrace = (int)get_setting('undertime_grace_minutes', '10');

    $overtime = max(0, round($hours - $req, 2));
    $undertime = max(0, round($req - $hours, 2));
    if ($undertime * 60 <= $utGrace) $undertime = 0;           // small gaps are not undertime
    if ($overtime * 60 < 1) $overtime = 0;

    $lateMin = (int)($rec['late_minutes'] ?? 0);
    if ($lateMin === 0 && ($rec['status'] ?? '') === 'late') {
        $lateMin = att_late_minutes($employee, $rec['clock_in']);
    }

    $status = $rec['status'] ?? 'present';
    $completed = ($undertime == 0);
    if ($isHalf) {
        $status = 'half_day';
    } elseif (in_array($status, ['present', 'late'])) {
        if ($status === 'late') {
            // Late employee: OT can compensate lateness if policy allows
            if ($completed && get_setting('ot_against_late', '1') === '1') $status = 'hours_completed';
            else $status = 'late';
        } elseif ($completed) {
            $status = 'hours_completed';
        } else {
            $status = 'present';
        }
    }

    return [
        'work_hours' => $hours,
        'overtime_hours' => $overtime,
        'undertime_hours' => $undertime,
        'status' => $status,
        'late_minutes' => $lateMin,
        'required_hours' => $req,
    ];
}

/**
 * Find the attendance row an employee should clock OUT of.
 * Handles night shifts: a clock-in from yesterday without clock-out (within 20h)
 * is still the "open" shift after midnight.
 */
function att_find_open_shift($employeeId, $now = null)
{
    $now = $now ?: now();
    $today = substr($now, 0, 10);
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $row = fetch_one(
        "SELECT * FROM attendance WHERE employee_id=? AND attendance_date IN (?, ?)
           AND clock_in IS NOT NULL AND clock_out IS NULL
         ORDER BY attendance_date DESC LIMIT 1",
        [$employeeId, $today, $yesterday]
    );
    if (!$row) return null;
    if ($row['attendance_date'] !== $today) {
        $age = (strtotime($now . ' UTC') - strtotime($row['clock_in'] . ' UTC')) / 3600;
        if ($age > 20) return null; // stale — treat as forgotten checkout
    }
    return $row;
}

/**
 * For manual entry: build clock in/out datetimes; if out time is earlier than in time
 * the shift crossed midnight and clock-out belongs to the next day.
 */
function att_manual_times($date, $inTime, $outTime)
{
    $in = $inTime ? $date . ' ' . substr($inTime, 0, 5) . ':00' : null;
    $out = null;
    if ($outTime) {
        $outDate = $date;
        if ($inTime && substr($outTime, 0, 5) < substr($inTime, 0, 5)) $outDate = date('Y-m-d', strtotime($date . ' +1 day'));
        $out = $outDate . ' ' . substr($outTime, 0, 5) . ':00';
    }
    return [$in, $out];
}

/* ----------------------------------------------------------------------------
 * AUTO ABSENT
 * -------------------------------------------------------------------------- */

/**
 * Insert "absent" rows for every past working day (not today, not future) where an
 * active employee has no attendance record and the day is not an off day / holiday.
 * Throttled to once per hour unless $force. Returns number of rows inserted.
 */
function att_sync_absents($force = false)
{
    att_backfill_once();
    if (get_setting('auto_absent_enabled', '1') !== '1') return 0;
    $last = get_setting('absent_sync_last', '');
    if (!$force && $last && (time() - strtotime($last . ' UTC') + 5 * 3600) < 3600) return 0;
    save_setting('absent_sync_last', now());

    $days = (int)get_setting('absent_sync_days', '45');
    if ($days < 1) $days = 45;
    $today = today();
    $from = date('Y-m-d', strtotime($today . " -$days days"));
    $to = date('Y-m-d', strtotime($today . ' -1 day'));
    if ($to < $from) return 0;

    $emps = fetch_all("SELECT id, joining_date, weekly_off, created_at FROM employees WHERE status='Active'");
    $inserted = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT OR IGNORE INTO attendance (employee_id, attendance_date, status, work_hours, overtime_hours, undertime_hours, clock_in_method, notes, created_at) VALUES (?, ?, 'absent', 0, 0, 0, 'system', 'Auto-marked absent (no attendance)', ?)");
        foreach ($emps as $emp) {
            $start = $from;
            $join = $emp['joining_date'] ?: substr((string)$emp['created_at'], 0, 10);
            if ($join && $join > $start) $start = $join;
            if ($start > $to) continue;
            $have = array_column(fetch_all("SELECT attendance_date FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?", [$emp['id'], $start, $to]), 'attendance_date');
            $have = array_flip($have);
            for ($d = $start; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (isset($have[$d])) continue;
                if (att_day_kind($emp, $d) !== 'work') continue;
                $ins->execute([$emp['id'], $d, now()]);
                $inserted += $ins->rowCount();
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
    return $inserted;
}

/**
 * One-time data repair for records created before the attendance engine:
 *  - night-shift rows where clock_out < clock_in (out belongs to next day)
 *  - missing late_minutes for existing Late rows
 *  - recompute hours / OT / undertime / status from the employee's own shift
 */
function att_backfill_once()
{
    if (get_setting('att_engine_backfill', '0') === '2') return;
    save_setting('att_engine_backfill', '2');
    try {
        $rows = fetch_all("SELECT a.*, e.shift_start, e.shift_end, e.weekly_off FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.clock_in IS NOT NULL AND a.clock_in <> ''");
        foreach ($rows as $r) {
            $emp = ['shift_start' => $r['shift_start'], 'shift_end' => $r['shift_end']];
            $data = [];
            $lateMin = att_late_minutes($emp, $r['clock_in']);
            if ((int)$r['late_minutes'] !== $lateMin) $data['late_minutes'] = $lateMin;
            $clockOut = $r['clock_out'];
            if ($clockOut && strtotime($clockOut . ' UTC') <= strtotime($r['clock_in'] . ' UTC')) {
                // stored on the same date but shift crossed midnight
                $clockOut = date('Y-m-d', strtotime(substr($clockOut, 0, 10) . ' +1 day')) . substr($clockOut, 10);
                $data['clock_out'] = $clockOut;
            }
            if ($clockOut && in_array($r['status'], ['present', 'late', 'hours_completed'])) {
                $calc = att_compute_clock_out($emp, ['clock_in' => $r['clock_in'], 'status' => $lateMin > 0 ? 'late' : 'present', 'late_minutes' => $lateMin], $clockOut);
                foreach (['work_hours', 'overtime_hours', 'undertime_hours', 'status'] as $k) {
                    if ((string)$r[$k] !== (string)$calc[$k]) $data[$k] = $calc[$k];
                }
            } elseif (!$clockOut && $r['status'] === 'present' && $lateMin > 0) {
                $data['status'] = 'late';
            }
            if ($data) update('attendance', $data, 'id=?', [$r['id']]);
        }
        // Generic "leave" rows → specific leave type where an approved request exists
        $leaves = fetch_all("SELECT a.id, a.attendance_date, a.employee_id FROM attendance a WHERE a.status='leave'");
        foreach ($leaves as $l) {
            $lr = fetch_one("SELECT lr.half_day, lt.code FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? AND lr.status='approved' AND ? BETWEEN lr.start_date AND lr.end_date ORDER BY lr.id DESC LIMIT 1", [$l['employee_id'], $l['attendance_date']]);
            if ($lr) update('attendance', ['status' => att_leave_status($lr['code'], (bool)$lr['half_day'])], 'id=?', [$l['id']]);
        }
    } catch (Exception $e) {
        // never block page load because of a repair job
    }
}

/* ----------------------------------------------------------------------------
 * LEAVE → ATTENDANCE
 * -------------------------------------------------------------------------- */

/** Attendance status for a leave-type code (CL, ML, AL, EMG, LWP, COMP...) */
function att_leave_status($leaveCode, $halfDay = false)
{
    if ($halfDay) return 'half_day';
    $code = strtoupper(trim((string)$leaveCode));
    $map = [
        'CL' => 'casual_leave', 'CASUAL' => 'casual_leave',
        'ML' => 'medical_leave', 'SL' => 'medical_leave', 'SICK' => 'medical_leave', 'MEDICAL' => 'medical_leave',
        'AL' => 'annual_leave', 'EL' => 'annual_leave', 'ANNUAL' => 'annual_leave', 'EARNED' => 'annual_leave',
        'EMG' => 'emergency_leave', 'EMERGENCY' => 'emergency_leave',
        'LWP' => 'unpaid_leave', 'UNPAID' => 'unpaid_leave',
        'COMP' => 'compensated_leave', 'CPL' => 'compensated_leave', 'CO' => 'compensated_leave',
        'HD' => 'half_day', 'HALF' => 'half_day',
    ];
    return $map[$code] ?? 'leave';
}

/**
 * Write an approved leave into the attendance sheet (one row per working day).
 * Off days & public holidays inside the range are skipped.
 */
function apply_leave_to_attendance($lr)
{
    $emp = fetch_one("SELECT id, weekly_off, joining_date FROM employees WHERE id=?", [$lr['employee_id']]);
    if (!$emp) return 0;
    $lt = fetch_one("SELECT code, name FROM leave_types WHERE id=?", [$lr['leave_type_id']]);
    $status = att_leave_status($lt['code'] ?? '', !empty($lr['half_day']));
    $note = ($lt['name'] ?? 'Leave') . ' (approved leave #' . $lr['id'] . ')';
    if (!empty($lr['half_day'])) {
        $note .= ' — ' . (($lr['half_day_session'] ?? '') === 'second_half' ? 'Second half' : 'First half');
    }
    $n = 0;
    try {
        $period = new DatePeriod(new DateTime($lr['start_date']), new DateInterval('P1D'), (new DateTime($lr['end_date']))->modify('+1 day'));
    } catch (Exception $e) { return 0; }
    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');
        if (att_day_kind($emp, $d) !== 'work') continue;
        $ex = fetch_one("SELECT id, status FROM attendance WHERE employee_id=? AND attendance_date=?", [$emp['id'], $d]);
        if ($ex) {
            update('attendance', ['status' => $status, 'notes' => $note, 'undertime_hours' => 0], 'id=?', [$ex['id']]);
        } else {
            insert('attendance', ['employee_id' => $emp['id'], 'attendance_date' => $d, 'status' => $status, 'notes' => $note, 'clock_in_method' => 'system']);
        }
        $n++;
    }
    return $n;
}

/** Count working days between two dates for an employee (skips off days & holidays) */
function att_working_days($employee, $start, $end)
{
    $n = 0;
    try {
        $period = new DatePeriod(new DateTime($start), new DateInterval('P1D'), (new DateTime($end))->modify('+1 day'));
    } catch (Exception $e) { return 0; }
    foreach ($period as $dt) if (att_day_kind($employee, $dt->format('Y-m-d')) === 'work') $n++;
    return $n;
}

/** Notify the *user account* linked to an employee (notify() expects a users.id) */
function notify_employee($employeeId, $title, $message, $link = null)
{
    $u = fetch_one("SELECT id FROM users WHERE employee_id=? AND status='active' LIMIT 1", [$employeeId]);
    if ($u) notify($u['id'], $title, $message, $link);
}
