<?php
declare(strict_types=1);

namespace AllStarConnect\Support;

final class AppCsrf
{
    public static function token(): string
    {
        if (empty($_SESSION['allstar_connect_csrf_token']) || !is_string($_SESSION['allstar_connect_csrf_token'])) {
            $_SESSION['allstar_connect_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['allstar_connect_csrf_token'];
    }

    public static function validateRequest(): bool
    {
        $expected = (string) ($_SESSION['allstar_connect_csrf_token'] ?? '');

        if ($expected === '') {
            return false;
        }

        $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if ($provided === '') {
            $provided = (string) ($_POST['csrf_token'] ?? '');
        }

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public static function inputHtml(): string
    {
        return '<input type="hidden" name="csrf_token" value="' .
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') .
            '">';
    }
}
