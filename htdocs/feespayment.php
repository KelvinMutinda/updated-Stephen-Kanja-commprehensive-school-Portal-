<?php
/*
 * feespayment.php
 * ================
 * Saves submitted fee rows from Fee.php.
 *
 * CHANGES from the original:
 *  - Now stores StudentID (Student.id) against each fee record,
 *    not just the Assessment number (kept for legacy reference).
 *  - Escapes every value before it goes into the query.
 *  - Instead of an alert() + redirect to Hoi.php, shows a small
 *    confirmation screen with a "Download Word Receipts" button
 *    that links to generate_receipt.php for the records just saved.
 */

include 'conn.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$insertedIds = [];
$grade = $term = $year = '';

if (!empty($_POST['firstName'])) {
    foreach ($_POST['firstName'] as $i => $fname) {

        $studentId     = $_POST['studentId'][$i]      ?? '';
        $assessment    = $_POST['assessment'][$i]     ?? '';
        $surname       = $_POST['surname'][$i]        ?? '';
        $fee           = $_POST['Fee'][$i]           !== '' ? $_POST['Fee'][$i]           : 0;
        $assessmentFee = $_POST['AssessmentFee'][$i] !== '' ? $_POST['AssessmentFee'][$i]  : 0;
        $activity      = $_POST['Activity'][$i]      !== '' ? $_POST['Activity'][$i]       : 0;
        $other         = $_POST['Other'][$i]         !== '' ? $_POST['Other'][$i]          : 0;
        $grade         = $_POST['grade'];
        $term          = $_POST['term'];
        $year          = $_POST['year'];

        if (
            empty($fee) &&
            empty($assessmentFee) &&
            empty($activity) &&
            empty($other)
        ) {
            continue;
        }

        $studentId_e     = mysqli_real_escape_string($conn, $studentId);
        $assessment_e    = mysqli_real_escape_string($conn, $assessment);
        $fname_e         = mysqli_real_escape_string($conn, $fname);
        $surname_e       = mysqli_real_escape_string($conn, $surname);
        $fee_e           = mysqli_real_escape_string($conn, $fee);
        $assessmentFee_e = mysqli_real_escape_string($conn, $assessmentFee);
        $activity_e      = mysqli_real_escape_string($conn, $activity);
        $other_e         = mysqli_real_escape_string($conn, $other);
        $grade_e         = mysqli_real_escape_string($conn, $grade);
        $term_e          = mysqli_real_escape_string($conn, $term);
        $year_e          = mysqli_real_escape_string($conn, $year);

        $sql = "INSERT INTO Fees
        (StudentID, Assesment, firstName, surname, Fee, AssesmentFee, Activity, Other, Grade, Term, Year, payment_date)
        VALUES ('$studentId_e','$assessment_e','$fname_e','$surname_e','$fee_e','$assessmentFee_e','$activity_e','$other_e','$grade_e','$term_e','$year_e', NOW())";

        if (!mysqli_query($conn, $sql)) {
            echo "Error: " . mysqli_error($conn) . "<br>Query: " . $sql;
            continue;
        }

        $insertedIds[] = mysqli_insert_id($conn);
    }

    if (empty($insertedIds)) {
        echo "<script>
            alert('No fee amounts were entered — nothing was saved.');
            window.location.href='Fee.php';
        </script>";
        exit;
    }

    $idsParam = implode(',', $insertedIds);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Payment Saved — Stephen Kanja School</title>
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
      <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
      <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body{font-family:'DM Sans',sans-serif;background:#f4f4f2;display:flex;align-items:center;justify-content:center;min-height:100vh;}
        .card{background:#fff;border-radius:12px;padding:2.5rem 2.25rem 2rem;max-width:420px;width:90%;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,0.08);border-top:4px solid #f0c040;}
        .check{width:52px;height:52px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:22px;}
        .card h1{font-family:'Bebas Neue',sans-serif;letter-spacing:.06em;font-size:1.5rem;margin-bottom:.5rem;}
        .card p{color:#5f5e5a;font-size:13.5px;margin-bottom:1.75rem;line-height:1.5;}
        .btns{display:flex;flex-direction:column;gap:10px;}
        a.btn{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13.5px;transition:background .15s;}
        .btn-primary{background:#f0c040;color:#111;}
        .btn-primary:hover{background:#c9a030;}
        .btn-secondary{background:#f8f8f6;color:#1a1a18;border:1px solid rgba(0,0,0,0.1);}
        .btn-secondary:hover{border-color:rgba(0,0,0,0.25);}
      </style>
    </head>
    <body>
      <div class="card">
        <div class="check"><i class="fa-solid fa-check"></i></div>
        <h1>Fees Paid Successfully</h1>
        <p>
          <?php echo count($insertedIds); ?> fee record<?php echo count($insertedIds) !== 1 ? 's' : ''; ?> saved
          for Grade <?php echo htmlspecialchars($grade); ?>,
          Term <?php echo htmlspecialchars($term); ?>, <?php echo htmlspecialchars($year); ?>.
        </p>
        <div class="btns">
          <a class="btn btn-primary" href="generate_receipt.php?ids=<?php echo urlencode($idsParam); ?>">
            <i class="fa-solid fa-file-word"></i> Download Word Receipts
          </a>
          <a class="btn btn-secondary" href="Fee.php">Pay More Fees</a>
          <a class="btn btn-secondary" href="Hoi.php">Back to Home</a>
        </div>
      </div>
    </body>
    </html>
    <?php
} else {
    echo "No student data submitted.";
}
?>