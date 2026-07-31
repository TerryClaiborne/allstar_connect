(() => {
    'use strict';

    const storageKey = 'allstar_connect_theme';
    const toggle = document.getElementById('theme-toggle');

    function applyTheme(value, persist = true) {
        const theme = value === 'light' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
        if (toggle) {
            toggle.setAttribute('aria-checked', theme === 'dark' ? 'true' : 'false');
        }
        if (persist) {
            try {
                window.localStorage.setItem(storageKey, theme);
            } catch (error) {
                // Keep the active theme when browser storage is unavailable.
            }
        }
    }

    if (toggle) {
        toggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            applyTheme(current === 'light' ? 'dark' : 'light');
        });
    }
    applyTheme(document.documentElement.getAttribute('data-theme'), false);

    function parseVersion(value) {
        const match = String(value || '').trim().match(/^v?(\d+)\.(\d+)\.(\d+)/i);
        return match ? [Number(match[1]), Number(match[2]), Number(match[3])] : null;
    }

    function newer(remote, local) {
        const left = parseVersion(remote);
        const right = parseVersion(local);
        if (!left || !right) return false;
        for (let index = 0; index < 3; index += 1) {
            if (left[index] !== right[index]) return left[index] > right[index];
        }
        return false;
    }

    async function checkForUpdate() {
        const brand = document.getElementById('branding-title');
        if (!brand) return;

        const localVersion = String(brand.dataset.localVersion || '').trim();
        const versionUrl = String(brand.dataset.versionUrl || '').trim();
        if (localVersion) {
            brand.title = `AllStar Connect v${localVersion}`;
        }
        if (!localVersion || !versionUrl) return;

        try {
            const response = await fetch(versionUrl, { method: 'GET', cache: 'no-store' });
            if (!response.ok) return;
            const remoteVersion = String(await response.text()).trim();
            if (newer(remoteVersion, localVersion)) {
                brand.classList.add('has-update');
                brand.title = `AllStar Connect v${localVersion} - update available: v${remoteVersion}`;
                brand.setAttribute('aria-label', `Open the AllStar Connect repository. Update available: version ${remoteVersion}.`);
            }
        } catch (error) {
            // The update check must never interfere with the dashboard.
        }
    }

    checkForUpdate();
})();
