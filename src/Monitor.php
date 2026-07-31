<?php
declare(strict_types=1);

namespace AllStarConnect;

require_once __DIR__ . '/CacheMaintenance.php';
require_once __DIR__ . '/NodeIdentity.php';

use AllStarConnect\Support\Config;

final class Monitor
{
    private const CACHE_TTL_SECONDS = 0.65;
    private const CACHE_FILE = '/run/local.json';
    private const LOCK_FILE = '/run/local.lock';
    private const STATE_FILE = '/run/state.json';
    private const ACTIVITY_LOG_FILE = '/var/log/allstar-connect/activity.log';
    private const ACTIVITY_PREVIEW_LIMIT = 50;
    private const ACTIVITY_LOG_RETAIN_LINES = 50;

    public function __construct(private Config $config)
    {
    }

    public function snapshot(): array
    {
        $root = dirname(__DIR__);
        CacheMaintenance::run($root);
        $cachePath = $root . self::CACHE_FILE;
        $lockPath = $root . self::LOCK_FILE;
        $cached = $this->readJsonFile($cachePath);

        if ($cached !== null && $this->cacheAgeSeconds($cachePath) < self::CACHE_TTL_SECONDS) {
            $cached['cache'] = ['hit' => true, 'stale' => false];
            return $this->publicSnapshot($cached);
        }

        $lock = @fopen($lockPath, 'c');
        if ($lock !== false && !@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            if ($cached !== null) {
                $cached['cache'] = ['hit' => true, 'stale' => true];
                return $this->publicSnapshot($cached);
            }
        }

        try {
            $snapshot = $this->collect(is_array($cached) ? $cached : null);
            $snapshot['activity'] = !empty($snapshot['ok'])
                ? $this->updateActivityState($root, $snapshot['connections'] ?? [], (string) ($snapshot['timestamp'] ?? gmdate('c')))
                : $this->normalizeActivityPreview($this->loadActivityPreview($root));
            $snapshot['cache'] = ['hit' => false, 'stale' => false];
            $this->writeJsonFile($cachePath, $snapshot);
            return $this->publicSnapshot($snapshot);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    private function publicSnapshot(array $snapshot): array
    {
        return $snapshot;
    }

    private function collect(?array $previousSnapshot = null): array
    {
        $started = microtime(true);
        $myNode = trim((string) $this->config->get('MYNODE', ''));
        $privateNode = trim((string) $this->config->get('DVSWITCH_NODE', ''));
        $previousSystem = is_array($previousSnapshot['system'] ?? null)
            ? $previousSnapshot['system']
            : null;
        $system = $this->collectSystemStats($previousSystem);

        if ($myNode === '' || preg_match('/^\d+$/', $myNode) !== 1) {
            return [
                'ok' => false,
                'timestamp' => gmdate('c'),
                'collection_ms' => 0,
                'node' => $myNode,
                'system' => $system,
                'connections' => [],
                'summary' => ['direct' => 0, 'keyed' => 0, 'hidden_private' => 0],
                'warnings' => [],
                'sources' => ['ami' => false, 'iax' => false],
            ];
        }

        $ami = $this->fetchAmiLinks($myNode);
        $links = $ami['links'];
        $directions = $this->readLinkDirections($myNode);
        $trackedDirections = $this->trackedLinkDirections();
        foreach ($links as &$link) {
            $linkNode = trim((string) ($link['node'] ?? ''));
            $reportedDirection = strtoupper(trim((string) ($link['direction'] ?? '')));
            if (!in_array($reportedDirection, ['IN', 'OUT'], true)) {
                if (isset($directions[$linkNode])) {
                    $link['direction'] = $directions[$linkNode];
                } elseif (isset($trackedDirections[$linkNode])) {
                    $link['direction'] = $trackedDirections[$linkNode];
                }
            }
        }
        unset($link);
        $iax = $this->readIaxChannels($myNode);
        if ($iax['available']) {
            $links = $this->mergeIaxLinks($links, $iax['channels'], $myNode);
        }

        $hiddenPrivate = 0;
        $connections = [];
        foreach ($links as $link) {
            $node = trim((string) ($link['node'] ?? ''));
            if ($node === '' || $node === $myNode) {
                continue;
            }

            if ($privateNode !== '' && $node === $privateNode) {
                $hiddenPrivate++;
                continue;
            }

            $connections[] = $this->normalizeConnection($link);
        }

        usort($connections, static function (array $a, array $b): int {
            $keyed = ((int) !empty($b['keyed'])) <=> ((int) !empty($a['keyed']));
            if ($keyed !== 0) {
                return $keyed;
            }

            $kindOrder = ['asl' => 0, 'echo' => 1, 'iax' => 2, 'client' => 3];
            $kind = ($kindOrder[$a['kind']] ?? 9) <=> ($kindOrder[$b['kind']] ?? 9);
            if ($kind !== 0) {
                return $kind;
            }

            return strnatcasecmp((string) $a['node'], (string) $b['node']);
        });

        return [
            'ok' => true,
            'timestamp' => gmdate('c'),
            'collection_ms' => (int) round((microtime(true) - $started) * 1000),
            'node' => $myNode,
            'system' => $system,
            'connections' => $connections,
            'summary' => [
                'direct' => count($connections),
                'keyed' => count(array_filter($connections, static fn (array $item): bool => !empty($item['keyed']))),
                'hidden_private' => $hiddenPrivate,
            ],
            'warnings' => [],
            'sources' => [
                'ami' => $ami['available'],
                'iax' => $iax['available'],
            ],
        ];
    }

    private function collectSystemStats(?array $previousSystem): array
    {
        $now = microtime(true);
        $previousCollectedAt = (float) ($previousSystem['collected_at'] ?? 0.0);
        if ($previousSystem !== null
            && $previousCollectedAt > 0.0
            && ($now - $previousCollectedAt) < 2.5
            && isset(
                $previousSystem['cpu_compact'],
                $previousSystem['memory_compact'],
                $previousSystem['temperature_compact'],
                $previousSystem['uptime_compact'],
                $previousSystem['root_compact']
            )) {
            return $previousSystem;
        }

        $cpu = $this->cpuUsage();
        $memory = $this->memoryUsage();
        $temperature = $this->temperature();
        $uptime = $this->uptime();
        $rootDisk = $this->diskUsage('/', '/');

        return [
            'collected_at' => $now,
            'cpu_percent' => $cpu['percent'],
            'cpu_compact' => $cpu['compact'],
            'memory_used_bytes' => $memory['used'],
            'memory_total_bytes' => $memory['total'],
            'memory_compact' => $memory['compact'],
            'temperature_c' => $temperature['c'],
            'temperature_f' => $temperature['f'],
            'temperature_compact' => $temperature['compact'],
            'uptime_seconds' => $uptime['seconds'],
            'uptime_compact' => $uptime['compact'],
            'root_used_percent' => $rootDisk['percent'],
            'root_compact' => $rootDisk['compact'],
        ];
    }

    private function cpuUsage(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/stat')) {
            return ['percent' => null, 'compact' => 'N/A'];
        }

        $sample = function (): ?array {
            $text = $this->readText('/proc/stat');
            if ($text === null) {
                return null;
            }

            $firstLine = strtok($text, "\n");
            if (!is_string($firstLine) || strpos($firstLine, 'cpu ') !== 0) {
                return null;
            }

            $parts = preg_split('/\s+/', trim($firstLine));
            if (!is_array($parts) || count($parts) < 8) {
                return null;
            }

            $numbers = array_map('floatval', array_slice($parts, 1));
            return [
                'idle' => ($numbers[3] ?? 0.0) + ($numbers[4] ?? 0.0),
                'total' => array_sum($numbers),
            ];
        };

        $first = $sample();
        usleep(200000);
        $second = $sample();
        if (!is_array($first) || !is_array($second)) {
            return ['percent' => null, 'compact' => 'N/A'];
        }

        $usage = 0.0;
        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];
        if ($totalDelta > 0) {
            $usage = (1.0 - ($idleDelta / $totalDelta)) * 100.0;
        }

        $usage = max(0.0, min(100.0, $usage));
        return [
            'percent' => $usage,
            'compact' => number_format($usage, 0) . '%',
        ];
    }

