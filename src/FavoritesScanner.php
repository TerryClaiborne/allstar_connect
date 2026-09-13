<?php
declare(strict_types=1);

namespace AllStarConnect;

final class FavoritesScanner
{
    private const STATE_FILE = '/run/favorites_scan.json';
    private const STATE_VERSION = 9;
    private const API_TIMEOUT_SECONDS = 10.0;
    private const ERROR_RETRY_SECONDS = 15.0;
    private const QUIET_CLEAR_SCANS = 3;

    public function snapshot(int $generation): array
    {
        $root = dirname(__DIR__);
        $nodes = $this->favoriteScanSet($root);
        $signature = hash('sha256', implode('|', $nodes));
        $statePath = $root . self::STATE_FILE;
        $now = microtime(true);

        $state = $this->readState($statePath);

        if (
            (int) ($state['version'] ?? 0) !== self::STATE_VERSION
            || (string) ($state['signature'] ?? '') !== $signature
            || (int) ($state['generation'] ?? -1) !== $generation
        ) {
            // Start a clean baseline only when Favorites Scan is newly entered
            // or the Favorites list changes. A hidden/background tab must not
            // age out the baseline.
            $state = $this->newState($generation, $signature, $nodes);
        }

        if ($nodes === []) {
            $this->writeState($statePath, $state);
            return $this->response($state, '');
        }

        $nextRequestAt = (float) ($state['next_request_at'] ?? 0.0);
        if ($now < $nextRequestAt) {
            $this->writeState($statePath, $state);
            return $this->response($state, '');
        }

        $index = max(0, (int) ($state['next_index'] ?? 0));
        if ($index >= count($nodes)) {
            $index = 0;
        }

        $node = (string) $nodes[$index];
        $state['request_count'] = (int) ($state['request_count'] ?? 0) + 1;
        $fetch = $this->fetchStats($node);
        $completedAt = microtime(true);
        $state['last_completed_at'] = $completedAt;

        $stats = !empty($fetch['ok']) && is_array($fetch['data'] ?? null)
            ? $fetch['data']
            : null;
        $data = is_array($stats) ? ($stats['stats']['data'] ?? null) : null;

        if (!empty($fetch['ok'])) {
            // An HTTP-200 response without a usable `stats` object is treated
            // as a completed zero/default sample rather than a transport error.
            $keyed = is_array($data)
                ? $this->boolValue($data['keyed'] ?? false)
                : false;
            $keyups = is_array($data)
                ? max(0, (int) ($data['totalkeyups'] ?? 0))
                : 0;
            $txTime = is_array($data)
                ? max(0, (int) ($data['totaltxtime'] ?? 0))
                : 0;
            $sampleTime = time();
            $previous = is_array($state['samples'][$node] ?? null)
                ? $state['samples'][$node]
                : $this->emptySample();

            $state['samples'][$node] = $this->updateSample(
                $previous,
                $keyed,
                $keyups,
                $txTime,
                $sampleTime,
                is_array($data) ? (int) ($data['time'] ?? 0) : 0
            );
            $state['last_checked_node'] = $node;

            $state['successes'] = (int) ($state['successes'] ?? 0) + 1;
            $state['consecutive_failures'] = 0;
            $state['last_failure_status'] = 0;
            $state['next_index'] = ($index + 1) % count($nodes);
            $delay = $this->normalIntervalSeconds(
                (int) ($state['request_count'] ?? 0),
                count($nodes),
                true
            );
        } else {
            // Preserve the previous activity average when a Stats request fails
            // and control only the next request.
            $previous = is_array($state['samples'][$node] ?? null)
                ? $state['samples'][$node]
                : $this->emptySample();
            $previous['ok'] = false;
            $previous['checked_at'] = gmdate('c');
            $state['samples'][$node] = $previous;

            $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
            $state['consecutive_failures'] =
                (int) ($state['consecutive_failures'] ?? 0) + 1;
            $status = (int) ($fetch['status'] ?? 0);
            $state['last_failure_status'] = $status;
            $state['last_failure_at'] = gmdate('c');

            if ($status === 429) {
                // Retry the same node after the rate-limit backoff.
                $delay = self::ERROR_RETRY_SECONDS;
            } elseif ($status === 404) {
                // A missing node advances at the normal scan interval.
                $state['next_index'] = ($index + 1) % count($nodes);
                $delay = $this->normalIntervalSeconds(
                    (int) ($state['request_count'] ?? 0),
                    count($nodes),
                    false
                );
            } else {
                // Advance after an ordinary Stats failure, then wait before
                // continuing the list.
                $state['next_index'] = ($index + 1) % count($nodes);
                $delay = self::ERROR_RETRY_SECONDS;
            }
        }

        $state['next_request_at'] = $completedAt + $delay;
        $state['updated_at'] = gmdate('c');
        $this->writeState($statePath, $state);

        return $this->response($state, $node);
    }

