<?php
$_POST = [];
$json = json_encode(['ticket_id' => 1, 'auth_user' => 'admin', 'auth_pass' => '0000']);
file_put_contents('php://input', $json);
