<?php
session_start();
// Unset user session credentials while preserving session state
unset($_SESSION['name']);
unset($_SESSION['role']);
unset($_SESSION['email']);
unset($_SESSION['school']);
unset($_SESSION['course']);
header("Location: login.php");
exit();
?>
