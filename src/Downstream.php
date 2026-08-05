<?php
declare(strict_types=1);

namespace AllStarConnect;

require_once __DIR__ . '/CacheMaintenance.php';
require_once __DIR__ . '/NodeIdentity.php';

use AllStarConnect\Support\Config;

final class Downstream
{
    private const STATE_FILE = '/run/downstream.json';
    private const LOCK_FILE = '/run/downstream.lock';
    private const NODE_CACHE_DIR = '/cache/stats';
    private const REFRESH_SECONDS = 30;
    private const NODE_CACHE_SECONDS = 180;
    private const STALE_FALLBACK_SECONDS = 21600;
    private const FAILURE_RETRY_SECONDS = 90;
    private const MAX_NODE_FAILURES = 3;
    private const API_TIMEOUT_SECONDS = 1.2;
    private const PEER_STATUS_TIMEOUT_SECONDS = 1.0;
    private const MAX_CACHED_QUEUE_STEPS_PER_REQUEST = 30;
    private const MAX_NETWORK_READS_PER_REQUEST = 1;
    private const SCAN_VERSION = 6;

    public function __construct(private Config $config)
    {
    }

    public function snapshot(array $connections): array
    {
        $root = dirname(__DIR__);
        $statePath = $root . self::STATE_FILE;
        $lockPath = $root . self::LOCK_FILE;
        $localNode = $this->digits((string) $this->config->get('MYNODE', ''));
        $direct = $this->directSources($connections, $localNode);
        $signature = $this->directSignature($direct);
        $state = $this->readJson($statePath) ?? $this->newState($signature, $direct, true);
        if (($state['signature'] ?? '') !== $signature) {
            $retryAt = trim((string) ($state['retry_at'] ?? ''));
            $previousRows = [];
            foreach (array_merge(
                array_values(array_filter($state['display_nodes'] ?? [], 'is_array')),
                array_values(array_filter($state['scan']['working_nodes'] ?? [], 'is_array'))
            ) as $item) {
                $this->addWorkingNode($previousRows, $item);
            }
            $previousDisplay = array_values($previousRows);
            $previousUpdatedAt = trim((string) ($state['display_updated_at'] ?? ''));
            $activeDirectNodes = [];
            foreach ($direct as $item) {
                $node = $this->digits((string) ($item['node'] ?? ''));
                if ($node !== '') {
                    $activeDirectNodes[$node] = true;
                }
            }

            $state = $this->newState($signature, $direct, true);

            // Keep completed branches that are still directly connected while
            // the changed direct-node set is rescanned in the background.
            if ($previousDisplay !== [] && $activeDirectNodes !== []) {
                $state['display_nodes'] = array_values(array_filter(
                    $previousDisplay,
                    function (array $item) use ($activeDirectNodes): bool {
                        $directNode = $this->digits(
                            (string) ($item['direct_node'] ?? '')
                        );
                        return $directNode !== ''
                            && isset($activeDirectNodes[$directNode]);
                    }
                ));
                if ($state['display_nodes'] !== []) {
                    $state['display_updated_at'] = $previousUpdatedAt !== ''
                        ? $previousUpdatedAt
                        : gmdate('c');
                }
            }

            if ($retryAt !== '') {
                $state['retry_at'] = $retryAt;
            }
        } else {
            $state['direct'] = $direct;
        }

        $scanVersionChanged = (int) ($state['scan_version'] ?? 0) !== self::SCAN_VERSION;
        if ($scanVersionChanged) {
            $state['scan_version'] = self::SCAN_VERSION;
            $state['scan'] = $direct === [] ? null : $this->newScan($direct, true);
        }

        $lock = @fopen($lockPath, 'c');
        if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return $this->response($state, true);
        }

