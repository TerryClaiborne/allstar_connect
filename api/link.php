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

const ECHOLINK_DISCONNECT_SETTLE_SECONDS = 1.0;
const NORMAL_LINK_SETTLE_SECONDS = 0.5;

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = (string) file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

function pause_seconds(float $seconds): void
{
    if ($seconds > 0) {
        usleep((int) round($seconds * 1000000));
    }
}

function clean_node(mixed $value): string
{
    return preg_replace('/[^0-9]/', '', trim((string) $value)) ?? '';
}

function normalize_network(mixed $value): string
{
    $value = strtoupper(trim((string) $value));
    return in_array($value, ['ECHO', 'ECHOLINK', 'E/L'], true) ? 'ECHO' : 'ASL';
}

function network_for_target(mixed $network, string $target): string
{
    return preg_match('/^3\d{6}$/', $target) === 1
        ? 'ECHO'
        : normalize_network($network);
}

function normalize_link_mode(mixed $value): string
{
    return strtolower(trim((string) $value)) === 'local_monitor' ? 'local_monitor' : 'transceive';
}

function bool_value(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function helper_path(): string
{
    return dirname(__DIR__) . '/bin/allstar-connect-control.sh';
}

function helper_run(array $arguments, int $timeout = 10): array
{
    $parts = ['sudo', helper_path()];
    foreach ($arguments as $argument) {
        $parts[] = (string) $argument;
    }
    $command = '/usr/bin/timeout ' . max(1, $timeout) . ' ' . implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
    $lines = [];
    $status = 0;
    exec($command, $lines, $status);
    return [
        'status' => $status,
        'output' => trim(implode("\n", $lines)),
    ];
}

function helper_success(array $result): bool
{
    if ((int) ($result['status'] ?? 1) !== 0) {
        return false;
    }
    $upper = strtoupper((string) ($result['output'] ?? ''));
    foreach (['NO SUCH COMMAND', 'INVALID', 'ERROR', 'FAILED', 'UNABLE'] as $needle) {
        if (str_contains($upper, $needle)) {
            return false;
        }
    }
    return true;
}

function acquire_control_lock()
{
    $path = dirname(__DIR__) . '/run/link-control.lock';
    $handle = @fopen($path, 'c');
    if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return null;
    }
    return $handle;
}

function control_state_path(): string
{
    return dirname(__DIR__) . '/run/control-state.json';
}

function read_control_state(): array
{
    $path = control_state_path();
    if (!is_file($path)) {
        return ['started_links' => []];
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded)) {
        return ['started_links' => []];
    }
    $decoded['started_links'] = is_array($decoded['started_links'] ?? null) ? $decoded['started_links'] : [];
    return $decoded;
}

function write_control_state(array $state): void
{
    $path = control_state_path();
    $tmp = $path . '.tmp.' . getmypid();
    $state['updated_at'] = gmdate('c');
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to save AllStar Connect control state.');
    }
    @chmod($path, 0640);
}

function track_started_link(string $node, string $network, string $mode): void
{
    $state = read_control_state();
    $state['started_links'][$node] = [
        'network' => $network,
        'link_mode' => $mode,
        'started_at' => time(),
    ];
    write_control_state($state);
}

function untrack_started_link(string $node): void
{
    $state = read_control_state();
    unset($state['started_links'][$node]);
    write_control_state($state);
}

function tracked_links(): array
{
    return (array) (read_control_state()['started_links'] ?? []);
}

function valid_client_name(string $client): bool
{
    return preg_match('/^[A-Za-z0-9_.:@-]{1,96}$/', $client) === 1;
}

function valid_iax_channel(string $channel): bool
{
    return preg_match('/^IAX2\/[A-Za-z0-9_.:@-]{1,96}$/', $channel) === 1;
}

function live_link_directions(string $myNode): array
{
    $result = helper_run(['rpt-lstats', $myNode], 6);
    if (!helper_success($result)) {
        return [];
    }
    $directions = [];
    foreach (preg_split('/\R/', (string) $result['output']) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim((string) $line)) ?: [];
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate === '' || !valid_client_name($candidate)) {
            continue;
        }
        foreach (array_slice($parts, 1) as $part) {
            $direction = strtoupper(trim((string) $part));
            if (in_array($direction, ['IN', 'OUT'], true)) {
                $directions[$candidate] = $direction;
                break;
            }
        }
    }
    return $directions;
}

