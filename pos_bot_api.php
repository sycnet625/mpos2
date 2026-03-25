<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

define('POSBOT_API_ROOT', __DIR__);

require_once POSBOT_API_ROOT . '/db.php';
require_once POSBOT_API_ROOT . '/config_loader.php';
require_once POSBOT_API_ROOT . '/habana_delivery.php';
require_once POSBOT_API_ROOT . '/push_notify.php';
require_once POSBOT_API_ROOT . '/posbot_api/bootstrap.php';
require_once POSBOT_API_ROOT . '/posbot_api/helpers.php';
require POSBOT_API_ROOT . '/posbot_api/router.php';
