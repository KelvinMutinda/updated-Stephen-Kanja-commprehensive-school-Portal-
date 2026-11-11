<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lessons Panel</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    
    <div class="header">
        <div class="header1">
            <h1>Stephen Kanja Primary And Junior school</h1>
            <h3>Aim Higher</h3>
        </div>
        
    </div>
    <div class="home">
        <div class="dashboard">
            <h2>Dashboard</h2>
            <a href="index.php">Home</a><br>
            <a href="Students.php">Students</a><br>
            <a href="Admin.php">Teachers Panel</a><br>
            <a href="HOIAccess.php">HOI Panel</a><br>
            <a href="ViewResults.php">Results</a><br>
            <a href="RegisterStudent.php">Register Student</a><br>
            <a href="Lesson.php">Lesson Management</a><br>
           
            
        </div>

        <div class="lesson-p">

            <table>
                <tr>
                    <td>TSC NO</td>
                    <td>TR'S NAME</td>
                    <td>TRS' SURNAME</td>
                    <td>LESSON1</td>
                    <td>LESSON2</td>
                    <td>LESSON3</td>
                    <td>LESSON4</td>
                    <td>LESSON5</td>
                    <td>LESSON6</td>
                    <td>LESSON7</td>
                    <td>LESSON8</td>
                    
                </tr>

                <?php
                 include 'conn.php';

                 $sql="SELECT * FROM lesson";

                 $result=mysqli_query($conn,$sql);

                 while($row=mysqli_fetch_assoc($result)){
                    echo "
                    <tr>
                        <td>{$row['tsc']}</td>
                        <td>{$row['fname']}</td>
                        <td>{$row['lname']}</td>
                        <td>{$row['l1']}</td>
                        <td>{$row['l2']}</td>
                        <td>{$row['l3']}</td>
                        <td>{$row['l4']}</td>
                        <td>{$row['l5']}</td>
                        <td>{$row['l6']}</td>
                        <td>{$row['l7']}</td>
                        <td>{$row['l8']}</td>
                    </tr>
                    ";
                 }

                ?>
            </table>
        </div>
        <div class="copy">
   <p>&copy; Designed by Kelvin  Mutinda 2025. All rights reserved.</p>

</div>
</body>
</html>