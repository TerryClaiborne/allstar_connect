<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/ApiAuthGuard.php';

use AllStarConnect\Support\ApiAuthGuard;
use AllStarConnect\Support\Config;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    respond(['ok' => false, 'message' => 'POST required.'], 405);
}

$config = new Config(dirname(__DIR__) . '/config.ini');
ApiAuthGuard::requireWriteAccess($config);
session_write_close();

$raw = (string) file_get_contents('php://input');
$request = json_decode($raw, true);
if (!is_array($request)) {
    respond(['ok' => false, 'message' => 'Invalid request.'], 400);
}

$action = trim((string) ($request['action'] ?? ''));
if ($action !== 'send_dtmf') {
    respond(['ok' => false, 'message' => 'Unsupported control action.'], 422);
}

$node = trim($config->getString('MYNODE', ''));
if (preg_match('/^[0-9]{4,7}$/', $node) !== 1) {
    respond(['ok' => false, 'message' => 'MYNODE is not configured.'], 500);
}

$digits = preg_replace('/\s+/', '', trim((string) ($request['dtmf_code'] ?? ''))) ?? '';
if (preg_match('/^[0-9*#]{1,14}$/', $digits) !== 1) {
    respond(['ok' => false, 'message' => 'Invalid DTMF code.'], 422);
}

$helper = dirname(__DIR__) . '/bin/allstar-connect-control.sh';
$command = 'sudo ' . escapeshellarg($helper) . ' rpt-fun ' . escapeshellarg($node) . ' ' . escapeshellarg($digits) . ' 2>&1';
$lines = [];
$status = 0;
exec($command, $lines, $status);
$output = trim(implode("\n", $lines));
$upper = strtoupper($output);
if ($status !== 0 || str_contains($upper, 'NO SUCH COMMAND') || str_contains($upper, 'INVALID') || str_contains($upper, 'ERROR') || str_contains($upper, 'FAILED')) {
    respond(['ok' => false, 'message' => 'DTMF send failed.'], 500);
}

respond(['ok' => true, 'message' => 'DTMF sent.', 'dtmf_code' => $digits]);
