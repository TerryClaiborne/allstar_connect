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
    if (preg_match('/^\d{1,7}$/', $rawTarget) === 1) {
        $target = $rawTarget;
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

    if (
        strlen($rawTarget) > 32
        || preg_match('/^[A-Z0-9_.\/-]+$/', $rawTarget) !== 1
        || preg_match('/[A-Z]/', $rawTarget) !== 1
    ) {
        identity_response(['ok' => false, 'message' => 'Enter a valid AllStar node number or callsign.'], 422);
    }

    $matches = array_map(
        static function (array $record): array {
            $target = trim((string) ($record['target'] ?? ''));
            $callsign = strtoupper(trim((string) ($record['callsign'] ?? '')));
            $qrzCallsign = NodeIdentity::qrzCallsign($callsign);

            return [
                'network' => 'ASL',
                'target' => $target,
                'found' => true,
                'callsign' => $callsign,
                'description' => trim((string) ($record['description'] ?? '')),
                'location' => trim((string) ($record['location'] ?? '')),
                'stats_url' => 'https://stats.allstarlink.org/stats/' . rawurlencode($target),
                'qrz_url' => $qrzCallsign !== '' ? 'https://www.qrz.com/db/' . rawurlencode($qrzCallsign) : '',
            ];
        },
        NodeIdentity::astdbCallsignLookup($rawTarget)
    );

    if ($matches === []) {
        identity_response([
            'ok' => false,
            'message' => 'That AllStar callsign was not found in the local node directory.',
        ], 404);
    }

    identity_response([
        'ok' => true,
        'identity' => count($matches) === 1 ? $matches[0] : null,
        'matches' => $matches,
    ]);
}

if (($_GET['search'] ?? '') === 'callsign') {
    $familyCall = preg_replace('/-(?:R|L)$/', '', $rawTarget) ?? $rawTarget;

    if (
        $familyCall === ''
        || strlen($familyCall) > 32
        || preg_match('/^[A-Z0-9_.\\/-]+$/', $familyCall) !== 1
        || preg_match('/[A-Z]/', $familyCall) !== 1
    ) {
        identity_response([
            'ok' => false,
            'message' => 'Enter a valid EchoLink callsign.',
        ], 422);
    }

    $identifiers = array_values(array_unique([
        $familyCall,
        $familyCall . '-R',
        $familyCall . '-L',
    ]));

    $result = (new EchoLink())->snapshot([], $identifiers);
    $matches = [];
    $seenTargets = [];

    foreach ($identifiers as $identifier) {
        $entry = is_array($result['entries']['call:' . $identifier] ?? null)
            ? $result['entries']['call:' . $identifier]
            : [];

        $resolvedNode = NodeIdentity::echoLinkNodeNumber((string) ($entry['node'] ?? ''));
        $callsign = strtoupper(trim((string) ($entry['callsign'] ?? '')));

        if ($resolvedNode === '' || $resolvedNode === '0' || $callsign === '') {
            continue;
        }

        $target = '3' . str_pad($resolvedNode, 6, '0', STR_PAD_LEFT);
        if (isset($seenTargets[$target])) {
            continue;
        }
        $seenTargets[$target] = true;

        $qrzCallsign = NodeIdentity::qrzCallsign($callsign);
        $matches[] = [
            'network' => 'ECHO',
            'target' => $target,
            'official_node' => $resolvedNode,
            'found' => true,
            'callsign' => $callsign,
            'description' => echolink_description($callsign),
            'location' => '',
            'stats_url' => '',
            'qrz_url' => $qrzCallsign !== ''
                ? 'https://www.qrz.com/db/' . rawurlencode($qrzCallsign)
                : '',
        ];
    }

    if ($matches === []) {
        identity_response([
            'ok' => false,
            'message' => 'No matching EchoLink callsign was found.',
        ], 404);
    }

    usort(
        $matches,
        static fn(array $left, array $right): int =>
            strnatcasecmp(
                (string) ($left['callsign'] ?? ''),
                (string) ($right['callsign'] ?? '')
            )
    );

    identity_response([
        'ok' => true,
        'identity' => count($matches) === 1 ? $matches[0] : null,
        'matches' => $matches,
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
