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

function respond_dtmf_favorites(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function dtmf_favorites_path(): string
{
    return dirname(__DIR__) . '/data/dtmf_favorites.txt';
}

function clean_dtmf_code(mixed $value): string
{
    $value = preg_replace('/[^0-9*#]/', '', (string) $value) ?? '';
    return substr($value, 0, 14);
}

function clean_dtmf_name(mixed $value): string
{
    $value = trim(str_replace('|', ' ', (string) $value));
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return function_exists('mb_substr') ? mb_substr($value, 0, 64) : substr($value, 0, 64);
}

function ensure_dtmf_favorites_storage(): void
{
    $dir = dirname(dtmf_favorites_path());
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        respond_dtmf_favorites(['ok' => false, 'message' => 'Unable to create the DTMF Favorites data directory.'], 500);
    }
    if (!is_file(dtmf_favorites_path()) && file_put_contents(dtmf_favorites_path(), '', LOCK_EX) === false) {
        respond_dtmf_favorites(['ok' => false, 'message' => 'Unable to create dtmf_favorites.txt.'], 500);
    }
}

function load_dtmf_favorites(): array
{
    ensure_dtmf_favorites_storage();
    $lines = file(dtmf_favorites_path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $items = [];
    foreach ($lines as $line) {
        $parts = explode('|', $line, 2);
        $code = clean_dtmf_code($parts[0] ?? '');
        if ($code === '') {
            continue;
        }
        $name = clean_dtmf_name($parts[1] ?? '');
        $items[] = [
            'code' => $code,
            'name' => $name !== '' ? $name : $code,
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
            ?: strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
    });

    return array_values($items);
}

function save_dtmf_favorites(array $items): void
{
    $lines = [];
    foreach ($items as $item) {
        $code = clean_dtmf_code($item['code'] ?? '');
        if ($code === '') {
            continue;
        }
        $name = clean_dtmf_name($item['name'] ?? '');
        $lines[] = $code . '|' . ($name !== '' ? $name : $code);
    }

    $content = $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents(dtmf_favorites_path(), $content, LOCK_EX) === false) {
        respond_dtmf_favorites(['ok' => false, 'message' => 'Unable to save DTMF Favorites.'], 500);
    }
}

function request_dtmf_favorites_data(): array
{
    $raw = (string) file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

$config = new Config(dirname(__DIR__) . '/config.ini');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $items = load_dtmf_favorites();
    respond_dtmf_favorites([
        'ok' => true,
        'favorites' => $items,
        'favorites_count' => count($items),
    ]);
}

if ($method !== 'POST') {
    respond_dtmf_favorites(['ok' => false, 'message' => 'Unsupported request method.'], 405);
}

ApiAuthGuard::requireWriteAccess($config);
$request = request_dtmf_favorites_data();
session_write_close();

$action = strtolower(trim((string) ($request['action'] ?? 'save')));
$items = load_dtmf_favorites();

if ($action === 'save') {
    $code = clean_dtmf_code($request['code'] ?? '');
    $originalCode = clean_dtmf_code($request['original_code'] ?? '');
    $name = clean_dtmf_name($request['name'] ?? '');

    if ($code === '' || preg_match('/^[0-9*#]{1,14}$/', $code) !== 1) {
        respond_dtmf_favorites(['ok' => false, 'message' => 'Enter a valid DTMF command using 0-9, * or #.'], 422);
    }
    if ($name === '') {
        $name = $code;
    }

    if ($originalCode !== '' && $originalCode !== $code) {
        foreach ($items as $item) {
            if ((string) ($item['code'] ?? '') === $code) {
                respond_dtmf_favorites(['ok' => false, 'message' => 'That DTMF command is already saved.'], 422);
            }
        }
    }

    $matchCode = $originalCode !== '' ? $originalCode : $code;
    $updated = false;
    foreach ($items as &$item) {
        if ((string) ($item['code'] ?? '') === $matchCode) {
            $item = compact('code', 'name');
            $updated = true;
            break;
        }
    }
    unset($item);

    if (!$updated) {
        $items[] = compact('code', 'name');
    }

    save_dtmf_favorites($items);
    $items = load_dtmf_favorites();

    respond_dtmf_favorites([
        'ok' => true,
        'message' => $updated ? 'DTMF Favorite updated.' : 'DTMF Favorite saved.',
        'updated' => $updated,
        'favorite' => compact('code', 'name'),
        'favorites' => $items,
        'favorites_count' => count($items),
    ]);
}

if ($action === 'delete') {
    $code = clean_dtmf_code($request['code'] ?? '');
    $before = count($items);
    $items = array_values(array_filter(
        $items,
        static fn(array $item): bool => (string) ($item['code'] ?? '') !== $code
    ));

    save_dtmf_favorites($items);
    $items = load_dtmf_favorites();

    respond_dtmf_favorites([
        'ok' => true,
        'message' => count($items) < $before ? 'DTMF Favorite removed.' : 'DTMF Favorite was already removed.',
        'favorites' => $items,
        'favorites_count' => count($items),
    ]);
}

respond_dtmf_favorites(['ok' => false, 'message' => 'Unsupported DTMF Favorites action.'], 422);
