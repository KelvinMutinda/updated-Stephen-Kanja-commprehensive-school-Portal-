<?php
/**
 * ClassRankings.php
 *
 * Ranks Grade 1–9 classes by average academic performance for a
 * chosen Term / Exam Type / Year (any of the three can be left as
 * "All" to widen the comparison). A class's score is computed as the
 * mean of each student's own average across their non-null subject
 * marks in exam2 for the matched rows, then averaged across all
 * students in that grade — so a grade with fewer entered subjects
 * per student isn't unfairly penalised relative to one with more.
 *
 * SCHEMA: exam2 columns — student_id, grade, term, exam_type, year,
 * math, eng, kisw, sst, scie, ca, agri, re, pretec.
 */

include 'conn.php';
mysqli_report(MYSQLI_REPORT_OFF);

$term     = isset($_GET['term']) ? mysqli_real_escape_string($conn, trim($_GET['term'])) : '';
$examType = isset($_GET['examType']) ? mysqli_real_escape_string($conn, trim($_GET['examType'])) : '';
$year     = isset($_GET['year']) ? mysqli_real_escape_string($conn, trim($_GET['year'])) : '';

$where = '1=1';
if ($term !== '')     $where .= " AND term = '$term'";
if ($examType !== '') $where .= " AND exam_type = '$examType'";
if ($year !== '')     $where .= " AND year = '$year'";

$subjectCols = ['math', 'eng', 'kisw', 'sst', 'scie', 'ca', 'agri', 're', 'pretec'];
$subjectLabels = [
    'math' => 'Maths', 'eng' => 'English', 'kisw' => 'Kiswahili', 'sst' => 'Soc. Studies',
    'scie' => 'Science', 'ca' => 'C/A', 'agri' => 'Agriculture', 're' => 'RE', 'pretec' => 'Pre-Tech',
];

$sql = "SELECT grade, " . implode(', ', $subjectCols) . " FROM exam2 WHERE $where";
$result = mysqli_query($conn, $sql);

$gradeStats = []; // grade => ['sum' => studentAvgTotal, 'count' => studentsWithMarks, 'subjSums' => [...], 'subjCounts' => [...]]
$dbError = null;

if ($result === false) {
    $dbError = mysqli_error($conn);
} else {
    while ($row = mysqli_fetch_assoc($result)) {
        $g = $row['grade'];
        $marks = [];

        if (!isset($gradeStats[$g])) {
            $gradeStats[$g] = ['sum' => 0, 'count' => 0, 'subjSums' => array_fill_keys($subjectCols, 0), 'subjCounts' => array_fill_keys($subjectCols, 0)];
        }

        foreach ($subjectCols as $col) {
            if ($row[$col] !== null && $row[$col] !== '') {
                $val = (float) $row[$col];
                $marks[] = $val;
                $gradeStats[$g]['subjSums'][$col] += $val;
                $gradeStats[$g]['subjCounts'][$col] += 1;
            }
        }

        if (empty($marks)) continue; // this exam2 row has no marks at all — skip for the overall average

        $studentAvg = array_sum($marks) / count($marks);
        $gradeStats[$g]['sum'] += $studentAvg;
        $gradeStats[$g]['count'] += 1;
    }
}

$ranking = [];
foreach ($gradeStats as $g => $stat) {
    if ($stat['count'] === 0) continue;
    $ranking[] = [
        'grade'    => $g,
        'average'  => $stat['sum'] / $stat['count'],
        'students' => $stat['count'],
        'subjSums'   => $stat['subjSums'],
        'subjCounts' => $stat['subjCounts'],
    ];
}
usort($ranking, fn($a, $b) => $b['average'] <=> $a['average']);

