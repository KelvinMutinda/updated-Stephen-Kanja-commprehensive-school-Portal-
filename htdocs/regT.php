 <?php
    
    include 'conn.php';

   $name=$_POST['name'];
   $email=$_POST['email'];
   $phone=$_POST['phone'];
   $tsc=$_POST['tsc'];
   $role=$_POST['role'];
   $grade=$_POST['grade'];
   $subject=$_POST['subject'];

  $sql="INSERT INTO Teachers(name,email,phoneNo,tscNo,role,classTeacher,subject)VALUES('$name','$email','$phone','$tsc','$role','$grade','$subject')";

 $result=mysqli_query($conn,$sql);
if($result){
    echo"
     <script>
     alert('Teacher registered successfully');
     window.location.href='Hoi.php';
    </script>";
}
   
    
    ?>