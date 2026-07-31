<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();
require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
$auth = new \AllStarConnect\Support\AppAuth(new \AllStarConnect\Support\Config(dirname(__DIR__) . '/config.ini'));
$auth->logout();
header('Location: /allstar_connect/public/');
exit;
