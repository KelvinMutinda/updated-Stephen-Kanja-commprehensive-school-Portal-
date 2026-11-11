<?php
include 'conn.php';
mysqli_report(MYSQLI_REPORT_OFF);

$assessment = trim($_GET['assessment'] ?? '');
$grade      = trim($_GET['grade'] ?? '');
$term       = trim($_GET['term'] ?? '');
$year       = trim($_GET['year'] ?? '');

if ($assessment === '' || $grade === '' || $term === '' || $year === '') {
    die('Missing assessment number, grade, term or year. <a href="ViewFees.php">Go back</a>.');
}

// ── Student bio (authoritative source, not the denormalized copy on Fees) ──
$stmt = $conn->prepare("SELECT firstName, middleName, surname, UPI FROM Student WHERE Assesment = ? LIMIT 1");
$stmt->bind_param('s', $assessment);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    die('No student found for that assessment number. <a href="ViewFees.php">Go back</a>.');
}

// ── Every payment this student made for this Grade/Term/Year ──
$stmt = $conn->prepare(
    "SELECT * FROM Fees WHERE Assesment = ? AND Grade = ? AND Term = ? AND Year = ? ORDER BY payment_date ASC"
);
$stmt->bind_param('ssss', $assessment, $grade, $term, $year);
$stmt->execute();
$payments = $stmt->get_result();
$paymentRows = [];
while ($r = $payments->fetch_assoc()) $paymentRows[] = $r;
$stmt->close();

$paidFee = array_sum(array_column($paymentRows, 'Fee'));
$paidAssess = array_sum(array_column($paymentRows, 'AssesmentFee'));
$paidActivity = array_sum(array_column($paymentRows, 'Activity'));
$paidOther = array_sum(array_column($paymentRows, 'Other'));
$paidTotal = $paidFee + $paidAssess + $paidActivity + $paidOther;

// ── Expected total for this Grade/Term/Year ──
$stmt = $conn->prepare("SELECT * FROM FeeStructure WHERE Grade = ? AND Term = ? AND Year = ? LIMIT 1");
$stmt->bind_param('sss', $grade, $term, $year);
$stmt->execute();
$structure = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hasStructure = (bool)$structure;
$expectedTotal = $hasStructure
    ? $structure['ExpectedFee'] + $structure['ExpectedAssesmentFee'] + $structure['ExpectedActivity'] + $structure['ExpectedOther']
    : null;
$balance = $hasStructure ? $expectedTotal - $paidTotal : null;

