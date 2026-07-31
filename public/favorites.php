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

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$root = dirname(__DIR__);
$config = new Config($root . '/config.ini');
$auth = new AppAuth($config);
$authEnabled = $auth->isEnabled();
$authLoggedIn = $auth->isLoggedIn();
$canWrite = !$authEnabled || $authLoggedIn;
$authHttpsWarning = $authEnabled && !\AllStarConnect\Support\AppSession::isHttps();
$csrfToken = AppCsrf::token();
$repoUrl = 'https://github.com/TerryClaiborne/allstar_connect';
$remoteVersionUrl = 'https://raw.githubusercontent.com/TerryClaiborne/allstar_connect/main/VERSION';
$localVersion = is_readable($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : '0.0.0';
$assetVersion = substr((string) @hash_file('sha256', __FILE__), 0, 12) ?: $localVersion;
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favorites - AllStar Connect</title>
    <script>
        (function () {
            try {
                var savedTheme = window.localStorage.getItem('allstar_connect_theme');
                document.documentElement.setAttribute('data-theme', savedTheme === 'light' ? 'light' : 'dark');
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        }());
    </script>
    <link rel="stylesheet" href="/allstar_connect/public/assets/app-shell.css?v=<?= e($assetVersion) ?>">
    <link rel="stylesheet" href="/allstar_connect/public/assets/allstar-connect.css?v=<?= e($assetVersion) ?>">
</head>
<body>
<div class="allstar-connect-page ac-page ac-favorites-page"
     data-favorites-endpoint="/allstar_connect/api/favorites.php"
     data-identity-endpoint="/allstar_connect/api/identity.php"
     data-csrf-token="<?= e($csrfToken) ?>"
     data-can-write="<?= $canWrite ? '1' : '0' ?>">
    <header class="ac-header">
        <div class="ac-brand-group">
            <a class="ac-brand" id="branding-title" href="<?= e($repoUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open the AllStar Connect repository" data-local-version="<?= e($localVersion) ?>" data-version-url="<?= e($remoteVersionUrl) ?>" title="AllStar Connect v<?= e($localVersion) ?>">
                <svg class="ac-brand-logo" viewBox="0 0 72 72" aria-hidden="true">
                    <path class="ac-logo-star" d="M36 4 44 25 67 26 49 40 55 64 36 51 17 64 23 40 5 26 28 25Z"/>
                    <path class="ac-logo-wave ac-logo-wave-one" d="M19 31c5-6 10-6 15 0s10 6 15 0"/>
                    <path class="ac-logo-wave ac-logo-wave-two" d="M16 39c7-8 14-8 21 0s14 8 21 0"/>
                    <circle class="ac-logo-dot" cx="36" cy="35" r="3.5"/>
                </svg>
                <span class="ac-brand-name">AllStar Connect</span>
            </a>
        </div>
        <nav class="ac-nav" aria-label="Primary navigation">
            <a class="ac-nav-link" href="/allstar_connect/public/">Dashboard</a>
            <a class="ac-nav-link is-active" href="/allstar_connect/public/favorites.php">Favorites</a>
        </nav>
        <div class="ac-header-tools">
            <button type="button" class="ac-theme-toggle" id="theme-toggle" role="switch" aria-checked="false" aria-label="Toggle light and dark mode">
                <span class="ac-theme-sun" aria-hidden="true">☀</span>
                <span class="ac-theme-moon" aria-hidden="true">☾</span>
            </button>
            <div class="ac-auth">
                <span class="ac-user-icon" aria-hidden="true">◎</span>
                <span class="ac-auth-copy"><strong><?= $authLoggedIn ? e($config->getString('ALLSTAR_CONNECT_ADMIN_USER', 'admin')) : ($authEnabled ? 'View Only' : 'Normal') ?></strong><small><?= $authEnabled ? ($authLoggedIn ? 'Signed In' : 'Login required') : 'No Login' ?></small></span>
                <?php if (!$authEnabled): ?>
                    <span class="ac-auth-pill">Normal Mode</span>
                <?php elseif ($authLoggedIn): ?>
                    <a class="ac-auth-button" href="/allstar_connect/public/logout.php">Logout</a>
                <?php else: ?>
                    <a class="ac-auth-button" href="/allstar_connect/public/login.php">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <?php if ($authHttpsWarning): ?>
        <div class="ac-warning">Web login is enabled, but this page is not using HTTPS. Use HTTPS or a VPN before allowing outside access.</div>
    <?php endif; ?>

    <main class="ac-favorites-main">
        <section class="ac-card ac-favorites-manager">
            <div class="ac-card-title-row">
                <div><h1>Favorites</h1><p>AllStarLink and EchoLink · View · Load · Add · Edit · Remove</p></div>
                <strong class="ac-count" id="favorites-count">0</strong>
            </div>
            <div class="ac-favorites-toolbar">
                <label class="ac-favorites-search"><span>Search</span><input id="favorites-search" type="search" placeholder="Node, callsign, station, or description"></label>
                <button type="button" class="ac-primary-button" id="favorites-add" <?= $canWrite ? '' : 'disabled' ?>>+ Add Favorite</button>
            </div>
            <div class="ac-favorites-table-wrap">
                <table class="ac-favorites-table">
                    <thead><tr>
                        <th><button type="button" class="ac-favorites-sort-button" data-sort="target" aria-sort="ascending" title="Sort by Target"><span>Target</span><span class="ac-sort-indicator" aria-hidden="true">▲</span></button></th>
                        <th><button type="button" class="ac-favorites-sort-button" data-sort="network" aria-sort="none" title="Sort by Network"><span>Network</span><span class="ac-sort-indicator" aria-hidden="true">↕</span></button></th>
                        <th><button type="button" class="ac-favorites-sort-button" data-sort="name" aria-sort="none" title="Sort by Station Name"><span>Station Name</span><span class="ac-sort-indicator" aria-hidden="true">↕</span></button></th>
                        <th><button type="button" class="ac-favorites-sort-button" data-sort="description" aria-sort="none" title="Sort by Description"><span>Description</span><span class="ac-sort-indicator" aria-hidden="true">↕</span></button></th>
                        <th class="ac-actions-heading">Actions</th>
                    </tr></thead>
                    <tbody id="favorites-table-body"><tr><td colspan="5">Loading Favorites…</td></tr></tbody>
                </table>
            </div>
        </section>

        <section class="ac-card ac-favorites-editor-page">
            <div class="ac-card-title-row"><div><h2 id="favorite-editor-title">Add Favorite</h2><p id="favorite-editor-helper">Enter an AllStarLink or mapped EchoLink target.</p></div></div>
            <form id="favorite-editor-form" class="ac-favorite-page-form">
                <label>Network<select id="favorite-network" <?= $canWrite ? '' : 'disabled' ?>><option value="ASL">AllStarLink</option><option value="ECHO">EchoLink</option></select></label>
                <label>Target<input id="favorite-target" inputmode="numeric" placeholder="Node number" <?= $canWrite ? '' : 'disabled' ?>></label>
                <label>Station Name<input id="favorite-name" placeholder="Station name" <?= $canWrite ? '' : 'disabled' ?>></label>
                <label>Description<textarea id="favorite-description" rows="5" placeholder="Description" <?= $canWrite ? '' : 'disabled' ?>></textarea></label>
                <div class="ac-favorite-page-actions">
                    <button type="submit" class="ac-primary-button" <?= $canWrite ? '' : 'disabled' ?>>Save Favorite</button>
                    <button type="button" class="ac-secondary-button" id="favorite-editor-clear">Clear</button>
                </div>
                <div class="ac-control-status" id="favorite-page-status" role="status" aria-live="polite"><?= $canWrite ? 'Ready' : 'View only - login to make changes.' ?></div>
            </form>
        </section>
    </main>
</div>
<script src="/allstar_connect/public/assets/header.js?v=<?= e($assetVersion) ?>" defer></script>
<script src="/allstar_connect/public/assets/favorites.js?v=<?= e($assetVersion) ?>" defer></script>
</body>
</html>