    private function memoryUsage(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/meminfo')) {
            return ['used' => 0.0, 'total' => 0.0, 'compact' => 'N/A'];
        }

        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $values = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^([^:]+):\s+(\d+)/', $line, $match) === 1) {
                    $values[$match[1]] = (float) $match[2] * 1024.0;
                }
            }
        }

        $total = $values['MemTotal'] ?? 0.0;
        if ($total <= 0.0) {
            return ['used' => 0.0, 'total' => 0.0, 'compact' => 'N/A'];
        }

        $available = $values['MemAvailable']
            ?? (($values['MemFree'] ?? 0.0) + ($values['Buffers'] ?? 0.0) + ($values['Cached'] ?? 0.0));
        $used = max(0.0, $total - $available);

        return [
            'used' => $used,
            'total' => $total,
            'compact' => $this->formatBinary($used, 2) . ' / ' . $this->formatBinary($total, 2),
        ];
    }

    private function temperature(): array
    {
        $chosen = null;
        foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
            $rawText = $this->readText($path);
            if ($rawText === null || !is_numeric($rawText)) {
                continue;
            }

            $celsius = $this->temperatureCelsiusFromRaw((float) $rawText);
            $fahrenheit = ($celsius * 9.0 / 5.0) + 32.0;
            if ($chosen === null || $celsius > $chosen['c']) {
                $chosen = ['c' => $celsius, 'f' => $fahrenheit];
            }
        }

        if ($chosen === null) {
            return ['c' => null, 'f' => null, 'compact' => 'N/A'];
        }

        return [
            'c' => $chosen['c'],
            'f' => $chosen['f'],
            'compact' => number_format($chosen['f'], 1) . "\u{00B0}F / "
                . number_format($chosen['c'], 2) . "\u{00B0}C",
        ];
    }

    private function temperatureCelsiusFromRaw(float $raw): float
    {
        $candidates = $raw >= 1000.0 ? [$raw / 1000.0, $raw / 100.0] : [$raw];
        foreach ($candidates as $candidate) {
            if ($candidate >= 10.0 && $candidate <= 120.0) {
                return $candidate;
            }
        }
        return $candidates[0];
    }

    private function uptime(): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/uptime')) {
            return ['seconds' => 0, 'compact' => 'N/A'];
        }

        $raw = $this->readText('/proc/uptime');
        $seconds = 0;
        if ($raw !== null) {
            $parts = preg_split('/\s+/', trim($raw));
            if (is_array($parts) && isset($parts[0]) && is_numeric($parts[0])) {
                $seconds = (int) floor((float) $parts[0]);
            }
        }
        $seconds = max(0, $seconds);

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($days > 0) {
            $compact = sprintf('%dd %02dh', $days, $hours);
        } elseif ($hours > 0) {
            $compact = sprintf('%dh %02dm', $hours, $minutes);
        } else {
            $compact = sprintf('%d min', $minutes);
        }

        return ['seconds' => $seconds, 'compact' => $compact];
    }

    private function diskUsage(string $path, string $label): array
    {
        if (!file_exists($path)) {
            return ['label' => $label, 'percent' => null, 'compact' => 'N/A'];
        }

        $output = $this->execCommand('df -kP ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($output === '') {
            return ['label' => $label, 'percent' => null, 'compact' => 'N/A'];
        }

        $lines = preg_split('/\R+/', trim($output));
        if (!is_array($lines) || count($lines) < 2) {
            return ['label' => $label, 'percent' => null, 'compact' => 'N/A'];
        }

        $parts = preg_split('/\s+/', trim((string) $lines[1]), 6);
        if (!is_array($parts) || count($parts) < 6) {
            return ['label' => $label, 'percent' => null, 'compact' => 'N/A'];
        }

        $usePercent = (string) $parts[4];
        return [
            'label' => $label,
            'percent' => is_numeric(rtrim($usePercent, '%')) ? (float) rtrim($usePercent, '%') : null,
            'compact' => $usePercent,
        ];
    }

    private function formatBinary(float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = max(0.0, $bytes);
        $unit = 0;
        while ($value >= 1024.0 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        if ($value >= 100) {
            $decimals = 0;
        } elseif ($value >= 10) {
            $decimals = min(1, $precision);
        } else {
            $decimals = $precision;
        }

        return number_format($value, $decimals) . ' ' . $units[$unit];
    }

    private function readText(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $text = @file_get_contents($path);
        return $text === false ? null : trim($text);
    }

    private function execCommand(string $command): string
    {
        if (function_exists('shell_exec')) {
            $output = @shell_exec($command);
            if (is_string($output)) {
                return trim($output);
            }
        }

        if (function_exists('exec')) {
            $lines = [];
            $status = 0;
            @exec($command, $lines, $status);
            if ($lines !== []) {
                return trim(implode("\n", $lines));
            }
        }

        return '';
    }

    private function fetchAmiLinks(string $myNode): array
    {
        $cfg = $this->readAmiConfig();
        if ($cfg === null) {
            return ['available' => false, 'links' => []];
        }

        $errno = 0;
        $error = '';
        $socket = @fsockopen($cfg['host'], $cfg['port'], $errno, $error, 1.0);
        if ($socket === false) {
            return ['available' => false, 'links' => []];
        }

        stream_set_timeout($socket, 1, 250000);

        try {
            if (!$this->amiLogin($socket, $cfg)) {
                return ['available' => false, 'links' => []];
            }

            $xstat = $this->amiRptStatus($socket, $myNode, 'XStat');
            $sawStat = $this->amiRptStatus($socket, $myNode, 'SawStat');

            return [
                'available' => true,
                'links' => $this->parseAmiLinks($xstat, $sawStat),
            ];
        } finally {
            @fclose($socket);
        }
    }

    private function readAmiConfig(): ?array
    {
        $file = '/etc/asterisk/manager.conf';
        if (!is_readable($file)) {
            return null;
        }

        $parsed = @parse_ini_file($file, true, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            return null;
        }

        $host = '127.0.0.1';
        $port = 5038;
        $user = '';
        $pass = '';

        foreach ($parsed as $section => $values) {
            if (!is_array($values)) {
                continue;
            }

            if (strtolower((string) $section) === 'general') {
                $configuredHost = trim((string) ($values['bindaddr'] ?? ''));
                if ($configuredHost !== '' && !in_array($configuredHost, ['0.0.0.0', '::'], true)) {
                    $host = $configuredHost;
                }
                $configuredPort = trim((string) ($values['port'] ?? ''));
                if (ctype_digit($configuredPort)) {
                    $port = (int) $configuredPort;
                }
                continue;
            }

            if ($user === '' && isset($values['secret'])) {
                $user = trim((string) $section);
                $pass = trim((string) $values['secret']);
            }
        }

        if ($user === '' || $pass === '') {
            return null;
        }

        return compact('host', 'port', 'user', 'pass');
    }

    private function amiLogin($socket, array $cfg): bool
    {
        $id = 'allstar_connect_login_' . bin2hex(random_bytes(4));
        $payload = "ACTION: LOGIN\r\n"
            . 'USERNAME: ' . $cfg['user'] . "\r\n"
            . 'SECRET: ' . $cfg['pass'] . "\r\n"
            . "EVENTS: 0\r\n"
            . 'ActionID: ' . $id . "\r\n\r\n";

        if (@fwrite($socket, $payload) === false) {
            return false;
        }

        $response = $this->readAmiResponse($socket, $id);
        return str_contains(implode("\n", $response), 'Authentication accepted');
    }

    private function amiRptStatus($socket, string $node, string $command): array
    {
        $id = strtolower($command) . '_' . bin2hex(random_bytes(4));
        $payload = "ACTION: RptStatus\r\n"
            . 'COMMAND: ' . $command . "\r\n"
            . 'NODE: ' . $node . "\r\n"
            . 'ActionID: ' . $id . "\r\n\r\n";

        if (@fwrite($socket, $payload) === false) {
            return [];
        }

        return $this->readAmiResponse($socket, $id);
    }

    private function readAmiResponse($socket, string $actionId): array
    {
        $lines = [];
        $matched = false;
        $deadline = microtime(true) + 1.5;

        while (microtime(true) < $deadline) {
            $line = fgets($socket);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    break;
                }
                usleep(10000);
                continue;
            }

            $line = trim($line);
            if ($line === '') {
                if ($matched) {
                    break;
                }
                continue;
            }

            if ($line === 'ActionID: ' . $actionId) {
                $matched = true;
            }

            if ($matched || str_starts_with($line, 'Response: ')) {
                if (!in_array($line, ['Privilege: Command', 'Command output follows'], true)) {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    private function parseAmiLinks(array $xstatLines, array $sawStatLines): array
    {
        $connections = [];
        $modes = [];
        $keyed = [];

        foreach ($xstatLines as $line) {
            if (preg_match('/Conn:\s+(.*)/', $line, $match) === 1) {
                $parts = preg_split('/\s+/', trim($match[1])) ?: [];
                $node = trim((string) ($parts[0] ?? ''));
                if ($node !== '' && preg_match('/^[A-Za-z0-9_.:@-]+$/', $node) === 1) {
                    $connections[$node] = [
                        'node' => $node,
                        'direction' => isset($parts[5]) ? ($parts[3] ?? '') : ($parts[2] ?? ''),
                        'elapsed' => isset($parts[5]) ? ($parts[4] ?? '') : ($parts[3] ?? ''),
                        'connection_type' => ctype_digit($node) ? 'node' : 'client',
                    ];
                }
            }

            if (preg_match('/LinkedNodes:\s+(.*)/', $line, $match) === 1) {
                foreach (preg_split('/,\s*/', trim($match[1])) ?: [] as $item) {
                    $item = trim($item);
                    if (strlen($item) < 2) {
                        continue;
                    }
                    $modes[substr($item, 1)] = strtoupper(substr($item, 0, 1));
                }
            }
        }

        foreach ($sawStatLines as $line) {
            if (preg_match('/Conn:\s+(.*)/', $line, $match) !== 1) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($match[1])) ?: [];
            $node = trim((string) ($parts[0] ?? ''));
            if ($node !== '') {
                $keyed[$node] = [
                    'active' => (($parts[1] ?? '0') === '1'),
                    'last' => (string) ($parts[2] ?? '-1'),
                ];
            }
        }

        $links = [];
        foreach ($connections as $node => $connection) {
            $mode = ($modes[$node] ?? '') === 'T' ? 'transceive' : 'local_monitor';
            $lastKeyed = (string) ($keyed[$node]['last'] ?? '-1');

            $links[] = $connection + [
                'link_mode' => $mode,
                'mode_label' => $mode === 'transceive' ? 'Transceive' : 'Local Monitor',
                'keyed' => !empty($keyed[$node]['active']),
                'last_keyed' => $lastKeyed,
            ];
        }

        return $links;
    }

    private function readLinkDirections(string $myNode): array
    {
        $output = $this->asteriskCli('rpt lstats ' . $myNode);
        if ($output === null || $output === '') {
            return [];
        }

        $directions = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim((string) $line)) ?: [];
            $node = trim((string) ($parts[0] ?? ''));
            if ($node === '' || preg_match('/^[A-Za-z0-9_.:@-]{1,96}$/', $node) !== 1) {
                continue;
            }
            foreach (array_slice($parts, 1) as $part) {
                $direction = strtoupper(trim((string) $part));
                if (in_array($direction, ['IN', 'OUT'], true)) {
                    $directions[$node] = $direction;
                    break;
                }
            }
        }
        return $directions;
    }

    private function trackedLinkDirections(): array
    {
        $path = dirname(__DIR__) . '/run/control-state.json';
        if (!is_readable($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        $tracked = is_array($decoded['started_links'] ?? null) ? $decoded['started_links'] : [];
        $directions = [];
        foreach (array_keys($tracked) as $node) {
            $node = trim((string) $node);
            if ($node !== '' && preg_match('/^[0-9]{1,7}$/', $node) === 1) {
                $directions[$node] = 'OUT';
            }
        }
        return $directions;
    }

    private function readIaxChannels(string $myNode): array
    {
        $peerOutput = $this->asteriskCli('iax2 show channels');
        $channelOutput = $this->asteriskCli('core show channels concise');
        if ($peerOutput === null || $channelOutput === null) {
            return ['available' => false, 'channels' => []];
        }

        $peers = [];
        foreach (preg_split('/\R/', $peerOutput) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'IAX2/')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $channel = trim((string) ($parts[0] ?? ''));
            if ($this->validIaxChannel($channel)) {
                $peers[$channel] = [
                    'peer' => trim((string) ($parts[1] ?? '')),
                    'username' => trim((string) ($parts[2] ?? '')),
                ];
            }
        }

        $channels = [];
        foreach (preg_split('/\R/', $channelOutput) ?: [] as $line) {
            $parts = explode('!', trim($line));
            $channel = trim((string) ($parts[0] ?? ''));
            $context = strtolower(trim((string) ($parts[1] ?? '')));
            $extension = trim((string) ($parts[2] ?? ''));
            $state = trim((string) ($parts[4] ?? ''));
            $application = trim((string) ($parts[5] ?? ''));
            $data = trim((string) ($parts[6] ?? ''));

            if (!$this->validIaxChannel($channel) || $application !== 'Rpt') {
                continue;
            }

            $runsThisNode = $data === $myNode
                || str_starts_with($data, $myNode . '|')
                || str_starts_with($data, $myNode . ',')
                || $extension === $myNode;
            if (!$runsThisNode || !in_array($context, ['iaxrpt', 'iax-client', 'iaxclient', 'allstar-public'], true)) {
                continue;
            }

            $phoneMode = $context === 'allstar-public'
                || str_contains($data, '|P')
                || str_contains($data, ',P');
            $mode = str_contains($data, '|X') || str_contains($data, ',X') ? 'transceive' : 'local_monitor';
            $channels[] = [
                'channel' => $channel,
                'state' => $state,
                'context' => $context,
                'data' => $data,
                'peer' => (string) ($peers[$channel]['peer'] ?? ''),
                'username' => (string) ($peers[$channel]['username'] ?? ''),
                // Asterisk's concise channel output reports the live channel
                // duration in field 10. Keep it as the same display-ready
                // elapsed value used by app_rpt xstat rows.
                'elapsed' => trim((string) ($parts[10] ?? '')),
                'phone_mode' => $phoneMode,
                'link_mode' => $mode,
                'mode_label' => $mode === 'transceive' ? 'Transceive' : 'Local Monitor',
            ];
        }

        return ['available' => true, 'channels' => $channels];
    }

    private function mergeIaxLinks(array $links, array $channels, string $myNode): array
    {
        $publicPhoneChannels = [];

        foreach ($channels as $channelInfo) {
            $channel = trim((string) ($channelInfo['channel'] ?? ''));
            $peer = trim((string) ($channelInfo['peer'] ?? ''));
            $username = trim((string) ($channelInfo['username'] ?? ''));
            $context = strtolower(trim((string) ($channelInfo['context'] ?? '')));
            $namedClient = $this->namedIaxClient((string) ($channelInfo['data'] ?? ''), $myNode);
            $phoneMode = !empty($channelInfo['phone_mode']) || $context === 'allstar-public';
            $phoneClient = $phoneMode
                ? $this->phoneClientName($namedClient, $username, $channel)
                : '';

            $matched = false;
            if ($phoneMode) {
                foreach ($links as &$link) {
                    $node = trim((string) ($link['node'] ?? ''));
                    if (!$this->phoneClientMatches($node, $phoneClient, $namedClient, $username, $channel)) {
                        continue;
                    }

                    $this->markWebPhoneLink($link, $channelInfo, $channel, $peer, $username);
                    $matched = true;
                    break;
                }
                unset($link);

                if ($matched) {
                    continue;
                }

                if ($context === 'allstar-public') {
                    // The public-auth IAX channel is transport for the app_rpt
                    // callsign row. Never expose it as a second True IAX link.
                    $publicPhoneChannels[] = [
                        'info' => $channelInfo,
                        'channel' => $channel,
                        'peer' => $peer,
                        'username' => $username,
                    ];
                    continue;
                }
            }

            if ($namedClient !== '' && $this->hasNamedClient($links, $namedClient)) {
                continue;
            }

            if ($peer !== '') {
                foreach ($links as &$link) {
                    $node = trim((string) ($link['node'] ?? ''));
                    if ($node === $peer && (!ctype_digit($node) || ($link['connection_type'] ?? '') === 'client')) {
                        $link['connection_type'] = 'iax_channel';
                        $link['iax_channel'] = $channel;
                        $link['peer'] = $peer;
                        $link['username'] = $username;
                        if (trim((string) ($channelInfo['elapsed'] ?? '')) !== '') {
                            $link['elapsed'] = trim((string) $channelInfo['elapsed']);
                        }
                        $link['link_mode'] = (string) ($channelInfo['link_mode'] ?? 'transceive');
                        $link['mode_label'] = (string) ($channelInfo['mode_label'] ?? 'Transceive');
                        $matched = true;
                        break;
                    }
                }
                unset($link);
            }

            if ($matched) {
                continue;
            }

            $links[] = [
                'node' => $phoneMode && $phoneClient !== ''
                    ? $phoneClient
                    : ($namedClient !== '' ? $namedClient : ($peer !== '' ? $peer : $channel)),
                'connection_type' => $phoneMode ? 'client' : 'iax_channel',
                'client_type' => $phoneMode ? 'web_phone' : '',
                'phone_mode' => $phoneMode,
                'iax_channel' => $channel,
                'peer' => $peer,
                'username' => $username,
                'direction' => 'IN',
                'elapsed' => trim((string) ($channelInfo['elapsed'] ?? '')),
                'link_mode' => (string) ($channelInfo['link_mode'] ?? 'transceive'),
                'mode_label' => (string) ($channelInfo['mode_label'] ?? 'Transceive'),
                'keyed' => false,
            ];
        }

        if ($publicPhoneChannels !== []) {
            $candidates = [];
            foreach ($links as $index => $link) {
                $node = trim((string) ($link['node'] ?? ''));
                $type = strtolower(trim((string) ($link['connection_type'] ?? '')));
                if ($type === 'client' && NodeIdentity::qrzCallsign($node) !== '' && empty($link['phone_mode'])) {
                    $candidates[] = $index;
                }
            }

            // When public-auth channels and callsign rows line up, the AMI
            // callsign rows are the human-facing side of those same sessions.
            // Pair them one-for-one and suppress the raw allstar-public rows.
            if ($candidates !== [] && count($candidates) <= count($publicPhoneChannels)) {
                foreach ($candidates as $position => $index) {
                    $channel = $publicPhoneChannels[$position];
                    $this->markWebPhoneLink(
                        $links[$index],
                        (array) ($channel['info'] ?? []),
                        (string) ($channel['channel'] ?? ''),
                        (string) ($channel['peer'] ?? ''),
                        (string) ($channel['username'] ?? '')
                    );
                }
                $publicPhoneChannels = array_slice($publicPhoneChannels, count($candidates));
            }

            // If Asterisk did not expose a callsign row, keep one generic
            // Web/Phone client rather than mislabeling public-auth transport
            // as a True IAX connection.
            foreach ($publicPhoneChannels as $position => $channel) {
                $channelInfo = (array) ($channel['info'] ?? []);
                $channelName = (string) ($channel['channel'] ?? '');
                $username = (string) ($channel['username'] ?? '');
                $identity = $this->phoneClientName('', $username, $channelName);
                if ($identity === '' || strcasecmp($identity, 'allstar-public') === 0) {
                    $identity = 'WebPhone-' . ($position + 1);
                }
                $links[] = [
                    'node' => $identity,
                    'connection_type' => 'client',
                    'client_type' => 'web_phone',
                    'phone_mode' => true,
                    'iax_channel' => $channelName,
                    'peer' => (string) ($channel['peer'] ?? ''),
                    'username' => $username,
                    'direction' => 'IN',
                    'elapsed' => trim((string) ($channelInfo['elapsed'] ?? '')),
                    'link_mode' => (string) ($channelInfo['link_mode'] ?? 'transceive'),
                    'mode_label' => (string) ($channelInfo['mode_label'] ?? 'Transceive'),
                    'keyed' => false,
                ];
            }
        }

        return $links;
    }

    private function markWebPhoneLink(
        array &$link,
        array $channelInfo,
        string $channel,
        string $peer,
        string $username
    ): void {
        $link['connection_type'] = 'client';
        $link['client_type'] = 'web_phone';
        $link['phone_mode'] = true;
        if ($channel !== '') {
            $link['iax_channel'] = $channel;
        }
        $link['peer'] = $peer;
        $link['username'] = $username;
        if (trim((string) ($channelInfo['elapsed'] ?? '')) !== '') {
            $link['elapsed'] = trim((string) $channelInfo['elapsed']);
        }
        if (empty($link['link_mode'])) {
            $link['link_mode'] = (string) ($channelInfo['link_mode'] ?? 'transceive');
            $link['mode_label'] = (string) ($channelInfo['mode_label'] ?? 'Transceive');
        }
    }

    private function normalizeConnection(array $link): array
    {
        $reportedNode = trim((string) ($link['node'] ?? ''));
        $node = $reportedNode;
        $type = strtolower(trim((string) ($link['connection_type'] ?? '')));
        $upperNode = strtoupper($node);
        $reportedClientType = strtolower(trim((string) ($link['client_type'] ?? '')));
        $isWebPhoneClient = !empty($link['phone_mode'])
            || $reportedClientType === 'web_phone'
            || preg_match('/^[A-Za-z0-9_.:@-]{1,96}-P$/i', $node) === 1;
        $webPhoneCallsign = NodeIdentity::webPhoneCallsign($node);
        if ($webPhoneCallsign === '' && $isWebPhoneClient) {
            $webPhoneCallsign = NodeIdentity::qrzCallsign($node);
        }
        if ($isWebPhoneClient && $webPhoneCallsign !== '') {
            $node = $webPhoneCallsign . '-P';
            $upperNode = strtoupper($node);
        }
        $clientType = '';

        if ($isWebPhoneClient) {
            $kind = 'client';
            $source = 'Web/Phone Client';
            $clientType = 'web_phone';
        } elseif ($type === 'iax_channel') {
            $kind = 'iax';
            $source = 'True IAX';
        } elseif ($upperNode === 'WT' || str_contains($upperNode, 'WEB')) {
            $kind = 'client';
            $source = 'Web / Client';
        } elseif (!ctype_digit($node)) {
            $kind = 'client';
            $source = 'Web / Client';
        } elseif (preg_match('/^3\d{6}$/', $node) === 1) {
            $kind = 'echo';
            $source = 'EchoLink';
        } else {
            $kind = 'asl';
            $source = 'AllStarLink';
        }

        $echoLinkNode = '';
        $echoLinkCallsign = '';
        if ($kind === 'echo') {
            $echoLinkNode = NodeIdentity::echoLinkNodeNumber($reportedNode);
            if ($echoLinkNode !== '') {
                $node = $echoLinkNode;
                // Relay-mode EchoLink sessions may carry a temporary node
                // number. Prefer chan_echolink's live connected identity so
                // that number is never resolved as an unrelated user.
                $echoLinkCallsign = $this->echoLinkLiveCallsign($echoLinkNode);
                if ($echoLinkCallsign === '') {
                    $echoLinkCallsign = $this->echoLinkCachedCallsign($echoLinkNode);
                }
            }
        }

        $details = $kind === 'asl' ? NodeIdentity::astdbLookup($node) : null;
        $callsign = trim((string) ($details['call'] ?? ''));
        if ($callsign === '' && $webPhoneCallsign !== '') {
            $callsign = $webPhoneCallsign;
        }
        if ($callsign === '' && $echoLinkCallsign !== '') {
            $callsign = $echoLinkCallsign;
        }
        $qrzCallsign = NodeIdentity::qrzCallsign($callsign !== '' ? $callsign : $node);
        $description = trim((string) ($details['description'] ?? ''));
        if ($description === '' && $kind === 'echo' && $callsign !== '') {
            $description = $this->echoLinkIdentityLabel($callsign);
        }
        $location = trim((string) ($details['location'] ?? ''));
        $mode = strtolower(trim((string) ($link['link_mode'] ?? '')));
        $mode = $mode === 'local_monitor' ? 'local_monitor' : ($mode === 'transceive' ? 'transceive' : 'connected');
        $modeLabel = $mode === 'local_monitor' ? 'Local Monitor' : ($mode === 'transceive' ? 'Transceive' : 'Connected');
        $iaxChannel = trim((string) ($link['iax_channel'] ?? ''));
        $stableIaxKey = $this->stableIaxChannelKey($iaxChannel);
        $keyIdentity = $clientType === 'web_phone' && $qrzCallsign !== ''
            ? strtolower($qrzCallsign)
            : strtolower(($kind === 'echo' ? $reportedNode : $node) . ':' . $iaxChannel);
        $connectionKey = $stableIaxKey !== ''
            ? $stableIaxKey
            : ($clientType === 'web_phone'
                ? 'client:web_phone:' . $keyIdentity
                : $kind . ':' . ($kind === 'echo' ? $reportedNode : $node) . ':' . $iaxChannel);

        return [
            'key' => $connectionKey,
            'kind' => $kind,
            'source' => $source,
            'client_type' => $clientType,
            'node' => $node,
            'reported_node' => $reportedNode,
            'control_target' => $kind === 'echo' ? $reportedNode : '',
            'disconnect_target' => $kind === 'client' ? $reportedNode : '',
            'echolink_node' => $echoLinkNode,
            'identity_pending' => $kind === 'echo' && $callsign === '',
            'callsign' => $callsign,
            'description' => $description,
            'location' => $location,
            'display' => $callsign !== '' ? $callsign : ($description !== '' ? $description : $node),
            'mode' => $mode,
            'mode_label' => $modeLabel,
            'direction' => strtoupper(trim((string) ($link['direction'] ?? ''))),
            'elapsed' => trim((string) ($link['elapsed'] ?? '')),
            'keyed' => !empty($link['keyed']),
            'channel' => $iaxChannel,
            'peer' => trim((string) ($link['peer'] ?? '')),
            'username' => trim((string) ($link['username'] ?? '')),
            'stats_url' => $kind === 'asl' ? 'https://stats.allstarlink.org/stats/' . rawurlencode($node) : '',
            'qrz_url' => ($kind === 'asl' || $kind === 'echo' || $clientType === 'web_phone') && $qrzCallsign !== ''
                ? 'https://www.qrz.com/db/' . rawurlencode($qrzCallsign)
                : '',
        ];
    }

    private function echoLinkLiveCallsign(string $node): string
    {
        static $cache = [];

        if ($node === '' || $node === '0' || preg_match('/^\d{1,6}$/', $node) !== 1) {
            return '';
        }
        if (array_key_exists($node, $cache)) {
            return $cache[$node];
        }

        $output = $this->asteriskCli('echolink dbget nodename ' . $node);
        if ($output === null || $output === '' || stripos($output, 'error:') !== false) {
            return $cache[$node] = '';
        }

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = explode('|', trim((string) $line));
            if (count($parts) < 2) {
                continue;
            }

            $returnedNode = ltrim(trim((string) ($parts[0] ?? '')), '0') ?: '0';
            if ($returnedNode !== (ltrim($node, '0') ?: '0')) {
                continue;
            }

            $callsign = strtoupper(trim((string) ($parts[1] ?? '')));
            if ($callsign !== '' && strlen($callsign) <= 32
                && preg_match('/^[A-Z0-9*_.\/-]+$/', $callsign) === 1) {
                return $cache[$node] = $callsign;
            }
        }

        return $cache[$node] = '';
    }

    private function echoLinkCachedCallsign(string $node): string
    {
        if ($node === '' || $node === '0') {
            return '';
        }

        $path = dirname(__DIR__) . '/cache/echolink/' . $node . '.json';
        if (!is_readable($path)) {
            return '';
        }

        $decoded = $this->readJsonFile($path);
        if ($decoded === null || empty($decoded['found'])) {
            return '';
        }

        $callsign = strtoupper(trim((string) ($decoded['callsign'] ?? '')));
        return strlen($callsign) <= 32 && preg_match('/^[A-Z0-9*_.\/-]+$/', $callsign) === 1 ? $callsign : '';
    }

    private function echoLinkIdentityLabel(string $callsign): string
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

        return 'EchoLink User';
    }

    private function asteriskCli(string $command): ?string
    {
        $helper = dirname(__DIR__) . '/bin/allstar-connect-read.sh';
        $arguments = match ($command) {
            'iax2 show channels' => ['iax-channels'],
            'core show channels concise' => ['core-channels'],
            default => preg_match('/^echolink dbget nodename ([0-9]{1,6})$/', $command, $match) === 1
                ? ['echolink-name', $match[1]]
                : (preg_match('/^rpt lstats ([0-9]{4,6})$/', $command, $match) === 1
                    ? ['rpt-lstats', $match[1]]
                    : []),
        };

        if ($arguments === []) {
            return null;
        }

        $shell = '/usr/bin/timeout 2 /usr/bin/sudo -n ' . escapeshellarg($helper);
        foreach ($arguments as $argument) {
            $shell .= ' ' . escapeshellarg($argument);
        }
        $output = shell_exec($shell . ' 2>/dev/null');
        return is_string($output) ? trim($output) : null;
    }

    private function stableIaxChannelKey(string $channel): string
    {
        $channel = trim($channel);
        return $this->validIaxChannel($channel) ? 'iax-channel:' . strtolower($channel) : '';
    }

    private function validIaxChannel(string $channel): bool
    {
        return preg_match('/^IAX2\/[A-Za-z0-9_.:@-]{1,96}$/', $channel) === 1;
    }

    private function namedIaxClient(string $data, string $myNode): string
    {
        $parts = preg_split('/[|,]/', trim($data), 3) ?: [];
        if (count($parts) !== 3 || trim((string) $parts[0]) !== $myNode || strtoupper(trim((string) $parts[1])) !== 'P') {
            return '';
        }

        $client = trim((string) $parts[2]);
        return preg_match('/^[A-Za-z0-9_.:@-]{1,96}$/', $client) === 1 ? $client : '';
    }

    private function phoneClientName(string $namedClient, string $username, string $channel): string
    {
        foreach ([$namedClient, $username, $this->iaxChannelName($channel)] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && preg_match('/^[A-Za-z0-9_.:@-]{1,96}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    private function iaxChannelName(string $channel): string
    {
        if (preg_match('/^IAX2\/([A-Za-z0-9_.:@-]+?)-[0-9]+$/', trim($channel), $match) === 1) {
            return $match[1];
        }

        return '';
    }

    private function phoneClientMatches(
        string $node,
        string $phoneClient,
        string $namedClient,
        string $username,
        string $channel
    ): bool {
        if ($node === '' || ctype_digit($node)) {
            return false;
        }

        $nodeUpper = strtoupper($node);
        foreach ([$phoneClient, $namedClient, $username, $this->iaxChannelName($channel)] as $candidate) {
            $candidate = strtoupper(trim($candidate));
            if ($candidate !== '' && ($nodeUpper === $candidate || $nodeUpper === $candidate . '-P')) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedClient(array $links, string $name): bool
    {
        foreach ($links as $link) {
            if (strcasecmp(trim((string) ($link['node'] ?? '')), $name) === 0) {
                return true;
            }
        }
        return false;
    }

    private function updateActivityState(string $root, array $connections, string $timestamp): array
    {
        $statePath = $root . self::STATE_FILE;
        $state = $this->readJsonFile($statePath);
        $now = strtotime($timestamp);
        $now = $now !== false ? $now : time();
        $timestamp = gmdate('c', $now);

        $current = [];
        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }

            $key = trim((string) ($connection['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $current[$key] = $this->activityConnectionState($connection);
        }

        if ($state === null || !isset($state['connections']) || !is_array($state['connections'])) {
            $activity = $this->enrichActivityMetadata(
                $this->normalizeActivityPreview($this->loadActivityPreview($root)),
                $current
            );
            $this->writeJsonFile($statePath, [
                'updated_at' => $timestamp,
                'connections' => $current,
                'activity' => $activity,
            ]);
            return $activity;
        }

        $previous = $this->reconcilePreviousConnectionState($state['connections'], $current);
        $activity = is_array($state['activity'] ?? null)
            ? $this->normalizeActivityPreview(array_values(array_filter($state['activity'], 'is_array')))
            : $this->normalizeActivityPreview($this->loadActivityPreview($root));
        $events = [];

        foreach ($current as $key => &$connection) {
            $old = is_array($previous[$key] ?? null) ? $previous[$key] : null;
            $isKeyed = !empty($connection['keyed']);

            if ($old === null) {
                $events[] = $this->activityEvent('connect', $connection, $timestamp);
                if ($isKeyed) {
                    $connection['keyed_at'] = $now;
                    $events[] = $this->activityEvent('key', $connection, $timestamp);
                }
                continue;
            }

            $wasKeyed = !empty($old['keyed']);
            $keyedAt = isset($old['keyed_at']) && is_numeric($old['keyed_at'])
                ? (int) $old['keyed_at']
                : null;

            if (!$wasKeyed && $isKeyed) {
                $connection['keyed_at'] = $now;
                $events[] = $this->activityEvent('key', $connection, $timestamp);
            } elseif ($wasKeyed && !$isKeyed) {
                $events[] = $this->activityEvent('unkey', $connection, $timestamp, $keyedAt);
                $connection['keyed_at'] = null;
            } else {
                $connection['keyed_at'] = $isKeyed ? $keyedAt : null;
            }
        }
        unset($connection);

        foreach ($previous as $key => $connection) {
            if (isset($current[$key]) || !is_array($connection)) {
                continue;
            }

            if (!empty($connection['keyed'])) {
                $keyedAt = isset($connection['keyed_at']) && is_numeric($connection['keyed_at'])
                    ? (int) $connection['keyed_at']
                    : null;
                $events[] = $this->activityEvent('unkey', $connection, $timestamp, $keyedAt);
            }
            $events[] = $this->activityEvent('disconnect', $connection, $timestamp);
        }

        if ($events !== []) {
            $this->appendActivityEvents(self::ACTIVITY_LOG_FILE, $events);
            $activity = array_slice(array_merge(array_reverse($events), $activity), 0, self::ACTIVITY_PREVIEW_LIMIT);
        } else {
            $activity = array_slice($activity, 0, self::ACTIVITY_PREVIEW_LIMIT);
        }

        $activity = $this->enrichActivityMetadata($activity, $current);

        $this->writeJsonFile($statePath, [
            'updated_at' => $timestamp,
            'connections' => $current,
            'activity' => $activity,
        ]);

        return $activity;
    }

    private function enrichActivityMetadata(array $activity, array $current): array
    {
        if ($activity === [] || $current === []) {
            return $activity;
        }

        foreach ($activity as &$event) {
            if (!is_array($event)) {
                continue;
            }

            $key = trim((string) ($event['key'] ?? ''));
            if ($key === '' || !is_array($current[$key] ?? null)) {
                continue;
            }

            $connection = $current[$key];
            foreach ([
                'kind',
                'client_type',
                'node',
                'reported_node',
                'echolink_node',
                'identity_pending',
                'source',
                'callsign',
                'description',
                'location',
                'channel',
                'peer',
                'stats_url',
                'qrz_url',
            ] as $field) {
                if (array_key_exists($field, $connection)) {
                    $event[$field] = $connection[$field];
                }
            }
        }
        unset($event);

        return $activity;
    }

    private function reconcilePreviousConnectionState(array $previous, array $current): array
    {
        // An IAX session can begin with a temporary identity and be refined on
        // the next poll. The exact Asterisk channel is the stable session ID.
        foreach ($current as $currentKey => $currentConnection) {
            if (isset($previous[$currentKey]) || !is_array($currentConnection)) {
                continue;
            }

            $currentChannel = trim((string) ($currentConnection['channel'] ?? ''));
            $stableIaxKey = $this->stableIaxChannelKey($currentChannel);
            if ($stableIaxKey === '') {
                continue;
            }

            foreach ($previous as $previousKey => $previousConnection) {
                if (!is_array($previousConnection)) {
                    continue;
                }
                $previousChannel = trim((string) ($previousConnection['channel'] ?? ''));
                if ($this->stableIaxChannelKey($previousChannel) !== $stableIaxKey) {
                    continue;
                }

                $old = $previousConnection;
                $previous[$currentKey] = array_merge($currentConnection, [
                    'keyed' => !empty($old['keyed']),
                    'keyed_at' => isset($old['keyed_at']) && is_numeric($old['keyed_at'])
                        ? (int) $old['keyed_at']
                        : null,
                ]);
                unset($previous[$previousKey]);
                break;
            }
        }

        // Keep the prior callsign-based fallback for channel-less Web/Phone
        // rows so CALLSIGN and CALLSIGN-P remain one connection.
        foreach ($current as $currentKey => $currentConnection) {
            if (isset($previous[$currentKey])
                || strtolower(trim((string) ($currentConnection['client_type'] ?? ''))) !== 'web_phone') {
                continue;
            }

            $base = NodeIdentity::qrzCallsign(
                (string) ($currentConnection['callsign'] ?? $currentConnection['node'] ?? '')
            );
            if ($base === '') {
                continue;
            }

            foreach ($previous as $previousKey => $previousConnection) {
                if (!is_array($previousConnection)) {
                    continue;
                }

                $node = trim((string) ($previousConnection['node'] ?? ''));
                $callsign = trim((string) ($previousConnection['callsign'] ?? ''));
                $previousBase = NodeIdentity::qrzCallsign($callsign !== '' ? $callsign : $node);
                $kind = strtolower(trim((string) ($previousConnection['kind'] ?? '')));
                if ($kind !== 'client' || $previousBase !== $base) {
                    continue;
                }

                $old = $previousConnection;
                $previous[$currentKey] = array_merge($currentConnection, [
                    'keyed' => !empty($old['keyed']),
                    'keyed_at' => isset($old['keyed_at']) && is_numeric($old['keyed_at'])
                        ? (int) $old['keyed_at']
                        : null,
                ]);
                unset($previous[$previousKey]);
                break;
            }
        }

        return $previous;
    }

    private function activityConnectionState(array $connection): array
    {
        return [
            'key' => trim((string) ($connection['key'] ?? '')),
            'kind' => trim((string) ($connection['kind'] ?? '')),
            'client_type' => trim((string) ($connection['client_type'] ?? '')),
            'node' => trim((string) ($connection['node'] ?? '')),
            'reported_node' => trim((string) ($connection['reported_node'] ?? '')),
            'echolink_node' => trim((string) ($connection['echolink_node'] ?? '')),
            'identity_pending' => !empty($connection['identity_pending']),
            'source' => trim((string) ($connection['source'] ?? '')),
            'callsign' => trim((string) ($connection['callsign'] ?? '')),
            'description' => trim((string) ($connection['description'] ?? '')),
            'location' => trim((string) ($connection['location'] ?? '')),
            'mode' => trim((string) ($connection['mode'] ?? '')),
            'mode_label' => trim((string) ($connection['mode_label'] ?? '')),
            'direction' => trim((string) ($connection['direction'] ?? '')),
            'channel' => trim((string) ($connection['channel'] ?? '')),
            'peer' => trim((string) ($connection['peer'] ?? '')),
            'stats_url' => trim((string) ($connection['stats_url'] ?? '')),
            'qrz_url' => trim((string) ($connection['qrz_url'] ?? '')),
            'keyed' => !empty($connection['keyed']),
            'keyed_at' => !empty($connection['keyed']) ? time() : null,
        ];
    }

    private function activityEvent(string $type, array $connection, string $timestamp, ?int $keyedAt = null): array
    {
        $event = [
            'id' => bin2hex(random_bytes(6)),
            'type' => $type,
            'key' => trim((string) ($connection['key'] ?? '')),
            'kind' => trim((string) ($connection['kind'] ?? '')),
            'client_type' => trim((string) ($connection['client_type'] ?? '')),
            'node' => trim((string) ($connection['node'] ?? '')),
            'reported_node' => trim((string) ($connection['reported_node'] ?? '')),
            'echolink_node' => trim((string) ($connection['echolink_node'] ?? '')),
            'identity_pending' => !empty($connection['identity_pending']),
            'source' => trim((string) ($connection['source'] ?? '')),
            'callsign' => trim((string) ($connection['callsign'] ?? '')),
            'description' => trim((string) ($connection['description'] ?? '')),
            'location' => trim((string) ($connection['location'] ?? '')),
            'mode' => trim((string) ($connection['mode'] ?? '')),
            'mode_label' => trim((string) ($connection['mode_label'] ?? '')),
            'direction' => trim((string) ($connection['direction'] ?? '')),
            'channel' => trim((string) ($connection['channel'] ?? '')),
            'peer' => trim((string) ($connection['peer'] ?? '')),
            'stats_url' => trim((string) ($connection['stats_url'] ?? '')),
            'qrz_url' => trim((string) ($connection['qrz_url'] ?? '')),
            'timestamp' => $timestamp,
        ];

        if ($type === 'unkey' && $keyedAt !== null) {
            $endedAt = strtotime($timestamp);
            if ($endedAt !== false) {
                $event['duration_seconds'] = max(1, $endedAt - $keyedAt);
            }
        }

        return $event;
    }

    private function appendActivityEvents(string $path, array $events): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $lines = '';
        foreach ($events as $event) {
            $json = json_encode($event, JSON_UNESCAPED_SLASHES);
            if (is_string($json)) {
                $lines .= $json . PHP_EOL;
            }
        }

        if ($lines !== '' && @file_put_contents($path, $lines, FILE_APPEND | LOCK_EX) !== false) {
            @chmod($path, 0640);
            $this->trimActivityLog($path);
        }
    }

    private function trimActivityLog(string $path): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= self::ACTIVITY_LOG_RETAIN_LINES) {
            return;
        }

        $kept = array_slice($lines, -self::ACTIVITY_LOG_RETAIN_LINES);
        $temp = $path . '.tmp.' . getmypid();
        $content = implode(PHP_EOL, $kept) . PHP_EOL;
        if (@file_put_contents($temp, $content, LOCK_EX) !== false) {
            @chmod($temp, 0640);
            @rename($temp, $path);
        }
        @unlink($temp);
    }

    private function loadActivityPreview(string $root): array
    {
        $state = $this->readJsonFile($root . self::STATE_FILE);
        if (is_array($state['activity'] ?? null)) {
            return $this->normalizeActivityPreview(array_slice(array_values(array_filter($state['activity'], 'is_array')), 0, self::ACTIVITY_PREVIEW_LIMIT));
        }

        $path = self::ACTIVITY_LOG_FILE;
        if (!is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return [];
        }

        $events = [];
        foreach (array_reverse(array_slice($lines, -self::ACTIVITY_PREVIEW_LIMIT)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $this->normalizeActivityPreview($events);
    }

    private function normalizeActivityPreview(array $events): array
    {
        $events = array_values(array_filter($events, 'is_array'));
        $drop = [];

        foreach ($events as $rawIndex => $raw) {
            $rawNode = trim((string) ($raw['node'] ?? ''));
            $rawChannel = trim((string) ($raw['channel'] ?? ''));
            $isPublicTransport = str_starts_with(strtoupper($rawNode), 'IAX2/ALLSTAR-PUBLIC-')
                || str_starts_with(strtoupper($rawChannel), 'IAX2/ALLSTAR-PUBLIC-');
            if (!$isPublicTransport) {
                continue;
            }

            $rawTime = strtotime((string) ($raw['timestamp'] ?? ''));
            $rawType = strtolower(trim((string) ($raw['type'] ?? '')));
            $bestIndex = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($events as $candidateIndex => $candidate) {
                if ($candidateIndex === $rawIndex || isset($drop[$candidateIndex])) {
                    continue;
                }
                if (strtolower(trim((string) ($candidate['type'] ?? ''))) !== $rawType) {
                    continue;
                }
                $candidateNode = trim((string) ($candidate['node'] ?? ''));
                $base = NodeIdentity::qrzCallsign($candidateNode);
                if ($base === '' || ctype_digit($candidateNode)) {
                    continue;
                }
                $candidateTime = strtotime((string) ($candidate['timestamp'] ?? ''));
                $distance = ($rawTime !== false && $candidateTime !== false) ? abs($rawTime - $candidateTime) : 0;
                if ($distance <= 3 && $distance < $bestDistance) {
                    $bestIndex = $candidateIndex;
                    $bestDistance = $distance;
                }
            }

            if ($bestIndex !== null) {
                $base = NodeIdentity::qrzCallsign((string) ($events[$bestIndex]['node'] ?? ''));
                $events[$bestIndex]['kind'] = 'client';
                $events[$bestIndex]['client_type'] = 'web_phone';
                $events[$bestIndex]['source'] = 'Web/Phone Client';
                $events[$bestIndex]['node'] = $base . '-P';
                $events[$bestIndex]['callsign'] = $base;
                $events[$bestIndex]['stats_url'] = '';
                $events[$bestIndex]['qrz_url'] = 'https://www.qrz.com/db/' . rawurlencode($base);
                $drop[$rawIndex] = true;
            }
        }

        $normalized = [];
        foreach ($events as $index => $event) {
            if (!isset($drop[$index])) {
                $normalized[] = $event;
            }
        }

        return array_slice($normalized, 0, self::ACTIVITY_PREVIEW_LIMIT);
    }

    private function readJsonFile(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeJsonFile(string $path, array $data): void
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

    private function cacheAgeSeconds(string $path): float
    {
        $modified = @filemtime($path);
        return is_int($modified) ? max(0.0, microtime(true) - $modified) : INF;
    }
}
