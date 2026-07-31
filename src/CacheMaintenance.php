<?php
declare(strict_types=1);

namespace AllStarConnect;

final class CacheMaintenance
{
    private const INTERVAL_SECONDS = 3600;
    private const TEMP_MAX_AGE_SECONDS = 3600;
    private const POLICIES = [
        'stats' => [21600, 1000, 16777216],
        'echolink' => [2592000, 2000, 4194304],
    ];

    public static function run(string $root): void
    {
        $stamp = $root . '/run/cache-prune.stamp';
        if (self::isRecent($stamp, self::INTERVAL_SECONDS)) {
            return;
        }

        $lock = @fopen($root . '/run/cache-prune.lock', 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return;
        }

        try {
            clearstatcache(true, $stamp);
            if (self::isRecent($stamp, self::INTERVAL_SECONDS)) {
                return;
            }
            foreach (self::POLICIES as $name => [$maxAge, $maxFiles, $maxBytes]) {
                self::prune($root . '/cache/' . $name, $maxAge, $maxFiles, $maxBytes);
            }
            if (@touch($stamp)) {
                @chmod($stamp, 0660);
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public static function clearStats(string $root): void
    {
        foreach (glob($root . '/cache/stats/*') ?: [] as $path) {
            $name = basename($path);
            if (is_file($path) && (str_ends_with($name, '.json') || str_contains($name, '.tmp.'))) {
                @unlink($path);
            }
        }
    }

    private static function prune(string $directory, int $maxAge, int $maxFiles, int $maxBytes): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
            return;
        }

        $now = time();
        $files = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);
            $modified = @filemtime($path);
            if (str_contains($name, '.tmp.')) {
                if (!is_int($modified) || $now - $modified > self::TEMP_MAX_AGE_SECONDS) {
                    @unlink($path);
                }
                continue;
            }
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            if (!is_int($modified) || $now - $modified > $maxAge) {
                @unlink($path);
                continue;
            }
            $files[$path] = [$modified, max(0, (int) (@filesize($path) ?: 0))];
        }

        uasort($files, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $bytes = array_sum(array_column($files, 1));
        while (count($files) > $maxFiles || $bytes > $maxBytes) {
            $path = array_key_first($files);
            if ($path === null) {
                break;
            }
            $bytes -= $files[$path][1];
            @unlink($path);
            unset($files[$path]);
        }
    }

    private static function isRecent(string $path, int $seconds): bool
    {
        $modified = @filemtime($path);
        return is_int($modified) && time() - $modified < $seconds;
    }
}
