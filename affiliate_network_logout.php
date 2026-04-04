<?php
require_once __DIR__ . '/affiliate_network/domain.php';

aff_session_start_if_needed();
aff_logout();
header('Location: /affiliate_network_login.php');
exit;
