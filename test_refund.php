<?php
$_POST = [];
$json = json_encode(['ticket_id' => 1, 'auth_user' => 'Eddy', 'auth_pass' => '123456']);
file_put_contents('php://input', $json);
// or we can just curl it
