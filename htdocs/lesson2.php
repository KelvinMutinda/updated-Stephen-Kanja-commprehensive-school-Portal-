<?php
    include 'conn.php';
        $tsc=$_POST['tsc'];
        $fname=$_POST['fname'];
        $lname=$_POST['lastName'];
        $lesson1=$_POST['lesson1'];
        $lesson2=$_POST['lesson2'];
        $lesson3=$_POST['lesson3'];
        $lesson4=$_POST['lesson4'];
        $lesson5=$_POST['lesson5'];
        $lesson6=$_POST['lesson6'];
        $lesson7=$_POST['lesson7'];
        $lesson8=$_POST['lesson8'];

        $sql="INSERT INTO lesson(tsc,fname,lname,l1,l2,l3,l4,l5,l6,l7,l8)VALUES
        ('$tsc','$fname','$lname','$lesson1','$lesson2','$lesson3','$lesson4','$lesson5','$lesson6','$lesson7','$lesson8')";
    
        $result=mysqli_query($conn,$sql);

        if($result){
            echo"
            <script>
            alert('Lesson allocated successful')
            window.location.href='ViewLesson.php';
            </script>";
        }

    
    ?>