<?php
include 'conn.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $term = mysqli_real_escape_string($conn, $_POST['term']);
    $exam_type = mysqli_real_escape_string($conn, $_POST['examType']);
    $year = mysqli_real_escape_string($conn, $_POST['year']);
    $grade = mysqli_real_escape_string($conn, $_POST['grade']);

    $assessments = $_POST['assesment'];
    $firstNames = $_POST['firstName'];
    $lastNames = $_POST['surname'];
    $maths = $_POST['MATH'];
    $engs = $_POST['ENG'];
    $kisws = $_POST['KISW'];
    $ssts = $_POST['SST'];
    $scies = $_POST['SCIE'];
    $cas = $_POST['CA'];
    $agris = $_POST['AGRI'];
    $res = $_POST['re'];
    $pretecs = $_POST['pretec'];

    for ($i = 0; $i < count($firstNames); $i++) {
        $assesment = mysqli_real_escape_string($conn, $assessments[$i]);
        $fname = mysqli_real_escape_string($conn, $firstNames[$i]);
        $lname = mysqli_real_escape_string($conn, $lastNames[$i]);
        $math = mysqli_real_escape_string($conn, $maths[$i]);
        $eng = mysqli_real_escape_string($conn, $engs[$i]);
        $kisw = mysqli_real_escape_string($conn, $kisws[$i]);
        $sst = mysqli_real_escape_string($conn, $ssts[$i]);
        $scie = mysqli_real_escape_string($conn, $scies[$i]);
        $ca = mysqli_real_escape_string($conn, $cas[$i]);
        $agri = mysqli_real_escape_string($conn, $agris[$i]);
        $re = mysqli_real_escape_string($conn, $res[$i]);
        $pretec = mysqli_real_escape_string($conn, $pretecs[$i]);

        $checkQuery = "SELECT * FROM exam2 
                       WHERE Assesment='$assesment' AND term='$term' AND exam_type='$exam_type' AND year='$year'";
        $result = mysqli_query($conn, $checkQuery);

        if (mysqli_num_rows($result) > 0) {
  
            $updateFields = [];
            if ($math !== '') $updateFields[] = "math='$math'";
            if ($eng !== '') $updateFields[] = "eng='$eng'";
            if ($kisw !== '') $updateFields[] = "kisw='$kisw'";
            if ($sst !== '') $updateFields[] = "sst='$sst'";
            if ($scie !== '') $updateFields[] = "scie='$scie'";
            if ($ca !== '') $updateFields[] = "ca='$ca'";
            if ($agri !== '') $updateFields[] = "agri='$agri'";
            if ($re !== '') $updateFields[] = "re='$re'";
            if ($pretec !== '') $updateFields[] = "pretec='$pretec'";

            if (!empty($updateFields)) {
                $updateQuery = "UPDATE exam2 SET " . implode(", ", $updateFields) . " 
                                WHERE Assesment='$assesment' AND term='$term' AND exam_type='$exam_type' AND year='$year'";
                mysqli_query($conn, $updateQuery);
            }
        } else {
            // Insert new record
            $insertQuery = "INSERT INTO exam2 
                (Assesment, firstName, lastName, math, eng, kisw, sst, scie, ca, agri, re, pretec, grade, term, exam_type, year)
                VALUES 
                ('$assesment', '$fname', '$lname', '$math', '$eng', '$kisw', '$sst', '$scie', '$ca', '$agri', '$re', '$pretec', '$grade', '$term', '$exam_type', '$year')";
            mysqli_query($conn, $insertQuery);
        }
    }
    echo"
    <script>
    alert('Marks added successfully');
    </script>";
    header("Location: teacherpanel.php"); 
    exit();;;
} else {
    echo "Invalid request.";
}
?>
