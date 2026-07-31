<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();
session_write_close();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/src/Monitor.php';
require_once dirname(__DIR__) . '/src/EchoLink.php';

use AllStarConnect\EchoLink;
use AllStarConnect\Monitor;
use AllStarConnect\Support\Config;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

try {
    $config = new Config(dirname(__DIR__) . '/config.ini');
    $local = (new Monitor($config))->snapshot();
    $connections = is_array($local['connections'] ?? null) ? $local['connections'] : [];

    $additionalNodes = [];
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($decoded['nodes'] ?? null)) {
            $additionalNodes = $decoded['nodes'];
        }
    }

    $result = (new EchoLink())->snapshot($connections, $additionalNodes);

    echo json_encode([
        'ok' => true,
        'data' => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'EchoLink identity lookup is unavailable.',
    ], JSON_UNESCAPED_SLASHES);
}