function bandFor($avg) {
    if ($avg >= 75) return ['Exceeding Expectation', '#2e7d32'];
    if ($avg >= 50) return ['Meeting Expectation', '#2563eb'];
    if ($avg >= 30) return ['Approaching Expectation', '#c9a030'];
    return ['Below Expectation', '#c62828'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class Rankings — Stephen Kanja School</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --gold: #f0c040; --gold-dim: #c9a030; --black: #111111; --dark: #1a1a1a; --mid: #2a2a2a;
    --bg: #f4f4f2; --bg-card: #ffffff; --bg-input: #f8f8f6;
    --text-primary: #1a1a18; --text-secondary: #5f5e5a; --text-tertiary: #888780;
    --border: rgba(0,0,0,0.09); --radius-md: 8px; --radius-lg: 12px;
  }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; }
  .site-header {
    background: var(--black); border-bottom: 3px solid var(--gold);
    display: flex; align-items: center; gap: 1rem; padding: 0 1.6rem; height: 62px;
  }
  .school-logo { width: 36px; height: 36px; border-radius: 8px; background: var(--gold); display: flex; align-items: center; justify-content: center; }
  .school-logo i { color: var(--black); font-size: 16px; }
  .school-name { font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem; letter-spacing: 0.12em; color: #fff; }
  .school-name span { color: var(--gold); }
  .main { max-width: 1000px; margin: 0 auto; padding: 2rem 1.5rem 3rem; display: flex; flex-direction: column; gap: 1.5rem; }
  .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--gold-dim); margin-bottom: 4px; }
  .page-title { font-size: 24px; font-weight: 400; }
  .filter-card, .table-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
  .filter-card-head, .table-card-header {
    background: var(--black); border-bottom: 2px solid var(--gold); padding: 0.8rem 1.4rem;
    display: flex; align-items: center; gap: 8px; justify-content: space-between;
  }
  .filter-card-head span, .table-card-header span.label {
    font-family: 'Bebas Neue', sans-serif; font-size: 1rem; letter-spacing: 0.1em; color: #fff;
    display: flex; align-items: center; gap: 8px;
  }
  .filter-card-head i, .table-card-header i { color: var(--gold); }
  .filter-body { padding: 1.4rem 1.6rem; }
  .filter-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem 1.2rem; align-items: end; }
  .filter-field { display: flex; flex-direction: column; gap: 6px; }
  .filter-field label { font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-secondary); }
  .filter-field select {
    padding: 9px 32px 9px 12px; font-size: 13.5px; background-color: var(--bg-input);
    border: 1px solid rgba(0,0,0,0.1); border-radius: var(--radius-md); outline: none; cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888780' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center; appearance: none;
  }
  .btn-filter {
    font-size: 13px; font-weight: 600; color: var(--black); background: var(--gold); border: none;
    border-radius: var(--radius-md); padding: 9px 18px; cursor: pointer;
  }
  .count-badge { background: var(--gold); color: var(--black); font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px; }
  table.rank-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .rank-table thead th {
    background: #fafafa; color: var(--text-tertiary); font-size: 10.5px; font-weight: 600;
    letter-spacing: 0.08em; text-transform: uppercase; padding: 10px; border-bottom: 1px solid rgba(0,0,0,0.07); text-align: left;
  }
  .rank-table tbody tr { border-bottom: 1px solid rgba(0,0,0,0.05); }
  .rank-table tbody tr:nth-child(even) { background: rgba(0,0,0,0.012); }
  .rank-table td { padding: 10px; vertical-align: middle; }
  .rank-pos {
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; border-radius: 50%; font-weight: 700; font-size: 12px; color: #fff; background: var(--mid);
  }
  .rank-pos.gold { background: #f0c040; color: #111; }
  .rank-pos.silver { background: #c9ccd1; color: #111; }
  .rank-pos.bronze { background: #cd8a52; color: #111; }
  .band-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; color: #fff; display: inline-block; }
  .avg-score { font-weight: 700; font-size: 15px; }
  .empty-state { text-align: center; padding: 3rem; color: var(--text-tertiary); }
  .subj-breakdown { font-size: 11px; color: var(--text-tertiary); margin-top: 3px; }
  @media (max-width: 640px) {
    .filter-grid { grid-template-columns: 1fr; }
    table.rank-table { font-size: 12px; }
    .rank-table thead th:nth-child(4), .rank-table td:nth-child(4) { display: none; }
  }
</style>
</head>
<body>

<header class="site-header">
  <div class="school-logo"><i class="fa-solid fa-graduation-cap"></i></div>
  <div class="school-name">Stephen Kanja <span>School</span></div>
</header>

<main class="main">
  <div>
    <p class="page-eyebrow">Academic Performance</p>
    <h1 class="page-title">Class Rankings</h1>
  </div>

  <form method="GET" class="filter-card">
    <div class="filter-card-head"><i class="fa-solid fa-sliders"></i><span>Filter</span></div>
    <div class="filter-body">
      <div class="filter-grid">
        <div class="filter-field">
          <label for="term">Term</label>
          <select id="term" name="term">
            <option value="" <?= $term === '' ? 'selected' : '' ?>>All Terms</option>
            <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
            <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
            <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="examType">Exam Type</label>
          <select id="examType" name="examType">
            <option value="" <?= $examType === '' ? 'selected' : '' ?>>All Exam Types</option>
            <option value="opener" <?= $examType === 'opener' ? 'selected' : '' ?>>Opener</option>
            <option value="midterm" <?= $examType === 'midterm' ? 'selected' : '' ?>>Midterm</option>
            <option value="endterm" <?= $examType === 'endterm' ? 'selected' : '' ?>>End Term</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="year">Year</label>
          <select id="year" name="year">
            <option value="" <?= $year === '' ? 'selected' : '' ?>>All Years</option>
            <?php for ($y = 2026; $y <= 2030; $y++): ?>
              <option value="<?= $y ?>" <?= $year === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
      <div style="margin-top:1rem;">
        <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Apply Filter</button>
      </div>
    </div>
  </form>

  <div class="table-card">
    <div class="table-card-header">
      <span class="label"><i class="fa-solid fa-ranking-star"></i> Ranked by Average Score</span>
      <span class="count-badge"><?= count($ranking) ?> class<?= count($ranking) !== 1 ? 'es' : '' ?></span>
    </div>

    <?php if ($dbError): ?>
      <div class="empty-state"><i class="fa-solid fa-triangle-exclamation" style="color:#c62828;font-size:20px;display:block;margin-bottom:8px;"></i>
        Database error: <?= htmlspecialchars($dbError) ?></div>
    <?php elseif (empty($ranking)): ?>
      <div class="empty-state"><i class="fa-solid fa-inbox" style="font-size:20px;display:block;margin-bottom:8px;"></i>
        No marks found for this filter yet.</div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="rank-table">
          <thead>
            <tr>
              <th>Rank</th>
              <th>Class</th>
              <th>Average Score</th>
              <th>Performance Band</th>
              <th>Students Assessed</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ranking as $i => $r):
              $pos = $i + 1;
              $posClass = $pos === 1 ? 'gold' : ($pos === 2 ? 'silver' : ($pos === 3 ? 'bronze' : ''));
              [$bandLabel, $bandColor] = bandFor($r['average']);

              // Best-performing subject in this class, for the small breakdown line
              $bestSubj = null; $bestSubjAvg = -1;
              foreach ($subjectCols as $col) {
                  if ($r['subjCounts'][$col] > 0) {
                      $a = $r['subjSums'][$col] / $r['subjCounts'][$col];
                      if ($a > $bestSubjAvg) { $bestSubjAvg = $a; $bestSubj = $col; }
                  }
              }
            ?>
            <tr>
              <td><span class="rank-pos <?= $posClass ?>">#<?= $pos ?></span></td>
              <td>
                Grade <?= htmlspecialchars($r['grade']) ?>
                <?php if ($bestSubj): ?>
                  <div class="subj-breakdown">Strongest subject: <?= $subjectLabels[$bestSubj] ?> (<?= round($bestSubjAvg, 1) ?>)</div>
                <?php endif; ?>
              </td>
              <td><span class="avg-score"><?= round($r['average'], 1) ?></span></td>
              <td><span class="band-pill" style="background:<?= $bandColor ?>;"><?= $bandLabel ?></span></td>
              <td><?= $r['students'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

</body>
</html>