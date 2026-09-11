<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/ApiAuthGuard.php';
require_once dirname(__DIR__) . '/app/Support/ScanMode.php';

use AllStarConnect\Support\ApiAuthGuard;
use AllStarConnect\Support\AppAuth;
use AllStarConnect\Support\Config;
use AllStarConnect\Support\ScanMode;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function respond_scan_mode(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$config = new Config(dirname(__DIR__) . '/config.ini');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $auth = new AppAuth($config);
    $canWrite = !$auth->isEnabled() || $auth->isLoggedIn();
    session_write_close();

    respond_scan_mode([
        'ok' => true,
        'can_write' => $canWrite,
        'data' => ScanMode::current(),
    ]);
}

if ($method !== 'POST') {
    respond_scan_mode(['ok' => false, 'message' => 'Unsupported request method.'], 405);
}

ApiAuthGuard::requireWriteAccess($config);

$raw = (string) file_get_contents('php://input');
$request = json_decode($raw, true);
$request = is_array($request) ? $request : $_POST;
$mode = strtolower(trim((string) ($request['mode'] ?? '')));

session_write_close();

if (!in_array($mode, [ScanMode::DOWNSTREAM, ScanMode::FAVORITES], true)) {
    respond_scan_mode(['ok' => false, 'message' => 'Invalid scanner mode.'], 422);
}

try {
    respond_scan_mode([
        'ok' => true,
        'data' => ScanMode::set($mode),
    ]);
} catch (Throwable $error) {
    respond_scan_mode([
        'ok' => false,
        'message' => 'Unable to change scanner mode.',
    ], 500);
}
