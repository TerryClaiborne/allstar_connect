<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();
session_write_close();

require_once dirname(__DIR__) . '/src/NodeIdentity.php';
require_once dirname(__DIR__) . '/src/EchoLink.php';

use AllStarConnect\EchoLink;
use AllStarConnect\NodeIdentity;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function identity_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function echolink_description(string $callsign): string
{
    $callsign = strtoupper(trim($callsign));
    if (str_ends_with($callsign, '-R')) {
        return 'EchoLink Repeater';
    }
    if (str_ends_with($callsign, '-L')) {
        return 'EchoLink Link';
    }
    if (str_starts_with($callsign, '*') && str_ends_with($callsign, '*')) {
        return 'EchoLink Conference';
    }
    return $callsign !== '' ? 'EchoLink User' : '';
}

$network = strtoupper(trim((string) ($_GET['network'] ?? 'ASL')));
$network = in_array($network, ['ECHO', 'ECHOLINK', 'E/L'], true) ? 'ECHO' : 'ASL';
$rawTarget = strtoupper(trim((string) ($_GET['target'] ?? '')));
$target = preg_replace('/\D+/', '', $rawTarget) ?? '';
if (preg_match('/^3\d{6}$/', $rawTarget) === 1) {
    $network = 'ECHO';
}

if ($network === 'ASL') {
    if (preg_match('/^\d{1,7}$/', $target) !== 1) {
        identity_response(['ok' => false, 'message' => 'Enter a valid AllStar node number.'], 422);
    }

    $record = NodeIdentity::astdbLookup($target);
    $callsign = trim((string) ($record['call'] ?? ''));
    $description = trim((string) ($record['description'] ?? ''));
    $location = trim((string) ($record['location'] ?? ''));
    $qrzCallsign = NodeIdentity::qrzCallsign($callsign);

    identity_response([
        'ok' => true,
        'identity' => [
            'network' => 'ASL',
            'target' => $target,
            'found' => is_array($record),
            'callsign' => $callsign,
            'description' => $description,
            'location' => $location,
            'stats_url' => 'https://stats.allstarlink.org/stats/' . rawurlencode($target),
            'qrz_url' => $qrzCallsign !== '' ? 'https://www.qrz.com/db/' . rawurlencode($qrzCallsign) : '',
        ],
    ]);
}

$echoIdentifier = '';
$echoNode = '';

if (preg_match('/^3\d{6}$/', $rawTarget) === 1 || preg_match('/^\d{1,6}$/', $rawTarget) === 1) {
    $echoNode = NodeIdentity::echoLinkNodeNumber($rawTarget);
    if ($echoNode === '' || $echoNode === '0') {
        identity_response(['ok' => false, 'message' => 'Enter a valid EchoLink node number.'], 422);
    }
    $echoIdentifier = $echoNode;
} elseif (
    strlen($rawTarget) <= 32
    && preg_match('/^[A-Z0-9*_.\/-]+$/', $rawTarget) === 1
    && preg_match('/[A-Z*]/', $rawTarget) === 1
) {
    $echoIdentifier = $rawTarget;
} else {
    identity_response([
        'ok' => false,
        'message' => 'Enter an EchoLink node number or callsign.',
    ], 422);
}

$result = (new EchoLink())->snapshot([], [$echoIdentifier]);
$entryKey = $echoNode !== '' ? $echoNode : 'call:' . $echoIdentifier;
$entry = is_array($result['entries'][$entryKey] ?? null)
    ? $result['entries'][$entryKey]
    : [];

$resolvedNode = NodeIdentity::echoLinkNodeNumber((string) ($entry['node'] ?? ''));
if ($resolvedNode === '' || $resolvedNode === '0') {
    $resolvedNode = $echoNode;
}

if ($resolvedNode === '' || $resolvedNode === '0') {
    identity_response([
        'ok' => false,
        'message' => 'That EchoLink callsign could not be resolved to a node number.',
    ], 404);
}

$target = '3' . str_pad($resolvedNode, 6, '0', STR_PAD_LEFT);
$callsign = strtoupper(trim((string) ($entry['callsign'] ?? '')));
$qrzCallsign = NodeIdentity::qrzCallsign($callsign);

identity_response([
    'ok' => true,
    'identity' => [
        'network' => 'ECHO',
        'target' => $target,
        'official_node' => $resolvedNode,
        'found' => $callsign !== '',
        'callsign' => $callsign,
        'description' => echolink_description($callsign),
        'location' => '',
        'stats_url' => '',
        'qrz_url' => $qrzCallsign !== '' ? 'https://www.qrz.com/db/' . rawurlencode($qrzCallsign) : '',
    ],
]);
