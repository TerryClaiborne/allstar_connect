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

function respond_favorites(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_favorite_network(mixed $value): string
{
    $value = strtoupper(trim((string) $value));
    return in_array($value, ['ECHO', 'ECHOLINK', 'E/L'], true) ? 'ECHO' : 'ASL';
}

function favorite_network_for_target(mixed $network, string $target): string
{
    return preg_match('/^3\d{6}$/', $target) === 1
        ? 'ECHO'
        : normalize_favorite_network($network);
}

function clean_favorite_value(mixed $value, int $maxLength): string
{
    $clean = trim(str_replace('|', ' ', (string) $value));
    $clean = preg_replace('/\s+/', ' ', $clean) ?? '';
    return function_exists('mb_substr') ? mb_substr($clean, 0, $maxLength) : substr($clean, 0, $maxLength);
}

function favorites_path(): string
{
    return dirname(__DIR__) . '/data/favorites.txt';
}

function ensure_favorites_storage(): void
{
    $dir = dirname(favorites_path());
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        respond_favorites(['ok' => false, 'message' => 'Unable to create the Favorites data directory.'], 500);
    }
    if (!is_file(favorites_path()) && file_put_contents(favorites_path(), '', LOCK_EX) === false) {
        respond_favorites(['ok' => false, 'message' => 'Unable to create favorites.txt.'], 500);
    }
}

function load_favorites(): array
{
    ensure_favorites_storage();
    $lines = file(favorites_path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $items = [];
    foreach ($lines as $line) {
        $parts = explode('|', $line);
        $target = clean_favorite_value($parts[0] ?? '', 96);
        if ($target === '') {
            continue;
        }
        $network = favorite_network_for_target($parts[3] ?? 'ASL', $target);
        $items[] = [
            'target' => $target,
            'name' => clean_favorite_value($parts[1] ?? '', 96),
            'description' => clean_favorite_value($parts[2] ?? '', 180),
            'network' => $network,
            'mode' => $network,
        ];
    }
    return array_values($items);
}

function save_favorites(array $items): void
{
    $lines = [];
    foreach ($items as $item) {
        $target = clean_favorite_value($item['target'] ?? '', 96);
        if ($target === '') {
            continue;
        }
        $name = clean_favorite_value($item['name'] ?? '', 96);
        $description = clean_favorite_value($item['description'] ?? '', 180);
        $network = favorite_network_for_target($item['network'] ?? $item['mode'] ?? 'ASL', $target);
        $lines[] = implode('|', [$target, $name, $description, $network]);
    }
    $content = $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
    if (file_put_contents(favorites_path(), $content, LOCK_EX) === false) {
        respond_favorites(['ok' => false, 'message' => 'Unable to save Favorites.'], 500);
    }
}

function request_favorites_data(): array
{
    $raw = (string) file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

$config = new Config(dirname(__DIR__) . '/config.ini');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $items = load_favorites();
    respond_favorites(['ok' => true, 'favorites' => $items, 'favorites_count' => count($items)]);
}
if ($method !== 'POST') {
    respond_favorites(['ok' => false, 'message' => 'Unsupported request method.'], 405);
}

ApiAuthGuard::requireWriteAccess($config);
$request = request_favorites_data();
session_write_close();
$action = strtolower(trim((string) ($request['action'] ?? 'save')));
$items = load_favorites();

if ($action === 'save') {
    $target = clean_favorite_value($request['target'] ?? '', 96);
    $network = favorite_network_for_target($request['network'] ?? $request['mode'] ?? 'ASL', $target);
    $name = clean_favorite_value($request['name'] ?? '', 96);
    $description = clean_favorite_value($request['description'] ?? '', 180);
    if ($target === '') {
        respond_favorites(['ok' => false, 'message' => 'Enter a target before saving.'], 422);
    }
    if ($network === 'ASL' && preg_match('/^[0-9]{1,7}$/', $target) !== 1) {
        respond_favorites(['ok' => false, 'message' => 'Enter a valid AllStar node number.'], 422);
    }
    if ($network === 'ECHO' && preg_match('/^3\d{6}$/', $target) !== 1) {
        respond_favorites(['ok' => false, 'message' => 'EchoLink Favorites must use the mapped 3xxxxxx node number.'], 422);
    }
    if ($name === '') {
        $name = $target;
    }
    $updated = false;
    foreach ($items as &$item) {
        if ((string) $item['target'] === $target && normalize_favorite_network($item['network'] ?? 'ASL') === $network) {
            $item = compact('target', 'name', 'description', 'network') + ['mode' => $network];
            $updated = true;
            break;
        }
    }
    unset($item);
    if (!$updated) {
        $items[] = compact('target', 'name', 'description', 'network') + ['mode' => $network];
    }
    save_favorites($items);
    $items = load_favorites();
    respond_favorites([
        'ok' => true,
        'message' => $updated ? 'Favorite updated.' : 'Favorite saved.',
        'updated' => $updated,
        'favorite' => compact('target', 'name', 'description', 'network') + ['mode' => $network],
        'favorites' => $items,
        'favorites_count' => count($items),
    ]);
}

if ($action === 'delete') {
    $target = clean_favorite_value($request['target'] ?? '', 96);
    $network = favorite_network_for_target($request['network'] ?? $request['mode'] ?? 'ASL', $target);
    $before = count($items);
    $items = array_values(array_filter($items, static function (array $item) use ($target, $network): bool {
        return !((string) ($item['target'] ?? '') === $target && normalize_favorite_network($item['network'] ?? 'ASL') === $network);
    }));
    save_favorites($items);
    respond_favorites([
        'ok' => true,
        'message' => count($items) < $before ? 'Favorite removed.' : 'Favorite was already removed.',
        'favorites' => $items,
        'favorites_count' => count($items),
    ]);
}

respond_favorites(['ok' => false, 'message' => 'Unsupported Favorites action.'], 422);