function live_link_names(string $myNode): array
{
    $names = [];
    $lstats = helper_run(['rpt-lstats', $myNode], 6);
    if (helper_success($lstats)) {
        foreach (preg_split('/\R/', (string) $lstats['output']) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim((string) $line)) ?: [];
            $candidate = trim((string) ($parts[0] ?? ''));
            if ($candidate !== '' && valid_client_name($candidate)) {
                $names[$candidate] = true;
            }
        }
    }
    $nodes = helper_run(['rpt-nodes', $myNode], 6);
    if (helper_success($nodes) && preg_match_all('/\b[TRLC]([A-Za-z0-9_.:@-]{1,96})\b/', (string) $nodes['output'], $matches) > 0) {
        foreach ($matches[1] as $candidate) {
            if (valid_client_name((string) $candidate)) {
                $names[(string) $candidate] = true;
            }
        }
    }
    return array_keys($names);
}

function live_echolink_directions(string $myNode): array
{
    return array_filter(
        live_link_directions($myNode),
        static fn (string $direction, string $node): bool => preg_match('/^3\d{6}$/', $node) === 1,
        ARRAY_FILTER_USE_BOTH
    );
}

function live_echolink_nodes(string $myNode): array
{
    return array_values(array_filter(
        live_link_names($myNode),
        static fn (string $node): bool => preg_match('/^3\d{6}$/', $node) === 1
    ));
}

function live_iax_channels(string $myNode): array
{
    $result = helper_run(['core-channels'], 6);
    if (!helper_success($result)) {
        return [];
    }
    $channels = [];
    foreach (preg_split('/\R/', (string) $result['output']) ?: [] as $line) {
        $parts = explode('!', trim((string) $line));
        $channel = trim((string) ($parts[0] ?? ''));
        $context = strtolower(trim((string) ($parts[1] ?? '')));
        $extension = trim((string) ($parts[2] ?? ''));
        $application = trim((string) ($parts[5] ?? ''));
        $data = trim((string) ($parts[6] ?? ''));
        if (!valid_iax_channel($channel) || $application !== 'Rpt') {
            continue;
        }
        $runsNode = $data === $myNode || str_starts_with($data, $myNode . '|') || str_starts_with($data, $myNode . ',') || $extension === $myNode;
        if (!$runsNode || !in_array($context, ['iaxrpt', 'iax-client', 'iaxclient'], true)) {
            continue;
        }
        $channels[] = $channel;
    }
    return $channels;
}


function echolink_node_from_mapped_target(string $target): string
{
    return preg_match('/^3(\d{6})$/', $target, $match) === 1
        ? (ltrim((string) $match[1], '0') ?: '0')
        : '';
}

function echolink_node_exists(string $mappedTarget): bool
{
    $node = echolink_node_from_mapped_target($mappedTarget);
    if ($node === '') {
        return false;
    }
    $result = helper_run(['echolink-dbget', $node], 6);
    if (!helper_success($result)) {
        return false;
    }
    foreach (preg_split('/\R/', (string) $result['output']) ?: [] as $line) {
        if (preg_match('/^' . preg_quote($node, '/') . '\|/', trim((string) $line)) === 1) {
            return true;
        }
    }
    return false;
}

function echolink_module_use_count(): int
{
    $result = helper_run(['echolink-module-show'], 6);
    if (!helper_success($result)) {
        return 0;
    }
    return preg_match('/^chan_echolink\.so\s+.*?\s+(\d+)\s+Running\s+/mi', (string) $result['output'], $match) === 1
        ? (int) $match[1]
        : 0;
}

function echolink_module_loaded(): bool
{
    $result = helper_run(['echolink-module-show'], 6);
    return helper_success($result) && stripos((string) $result['output'], 'chan_echolink.so') !== false;
}

