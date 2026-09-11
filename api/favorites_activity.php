<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();
session_write_close();

require_once dirname(__DIR__) . '/app/Support/ScanMode.php';
require_once dirname(__DIR__) . '/src/FavoritesScanner.php';

use AllStarConnect\FavoritesScanner;
use AllStarConnect\Support\ScanMode;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$executionLock = null;

try {
    $mode = ScanMode::current();

    if (($mode['mode'] ?? ScanMode::DOWNSTREAM) !== ScanMode::FAVORITES) {
        echo json_encode([
            'ok' => true,
            'paused' => true,
            'scan_mode' => $mode,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return;
    }

    $executionLock = ScanMode::acquireExecution(ScanMode::FAVORITES);

    if ($executionLock === null) {
        echo json_encode([
            'ok' => true,
            'paused' => true,
            'scan_mode' => ScanMode::current(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return;
    }

    $startedMode = ScanMode::current();
    $generation = (int) ($startedMode['generation'] ?? 0);

    $data = (new FavoritesScanner())->snapshot($generation);

    $finishedMode = ScanMode::current();

    if (
        ($finishedMode['mode'] ?? '') !== ScanMode::FAVORITES
        || (int) ($finishedMode['generation'] ?? 0) !== $generation
    ) {
        echo json_encode([
            'ok' => true,
            'paused' => true,
            'scan_mode' => $finishedMode,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } else {
        echo json_encode([
            'ok' => true,
            'paused' => false,
            'scan_mode' => $finishedMode,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Favorites activity scanner is unavailable.',
    ], JSON_UNESCAPED_SLASHES);
} finally {
    ScanMode::releaseExecution($executionLock);
}
