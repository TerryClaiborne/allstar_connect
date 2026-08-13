<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/src/Monitor.php';

use AllStarConnect\Monitor;
use AllStarConnect\Support\AppAuth;
use AllStarConnect\Support\Config;

$config = new Config(dirname(__DIR__) . '/config.ini');
$auth = new AppAuth($config);
$canWrite = !$auth->isEnabled() || $auth->isLoggedIn();
session_write_close();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

try {
    $monitor = new Monitor($config);
    echo json_encode([
        'ok' => true,
        'can_write' => $canWrite,
        'data' => $monitor->snapshot(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'AllStar Connect local status is unavailable.',
    ], JSON_UNESCAPED_SLASHES);
}