function ensure_echolink_loaded(): void
{
    if (!echolink_module_loaded()) {
        $result = helper_run(['echolink-module-load'], 10);
        pause_seconds(2.0);
        if (!helper_success($result) || !echolink_module_loaded()) {
            throw new RuntimeException('EchoLink is unavailable because chan_echolink.so could not be loaded.');
        }
    }
}

function reset_echolink_module(): void
{
    $unload = helper_run(['echolink-module-unload'], 10);
    pause_seconds(1.0);
    $load = helper_run(['echolink-module-load'], 10);
    pause_seconds(2.0);
    if (!helper_success($load) || !echolink_module_loaded()) {
        throw new RuntimeException('EchoLink driver reset did not complete safely.');
    }
}

function reset_echolink_if_confirmed_idle(string $myNode): bool
{
    /* Preserve the safe EchoLink rule: if either live check says the
     * driver is still in use, do not force cleanup. The next protected
     * outgoing connect will reset the driver before it connects. */
    if (live_echolink_nodes($myNode) !== [] || echolink_module_use_count() !== 0) {
        return false;
    }

    reset_echolink_module();
    return true;
}

function ilink(string $myNode, string $code, string $target): bool
{
    return helper_success(helper_run(['rpt-ilink', $myNode, $code, $target], 10));
}

function wait_iax_gone(string $myNode, string $channel, float $timeout = 2.5): bool
{
    $deadline = microtime(true) + $timeout;
    do {
        if (!in_array($channel, live_iax_channels($myNode), true)) {
            return true;
        }
        pause_seconds(0.15);
    } while (microtime(true) < $deadline);
    return !in_array($channel, live_iax_channels($myNode), true);
}

function wait_client_gone(string $myNode, string $client, float $timeout = 2.5): bool
{
    $deadline = microtime(true) + $timeout;
    do {
        if (!in_array($client, live_link_names($myNode), true)) {
            return true;
        }
        pause_seconds(0.2);
    } while (microtime(true) < $deadline);
    return !in_array($client, live_link_names($myNode), true);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    respond(['ok' => false, 'message' => 'POST required.'], 405);
}

$config = new Config(dirname(__DIR__) . '/config.ini');
ApiAuthGuard::requireWriteAccess($config);
$myNode = clean_node($config->getString('MYNODE', ''));
if ($myNode === '' || preg_match('/^[0-9]{1,7}$/', $myNode) !== 1) {
    respond(['ok' => false, 'message' => 'MYNODE is not configured.'], 500);
}
$request = request_json();
session_write_close();

$action = strtolower(trim((string) ($request['action'] ?? '')));
$lock = acquire_control_lock();
if (!is_resource($lock)) {
    respond(['ok' => false, 'message' => 'Another link operation is already in progress.'], 409);
}

