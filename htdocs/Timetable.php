<?php
// ============================================================
// timetable.php — Class Timetable
// Stephen Kanja School Management System
// ============================================================
include 'conn.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Auto-create timetable table ──────────────────────────────
mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS timetable (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        grade        VARCHAR(20)  NOT NULL,
        day          VARCHAR(15)  NOT NULL,
        period       VARCHAR(20)  NOT NULL,
        subject      VARCHAR(50)  NOT NULL,
        teacher_name VARCHAR(100) DEFAULT '',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_slot (grade, day, period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$days     = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$periods  = ['7:30 - 8:30', '8:30 - 9:30', '9:30 - 10:00', '10:00 - 11:00', '11:00 - 12:00', '12:00 - 1:00', '1:00 - 2:00'];
$grades   = ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9'];
$subjects = ['Mathematics', 'English', 'Kiswahili', 'Science', 'Social Studies', 'CRE', 'Art & Craft', 'PE', 'Break', 'Lunch Break'];

$subject_colors = [
    'Mathematics'    => ['#dbeafe', '#1e40af'],
    'English'        => ['#dcfce7', '#166534'],
    'Kiswahili'      => ['#fde8e8', '#b91c1c'],
    'Science'        => ['#fef9c3', '#854d0e'],
    'Social Studies' => ['#ede9fe', '#6d28d9'],
    'CRE'            => ['#fce7f3', '#9d174d'],
    'Art & Craft'    => ['#ffedd5', '#9a3412'],
    'PE'             => ['#cffafe', '#155e75'],
    'Break'          => ['#f3f4f6', '#4b5563'],
    'Lunch Break'    => ['#f3f4f6', '#4b5563'],
];

$message = '';

// ── POST: Add / delete entry ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_entry') {
        $grade   = mysqli_real_escape_string($conn, $_POST['grade']);
        $day     = mysqli_real_escape_string($conn, $_POST['day']);
        $period  = mysqli_real_escape_string($conn, $_POST['period']);
        $subject = mysqli_real_escape_string($conn, $_POST['subject']);
        $teacher = mysqli_real_escape_string($conn, trim($_POST['teacher'] ?? ''));

        mysqli_query($conn, "DELETE FROM timetable WHERE grade='$grade' AND day='$day' AND period='$period'");

        $sql = "INSERT INTO timetable (grade, day, period, subject, teacher_name)
                VALUES ('$grade', '$day', '$period', '$subject', '$teacher')";

        if (mysqli_query($conn, $sql)) {
            $message = '<div class="tt-alert tt-success"><i class="fa-solid fa-check"></i> Timetable entry saved.</div>';
        } else {
            $message = '<div class="tt-alert tt-error">Error: ' . mysqli_error($conn) . '</div>';
        }
    }

    if ($_POST['action'] === 'delete_entry' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if (mysqli_query($conn, "DELETE FROM timetable WHERE id = $id")) {
            $message = '<div class="tt-alert tt-success"><i class="fa-solid fa-check"></i> Entry removed.</div>';
        } else {
            $message = '<div class="tt-alert tt-error">Error: ' . mysqli_error($conn) . '</div>';
        }
    }
}

// ── Selected grade ──────────────────────────────────────────
$selected_grade = (isset($_GET['grade']) && in_array($_GET['grade'], $grades))
    ? $_GET['grade'] : 'Grade 8';

