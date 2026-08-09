<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/AppSession.php';
\AllStarConnect\Support\AppSession::start();

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/AppAuth.php';
require_once dirname(__DIR__) . '/app/Support/AppCsrf.php';

use AllStarConnect\Support\AppAuth;
use AllStarConnect\Support\Config;
use AllStarConnect\Support\AppCsrf;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$root = dirname(__DIR__);
$config = new Config($root . '/config.ini');
$auth = new AppAuth($config);
$authEnabled = $auth->isEnabled();
$authLoggedIn = $auth->isLoggedIn();
$authHttpsWarning = $authEnabled && !\AllStarConnect\Support\AppSession::isHttps();
$canWrite = !$authEnabled || $authLoggedIn;
$csrfToken = AppCsrf::token();
$repoUrl = 'https://github.com/TerryClaiborne/allstar_connect';
$remoteVersionUrl = 'https://raw.githubusercontent.com/TerryClaiborne/allstar_connect/main/VERSION';
$localVersion = is_readable($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : '0.0.0';
$localVersion = $localVersion !== '' ? $localVersion : '0.0.0';
$myNode = trim($config->getString('MYNODE', ''));
$myNodeIsValid = preg_match('/^[0-9]+$/', $myNode) === 1;
$cssVersion = substr((string) @hash_file('sha256', $root . '/public/assets/allstar-connect.css'), 0, 12) ?: $localVersion;
$jsVersion = substr((string) @hash_file('sha256', $root . '/public/assets/allstar-connect.js'), 0, 12) ?: $localVersion;
$audioVersion = substr((string) @hash_file('sha256', $root . '/public/assets/audio-alerts.js'), 0, 12) ?: $localVersion;
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AllStar Connect</title>
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
    <link rel="stylesheet" href="/allstar_connect/public/assets/app-shell.css?v=<?= e($cssVersion) ?>">
    <link rel="stylesheet" href="/allstar_connect/public/assets/allstar-connect.css?v=<?= e($cssVersion) ?>">
</head>
<body>
<div class="allstar-connect-page ac-page"
     data-status-endpoint="/allstar_connect/api/local.php"
     data-downstream-endpoint="/allstar_connect/api/downstream.php"
     data-echolink-endpoint="/allstar_connect/api/echolink.php"
     data-control-endpoint="/allstar_connect/api/control.php"
     data-link-endpoint="/allstar_connect/api/link.php"
     data-favorites-endpoint="/allstar_connect/api/favorites.php"
     data-dtmf-favorites-endpoint="/allstar_connect/api/dtmf_favorites.php"
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
            <a class="ac-nav-link is-active" href="#dashboard">Dashboard</a>
            <a class="ac-nav-link" href="/allstar_connect/public/favorites.php">Favorites</a>
        </nav>

        <div class="ac-header-tools">
            <button type="button" class="ac-theme-toggle" id="theme-toggle" role="switch" aria-checked="false" aria-label="Toggle light and dark mode">
                <span class="ac-theme-sun" aria-hidden="true">☀</span>
                <span class="ac-theme-moon" aria-hidden="true">☾</span>
            </button>
            <div class="ac-auth">
                <span class="ac-user-icon" aria-hidden="true">◎</span>
                <span class="ac-auth-copy">
                    <strong><?= $authLoggedIn ? e($config->getString('ALLSTAR_CONNECT_ADMIN_USER', 'admin')) : ($authEnabled ? 'View Only' : 'Normal') ?></strong>
                    <small><?= $authEnabled ? ($authLoggedIn ? 'Signed In' : 'Login required') : 'No Login' ?></small>
                </span>
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

    <main class="ac-dashboard" id="dashboard">
        <aside class="ac-left-column">
            <section class="ac-card ac-control-card">
                <div class="ac-card-title-row">
                    <div>
                        <h2>Connect &amp; Favorites</h2>
                        <p>AllStarLink and EchoLink control</p>
                    </div>
                    <span class="ac-info-dot" title="Connect controls and saved Favorites">i</span>
                </div>

                <div class="ac-connect-section">
                    <h3>Connect</h3>
                    <p class="ac-section-note">Make a new connection</p>

                    <div class="ac-network-tabs" role="group" aria-label="Network">
                        <button type="button" class="is-active" data-network="ASL" aria-pressed="true" <?= $canWrite ? '' : 'disabled' ?>>AllStarLink</button>
                        <button type="button" data-network="ECHO" aria-pressed="false" <?= $canWrite ? '' : 'disabled' ?>>EchoLink</button>
                    </div>

                    <label class="ac-field">
                        <span>Target Node</span>
                        <span class="ac-input-with-action">
                            <input id="connect-target" type="text" inputmode="numeric" placeholder="Enter node number" <?= $canWrite ? '' : 'disabled' ?>>
                            <button type="button" class="ac-inline-star" id="connect-favorite-star" aria-label="Add manual target to Favorites" <?= $canWrite ? '' : 'disabled' ?>>☆</button>
                        </span>
                    </label>

                    <label class="ac-field">
                        <span>Link Mode</span>
                        <select id="connect-mode" <?= $canWrite ? '' : 'disabled' ?>>
                            <option value="transceive">Transceive</option>
                            <option value="local_monitor">Local Monitor</option>
                        </select>
                    </label>

                    <button type="button" class="ac-primary-button" id="connect-button" <?= $canWrite ? '' : 'disabled' ?>><span aria-hidden="true">↗</span> Connect</button>

                    <div class="ac-control-utilities">
                        <div class="ac-control-checks">
                            <label class="ac-check-control" for="disconnect_before_connect">
                                <input id="disconnect_before_connect" type="checkbox" <?= $canWrite ? '' : 'disabled' ?>>
                                <span>Disconnect before Connect</span>
                            </label>
                            <label class="ac-check-control" for="audio_alerts">
                                <input id="audio_alerts" type="checkbox" checked <?= $canWrite ? '' : 'disabled' ?>>
                                <span>Audio Alerts</span>
                            </label>
                        </div>
                        <div class="ac-dtmf-control">
                            <label for="dtmf-code">DTMF</label>
                            <input id="dtmf-code" type="text" inputmode="tel" maxlength="14" placeholder="*70 or 1234#" <?= $canWrite ? '' : 'disabled' ?>>
                            <button
                                id="dtmf-favorites-button"
                                class="ac-dtmf-favorites-button"
                                type="button"
                                aria-haspopup="dialog"
                                aria-controls="allstar-connect-dtmf-favorites-modal"
                                <?= $canWrite ? '' : 'disabled' ?>
                            >★ Favorites</button>
                            <button id="send-dtmf-button" type="button" disabled>Send</button>
                        </div>
                        <div class="ac-control-status" id="allstar-connect-control-status" role="status" aria-live="polite">Ready</div>
                    </div>
                </div>

                <div class="ac-card-divider"></div>

                <section class="ac-favorites-section" id="favorites-panel">
                    <div class="ac-subhead">
                        <h3>Favorites</h3>
                    </div>
                    <div class="ac-dashboard-favorites-head" aria-label="Sort dashboard Favorites">
                        <span class="ac-dashboard-favorites-head-spacer" aria-hidden="true"></span>
                        <button type="button" class="ac-dashboard-favorites-sort is-active" data-dashboard-favorite-sort="target" aria-sort="ascending">
                            <span>Node</span>
                            <span class="ac-dashboard-favorites-sort-indicator" aria-hidden="true">▲</span>
                        </button>
                        <button type="button" class="ac-dashboard-favorites-sort" data-dashboard-favorite-sort="station" aria-sort="none">
                            <span>Station</span>
                            <span class="ac-dashboard-favorites-sort-indicator" aria-hidden="true">↕</span>
                        </button>
                        <button type="button" class="ac-dashboard-favorites-sort" data-dashboard-favorite-sort="network" aria-sort="none">
                            <span>Network</span>
                            <span class="ac-dashboard-favorites-sort-indicator" aria-hidden="true">↕</span>
                        </button>
                    </div>
                    <div class="ac-favorites-list" id="allstar-connect-favorites">
                        <div class="ac-empty-inline">
                            <strong>No Favorites saved yet</strong>
                            <span>Use the star beside a live connection to add or edit one.</span>
                        </div>
                    </div>
                    <div class="ac-favorites-footer">
                        <a class="ac-view-all ac-manage-favorites" href="/allstar_connect/public/favorites.php">★ Manage Favorites</a>
                    </div>
                </section>
            </section>
        </aside>

        <section class="ac-center-column">
            <section class="ac-card ac-connections-card">
                <div class="ac-card-title-row ac-connections-title-row">
                    <div class="ac-title-with-count">
                        <h2>Current Connections</h2>
                        <strong class="ac-count" data-connections-count>0</strong>
                        <span class="ac-node-offline" id="allstar-connect-node-offline" role="status" aria-live="polite" hidden>Node Offline</span>
                    </div>
                    <div class="ac-system-cluster">
                        <div class="ac-system-pills ac-system-pills-left" aria-label="Live system status">
                            <span class="ac-system-pill" title="Current local time"><span>Time</span><strong id="allstar-connect-current-time">—</strong></span>
                            <span class="ac-system-pill" title="Current CPU use"><span>CPU</span><strong id="allstar-connect-system-cpu">—</strong></span>
                            <span class="ac-system-pill" title="Current memory use"><span>RAM</span><strong id="allstar-connect-system-ram">—</strong></span>
                        </div>
                        <div class="ac-connections-node-center">
                            <?php if ($myNodeIsValid): ?>
                                <a class="ac-local-node-pill" href="https://stats.allstarlink.org/stats/<?= e($myNode) ?>" target="_blank" rel="noopener noreferrer" title="Open local node <?= e($myNode) ?> on AllStarLink Stats">Node <?= e($myNode) ?></a>
                            <?php else: ?>
                                <span class="ac-local-node-pill is-disabled">Node not configured</span>
                            <?php endif; ?>
                        </div>
                        <div class="ac-system-pills ac-system-pills-right" aria-label="Live system status">
                            <span class="ac-system-pill" title="Current system temperature"><span>Temp</span><strong id="allstar-connect-system-temp">—</strong></span>
                            <span class="ac-system-pill" title="Root disk space used"><span>Disk</span><strong id="allstar-connect-system-disk">—</strong></span>
                            <span class="ac-system-pill" title="System uptime"><span>Uptime</span><strong id="allstar-connect-system-uptime">—</strong></span>
                        </div>
                    </div>
                    <div class="ac-direction-legend">
                        <span class="is-incoming">↓ Incoming</span>
                        <span class="is-outgoing">↑ Outgoing</span>
                        <button
                            type="button"
                            class="ac-panel-expand"
                            id="allstar-connect-connections-expand"
                            aria-controls="allstar-connect-connections-window"
                            aria-expanded="false"
                            title="Open a movable expanded Current Connections window"
                        ><span aria-hidden="true">&#10530;</span>Expand</button>
                    </div>
                </div>

                <div class="ac-connection-table-head" aria-hidden="true">
                    <span>Dir</span>
                    <span>Node / Callsign</span>
                    <span>Location / Name</span>
                    <span>Mode</span>
                    <span>Time</span>
                    <span>Link</span>
                    <span>Actions</span>
                </div>

                <div id="allstar-connect-connections" class="allstar-connect-connection-list ac-scroll" aria-live="polite" aria-busy="true" tabindex="0">
                    <div class="ac-empty-state">
                        <span class="ac-empty-symbol" aria-hidden="true">⇄</span>
                        <strong>Loading current connections…</strong>
                        <p>The local Asterisk snapshot is being collected.</p>
                    </div>
                </div>

                <div class="ac-action-legend">
                    <span><b>☆</b> Favorite / Add or Edit</span>
                    <span><b class="is-red">×</b> Exact Disconnect</span>
                    <span>Mode: Transceive or Local Monitor</span>
                </div>
            </section>

            <section class="ac-card ac-downstream-card" id="downstream-panel">
                <div class="ac-card-title-row">
                    <div class="ac-title-with-count">
                        <h2>Downstream Nodes</h2>
                        <strong class="ac-downstream-total"><span id="allstar-connect-downstream-count">0</span> total downstream nodes</strong>
                    </div>
                    <button
                        type="button"
                        class="ac-panel-expand"
                        id="allstar-connect-downstream-expand"
                        aria-controls="allstar-connect-downstream-window"
                        aria-expanded="false"
                        title="Open a movable expanded Downstream Nodes window"
                    ><span aria-hidden="true">&#10530;</span>Expand</button>
                </div>

                <div class="ac-downstream-toolbar">
                    <div class="ac-downstream-controls">
                        <label class="ac-compact-field">
                            <span>Branch</span>
                            <select id="allstar-connect-downstream-branch" aria-label="Choose a direct downstream branch">
                                <option value="">Automatic</option>
                            </select>
                        </label>
                        <label class="ac-downstream-search">
                            <span class="sr-only">Search downstream nodes</span>
                            <input id="allstar-connect-downstream-search" type="search" placeholder="Search nodes">
                        </label>
                    </div>
                    <div class="ac-downstream-filters" role="group" aria-label="Filter downstream connections">
                        <button type="button" class="allstar-connect-downstream-filter is-active" data-downstream-filter="all" aria-pressed="true">All <strong data-downstream-filter-count="all">0</strong></button>
                        <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="nodes" aria-pressed="false">Nodes <strong data-downstream-filter-count="nodes">0</strong></button>
                        <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="private" aria-pressed="false">Private <strong data-downstream-filter-count="private">0</strong></button>
                        <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="clients" aria-pressed="false">Clients <strong data-downstream-filter-count="clients">0</strong></button>
                        <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="echolink" aria-pressed="false">EchoLink <strong data-downstream-filter-count="echolink">0</strong></button>
                    </div>
                </div>

                <div id="allstar-connect-downstream-note" class="ac-downstream-note">Waiting for direct AllStarLink</div>
                <div id="allstar-connect-downstream" class="allstar-connect-downstream-list ac-scroll" aria-live="polite" aria-busy="true" tabindex="0">
                    <div class="ac-empty-state">
                        <span class="ac-empty-symbol" aria-hidden="true">◇</span>
                        <strong>Loading downstream data…</strong>
                        <p>The same cached scanner data is used here and in the expanded window.</p>
                    </div>
                </div>
                <div class="ac-downstream-footer">
                    <span>Direct-node groups are color-coded for easier branch tracking.</span>
                    <span>Private nodes are shown in gold.</span>
                    <button
                        type="button"
                        class="ac-mobile-downstream-open"
                        id="allstar-connect-downstream-mobile-open"
                        aria-controls="allstar-connect-downstream-mobile-sheet"
                        aria-expanded="false"
                    >View All Downstream</button>
                </div>
            </section>
        </section>

        <aside class="ac-right-column">
            <section class="ac-card ac-details-card">
                <div class="ac-card-title-row">
                    <h2>Node Details</h2>
                    <span class="ac-refresh" aria-hidden="true">↻</span>
                </div>
                <div class="ac-detail-hero">
                    <strong id="allstar-connect-detail-node">—</strong>
                    <span id="allstar-connect-detail-call">Select a node</span>
                </div>
                <dl class="ac-detail-list">
                    <div><dt>Location</dt><dd id="allstar-connect-detail-location">—</dd></div>
                    <div><dt>Node Type</dt><dd id="allstar-connect-detail-type">—</dd></div>
                    <div><dt>Direction</dt><dd id="allstar-connect-detail-direction">—</dd></div>
                    <div><dt>Link</dt><dd id="allstar-connect-detail-link">—</dd></div>
                    <div><dt>Connected To</dt><dd id="allstar-connect-detail-connected-to">—</dd></div>
                    <div><dt>Connected For</dt><dd id="allstar-connect-detail-duration">—</dd></div>
                    <div><dt>Mode</dt><dd id="allstar-connect-detail-mode">—</dd></div>
                    <div><dt>Favorite</dt><dd id="allstar-connect-detail-favorite-state">—</dd></div>
                </dl>
                <p class="ac-detail-description" id="allstar-connect-detail-description">Select a connection, downstream node, or activity entry to see its details.</p>
                <div class="ac-detail-actions" id="allstar-connect-detail-links">
                    <a id="allstar-connect-detail-qrz" class="is-disabled" aria-disabled="true" target="_blank" rel="noopener noreferrer">QRZ Page ↗</a>
                    <button type="button" class="ac-detail-load" id="allstar-connect-detail-load" title="Load this node into Connect" disabled>Load</button>
                    <button type="button" class="ac-favorite-detail" id="allstar-connect-detail-favorite" disabled>☆ Add to Favorites</button>
                </div>
                <span id="allstar-connect-detail-path" hidden>Select a row</span>
            </section>

            <section class="ac-card ac-activity-card">
                <div class="ac-card-title-row">
                    <div>
                        <h2>Live Activity</h2>
                        <p>Newest first · saved locally</p>
                    </div>
                    <button
                        type="button"
                        class="ac-panel-expand"
                        id="allstar-connect-activity-expand"
                        aria-controls="allstar-connect-activity-window"
                        aria-expanded="false"
                        title="Open a movable expanded Live Activity window"
                    ><span aria-hidden="true">&#10530;</span>Expand</button>
                </div>
                <div class="allstar-connect-activity-legend" aria-label="Live Activity event colors">
                    <span class="activity-key">Key</span>
                    <span class="activity-unkey">Unkey</span>
                    <span class="activity-connect">Connect</span>
                    <span class="activity-disconnect">Disconnect</span>
                </div>
                <div id="allstar-connect-activity" class="allstar-connect-activity-list ac-scroll" aria-live="polite" tabindex="0"></div>
                <button type="button" id="allstar-connect-activity-toggle" class="ac-activity-toggle" hidden>Show All</button>
            </section>
        </aside>
    </main>

    <section
        class="ac-mobile-downstream-sheet"
        id="allstar-connect-downstream-mobile-sheet"
        role="dialog"
        aria-modal="true"
        aria-labelledby="allstar-connect-downstream-mobile-title"
        aria-hidden="true"
        hidden
    >
        <header class="ac-mobile-downstream-sheet-header">
            <div>
                <strong id="allstar-connect-downstream-mobile-title">Downstream Nodes</strong>
                <span><span id="allstar-connect-downstream-mobile-count">0</span> total downstream nodes</span>
            </div>
            <button type="button" class="ac-mobile-downstream-close" id="allstar-connect-downstream-mobile-close" aria-label="Close Downstream Nodes">×</button>
        </header>
        <div class="ac-mobile-downstream-sheet-controls">
            <label class="ac-compact-field">
                <span>Branch</span>
                <select id="allstar-connect-downstream-mobile-branch" aria-label="Choose a direct downstream branch">
                    <option value="">Automatic</option>
                </select>
            </label>
            <label class="ac-downstream-search">
                <span class="sr-only">Search downstream nodes</span>
                <input id="allstar-connect-downstream-mobile-search" type="search" placeholder="Search nodes">
            </label>
        </div>
        <div class="allstar-connect-downstream-filters ac-mobile-downstream-sheet-filters" role="group" aria-label="Filter mobile downstream connections">
            <button type="button" class="allstar-connect-downstream-filter is-active" data-downstream-filter="all" aria-pressed="true">All <strong data-downstream-filter-count="all">0</strong></button>
            <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="nodes" aria-pressed="false">Nodes <strong data-downstream-filter-count="nodes">0</strong></button>
            <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="private" aria-pressed="false">Private <strong data-downstream-filter-count="private">0</strong></button>
            <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="clients" aria-pressed="false">Clients <strong data-downstream-filter-count="clients">0</strong></button>
            <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="echolink" aria-pressed="false">EchoLink <strong data-downstream-filter-count="echolink">0</strong></button>
        </div>
        <div id="allstar-connect-downstream-mobile" class="allstar-connect-downstream-list ac-mobile-downstream-sheet-list ac-scroll" aria-live="polite" aria-busy="true" tabindex="0">
            <div class="ac-empty-state">
                <span class="ac-empty-symbol" aria-hidden="true">◇</span>
                <strong>Loading downstream data…</strong>
                <p>The selected direct-node branch will appear here.</p>
            </div>
        </div>
    </section>

    <div class="ac-favorite-modal-backdrop" id="allstar-connect-favorite-modal" hidden aria-hidden="true">
        <section class="ac-favorite-modal-card" role="dialog" aria-modal="true" aria-labelledby="allstar-connect-favorite-title">
            <button type="button" id="allstar-connect-favorite-close" class="ac-favorite-modal-close" aria-label="Close Favorite window">×</button>
            <h2 id="allstar-connect-favorite-title">Add Favorite</h2>
            <p id="allstar-connect-favorite-helper" class="ac-favorite-modal-help">The selected AllStarLink or EchoLink identity will be filled automatically. Change any details before saving.</p>
            <div class="ac-favorite-modal-summary">
                <label>Network<input id="allstar-connect-favorite-network" readonly placeholder="—"></label>
                <label>Target<input id="allstar-connect-favorite-target" readonly placeholder="—"></label>
            </div>
            <div class="ac-favorite-dialog">
                <label>Callsign / Station Name<input id="allstar-connect-favorite-name" maxlength="96" autocomplete="off" placeholder="Callsign or station name"></label>
                <label>Description<textarea id="allstar-connect-favorite-description" rows="3" maxlength="180" placeholder="Description"></textarea></label>
                <div class="ac-favorite-dialog-actions">
                    <button type="button" class="ac-secondary-button" id="allstar-connect-favorite-cancel">Cancel</button>
                    <button type="button" class="ac-primary-button" id="allstar-connect-favorite-save" <?= $canWrite ? '' : 'disabled' ?>>Save Favorite</button>
                </div>
            </div>
        </section>
    </div>


    <div class="ac-favorite-modal-backdrop ac-dtmf-favorites-modal" id="allstar-connect-dtmf-favorites-modal" hidden aria-hidden="true">
        <section class="ac-favorite-modal-card ac-dtmf-favorites-card" role="dialog" aria-modal="true" aria-labelledby="allstar-connect-dtmf-favorites-title">
            <button type="button" id="allstar-connect-dtmf-favorites-close" class="ac-favorite-modal-close" aria-label="Close DTMF Favorites">×</button>
            <h2 id="allstar-connect-dtmf-favorites-title">DTMF Favorites</h2>
            <p class="ac-favorite-modal-help">Choose a saved command to place it in the DTMF line. Nothing is sent until you press Send.</p>

            <div id="allstar-connect-dtmf-favorites-list" class="ac-dtmf-favorites-list"></div>

            <div class="ac-dtmf-favorite-editor">
                <div class="ac-dtmf-favorite-fields">
                    <label>
                        Name
                        <input id="allstar-connect-dtmf-favorite-name" type="text" maxlength="64" autocomplete="off" placeholder="Example: Disconnect All">
                    </label>
                    <label>
                        DTMF Command
                        <input id="allstar-connect-dtmf-favorite-code" type="text" inputmode="tel" maxlength="14" autocomplete="off" placeholder="*70">
                    </label>
                </div>
                <div class="ac-favorite-dialog-actions">
                    <button type="button" class="ac-secondary-button" id="allstar-connect-dtmf-favorite-clear">Clear</button>
                    <button type="button" class="ac-primary-button" id="allstar-connect-dtmf-favorite-save" <?= $canWrite ? '' : 'disabled' ?>>Add Favorite</button>
                </div>
            </div>
        </section>
    </div>

    <section class="allstar-connect-floating-window allstar-connect-connections-window" id="allstar-connect-connections-window" data-floating-window="connections" role="dialog" aria-modal="false" aria-labelledby="allstar-connect-connections-window-title" tabindex="-1" hidden>
        <div class="allstar-connect-floating-window-header" id="allstar-connect-connections-window-handle">
            <div class="allstar-connect-floating-window-heading"><strong id="allstar-connect-connections-window-title">Current Connections <span data-connections-count>0</span></strong><span>Local connections · same live data</span></div>
            <span class="allstar-connect-floating-window-move-cue" role="img" aria-label="Hold down the mouse button to drag and move the window" title="Hold down the mouse button to drag and move the window"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M2 12h20M8.5 5.5 12 2l3.5 3.5M8.5 18.5 12 22l3.5-3.5M5.5 8.5 2 12l3.5 3.5M18.5 8.5 22 12l-3.5 3.5"/></svg></span>
            <button type="button" class="allstar-connect-floating-window-close" id="allstar-connect-connections-window-close" aria-label="Close expanded Current Connections window">×</button>
        </div>
        <div class="allstar-connect-floating-window-body">
            <div class="ac-connection-table-head ac-floating-table-head" aria-hidden="true"><span>Dir</span><span>Node / Callsign</span><span>Location / Name</span><span>Mode</span><span>Time</span><span>Link</span><span>Actions</span></div>
            <div id="allstar-connect-connections-expanded" class="allstar-connect-connection-list allstar-connect-floating-window-list ac-scroll" aria-live="polite" aria-busy="true" tabindex="0"></div>
            <div class="allstar-connect-floating-window-hint">Click any row to update Node Details. Use the lower-right corner to resize.</div>
        </div>
    </section>

    <section class="allstar-connect-floating-window allstar-connect-downstream-window" id="allstar-connect-downstream-window" data-floating-window="downstream" role="dialog" aria-modal="false" aria-labelledby="allstar-connect-downstream-window-title" tabindex="-1" hidden>
        <div class="allstar-connect-floating-window-header" id="allstar-connect-downstream-window-handle">
            <div class="allstar-connect-floating-window-heading"><strong id="allstar-connect-downstream-window-title">Downstream Nodes</strong><span>Direct-node groups · Color-coded flow</span></div>
            <span class="allstar-connect-floating-window-move-cue" role="img" aria-label="Hold down the mouse button to drag and move the window" title="Hold down the mouse button to drag and move the window"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M2 12h20M8.5 5.5 12 2l3.5 3.5M8.5 18.5 12 22l3.5-3.5M5.5 8.5 2 12l3.5 3.5M18.5 8.5 22 12l-3.5 3.5"/></svg></span>
            <button type="button" class="allstar-connect-floating-window-close" id="allstar-connect-downstream-window-close" aria-label="Close expanded Downstream Nodes window">×</button>
        </div>
        <div class="allstar-connect-floating-window-body">
            <div class="allstar-connect-downstream-filters allstar-connect-downstream-window-filters" role="group" aria-label="Filter expanded downstream connections">
                <button type="button" class="allstar-connect-downstream-filter is-active" data-downstream-filter="all" aria-pressed="true">All <strong data-downstream-filter-count="all">0</strong></button>
                <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="nodes" aria-pressed="false">Nodes <strong data-downstream-filter-count="nodes">0</strong></button>
                <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="private" aria-pressed="false">Private <strong data-downstream-filter-count="private">0</strong></button>
                <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="clients" aria-pressed="false">Clients <strong data-downstream-filter-count="clients">0</strong></button>
                <button type="button" class="allstar-connect-downstream-filter" data-downstream-filter="echolink" aria-pressed="false">EchoLink <strong data-downstream-filter-count="echolink">0</strong></button>
            </div>
            <div id="allstar-connect-downstream-expanded" class="allstar-connect-downstream-list allstar-connect-floating-window-list ac-scroll" aria-live="polite" aria-busy="true" tabindex="0"></div>
            <div class="allstar-connect-floating-window-hint">The expanded window shows all filtered branches using the same cached downstream data. Use the lower-right corner to resize.</div>
        </div>
    </section>

    <section class="allstar-connect-floating-window allstar-connect-activity-window" id="allstar-connect-activity-window" data-floating-window="activity" role="dialog" aria-modal="false" aria-labelledby="allstar-connect-activity-window-title" tabindex="-1" hidden>
        <div class="allstar-connect-floating-window-header" id="allstar-connect-activity-window-handle">
            <div class="allstar-connect-floating-window-heading"><strong id="allstar-connect-activity-window-title">Live Activity</strong><span>Newest first · saved locally</span></div>
            <span class="allstar-connect-floating-window-move-cue" role="img" aria-label="Hold down the mouse button to drag and move the window" title="Hold down the mouse button to drag and move the window"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M2 12h20M8.5 5.5 12 2l3.5 3.5M8.5 18.5 12 22l3.5-3.5M5.5 8.5 2 12l3.5 3.5M18.5 8.5 22 12l-3.5 3.5"/></svg></span>
            <button type="button" class="allstar-connect-floating-window-close" id="allstar-connect-activity-window-close" aria-label="Close expanded Live Activity window">×</button>
        </div>
        <div class="allstar-connect-floating-window-body">
            <div class="allstar-connect-activity-legend allstar-connect-floating-window-legend"><span class="activity-key">Key</span><span class="activity-unkey">Unkey</span><span class="activity-connect">Connect</span><span class="activity-disconnect">Disconnect</span></div>
            <div id="allstar-connect-activity-expanded" class="allstar-connect-activity-list allstar-connect-floating-window-list ac-scroll" aria-live="polite" tabindex="0"></div>
            <div class="allstar-connect-floating-window-hint">Click any row to update Node Details. Use the lower-right corner to resize.</div>
        </div>
    </section>
</div>
<script src="/allstar_connect/public/assets/header.js"></script>
<script src="/allstar_connect/public/assets/audio-alerts.js?v=<?= e($audioVersion) ?>"></script>
<script src="/allstar_connect/public/assets/allstar-connect.js?v=<?= e($jsVersion) ?>"></script>
</body>
</html>
