<?php
// info.php
header("Content-Type: text/plain");
echo "Versión PHP: " . phpversion() . "\n";
echo "Drivers PDO detectados: " . implode(", ", pdo_drivers());
?>