$receiptRef = 'RCPT-' . strtoupper($assessment) . '-G' . $grade . 'T' . $term . '-' . $year;
$fullName = trim($student['firstName'] . ' ' . ($student['middleName'] ?? '') . ' ' . $student['surname']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt — <?= htmlspecialchars($fullName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:'DM Sans',sans-serif; background:#f4f4f2; color:#1a1a18; padding:2rem; }
  .wrap { max-width:640px; margin:0 auto; }
  .toolbar { display:flex; justify-content:flex-end; gap:10px; margin-bottom:1rem; }
  .toolbar a, .toolbar button {
    font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px;
    border:1px solid rgba(0,0,0,.12); background:#fff; cursor:pointer; text-decoration:none; color:#111;
  }
  .toolbar .print-btn { background:#f0c040; border-color:#f0c040; }

  .receipt { background:#fff; border:1px solid rgba(0,0,0,.09); border-radius:12px; overflow:hidden; }
  .r-head { background:#111; color:#fff; padding:1.4rem; border-bottom:3px solid #f0c040; display:flex; justify-content:space-between; align-items:center; }
  .r-head .school { font-family:'Bebas Neue',sans-serif; font-size:1.4rem; letter-spacing:.08em; }
  .r-head .school span { color:#f0c040; }
  .r-head .motto { font-size:10px; color:#999; letter-spacing:.1em; margin-top:2px; }
  .r-head .ref { text-align:right; font-size:11px; color:#ccc; }
  .r-head .ref strong { color:#f0c040; display:block; font-size:13px; }

  .r-body { padding:1.6rem; }
  .r-title { font-family:'Bebas Neue',sans-serif; font-size:1.3rem; letter-spacing:.06em; margin-bottom:1rem; }

  .bio-grid { display:grid; grid-template-columns:1fr 1fr; gap:.6rem 1.5rem; margin-bottom:1.6rem; font-size:13px; }
  .bio-grid .label { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#888780; }
  .bio-grid .value { font-weight:600; }

  table.pay-table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:1.2rem; }
  .pay-table th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#888780; padding:8px 4px; border-bottom:1px solid rgba(0,0,0,.08); }
  .pay-table td { padding:8px 4px; border-bottom:1px solid rgba(0,0,0,.05); }
  .pay-table .amt { font-family:monospace; text-align:right; }

  .summary { border-top:2px solid #f0c040; padding-top:1rem; margin-top:.6rem; }
  .summary-row { display:flex; justify-content:space-between; font-size:13.5px; padding:5px 0; }
  .summary-row.total { font-weight:700; font-size:15px; }
  .summary-row.paid .amt { color:#16a34a; font-family:monospace; }
  .summary-row.balance .amt { font-family:monospace; font-weight:700; }
  .balance-owing .amt { color:#dc2626; }
  .balance-clear .amt { color:#16a34a; }
  .balance-overpaid .amt { color:#2563eb; }

  .no-structure {
    background:#fff7e6; border:1px solid #f0c040; color:#7a5c05;
    border-radius:8px; padding:12px 14px; font-size:12.5px; margin-top:1rem;
  }

  .r-footer { padding:1rem 1.6rem; border-top:1px solid rgba(0,0,0,.07); font-size:11px; color:#888; text-align:center; }

  @media print {
    .toolbar { display:none; }
    body { background:#fff; padding:0; }
    .wrap { max-width:100%; }
    .receipt { border:none; border-radius:0; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="toolbar">
    <a href="ViewFees.php">&larr; Back</a>
    <button class="print-btn" onclick="window.print()">Print Receipt</button>
  </div>

  <div class="receipt">
    <div class="r-head">
      <div>
        <div class="school">Stephen Kanja <span>School</span></div>
        <div class="motto">AIM HIGHER</div>
      </div>
      <div class="ref">
        Receipt Ref
        <strong><?= htmlspecialchars($receiptRef) ?></strong>
      </div>
    </div>

    <div class="r-body">
      <div class="r-title">Fee Payment Receipt</div>

      <div class="bio-grid">
        <div><div class="label">Student</div><div class="value"><?= htmlspecialchars($fullName) ?></div></div>
        <div><div class="label">Assessment No.</div><div class="value"><?= htmlspecialchars($assessment) ?></div></div>
        <div><div class="label">Grade</div><div class="value">Grade <?= htmlspecialchars($grade) ?></div></div>
        <div><div class="label">Term / Year</div><div class="value">Term <?= htmlspecialchars($term) ?>, <?= htmlspecialchars($year) ?></div></div>
      </div>

      <?php if (count($paymentRows) > 0): ?>
      <table class="pay-table">
        <thead>
          <tr><th>Date</th><th>School Fee</th><th>Assess.</th><th>Activity</th><th>Other</th><th class="amt">Line Total</th></tr>
        </thead>
        <tbody>
          <?php foreach ($paymentRows as $p):
            $lineTotal = $p['Fee'] + $p['AssesmentFee'] + $p['Activity'] + $p['Other'];
          ?>
          <tr>
            <td><?= !empty($p['payment_date']) ? date('d M Y', strtotime($p['payment_date'])) : '—' ?></td>
            <td>KES <?= number_format($p['Fee'], 0) ?></td>
            <td>KES <?= number_format($p['AssesmentFee'], 0) ?></td>
            <td>KES <?= number_format($p['Activity'], 0) ?></td>
            <td>KES <?= number_format($p['Other'], 0) ?></td>
            <td class="amt">KES <?= number_format($lineTotal, 0) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <p style="font-size:13px;color:#888;margin-bottom:1rem;">No payments recorded yet for this period.</p>
      <?php endif; ?>

      <div class="summary">
        <div class="summary-row paid">
          <span>Total Paid</span>
          <span class="amt">KES <?= number_format($paidTotal, 0) ?></span>
        </div>

        <?php if ($hasStructure): ?>
          <div class="summary-row total">
            <span>Expected Total (Grade <?= htmlspecialchars($grade) ?>, Term <?= htmlspecialchars($term) ?>, <?= htmlspecialchars($year) ?>)</span>
            <span class="amt">KES <?= number_format($expectedTotal, 0) ?></span>
          </div>
          <?php
            $balanceClass = $balance > 0 ? 'balance-owing' : ($balance < 0 ? 'balance-overpaid' : 'balance-clear');
            $balanceLabel = $balance > 0 ? 'Balance Owing' : ($balance < 0 ? 'Overpaid' : 'Balance');
          ?>
          <div class="summary-row balance <?= $balanceClass ?>">
            <span><?= $balanceLabel ?></span>
            <span class="amt">KES <?= number_format(abs($balance), 0) ?></span>
          </div>
        <?php else: ?>
          <div class="no-structure">
            No fee structure has been set for Grade <?= htmlspecialchars($grade) ?>, Term <?= htmlspecialchars($term) ?>,
            <?= htmlspecialchars($year) ?> yet, so a balance can't be calculated.
            <a href="FeeStructure.php">Set it here</a>.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="r-footer">
      Generated <?= date('d M Y, g:i A') ?> &middot; Stephen Kanja School Management System
    </div>
  </div>
</div>
</body>
</html>
<?php mysqli_close($conn); ?>