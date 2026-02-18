<?php
session_start();
session_destroy(); // Destruye la sesión
header('Location: login.php'); // Manda de vuelta al login
exit;
?>