// ── Build timetable grid ────────────────────────────────────
$grid = [];
$res  = mysqli_query($conn, "SELECT * FROM timetable WHERE grade='" . mysqli_real_escape_string($conn, $selected_grade) . "'");
while ($row = mysqli_fetch_assoc($res)) {
    $grid[$row['day']][$row['period']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Timetable — Stephen Kanja School</title>
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
  .tt-wrapper { max-width: 1100px; margin: 0 auto; display: flex; flex-direction: column; gap: 1.2rem; }

  .tt-header {
    background: var(--black); border-bottom: 3px solid var(--gold);
    padding: 1rem 1.4rem; border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;
  }
  .tt-header-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem; letter-spacing: 0.1em; color: #fff; }
  .tt-header-title i { color: var(--gold); margin-right: 6px; }
  .tt-header-sub { font-size: 12px; color: #555; }

  .tt-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
  .tt-card-head {
    background: var(--black); border-bottom: 2px solid var(--gold);
    padding: 0.7rem 1.2rem; display: flex; align-items: center; gap: 8px;
  }
  .tt-card-head span { font-family: 'Bebas Neue', sans-serif; font-size: 0.95rem; letter-spacing: 0.1em; color: #fff; }
  .tt-card-head i { color: var(--gold); font-size: 12px; }
  .tt-card-body { padding: 1.2rem 1.4rem; }

  .tt-grade-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
  .tt-grade-tab {
    padding: 5px 14px; border-radius: 20px; border: 1px solid var(--border);
    background: var(--bg-input); cursor: pointer; font-size: 12px; font-weight: 500;
    text-decoration: none; color: var(--text-secondary); transition: all 0.15s;
  }
  .tt-grade-tab:hover, .tt-grade-tab.active { background: var(--black); color: var(--gold); border-color: transparent; }

  .tt-form-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .tt-form-row select, .tt-form-row input {
    flex: 1; min-width: 140px; padding: 9px 12px;
    font-family: 'DM Sans', sans-serif; font-size: 13px;
    background: var(--bg-input); border: 1px solid rgba(0,0,0,0.1);
    border-radius: var(--radius-md); outline: none; color: var(--text-primary);
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .tt-form-row select:focus, .tt-form-row input:focus {
    border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,192,64,0.12);
  }
  .btn-save {
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
    color: var(--black); background: var(--gold); border: none;
    border-radius: var(--radius-md); padding: 9px 22px; cursor: pointer;
    display: flex; align-items: center; gap: 6px; transition: background 0.15s; white-space: nowrap;
  }
  .btn-save:hover { background: var(--gold-dim); }

  .tt-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table.tt { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 700px; }
  table.tt th {
    background: var(--black); color: var(--gold);
    padding: 10px 8px; text-align: center; font-family: 'Bebas Neue', sans-serif;
    font-size: 13px; letter-spacing: 0.08em; white-space: nowrap;
  }
  table.tt td { border: 1px solid rgba(0,0,0,0.06); padding: 8px 6px; text-align: center; vertical-align: middle; }
  table.tt tr:hover td { background: var(--bg-input); }
  .tt-period-label { font-weight: 600; font-size: 11px; color: var(--text-secondary); white-space: nowrap; text-align: left !important; padding-left: 10px !important; }
  .subj-pill { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
  .tt-teacher { font-size: 10px; color: var(--text-tertiary); margin-top: 2px; }
  .tt-empty-cell { color: #ccc; font-size: 12px; }
  .del-btn {
    background: none; border: none; color: #ddd; cursor: pointer;
    font-size: 11px; margin-left: 4px; padding: 2px 5px; border-radius: 4px; transition: all 0.15s;
  }
  .del-btn:hover { color: #b91c1c; background: #fde8e8; }

  .tt-alert { padding: 10px 14px; border-radius: var(--radius-md); font-size: 13px; }
  .tt-success { background: #dcfce7; color: #166534; border: 1px solid #16a34a; }
  .tt-error   { background: #fde8e8; color: #b91c1c; border: 1px solid #dc2626; }

  @media (max-width: 600px) {
    body { padding: 1rem; }
    .tt-form-row { flex-direction: column; }
    .btn-save { width: 100%; justify-content: center; }
  }
</style>
</head>
<body>
<div class="tt-wrapper">

  <?= $message ?>

  <div class="tt-header">
    <div class="tt-header-title"><i class="fa-solid fa-calendar-days"></i> Class Timetable</div>
    <div class="tt-header-sub">Term 1 &middot; 2026</div>
  </div>

  <div class="tt-card">
    <div class="tt-card-head">
      <i class="fa-solid fa-layer-group"></i>
      <span>Select Grade</span>
    </div>
    <div class="tt-card-body">
      <div class="tt-grade-tabs">
        <?php foreach ($grades as $g): ?>
          <a href="?grade=<?= urlencode($g) ?>"
             class="tt-grade-tab <?= $g === $selected_grade ? 'active' : '' ?>">
            <?= htmlspecialchars($g) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="tt-card">
    <div class="tt-card-head">
      <i class="fa-solid fa-plus"></i>
      <span>Add / Edit Entry</span>
    </div>
    <div class="tt-card-body">
      <form method="POST">
        <input type="hidden" name="action" value="add_entry">
        <div class="tt-form-row">
          <select name="grade">
            <?php foreach ($grades as $g): ?>
              <option value="<?= $g ?>" <?= $g === $selected_grade ? 'selected' : '' ?>><?= $g ?></option>
            <?php endforeach; ?>
          </select>
          <select name="day">
            <?php foreach ($days as $d): ?>
              <option value="<?= $d ?>"><?= $d ?></option>
            <?php endforeach; ?>
          </select>
          <select name="period">
            <?php foreach ($periods as $p): ?>
              <option value="<?= $p ?>"><?= $p ?></option>
            <?php endforeach; ?>
          </select>
          <select name="subject">
            <?php foreach ($subjects as $s): ?>
              <option value="<?= $s ?>"><?= $s ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="teacher" placeholder="Teacher name (optional)">
          <button type="submit" class="btn-save">
            <i class="fa-solid fa-floppy-disk"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="tt-card">
    <div class="tt-card-head">
      <i class="fa-solid fa-table"></i>
      <span><?= htmlspecialchars($selected_grade) ?> — Weekly Schedule</span>
    </div>
    <div class="tt-table-wrap">
      <table class="tt">
        <thead>
          <tr>
            <th style="text-align:left;padding-left:10px;">Time</th>
            <?php foreach ($days as $d): ?>
              <th><?= $d ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periods as $period): ?>
            <tr>
              <td class="tt-period-label"><?= htmlspecialchars($period) ?></td>
              <?php foreach ($days as $day): ?>
                <td>
                  <?php if (isset($grid[$day][$period])): ?>
                    <?php
                      $entry  = $grid[$day][$period];
                      $subj   = $entry['subject'];
                      $colors = $subject_colors[$subj] ?? ['#f3f4f6', '#374151'];
                    ?>
                    <span class="subj-pill" style="background:<?= $colors[0] ?>;color:<?= $colors[1] ?>">
                      <?= htmlspecialchars($subj) ?>
                    </span>
                    <?php if (!empty($entry['teacher_name'])): ?>
                      <div class="tt-teacher"><?= htmlspecialchars($entry['teacher_name']) ?></div>
                    <?php endif; ?>
                    <form method="POST" style="display:inline">
                      <input type="hidden" name="action" value="delete_entry">
                      <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                      <button type="submit" class="del-btn" title="Remove"
                              onclick="return confirm('Remove this entry?')">
                        <i class="fa-solid fa-xmark"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="tt-empty-cell">—</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
<?php mysqli_close($conn); ?>