<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <form method="POST" action="">
        <table>
            <tr>
                <td>Enter cat</td>
                <td><input type="text" name="cat"></td>

            </tr>
            <tr>
                <td>Enter MainExam</td>
                <td><input type="text" name="exam"></td>
            </tr>
        </table>
        <button>Add Marks </button>
    </form>
    <?php
    $cat=$_POST['cat'];
    $exam=$_POST['exam'];

    $total=$cat+$exam;

    echo " This is the total marks {$total}";
    ?>
</body>
</html>