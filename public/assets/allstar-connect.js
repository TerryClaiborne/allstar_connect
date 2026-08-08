(() => {
    'use strict';

    const page = document.querySelector('.allstar-connect-page');
    if (!page) {
        return;
    }

    const localEndpoint = String(page.dataset.statusEndpoint || '').trim();
    const downstreamEndpoint = String(page.dataset.downstreamEndpoint || '').trim();
    const echoLinkEndpoint = String(page.dataset.echolinkEndpoint || '').trim();
    const controlEndpoint = String(page.dataset.controlEndpoint || '').trim();
    const linkEndpoint = String(page.dataset.linkEndpoint || '').trim();
    const favoritesEndpoint = String(page.dataset.favoritesEndpoint || '').trim();
    const identityEndpoint = String(page.dataset.identityEndpoint || '').trim();
    const csrfToken = String(page.dataset.csrfToken || '').trim();
    const canWrite = String(page.dataset.canWrite || '') === '1';
    const mobileActivityMedia = window.matchMedia('(max-width: 760px)');
    const mobileDownstreamMedia = window.matchMedia('(max-width: 820px)');
    const desktopFloatingMedia = window.matchMedia('(min-width: 761px)');
    const MOBILE_ACTIVITY_LIMIT = 8;
    let dashboardFavoritesSortKey = 'target';
    let dashboardFavoritesSortDirection = 'asc';
    if (!localEndpoint) {
        return;
    }

    const elements = {
        connections: document.getElementById('allstar-connect-connections'),
        connectionsExpanded: document.getElementById('allstar-connect-connections-expanded'),
        connectionsCounts: Array.from(document.querySelectorAll('[data-connections-count]')),
        connectionsWindow: document.getElementById('allstar-connect-connections-window'),
        connectionsWindowHandle: document.getElementById('allstar-connect-connections-window-handle'),
        connectionsWindowClose: document.getElementById('allstar-connect-connections-window-close'),
        connectionsExpand: document.getElementById('allstar-connect-connections-expand'),
        downstream: document.getElementById('allstar-connect-downstream'),
        downstreamExpanded: document.getElementById('allstar-connect-downstream-expanded'),
        downstreamWindow: document.getElementById('allstar-connect-downstream-window'),
        downstreamWindowHandle: document.getElementById('allstar-connect-downstream-window-handle'),
        downstreamWindowClose: document.getElementById('allstar-connect-downstream-window-close'),
        downstreamExpand: document.getElementById('allstar-connect-downstream-expand'),
        downstreamCount: document.getElementById('allstar-connect-downstream-count'),
        downstreamNote: document.getElementById('allstar-connect-downstream-note'),
        downstreamBranch: document.getElementById('allstar-connect-downstream-branch'),
        downstreamSearch: document.getElementById('allstar-connect-downstream-search'),
        downstreamMobile: document.getElementById('allstar-connect-downstream-mobile'),
        downstreamMobileSheet: document.getElementById('allstar-connect-downstream-mobile-sheet'),
        downstreamMobileOpen: document.getElementById('allstar-connect-downstream-mobile-open'),
        downstreamMobileClose: document.getElementById('allstar-connect-downstream-mobile-close'),
        downstreamMobileBranch: document.getElementById('allstar-connect-downstream-mobile-branch'),
        downstreamMobileSearch: document.getElementById('allstar-connect-downstream-mobile-search'),
        downstreamMobileCount: document.getElementById('allstar-connect-downstream-mobile-count'),
        downstreamFilters: Array.from(document.querySelectorAll('[data-downstream-filter]')),
        downstreamFilterCounts: {
            all: Array.from(document.querySelectorAll('[data-downstream-filter-count="all"]')),
            nodes: Array.from(document.querySelectorAll('[data-downstream-filter-count="nodes"]')),
            private: Array.from(document.querySelectorAll('[data-downstream-filter-count="private"]')),
            clients: Array.from(document.querySelectorAll('[data-downstream-filter-count="clients"]')),
            echolink: Array.from(document.querySelectorAll('[data-downstream-filter-count="echolink"]')),
        },
        currentTime: document.getElementById('allstar-connect-current-time'),
        systemCpu: document.getElementById('allstar-connect-system-cpu'),
        systemRam: document.getElementById('allstar-connect-system-ram'),
        systemTemp: document.getElementById('allstar-connect-system-temp'),
        systemUptime: document.getElementById('allstar-connect-system-uptime'),
        systemDisk: document.getElementById('allstar-connect-system-disk'),
        detailNode: document.getElementById('allstar-connect-detail-node'),
        detailCall: document.getElementById('allstar-connect-detail-call'),
        detailPath: document.getElementById('allstar-connect-detail-path'),
        detailLocation: document.getElementById('allstar-connect-detail-location'),
        detailDescription: document.getElementById('allstar-connect-detail-description'),
        detailType: document.getElementById('allstar-connect-detail-type'),
        detailDirection: document.getElementById('allstar-connect-detail-direction'),
        detailLink: document.getElementById('allstar-connect-detail-link'),
        detailConnectedTo: document.getElementById('allstar-connect-detail-connected-to'),
        detailDuration: document.getElementById('allstar-connect-detail-duration'),
        detailMode: document.getElementById('allstar-connect-detail-mode'),
        detailFavoriteState: document.getElementById('allstar-connect-detail-favorite-state'),
        detailLinks: document.getElementById('allstar-connect-detail-links'),
        detailQrz: document.getElementById('allstar-connect-detail-qrz'),
        detailLoad: document.getElementById('allstar-connect-detail-load'),
        detailFavorite: document.getElementById('allstar-connect-detail-favorite'),
        activity: document.getElementById('allstar-connect-activity'),
        activityExpanded: document.getElementById('allstar-connect-activity-expanded'),
        activityToggle: document.getElementById('allstar-connect-activity-toggle'),
        activityWindow: document.getElementById('allstar-connect-activity-window'),
        activityWindowHandle: document.getElementById('allstar-connect-activity-window-handle'),
        activityWindowClose: document.getElementById('allstar-connect-activity-window-close'),
        activityExpand: document.getElementById('allstar-connect-activity-expand'),
        disconnectBeforeConnect: document.getElementById('disconnect_before_connect'),
        dtmfCode: document.getElementById('dtmf-code'),
        dtmfSend: document.getElementById('send-dtmf-button'),
        controlStatus: document.getElementById('allstar-connect-control-status'),
        networkTabs: Array.from(document.querySelectorAll('[data-network]')),
        connectTarget: document.getElementById('connect-target'),
        connectMode: document.getElementById('connect-mode'),
        connectButton: document.getElementById('connect-button'),
        connectFavoriteStar: document.getElementById('connect-favorite-star'),
        favoritesList: document.getElementById('allstar-connect-favorites'),
        favoriteSortButtons: Array.from(document.querySelectorAll('[data-dashboard-favorite-sort]')),
        favoriteModal: document.getElementById('allstar-connect-favorite-modal'),
        favoriteTitle: document.getElementById('allstar-connect-favorite-title'),
        favoriteSave: document.getElementById('allstar-connect-favorite-save'),
        favoriteNetwork: document.getElementById('allstar-connect-favorite-network'),
        favoriteTarget: document.getElementById('allstar-connect-favorite-target'),
        favoriteName: document.getElementById('allstar-connect-favorite-name'),
        favoriteDescription: document.getElementById('allstar-connect-favorite-description'),
        favoriteHelper: document.getElementById('allstar-connect-favorite-helper'),
    };

    const state = {
        localTimer: 0,
        downstreamTimer: 0,
        clockTimer: 0,
        localLoading: false,
        localSnapshotLoaded: false,
        localController: null,
        localFailureCount: 0,
        lastLocalPollTick: Date.now(),
        downstreamLoading: false,
        downstreamController: null,
        echoLinkLoading: false,
        echoLinkTimer: 0,
        echoLinkNextAllowed: 0,
        selectedKey: '',
        selectedType: '',
        preferredDirectNode: '',
        preferredRemoteClients: false,
        downstreamFilter: 'all',
        downstreamSearch: '',
        localNode: '',
        scrollDownstreamOnRender: false,
        downstreamHighlightTimer: 0,
        connections: [],
        activity: [],
        activityRenderSignature: '',
        activityExpanded: false,
        downstreamRenderSignature: '',
        downstreamNodes: [],
        downstreamDirect: [],
        downstreamSummary: {},
        downstreamCache: {},
        echoLinkEntries: {},
        selectedNetwork: 'ASL',
        favorites: [],
        pendingActions: new Set(),
        pendingDisconnectUntil: new Map(),
        completedDisconnects: new Set(),
        connectionActionPointerActive: false,
        favoriteLookupTimer: 0,
        favoriteLookupController: null,
        loadIdentityCache: new Map(),
        loadIdentityRequests: new Map(),
        favoriteEditorKey: '',
        favoriteEditorDirty: false,
    };


    const DISCONNECT_BEFORE_CONNECT_KEY = 'allstar_connect_disconnect_before_connect';

    function setControlStatus(message, isError = false) {
        if (!elements.controlStatus) return;
        elements.controlStatus.textContent = String(message || 'Ready');
        elements.controlStatus.classList.toggle('is-error', Boolean(isError));
    }

    function loadDisconnectBeforeConnect() {
        if (!elements.disconnectBeforeConnect) return;
        let enabled = false;
        try { enabled = window.localStorage.getItem(DISCONNECT_BEFORE_CONNECT_KEY) === '1'; } catch (error) {}
        elements.disconnectBeforeConnect.checked = enabled;
        elements.disconnectBeforeConnect.addEventListener('change', () => {
            try {
                window.localStorage.setItem(DISCONNECT_BEFORE_CONNECT_KEY, elements.disconnectBeforeConnect.checked ? '1' : '0');
            } catch (error) {}
        });
    }

    function sanitizeDtmf(value) {
        return String(value || '').replace(/[^0-9*#]/g, '').slice(0, 14);
    }

    function syncDtmfControl() {
        if (!elements.dtmfCode || !elements.dtmfSend) return;
        const clean = sanitizeDtmf(elements.dtmfCode.value);
        if (elements.dtmfCode.value !== clean) elements.dtmfCode.value = clean;
        elements.dtmfSend.disabled = !canWrite || !controlEndpoint || clean === '';
    }

    async function sendDtmf() {
        if (!elements.dtmfCode || !elements.dtmfSend || elements.dtmfSend.disabled) return;
        const code = sanitizeDtmf(elements.dtmfCode.value);
        if (!code) return;
        elements.dtmfSend.disabled = true;
        setControlStatus(`Sending DTMF ${code}…`);
        try {
            const response = await fetch(controlEndpoint, {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ action: 'send_dtmf', dtmf_code: code }),
            });
            const payload = await response.json();
            if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'DTMF send failed.');
            elements.dtmfCode.value = '';
            setControlStatus(`DTMF sent: ${code}`);
        } catch (error) {
            setControlStatus(error?.message || 'DTMF send failed.', true);
        } finally {
            syncDtmfControl();
        }
    }

    loadDisconnectBeforeConnect();
    if (elements.dtmfCode) {
        elements.dtmfCode.addEventListener('input', syncDtmfControl);
        elements.dtmfCode.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); sendDtmf(); }
        });
    }
    elements.dtmfSend?.addEventListener('click', sendDtmf);
    syncDtmfControl();

    function normalizeNetworkCode(value) {
        const normalized = String(value || '').trim().toUpperCase();
        return ['ECHO', 'ECHOLINK', 'E/L'].includes(normalized) ? 'ECHO' : 'ASL';
    }

    function networkForTarget(network, target) {
        const cleanTarget = String(target || '').replace(/\D/g, '');
        return /^3\d{6}$/.test(cleanTarget) ? 'ECHO' : normalizeNetworkCode(network);
    }

    function cleanEchoLinkEntry(value) {
        return String(value || '')
            .trim()
            .toUpperCase()
            .replace(/\s+/g, '')
            .replace(/[^A-Z0-9*_.\/-]/g, '');
    }

    function mappedEchoLinkTarget(value) {
        const entry = cleanEchoLinkEntry(value);
        if (/^3\d{6}$/.test(entry) && entry !== '3000000') return entry;
        if (/^\d{1,6}$/.test(entry) && Number(entry) > 0) {
            return `3${entry.padStart(6, '0')}`;
        }
        return '';
    }

    function validEchoLinkIdentifier(value) {
        const entry = cleanEchoLinkEntry(value);
        return mappedEchoLinkTarget(entry) !== ''
            || (
                entry.length <= 32
                && /^[A-Z0-9*_.\/-]+$/.test(entry)
                && /[A-Z*]/.test(entry)
            );
    }

    function cleanConnectEntry(value, network = state.selectedNetwork) {
        return normalizeNetworkCode(network) === 'ECHO'
            ? cleanEchoLinkEntry(value)
            : String(value || '').replace(/\D/g, '');
    }

    function networkDisplay(value) {
        return normalizeNetworkCode(value) === 'ECHO' ? 'EchoLink' : 'AllStarLink';
    }

    function favoriteTargetForItem(item) {
        if (!item || Boolean(item.is_private)) return '';

        if (String(item.kind || '') === 'echo') {
            /*
             * A known numeric EchoLink node is saveable even when its
             * callsign lookup has not completed. Verified identity still
             * takes priority, but it is not required for Favorite eligibility.
             */
            const official = echoLinkNodeNumber(
                item.echolink_node
                || item.reported_node
                || item.node
            );

            return official !== '' && official !== '0'
                ? `3${official.padStart(6, '0')}`
                : '';
        }

        return String(item.node || '').replace(/\D/g, '');
    }

    function favoriteNetworkForItem(item) {
        return String(item?.kind || '') === 'echo' ? 'ECHO' : 'ASL';
    }

    function connectionUsesTarget(network, target) {
        const networkCode = normalizeNetworkCode(network);
        const cleanTarget = String(target || '').replace(/\D/g, '');
        if (!cleanTarget) return false;

        return state.connections.some((connection) => {
            const kind = String(connection?.kind || '');
            if (networkCode === 'ASL') {
                return kind === 'asl'
                    && String(connection.node || '').replace(/\D/g, '') === cleanTarget;
            }

            if (kind !== 'echo' || !Boolean(connection.identity_verified)) {
                return false;
            }
            const officialNode = echoLinkNodeNumber(connection.echolink_node || connection.node);
            const connectionTarget = officialNode ? `3${officialNode.padStart(6, '0')}` : '';
            return connectionTarget === cleanTarget;
        });
    }

    function connectTargetCandidateForItem(item) {
        if (!item || state.selectedType === 'current' || Boolean(item.is_private)) {
            return null;
        }

        const kind = String(item.kind || '');
        if (kind === 'asl') {
            const target = String(item.node || '').replace(/\D/g, '');
            if (
                !/^\d{1,7}$/.test(target)
                || /^1\d{3}$/.test(target)
                || target === String(state.localNode || '')
                || connectionUsesTarget('ASL', target)
            ) {
                return null;
            }
            return { network: 'ASL', target };
        }

        if (kind === 'echo' && Boolean(item.identity_verified)) {
            const officialNode = echoLinkNodeNumber(item.echolink_node || item.node);
            const target = officialNode ? `3${officialNode.padStart(6, '0')}` : '';
            return /^3\d{6}$/.test(target)
                && target !== '3000000'
                && !connectionUsesTarget('ECHO', target)
                ? { network: 'ECHO', target }
                : null;
        }

        return null;
    }

    function connectTargetForItem(item) {
        const candidate = connectTargetCandidateForItem(item);
        if (!candidate) return null;
        if (candidate.network === 'ASL' && state.loadIdentityCache.get(candidate.target) !== true) {
            return null;
        }
        return candidate;
    }

    async function verifyAllStarLoadTarget(target) {
        const node = String(target || '').replace(/\D/g, '');
        if (!/^\d{1,7}$/.test(node) || /^1\d{3}$/.test(node) || !identityEndpoint) {
            return false;
        }
        if (state.loadIdentityCache.has(node)) {
            return state.loadIdentityCache.get(node) === true;
        }
        if (state.loadIdentityRequests.has(node)) {
            return state.loadIdentityRequests.get(node);
        }

        const request = (async () => {
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 4000);
            try {
                const url = new URL(identityEndpoint, window.location.href);
                url.searchParams.set('network', 'ASL');
                url.searchParams.set('target', node);
                url.searchParams.set('_', String(Date.now()));
                const response = await fetch(url.toString(), {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const payload = await response.json().catch(() => ({}));
                const identity = payload?.identity && typeof payload.identity === 'object'
                    ? payload.identity
                    : null;
                const valid = Boolean(
                    response.ok
                    && payload?.ok
                    && identity?.found === true
                    && normalizeNetworkCode(identity.network) === 'ASL'
                    && String(identity.target || '').replace(/\D/g, '') === node
                );
                state.loadIdentityCache.set(node, valid);
                return valid;
            } catch (error) {
                state.loadIdentityCache.set(node, false);
                return false;
            } finally {
                window.clearTimeout(timeout);
                state.loadIdentityRequests.delete(node);
            }
        })();

        state.loadIdentityRequests.set(node, request);
        return request;
    }

    function refreshLoadEligibility(item) {
        const candidate = connectTargetCandidateForItem(item);
        if (!candidate || candidate.network !== 'ASL' || state.loadIdentityCache.has(candidate.target)) {
            return;
        }

        const selectedKey = state.selectedKey;
        const selectedType = state.selectedType;
        verifyAllStarLoadTarget(candidate.target).then(() => {
            if (state.selectedKey === selectedKey && state.selectedType === selectedType) {
                renderDetails(selectedItem());
            }
        });
    }

    function favoriteKey(network, target) {
        return `${normalizeNetworkCode(network)}:${String(target || '').trim()}`;
    }

    function favoriteFor(network, target) {
        const key = favoriteKey(network, target);
        return state.favorites.find((item) => favoriteKey(item.network || item.mode, item.target) === key) || null;
    }


    function validFavoriteTarget(network, target) {
        return normalizeNetworkCode(network) === 'ECHO'
            ? /^3\d{6}$/.test(String(target || ''))
            : /^\d{1,7}$/.test(String(target || ''));
    }

    function identityDescription(identity) {
        const parts = [identity?.description, identity?.location]
            .map((value) => String(value || '').trim())
            .filter((value, index, values) => value !== '' && values.indexOf(value) === index);
        return parts.join(' — ');
    }

    function setFavoriteHelper(message, isError = false) {
        if (!elements.favoriteHelper) return;
        elements.favoriteHelper.textContent = String(message || '');
        elements.favoriteHelper.classList.toggle('is-error', Boolean(isError));
    }

    function activateFavoriteEditor(focusName = false) {
        const editor = elements.favoriteModal;
        if (!editor) return;
        editor.hidden = false;
        editor.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ac-modal-open');
        if (focusName) {
            window.requestAnimationFrame(() => {
                elements.favoriteName?.focus({ preventScroll: true });
                elements.favoriteName?.select();
            });
        }
    }

    async function lookupTargetIdentity(network, target) {
        if (!identityEndpoint) return null;
        state.favoriteLookupController?.abort();
        const controller = new AbortController();
        state.favoriteLookupController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 4000);
        try {
            const url = new URL(identityEndpoint, window.location.href);
            url.searchParams.set('network', normalizeNetworkCode(network));
            url.searchParams.set('target', String(target || ''));
            url.searchParams.set('_', String(Date.now()));
            const response = await fetch(url.toString(), {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Node identity lookup failed.');
            return payload.identity && typeof payload.identity === 'object' ? payload.identity : null;
        } finally {
            window.clearTimeout(timeout);
            if (state.favoriteLookupController === controller) state.favoriteLookupController = null;
        }
    }

    async function resolveEchoLinkConnectTarget(value) {
        const entry = cleanEchoLinkEntry(value);
        const mapped = mappedEchoLinkTarget(entry);

        if (mapped !== '') {
            if (elements.connectTarget) elements.connectTarget.value = mapped;
            applySelectedNetwork('ECHO', mapped, false);
            scheduleManualFavoritePrefill(50);
            return mapped;
        }

        if (!validEchoLinkIdentifier(entry)) {
            throw new Error('Enter an EchoLink node number or callsign.');
        }

        setControlStatus(`Looking up EchoLink ${entry}…`);
        const identity = await lookupTargetIdentity('ECHO', entry);
        const resolved = String(identity?.target || '').replace(/\D/g, '');
        const officialNode = String(identity?.official_node || '').replace(/\D/g, '');
        const callsign = String(identity?.callsign || entry).trim().toUpperCase();

        if (!/^3\d{6}$/.test(resolved) || resolved === '3000000' || !/^\d{1,6}$/.test(officialNode)) {
            throw new Error('That EchoLink callsign could not be resolved to a node number.');
        }

        const resolvedEntry = {
            node: officialNode,
            callsign,
            found: true,
            checked_at: new Date().toISOString(),
        };

        state.echoLinkEntries[officialNode] = resolvedEntry;
        const callsignKey = echoLinkCallsignKey(callsign);
        if (callsignKey) {
            state.echoLinkEntries[callsignKey] = resolvedEntry;
        }

        if (elements.connectTarget) elements.connectTarget.value = resolved;
        applySelectedNetwork('ECHO', resolved, false);
        scheduleManualFavoritePrefill(50);
        return resolved;
    }

    function normalizeEchoLinkNumericInput() {
        if (state.selectedNetwork !== 'ECHO' || !elements.connectTarget) return false;
        const mapped = mappedEchoLinkTarget(elements.connectTarget.value);
        if (mapped === '' || elements.connectTarget.value === mapped) return false;
        elements.connectTarget.value = mapped;
        syncConnectControls();
        scheduleManualFavoritePrefill(50);
        return true;
    }

    async function prefillFavoriteEditor(network, target, item = null, options = {}) {
        const cleanTarget = String(target || '').replace(/\D/g, '');
        const networkCode = networkForTarget(network, cleanTarget);
        if (!validFavoriteTarget(networkCode, cleanTarget)) return;

        const key = favoriteKey(networkCode, cleanTarget);
        const changedTarget = state.favoriteEditorKey !== key;
        if (changedTarget) {
            state.favoriteEditorKey = key;
            state.favoriteEditorDirty = false;
        }

        if (elements.favoriteNetwork) elements.favoriteNetwork.value = networkDisplay(networkCode);
        if (elements.favoriteTarget) elements.favoriteTarget.value = cleanTarget;
        if (options.open) activateFavoriteEditor(Boolean(options.focus));

        const existing = favoriteFor(networkCode, cleanTarget);
        if (existing) {
            if (elements.favoriteTitle) elements.favoriteTitle.textContent = 'Edit Favorite';
            if (elements.favoriteSave) elements.favoriteSave.textContent = 'Update Favorite';
            if (elements.favoriteName) elements.favoriteName.value = String(existing.name || cleanTarget);
            if (elements.favoriteDescription) elements.favoriteDescription.value = String(existing.description || '');
            setFavoriteHelper('This target is already saved. Change the details or leave them as shown, then update it.');
            return;
        }

        if (elements.favoriteTitle) elements.favoriteTitle.textContent = 'Add Favorite';
        if (elements.favoriteSave) elements.favoriteSave.textContent = 'Save Favorite';

        const itemName = String(item?.callsign || '').trim();
        const rawItemDescription = [item?.description, item?.location]
            .map((value) => String(value || '').trim())
            .filter((value, index, values) => value !== '' && values.indexOf(value) === index)
            .join(' — ');

        let itemDescription = rawItemDescription;

        if (networkCode === 'ECHO' && itemName === '') {
            const echoNode = echoLinkNodeNumber(
                item?.echolink_node
                || item?.reported_node
                || item?.node
                || cleanTarget
            );

            itemDescription = echoNode !== ''
                ? `EchoLink node ${echoNode}`
                : '';
        }

        if (!state.favoriteEditorDirty) {
            /*
             * Unknown identity stays blank for manual entry. Never use the
             * numeric target as the Callsign / Station Name.
             */
            if (elements.favoriteName) elements.favoriteName.value = itemName;
            if (elements.favoriteDescription) elements.favoriteDescription.value = itemDescription;
        }

        setFavoriteHelper(`Looking up ${networkDisplay(networkCode)} ${cleanTarget}…`);
        try {
            const identity = await lookupTargetIdentity(networkCode, cleanTarget);
            if (state.favoriteEditorKey !== key) return;
            if (!state.favoriteEditorDirty) {
                const identityName = String(identity?.callsign || '').trim();
                const description = identityDescription(identity);
                if (elements.favoriteName) elements.favoriteName.value = identityName || itemName;
                if (elements.favoriteDescription) elements.favoriteDescription.value = description || itemDescription;
            }
            setFavoriteHelper(identity?.found
                ? `${networkDisplay(networkCode)} details were detected. Change anything you want, then save.`
                : `${networkDisplay(networkCode)} database details were not found. Enter the station name and description manually.`);
        } catch (error) {
            if (error?.name === 'AbortError' || state.favoriteEditorKey !== key) return;
            setFavoriteHelper(error?.message || 'Node identity lookup failed. Enter details manually.', true);
        }
    }

    function scheduleManualFavoritePrefill(delay = 280) {
        window.clearTimeout(state.favoriteLookupTimer);
        const target = String(elements.connectTarget?.value || '').replace(/\D/g, '');
        const network = networkForTarget(state.selectedNetwork, target);
        if (!validFavoriteTarget(network, target)) {
            return;
        }
        state.favoriteLookupTimer = window.setTimeout(() => {
            prefillFavoriteEditor(network, target, null, { open: false, focus: false });
        }, delay);
    }

    function dashboardFavoriteSortValue(item, key) {
        if (key === 'station') {
            return String(item.name || item.description || networkDisplay(item.network));
        }
        if (key === 'network') {
            return networkDisplay(item.network);
        }
        return String(item.target || '');
    }

    function compareDashboardFavorites(left, right) {
        const compareText = (leftValue, rightValue) => String(leftValue || '').localeCompare(
            String(rightValue || ''),
            undefined,
            {
                numeric: true,
                sensitivity: 'base',
            }
        );

        const compareNode = (leftValue, rightValue) => {
            const leftNode = String(leftValue || '');
            const rightNode = String(rightValue || '');

            if (/^\d+$/.test(leftNode) && /^\d+$/.test(rightNode)) {
                return Number(leftNode) - Number(rightNode);
            }

            return compareText(leftNode, rightNode);
        };

        const primaryKey = dashboardFavoritesSortKey;
        const leftPrimary = dashboardFavoriteSortValue(left, primaryKey);
        const rightPrimary = dashboardFavoriteSortValue(right, primaryKey);

        let result = primaryKey === 'target'
            ? compareNode(leftPrimary, rightPrimary)
            : compareText(leftPrimary, rightPrimary);

        if (result !== 0) {
            return dashboardFavoritesSortDirection === 'desc' ? -result : result;
        }

        /*
         * Default order:
         *   1. Node number ascending
         *   2. Station ascending
         *
         * Station remains the ascending secondary tie-breaker whenever
         * another primary column is selected.
         */
        if (primaryKey !== 'station') {
            result = compareText(
                dashboardFavoriteSortValue(left, 'station'),
                dashboardFavoriteSortValue(right, 'station')
            );
            if (result !== 0) return result;
        }

        if (primaryKey !== 'target') {
            result = compareNode(left.target, right.target);
            if (result !== 0) return result;
        }

        if (primaryKey !== 'network') {
            result = compareText(
                dashboardFavoriteSortValue(left, 'network'),
                dashboardFavoriteSortValue(right, 'network')
            );
        }

        return result;
    }

    function updateDashboardFavoriteSortButtons() {
        for (const button of elements.favoriteSortButtons) {
            const key = String(button.dataset.dashboardFavoriteSort || '');
            const active = key === dashboardFavoritesSortKey;
            const direction = active ? dashboardFavoritesSortDirection : 'none';
            const indicator = button.querySelector('.ac-dashboard-favorites-sort-indicator');

            button.setAttribute(
                'aria-sort',
                direction === 'asc' ? 'ascending' : (direction === 'desc' ? 'descending' : 'none')
            );
            button.classList.toggle('is-active', active);

            if (indicator) {
                indicator.textContent = active
                    ? (dashboardFavoritesSortDirection === 'asc' ? '▲' : '▼')
                    : '↕';
            }
        }
    }

    function renderDashboardFavorites() {
        if (!elements.favoritesList) return;

        updateDashboardFavoriteSortButtons();

        if (!state.favorites.length) {
            elements.favoritesList.innerHTML = '<div class="ac-empty-inline"><strong>No Favorites saved yet</strong><span>Use the star beside a live connection to add or edit one.</span></div>';
            return;
        }

        const sorted = [...state.favorites].sort(compareDashboardFavorites);

        elements.favoritesList.innerHTML = sorted.map((item) => `
            <button type="button" class="ac-dashboard-favorite" data-load-favorite="${escapeHtml(favoriteKey(item.network, item.target))}">
                <span class="ac-dashboard-favorite-star">★</span>
                <strong>${escapeHtml(item.target)}</strong>
                <span>${escapeHtml(item.name || item.description || networkDisplay(item.network))}</span>
                <small>${escapeHtml(networkDisplay(item.network))}</small>
            </button>`).join('');
    }

    async function refreshFavorites() {
        if (!favoritesEndpoint) return;
        try {
            const response = await fetch(`${favoritesEndpoint}?_=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Unable to load Favorites.');
            state.favorites = Array.isArray(payload.favorites) ? payload.favorites : [];
            renderDashboardFavorites();
            renderConnections(state.connections);
            const selected = selectedItem();
            if (selected) renderDetails(selected);
        } catch (error) {
            setControlStatus(error?.message || 'Unable to load Favorites.', true);
        }
    }

    async function postFavorite(payload) {
        if (!favoritesEndpoint) throw new Error('Favorites API is unavailable.');
        const response = await fetch(favoritesEndpoint, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result?.ok) throw new Error(result?.message || 'Favorite action failed.');
        state.favorites = Array.isArray(result.favorites) ? result.favorites : state.favorites;
        renderDashboardFavorites();
        renderConnections(state.connections);
        return result;
    }

    async function postLink(payload) {
        if (!linkEndpoint) throw new Error('Link control API is unavailable.');
        const response = await fetch(linkEndpoint, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result?.ok) throw new Error(result?.message || 'Link operation failed.');
        return result;
    }

    function applySelectedNetwork(network, targetOverride = null, prefill = true) {
        const requestedNetwork = normalizeNetworkCode(network);
        const source = targetOverride === null
            ? String(elements.connectTarget?.value || '')
            : String(targetOverride || '');
        const target = cleanConnectEntry(source, requestedNetwork);

        state.selectedNetwork = networkForTarget(requestedNetwork, target);
        for (const button of elements.networkTabs) {
            const active = normalizeNetworkCode(button.dataset.network) === state.selectedNetwork;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
        if (elements.connectTarget) {
            elements.connectTarget.value = target;
            elements.connectTarget.inputMode = state.selectedNetwork === 'ECHO' ? 'text' : 'numeric';
            elements.connectTarget.placeholder = state.selectedNetwork === 'ECHO'
                ? 'EchoLink node or callsign'
                : 'Enter AllStar node number';
        }
        syncConnectControls();
        if (prefill) scheduleManualFavoritePrefill();
    }

    function loadConnectTarget(network, target, item = null, options = {}) {
        const cleanTarget = String(target || '').replace(/\D/g, '');
        const networkCode = networkForTarget(network, cleanTarget);
        if (!validFavoriteTarget(networkCode, cleanTarget)) return;
        if (elements.connectTarget) elements.connectTarget.value = cleanTarget;
        applySelectedNetwork(networkCode, cleanTarget, false);
        syncConnectControls();
        prefillFavoriteEditor(networkCode, cleanTarget, item, { open: false, focus: Boolean(options.focus) });
        if (options.focusTarget !== false && elements.connectTarget) {
            if (mobileDownstreamMedia.matches) {
                const targetInput = elements.connectTarget;
                const connectSection = targetInput.closest('.ac-connect-section');

                /*
                 * A tapped Dashboard Favorite keeps focus until its click
                 * finishes. Defer the mobile scroll until after that focus
                 * settles, then focus the Target Node field without letting
                 * Android scroll back to the Favorite row.
                 */
                window.setTimeout(() => {
                    (connectSection || targetInput).scrollIntoView({
                        behavior: 'smooth',
                        block: 'start',
                    });
                    window.setTimeout(() => {
                        targetInput.focus({ preventScroll: true });
                    }, 300);
                }, 0);
            } else {
                elements.connectTarget.focus();
            }
        }
    }

    function syncNetworkFromTarget() {
        const raw = String(elements.connectTarget?.value || '').trim();
        const echoEntry = cleanEchoLinkEntry(raw);
        const echoCallsign = (
            echoEntry.length <= 32
            && /^[A-Z0-9*_.\/-]+$/.test(echoEntry)
            && /[A-Z*]/.test(echoEntry)
        );

        if (echoCallsign && state.selectedNetwork !== 'ECHO') {
            applySelectedNetwork('ECHO', echoEntry, false);
            return true;
        }

        if (/^3\d{6}$/.test(raw) && state.selectedNetwork !== 'ECHO') {
            applySelectedNetwork('ECHO', raw, false);
            return true;
        }

        return false;
    }

    function syncConnectControls() {
        const target = cleanConnectEntry(elements.connectTarget?.value, state.selectedNetwork);
        const network = networkForTarget(state.selectedNetwork, target);
        if (elements.connectTarget && elements.connectTarget.value !== target) {
            elements.connectTarget.value = target;
        }

        const valid = network === 'ECHO'
            ? validEchoLinkIdentifier(target)
            : /^\d{1,7}$/.test(target);
        const favoriteTarget = network === 'ECHO'
            ? mappedEchoLinkTarget(target)
            : target;

        if (elements.connectButton) {
            elements.connectButton.disabled =
                !canWrite
                || !linkEndpoint
                || !valid
                || state.pendingActions.has('connect');
        }
        if (elements.connectFavoriteStar) {
            elements.connectFavoriteStar.disabled = !canWrite || !valid;
            elements.connectFavoriteStar.textContent =
                favoriteTarget !== '' && favoriteFor(network, favoriteTarget) ? '★' : '☆';
        }
    }

    async function connectSelectedTarget() {
        if (!elements.connectButton || elements.connectButton.disabled) return;

        const entry = cleanConnectEntry(elements.connectTarget?.value, state.selectedNetwork);
        const network = networkForTarget(state.selectedNetwork, entry);
        const mode = String(elements.connectMode?.value || 'transceive');

        state.pendingActions.add('connect');
        syncConnectControls();

        try {
            const target = network === 'ECHO'
                ? await resolveEchoLinkConnectTarget(entry)
                : entry;

            setControlStatus(`Connecting ${networkDisplay(network)} ${target}…`);
            const result = await postLink({
                action: 'connect',
                network,
                target,
                link_mode: mode,
                disconnect_before_connect: Boolean(elements.disconnectBeforeConnect?.checked),
            });
            setControlStatus(result.message || 'Connect command completed.');
            window.setTimeout(refreshLocal, 250);
            window.setTimeout(refreshDownstream, 900);
        } catch (error) {
            setControlStatus(error?.message || 'Connect failed.', true);
        } finally {
            state.pendingActions.delete('connect');
            syncConnectControls();
        }
    }

    function disconnectPayload(item) {
        const kind = String(item?.kind || '');
        if (kind === 'iax' && item.channel) {
            return { action: 'disconnect_iax_channel', selected_channel: item.channel, selected_row_node: item.node || '' };
        }
        if (kind === 'client') {
            return {
                action: 'disconnect_live_client',
                selected_client: item.disconnect_target || item.reported_node || item.node || item.callsign || '',
            };
        }
        return {
            action: 'disconnect_selected',
            selected_node: String(kind === 'echo'
                ? (item.control_target || item.reported_node || '')
                : item.node || '').replace(/\D/g, ''),
            network: kind === 'echo' ? 'ECHO' : 'ASL',
        };
    }

    function disconnectIdentity(item) {
        const kind = String(item?.kind || 'asl').toLowerCase();
        if (kind === 'iax') {
            return `iax:${String(item?.node || item?.callsign || item?.channel || '').trim().toLowerCase()}`;
        }
        if (kind === 'client') {
            return `client:${String(item?.disconnect_target || item?.reported_node || item?.node || item?.callsign || '').trim().toLowerCase()}`;
        }
        const target = String(kind === 'echo'
            ? (item?.control_target || item?.reported_node || '')
            : item?.node || '').replace(/\D/g, '');
        return `${kind === 'echo' ? 'echo' : 'asl'}:${target}`;
    }

    function disconnectActionKey(item) {
        return `disconnect:${disconnectIdentity(item)}`;
    }

    function pendingDisconnectActive(item) {
        const key = disconnectActionKey(item);
        const expiresAt = Number(state.pendingDisconnectUntil.get(key) || 0);
        if (!expiresAt) return false;
        if (Date.now() <= expiresAt) return true;
        state.pendingDisconnectUntil.delete(key);
        state.pendingActions.delete(key);
        return false;
    }

    function reconcilePendingDisconnects(connections, previousConnections = []) {
        const next = Array.isArray(connections) ? [...connections] : [];
        const active = new Set(next.map(disconnectIdentity));
        const previousByIdentity = new Map(
            (Array.isArray(previousConnections) ? previousConnections : [])
                .map((item) => [disconnectIdentity(item), item])
        );

        for (const [key, expiresAt] of state.pendingDisconnectUntil.entries()) {
            const identity = key.replace(/^disconnect:/, '');
            const apiComplete = state.completedDisconnects.has(key);

            if (!active.has(identity)) {
                if (apiComplete) {
                    state.pendingDisconnectUntil.delete(key);
                    state.pendingActions.delete(key);
                    state.completedDisconnects.delete(key);
                    continue;
                }

                // Asterisk can remove the row as soon as ilink 1 is sent, while
                // the protected EchoLink settle/idle/reset sequence is still
                // running. Keep the last exact row visible and disabled until the
                // API confirms that the entire protected operation is complete.
                const previous = previousByIdentity.get(identity);
                if (previous) {
                    next.push({ ...previous, disconnect_pending: true });
                    active.add(identity);
                }
            }

            if (Date.now() > Number(expiresAt || 0)) {
                state.pendingDisconnectUntil.delete(key);
                state.pendingActions.delete(key);
                state.completedDisconnects.delete(key);
                setControlStatus('The protected disconnect did not finish in time. Refresh status before trying again.', true);
            }
        }

        return next;
    }

    async function disconnectItem(item) {
        if (!item || !canWrite) return;
        const key = disconnectActionKey(item);
        if (state.pendingActions.has(key)) return;
        state.pendingActions.add(key);
        state.completedDisconnects.delete(key);
        state.pendingDisconnectUntil.set(key, Date.now() + 20000);
        if (state.selectedType === 'current' && state.selectedKey === item.key) {
            state.selectedKey = '';
            state.selectedType = '';
            renderDetails(null);
        }
        renderConnections(state.connections);
        setControlStatus(`Disconnecting ${item.callsign || item.node || 'selected connection'}…`);
        try {
            const result = await postLink(disconnectPayload(item));
            state.completedDisconnects.add(key);
            window.dispatchEvent(new CustomEvent('allstar_connect:disconnect_expected', { detail: { item } }));
            setControlStatus(result.message || 'Protected disconnect completed; waiting for Asterisk status…');
            [0, 150, 400, 850, 1500].forEach((delay) => window.setTimeout(refreshLocal, delay));
            window.setTimeout(refreshDownstream, 650);
        } catch (error) {
            window.dispatchEvent(new CustomEvent('allstar_connect:disconnect_cancelled', { detail: { item } }));
            state.pendingDisconnectUntil.delete(key);
            state.pendingActions.delete(key);
            state.completedDisconnects.delete(key);
            renderConnections(state.connections);
            setControlStatus(error?.message || 'Disconnect failed.', true);
        }
    }

    async function switchItemMode(item, requestedMode) {
        if (!item || !canWrite) return;
        const kind = String(item.kind || '');
        if (!['asl', 'echo'].includes(kind)) return;
        const key = `mode:${item.key}`;
        if (state.pendingActions.has(key)) return;
        state.pendingActions.add(key);
        renderConnections(state.connections);
        const target = String(kind === 'echo'
            ? (item.control_target || item.reported_node || '')
            : item.node || '').replace(/\D/g, '');
        setControlStatus(`Changing ${item.callsign || item.node} to ${requestedMode === 'local_monitor' ? 'Local Monitor' : 'Transceive'}…`);
        try {
            const result = await postLink({
                action: 'switch_mode',
                selected_node: target,
                network: kind === 'echo' ? 'ECHO' : 'ASL',
                link_mode: requestedMode,
            });
            setControlStatus(result.message || 'Link mode changed.');
            window.setTimeout(refreshLocal, 300);
        } catch (error) {
            setControlStatus(error?.message || 'Mode change failed.', true);
        } finally {
            window.setTimeout(() => {
                state.pendingActions.delete(key);
                renderConnections(state.connections);
            }, 800);
        }
    }

    for (const button of elements.networkTabs) {
        button.addEventListener('click', () => {
            const requestedNetwork = normalizeNetworkCode(button.dataset.network);

            if (requestedNetwork === state.selectedNetwork) {
                elements.connectTarget?.focus();
                return;
            }

            window.clearTimeout(state.favoriteLookupTimer);
            state.favoriteLookupController?.abort();
            state.favoriteLookupController = null;

            if (elements.connectTarget) {
                elements.connectTarget.value = '';
            }

            applySelectedNetwork(requestedNetwork, '', false);
            elements.connectTarget?.focus();
        });
    }
    elements.connectTarget?.addEventListener('input', () => {
        if (syncNetworkFromTarget()) {
            scheduleManualFavoritePrefill();
            return;
        }

        const cleaned = cleanConnectEntry(elements.connectTarget.value, state.selectedNetwork);
        if (elements.connectTarget.value !== cleaned) elements.connectTarget.value = cleaned;
        syncConnectControls();
        scheduleManualFavoritePrefill();
    });
    elements.connectTarget?.addEventListener('paste', () => {
        window.setTimeout(normalizeEchoLinkNumericInput, 0);
    });
    elements.connectTarget?.addEventListener('blur', normalizeEchoLinkNumericInput);
    elements.connectTarget?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            connectSelectedTarget();
        }
    });
    elements.connectMode?.addEventListener('change', syncConnectControls);
    elements.connectButton?.addEventListener('click', connectSelectedTarget);
    elements.connectFavoriteStar?.addEventListener('click', async () => {
        const entry = cleanConnectEntry(elements.connectTarget?.value, state.selectedNetwork);
        const network = networkForTarget(state.selectedNetwork, entry);
        try {
            const target = network === 'ECHO'
                ? await resolveEchoLinkConnectTarget(entry)
                : entry;
            if (!validFavoriteTarget(network, target)) return;
            prefillFavoriteEditor(network, target, null, { open: true, focus: true });
        } catch (error) {
            setControlStatus(error?.message || 'EchoLink lookup failed.', true);
        }
    });
    elements.favoritesList?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-load-favorite]');
        if (!button) return;
        const item = state.favorites.find((entry) => favoriteKey(entry.network, entry.target) === String(button.dataset.loadFavorite || ''));
        if (!item) return;
        event.preventDefault();
        button.blur();
        loadConnectTarget(item.network, item.target, item, {
            focus: false,
            focusTarget: true,
        });
    });

    for (const button of elements.favoriteSortButtons) {
        button.addEventListener('click', () => {
            const key = String(button.dataset.dashboardFavoriteSort || '');
            if (!['target', 'station', 'network'].includes(key)) return;

            if (dashboardFavoritesSortKey === key) {
                dashboardFavoritesSortDirection =
                    dashboardFavoritesSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                dashboardFavoritesSortKey = key;
                dashboardFavoritesSortDirection = 'asc';
            }

            renderDashboardFavorites();
        });
    }

    const query = new URLSearchParams(window.location.search);
    if (query.get('target')) {
        loadConnectTarget(query.get('network') || 'ASL', query.get('target'), null, { focus: false, focusTarget: false });
    } else {
        applySelectedNetwork('ASL');
        syncConnectControls();
        scheduleManualFavoritePrefill(50);
    }

    function connectionLists() {
        return [elements.connections, elements.connectionsExpanded].filter(Boolean);
    }

    function activityLists() {
        return [elements.activity, elements.activityExpanded].filter(Boolean);
    }

    function downstreamLists() {
        return [
            elements.downstream,
            elements.downstreamExpanded,
            elements.downstreamMobile,
        ].filter(Boolean);
    }

    function syncListSelection(lists, sourceList, attribute, key) {
        const selectedKey = String(key || '');
        if (!selectedKey) {
            return;
        }

        window.requestAnimationFrame(() => {
            for (const list of lists) {
                if (list === sourceList || list.clientHeight === 0) {
                    continue;
                }

                const target = list.querySelector(`[${attribute}="${CSS.escape(selectedKey)}"]`);
                if (!target) {
                    continue;
                }

                const listRect = list.getBoundingClientRect();
                const targetRect = target.getBoundingClientRect();
                const padding = 8;
                let nextTop = list.scrollTop;

                if (targetRect.top < listRect.top + padding) {
                    nextTop -= (listRect.top + padding) - targetRect.top;
                } else if (targetRect.bottom > listRect.bottom - padding) {
                    nextTop += targetRect.bottom - (listRect.bottom - padding);
                }

                if (Math.abs(nextTop - list.scrollTop) >= 1) {
                    list.scrollTo({ top: Math.max(0, nextTop), behavior: 'smooth' });
                }
            }
        });
    }

    function syncDownstreamSelection(sourceList, attribute, key) {
        syncListSelection(downstreamLists(), sourceList, attribute, key);
    }

    const floatingWindows = [
        {
            key: 'connections',
            panel: elements.connectionsWindow,
            handle: elements.connectionsWindowHandle,
            close: elements.connectionsWindowClose,
            expand: elements.connectionsExpand,
        },
        {
            key: 'downstream',
            panel: elements.downstreamWindow,
            handle: elements.downstreamWindowHandle,
            close: elements.downstreamWindowClose,
            expand: elements.downstreamExpand,
        },
        {
            key: 'activity',
            panel: elements.activityWindow,
            handle: elements.activityWindowHandle,
            close: elements.activityWindowClose,
            expand: elements.activityExpand,
        },
    ].filter((item) => item.panel && item.handle && item.close && item.expand);

    let floatingWindowZ = 90;
    let floatingWindowDrag = null;

    function bringFloatingWindowToFront(config) {
        if (!config?.panel) {
            return;
        }
        floatingWindowZ += 1;
        config.panel.style.zIndex = String(floatingWindowZ);
    }

    function constrainFloatingWindow(config) {
        const panel = config?.panel;
        if (!panel || panel.hidden || !desktopFloatingMedia.matches) {
            return;
        }

        const rect = panel.getBoundingClientRect();
        const margin = 8;
        const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
        const maxTop = Math.max(margin, window.innerHeight - rect.height - margin);
        const left = Math.min(maxLeft, Math.max(margin, rect.left));
        const top = Math.min(maxTop, Math.max(margin, rect.top));
        panel.style.left = `${Math.round(left)}px`;
        panel.style.top = `${Math.round(top)}px`;
        panel.style.right = 'auto';
    }

    function setFloatingWindowOpen(config, open, returnFocus = false) {
        if (!config?.panel || !config.expand) {
            return;
        }

        const shouldOpen = Boolean(open) && desktopFloatingMedia.matches;
        config.panel.hidden = !shouldOpen;
        config.expand.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        config.expand.classList.toggle('is-active', shouldOpen);

        if (shouldOpen) {
            bringFloatingWindowToFront(config);
            window.requestAnimationFrame(() => {
                constrainFloatingWindow(config);
                if (config.key === 'downstream' && elements.downstreamExpanded) {
                    elements.downstreamExpanded.scrollTop = 0;
                    elements.downstreamExpanded.scrollLeft = 0;
                }
                config.close?.focus({ preventScroll: true });
            });
        } else if (returnFocus && desktopFloatingMedia.matches) {
            config.expand.focus({ preventScroll: true });
        }
    }

    function beginFloatingWindowDrag(config, event) {
        const panel = config?.panel;
        const handle = config?.handle;
        if (!panel || !handle || panel.hidden || event.button !== 0 || event.target.closest('button, a')) {
            return;
        }

        bringFloatingWindowToFront(config);
        const rect = panel.getBoundingClientRect();
        panel.style.left = `${Math.round(rect.left)}px`;
        panel.style.top = `${Math.round(rect.top)}px`;
        panel.style.right = 'auto';
        floatingWindowDrag = {
            config,
            pointerId: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
        };
        handle.setPointerCapture?.(event.pointerId);
        panel.classList.add('is-dragging');
        event.preventDefault();
    }

    function moveFloatingWindow(event) {
        const drag = floatingWindowDrag;
        const panel = drag?.config?.panel;
        if (!panel || event.pointerId !== drag.pointerId) {
            return;
        }

        const rect = panel.getBoundingClientRect();
        const margin = 8;
        const maxLeft = Math.max(margin, window.innerWidth - rect.width - margin);
        const maxTop = Math.max(margin, window.innerHeight - rect.height - margin);
        const left = Math.min(maxLeft, Math.max(margin, event.clientX - drag.offsetX));
        const top = Math.min(maxTop, Math.max(margin, event.clientY - drag.offsetY));
        panel.style.left = `${Math.round(left)}px`;
        panel.style.top = `${Math.round(top)}px`;
        event.preventDefault();
    }

    function endFloatingWindowDrag(event) {
        const drag = floatingWindowDrag;
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }

        drag.config.handle?.releasePointerCapture?.(event.pointerId);
        drag.config.panel?.classList.remove('is-dragging');
        floatingWindowDrag = null;
        constrainFloatingWindow(drag.config);
    }

    for (const config of floatingWindows) {
        config.expand.addEventListener('click', () => {
            setFloatingWindowOpen(config, config.panel.hidden !== false);
        });
        config.close.addEventListener('click', () => setFloatingWindowOpen(config, false, true));
        config.handle.addEventListener('pointerdown', (event) => beginFloatingWindowDrag(config, event));
        config.panel.addEventListener('pointerdown', () => bringFloatingWindowToFront(config));
        config.panel.addEventListener('focusin', () => bringFloatingWindowToFront(config));
    }

    window.addEventListener('pointermove', moveFloatingWindow);
    window.addEventListener('pointerup', endFloatingWindowDrag);
    window.addEventListener('pointercancel', endFloatingWindowDrag);
    window.addEventListener('resize', () => {
        for (const config of floatingWindows) {
            constrainFloatingWindow(config);
        }
    });

    let floatingWindowResizeObserver = null;
    if (typeof ResizeObserver === 'function') {
        floatingWindowResizeObserver = new ResizeObserver((entries) => {
            for (const entry of entries) {
                const config = floatingWindows.find((item) => item.panel === entry.target);
                if (config) {
                    window.requestAnimationFrame(() => constrainFloatingWindow(config));
                }
            }
        });
        for (const config of floatingWindows) {
            floatingWindowResizeObserver.observe(config.panel);
        }
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (mobileDownstreamSheetOpen()) {
            setMobileDownstreamSheetOpen(false, true);
            return;
        }

        const openWindows = floatingWindows
            .filter((item) => item.panel.hidden === false)
            .sort((a, b) => Number(b.panel.style.zIndex || 90) - Number(a.panel.style.zIndex || 90));
        if (openWindows[0]) {
            setFloatingWindowOpen(openWindows[0], false, true);
        }
    });

    const handleFloatingViewportChange = () => {
        for (const config of floatingWindows) {
            if (!desktopFloatingMedia.matches) {
                setFloatingWindowOpen(config, false);
            } else {
                constrainFloatingWindow(config);
            }
        }
    };
    if (typeof desktopFloatingMedia.addEventListener === 'function') {
        desktopFloatingMedia.addEventListener('change', handleFloatingViewportChange);
    } else if (typeof desktopFloatingMedia.addListener === 'function') {
        desktopFloatingMedia.addListener(handleFloatingViewportChange);
    }

    function mobileDownstreamSheetOpen() {
        return Boolean(
            elements.downstreamMobileSheet
            && elements.downstreamMobileSheet.hidden === false
        );
    }

    function setMobileDownstreamSheetOpen(open, returnFocus = false) {
        if (
            !elements.downstreamMobileSheet
            || !elements.downstreamMobileOpen
        ) {
            return;
        }

        const shouldOpen = Boolean(open) && mobileDownstreamMedia.matches;
        elements.downstreamMobileSheet.hidden = !shouldOpen;
        elements.downstreamMobileSheet.setAttribute(
            'aria-hidden',
            shouldOpen ? 'false' : 'true'
        );
        elements.downstreamMobileOpen.setAttribute(
            'aria-expanded',
            shouldOpen ? 'true' : 'false'
        );
        document.body.classList.toggle(
            'ac-mobile-downstream-sheet-open',
            shouldOpen
        );

        if (shouldOpen) {
            state.downstreamRenderSignature = '';
            renderDownstream();
            window.requestAnimationFrame(() => {
                elements.downstreamMobileClose?.focus({ preventScroll: true });
            });
        } else if (returnFocus) {
            elements.downstreamMobileOpen.focus({ preventScroll: true });
        }
    }

    elements.downstreamMobileOpen?.addEventListener('click', () => {
        setMobileDownstreamSheetOpen(true);
    });
    elements.downstreamMobileClose?.addEventListener('click', () => {
        setMobileDownstreamSheetOpen(false, true);
    });

    function handleMobileDownstreamViewportChange() {
        if (!mobileDownstreamMedia.matches && mobileDownstreamSheetOpen()) {
            setMobileDownstreamSheetOpen(false);
        }
    }

    if (typeof mobileDownstreamMedia.addEventListener === 'function') {
        mobileDownstreamMedia.addEventListener(
            'change',
            handleMobileDownstreamViewportChange
        );
    } else if (typeof mobileDownstreamMedia.addListener === 'function') {
        mobileDownstreamMedia.addListener(
            handleMobileDownstreamViewportChange
        );
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function formatTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return 'Just now';
        }
        return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }

    function formatCurrentDateTime(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '—';
        }
        return date.toLocaleTimeString([], {
            hour: 'numeric',
            minute: '2-digit',
        });
    }

    function renderSystemStatus(system) {
        const data = system && typeof system === 'object' ? system : {};
        const number = (key) => {
            const value = Number(data[key]);
            return Number.isFinite(value) ? value : null;
        };
        const compact = (key) => {
            const value = String(data[key] ?? '').trim();
            return value !== '' ? value : '—';
        };
        const setValue = (element, value, title) => {
            if (!element) return;
            element.textContent = value;
            element.closest('.ac-system-pill')?.setAttribute('title', title);
        };

        const cpuPercent = number('cpu_percent');
        setValue(
            elements.systemCpu,
            cpuPercent === null ? compact('cpu_compact') : `${Math.round(cpuPercent)}%`,
            `CPU use: ${compact('cpu_compact')}`
        );

        const memoryUsed = number('memory_used_bytes');
        const memoryTotal = number('memory_total_bytes');
        const memoryPercent = memoryUsed !== null && memoryTotal !== null && memoryTotal > 0
            ? Math.max(0, Math.min(100, Math.round((memoryUsed / memoryTotal) * 100)))
            : null;
        setValue(
            elements.systemRam,
            memoryPercent === null ? compact('memory_compact') : `${memoryPercent}%`,
            `Memory: ${compact('memory_compact')}`
        );

        const temperatureF = number('temperature_f');
        setValue(
            elements.systemTemp,
            temperatureF === null ? compact('temperature_compact') : `${Math.round(temperatureF)}°F`,
            `Temperature: ${compact('temperature_compact')}`
        );

        setValue(
            elements.systemDisk,
            compact('root_compact'),
            `Root disk used: ${compact('root_compact')}`
        );
        setValue(
            elements.systemUptime,
            compact('uptime_compact'),
            `Uptime: ${compact('uptime_compact')}`
        );
    }

    function activityEventKey(event) {
        const id = String(event?.id || '').trim();
        if (id) {
            return id;
        }
        return [event?.timestamp, event?.type, event?.key, event?.node].map((value) => String(value || '')).join(':');
    }

    function qrzCallsign(value) {
        const identity = String(value || '').trim().toUpperCase();
        if (/^\*[A-Z0-9_.\/-]+\*$/.test(identity)) {
            return '';
        }
        const match = identity.match(/\b([A-Z]{1,3}[0-9][A-Z0-9]{1,4})\b/);
        return match ? match[1] : '';
    }

    function webPhoneCallsign(value) {
        const match = String(value || '').trim().toUpperCase().match(/^([A-Z]{1,3}[0-9][A-Z0-9]{1,4})-P$/);
        return match ? match[1] : '';
    }

    function isRemoteWebPhoneClient(item) {
        if (!item) {
            return false;
        }
        return String(item.client_type || '').trim() === 'web_phone'
            || (String(item.kind || '').trim() === 'client' && /-P$/i.test(String(item.node || '').trim()));
    }

    function isDownstreamWebPhoneClient(item) {
        return Boolean(item)
            && String(item.kind || '').trim() === 'client'
            && String(item.client_type || '').trim() === 'web_phone'
            && Boolean(String(item.direct_node || '').trim());
    }

    function isDownstreamEchoLink(item) {
        return Boolean(item)
            && String(item.kind || '').trim() === 'echo'
            && Boolean(String(item.direct_node || '').trim());
    }

    function echoLinkNodeNumber(value) {
        const raw = String(value || '').trim();
        const mapped = raw.match(/^3(\d{6})$/);
        if (mapped) {
            return mapped[1].replace(/^0+/, '') || '0';
        }
        return /^\d{1,6}$/.test(raw) ? (raw.replace(/^0+/, '') || '0') : '';
    }

    function echoLinkCallsignKey(value) {
        const callsign = String(value || '').trim().toUpperCase();
        return callsign ? `call:${callsign}` : '';
    }

    function echoLinkDescription(callsign) {
        const value = String(callsign || '').trim().toUpperCase();
        if (!value) return 'EchoLink';
        if (value.endsWith('-R')) return 'EchoLink Repeater';
        if (value.endsWith('-L')) return 'EchoLink Link';
        if (qrzCallsign(value)) return 'EchoLink User';
        return 'EchoLink Conference';
    }

    function applyEchoLinkIdentity(item) {
        if (!item || String(item.kind || '').trim() !== 'echo') {
            return item;
        }

        const reportedNode = echoLinkNodeNumber(item.echolink_node || item.reported_node || item.node);
        const liveCallsign = String(item.callsign || '').trim().toUpperCase();
        const callsignEntry = liveCallsign ? state.echoLinkEntries[echoLinkCallsignKey(liveCallsign)] : null;
        const nodeEntry = reportedNode ? state.echoLinkEntries[reportedNode] : null;

        // Relay-mode EchoLink sessions can report a made-up node number.
        // The live callsign is reliable, and the official callsign lookup is
        // authoritative for the assigned EchoLink node number.
        const callsign = String(liveCallsign || callsignEntry?.callsign || nodeEntry?.callsign || '').trim().toUpperCase();
        const officialNode = echoLinkNodeNumber(callsignEntry?.node || (!liveCallsign ? nodeEntry?.node : ''));
        const displayNode = officialNode || (!liveCallsign ? reportedNode : '');

        if (!callsign) {
            return {
                ...item,
                node: reportedNode,
                echolink_node: reportedNode,
                identity_pending: true,
                identity_verified: false,
            };
        }

        const qrz = qrzCallsign(callsign);
        const verified = Boolean(officialNode);
        return {
            ...item,
            node: verified ? officialNode : '',
            echolink_node: verified ? officialNode : '',
            callsign,
            description: echoLinkDescription(callsign),
            display: callsign,
            identity_pending: !verified,
            identity_verified: verified,
            stats_url: '',
            qrz_url: qrz ? `https://www.qrz.com/db/${encodeURIComponent(qrz)}` : '',
        };
    }

    function orderedDownstreamChildren(rootNode, children) {
        const byParent = new Map();
        for (const item of children) {
            const parent = String(item.parent_node || rootNode || '').trim();
            if (!byParent.has(parent)) {
                byParent.set(parent, []);
            }
            byParent.get(parent).push(item);
        }

        const sortItems = (items) => items.sort((a, b) => {
            const kindOrder = (item) => isDownstreamWebPhoneClient(item) ? 1 : (isDownstreamEchoLink(item) ? 2 : 0);
            const order = kindOrder(a) - kindOrder(b);
            if (order !== 0) {
                return order;
            }
            return String(a.node || '').localeCompare(String(b.node || ''), undefined, { numeric: true });
        });
        for (const items of byParent.values()) {
            sortItems(items);
        }

        const ordered = [];
        const visited = new Set();
        const visit = (parent) => {
            for (const item of byParent.get(String(parent)) || []) {
                const key = String(item.key || `${item.direct_node || ''}:${item.parent_node || ''}:${item.node || ''}`);
                if (visited.has(key)) {
                    continue;
                }
                visited.add(key);
                ordered.push(item);
                if (String(item.kind || '') === 'asl') {
                    visit(item.node);
                }
            }
        };

        visit(rootNode);
        for (const item of sortItems([...children])) {
            const key = String(item.key || `${item.direct_node || ''}:${item.parent_node || ''}:${item.node || ''}`);
            if (!visited.has(key)) {
                ordered.push(item);
            }
        }
        return ordered;
    }

    function historicalActivityItem(event) {
        if (!event) {
            return null;
        }

        const source = String(event.source || '').trim();
        const key = String(event.key || '');
        const node = String(event.node || '').trim();
        let kind = String(event.kind || '').trim() || (source === 'AllStarLink' || key.startsWith('asl:') ? 'asl' : '');
        if (!kind && /-P$/i.test(node)) {
            kind = 'client';
        }
        const callsign = String(event.callsign || '').trim();
        const clientType = String(event.client_type || '').trim();
        const webPhoneCall = webPhoneCallsign(node);
        const isWebPhone = clientType === 'web_phone' || (kind === 'client' && /-P$/i.test(node));
        const resolvedCallsign = callsign || webPhoneCall;
        const qrz = qrzCallsign(resolvedCallsign || node);
        const isAllStar = kind === 'asl' && /^\d+$/.test(node);
        const isEchoLink = kind === 'echo';

        return {
            ...event,
            key: activityEventKey(event),
            kind,
            client_type: isWebPhone ? 'web_phone' : clientType,
            node,
            source: isWebPhone ? 'Web/Phone Client' : (source || 'Recorded activity'),
            callsign: resolvedCallsign,
            description: String(event.description || '').trim(),
            location: String(event.location || '').trim(),
            mode: String(event.mode || '').trim(),
            mode_label: String(event.mode_label || '').trim() || 'Recorded state',
            direction: String(event.direction || '').trim(),
            channel: String(event.channel || '').trim(),
            peer: String(event.peer || '').trim(),
            stats_url: isAllStar ? (String(event.stats_url || '').trim() || `https://stats.allstarlink.org/stats/${encodeURIComponent(node)}`) : '',
            qrz_url: (isAllStar || isWebPhone || isEchoLink) && qrz
                ? (String(event.qrz_url || '').trim() || `https://www.qrz.com/db/${encodeURIComponent(qrz)}`)
                : '',
            historical: true,
            activity_type: String(event.type || '').trim(),
            activity_timestamp: String(event.timestamp || '').trim(),
            duration_seconds: Number(event.duration_seconds || 0),
        };
    }

    function selectItem(item, type) {
        state.selectedKey = String(item?.key || '');
        state.selectedType = state.selectedKey ? type : '';
        renderConnections(state.connections);
        renderActivity();
        renderDownstream();
        renderDetails(item || null);

        if (item && ['current', 'downstream', 'root'].includes(type)) {
            const target = favoriteTargetForItem(item);
            const network = favoriteNetworkForItem(item);
            if (target && validFavoriteTarget(network, target)) {
                prefillFavoriteEditor(network, target, item, { open: false, focus: false });
            }
        }
    }

    function selectActivity(event) {
        const item = historicalActivityItem(event);
        state.selectedKey = item ? item.key : '';
        state.selectedType = state.selectedKey ? 'activity' : '';
        renderConnections(state.connections);
        renderActivity();
        renderDownstream();
        renderDetails(item);
    }

    function prioritizeDownstream(node) {
        const directNode = String(node || '').trim();
        if (!directNode) {
            return;
        }
        state.preferredDirectNode = directNode;
        state.preferredRemoteClients = false;
        state.scrollDownstreamOnRender = true;
    }

    function prioritizeRemoteClients() {
        state.preferredDirectNode = '';
        state.preferredRemoteClients = true;
        state.scrollDownstreamOnRender = true;
    }

    function selectedItem() {
        if (state.selectedType === 'current') {
            return state.connections.find((item) => item.key === state.selectedKey) || null;
        }
        if (state.selectedType === 'downstream') {
            return state.downstreamNodes.find((item) => item.key === state.selectedKey) || null;
        }
        if (state.selectedType === 'root') {
            return state.downstreamDirect.find((item) => item.key === state.selectedKey) || null;
        }
        if (state.selectedType === 'activity') {
            const event = state.activity.find((item) => activityEventKey(item) === state.selectedKey) || null;
            return historicalActivityItem(event);
        }
        return null;
    }

    function formatConnectionDuration(secondsValue) {
        const total = Math.max(0, Number(secondsValue || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = Math.floor(total % 60);
        return [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
    }

    function connectionDurationLabel(item) {
        const elapsed = String(item?.elapsed || '').trim();
        if (elapsed !== '') {
            if (/^\d+$/.test(elapsed)) {
                return formatConnectionDuration(Number(elapsed));
            }
            const clock = elapsed.match(/^(?:(\d+):)?(\d{1,2}):(\d{2})$/);
            if (clock) {
                return [Number(clock[1] || 0), Number(clock[2] || 0), Number(clock[3] || 0)]
                    .map((value) => String(value).padStart(2, '0')).join(':');
            }
            return elapsed;
        }
        const durationSeconds = item?.duration_seconds;
        return durationSeconds === null || durationSeconds === undefined || String(durationSeconds).trim() === ''
            ? '—'
            : formatConnectionDuration(durationSeconds);
    }

    function connectionDirection(item) {
        const value = String(item?.direction || '').trim().toLowerCase();
        if (value === 'incoming' || value === 'in' || value.startsWith('in')) return 'in';
        if (value === 'outgoing' || value === 'out' || value.startsWith('out')) return 'out';
        return 'unknown';
    }

    function connectionIdentity(item) {
        return [item.callsign, item.location || item.description].filter(Boolean).join(' — ')
            || item.channel || item.peer || 'Local Asterisk connection';
    }

    function openFavoriteModal(item) {
        if (!canWrite || !item) return;
        if (Boolean(item.is_private)) {
            setControlStatus('Private AllStar nodes cannot be saved as AllStarLink Favorites.', true);
            return;
        }
        const networkCode = favoriteNetworkForItem(item);
        const target = favoriteTargetForItem(item);
        if (!target) {
            setControlStatus('This connection does not have a saveable AllStar/EchoLink target.', true);
            return;
        }
        prefillFavoriteEditor(networkCode, target, item, { open: true, focus: true });
    }

    function closeFavoriteModal() {
        window.clearTimeout(state.favoriteLookupTimer);
        state.favoriteLookupController?.abort();
        state.favoriteLookupController = null;
        state.favoriteEditorKey = '';
        state.favoriteEditorDirty = false;
        for (const field of [elements.favoriteNetwork, elements.favoriteTarget, elements.favoriteName, elements.favoriteDescription]) {
            if (field) field.value = '';
        }
        if (elements.favoriteTitle) elements.favoriteTitle.textContent = 'Add Favorite';
        if (elements.favoriteSave) elements.favoriteSave.textContent = 'Save Favorite';
        setFavoriteHelper('The selected AllStarLink or EchoLink identity will be filled automatically. Change any details before saving.');
        if (elements.favoriteModal) {
            elements.favoriteModal.hidden = true;
            elements.favoriteModal.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('ac-modal-open');
    }

    for (const field of [elements.favoriteName, elements.favoriteDescription]) {
        field?.addEventListener('input', () => { state.favoriteEditorDirty = true; });
    }

    document.getElementById('allstar-connect-favorite-close')?.addEventListener('click', closeFavoriteModal);
    document.getElementById('allstar-connect-favorite-cancel')?.addEventListener('click', closeFavoriteModal);
    elements.favoriteModal?.addEventListener('click', (event) => {
        if (event.target === elements.favoriteModal) closeFavoriteModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && elements.favoriteModal && !elements.favoriteModal.hidden) closeFavoriteModal();
    });
    elements.favoriteSave?.addEventListener('click', async () => {
        if (!canWrite || elements.favoriteSave.disabled) return;
        const network = normalizeNetworkCode(elements.favoriteNetwork?.value || 'ASL');
        const target = String(elements.favoriteTarget?.value || '').replace(/\D/g, '');
        if (!target) {
            setControlStatus('Favorite target is missing.', true);
            return;
        }
        elements.favoriteSave.disabled = true;
        setControlStatus('Saving Favorite…');
        try {
            const result = await postFavorite({
                action: 'save',
                network,
                target,
                name: String(elements.favoriteName?.value || '').trim(),
                description: String(elements.favoriteDescription?.value || '').trim(),
            });
            setControlStatus(result.message || 'Favorite saved.');
            closeFavoriteModal();
            const selected = selectedItem();
            if (selected) renderDetails(selected);
            syncConnectControls();
        } catch (error) {
            setControlStatus(error?.message || 'Unable to save Favorite.', true);
        } finally {
            elements.favoriteSave.disabled = !canWrite;
        }
    });

    function connectionMarkup(connections) {
        return connections.map((item) => {
            const selected = state.selectedType === 'current' && item.key === state.selectedKey ? ' is-selected' : '';
            const keyed = item.keyed ? ' is-keyed' : '';
            const direction = connectionDirection(item);
            const directionArrow = direction === 'in' ? '↓' : (direction === 'out' ? '↑' : '↔');
            const directionClass = direction === 'in' ? ' is-in' : (direction === 'unknown' ? ' is-unknown' : '');
            const directionTitle = direction === 'in' ? 'Incoming' : (direction === 'out' ? 'Outgoing' : 'Direction not reported');
            const callsign = String(item.callsign || '').trim();
            const kind = String(item.kind || 'asl');
            const network = kind === 'echo' ? 'EchoLink' : (String(item.source || 'AllStarLink'));
            const echoNode = kind === 'echo' && item.identity_verified
                ? String(item.echolink_node || '').replace(/\D/g, '')
                : '';
            const primaryNodeLabel = kind === 'echo'
                ? (callsign || 'EchoLink')
                : String(item.node || callsign || 'Connection');
            const secondaryNodeLabel = kind === 'echo'
                ? (echoNode ? `EchoLink #${echoNode}` : 'Resolving EchoLink node…')
                : callsign;
            const modeValue = String(item.mode || '').toLowerCase() === 'local_monitor' ? 'local_monitor' : 'transceive';
            const modeLabel = modeValue === 'local_monitor' ? 'Local Monitor' : 'Transceive';
            const favoriteNetwork = favoriteNetworkForItem(item);
            const favoriteTarget = favoriteTargetForItem(item);
            const savedFavorite = favoriteTarget ? favoriteFor(favoriteNetwork, favoriteTarget) : null;
            const canFavorite = ['asl', 'echo'].includes(kind) && Boolean(favoriteTarget);
            const canMode = canWrite && linkEndpoint && ['asl', 'echo'].includes(kind);
            const pendingDisconnect = pendingDisconnectActive(item);
            const pendingMode = state.pendingActions.has(`mode:${item.key}`);
            const disconnectDisabled = !canWrite || !linkEndpoint || pendingDisconnect;
            const disconnectTitle = kind === 'iax' ? 'Exact true IAX channel hangup' : (kind === 'client' ? 'Exact IAX/Web client disconnect' : 'Exact row disconnect');
            return `
                <div role="button" tabindex="0" class="allstar-connect-connection-row${selected}${keyed}" data-connection-key="${escapeHtml(item.key)}">
                    <span class="ac-connection-dir${directionClass}" title="${directionTitle}">${directionArrow}</span>
                    <span class="ac-connection-node">
                        <strong>${escapeHtml(primaryNodeLabel)}</strong>
                        <small>${escapeHtml(secondaryNodeLabel)}</small>
                    </span>
                    <span class="ac-connection-identity">${item.keyed ? '<b class="ac-keyed-inline">KEYED</b>' : ''}${escapeHtml(connectionIdentity(item))}</span>
                    <span class="ac-connection-mode">${escapeHtml(modeLabel)}</span>
                    <span class="ac-connection-time">${escapeHtml(connectionDurationLabel(item))}</span>
                    <span class="ac-connection-link">${escapeHtml(network)}</span>
                    <span class="ac-row-actions">
                        <button type="button" class="ac-favorite-star" data-favorite-key="${escapeHtml(item.key)}" title="${savedFavorite ? 'Edit Favorite' : 'Add Favorite'}" aria-label="${savedFavorite ? 'Edit Favorite' : 'Add Favorite'}" ${canFavorite ? '' : 'disabled'}>${savedFavorite ? '★' : '☆'}</button>
                        <button type="button" class="ac-row-action ac-disconnect-button" data-disconnect-key="${escapeHtml(item.key)}" title="${escapeHtml(disconnectTitle)}" ${disconnectDisabled ? 'disabled' : ''}>${pendingDisconnect ? '…' : '× Disconnect'}</button>
                        <select class="ac-row-action ac-mode-select" data-mode-key="${escapeHtml(item.key)}" aria-label="Link mode" ${(!canMode || pendingMode) ? 'disabled' : ''}>
                            <option value="transceive"${modeValue === 'transceive' ? ' selected' : ''}>Transceive</option>
                            <option value="local_monitor"${modeValue === 'local_monitor' ? ' selected' : ''}>Local Monitor</option>
                        </select>
                    </span>
                </div>`;
        }).join('');
    }

    function updateConnectionCount(connections) {
        for (const count of elements.connectionsCounts) {
            count.textContent = String(connections.length);
        }
    }

    function renderConnectionEmpty(message, detail = '') {
        const markup = `
            <div class="allstar-connect-empty">
                <span class="allstar-connect-empty-icon" aria-hidden="true">&#8644;</span>
                <strong>${escapeHtml(message)}</strong>
                ${detail ? `<p>${escapeHtml(detail)}</p>` : ''}
            </div>`;

        for (const list of connectionLists()) {
            list.innerHTML = markup;
            list.setAttribute('aria-busy', 'false');
        }
        updateConnectionCount([]);
    }

    function renderConnections(connections) {
        const lists = connectionLists();
        if (!lists.length) {
            return;
        }

        for (const list of lists) {
            list.setAttribute('aria-busy', 'false');
        }

        const visibleConnections = Array.isArray(connections) ? connections : [];

        if (!visibleConnections.length) {
            const disconnecting = state.pendingDisconnectUntil.size > 0;
            renderConnectionEmpty(
                disconnecting ? 'Disconnecting selected connection…' : 'No direct connections detected',
                disconnecting ? 'Waiting for Asterisk to confirm that the exact row is gone.' : 'The local Asterisk snapshot is active and will update automatically.'
            );
            if (state.selectedType === 'current') {
                state.selectedKey = '';
                state.selectedType = '';
                renderDetails(null);
            }
            return;
        }

        updateConnectionCount(visibleConnections);

        // Do not replace a row while the operator is pressing an action control,
        // or while a native mode selector is open. A one-second status refresh
        // must never cancel the first Disconnect click or close the mode menu.
        const activeModeSelect = document.activeElement instanceof HTMLSelectElement
            && document.activeElement.matches('.ac-mode-select[data-mode-key]')
            ? document.activeElement
            : null;
        if (state.connectionActionPointerActive) {
            return;
        }
        const markup = connectionMarkup(visibleConnections);
        const scrollPositions = new Map(lists.map((list) => [list, list.scrollTop]));

        for (const list of lists) {
            if (activeModeSelect && list.contains(activeModeSelect)) {
                continue;
            }
            list.innerHTML = markup;
            list.scrollTop = scrollPositions.get(list) || 0;
        }
    }

    function handleConnectionClick(container, event) {
        const row = event.target.closest('[data-connection-key]');
        if (!row || !container.contains(row)) return;
        const connectionKey = String(row.dataset.connectionKey || '');
        const item = state.connections.find((connection) => connection.key === connectionKey);
        if (!item) return;

        const favoriteButton = event.target.closest('[data-favorite-key]');
        if (favoriteButton) {
            event.preventDefault();
            event.stopPropagation();
            state.connectionActionPointerActive = false;
            openFavoriteModal(item);
            return;
        }
        const disconnectButton = event.target.closest('[data-disconnect-key]');
        if (disconnectButton) {
            event.preventDefault();
            event.stopPropagation();
            // The click has already landed. Release the poll-protection flag
            // before rendering the pending state so one press immediately
            // disables/hides the exact row instead of looking like it needs a
            // second click.
            state.connectionActionPointerActive = false;
            disconnectItem(item);
            return;
        }
        if (event.target.closest('.ac-row-action')) return;

        if (item.kind === 'asl') prioritizeDownstream(item.node);
        else if (isRemoteWebPhoneClient(item)) prioritizeRemoteClients();
        selectItem(item, 'current');
        syncListSelection(connectionLists(), container, 'data-connection-key', connectionKey);
    }

    for (const list of connectionLists()) {
        list.addEventListener('pointerdown', (event) => {
            if (event.target.closest('.ac-row-action')) state.connectionActionPointerActive = true;
        });
        list.addEventListener('click', (event) => handleConnectionClick(list, event));
        list.addEventListener('change', (event) => {
            const select = event.target.closest('[data-mode-key]');
            if (!select || !list.contains(select)) return;
            const item = state.connections.find((connection) => connection.key === String(select.dataset.modeKey || ''));
            if (!item) return;
            state.connectionActionPointerActive = false;
            switchItemMode(item, String(select.value || 'transceive'));
        });
        list.addEventListener('keydown', (event) => {
            if (event.target.closest('button,select,input,textarea,a')) return;
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const row = event.target.closest('[data-connection-key]');
            if (!row) return;
            event.preventDefault();
            row.click();
        });
    }

    document.addEventListener('pointerup', () => {
        window.setTimeout(() => { state.connectionActionPointerActive = false; }, 0);
    }, true);
    document.addEventListener('pointercancel', () => {
        state.connectionActionPointerActive = false;
    }, true);

    function activityClass(type) {
        return {
            key: 'activity-key',
            unkey: 'activity-unkey',
            connect: 'activity-connect',
            disconnect: 'activity-disconnect',
        }[type] || 'activity-connect';
    }

    function activityLabel(type) {
        return {
            key: 'Key',
            unkey: 'Unkey',
            connect: 'Connect',
            disconnect: 'Disconnect',
        }[type] || 'Update';
    }

    function formatActivityTimestamp(value) {
        const time = formatTime(value);
        if (mobileActivityMedia.matches) {
            return time;
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return time;
        }

        return `${time} · ${date.getMonth() + 1}/${date.getDate()}`;
    }

    function activityIdentity(item) {
        const detail = String(item.kind || '') === 'asl'
            ? [item.callsign, item.location].filter(Boolean).join(' — ')
            : [item.callsign, item.description].filter(Boolean).join(' — ');
        return detail || item.node || item.source || 'Connection';
    }

    function activityRenderSignature() {
        const selected = state.selectedType === 'activity' ? state.selectedKey : '';
        return [
            selected,
            mobileActivityMedia.matches ? 'mobile' : 'desktop',
            state.activityExpanded ? 'expanded' : 'recent',
            ...state.activity.map((event) => [
                activityEventKey(event),
                event.type,
                event.node,
                event.callsign,
                event.description,
                event.location,
                event.source,
                event.timestamp,
                event.duration_seconds,
            ].map((value) => String(value || '')).join('|')),
        ].join('~');
    }

    function updateActivityActions() {
        const mobileLimited = mobileActivityMedia.matches && state.activity.length > MOBILE_ACTIVITY_LIMIT;

        if (elements.activityToggle) {
            elements.activityToggle.hidden = !mobileLimited;
            elements.activityToggle.textContent = state.activityExpanded ? 'Show Recent' : 'Show All';
            elements.activityToggle.setAttribute('aria-expanded', state.activityExpanded ? 'true' : 'false');
        }

    }

    function activityMarkup(activity) {
        return activity.map((event) => {
            const identity = activityIdentity(event);
            const activityKind = String(event.kind || '');
            const sourceLabel = activityKind === 'asl'
                ? 'ASL'
                : (activityKind === 'echo' ? '' : String(event.source || '').trim());
            const eventKey = activityEventKey(event);
            const selected = state.selectedType === 'activity' && state.selectedKey === eventKey ? ' is-selected' : '';
            const duration = Number(event.duration_seconds || 0);
            const durationText = event.type === 'unkey' && duration > 0 ? ` · ${duration}s` : '';
            return `
                <button type="button" class="allstar-connect-activity-row${selected}" data-activity-id="${escapeHtml(eventKey)}">
                    <span class="allstar-connect-activity-type ${activityClass(event.type)}">${activityLabel(event.type)}</span>
                    <span class="allstar-connect-activity-main">
                        <strong>${escapeHtml(event.node || event.callsign || identity)}</strong>
                        <span>${escapeHtml(identity)}${sourceLabel ? ` · ${escapeHtml(sourceLabel)}` : ''}${durationText}</span>
                    </span>
                    <time datetime="${escapeHtml(event.timestamp)}">${escapeHtml(formatActivityTimestamp(event.timestamp))}</time>
                </button>`;
        }).join('');
    }

    function renderActivity() {
        const lists = activityLists();
        if (!lists.length) {
            return;
        }

        const signature = activityRenderSignature();
        if (signature === state.activityRenderSignature) {
            return;
        }
        state.activityRenderSignature = signature;

        updateActivityActions();

        if (!state.activity.length) {
            const markup = `
                <div class="allstar-connect-empty allstar-connect-empty-compact">
                    <span class="allstar-connect-empty-icon" aria-hidden="true">&#9889;</span>
                    <strong>No recorded activity yet</strong>
                    <p>Connect, disconnect, key, and unkey changes will be retained here when they occur.</p>
                </div>`;
            for (const list of lists) {
                list.innerHTML = markup;
                list.scrollTop = 0;
            }
            return;
        }

        const visibleActivity = mobileActivityMedia.matches && !state.activityExpanded
            ? state.activity.slice(0, MOBILE_ACTIVITY_LIMIT)
            : state.activity;
        const scrollPositions = new Map(lists.map((list) => [list, list.scrollTop]));

        if (elements.activity) {
            elements.activity.innerHTML = activityMarkup(visibleActivity);
        }
        if (elements.activityExpanded) {
            elements.activityExpanded.innerHTML = activityMarkup(state.activity);
        }
        for (const list of lists) {
            list.scrollTop = scrollPositions.get(list) || 0;
        }
    }

    function handleActivityClick(container, event) {
        const row = event.target.closest('[data-activity-id]');
        if (!row || !container.contains(row)) {
            return;
        }

        const eventKey = String(row.dataset.activityId || '');
        const activity = state.activity.find((item) => activityEventKey(item) === eventKey);
        if (activity) {
            selectActivity(activity);
            syncListSelection(activityLists(), container, 'data-activity-id', eventKey);
        }
    }

    for (const list of activityLists()) {
        list.addEventListener('click', (event) => handleActivityClick(list, event));
    }

    elements.activityToggle?.addEventListener('click', () => {
        state.activityExpanded = !state.activityExpanded;
        state.activityRenderSignature = '';
        renderActivity();
    });

    const handleActivityViewportChange = () => {
        state.activityExpanded = false;
        state.activityRenderSignature = '';
        renderActivity();
    };

    if (typeof mobileActivityMedia.addEventListener === 'function') {
        mobileActivityMedia.addEventListener('change', handleActivityViewportChange);
    } else if (typeof mobileActivityMedia.addListener === 'function') {
        mobileActivityMedia.addListener(handleActivityViewportChange);
    }

    function downstreamCategory(item) {
        if (isDownstreamWebPhoneClient(item)) return 'clients';
        if (Boolean(item?.is_private)) return 'private';
        if (isDownstreamEchoLink(item)) return 'echolink';
        return String(item?.kind || 'asl') === 'asl' ? 'nodes' : '';
    }

    function matchesDownstreamFilter(item) {
        return state.downstreamFilter === 'all' || downstreamCategory(item) === state.downstreamFilter;
    }

    function downstreamFilterCounts(items, includeLocalClients = false) {
        const counts = { all: 0, nodes: 0, privateNodes: 0, clients: 0, echolink: 0 };

        for (const item of items) {
            const category = downstreamCategory(item);
            if (category === 'private') counts.privateNodes++;
            else if (category && category in counts) counts[category]++;
        }

        if (includeLocalClients) {
            counts.clients += state.connections.filter(isRemoteWebPhoneClient).length;
        }

        counts.all = counts.nodes + counts.privateNodes + counts.clients + counts.echolink;
        return counts;
    }

    function selectedBranchFilterCounts() {
        if (state.preferredRemoteClients) {
            return downstreamFilterCounts([], true);
        }

        const selectedDirectNode = String(
            state.preferredDirectNode
            || state.downstreamDirect[0]?.node
            || ''
        ).trim();

        const branchItems = selectedDirectNode
            ? state.downstreamNodes.filter(
                (item) => String(item?.direct_node || '').trim() === selectedDirectNode
            )
            : state.downstreamNodes;

        return downstreamFilterCounts(branchItems, false);
    }

    function updateDownstreamFilters() {
        const dashboardCounts = selectedBranchFilterCounts();
        const expandedCounts = downstreamFilterCounts(state.downstreamNodes, true);

        const valuesFor = (counts) => ({
            all: counts.all,
            nodes: counts.nodes,
            private: counts.privateNodes,
            clients: counts.clients,
            echolink: counts.echolink,
        });

        const dashboardValues = valuesFor(dashboardCounts);
        const expandedValues = valuesFor(expandedCounts);

        for (const [filter, countElements] of Object.entries(elements.downstreamFilterCounts)) {
            for (const count of countElements || []) {
                const values = count.closest('#allstar-connect-downstream-window')
                    ? expandedValues
                    : dashboardValues;
                count.textContent = String(values[filter] || 0);
            }
        }

        for (const button of elements.downstreamFilters) {
            const active = String(button.dataset.downstreamFilter || '') === state.downstreamFilter;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
    }

    function downstreamPath(item) {
        if (!item) return '';
        const directNode = String(item.direct_node || '').trim();
        if (!directNode) {
            const local = state.localNode || 'Local node';
            return `${local} → ${String(item.node || item.callsign || 'Connection')}`;
        }

        const byNode = new Map();
        for (const candidate of state.downstreamNodes) {
            if (String(candidate.direct_node || '') === directNode && String(candidate.kind || 'asl') === 'asl') {
                byNode.set(String(candidate.node || ''), candidate);
            }
        }

        const path = [];
        const visited = new Set();
        let parent = String(item.parent_node || directNode).trim();
        while (parent && !visited.has(parent)) {
            visited.add(parent);
            path.unshift(parent);
            if (parent === directNode) break;
            const parentItem = byNode.get(parent);
            parent = String(parentItem?.parent_node || directNode).trim();
        }
        if (!path.length || path[0] !== directNode) path.unshift(directNode);

        let endpoint = String(item.node || '').trim();
        if (isDownstreamEchoLink(item)) endpoint = String(item.callsign || endpoint).trim();
        if (endpoint && path[path.length - 1] !== endpoint) path.push(endpoint);
        return path.join(' → ');
    }

    function downstreamRenderSignature() {
        const compact = (item) => [
            String(item?.key || ''),
            String(item?.node || ''),
            String(item?.direct_node || ''),
            String(item?.parent_node || ''),
            Number(item?.depth || 0),
            String(item?.kind || ''),
            String(item?.client_type || ''),
            String(item?.callsign || ''),
            String(item?.description || ''),
            String(item?.location || ''),
            String(item?.mode || ''),
            String(item?.mode_label || ''),
            Boolean(item?.is_private),
            Boolean(item?.keyed),
        ];

        return JSON.stringify([
            state.downstreamFilter,
            state.selectedType,
            state.selectedKey,
            state.preferredDirectNode,
            state.preferredRemoteClients,
            state.downstreamDirect.map(compact),
            state.downstreamNodes.map(compact),
            state.connections.filter(isRemoteWebPhoneClient).map(compact),
        ]);
    }

    const DOWNSTREAM_BRANCH_COLORS = ['#4d9cff', '#25c7bd', '#b860ff', '#e451a0', '#f3a541', '#5ac878'];

    function renderDownstreamEmpty(title, detail) {
        const markup = `
            <div class="allstar-connect-empty">
                <span class="allstar-connect-empty-icon" aria-hidden="true">◇</span>
                <strong>${escapeHtml(title)}</strong>
                <p>${escapeHtml(detail)}</p>
            </div>`;
        for (const list of downstreamLists()) list.innerHTML = markup;
    }

    function downstreamSearchText(item) {
        return [item?.node,item?.callsign,item?.description,item?.location,item?.kind,item?.client_type]
            .map((value) => String(value || '').toLowerCase()).join(' ');
    }

    function matchesDownstreamSearch(item) {
        const query = String(state.downstreamSearch || '').trim().toLowerCase();
        return !query || downstreamSearchText(item).includes(query);
    }

    function updateDownstreamBranchControl() {
        const select = elements.downstreamBranch;
        if (!select) return;
        const roots = state.downstreamDirect;
        const remoteCount = state.connections.filter(isRemoteWebPhoneClient).length;
        const hasPreferred = roots.some((root) => String(root.node || '') === state.preferredDirectNode);

        if (state.preferredRemoteClients && remoteCount === 0) {
            state.preferredRemoteClients = false;
        }
        if (!state.preferredRemoteClients && !hasPreferred && roots.length) {
            state.preferredDirectNode = String(roots[0].node || '');
        }

        const currentValue = state.preferredRemoteClients ? '__remote_clients__' : state.preferredDirectNode;
        const options = roots.map((root) => {
            const node = String(root.node || '').trim();
            const callsign = String(root.callsign || '').trim();
            const fullIdentity = [root.callsign, root.description, root.location].filter(Boolean).join(' — ');
            const label = callsign ? `${node} · ${callsign}` : node;
            const fullLabel = fullIdentity ? `${node} · ${fullIdentity}` : label;
            return `<option value="${escapeHtml(node)}" data-full-label="${escapeHtml(fullLabel)}">${escapeHtml(label)}</option>`;
        });
        if (remoteCount > 0) {
            const remoteLabel = `Remote Clients · ${remoteCount}`;
            options.push(`<option value="__remote_clients__" data-full-label="${escapeHtml(remoteLabel)}">${escapeHtml(remoteLabel)}</option>`);
        }
        if (!options.length) options.push('<option value="" data-full-label="Automatic">Automatic</option>');

        const optionsSignature = JSON.stringify([
            roots.map((root) => [
                String(root.node || '').trim(),
                String(root.callsign || '').trim(),
                String(root.description || '').trim(),
                String(root.location || '').trim(),
            ]),
            remoteCount,
        ]);
        const controlActive = document.activeElement === select;

        // Never destroy and rebuild a native selector while the operator has
        // it open. Mobile browsers can flash or strand the picker when the
        // two-second downstream refresh replaces its options.
        if (!controlActive && select.dataset.optionsSignature !== optionsSignature) {
            select.innerHTML = options.join('');
            select.dataset.optionsSignature = optionsSignature;
        }

        if (!controlActive) {
            select.value = currentValue;
            if (!select.value && roots.length) select.value = String(roots[0].node || '');
            const selectedOption = select.options[select.selectedIndex] || null;
            select.title = selectedOption
                ? String(selectedOption.dataset.fullLabel || selectedOption.textContent || '')
                : '';
        }

        select.disabled = roots.length === 0 && remoteCount === 0;
    }

    function syncMobileDownstreamControls() {
        const sourceSelect = elements.downstreamBranch;
        const mobileSelect = elements.downstreamMobileBranch;

        if (sourceSelect && mobileSelect) {
            const controlActive = document.activeElement === mobileSelect;
            const signature = String(
                sourceSelect.dataset.optionsSignature || ''
            );

            if (
                !controlActive
                && mobileSelect.dataset.optionsSignature !== signature
            ) {
                mobileSelect.innerHTML = sourceSelect.innerHTML;
                mobileSelect.dataset.optionsSignature = signature;
            }
            if (!controlActive) {
                mobileSelect.value = sourceSelect.value;
                const selectedOption =
                    mobileSelect.options[mobileSelect.selectedIndex] || null;
                mobileSelect.title = selectedOption
                    ? String(
                        selectedOption.dataset.fullLabel
                        || selectedOption.textContent
                        || ''
                    )
                    : '';
            }
            mobileSelect.disabled = sourceSelect.disabled;
        }

        if (
            elements.downstreamMobileSearch
            && document.activeElement !== elements.downstreamMobileSearch
            && elements.downstreamMobileSearch.value !== state.downstreamSearch
        ) {
            elements.downstreamMobileSearch.value = state.downstreamSearch;
        }
    }

    function downstreamRowIdentity(item) {
        if (Boolean(item?.is_private)) return `Node ${item.node} — Private Node`;
        if (isDownstreamEchoLink(item)) {
            const callsign = String(item.callsign || '').trim();
            return [callsign || `EchoLink node ${item.node}`, echoLinkDescription(callsign), item.location].filter(Boolean).join(' — ');
        }
        if (isDownstreamWebPhoneClient(item)) {
            const callsign = String(item.callsign || webPhoneCallsign(item.node) || '').trim();
            return [callsign, 'Web/Phone Client'].filter(Boolean).join(' — ');
        }
        return [item.callsign, item.description, item.location].filter(Boolean).join(' — ') || `Node ${item.node}`;
    }

    function downstreamTypeClass(item) {
        if (Boolean(item?.is_private)) return ' ac-ds-private';
        if (isDownstreamEchoLink(item)) return ' ac-ds-echolink';
        if (isDownstreamWebPhoneClient(item)) return ' ac-ds-client';
        return '';
    }

    function downstreamTypeChip(item) {
        if (Boolean(item?.is_private)) return '<span class="ac-ds-chip ac-ds-chip-private">Private Node</span>';
        if (isDownstreamEchoLink(item)) return '<span class="ac-ds-chip ac-ds-chip-echo">EchoLink</span>';
        if (isDownstreamWebPhoneClient(item)) return '<span class="ac-ds-chip ac-ds-chip-client">Web/Phone</span>';
        return '';
    }

    function downstreamRowMarkup(item, index, total) {
        const selected = state.selectedType === 'downstream' && state.selectedKey === item.key ? ' is-selected' : '';
        const keyed = Boolean(item.keyed) ? ' is-keyed' : '';
        const branch = index === total - 1 ? '└─' : '├─';
        const depth = Math.max(1, Number(item.depth || 1));
        const depthClass = depth >= 5 ? ' depth-deep' : ` depth-${depth}`;
        const nested = depth >= 2 ? ' is-nested' : '';
        const keyedBadge = item.keyed ? '<span class="ac-ds-keyed">KEYED</span>' : '';
        return `
            <button type="button" class="ac-ds-row${depthClass}${nested}${downstreamTypeClass(item)}${selected}${keyed}" data-downstream-key="${escapeHtml(item.key)}">
                <span class="ac-ds-branch" aria-hidden="true">${branch}</span>
                <span class="ac-ds-dot" aria-hidden="true"></span>
                <span class="ac-ds-main">
                    <strong>${escapeHtml(isDownstreamEchoLink(item) ? (item.callsign || item.node) : item.node)}</strong>
                    <span>${escapeHtml(downstreamRowIdentity(item))}</span>
                </span>
                <span class="ac-ds-state">${keyedBadge}${downstreamTypeChip(item)}</span>
            </button>`;
    }

    function downstreamGroupMarkup(root, index, children) {
        const selected = state.selectedType === 'root' && state.selectedKey === root.key ? ' is-selected' : '';
        const keyed = Boolean(root.keyed) ? ' is-keyed' : '';
        const color = DOWNSTREAM_BRANCH_COLORS[index % DOWNSTREAM_BRANCH_COLORS.length];
        const identity = [root.callsign, root.description, root.location].filter(Boolean).join(' — ') || 'Direct AllStarLink node';
        const keyedBadge = root.keyed ? '<span class="ac-ds-keyed">KEYED</span>' : '';
        const rows = children.length
            ? children.map((item, childIndex) => downstreamRowMarkup(item, childIndex, children.length)).join('')
            : '<div class="ac-ds-none">No downstream children are reported for this direct node.</div>';
        return `
            <section class="ac-ds-group" data-direct-node="${escapeHtml(root.node)}" style="--ds-branch:${color}">
                <button type="button" class="ac-ds-root${selected}${keyed}" data-downstream-root-key="${escapeHtml(root.key)}">
                    <span class="ac-ds-dot" aria-hidden="true"></span>
                    <span class="ac-ds-main"><strong>${escapeHtml(root.node)}</strong><span>${escapeHtml(identity)}</span></span>
                    <span class="ac-ds-state">${keyedBadge}<span class="ac-ds-count">${children.length} ${children.length === 1 ? 'connection' : 'connections'}</span></span>
                </button>
                <div class="ac-ds-children">${rows}</div>
            </section>`;
    }

    function remoteClientsGroupMarkup(clients) {
        const rows = clients.map((item, index) => {
            const selected = state.selectedType === 'current' && state.selectedKey === item.key ? ' is-selected' : '';
            const keyed = item.keyed ? ' is-keyed' : '';
            const branch = index === clients.length - 1 ? '└─' : '├─';
            return `
                <button type="button" class="ac-ds-row ac-ds-client${selected}${keyed}" data-remote-client-key="${escapeHtml(item.key)}">
                    <span class="ac-ds-branch" aria-hidden="true">${branch}</span><span class="ac-ds-dot" aria-hidden="true"></span>
                    <span class="ac-ds-main"><strong>${escapeHtml(item.node)}</strong><span>${escapeHtml(downstreamRowIdentity(item))}</span></span>
                    <span class="ac-ds-state">${item.keyed ? '<span class="ac-ds-keyed">KEYED</span>' : ''}<span class="ac-ds-chip ac-ds-chip-client">Web/Phone</span></span>
                </button>`;
        }).join('');
        return `
            <section class="ac-ds-group ac-ds-remote-group" data-downstream-group="remote-clients" style="--ds-branch:#ff785f">
                <div class="ac-ds-root ac-ds-static"><span class="ac-ds-dot" aria-hidden="true"></span><span class="ac-ds-main"><strong>Remote Clients</strong><span>Connected Web/Phone clients reported by local Asterisk</span></span><span class="ac-ds-state"><span class="ac-ds-count">${clients.length}</span></span></div>
                <div class="ac-ds-children">${rows}</div>
            </section>`;
    }

    function downstreamDashboardScroller() {
        return elements.downstream?.querySelector('.ac-ds-group > .ac-ds-children') || null;
    }

    function downstreamScrollSurfaces() {
        const surfaces = [];
        const dashboard = downstreamDashboardScroller();
        if (dashboard) {
            const group = dashboard.closest('.ac-ds-group');
            surfaces.push({
                name: 'dashboard',
                container: dashboard,
                branch: String(group?.dataset.directNode || group?.dataset.downstreamGroup || ''),
            });
        }
        if (elements.downstreamExpanded && elements.downstreamExpanded.offsetParent !== null) {
            surfaces.push({
                name: 'expanded',
                container: elements.downstreamExpanded,
                branch: '',
            });
        }
        if (mobileDownstreamSheetOpen()) {
            const mobile = elements.downstreamMobile?.querySelector(
                '.ac-ds-group > .ac-ds-children'
            ) || null;
            if (mobile) {
                const group = mobile.closest('.ac-ds-group');
                surfaces.push({
                    name: 'mobile',
                    container: mobile,
                    branch: String(
                        group?.dataset.directNode
                        || group?.dataset.downstreamGroup
                        || ''
                    ),
                });
            }
        }
        return surfaces;
    }

    function downstreamAnchorForRow(row) {
        if (!row) return null;
        if (row.dataset.downstreamRootKey) return { type: 'root', key: String(row.dataset.downstreamRootKey) };
        if (row.dataset.downstreamKey) return { type: 'node', key: String(row.dataset.downstreamKey) };
        if (row.dataset.remoteClientKey) return { type: 'client', key: String(row.dataset.remoteClientKey) };
        return null;
    }

    function findDownstreamAnchorRow(container, anchor) {
        if (!container || !anchor?.key) return null;
        const rows = container.querySelectorAll('[data-downstream-root-key], [data-downstream-key], [data-remote-client-key]');
        for (const row of rows) {
            const candidate = downstreamAnchorForRow(row);
            if (candidate?.type === anchor.type && candidate.key === anchor.key) return row;
        }
        return null;
    }

    function captureDownstreamScrollState() {
        const captured = new Map();
        for (const surface of downstreamScrollSurfaces()) {
            const { container } = surface;
            const viewport = container.getBoundingClientRect();
            let anchor = null;
            const rows = container.querySelectorAll('[data-downstream-root-key], [data-downstream-key], [data-remote-client-key]');
            for (const row of rows) {
                const rect = row.getBoundingClientRect();
                if (rect.bottom <= viewport.top + 1) continue;
                const identity = downstreamAnchorForRow(row);
                if (!identity) continue;
                anchor = {
                    ...identity,
                    offset: rect.top - viewport.top,
                };
                break;
            }
            captured.set(surface.name, {
                branch: surface.branch,
                scrollTop: container.scrollTop,
                scrollLeft: container.scrollLeft,
                anchor,
            });
        }
        return captured;
    }

    function restoreDownstreamScrollState(captured) {
        if (!(captured instanceof Map)) return;
        for (const surface of downstreamScrollSurfaces()) {
            const saved = captured.get(surface.name);
            if (!saved) continue;
            if (surface.name === 'dashboard' && saved.branch !== surface.branch) continue;

            const { container } = surface;
            container.scrollTop = saved.scrollTop;
            container.scrollLeft = saved.scrollLeft;

            const row = findDownstreamAnchorRow(container, saved.anchor);
            if (!row) continue;
            const viewport = container.getBoundingClientRect();
            const currentOffset = row.getBoundingClientRect().top - viewport.top;
            container.scrollTop += currentOffset - Number(saved.anchor.offset || 0);
        }
    }

    function resetDownstreamScroll() {
        for (const surface of downstreamScrollSurfaces()) {
            surface.container.scrollTop = 0;
            surface.container.scrollLeft = 0;
        }
    }

    function renderDownstream() {
        const lists = downstreamLists();
        if (!lists.length) return;
        for (const list of lists) list.setAttribute('aria-busy','false');
        updateDownstreamBranchControl();
        syncMobileDownstreamControls();
        updateDownstreamFilters();

        const signature = JSON.stringify([
            downstreamRenderSignature(),
            state.downstreamSearch,
            state.preferredDirectNode,
            state.preferredRemoteClients,
            mobileDownstreamSheetOpen(),
        ]);
        if (!state.scrollDownstreamOnRender && signature === state.downstreamRenderSignature) return;
        const resetScroll = state.scrollDownstreamOnRender;
        const savedScroll = resetScroll ? null : captureDownstreamScrollState();
        state.downstreamRenderSignature = signature;

        const remoteClients = state.connections.filter(isRemoteWebPhoneClient)
            .filter((item) => (state.downstreamFilter === 'all' || state.downstreamFilter === 'clients') && matchesDownstreamSearch(item));
        const groups = state.downstreamDirect.map((root, index) => {
            const allChildren = orderedDownstreamChildren(String(root.node || ''), state.downstreamNodes.filter((item) => String(item.direct_node || '') === String(root.node || '')));
            const visibleChildren = allChildren.filter((item) => matchesDownstreamFilter(item) && matchesDownstreamSearch(item));
            return { root, index, visibleChildren };
        }).filter((group) => state.downstreamFilter === 'all' || group.visibleChildren.length > 0);

        if (!groups.length && !remoteClients.length) {
            renderDownstreamEmpty('No matching downstream connections','Clear the search or choose another filter.');
            return;
        }

        const preferredGroup = groups.find((group) => String(group.root.node || '') === state.preferredDirectNode) || groups[0] || null;
        if (preferredGroup) state.preferredDirectNode = String(preferredGroup.root.node || '');

        const mainMarkup = state.preferredRemoteClients && remoteClients.length
            ? remoteClientsGroupMarkup(remoteClients)
            : (preferredGroup ? downstreamGroupMarkup(preferredGroup.root, preferredGroup.index, preferredGroup.visibleChildren) : remoteClientsGroupMarkup(remoteClients));
        const expandedGroups = preferredGroup
            ? [preferredGroup, ...groups.filter((group) => group !== preferredGroup)]
            : groups;
        const expandedMarkup = [
            ...(state.preferredRemoteClients && remoteClients.length
                ? [remoteClientsGroupMarkup(remoteClients)]
                : []),
            ...expandedGroups.map((group) => downstreamGroupMarkup(group.root, group.index, group.visibleChildren)),
            ...(!state.preferredRemoteClients && remoteClients.length
                ? [remoteClientsGroupMarkup(remoteClients)]
                : []),
        ].join('');

        if (elements.downstream) elements.downstream.innerHTML = mainMarkup;
        if (elements.downstreamExpanded) elements.downstreamExpanded.innerHTML = expandedMarkup;
        if (elements.downstreamMobile) elements.downstreamMobile.innerHTML = mainMarkup;
        if (resetScroll) {
            state.scrollDownstreamOnRender = false;
            resetDownstreamScroll();
        } else {
            restoreDownstreamScrollState(savedScroll);
        }
    }

    function handleDownstreamClick(container, event) {
        const row = event.target.closest('[data-downstream-root-key], [data-downstream-key], [data-remote-client-key]');
        if (!row || !container.contains(row)) {
            return;
        }

        const rootKey = String(row.dataset.downstreamRootKey || '');
        if (rootKey) {
            const item = state.downstreamDirect.find((entry) => entry.key === rootKey);
            if (item) {
                prioritizeDownstream(item.node);
                selectItem(item, 'root');
                syncDownstreamSelection(container, 'data-downstream-root-key', rootKey);
            }
            return;
        }

        const downstreamKey = String(row.dataset.downstreamKey || '');
        if (downstreamKey) {
            const item = state.downstreamNodes.find((entry) => entry.key === downstreamKey);
            if (item) {
                selectItem(item, 'downstream');
                syncDownstreamSelection(container, 'data-downstream-key', downstreamKey);
            }
            return;
        }

        const clientKey = String(row.dataset.remoteClientKey || '');
        const item = state.connections.find((entry) => entry.key === clientKey);
        if (item) {
            prioritizeRemoteClients();
            selectItem(item, 'current');
            syncDownstreamSelection(container, 'data-remote-client-key', clientKey);
        }
    }

    for (const list of downstreamLists()) {
        list.addEventListener('click', (event) => handleDownstreamClick(list, event));
    }

    function renderDetails(item) {
        if (!item) {
            clearDetails();
            return;
        }

        const kind = String(item.kind || 'asl');
        const rawDirection = String(item.direction || '').trim().toLowerCase();
        let directionLabel = '';
        if (rawDirection === 'incoming' || rawDirection === 'in' || rawDirection.startsWith('in')) {
            directionLabel = 'Incoming';
        } else if (rawDirection === 'outgoing' || rawDirection === 'out' || rawDirection.startsWith('out')) {
            directionLabel = 'Outgoing';
        }
        const isNestedDownstream = Boolean(item.direct_node && String(item.node || '') !== String(item.direct_node || ''));
        const isDownstreamRoot = String(item.key || '').startsWith('downstream-root:');
        const directNode = String(item.direct_node || (isDownstreamRoot ? item.node : '') || '').trim();
        const matchingConnection = directNode
            ? state.connections.find((connection) => String(connection.node || connection.reported_node || '') === directNode)
            : null;
        if (!directionLabel) {
            const matchingDirection = String(matchingConnection?.direction || '').trim().toLowerCase();
            if (isNestedDownstream) directionLabel = 'Downstream';
            else if (matchingDirection.startsWith('in')) directionLabel = 'Incoming';
            else if (matchingDirection.startsWith('out')) directionLabel = 'Outgoing';
            else if (kind === 'client' || kind === 'iax') directionLabel = 'Incoming';
            else directionLabel = 'Not reported';
        }
        const linkLabel = kind === 'echo' ? 'EchoLink' : (kind === 'client' ? 'Web/Phone Client' : (kind === 'iax' ? 'IAX' : 'AllStarLink'));
        const typeLabel = kind === 'echo' ? 'EchoLink Station' : (kind === 'client' ? 'Web/Phone Client' : (kind === 'iax' ? 'IAX Client' : (Boolean(item.is_private) ? 'Private AllStar Node' : 'AllStarLink Node')));
        const modeLabel = String(item.mode_label || (item.mode === 'local_monitor' ? 'Local Monitor' : (item.mode ? 'Transceive' : '—')));
        const connectedTo = String(item.connected_to || item.parent_node || state.localNode || '—');

        if (elements.detailNode) elements.detailNode.textContent = item.node || '—';
        if (elements.detailCall) elements.detailCall.textContent = item.callsign || '—';
        if (elements.detailLocation) elements.detailLocation.textContent = item.location || '—';
        if (elements.detailType) elements.detailType.textContent = typeLabel;
        if (elements.detailDirection) elements.detailDirection.textContent = directionLabel;
        if (elements.detailLink) elements.detailLink.textContent = linkLabel;
        if (elements.detailConnectedTo) elements.detailConnectedTo.textContent = connectedTo;
        const durationItem = isDownstreamRoot && matchingConnection ? matchingConnection : item;
        if (elements.detailDuration) {
            elements.detailDuration.textContent = isNestedDownstream ? '—' : connectionDurationLabel(durationItem);
        }
        if (elements.detailMode) elements.detailMode.textContent = modeLabel;
        const favoriteTarget = favoriteTargetForItem(item);
        const connectCandidate = connectTargetCandidateForItem(item);
        const connectTarget = connectTargetForItem(item);
        const checkingAllStar = Boolean(
            connectCandidate
            && connectCandidate.network === 'ASL'
            && !state.loadIdentityCache.has(connectCandidate.target)
        );
        const savedFavorite = favoriteTarget ? favoriteFor(favoriteNetworkForItem(item), favoriteTarget) : null;
        if (elements.detailFavoriteState) elements.detailFavoriteState.textContent = savedFavorite ? 'Saved' : 'Not saved';

        if (elements.detailPath) {
            if (item.historical) {
                const eventLabel = activityLabel(item.activity_type);
                elements.detailPath.textContent = `Historical ${eventLabel} · ${formatTime(item.activity_timestamp)} · ${item.source}`;
            } else if (item.direct_node) {
                elements.detailPath.textContent = `${downstreamPath(item)} · Depth ${item.depth || 1} · ${modeLabel}`;
            } else if (String(item.key || '').startsWith('downstream-root:')) {
                const local = state.localNode ? `${state.localNode} → ` : '';
                elements.detailPath.textContent = `${local}${item.node} · Direct AllStarLink · ${modeLabel}`;
            } else if (isRemoteWebPhoneClient(item)) {
                elements.detailPath.textContent = `${downstreamPath(item)} · Web/Phone Client · ${modeLabel}`;
            } else {
                elements.detailPath.textContent = `${item.source || linkLabel} · ${modeLabel}${item.direction ? ` · ${item.direction}` : ''}`;
            }
        }

        if (elements.detailDescription) {
            const baseDescription = item.description || item.channel || item.peer || 'No additional description is available.';
            elements.detailDescription.textContent = item.historical && item.activity_type === 'unkey' && Number(item.duration_seconds || 0) > 0
                ? `${baseDescription} · Keyed for ${Number(item.duration_seconds)} seconds.`
                : baseDescription;
        }

        const hasQrz = Boolean(item.qrz_url);
        const canFavorite = canWrite
            && !item.historical
            && !Boolean(item.is_private)
            && ['asl', 'echo'].includes(kind)
            && Boolean(favoriteTarget);
        if (elements.detailQrz) {
            elements.detailQrz.classList.toggle('is-disabled', !hasQrz);
            elements.detailQrz.setAttribute('aria-disabled', hasQrz ? 'false' : 'true');
            if (hasQrz) {
                elements.detailQrz.href = item.qrz_url;
                elements.detailQrz.target = '_blank';
                elements.detailQrz.rel = 'noopener noreferrer';
            } else {
                elements.detailQrz.removeAttribute('href');
                elements.detailQrz.removeAttribute('target');
                elements.detailQrz.removeAttribute('rel');
            }
        }
        if (elements.detailLoad) {
            elements.detailLoad.disabled = !canWrite || !connectTarget;
            elements.detailLoad.hidden = !canWrite;
            elements.detailLoad.title = connectTarget
                ? `Load ${networkDisplay(connectTarget.network)} ${connectTarget.target} into Connect`
                : (checkingAllStar
                    ? 'Checking this node against the local AllStar node database'
                    : 'Only a verified public AllStarLink node or resolved EchoLink node can be loaded');
        }
        if (checkingAllStar) {
            refreshLoadEligibility(item);
        }
        if (elements.detailFavorite) {
            elements.detailFavorite.disabled = !canFavorite;
            elements.detailFavorite.textContent = savedFavorite ? '★ Edit Favorite' : '☆ Add to Favorites';
            elements.detailFavorite.hidden = !canWrite;
        }
        if (elements.detailLinks) elements.detailLinks.hidden = false;
    }

    elements.detailLoad?.addEventListener('click', async () => {
        const item = selectedItem();
        const candidate = connectTargetCandidateForItem(item);
        if (!candidate) return;

        if (candidate.network === 'ASL' && !(await verifyAllStarLoadTarget(candidate.target))) {
            setControlStatus('That number is not a verified public AllStarLink node.', true);
            renderDetails(selectedItem());
            return;
        }

        const target = connectTargetForItem(item);
        if (!target) return;
        loadConnectTarget(target.network, target.target, item, {
            focus: false,
            focusTarget: true,
        });
    });

    elements.detailFavorite?.addEventListener('click', () => {
        const item = selectedItem();
        if (item) openFavoriteModal(item);
    });

    function clearDetails() {
        if (elements.detailNode) elements.detailNode.textContent = '—';
        if (elements.detailCall) elements.detailCall.textContent = 'Select a node';
        if (elements.detailPath) elements.detailPath.textContent = 'Select a row';
        if (elements.detailLocation) elements.detailLocation.textContent = '—';
        if (elements.detailDescription) elements.detailDescription.textContent = 'Select a connection, downstream node, or activity entry to see its details.';
        if (elements.detailType) elements.detailType.textContent = '—';
        if (elements.detailDirection) elements.detailDirection.textContent = '—';
        if (elements.detailLink) elements.detailLink.textContent = '—';
        if (elements.detailConnectedTo) elements.detailConnectedTo.textContent = '—';
        if (elements.detailDuration) elements.detailDuration.textContent = '—';
        if (elements.detailMode) elements.detailMode.textContent = '—';
        if (elements.detailFavoriteState) elements.detailFavoriteState.textContent = '—';
        if (elements.detailQrz) {
            elements.detailQrz.classList.add('is-disabled');
            elements.detailQrz.setAttribute('aria-disabled', 'true');
            elements.detailQrz.removeAttribute('href');
        }
        if (elements.detailLoad) {
            elements.detailLoad.hidden = !canWrite;
            elements.detailLoad.disabled = true;
            elements.detailLoad.title = 'This row does not have a valid connect target';
        }
        if (elements.detailFavorite) {
            elements.detailFavorite.hidden = !canWrite;
            elements.detailFavorite.disabled = true;
        }
        if (elements.detailLinks) elements.detailLinks.hidden = false;
    }

    function unresolvedEchoLinkNodes() {
        const identifiers = new Set();
        for (const item of [...state.connections, ...state.downstreamNodes, ...state.activity]) {
            if (String(item?.kind || '') !== 'echo') continue;

            const callsign = String(item.callsign || '').trim().toUpperCase();
            const callsignKey = echoLinkCallsignKey(callsign);
            if (callsignKey && !state.echoLinkEntries[callsignKey]?.node) {
                identifiers.add(callsign);
                continue;
            }

            const node = echoLinkNodeNumber(item.echolink_node || item.reported_node || item.node);
            if (node && !callsign && !state.echoLinkEntries[node]?.callsign) {
                identifiers.add(node);
            }
        }
        return Array.from(identifiers);
    }

    function applyEchoLinkEntries(entries) {
        if (entries && typeof entries === 'object') {
            state.echoLinkEntries = { ...state.echoLinkEntries, ...entries };
        }
        state.connections = state.connections.map(applyEchoLinkIdentity);
        state.downstreamNodes = state.downstreamNodes.map(applyEchoLinkIdentity);
        state.activity = state.activity.map((item) => String(item?.kind || '') === 'echo' ? applyEchoLinkIdentity(item) : item);
        window.dispatchEvent(new CustomEvent('allstar_connect:connections', { detail: state.connections }));
    }

    function scheduleEchoLinkLookup(delay = 150) {
        if (!echoLinkEndpoint || document.hidden || state.echoLinkLoading || !unresolvedEchoLinkNodes().length) {
            return;
        }

        const wait = Math.max(delay, state.echoLinkNextAllowed - Date.now());
        window.clearTimeout(state.echoLinkTimer);
        state.echoLinkTimer = window.setTimeout(refreshEchoLink, wait);
    }

    async function refreshEchoLink() {
        const lookupNodes = unresolvedEchoLinkNodes();
        if (!echoLinkEndpoint || state.echoLinkLoading || document.hidden || !lookupNodes.length) {
            return;
        }

        state.echoLinkLoading = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 3500);

        try {
            const response = await fetch(`${echoLinkEndpoint}?_=${Date.now()}`, {
                method: 'POST',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nodes: lookupNodes }),
                signal: controller.signal,
            });
            const payload = await response.json();
            if (!response.ok || !payload?.ok || !payload?.data) {
                throw new Error(payload?.message || 'EchoLink identity lookup failed.');
            }

            const pending = Number(payload.data.pending || 0);
            const retrySeconds = Math.max(1, Number(payload.data.retry_after_seconds || 15));
            state.echoLinkNextAllowed = pending > 0 ? Date.now() + retrySeconds * 1000 : 0;
            applyEchoLinkEntries(payload.data.entries || {});
            renderConnections(state.connections);
            renderActivity();
            renderDownstream();
            renderDetails(selectedItem());
            if (payload.data.updated) {
                window.setTimeout(refreshLocal, 100);
            }
            if (pending > 0) {
                scheduleEchoLinkLookup(retrySeconds * 1000);
            }
        } catch (error) {
            state.echoLinkNextAllowed = Date.now() + 30000;
        } finally {
            window.clearTimeout(timeout);
            state.echoLinkLoading = false;
        }
    }

    function renderLocalSnapshot(snapshot) {
        state.localSnapshotLoaded = true;
        const connections = Array.isArray(snapshot.connections) ? snapshot.connections : [];
        const summary = snapshot.summary && typeof snapshot.summary === 'object' ? snapshot.summary : {};
        state.localNode = String(snapshot.node || state.localNode || '').trim();
        const normalizedConnections = connections.map(applyEchoLinkIdentity);
        state.connections = reconcilePendingDisconnects(normalizedConnections, state.connections);
        state.activity = (Array.isArray(snapshot.activity) ? snapshot.activity : [])
            .map((item) => String(item?.kind || '') === 'echo' ? applyEchoLinkIdentity(item) : item);
        window.dispatchEvent(new CustomEvent('allstar_connect:connections', { detail: state.connections }));

        if (state.selectedType === 'current' && !state.connections.some((item) => item.key === state.selectedKey)) {
            state.selectedKey = '';
            state.selectedType = '';
        }
        if (state.selectedType === 'activity' && !state.activity.some((item) => activityEventKey(item) === state.selectedKey)) {
            state.selectedKey = '';
            state.selectedType = '';
        }
        if (state.preferredDirectNode && !connections.some((item) => item.kind === 'asl' && String(item.node) === state.preferredDirectNode)) {
            state.preferredDirectNode = '';
            state.scrollDownstreamOnRender = false;
        }
        if (state.preferredRemoteClients && !connections.some(isRemoteWebPhoneClient)) {
            state.preferredRemoteClients = false;
            state.scrollDownstreamOnRender = false;
        }
        if (!state.selectedKey && connections.length) {
            state.selectedKey = connections[0].key;
            state.selectedType = 'current';
        }

        renderActivity();
        renderConnections(state.connections);
        renderDownstream();
        updateDownstreamSummary();
        renderDetails(selectedItem());

        renderSystemStatus(snapshot.system);
        scheduleEchoLinkLookup();
    }

    function updateDownstreamSummary() {
        const summary = state.downstreamSummary && typeof state.downstreamSummary === 'object' ? state.downstreamSummary : {};
        const cache = state.downstreamCache && typeof state.downstreamCache === 'object' ? state.downstreamCache : {};
        const publicCount = Number(summary.downstream || 0);
        const privateCount = Number(summary.private || 0);
        const downstreamClientCount = Number(summary.remote_clients || 0);
        const echoCount = Number(summary.echolink || 0);
        const localClientCount = state.connections.filter(isRemoteWebPhoneClient).length;
        const clientCount = downstreamClientCount + localClientCount;
        const total = publicCount + privateCount + clientCount + echoCount;

        if (elements.downstreamCount) {
            elements.downstreamCount.textContent = String(total);
        }
        if (elements.downstreamMobileCount) {
            elements.downstreamMobileCount.textContent = String(total);
        }
        if (!elements.downstreamNote) {
            return;
        }

        const hidden = Number(summary.hidden || 0);
        if (!state.downstreamDirect.length) {
            elements.downstreamNote.textContent = localClientCount > 0
                ? `${localClientCount} ${localClientCount === 1 ? 'client' : 'clients'} · no public tree`
                : 'Waiting for direct AllStarLink';
            return;
        }

        const selectedText = state.preferredRemoteClients
            ? 'Remote Clients'
            : (state.preferredDirectNode ? `Branch ${state.preferredDirectNode}` : 'Selected branch');
        const searchText = state.downstreamSearch ? ` · Search: “${state.downstreamSearch}”` : '';
        let statusText;

        if (!cache.updated_at) {
            statusText = cache.refreshing
                ? (cache.pending > 0 ? `Scanning · ${cache.pending} queued` : 'Finishing scan')
                : 'Waiting for first cached result';
        } else {
            statusText = hidden > 0 ? `Tree ready · ${hidden} filtered` : 'Tree ready';
        }

        elements.downstreamNote.textContent = `${selectedText} · ${statusText}${searchText}`;
    }

    function renderDownstreamSnapshot(snapshot) {
        const summary = snapshot.summary && typeof snapshot.summary === 'object' ? snapshot.summary : {};
        const cache = snapshot.cache && typeof snapshot.cache === 'object' ? snapshot.cache : {};
        state.downstreamSummary = summary;
        state.downstreamCache = cache;
        state.downstreamNodes = (Array.isArray(snapshot.nodes) ? snapshot.nodes : []).map(applyEchoLinkIdentity);
        state.downstreamDirect = Array.isArray(snapshot.direct) ? snapshot.direct : [];

        if (state.selectedType === 'downstream' && !state.downstreamNodes.some((item) => item.key === state.selectedKey)) {
            state.selectedKey = '';
            state.selectedType = '';
        }
        if (state.selectedType === 'root' && !state.downstreamDirect.some((item) => item.key === state.selectedKey)) {
            state.selectedKey = '';
            state.selectedType = '';
        }
        if (!state.selectedKey && !state.connections.length) {
            const first = state.downstreamNodes[0] || state.downstreamDirect[0];
            if (first) {
                state.selectedKey = first.key;
                state.selectedType = first.direct_node ? 'downstream' : 'root';
            }
        }

        updateDownstreamSummary();

        renderDownstream();
        renderDetails(selectedItem());
        scheduleEchoLinkLookup();
    }


    const LOCAL_OFFLINE_FAILURES = 5;

    function setNodeOffline(offline) {
        const indicator = document.getElementById('allstar-connect-node-offline');
        if (indicator) {
            indicator.hidden = !offline;
        }
    }

    function noteLocalSuccess() {
        state.localFailureCount = 0;
        setNodeOffline(false);
    }

    function noteLocalFailure() {
        state.localFailureCount += 1;
        if (state.localFailureCount >= LOCAL_OFFLINE_FAILURES) {
            setNodeOffline(true);
        }
    }

    function resetSuspendedRequests() {
        state.localController?.abort();
        state.downstreamController?.abort();
        state.localController = null;
        state.downstreamController = null;
        state.localLoading = false;
        state.downstreamLoading = false;
    }

    async function refreshLocal() {
        if (state.localLoading || document.hidden) {
            return;
        }
        state.localLoading = true;
        const controller = new AbortController();
        state.localController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 2500);

        try {
            const response = await fetch(`${localEndpoint}?_=${Date.now()}`, {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();
            if (!response.ok || !payload?.ok || !payload?.data) {
                throw new Error(payload?.message || 'Local status request failed.');
            }
            noteLocalSuccess();
            renderLocalSnapshot(payload.data);
        } catch (error) {
            if (state.localController !== controller) {
                return;
            }
            noteLocalFailure();
            if (error?.name === 'AbortError' && state.localSnapshotLoaded) {
                return;
            }
            // Keep the last successful snapshot and retry quietly.
        } finally {
            window.clearTimeout(timeout);
            if (state.localController === controller) {
                state.localController = null;
                state.localLoading = false;
            }
        }
    }

    async function refreshDownstream() {
        if (!downstreamEndpoint || state.downstreamLoading || document.hidden) {
            return;
        }
        state.downstreamLoading = true;
        const controller = new AbortController();
        state.downstreamController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 3500);

        try {
            const response = await fetch(`${downstreamEndpoint}?_=${Date.now()}`, {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();
            if (!response.ok || !payload?.ok || !payload?.data) {
                throw new Error(payload?.message || 'Downstream status request failed.');
            }
            renderDownstreamSnapshot(payload.data);
        } catch (error) {
            if (state.downstreamController !== controller) {
                return;
            }
            // Keep the last successful tree and retry quietly.
        } finally {
            window.clearTimeout(timeout);
            if (state.downstreamController === controller) {
                state.downstreamController = null;
                state.downstreamLoading = false;
            }
        }
    }

    for (const button of elements.downstreamFilters) {
        button.addEventListener('click', () => {
            const filter = String(button.dataset.downstreamFilter || 'all');
            if (!['all', 'nodes', 'private', 'clients', 'echolink'].includes(filter) || filter === state.downstreamFilter) {
                return;
            }
            state.downstreamFilter = filter;
            state.scrollDownstreamOnRender = true;
            renderDownstream();
        });
    }

    elements.downstreamBranch?.addEventListener('change', () => {
        const value = String(elements.downstreamBranch.value || '');
        state.preferredRemoteClients = value === '__remote_clients__';
        state.preferredDirectNode = state.preferredRemoteClients ? '' : value;
        state.scrollDownstreamOnRender = true;
        renderDownstream();
    });

    elements.downstreamMobileBranch?.addEventListener('change', () => {
        const value = String(elements.downstreamMobileBranch.value || '');
        state.preferredRemoteClients = value === '__remote_clients__';
        state.preferredDirectNode = state.preferredRemoteClients ? '' : value;
        if (
            elements.downstreamBranch
            && document.activeElement !== elements.downstreamBranch
        ) {
            elements.downstreamBranch.value = value;
        }
        state.scrollDownstreamOnRender = true;
        renderDownstream();
    });

    elements.downstreamSearch?.addEventListener('input', () => {
        state.downstreamSearch = String(elements.downstreamSearch.value || '').trim();
        if (
            elements.downstreamMobileSearch
            && document.activeElement !== elements.downstreamMobileSearch
        ) {
            elements.downstreamMobileSearch.value = state.downstreamSearch;
        }
        state.scrollDownstreamOnRender = true;
        renderDownstream();
    });

    elements.downstreamMobileSearch?.addEventListener('input', () => {
        state.downstreamSearch = String(
            elements.downstreamMobileSearch.value || ''
        ).trim();
        if (
            elements.downstreamSearch
            && document.activeElement !== elements.downstreamSearch
        ) {
            elements.downstreamSearch.value = state.downstreamSearch;
        }
        state.scrollDownstreamOnRender = true;
        renderDownstream();
    });

    function updateCurrentTime() {
        if (!elements.currentTime) return;
        const now = new Date();
        elements.currentTime.textContent = formatCurrentDateTime(now);
        elements.currentTime.closest('.ac-system-pill')?.setAttribute(
            'title',
            `Local time: ${now.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}`
        );
    }

    function startClock() {
        window.clearInterval(state.clockTimer);
        updateCurrentTime();
        state.clockTimer = window.setInterval(updateCurrentTime, 60000);
    }

    function resumeLivePolling() {
        if (document.hidden) {
            return;
        }

        const now = Date.now();
        const wasSuspended = now - state.lastLocalPollTick > 5000;
        state.lastLocalPollTick = now;

        if (wasSuspended) {
            resetSuspendedRequests();
        }

        updateCurrentTime();
        refreshLocal();
        refreshDownstream();
        scheduleEchoLinkLookup();
        schedule();
    }

    function localPollTick() {
        const now = Date.now();
        if (now - state.lastLocalPollTick > 5000) {
            resumeLivePolling();
            return;
        }

        state.lastLocalPollTick = now;
        refreshLocal();
    }

    function schedule() {
        window.clearInterval(state.localTimer);
        window.clearInterval(state.downstreamTimer);
        state.localTimer = window.setInterval(localPollTick, 1000);
        state.downstreamTimer = window.setInterval(refreshDownstream, 2000);
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            resumeLivePolling();
        }
    });
    window.addEventListener('focus', resumeLivePolling);
    window.addEventListener('pageshow', resumeLivePolling);
    window.addEventListener('online', resumeLivePolling);

    startClock();
    refreshFavorites();
    refreshLocal();
    window.setTimeout(refreshDownstream, 800);
    schedule();
})();