        try {
            if ($scanVersionChanged) {
                CacheMaintenance::clearStats($root);
            }
            if ($direct === []) {
                $retryAt = trim((string) ($state['retry_at'] ?? ''));
                $state = $this->newState($signature, []);
                if ($retryAt !== '') {
                    $state['retry_at'] = $retryAt;
                }
                $state['display_updated_at'] = gmdate('c');
                $this->writeJson($statePath, $state);
                return $this->response($state, false);
            }

            $retryValue = trim((string) ($state['retry_at'] ?? ''));
            $retryAt = $retryValue !== '' ? strtotime($retryValue) : false;
            $retryDeferred = $retryAt !== false && $retryAt > time();
            if (!$retryDeferred && $retryValue !== '') {
                unset($state['retry_at']);
            }

            if (!$this->hasActiveScan($state) && $this->needsRefresh($state)) {
                $state['scan'] = $this->newScan($direct);
            }

            if ($this->hasActiveScan($state) && !$retryDeferred) {
                $state = $this->advanceScan($root, $state, $localNode);
            }

            $peerSnapshots = $this->peerLocalSnapshots($direct, $localNode);
            $this->writeJson($statePath, $state);
            return $this->response($state, false, $peerSnapshots, $localNode);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    private function directSources(array $connections, string $localNode): array
    {
        $direct = [];
        foreach ($connections as $connection) {
            if (!is_array($connection) || ($connection['kind'] ?? '') !== 'asl') {
                continue;
            }

            $node = $this->digits((string) ($connection['node'] ?? ''));
            if (!$this->shouldShowNode($node, $localNode)) {
                continue;
            }

            $mode = ($connection['mode'] ?? '') === 'local_monitor' ? 'local_monitor' : 'transceive';
            $direct[$node] = [
                'key' => 'downstream-root:' . $node,
                'node' => $node,
                'callsign' => trim((string) ($connection['callsign'] ?? '')),
                'description' => trim((string) ($connection['description'] ?? '')),
                'location' => trim((string) ($connection['location'] ?? '')),
                'mode' => $mode,
                'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
                'stats_url' => 'https://stats.allstarlink.org/stats/' . rawurlencode($node),
                'qrz_url' => (string) ($connection['qrz_url'] ?? ''),
            ];
        }

        ksort($direct, SORT_NATURAL);
        return array_values($direct);
    }

    private function directSignature(array $direct): string
    {
        $parts = [];
        foreach ($direct as $item) {
            $parts[] = (string) ($item['node'] ?? '') . ':' . (string) ($item['mode'] ?? '');
        }
        return hash('sha256', implode('|', $parts));
    }

    private function newState(string $signature, array $direct, bool $useCachedRoots = false): array
    {
        return [
            'scan_version' => self::SCAN_VERSION,
            'signature' => $signature,
            'direct' => $direct,
            'display_nodes' => [],
            'display_updated_at' => null,
            'last_attempt_at' => null,
            'scan' => $direct === [] ? null : $this->newScan($direct, $useCachedRoots),
        ];
    }

    private function newScan(array $direct, bool $useCachedRoots = false): array
    {
        $queue = [];
        foreach ($direct as $item) {
            $node = (string) ($item['node'] ?? '');
            if ($node === '') {
                continue;
            }
            $queue[] = [
                'node' => $node,
                'depth' => 1,
                'direct_node' => $node,
                'parent_node' => '',
                'path_mode' => (string) ($item['mode'] ?? 'transceive'),
                'force_refresh' => !$useCachedRoots,
            ];
        }

        return [
            'started_at' => gmdate('c'),
            'queue' => $queue,
            'visited' => [],
            'working_nodes' => [],
            'api_reads' => 0,
            'successes' => 0,
            'failures' => 0,
            'hidden' => 0,
            'used_stale_cache' => false,
        ];
    }

    private function hasActiveScan(array $state): bool
    {
        return isset($state['scan']) && is_array($state['scan']);
    }

    private function needsRefresh(array $state): bool
    {
        $updated = strtotime((string) ($state['display_updated_at'] ?? ''));
        return $updated === false || (time() - $updated) >= self::REFRESH_SECONDS;
    }

    private function advanceScan(string $root, array $state, string $localNode): array
    {
        $scan = is_array($state['scan'] ?? null) ? $state['scan'] : $this->newScan($state['direct'] ?? []);
        $directNodeSet = [];
        foreach (($state['direct'] ?? []) as $item) {
            $node = $this->digits((string) ($item['node'] ?? ''));
            if ($node !== '') {
                $directNodeSet[$node] = true;
            }
        }

        $networkReads = 0;
        $steps = 0;

        while (($scan['queue'] ?? []) !== [] && $steps < self::MAX_CACHED_QUEUE_STEPS_PER_REQUEST) {
            $steps++;
            $current = $scan['queue'][0] ?? null;
            if (!is_array($current)) {
                array_shift($scan['queue']);
                continue;
            }

            $node = $this->digits((string) ($current['node'] ?? ''));
            $depth = max(1, (int) ($current['depth'] ?? 1));
            $visitKey = (string) ($current['direct_node'] ?? '') . ':' . $node;
            if ($node === '' || isset($scan['visited'][$visitKey])) {
                array_shift($scan['queue']);
                continue;
            }
            $cache = $this->readNodeCache($root, $node);
            $data = null;
            $usedStale = false;
            $isDirectRoot = $depth === 1
                && $node === (string) ($current['direct_node'] ?? '')
                && trim((string) ($current['parent_node'] ?? '')) === '';
            $forceRefresh = $isDirectRoot && !empty($current['force_refresh']);

            if (!$forceRefresh && $cache !== null && $cache['age'] <= self::STALE_FALLBACK_SECONDS) {
                $data = $cache['data'];
                $usedStale = $cache['age'] > self::NODE_CACHE_SECONDS;
            } elseif ($networkReads < self::MAX_NETWORK_READS_PER_REQUEST) {
                $networkReads++;
                $scan['api_reads'] = (int) ($scan['api_reads'] ?? 0) + 1;
                $fetched = $this->fetchStats($node);
                if ($fetched !== null) {
                    $data = $fetched;
                    $this->writeNodeCache($root, $node, $fetched);
                    unset($state['retry_at']);
                } else {
                    if ($cache !== null && $cache['age'] <= self::STALE_FALLBACK_SECONDS) {
                        $state['retry_at'] = gmdate('c', time() + self::FAILURE_RETRY_SECONDS);
                        $data = $cache['data'];
                        $usedStale = true;
                    } else {
                        $failedAttempts = (int) ($current['failed_attempts'] ?? 0) + 1;
                        $scan['failures'] = (int) ($scan['failures'] ?? 0) + 1;

                        if ($failedAttempts < self::MAX_NODE_FAILURES) {
                            $current['failed_attempts'] = $failedAttempts;
                            array_shift($scan['queue']);
                            $scan['queue'][] = $current;
                            unset($state['retry_at']);
                            continue;
                        }

                        unset($state['retry_at']);
                        array_shift($scan['queue']);
                        $scan['visited'][$visitKey] = true;
                        continue;
                    }
                }
            } else {
                break;
            }

            array_shift($scan['queue']);
            $scan['visited'][$visitKey] = true;

            if (!is_array($data)) {
                $scan['failures'] = (int) ($scan['failures'] ?? 0) + 1;
                continue;
            }

            if ($usedStale) {
                $scan['used_stale_cache'] = true;
            }
            $scan['successes'] = (int) ($scan['successes'] ?? 0) + 1;

            $parsed = $this->parseStatsLinks(
                $data,
                $node,
                $localNode,
                (string) ($current['direct_node'] ?? ''),
                $depth,
                (string) ($current['path_mode'] ?? 'transceive'),
                $directNodeSet
            );
            $scan['hidden'] = (int) ($scan['hidden'] ?? 0) + $parsed['hidden'];

            foreach ($parsed['nodes'] as $child) {
                $this->addWorkingNode($scan['working_nodes'], $child);
                if (($child['kind'] ?? '') !== 'asl') {
                    continue;
                }

                $childNode = (string) ($child['node'] ?? '');
                $childVisitKey = (string) ($current['direct_node'] ?? '') . ':' . $childNode;
                if ($childNode !== '' && $childNode !== $localNode && !isset($scan['visited'][$childVisitKey])) {
                    $scan['queue'][] = [
                        'node' => $childNode,
                        'depth' => $depth + 1,
                        'direct_node' => (string) ($current['direct_node'] ?? ''),
                        'parent_node' => $node,
                        'path_mode' => (string) ($current['path_mode'] ?? 'transceive'),
                    ];
                }
            }
        }

        $scan['queue'] = $this->dedupeQueue($scan['queue'] ?? [], $scan['visited'] ?? []);
        $state['last_attempt_at'] = gmdate('c');

        if (($scan['queue'] ?? []) === []) {
            $successes = (int) ($scan['successes'] ?? 0);
            if ($successes > 0 || ($state['display_nodes'] ?? []) === []) {
                $nodes = array_values(array_filter($scan['working_nodes'] ?? [], 'is_array'));
                $this->sortNodes($nodes);
                $state['display_nodes'] = $nodes;
                $state['display_updated_at'] = gmdate('c');
            }

            $failures = (int) ($scan['failures'] ?? 0);
            $state['last_scan'] = [
                'api_reads' => (int) ($scan['api_reads'] ?? 0),
                'successes' => $successes,
                'failures' => $failures,
                'hidden' => (int) ($scan['hidden'] ?? 0),
            ];
            $state['scan'] = null;
        } else {
            $state['scan'] = $scan;
        }

        return $state;
    }

    private function readNodeCache(string $root, string $node): ?array
    {
        $path = $root . self::NODE_CACHE_DIR . '/' . $node . '.json';
        $cached = $this->readJson($path);
        if (!is_array($cached['data'] ?? null)) {
            return null;
        }

        $fetched = strtotime((string) ($cached['fetched_at'] ?? ''));
        $age = $fetched === false ? PHP_INT_MAX : max(0, time() - $fetched);
        return ['data' => $cached['data'], 'age' => $age];
    }

    private function writeNodeCache(string $root, string $node, array $data): void
    {
        $path = $root . self::NODE_CACHE_DIR . '/' . $node . '.json';
        $this->writeJson($path, ['fetched_at' => gmdate('c'), 'data' => $data]);
    }

    private function fetchStats(string $node): ?array
    {
        $url = 'https://stats.allstarlink.org/api/stats/' . rawurlencode($node);
        $raw = null;
        $attempted = false;

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl !== false) {
                $attempted = true;
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT_MS => 700,
                    CURLOPT_TIMEOUT_MS => (int) round(self::API_TIMEOUT_SECONDS * 1000),
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_USERAGENT => 'AllStarConnect/0.50',
                ]);
                $result = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                curl_close($curl);
                if (is_string($result) && $status >= 200 && $status < 300) {
                    $raw = $result;
                }
            }
        }

        if (!$attempted && $raw === null && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => self::API_TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'header' => "User-Agent: AllStarConnect/0.50\r\nAccept: application/json\r\n",
                ],
            ]);
            $result = @file_get_contents($url, false, $context);
            if (is_string($result) && trim($result) !== '') {
                $raw = $result;
            }
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function parseStatsLinks(
        array $stats,
        string $sourceNode,
        string $localNode,
        string $directNode,
        int $depth,
        string $pathMode,
        array $directNodeSet
    ): array {
        $data = $stats['stats']['data'] ?? null;
        if (!is_array($data)) {
            return ['nodes' => [], 'hidden' => 0];
        }

        $entries = [];
        $remoteClients = [];
        $echoLinkEntries = [];
        foreach (($data['linkedNodes'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rawName = trim((string) ($entry['name'] ?? ''));
            $echoLinkNode = NodeIdentity::echoLinkNodeNumber($rawName, true);
            if ($echoLinkNode !== '') {
                $echoLinkEntries[$echoLinkNode] = ['entry' => $entry, 'reported_node' => $rawName];
                continue;
            }

            $node = $this->digits($rawName);
            if ($node !== '' && ctype_digit($rawName)) {
                $entries[$node] = $entry;
                continue;
            }

            $clientCallsign = NodeIdentity::webPhoneCallsign($rawName);
            if ($clientCallsign !== '') {
                $clientNode = $clientCallsign . '-P';
                $remoteClients[$clientNode] = $entry;
            }
        }

        foreach (($data['links'] ?? []) as $rawNode) {
            $rawNode = trim((string) $rawNode);
            $echoLinkNode = NodeIdentity::echoLinkNodeNumber($rawNode, true);
            if ($echoLinkNode !== '') {
                if (!isset($echoLinkEntries[$echoLinkNode])) {
                    $echoLinkEntries[$echoLinkNode] = ['entry' => ['name' => $rawNode], 'reported_node' => $rawNode];
                }
                continue;
            }

            $node = $this->digits($rawNode);
            if ($node !== '' && !isset($entries[$node])) {
                $entries[$node] = ['name' => $node];
            }
        }

        $mode = $pathMode === 'local_monitor' ? 'local_monitor' : 'transceive';
        $modeLabel = $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive';
        $nodes = [];
        $hidden = 0;
        foreach ($entries as $node => $entry) {
            $node = (string) $node;
            $isLocalNode = $node === $localNode;
            if ($node === $sourceNode || isset($directNodeSet[$node]) || (!$isLocalNode && !$this->shouldShowNode($node, $localNode))) {
                $hidden++;
                continue;
            }

            $server = is_array($entry['server'] ?? null) ? $entry['server'] : [];
            $callsign = NodeIdentity::cleanDisplay((string) ($entry['callsign'] ?? ($entry['User_ID'] ?? '')));
            $description = NodeIdentity::cleanDisplay((string) ($entry['node_frequency'] ?? ''));
            $location = NodeIdentity::cleanDisplay((string) ($server['Location'] ?? ($server['SiteName'] ?? '')));
            $local = NodeIdentity::astdbLookup($node);

            if ($callsign === '') {
                $callsign = trim((string) ($local['call'] ?? ''));
            }
            if ($description === '') {
                $description = trim((string) ($local['description'] ?? ''));
            }
            if ($location === '') {
                $location = trim((string) ($local['location'] ?? ''));
            }

            $nodeNumber = (int) $node;
            $isPrivate = ($nodeNumber >= 1000 && $nodeNumber <= 1999)
                || ($callsign === '' && $description === '' && $location === '');
            $qrz = NodeIdentity::qrzCallsign($callsign);
            $nodes[] = [
                'key' => 'downstream:' . $directNode . ':' . $node,
                'kind' => 'asl',
                'source' => $isPrivate ? 'Private Node' : 'AllStarLink',
                'node' => $node,
                'callsign' => $callsign,
                'description' => $isPrivate ? 'Private Node' : $description,
                'location' => $location,
                'display' => $isPrivate ? 'Node ' . $node . ' - Private Node' : ($callsign !== '' ? $callsign : ($description !== '' ? $description : $node)),
                'mode' => $mode,
                'mode_label' => $modeLabel,
                'direct_node' => $directNode,
                'parent_node' => $sourceNode,
                'depth' => $depth,
                'is_private' => $isPrivate,
                'stats_url' => $isPrivate ? '' : 'https://stats.allstarlink.org/stats/' . rawurlencode($node),
                'qrz_url' => !$isPrivate && $qrz !== '' ? 'https://www.qrz.com/db/' . rawurlencode($qrz) : '',
            ];
        }

        foreach ($remoteClients as $clientNode => $entry) {
            $callsign = substr($clientNode, 0, -2);
            $nodes[] = [
                'key' => 'downstream-client:' . $directNode . ':' . $sourceNode . ':' . $clientNode,
                'kind' => 'client',
                'client_type' => 'web_phone',
                'source' => 'Web/Phone Client',
                'node' => $clientNode,
                'callsign' => $callsign,
                'description' => 'Web/Phone Client',
                'location' => '',
                'display' => $callsign,
                'mode' => $mode,
                'mode_label' => $modeLabel,
                'direct_node' => $directNode,
                'parent_node' => $sourceNode,
                'depth' => $depth,
                'stats_url' => '',
                'qrz_url' => 'https://www.qrz.com/db/' . rawurlencode($callsign),
                'remote_reported' => true,
            ];
        }

        foreach ($echoLinkEntries as $echoLinkNode => $echoLinkEntry) {
            $reportedNode = trim((string) ($echoLinkEntry['reported_node'] ?? ''));
            $nodes[] = [
                'key' => 'downstream-echo:' . $directNode . ':' . $sourceNode . ':' . $echoLinkNode,
                'kind' => 'echo',
                'source' => 'EchoLink',
                'node' => $echoLinkNode,
                'reported_node' => $reportedNode,
                'echolink_node' => $echoLinkNode,
                'callsign' => '',
                'description' => 'EchoLink',
                'location' => '',
                'display' => 'EchoLink node ' . $echoLinkNode,
                'mode' => $mode,
                'mode_label' => $modeLabel,
                'direct_node' => $directNode,
                'parent_node' => $sourceNode,
                'depth' => $depth,
                'stats_url' => '',
                'qrz_url' => '',
                'identity_pending' => true,
                'remote_reported' => true,
            ];
        }

        return ['nodes' => $nodes, 'hidden' => $hidden];
    }

    private function addWorkingNode(array &$working, array $candidate): void
    {
        $node = (string) ($candidate['node'] ?? '');
        $directNode = (string) ($candidate['direct_node'] ?? '');
        if ($node === '' || $directNode === '') {
            return;
        }

        if (($candidate['kind'] ?? '') !== 'asl') {
            $parentNode = (string) ($candidate['parent_node'] ?? '');
            $kind = (string) ($candidate['kind'] ?? 'other');
            $key = $directNode . ':' . $kind . ':' . $parentNode . ':' . $node;
            $working[$key] = $candidate;
            return;
        }

        $key = $directNode . ':' . $node;
        $existing = is_array($working[$key] ?? null) ? $working[$key] : null;
        if ($existing === null || (int) ($candidate['depth'] ?? 99) < (int) ($existing['depth'] ?? 99)) {
            $working[$key] = $candidate;
        }
    }

    private function dedupeQueue(array $queue, array $visited): array
    {
        $seen = [];
        $result = [];
        foreach ($queue as $item) {
            if (!is_array($item)) {
                continue;
            }
            $node = $this->digits((string) ($item['node'] ?? ''));
            $key = (string) ($item['direct_node'] ?? '') . ':' . $node;
            if ($node === '' || isset($visited[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
        }
        return $result;
    }

    private function sortNodes(array &$nodes): void
    {
        usort($nodes, static function (array $a, array $b): int {
            $direct = strnatcasecmp((string) ($a['direct_node'] ?? ''), (string) ($b['direct_node'] ?? ''));
            if ($direct !== 0) {
                return $direct;
            }
            $depth = ((int) ($a['depth'] ?? 99)) <=> ((int) ($b['depth'] ?? 99));
            if ($depth !== 0) {
                return $depth;
            }
            $parent = strnatcasecmp((string) ($a['parent_node'] ?? ''), (string) ($b['parent_node'] ?? ''));
            if ($parent !== 0) {
                return $parent;
            }
            $kindOrder = static fn(array $item): int => match ((string) ($item['kind'] ?? '')) {
                'asl' => 0,
                'client' => 1,
                'echo' => 2,
                default => 3,
            };
            $kind = $kindOrder($a) <=> $kindOrder($b);
            if ($kind !== 0) {
                return $kind;
            }
            return strnatcasecmp((string) ($a['node'] ?? ''), (string) ($b['node'] ?? ''));
        });
    }

    private function peerLocalSnapshots(array $direct, string $localNode): array
    {
        if ($localNode === '' || $direct === []) {
            return [];
        }

        $wanted = [];
        foreach ($direct as $item) {
            $node = $this->digits((string) ($item['node'] ?? ''));
            if ($node !== '') {
                $wanted[$node] = true;
            }
        }

        $output = $this->readHelper(['rpt-lstats', $localNode]);
        if ($output === null) {
            return [];
        }

        $snapshots = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim((string) $line)) ?: [];
            $node = $this->digits((string) ($parts[0] ?? ''));
            $peerIp = trim((string) ($parts[1] ?? ''));
            if (count($parts) < 6 || !isset($wanted[$node])
                || strtoupper((string) end($parts)) !== 'ESTABLISHED'
                || preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/', $peerIp) !== 1) {
                continue;
            }

            $host = str_contains($peerIp, ':') ? '[' . $peerIp . ']' : $peerIp;
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::PEER_STATUS_TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nConnection: close\r\nUser-Agent: AllStarConnect-Peer/0.50\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
            $raw = @file_get_contents('https://' . $host . '/allstar_connect/api/local.php', false, $context);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            $snapshot = is_array($payload['data'] ?? null) ? $payload['data'] : null;
            if (!is_array($snapshot) || empty($payload['ok']) || empty($snapshot['ok'])
                || $this->digits((string) ($snapshot['node'] ?? '')) !== $node
                || !is_array($snapshot['connections'] ?? null)) {
                continue;
            }
            $snapshots[$node] = $snapshot['connections'];
        }

        return $snapshots;
    }

    private function overlayPeerRootRows(array $display, array $snapshots, string $localNode): array
    {
        $parents = [];
        $cachedAsl = [];
        foreach ($display as $item) {
            if (!is_array($item) || strtolower((string) ($item['kind'] ?? '')) !== 'asl') {
                continue;
            }
            $directNode = $this->digits((string) ($item['direct_node'] ?? ''));
            $node = $this->digits((string) ($item['node'] ?? ''));
            $parentNode = $this->digits((string) ($item['parent_node'] ?? ''));
            if ($directNode !== '' && $node !== '' && $parentNode !== '') {
                $parents[$directNode][$node] = $parentNode;
                $cachedAsl[$directNode][$node] = $item;
            }
        }

        $liveAsl = [];
        $liveRows = [];
        foreach ($snapshots as $directNode => $connections) {
            $directNode = $this->digits((string) $directNode);
            if ($directNode === '' || !is_array($connections)) {
                continue;
            }

            foreach ($connections as $connection) {
                if (!is_array($connection)) {
                    continue;
                }

                $kind = strtolower(trim((string) ($connection['kind'] ?? '')));
                $clientType = strtolower(trim((string) ($connection['client_type'] ?? '')));
                $rawNode = trim((string) ($connection['node'] ?? ''));
                $webPhone = $kind === 'client'
                    && ($clientType === 'web_phone' || preg_match('/-P$/i', $rawNode) === 1);

                if ($kind === 'asl') {
                    $node = $this->digits($rawNode);
                    if ($node === '' || $node === $directNode) {
                        continue;
                    }

                    $liveAsl[$directNode][$node] = true;
                    $cached = is_array($cachedAsl[$directNode][$node] ?? null)
                        ? $cachedAsl[$directNode][$node]
                        : [];
                    $callsign = trim((string) ($cached['callsign'] ?? ''));
                    if ($callsign === '') {
                        $callsign = trim((string) ($connection['callsign'] ?? ''));
                    }
                    $description = trim((string) ($cached['description'] ?? ''));
                    if ($description === '') {
                        $description = trim((string) ($connection['description'] ?? ''));
                    }
                    $location = trim((string) ($cached['location'] ?? ''));
                    if ($location === '') {
                        $location = trim((string) ($connection['location'] ?? ''));
                    }
                    $nodeNumber = (int) $node;
                    $isPrivate = ($nodeNumber >= 1000 && $nodeNumber <= 1999)
                        || ($callsign === '' && $description === '' && $location === '');
                    $mode = (string) ($connection['mode'] ?? 'transceive');
                    $mode = $mode === 'local_monitor' ? 'local_monitor' : 'transceive';
                    $liveRows[] = [
                        'key' => 'downstream:' . $directNode . ':' . $node,
                        'kind' => 'asl',
                        'source' => $isPrivate ? 'Private Node' : 'AllStarLink',
                        'node' => $node,
                        'callsign' => $callsign,
                        'description' => $isPrivate ? 'Private Node' : $description,
                        'location' => $location,
                        'display' => $isPrivate
                            ? 'Node ' . $node . ' - Private Node'
                            : trim((string) ($connection['display'] ?? ($callsign !== '' ? $callsign : ($description !== '' ? $description : $node)))),
                        'mode' => $mode,
                        'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
                        'direct_node' => $directNode,
                        'parent_node' => $directNode,
                        'depth' => 1,
                        'is_private' => $isPrivate,
                        'stats_url' => $isPrivate ? '' : 'https://stats.allstarlink.org/stats/' . rawurlencode($node),
                        'qrz_url' => $isPrivate ? '' : (string) ($cached['qrz_url'] ?? ($connection['qrz_url'] ?? '')),
                        'remote_reported' => true,
                    ];
                    continue;
                }

                if (!$webPhone && $kind !== 'echo') {
                    continue;
                }

                $callsign = trim((string) ($connection['callsign'] ?? ''));
                $identity = $callsign !== '' ? strtoupper($callsign) : $rawNode;
                if ($identity === '') {
                    continue;
                }
                $mode = (string) ($connection['mode'] ?? 'transceive');
                $mode = $mode === 'local_monitor' ? 'local_monitor' : 'transceive';
                $liveRows[] = [
                    'key' => 'downstream-peer-' . $kind . ':' . $directNode . ':' . $identity,
                    'kind' => $kind,
                    'client_type' => $webPhone ? 'web_phone' : '',
                    'source' => $kind === 'echo' ? 'EchoLink' : 'Web/Phone Client',
                    'node' => $rawNode,
                    'reported_node' => trim((string) ($connection['reported_node'] ?? '')),
                    'echolink_node' => trim((string) ($connection['echolink_node'] ?? '')),
                    'callsign' => $callsign,
                    'description' => trim((string) ($connection['description'] ?? '')),
                    'location' => trim((string) ($connection['location'] ?? '')),
                    'display' => trim((string) ($connection['display'] ?? $identity)),
                    'mode' => $mode,
                    'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
                    'direct_node' => $directNode,
                    'parent_node' => $directNode,
                    'depth' => 1,
                    'stats_url' => '',
                    'qrz_url' => (string) ($connection['qrz_url'] ?? ''),
                    'identity_pending' => !empty($connection['identity_pending']),
                    'remote_reported' => true,
                ];
            }
        }

        $map = [];
        foreach ($display as $item) {
            if (!is_array($item)) {
                continue;
            }

            $directNode = $this->digits((string) ($item['direct_node'] ?? ''));
            if ($directNode === '' || !isset($snapshots[$directNode])) {
                $this->addWorkingNode($map, $item);
                continue;
            }

            $kind = strtolower((string) ($item['kind'] ?? ''));
            $clientType = strtolower((string) ($item['client_type'] ?? ''));
            $atRoot = $this->digits((string) ($item['parent_node'] ?? '')) === $directNode;
            $authoritativeRootRow = $kind === 'asl'
                || $kind === 'echo'
                || ($kind === 'client' && $clientType === 'web_phone');
            if ($atRoot && $authoritativeRootRow) {
                continue;
            }

            $rootChild = $this->rootAslChild($item, $directNode, $parents[$directNode] ?? []);
            if ($rootChild !== '' && !isset($liveAsl[$directNode][$rootChild])) {
                continue;
            }

            $this->addWorkingNode($map, $item);
        }

        foreach ($liveRows as $item) {
            $this->addWorkingNode($map, $item);
        }

        $result = array_values($map);
        $this->sortNodes($result);
        return $result;
    }

    private function overlayLocalNodeBranches(array $display, array $direct, string $localNode): array
    {
        $parents = [];
        $localRows = [];
        foreach ($display as $item) {
            if (!is_array($item) || ($item['kind'] ?? '') !== 'asl') {
                continue;
            }

            $directNode = $this->digits((string) ($item['direct_node'] ?? ''));
            $node = $this->digits((string) ($item['node'] ?? ''));
            $parentNode = $this->digits((string) ($item['parent_node'] ?? ''));
            if ($directNode !== '' && $node !== '' && $parentNode !== '') {
                $parents[$directNode][$node] = $parentNode;
            }
            if ($node === $localNode) {
                $localRows[] = $item;
            }
        }

        if ($localRows === []) {
            return $display;
        }

        $map = [];
        foreach ($display as $item) {
            if (is_array($item)) {
                $this->addWorkingNode($map, $item);
            }
        }

        foreach ($localRows as $localRow) {
            $directNode = $this->digits((string) ($localRow['direct_node'] ?? ''));
            $ancestors = [$localNode => true];
            for ($node = $localNode; $node !== '';) {
                $node = $this->digits((string) ($parents[$directNode][$node] ?? ''));
                if ($node === '' || isset($ancestors[$node])) {
                    break;
                }
                $ancestors[$node] = true;
            }

            foreach ($direct as $connection) {
                if (!is_array($connection)) {
                    continue;
                }

                $node = $this->digits((string) ($connection['node'] ?? ''));
                if ($node === '' || isset($ancestors[$node])) {
                    continue;
                }

                $mode = ($connection['mode'] ?? '') === 'local_monitor' ? 'local_monitor' : 'transceive';
                $isPrivate = (int) $node >= 1000 && (int) $node <= 1999;
                $this->addWorkingNode($map, array_merge($connection, [
                    'key' => 'downstream:' . $directNode . ':' . $node,
                    'kind' => 'asl',
                    'source' => $isPrivate ? 'Private Node' : 'AllStarLink',
                    'description' => $isPrivate ? 'Private Node' : (string) ($connection['description'] ?? ''),
                    'mode' => $mode,
                    'mode_label' => $mode === 'local_monitor' ? 'Local Monitor' : 'Transceive',
                    'direct_node' => $directNode,
                    'parent_node' => $localNode,
                    'depth' => max(1, (int) ($localRow['depth'] ?? 1)) + 1,
                    'is_private' => $isPrivate,
                    'stats_url' => $isPrivate ? '' : (string) ($connection['stats_url'] ?? ''),
                    'qrz_url' => $isPrivate ? '' : (string) ($connection['qrz_url'] ?? ''),
                    'local_reported' => true,
                ]));
            }
        }

        $result = array_values($map);
        $this->sortNodes($result);
        return $result;
    }

    private function normalizePrivateNodeClassification(array $items): array
    {
        foreach ($items as &$item) {
            if (!is_array($item) || strtolower((string) ($item['kind'] ?? 'asl')) !== 'asl') {
                continue;
            }

            $node = $this->digits((string) ($item['node'] ?? ''));
            if ($node === '') {
                continue;
            }

            $callsign = NodeIdentity::cleanDisplay((string) ($item['callsign'] ?? ''));
            $description = NodeIdentity::cleanDisplay((string) ($item['description'] ?? ''));
            $location = NodeIdentity::cleanDisplay((string) ($item['location'] ?? ''));
            if (strcasecmp($description, 'Private Node') === 0) {
                $description = '';
            }

            $number = (int) $node;
            $private = ($number >= 1000 && $number <= 1999)
                || ($callsign === '' && $description === '' && $location === '');
            $item['is_private'] = $private;

            if ($private) {
                $item['source'] = 'Private Node';
                $item['description'] = 'Private Node';
                $item['display'] = 'Node ' . $node . ' - Private Node';
                $item['stats_url'] = '';
                $item['qrz_url'] = '';
                continue;
            }

            $item['source'] = 'AllStarLink';
            $item['description'] = $description;
            if (preg_match('/^Node\s+' . preg_quote($node, '/') . '\s+-\s+Private Node$/i', (string) ($item['display'] ?? '')) === 1) {
                $item['display'] = $callsign !== '' ? $callsign : ($description !== '' ? $description : $node);
            }
            $item['stats_url'] = 'https://stats.allstarlink.org/stats/' . rawurlencode($node);
            $qrz = NodeIdentity::qrzCallsign($callsign);
            $item['qrz_url'] = $qrz !== '' ? 'https://www.qrz.com/db/' . rawurlencode($qrz) : '';
        }
        unset($item);
        return $items;
    }

    private function rootAslChild(array $item, string $directNode, array $parents): string
    {
        $kind = strtolower((string) ($item['kind'] ?? ''));
        $current = $kind === 'asl'
            ? $this->digits((string) ($item['node'] ?? ''))
            : $this->digits((string) ($item['parent_node'] ?? ''));
        if ($current === '' || $current === $directNode) {
            return '';
        }

        $seen = [];
        while ($current !== '') {
            if (isset($seen[$current])) {
                return '';
            }
            $seen[$current] = true;
            $parent = $this->digits((string) ($parents[$current] ?? ''));
            if ($parent === $directNode) {
                return $current;
            }
            if ($parent === '') {
                return '';
            }
            $current = $parent;
        }

        return '';
    }

    private function readHelper(array $arguments): ?string
    {
        $helper = dirname(__DIR__) . '/bin/allstar-connect-read.sh';
        $shell = '/usr/bin/timeout 2 /usr/bin/sudo -n ' . escapeshellarg($helper);
        foreach ($arguments as $argument) {
            $shell .= ' ' . escapeshellarg((string) $argument);
        }
        $output = shell_exec($shell . ' 2>/dev/null');
        return is_string($output) ? trim($output) : null;
    }

    private function response(array $state, bool $refreshInProgress, array $peerSnapshots = [], string $localNode = ''): array
    {
        $scan = is_array($state['scan'] ?? null) ? $state['scan'] : null;
        $display = array_values(array_filter($state['display_nodes'] ?? [], 'is_array'));
        $working = $scan !== null ? array_values(array_filter($scan['working_nodes'] ?? [], 'is_array')) : [];

        if ($working !== []) {
            $merged = [];
            foreach ($display as $item) {
                $this->addWorkingNode($merged, $item);
            }
            foreach ($working as $item) {
                $this->addWorkingNode($merged, $item);
            }
            $display = array_values($merged);
            $this->sortNodes($display);
        }

        if ($peerSnapshots !== []) {
            $display = $this->overlayPeerRootRows($display, $peerSnapshots, $localNode);
        }

        $direct = array_values(array_filter($state['direct'] ?? [], 'is_array'));
        if ($localNode !== '' && $direct !== []) {
            $display = $this->overlayLocalNodeBranches($display, $direct, $localNode);
        }
        $display = $this->normalizePrivateNodeClassification($display);
        $pending = $scan !== null ? count($scan['queue'] ?? []) : 0;
        $updatedAt = $state['display_updated_at'] ?? null;
        $updatedTs = strtotime((string) $updatedAt);
        $stale = $updatedTs === false || (time() - $updatedTs) > (self::REFRESH_SECONDS * 2);
        $publicNodeCount = 0;
        $privateNodeCount = 0;
        $remoteClientCount = 0;
        $echoLinkCount = 0;
        foreach ($display as $item) {
            $kind = (string) ($item['kind'] ?? 'asl');
            if ($kind === 'client') {
                $remoteClientCount++;
            } elseif ($kind === 'echo') {
                $echoLinkCount++;
            } elseif (!empty($item['is_private'])) {
                $privateNodeCount++;
            } else {
                $publicNodeCount++;
            }
        }

        return [
            'ok' => true,
            'timestamp' => gmdate('c'),
            'direct' => $direct,
            'nodes' => $display,
            'summary' => [
                'downstream' => $publicNodeCount,
                'private' => $privateNodeCount,
                'remote_clients' => $remoteClientCount,
                'echolink' => $echoLinkCount,
                'direct_sources' => count($direct),
                'hidden' => (int) (($state['last_scan']['hidden'] ?? 0)),
            ],
            'cache' => [
                'updated_at' => $updatedAt,
                'stale' => $stale,
                'refreshing' => $scan !== null || $refreshInProgress,
                'pending' => $pending,
                'api_reads' => (int) (($scan['api_reads'] ?? ($state['last_scan']['api_reads'] ?? 0))),
            ],
            'warnings' => [],
        ];
    }

    private function shouldShowNode(string $node, string $localNode): bool
    {
        if ($node === '' || $node === $localNode || isset($this->hiddenNodes()[$node])) {
            return false;
        }

        // A numeric node reported as connected belongs in the topology. Public
        // identity is resolved later; unresolved numeric rows are displayed as
        // Private rather than being discarded by number-prefix assumptions.
        return preg_match('/^[0-9]{4,7}$/', $node) === 1;
    }

    private function hiddenNodes(): array
    {
        static $hidden = null;
        if (is_array($hidden)) {
            return $hidden;
        }

        $hidden = [];
        foreach (['MYNODE', 'DVSWITCH_NODE', 'HIDE_NODES', 'PRIVATE_NODES', 'ALLSTAR_CONNECT_HIDE_NODES'] as $key) {
            $raw = (string) $this->config->get($key, '');
            foreach (preg_split('/[,\s]+/', $raw) ?: [] as $item) {
                $node = $this->digits($item);
                if ($node !== '') {
                    $hidden[$node] = true;
                }
            }
        }
        return $hidden;
    }

    private function digits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    private function readJson(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $temp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($temp, $json, LOCK_EX) !== false) {
            @chmod($temp, 0660);
            @rename($temp, $path);
        }
        @unlink($temp);
    }
}
