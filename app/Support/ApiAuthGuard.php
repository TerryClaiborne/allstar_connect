<?php
declare(strict_types=1);

namespace AllStarConnect\Support;

require_once __DIR__ . '/AppCsrf.php';
require_once __DIR__ . '/AppAuth.php';
require_once __DIR__ . '/Config.php';

final class ApiAuthGuard
{
    public static function requireWriteAccess(?Config $config = null): void
    {
        $config = $config ?: new Config(dirname(__DIR__, 2) . '/config.ini');
        $auth = new AppAuth($config);

        if ($auth->isEnabled() && !$auth->isLoggedIn()) {
            self::fail(401, 'Login required.');
        }

        if (!AppCsrf::validateRequest()) {
            self::fail(403, 'Security check failed. Refresh the page and try again.');
        }
    }

    private static function fail(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