    private function updateSample(
        array $previous,
        bool $keyed,
        int $keyups,
        int $txTime,
        int $sampleTime,
        int $statsTime
    ): array {
        if (
            empty($previous['initialized'])
            || (int) ($previous['totalkeyups'] ?? 0) === 0
            || $keyups < (int) ($previous['totalkeyups'] ?? 0)
            || $txTime < (int) ($previous['totaltxtime'] ?? 0)
        ) {
            $previous = $this->emptySample();
            $previous['initialized'] = true;
            $previous['totalkeyups'] = $keyups;
            $previous['totaltxtime'] = $txTime;
            $previous['sampled_at'] = 0;
            $previous['tx_average'] = 0;
        }

        $txDelta = $keyups - (int) ($previous['totalkeyups'] ?? 0);
        $timeDelta = $txTime - (int) ($previous['totaltxtime'] ?? 0);
        $elapsed = $sampleTime - (int) ($previous['sampled_at'] ?? 0);

        // Reject implausible counter jumps before calculating activity.
        if ($timeDelta > (2 * $elapsed) || $txDelta > ($elapsed / 3)) {
            $txDelta = 0;
            $timeDelta = 0;
        }

        $txPercent = $elapsed > 0
            ? min(100, (int) round(100 * $timeDelta / $elapsed))
            : 0;
        $txAverage = max(0, (int) ($previous['tx_average'] ?? 0));
        $lastActivityAt = max(0, (int) ($previous['last_activity_at'] ?? 0));
        $quietScans = max(0, (int) ($previous['quiet_scans'] ?? 0));
        $hasActivity = $keyed || $txDelta > 0 || $timeDelta > 0 || $txPercent > 0;

        if ($hasActivity) {
            $lastActivityAt = $sampleTime;
            $quietScans = 0;
        } else {
            $quietScans++;
        }

        if ($keyed) {
            $txAverage = 100;
        } elseif (($txAverage + $txPercent) > 2) {
            $txAverage = (int) round(($txAverage / 2) + ($txPercent / 2));
        } else {
            $txAverage = 0;
        }

        // Do not leave a Favorite highlighted for more than three
        // consecutive quiet samples.
        if (!$hasActivity && $quietScans >= self::QUIET_CLEAR_SCANS) {
            $txAverage = 0;
        }

        return [
            'initialized' => true,
            'keyed' => $keyed,
            'active' => $txAverage > 5,
            'tx_average' => $txAverage,
            'tx_percent' => $txPercent,
            'key_delta' => $txDelta,
            'tx_delta' => $timeDelta,
            'quiet_scans' => $quietScans,
            'elapsed' => $elapsed,
            'time' => $statsTime,
            'totalkeyups' => $keyups,
            'totaltxtime' => $txTime,
            'sampled_at' => $sampleTime,
            'last_activity_at' => $lastActivityAt,
            'checked_at' => gmdate('c'),
            'ok' => true,
        ];
    }

    private function normalIntervalSeconds(
        int $requestCount,
        int $nodeCount,
        bool $protectSameNodeInterval
    ): float {
        // Populate Favorites quickly, then progressively reduce the request
        // rate. After two complete passes, do not revisit the same Favorite
        // more often than about every 30 seconds. The activity calculation
        // compares Stats counter changes with elapsed time between samples,
        // so this per-node interval is part of the detection algorithm.
        if ($requestCount > 9000) {
            $interval = 10.0;
        } elseif ($requestCount > 4000) {
            $interval = 6.0;
        } elseif ($requestCount > 600) {
            $interval = 4.0;
        } elseif ($requestCount > 200) {
            $interval = 3.0;
        } elseif ($requestCount > (2 * $nodeCount) || $requestCount > 20) {
            $interval = 2.0;
        } else {
            $interval = 1.0;
        }

        if (
            $protectSameNodeInterval
            && $nodeCount > 0
            && ($interval * $nodeCount) < 30.0
            && $requestCount >= (2 * $nodeCount)
        ) {
            $interval = 30.0 / $nodeCount;
        }

        return $interval;
    }

