<?php
declare(strict_types=1);

namespace AllStarConnect\Support;

final class AppAuth
{
    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?: new Config(dirname(__DIR__, 2) . '/config.ini');
    }

    public function adminUser(): string
    {
        $user = trim($this->config->getString('ALLSTAR_CONNECT_ADMIN_USER', 'admin'));
        return $user !== '' ? $user : 'admin';
    }

    public function isEnabled(): bool
    {
        $enabled = strtolower(trim($this->config->getString('ALLSTAR_CONNECT_AUTH_ENABLED', '0')));
        $hash = trim($this->config->getString('ALLSTAR_CONNECT_ADMIN_PASSWORD_HASH', ''));

        return in_array($enabled, ['1', 'true', 'yes', 'on'], true) && $hash !== '';
    }

    public function isLoggedIn(): bool
    {
        return !empty($_SESSION['allstar_connect_authenticated']);
    }

    public function login(string $username, string $password): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $expectedUser = strtolower($this->adminUser());
        $providedUser = strtolower(trim($username));

        if ($providedUser === '' || !hash_equals($expectedUser, $providedUser)) {
            return false;
        }

        $hash = trim($this->config->getString('ALLSTAR_CONNECT_ADMIN_PASSWORD_HASH', ''));

        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['allstar_connect_authenticated'] = true;
        $_SESSION['allstar_connect_login_time'] = time();

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['allstar_connect_authenticated'], $_SESSION['allstar_connect_login_time']);
        session_regenerate_id(true);
    }
}
