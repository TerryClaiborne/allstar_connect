<?php
declare(strict_types=1);

namespace AllStarConnect;

require_once __DIR__ . '/CacheMaintenance.php';
require_once __DIR__ . '/NodeIdentity.php';

final class EchoLink
{
    private const CACHE_DIR = '/cache/echolink';
    private const LOCK_FILE = '/run/echolink.lock';
    private const LOOKUP_URL = 'https://www.echolink.org/validation/node_lookup.jsp';
    private const SUCCESS_REFRESH_SECONDS = 604800;
    private const MISS_RETRY_SECONDS = 900;
    private const REQUEST_TIMEOUT_SECONDS = 2.5;

    public function snapshot(array $connections, array $additionalIdentifiers = []): array
    {
        $root = dirname(__DIR__);
        CacheMaintenance::run($root);
        $identifiers = $this->echoLinkIdentifiers($connections, $additionalIdentifiers);
        if ($identifiers === []) {
            return [
                'entries' => [],
                'updated' => false,
                'pending' => 0,
                'retry_after_seconds' => 0,
                'warnings' => [],
            ];
        }

        $entries = [];
        $lookupIdentifier = '';
        foreach ($identifiers as $identifier) {
            $key = $this->identifierKey($identifier);
            $cached = $this->readCache($root, $identifier);
            if ($cached !== null) {
                $entries[$key] = $this->publicEntry($cached);
                if ($lookupIdentifier === '' && $this->cacheNeedsRefresh($cached)) {
                    $lookupIdentifier = $identifier;
                }
            } elseif ($lookupIdentifier === '') {
                $lookupIdentifier = $identifier;
            }
        }

        $updated = false;
        if ($lookupIdentifier !== '') {
            $lock = @fopen($root . self::LOCK_FILE, 'c');
            if ($lock !== false && @flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    $existing = $this->readCache($root, $lookupIdentifier);
                    $result = $this->lookup($lookupIdentifier);
                    $key = $this->identifierKey($lookupIdentifier);
                    if (empty($result['request_ok']) && is_array($existing) && !empty($existing['found'])) {
                        $entries[$key] = $this->publicEntry($existing);
                    } else {
                        $this->writeCache($root, $lookupIdentifier, $result);
                        $this->writeResolvedAliases($root, $result);
                        $entries[$key] = $this->publicEntry($result);
                        $updated = true;
                    }
                } finally {
                    @flock($lock, LOCK_UN);
                    @fclose($lock);
                }
            } elseif (is_resource($lock)) {
                @fclose($lock);
            }
        }

        $pending = 0;
        foreach ($identifiers as $identifier) {
            $entry = $entries[$this->identifierKey($identifier)] ?? null;
            if (!is_array($entry) || empty($entry['callsign']) || empty($entry['node'])) {
                $pending++;
            }
        }