    private function favoriteScanSet(string $root): array
    {
        $lines = @file(
            $root . '/data/favorites.txt',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        if (!is_array($lines)) {
            return [];
        }
        $nodes = [];
        foreach ($lines as $line) {
            $parts = explode('|', $line);
            $target = trim((string) ($parts[0] ?? ''));
            $network = strtoupper(trim((string) ($parts[3] ?? 'ASL')));

            if (
                $network === 'ECHO'
                || $network === 'ECHOLINK'
                || $network === 'E/L'
                || preg_match('/^\d{1,7}$/', $target) !== 1
            ) {
                continue;
            }

            $number = (int) $target;
            if ($number >= 1000 && $number <= 1999) {
                continue;
            }

            $nodes[$target] = true;
        }

        $scanNodes = array_map('strval', array_keys($nodes));
        usort($scanNodes, static fn(string $left, string $right): int =>
            ((int) $left) <=> ((int) $right)
        );

        // The Dashboard's default Favorites order is Node ascending. Scan in
        // that same base order so the scan marker follows the normal list order.
        return $scanNodes;
    }

    private function newState(int $generation, string $signature, array $nodes): array
    {
        $samples = [];
        foreach ($nodes as $node) {
            $samples[(string) $node] = $this->emptySample();
        }

        return [
            'version' => self::STATE_VERSION,
            'generation' => $generation,
            'session_id' => bin2hex(random_bytes(8)),
            'signature' => $signature,
            'nodes' => array_values(array_map('strval', $nodes)),
            'samples' => $samples,
            'next_index' => 0,
            'next_request_at' => 0.0,
            'request_count' => 0,
            'consecutive_failures' => 0,
            'last_failure_status' => 0,
            'last_failure_at' => null,
            'last_completed_at' => 0.0,
            'last_checked_node' => '',
            'updated_at' => null,
            'successes' => 0,
            'failures' => 0,
        ];
    }

    private function emptySample(): array
    {
        return [
            'initialized' => false,
            'keyed' => false,
            'active' => false,
            'tx_average' => 0,
            'tx_percent' => 0,
            'key_delta' => 0,
            'tx_delta' => 0,
            'quiet_scans' => 0,
            'elapsed' => 0,
            'time' => 0,
            'totalkeyups' => 0,
            'totaltxtime' => 0,
            'sampled_at' => 0,
            'last_activity_at' => 0,
            'checked_at' => null,
            'ok' => null,
        ];
    }

    private function response(array $state, string $checkedNode): array
    {
        $activeNodes = [];
        foreach (($state['nodes'] ?? []) as $node) {
            $node = (string) $node;
            $sample = $state['samples'][$node] ?? null;
            if (is_array($sample) && (int) ($sample['tx_average'] ?? 0) > 5) {
                $activeNodes[] = $node;
            }
        }

        $nodes = array_values(array_map('strval', $state['nodes'] ?? []));
        $nextIndex = max(0, (int) ($state['next_index'] ?? 0));
        $nextNode = $nodes !== []
            ? (string) ($nodes[$nextIndex % count($nodes)] ?? '')
            : '';

        $remaining = max(
            0.0,
            (float) ($state['next_request_at'] ?? 0.0) - microtime(true)
        );

        return [
            'generation' => (int) ($state['generation'] ?? 0),
            'session_id' => (string) ($state['session_id'] ?? ''),
            'favorites_count' => count($nodes),
            'checked_node' => $checkedNode,
            'last_checked_node' => (string) ($state['last_checked_node'] ?? ''),
            'next_node' => $nextNode,
            'keyed_nodes' => $activeNodes,
            'samples' => is_array($state['samples'] ?? null)
                ? $state['samples']
                : [],
            'request_count' => (int) ($state['request_count'] ?? 0),
            'next_interval_ms' => max(250, (int) ceil($remaining * 1000)),
            'updated_at' => $state['updated_at'] ?? null,
            'consecutive_failures' =>
                (int) ($state['consecutive_failures'] ?? 0),
            'last_failure_status' =>
                (int) ($state['last_failure_status'] ?? 0),
            'last_failure_at' => $state['last_failure_at'] ?? null,
            'successes' => (int) ($state['successes'] ?? 0),
            'failures' => (int) ($state['failures'] ?? 0),
        ];
    }

    private function fetchStats(string $node): array
    {
        // Keep the scanner independent of the optional PHP cURL extension.
        $url = 'http://stats.allstarlink.org/api/stats/' . rawurlencode($node);
        ini_set('default_socket_timeout', (string) ((int) self::API_TIMEOUT_SECONDS));
        unset($http_response_header);

        $raw = @file_get_contents($url);
        $status = 0;
        $header = (string) (($http_response_header[0] ?? ''));
        if (preg_match('/([0-9])\d+/', $header, $match) === 1) {
            $status = (int) ($match[0] ?? 0);
        }

        if (!is_string($raw) || $raw === '' || $status !== 200) {
            return ['ok' => false, 'status' => $status, 'data' => null];
        }

        $decoded = json_decode($raw, true);
        return [
            // A non-empty HTTP-200 body is a completed request. If it cannot
            // produce a normal Stats object, the caller uses a zero/default
            // sample and continues at the normal scan interval.
            'ok' => true,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : null,
        ];
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(string $path, array $state): void
    {
        $json = json_encode(
            $state,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        $temp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write Favorites scanner state.');
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to activate Favorites scanner state.');
        }
        @chmod($path, 0640);
    }
}
