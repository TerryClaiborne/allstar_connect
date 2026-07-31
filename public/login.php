<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();
require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/AppCsrf.php';
use AllStarConnect\Support\AppAuth;
use AllStarConnect\Support\AppCsrf;
use AllStarConnect\Support\Config;
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$config = new Config(dirname(__DIR__) . '/config.ini');
$auth = new AppAuth($config);
$message = '';
$error = '';
if (!$auth->isEnabled()) {
    $message = 'Web login is disabled. AllStar Connect is running in normal no-login mode.';
} elseif ($auth->isLoggedIn()) {
    $message = 'You are already signed in.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AppCsrf::validateRequest()) {
        $error = 'Security check failed. Refresh the page and try again.';
    } elseif ($auth->login($auth->adminUser(), (string) ($_POST['password'] ?? ''))) {
        header('Location: /allstar_connect/public/');
        exit;
    } else {
        $error = 'Login failed. Check the password and try again.';
    }
}
?>
<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AllStar Connect Login</title><link rel="stylesheet" href="/allstar_connect/public/assets/app-shell.css"></head><body><main class="login-page"><section class="login-card"><h1>AllStar Connect</h1><p>Optional web login</p><?php if ($error !== ''): ?><p class="login-error"><?= e($error) ?></p><?php endif; ?><?php if ($message !== ''): ?><p class="login-message"><?= e($message) ?></p><?php endif; ?><?php if ($auth->isEnabled() && !$auth->isLoggedIn()): ?><form method="post"><?= AppCsrf::inputHtml() ?><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required autofocus><div class="login-actions"><button type="submit">Login</button><a href="/allstar_connect/public/">Cancel</a></div></form><?php else: ?><div class="login-actions"><a href="/allstar_connect/public/">Return to AllStar Connect</a></div><?php endif; ?></section></main></body></html>
