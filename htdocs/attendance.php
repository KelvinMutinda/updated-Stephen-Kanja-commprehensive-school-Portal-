<?php
// ============================================================
// attendance.php — Student Attendance Tracker
// Stephen Kanja School Management System
// ============================================================
include 'conn.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Auto-create tables ────────────────────────────────────────
// Student table (if not exists)
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS Student (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        Assesment  VARCHAR(50)  NOT NULL UNIQUE,
        firstName  VARCHAR(100) NOT NULL,
        surname    VARCHAR(100) NOT NULL,
        Grade      VARCHAR(10)  NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Attendance table
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS attendance (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        Assesment       VARCHAR(50)  NOT NULL,
        student_name    VARCHAR(200) NOT NULL,
        grade           VARCHAR(10)  NOT NULL,
        attendance_date DATE         NOT NULL,
        status          ENUM('Present','Absent','Late') NOT NULL DEFAULT 'Present',
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_att (Assesment, attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$message = '';

// ── POST: Save attendance ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_attendance') {
        $date   = mysqli_real_escape_string($conn, $_POST['att_date']);
        $grade  = mysqli_real_escape_string($conn, $_POST['grade']);
        $marked = $_POST['status'] ?? [];

        foreach ($marked as $assessment => $status) {
            $assessment = mysqli_real_escape_string($conn, $assessment);
            $status     = in_array($status, ['Present', 'Absent', 'Late']) ? $status : 'Present';

            $sres  = mysqli_query($conn, "SELECT firstName, surname FROM Student WHERE Assesment='$assessment' LIMIT 1");
            $srow  = $sres ? mysqli_fetch_assoc($sres) : null;
            $sname = $srow ? mysqli_real_escape_string($conn, $srow['firstName'] . ' ' . $srow['surname']) : $assessment;

            $sql = "INSERT INTO attendance (Assesment, student_name, grade, attendance_date, status)
                    VALUES ('$assessment', '$sname', '$grade', '$date', '$status')
                    ON DUPLICATE KEY UPDATE status='$status'";

            if (!mysqli_query($conn, $sql)) {
                $message .= '<div class="at-alert at-error">Error saving ' . htmlspecialchars($sname) . ': ' . mysqli_error($conn) . '</div>';
            }
        }

        if (empty($message)) {
            $message = '<div class="at-alert at-success"><i class="fa-solid fa-check"></i> Attendance saved for ' . htmlspecialchars($date) . '.</div>';
        }
    }
}

// ── Filters ───────────────────────────────────────────────────
$sel_grade = isset($_GET['grade']) ? mysqli_real_escape_string($conn, $_GET['grade']) : '1';
$sel_date  = isset($_GET['date'])  ? mysqli_real_escape_string($conn, $_GET['date'])  : date('Y-m-d');

// ── Students ──────────────────────────────────────────────────
$students_res = mysqli_query($conn,
    "SELECT DISTINCT Assesment, firstName, surname FROM Student
     WHERE Grade='$sel_grade' ORDER BY surname, firstName"
);
$students = [];
if ($students_res) while ($r = mysqli_fetch_assoc($students_res)) $students[] = $r;

// ── Today's attendance ────────────────────────────────────────
$att_res = mysqli_query($conn,
    "SELECT Assesment, status FROM attendance
     WHERE grade='$sel_grade' AND attendance_date='$sel_date'"
);
$today_att = [];
if ($att_res) while ($r = mysqli_fetch_assoc($att_res)) $today_att[$r['Assesment']] = $r['status'];

$summary = ['Present' => 0, 'Absent' => 0, 'Late' => 0];
foreach ($today_att as $s) { if (isset($summary[$s])) $summary[$s]++; }

// ── Monthly report ────────────────────────────────────────────
$month_start = date('Y-m-01', strtotime($sel_date));
$month_end   = date('Y-m-t',  strtotime($sel_date));
$monthly_res = mysqli_query($conn,
    "SELECT Assesment, student_name,
            SUM(status='Present') as present_count,
            SUM(status='Absent')  as absent_count,
            SUM(status='Late')    as late_count,
            COUNT(*) as total_days
     FROM attendance
     WHERE grade='$sel_grade'
       AND attendance_date BETWEEN '$month_start' AND '$month_end'
     GROUP BY Assesment, student_name ORDER BY student_name"
);

// ── All grades ────────────────────────────────────────────────
$grades_res = mysqli_query($conn, "SELECT DISTINCT Grade FROM Student ORDER BY Grade+0");
$all_grades = [];
if ($grades_res) while ($g = mysqli_fetch_assoc($grades_res)) $all_grades[] = $g['Grade'];
if (empty($all_grades)) $all_grades = ['1','2','3','4','5','6','7','8','9'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance — Stephen Kanja School</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --gold: #f0c040; --gold-dim: #c9a030;
    --black: #111111; --mid: #2a2a2a;
    --bg: #f4f4f2; --bg-card: #ffffff; --bg-input: #f8f8f6;
    --text-primary: #1a1a18; --text-secondary: #5f5e5a; --text-tertiary: #888780;
    --border: rgba(0,0,0,0.09); --radius-md: 8px; --radius-lg: 12px;
  }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; padding: 2rem; }
  .at-wrapper { max-width: 1000px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.2rem; }

  .at-header {
    background: var(--black); border-bottom: 3px solid var(--gold);
    padding: 1rem 1.4rem; border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
  }
  .at-header-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem; letter-spacing: 0.1em; color: #fff; }
  .at-header-title i { color: var(--gold); margin-right: 6px; }
  .at-header-date { font-size: 12px; color: #555; }

  .at-stats { display: flex; gap: 12px; flex-wrap: wrap; }
  .at-stat-card {
    flex: 1; min-width: 110px; padding: 14px;
    background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); text-align: center;
  }
  .at-stat-val { font-size: 26px; font-weight: 600; }
  .at-stat-lbl { font-size: 11px; color: var(--text-tertiary); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.06em; }
  .val-present { color: #16a34a; } .val-absent { color: #dc2626; } .val-late { color: #d97706; } .val-total { color: #2563eb; }

  .at-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
  .at-card-head {
    background: var(--black); border-bottom: 2px solid var(--gold);
    padding: 0.7rem 1.2rem; display: flex; align-items: center; gap: 8px;
  }
  .at-card-head span { font-family: 'Bebas Neue', sans-serif; font-size: 0.95rem; letter-spacing: 0.1em; color: #fff; }
  .at-card-head i { color: var(--gold); font-size: 12px; }
  .at-card-body { padding: 1.2rem 1.4rem; }

  .at-controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .at-ctrl-field { display: flex; flex-direction: column; gap: 5px; }
  .at-ctrl-field label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-tertiary); }
  .at-ctrl-field select, .at-ctrl-field input[type=date] {
    padding: 8px 12px; font-family: 'DM Sans', sans-serif; font-size: 13px;
    background: var(--bg-input); border: 1px solid rgba(0,0,0,0.1);
    border-radius: var(--radius-md); outline: none; color: var(--text-primary); transition: border-color 0.15s;
  }
  .at-ctrl-field select:focus, .at-ctrl-field input:focus { border-color: var(--gold); }
  .btn-load {
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
    color: var(--black); background: var(--gold); border: none;
    border-radius: var(--radius-md); padding: 9px 18px; cursor: pointer;
    display: flex; align-items: center; gap: 6px; transition: background 0.15s; align-self: flex-end;
  }
  .btn-load:hover { background: var(--gold-dim); }

  .at-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table.at { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 550px; }
  table.at th {
    background: #fafafa; color: var(--text-tertiary); font-size: 10.5px; font-weight: 600;
    letter-spacing: 0.07em; text-transform: uppercase; padding: 10px;
    border-bottom: 1px solid rgba(0,0,0,0.07); text-align: left; white-space: nowrap;
  }
  table.at td { padding: 9px 10px; border-bottom: 1px solid rgba(0,0,0,0.05); vertical-align: middle; }
  table.at tr:hover td { background: var(--bg-input); }
  table.at tr:last-child td { border-bottom: none; }

  .at-radio-group { display: flex; gap: 6px; }
  .at-radio-group label {
    display: flex; align-items: center; gap: 4px; cursor: pointer; padding: 5px 11px;
    border-radius: 20px; border: 1px solid var(--border); font-size: 12px; font-weight: 500;
    background: var(--bg-input); color: var(--text-secondary); transition: all 0.15s;
  }
  .at-radio-group input[type=radio] { display: none; }
  .lbl-P { background: #dcfce7 !important; color: #166534 !important; border-color: #16a34a !important; }
  .lbl-A { background: #fde8e8 !important; color: #b91c1c !important; border-color: #dc2626 !important; }
  .lbl-L { background: #fef9c3 !important; color: #854d0e !important; border-color: #d97706 !important; }

  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
  .dot-P { background: #16a34a; } .dot-A { background: #dc2626; } .dot-L { background: #d97706; }

  .btn-save-att {
    display: block; width: 100%; padding: 12px;
    background: var(--black); color: var(--gold); border: none;
    font-family: 'Bebas Neue', sans-serif; font-size: 1rem; letter-spacing: 0.1em;
    cursor: pointer; transition: background 0.15s;
  }
  .btn-save-att:hover { background: var(--mid); }

  table.monthly { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  table.monthly th {
    background: #fafafa; color: var(--text-tertiary); font-size: 10.5px; font-weight: 600;
    letter-spacing: 0.07em; text-transform: uppercase; padding: 10px;
    border-bottom: 1px solid rgba(0,0,0,0.07); text-align: left;
  }
  table.monthly td { padding: 9px 10px; border-bottom: 1px solid rgba(0,0,0,0.05); }
  table.monthly tr:last-child td { border-bottom: none; }
  .pct-bar-wrap { width: 70px; height: 6px; background: #e5e7eb; border-radius: 3px; display: inline-block; vertical-align: middle; margin-right: 6px; }
  .pct-bar { height: 100%; border-radius: 3px; }

  .at-empty { text-align: center; color: var(--text-tertiary); padding: 2.5rem 0; font-size: 14px; }
  .at-empty i { font-size: 28px; color: var(--gold); display: block; margin-bottom: 8px; }

  .at-alert { padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; }
  .at-success { background: #dcfce7; color: #166534; border: 1px solid #16a34a; }
  .at-error   { background: #fde8e8; color: #b91c1c; border: 1px solid #dc2626; }

  @media (max-width: 600px) {
    body { padding: 1rem; }
    .at-controls { flex-direction: column; }
    .btn-load { width: 100%; justify-content: center; }
    .at-stat-card { min-width: calc(50% - 6px); }
    .at-radio-group label { padding: 5px 8px; }
  }
</style>
</head>
<body>
<div class="at-wrapper">

  <?= $message ?>

  <div class="at-header">
    <div class="at-header-title"><i class="fa-solid fa-clipboard-check"></i> Student Attendance</div>
    <div class="at-header-date"><?= date('l, d M Y', strtotime($sel_date)) ?></div>
  </div>

  <div class="at-stats">
    <div class="at-stat-card"><div class="at-stat-val val-present"><?= $summary['Present'] ?></div><div class="at-stat-lbl">Present</div></div>
    <div class="at-stat-card"><div class="at-stat-val val-absent"><?= $summary['Absent'] ?></div><div class="at-stat-lbl">Absent</div></div>
    <div class="at-stat-card"><div class="at-stat-val val-late"><?= $summary['Late'] ?></div><div class="at-stat-lbl">Late</div></div>
    <div class="at-stat-card"><div class="at-stat-val val-total"><?= count($students) ?></div><div class="at-stat-lbl">Total Students</div></div>
  </div>

  <div class="at-card">
    <div class="at-card-head"><i class="fa-solid fa-sliders"></i><span>Select Grade &amp; Date</span></div>
    <div class="at-card-body">
      <form method="GET">
        <div class="at-controls">
          <div class="at-ctrl-field">
            <label>Grade</label>
            <select name="grade" onchange="this.form.submit()">
              <?php foreach ($all_grades as $g): ?>
                <option value="<?= htmlspecialchars($g) ?>" <?= $g == $sel_grade ? 'selected' : '' ?>>Grade <?= htmlspecialchars($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="at-ctrl-field">
            <label>Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($sel_date) ?>">
          </div>
          <button type="submit" class="btn-load"><i class="fa-solid fa-rotate"></i> Load</button>
        </div>
      </form>
    </div>
  </div>

  <div class="at-card">
    <div class="at-card-head"><i class="fa-solid fa-users"></i><span>Grade <?= htmlspecialchars($sel_grade) ?> — <?= date('d M Y', strtotime($sel_date)) ?></span></div>

    <?php if (count($students) > 0): ?>
    <form method="POST">
      <input type="hidden" name="action" value="save_attendance">
      <input type="hidden" name="att_date" value="<?= htmlspecialchars($sel_date) ?>">
      <input type="hidden" name="grade" value="<?= htmlspecialchars($sel_grade) ?>">

      <div class="at-table-wrap">
        <table class="at">
          <thead>
            <tr><th>#</th><th>Assess. No</th><th>First Name</th><th>Surname</th><th>Mark Attendance</th><th>Current Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($students as $i => $stu):
              $assess  = $stu['Assesment'];
              $cur     = $today_att[$assess] ?? 'Present';
              $dot_cls = ['Present'=>'dot-P','Absent'=>'dot-A','Late'=>'dot-L'][$cur] ?? 'dot-P';
            ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td style="font-family:monospace;font-size:11px;"><?= htmlspecialchars($assess) ?></td>
                <td><?= htmlspecialchars($stu['firstName']) ?></td>
                <td><?= htmlspecialchars($stu['surname']) ?></td>
                <td>
                  <div class="at-radio-group">
                    <?php foreach (['Present'=>'P','Absent'=>'A','Late'=>'L'] as $val=>$lbl): ?>
                      <label class="<?= $cur===$val ? 'lbl-'.substr($val,0,1) : '' ?>" id="lbl-<?= htmlspecialchars($assess) ?>-<?= $val[0] ?>">
                        <input type="radio" name="status[<?= htmlspecialchars($assess) ?>]" value="<?= $val ?>"
                               <?= $cur===$val ? 'checked' : '' ?>
                               onchange="updateRow(this,'<?= htmlspecialchars($assess) ?>')">
                        <?= $lbl ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td id="status-cell-<?= htmlspecialchars($assess) ?>">
                  <span class="dot <?= $dot_cls ?>"></span><?= $cur ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="btn-save-att"><i class="fa-solid fa-floppy-disk"></i>&nbsp; Save Attendance for <?= htmlspecialchars($sel_date) ?></button>
    </form>
    <?php else: ?>
      <div class="at-card-body">
        <div class="at-empty"><i class="fa-solid fa-user-xmark"></i>No students found for Grade <?= htmlspecialchars($sel_grade) ?>. Please register students first.</div>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($monthly_res && mysqli_num_rows($monthly_res) > 0): ?>
  <div class="at-card">
    <div class="at-card-head"><i class="fa-solid fa-chart-bar"></i><span>Monthly Report — <?= date('F Y', strtotime($sel_date)) ?> &middot; Grade <?= htmlspecialchars($sel_grade) ?></span></div>
    <div class="at-table-wrap">
      <table class="monthly">
        <thead><tr><th>Student</th><th>Present</th><th>Absent</th><th>Late</th><th>Total Days</th><th>Attendance %</th></tr></thead>
        <tbody>
          <?php while ($mr = mysqli_fetch_assoc($monthly_res)):
            $pct = $mr['total_days'] > 0 ? round(($mr['present_count'] / $mr['total_days']) * 100) : 0;
            $bar_color = $pct >= 80 ? '#16a34a' : ($pct >= 60 ? '#d97706' : '#dc2626');
            $txt_color = $pct >= 80 ? '#166534' : ($pct >= 60 ? '#854d0e' : '#b91c1c');
          ?>
            <tr>
              <td><?= htmlspecialchars($mr['student_name']) ?></td>
              <td><span class="dot dot-P"></span><?= $mr['present_count'] ?></td>
              <td><span class="dot dot-A"></span><?= $mr['absent_count'] ?></td>
              <td><span class="dot dot-L"></span><?= $mr['late_count'] ?></td>
              <td><?= $mr['total_days'] ?></td>
              <td>
                <div class="pct-bar-wrap"><div class="pct-bar" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div></div>
                <span style="color:<?= $txt_color ?>;font-weight:600;"><?= $pct ?>%</span>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>
<script>
const dotMap = { Present:'dot-P', Absent:'dot-A', Late:'dot-L' };
const lblMap = { Present:'lbl-P', Absent:'lbl-A', Late:'lbl-L' };
function updateRow(radio, assess) {
  const val = radio.value;
  ['Present','Absent','Late'].forEach(v => {
    const lbl = document.getElementById('lbl-' + assess + '-' + v[0]);
    if (lbl) lbl.className = '';
  });
  const activeLbl = document.getElementById('lbl-' + assess + '-' + val[0]);
  if (activeLbl) activeLbl.className = lblMap[val] || '';
  const cell = document.getElementById('status-cell-' + assess);
  if (cell) cell.innerHTML = '<span class="dot ' + (dotMap[val]||'') + '"></span>' + val;
}
</script>
</body>
</html>
<?php mysqli_close($conn); ?>
