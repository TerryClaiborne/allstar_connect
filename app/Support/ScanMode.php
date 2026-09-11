<?php
declare(strict_types=1);

namespace AllStarConnect\Support;

final class ScanMode
{
    public const DOWNSTREAM = 'downstream';
    public const FAVORITES = 'favorites';

    private const STATE_FILE = '/run/scan_mode.json';
    private const LOCK_FILE = '/run/scan_mode.lock';
    private const EXECUTION_LOCK_FILE = '/run/scan_execution.lock';

    public static function current(): array
    {
        $root = dirname(__DIR__, 2);
        $lockPath = $root . self::LOCK_FILE;
        $statePath = $root . self::STATE_FILE;

        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return self::defaultState();
        }

        @chmod($lockPath, 0640);

        try {
            if (!@flock($lock, LOCK_SH)) {
                return self::defaultState();
            }

            return self::readState($statePath);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public static function set(string $mode): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::DOWNSTREAM, self::FAVORITES], true)) {
            throw new \InvalidArgumentException('Invalid scanner mode.');
        }

        $root = dirname(__DIR__, 2);
        $runDir = $root . '/run';
        $lockPath = $root . self::LOCK_FILE;
        $statePath = $root . self::STATE_FILE;

        if (!is_dir($runDir)) {
            throw new \RuntimeException('Scanner runtime directory is unavailable.');
        }

        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open scanner mode lock.');
        }

        @chmod($lockPath, 0640);

        try {
            if (!@flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock scanner mode state.');
            }

            $state = self::readState($statePath);
            $changed = $state['mode'] !== $mode;

            if ($changed) {
                $state = [
                    'mode' => $mode,
                    'generation' => ((int) $state['generation']) + 1,
                    'updated_at' => gmdate('c'),
                ];
                self::writeState($statePath, $state);
            }

            return $state + ['changed' => $changed];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public static function acquireExecution(string $mode)
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::DOWNSTREAM, self::FAVORITES], true)) {
            throw new \InvalidArgumentException('Invalid scanner mode.');
        }

        $path = dirname(__DIR__, 2) . self::EXECUTION_LOCK_FILE;
        $lock = @fopen($path, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open scanner execution lock.');
        }

        @chmod($path, 0640);

        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return null;
        }

        $state = self::current();
        if (($state['mode'] ?? self::DOWNSTREAM) !== $mode) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            return null;
        }

        return $lock;
    }

    public static function releaseExecution($lock): void
    {
        if (!is_resource($lock)) {
            return;
        }

        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    private static function defaultState(): array
    {
        return [
            'mode' => self::DOWNSTREAM,
            'generation' => 0,
            'updated_at' => null,
        ];
    }

    private static function readState(string $path): array
    {
        if (!is_file($path)) {
            return self::defaultState();
        }

        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($decoded)) {
            return self::defaultState();
        }

        $mode = strtolower(trim((string) ($decoded['mode'] ?? '')));
        if (!in_array($mode, [self::DOWNSTREAM, self::FAVORITES], true)) {
            return self::defaultState();
        }

        return [
            'mode' => $mode,
            'generation' => max(0, (int) ($decoded['generation'] ?? 0)),
            'updated_at' => is_string($decoded['updated_at'] ?? null)
                ? $decoded['updated_at']
                : null,
        ];
    }

    private static function writeState(string $path, array $state): void
    {
        $json = json_encode(
            $state,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $temp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write scanner mode state.');
        }

        @chmod($temp, 0640);

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to activate scanner mode state.');
        }

        @chmod($path, 0640);
    }
}
