<?php
session_start();
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'pos_purchases.php' . ($query !== '' ? ('?' . $query) : '');
header('Location: ' . $target, true, 302);
exit;
