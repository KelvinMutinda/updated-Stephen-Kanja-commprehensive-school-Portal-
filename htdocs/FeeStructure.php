<?php
include 'conn.php';
mysqli_report(MYSQLI_REPORT_OFF);

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grade = trim($_POST['grade'] ?? '');
    $term  = trim($_POST['term'] ?? '');
    $year  = trim($_POST['year'] ?? '');
    $fee   = (float)($_POST['fee'] ?? 0);
    $assess= (float)($_POST['assess'] ?? 0);
    $activity = (float)($_POST['activity'] ?? 0);
    $other = (float)($_POST['other'] ?? 0);

    if ($grade === '' || $term === '' || $year === '') {
        $errors[] = 'Grade, Term and Year are all required.';
    }

    if (!$errors) {
        $stmt = $conn->prepare(
            "INSERT INTO FeeStructure (Grade, Term, Year, ExpectedFee, ExpectedAssesmentFee, ExpectedActivity, ExpectedOther)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               ExpectedFee = VALUES(ExpectedFee),
               ExpectedAssesmentFee = VALUES(ExpectedAssesmentFee),
               ExpectedActivity = VALUES(ExpectedActivity),
               ExpectedOther = VALUES(ExpectedOther)"
        );
        $stmt->bind_param('sssdddd', $grade, $term, $year, $fee, $assess, $activity, $other);
        if ($stmt->execute()) {
            $saved = true;
        } else {
            $errors[] = 'Could not save: ' . $stmt->error;
        }
        $stmt->close();
    }
}

$rows = mysqli_query($conn,
    "SELECT * FROM FeeStructure ORDER BY Year DESC, Grade+0 ASC, Term+0 ASC"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fee Structure — Stephen Kanja School</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:'DM Sans',sans-serif; background:#f4f4f2; color:#1a1a18; padding:2rem; }
  .wrap { max-width:900px; margin:0 auto; display:flex; flex-direction:column; gap:1.5rem; }
  h1 { font-family:'Bebas Neue',sans-serif; font-size:1.8rem; letter-spacing:.08em; }
  .card { background:#fff; border:1px solid rgba(0,0,0,.09); border-radius:12px; overflow:hidden; }
  .card-head { background:#111; color:#fff; padding:.8rem 1.4rem; border-bottom:2px solid #f0c040; font-weight:600; letter-spacing:.06em; font-size:13px; }
  .card-body { padding:1.4rem; }
  .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.9rem; margin-bottom:1rem; }
  .grid label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#5f5e5a; display:block; margin-bottom:5px; }
  .grid input { width:100%; padding:9px 11px; border:1px solid rgba(0,0,0,.12); border-radius:8px; font-size:13px; }
  button { background:#f0c040; border:none; padding:10px 20px; border-radius:8px; font-weight:600; cursor:pointer; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#888780; padding:9px 12px; border-bottom:1px solid rgba(0,0,0,.08); }
  td { padding:9px 12px; border-bottom:1px solid rgba(0,0,0,.05); }
  .total-cell { font-weight:700; color:#16a34a; font-family:monospace; }
  .msg-ok { background:#dcfce7; color:#166534; padding:10px 14px; border-radius:8px; font-size:13px; }
  .msg-err { background:#fee2e2; color:#991b1b; padding:10px 14px; border-radius:8px; font-size:13px; }
  a { color:#111; }
</style>
</head>
<body>
<div class="wrap">
  <div>
    <h1>Fee Structure</h1>
    <p style="font-size:13px;color:#888;margin-top:4px;">Set the expected fee amounts per Grade / Term / Year — receipts use this to calculate balances.</p>
  </div>

  <?php if ($saved): ?><div class="msg-ok">Saved.</div><?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="msg-err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

  <div class="card">
    <div class="card-head">Set / Update Expected Fee</div>
    <div class="card-body">
      <form method="POST">
        <div class="grid">
          <div><label>Grade</label><input name="grade" required placeholder="e.g. 4"></div>
          <div><label>Term</label><input name="term" required placeholder="e.g. 1"></div>
          <div><label>Year</label><input name="year" required placeholder="e.g. 2026"></div>
          <div><label>School Fee</label><input name="fee" type="number" step="0.01" required></div>
          <div><label>Assessment Fee</label><input name="assess" type="number" step="0.01" required></div>
          <div><label>Activity</label><input name="activity" type="number" step="0.01" required></div>
          <div><label>Other</label><input name="other" type="number" step="0.01" required></div>
        </div>
        <button type="submit">Save</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Current Fee Structure</div>
    <div class="card-body" style="padding:0;">
      <table>
        <thead>
          <tr><th>Grade</th><th>Term</th><th>Year</th><th>School Fee</th><th>Assess.</th><th>Activity</th><th>Other</th><th>Total Expected</th></tr>
        </thead>
        <tbody>
          <?php if ($rows && mysqli_num_rows($rows) > 0): while ($r = mysqli_fetch_assoc($rows)):
            $total = $r['ExpectedFee'] + $r['ExpectedAssesmentFee'] + $r['ExpectedActivity'] + $r['ExpectedOther'];
          ?>
          <tr>
            <td>Grade <?= htmlspecialchars($r['Grade']) ?></td>
            <td>Term <?= htmlspecialchars($r['Term']) ?></td>
            <td><?= htmlspecialchars($r['Year']) ?></td>
            <td>KES <?= number_format($r['ExpectedFee'], 0) ?></td>
            <td>KES <?= number_format($r['ExpectedAssesmentFee'], 0) ?></td>
            <td>KES <?= number_format($r['ExpectedActivity'], 0) ?></td>
            <td>KES <?= number_format($r['ExpectedOther'], 0) ?></td>
            <td class="total-cell">KES <?= number_format($total, 0) ?></td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="8" style="text-align:center;color:#888;padding:2rem;">No fee structure set yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <a href="ViewFees.php">&larr; Back to Fee Payments</a>
</div>
</body>
</html>
<?php mysqli_close($conn); ?>