        ksort($entries, SORT_NATURAL);
        return [
            'entries' => $entries,
            'updated' => $updated,
            'pending' => $pending,
            'retry_after_seconds' => $pending > 0 ? $this->retryAfterSeconds($root, $identifiers) : 0,
            'warnings' => [],
        ];
    }

    private function echoLinkIdentifiers(array $connections, array $additionalIdentifiers): array
    {
        $identifiers = [];

        // Callsigns are intentionally inserted first. Relay-mode sessions can
        // carry a temporary node number, while the live callsign is reliable.
        foreach ($connections as $connection) {
            if (!is_array($connection) || strtolower(trim((string) ($connection['kind'] ?? ''))) !== 'echo') {
                continue;
            }

            $callsign = $this->normalizeCallsign((string) ($connection['callsign'] ?? ''));
            if ($callsign !== '') {
                $identifiers[$this->identifierKey($callsign)] = $callsign;
            }
        }

        foreach ($connections as $connection) {
            if (!is_array($connection) || strtolower(trim((string) ($connection['kind'] ?? ''))) !== 'echo') {
                continue;
            }
            if ($this->normalizeCallsign((string) ($connection['callsign'] ?? '')) !== '') {
                continue;
            }

            $node = NodeIdentity::echoLinkNodeNumber((string) ($connection['echolink_node'] ?? $connection['reported_node'] ?? $connection['node'] ?? ''));
            if ($node !== '' && $node !== '0') {
                $identifiers[$this->identifierKey($node)] = $node;
            }
        }

        foreach ($additionalIdentifiers as $value) {
            $identifier = $this->normalizeIdentifier((string) $value);
            if ($identifier !== '') {
                $identifiers[$this->identifierKey($identifier)] = $identifier;
            }
        }

        return array_values($identifiers);
    }

    private function normalizeIdentifier(string $value): string
    {
        $node = NodeIdentity::echoLinkNodeNumber($value);
        return $node !== '' ? $node : $this->normalizeCallsign($value);
    }

    private function normalizeCallsign(string $value): string
    {
        $value = strtoupper(trim($value));
        return $value !== '' && strlen($value) <= 32
            && preg_match('/^[A-Z0-9*_.\/-]+$/', $value) === 1
            ? $value
            : '';
    }

    private function identifierKey(string $identifier): string
    {
        $node = NodeIdentity::echoLinkNodeNumber($identifier);
        return $node !== '' ? $node : 'call:' . strtoupper($identifier);
    }

    private function cacheNeedsRefresh(array $cache): bool
    {
        $checked = strtotime((string) ($cache['checked_at'] ?? ''));
        if ($checked === false) {
            return true;
        }

        $ttl = !empty($cache['found']) ? self::SUCCESS_REFRESH_SECONDS : self::MISS_RETRY_SECONDS;
        return (time() - $checked) >= $ttl;
    }

    private function retryAfterSeconds(string $root, array $identifiers): int
    {
        $next = self::MISS_RETRY_SECONDS;
        foreach ($identifiers as $identifier) {
            $cache = $this->readCache($root, (string) $identifier);
            if ($cache === null) {
                return 1;
            }
            if (!empty($cache['found'])) {
                continue;
            }

            $checked = strtotime((string) ($cache['checked_at'] ?? ''));
            if ($checked === false) {
                return 1;
            }
            $remaining = self::MISS_RETRY_SECONDS - max(0, time() - $checked);
            $next = min($next, max(1, $remaining));
        }

        return max(1, $next);
    }

    private function lookup(string $identifier): array
    {
        $html = $this->requestLookup($identifier);
        $requestOk = is_string($html);
        $match = $requestOk ? $this->parseLookup($html, $identifier) : null;

        return [
            'query' => $identifier,
            'node' => is_array($match) ? (string) ($match['node'] ?? '') : '',
            'callsign' => is_array($match) ? (string) ($match['callsign'] ?? '') : '',
            'found' => is_array($match),
            'request_ok' => $requestOk,
            'checked_at' => gmdate('c'),
        ];
    }

    private function requestLookup(string $identifier): ?string
    {
        $raw = null;
        $post = http_build_query(['call' => $identifier], '', '&', PHP_QUERY_RFC3986);

        if (function_exists('curl_init')) {
            $curl = curl_init(self::LOOKUP_URL);
            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $post,
                    CURLOPT_CONNECTTIMEOUT_MS => 900,
                    CURLOPT_TIMEOUT_MS => (int) round(self::REQUEST_TIMEOUT_SECONDS * 1000),
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml',
                        'Content-Type: application/x-www-form-urlencoded',
                    ],
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

        if ($raw === null && filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                    'ignore_errors' => true,
                    'header' => "User-Agent: AllStarConnect/0.50\r\n"
                        . "Accept: text/html,application/xhtml+xml\r\n"
                        . "Content-Type: application/x-www-form-urlencoded\r\n"
                        . 'Content-Length: ' . strlen($post) . "\r\n",
                    'content' => $post,
                ],
            ]);
            $result = @file_get_contents(self::LOOKUP_URL, false, $context);
            if (is_string($result) && trim($result) !== '') {
                $raw = $result;
            }
        }

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    private function parseLookup(string $html, string $identifier): ?array
    {
        if (preg_match_all('/<tr[^>]*>\s*<td[^>]*>\s*([^<]+?)\s*<\/td>\s*<td[^>]*>\s*(\d+)\s*<\/td>\s*<\/tr>/is', $html, $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        $wantedNode = NodeIdentity::echoLinkNodeNumber($identifier);
        $wantedCallsign = $wantedNode === '' ? $this->normalizeCallsign($identifier) : '';

        foreach ($matches as $match) {
            $rawCallsign = html_entity_decode(strip_tags((string) ($match[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rawCallsign = preg_replace('/\s+/', '', $rawCallsign) ?? '';
            $callsign = $this->normalizeCallsign($rawCallsign);
            $node = NodeIdentity::echoLinkNodeNumber((string) ($match[2] ?? ''));
            if ($callsign === '' || $node === '' || $node === '0') {
                continue;
            }
            if ($wantedNode !== '' && $node !== $wantedNode) {
                continue;
            }
            if ($wantedCallsign !== '' && $callsign !== $wantedCallsign) {
                continue;
            }

            return [
                'node' => $node,
                'callsign' => $callsign,
            ];
        }

        return null;
    }

    private function publicEntry(array $entry): array
    {
        return [
            'node' => trim((string) ($entry['node'] ?? '')),
            'callsign' => trim((string) ($entry['callsign'] ?? '')),
            'found' => !empty($entry['found']),
            'checked_at' => trim((string) ($entry['checked_at'] ?? '')),
        ];
    }

    private function cachePath(string $root, string $identifier): string
    {
        $node = NodeIdentity::echoLinkNodeNumber($identifier);
        $name = $node !== '' ? $node : 'call-' . sha1(strtoupper($identifier));
        return $root . self::CACHE_DIR . '/' . $name . '.json';
    }

    private function readCache(string $root, string $identifier): ?array
    {
        $path = $this->cachePath($root, $identifier);
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeResolvedAliases(string $root, array $data): void
    {
        if (empty($data['found'])) {
            return;
        }

        $node = NodeIdentity::echoLinkNodeNumber((string) ($data['node'] ?? ''));
        $callsign = $this->normalizeCallsign((string) ($data['callsign'] ?? ''));
        if ($node !== '' && $node !== '0') {
            $this->writeCache($root, $node, $data);
        }
        if ($callsign !== '') {
            $this->writeCache($root, $callsign, $data);
        }
    }

    private function writeCache(string $root, string $identifier, array $data): void
    {
        $directory = $root . self::CACHE_DIR;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $path = $this->cachePath($root, $identifier);
        $temp = $path . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        if (@file_put_contents($temp, $json, LOCK_EX) !== false) {
            @chmod($temp, 0660);
            @rename($temp, $path);
        }
        @unlink($temp);
    }
}