try {
    if ($action === 'connect') {
        $target = clean_node($request['target'] ?? '');
        $network = network_for_target($request['network'] ?? 'ASL', $target);
        $mode = normalize_link_mode($request['link_mode'] ?? 'transceive');
        $disconnectBefore = bool_value($request['disconnect_before_connect'] ?? false);
        if ($target === '' || preg_match('/^[0-9]{1,7}$/', $target) !== 1) {
            respond(['ok' => false, 'message' => 'Enter a valid node number.'], 422);
        }
        if ($network === 'ECHO' && preg_match('/^3\d{6}$/', $target) !== 1) {
            respond(['ok' => false, 'message' => 'EchoLink targets must use the mapped 3xxxxxx node number.'], 422);
        }
        if ($network === 'ECHO' && !echolink_node_exists($target)) {
            respond(['ok' => false, 'message' => 'EchoLink node was not found in the active EchoLink database.'], 404);
        }

        if ($disconnectBefore) {
            foreach (array_reverse(array_keys(tracked_links())) as $trackedNode) {
                $trackedNode = clean_node($trackedNode);
                if ($trackedNode === '' || $trackedNode === $target) {
                    continue;
                }
                $trackedInfo = tracked_links()[$trackedNode] ?? [];
                $trackedNetwork = normalize_network($trackedInfo['network'] ?? (preg_match('/^3\d{6}$/', $trackedNode) ? 'ECHO' : 'ASL'));
                $direction = strtoupper((string) (live_link_directions($myNode)[$trackedNode] ?? ''));
                ilink($myNode, '1', $trackedNode);
                pause_seconds($trackedNetwork === 'ECHO' ? ECHOLINK_DISCONNECT_SETTLE_SECONDS : NORMAL_LINK_SETTLE_SECONDS);
                if ($trackedNetwork === 'ECHO' && $direction === 'OUT' && $network !== 'ECHO') {
                    reset_echolink_if_confirmed_idle($myNode);
                }
                untrack_started_link($trackedNode);
            }
        }

        if ($network === 'ECHO') {
            $echoDirections = live_echolink_directions($myNode);
            $liveEchoNodes = live_echolink_nodes($myNode);
            if ($liveEchoNodes !== [] && count($echoDirections) < count($liveEchoNodes)) {
                respond(['ok' => false, 'message' => 'EchoLink direction could not be verified; outbound connect is blocked.'], 409);
            }
            if (in_array('IN', $echoDirections, true)) {
                respond(['ok' => false, 'message' => 'Inbound EchoLink is active; outbound connect is blocked to protect that caller.'], 409);
            }
            foreach ($echoDirections as $node => $direction) {
                if ($direction === 'OUT' && (string) $node !== $target) {
                    respond(['ok' => false, 'message' => 'Only one outgoing EchoLink connection is allowed.'], 409);
                }
            }
            // Use the protected outgoing EchoLink sequence:
            // reset the driver, wait 1 second after unload and 2 seconds after
            // load, then send the ilink command. Do not add another delay after
            // the connect command.
            reset_echolink_module();
            ensure_echolink_loaded();
        }

        $code = $mode === 'local_monitor' ? '8' : '3';
        if (!ilink($myNode, $code, $target)) {
            respond(['ok' => false, 'message' => 'Asterisk did not accept the connect command.'], 500);
        }
        if ($network !== 'ECHO') {
            pause_seconds(NORMAL_LINK_SETTLE_SECONDS);
        }
        track_started_link($target, $network, $mode);
        respond([
            'ok' => true,
            'message' => ($network === 'ECHO' ? 'EchoLink' : 'AllStarLink') . ' connect command completed.',
            'target' => $target,
            'network' => $network,
            'link_mode' => $mode,
        ]);
    }

    if ($action === 'disconnect_selected') {
        $target = clean_node($request['selected_node'] ?? $request['target'] ?? '');
        $network = network_for_target($request['network'] ?? 'ASL', $target);
        if ($target === '' || preg_match('/^[0-9]{1,7}$/', $target) !== 1) {
            respond(['ok' => false, 'message' => 'Invalid selected node.'], 422);
        }
        $direction = strtoupper((string) (live_link_directions($myNode)[$target] ?? ''));
        $remainingEchoNodes = [];
        if ($network === 'ECHO') {
            $echoDirections = live_echolink_directions($myNode);
            $direction = strtoupper((string) ($echoDirections[$target] ?? ''));
            $remainingEchoNodes = array_values(array_filter(
                array_keys($echoDirections),
                static fn (string $node): bool => $node !== $target
            ));
        }
        if (!ilink($myNode, '1', $target)) {
            respond(['ok' => false, 'message' => 'Asterisk did not accept the disconnect command.'], 500);
        }
        pause_seconds($network === 'ECHO' ? ECHOLINK_DISCONNECT_SETTLE_SECONDS : NORMAL_LINK_SETTLE_SECONDS);
        if ($network === 'ECHO' && $direction === 'OUT' && $remainingEchoNodes === []) {
            reset_echolink_if_confirmed_idle($myNode);
        }
        untrack_started_link($target);
        respond([
            'ok' => true,
            'message' => $network === 'ECHO'
                ? 'Protected EchoLink disconnect sequence completed.'
                : 'Selected connection disconnected.',
            'target' => $target,
            'network' => $network,
            'direction' => $direction,
        ]);
    }

    if ($action === 'disconnect_live_client') {
        $client = trim((string) ($request['selected_client'] ?? ''));
        if ($client === '' || !valid_client_name($client) || ctype_digit($client)) {
            respond(['ok' => false, 'message' => 'Invalid live client.'], 422);
        }

        // The row came from live Asterisk status. Send the exact app_rpt client
        // identifier once; do not reject the click because
        // a second preflight briefly misses the same row during polling.
        if (!ilink($myNode, '11', $client)) {
            respond(['ok' => false, 'message' => 'Asterisk did not accept the client disconnect command.'], 500);
        }
        pause_seconds(1.0);
        wait_client_gone($myNode, $client);
        respond(['ok' => true, 'message' => 'Selected IAX/Web client disconnected.', 'client' => $client]);
    }

    if ($action === 'disconnect_iax_channel') {
        $requestedChannel = trim((string) ($request['selected_channel'] ?? ''));
        if (!valid_iax_channel($requestedChannel)) {
            respond(['ok' => false, 'message' => 'Invalid IAX channel.'], 422);
        }
        $liveChannels = live_iax_channels($myNode);
        $channel = '';
        $alreadyGone = false;
        if (in_array($requestedChannel, $liveChannels, true)) {
            $channel = $requestedChannel;
        } elseif ($liveChannels === []) {
            $alreadyGone = true;
        } elseif (count($liveChannels) === 1) {
            $channel = (string) $liveChannels[0];
        } else {
            respond(['ok' => false, 'message' => 'Multiple true IAX channels are active; refresh and use the exact row again.'], 409);
        }
        if ($channel !== '') {
            $result = helper_run(['channel-hangup', $channel], 8);
            if (!helper_success($result)) {
                respond(['ok' => false, 'message' => 'Asterisk did not accept the exact IAX hangup.'], 500);
            }
            wait_iax_gone($myNode, $channel);
        }
        respond([
            'ok' => true,
            'message' => 'Selected true IAX channel disconnected.',
            'requested_channel' => $requestedChannel,
            'disconnected_channel' => $channel,
            'already_gone' => $alreadyGone,
        ]);
    }

    if ($action === 'switch_mode') {
        $target = clean_node($request['selected_node'] ?? '');
        $mode = normalize_link_mode($request['link_mode'] ?? 'transceive');
        $network = network_for_target($request['network'] ?? 'ASL', $target);
        if ($target === '' || preg_match('/^[0-9]{1,7}$/', $target) !== 1) {
            respond(['ok' => false, 'message' => 'Invalid selected node.'], 422);
        }
        $code = $mode === 'local_monitor' ? '8' : '3';
        if ($network === 'ECHO') {
            $directions = live_echolink_directions($myNode);
            $direction = strtoupper((string) ($directions[$target] ?? ''));
            if (!in_array($direction, ['IN', 'OUT'], true)) {
                respond(['ok' => false, 'message' => 'EchoLink direction could not be verified. Refresh and try again.'], 409);
            }
            if ($direction === 'OUT') {
                $others = array_filter(array_keys($directions), static fn (string $node): bool => $node !== $target);
                if ($others !== []) {
                    respond(['ok' => false, 'message' => 'EchoLink mode change is blocked while another EchoLink connection is active.'], 409);
                }
                reset_echolink_module();
                ensure_echolink_loaded();
                if (!ilink($myNode, $code, $target)) {
                    respond(['ok' => false, 'message' => 'Asterisk did not accept the EchoLink mode change.'], 500);
                }
            } else {
                pause_seconds(1.0);
                if (!ilink($myNode, $code, $target)) {
                    respond(['ok' => false, 'message' => 'Asterisk did not accept the inbound EchoLink mode change.'], 500);
                }
                pause_seconds(2.0);
            }
        } else {
            if (!ilink($myNode, $code, $target)) {
                respond(['ok' => false, 'message' => 'Asterisk did not accept the mode change.'], 500);
            }
            pause_seconds(NORMAL_LINK_SETTLE_SECONDS);
        }
        if (isset(tracked_links()[$target])) {
            $tracked = tracked_links()[$target];
            track_started_link($target, normalize_network($tracked['network'] ?? $network), $mode);
        }
        respond(['ok' => true, 'message' => 'Link mode changed.', 'target' => $target, 'link_mode' => $mode]);
    }

    respond(['ok' => false, 'message' => 'Unsupported link action.'], 422);
} catch (Throwable $error) {
    respond(['ok' => false, 'message' => $error->getMessage()], 500);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
