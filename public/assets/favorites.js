(() => {
    'use strict';

    const page = document.querySelector('.ac-favorites-page');
    if (!page) return;

    const endpoint = String(page.dataset.favoritesEndpoint || '').trim();
    const identityEndpoint = String(page.dataset.identityEndpoint || '').trim();
    const csrfToken = String(page.dataset.csrfToken || '').trim();
    const canWrite = page.dataset.canWrite === '1';
    const elements = {
        count: document.getElementById('favorites-count'),
        search: document.getElementById('favorites-search'),
        callsignResults: document.getElementById('favorites-callsign-results'),
        body: document.getElementById('favorites-table-body'),
        add: document.getElementById('favorites-add'),
        form: document.getElementById('favorite-editor-form'),
        network: document.getElementById('favorite-network'),
        target: document.getElementById('favorite-target'),
        name: document.getElementById('favorite-name'),
        description: document.getElementById('favorite-description'),
        clear: document.getElementById('favorite-editor-clear'),
        title: document.getElementById('favorite-editor-title'),
        helper: document.getElementById('favorite-editor-helper'),
        status: document.getElementById('favorite-page-status'),
        modal: document.getElementById('favorites-editor-modal'),
        close: document.getElementById('favorite-editor-close'),
    };

    const state = {
        items: [],
        sortKey: 'target',
        sortDirection: 'asc',
        originalNetwork: '',
        originalTarget: '',
        lookupTimer: 0,
        lookupController: null,
        lookupKey: '',
        callsignLookupController: null,
        callsignLookupTimer: 0,
        callsignMatches: [],
        editorDirty: false,
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
        }[character]));
    }

    function cleanTarget(value) {
        return String(value || '').replace(/\D/g, '');
    }

    function networkForTarget(network, target) {
        const clean = cleanTarget(target);
        return /^3\d{6}$/.test(clean) ? 'ECHO' : (String(network || '').toUpperCase() === 'ECHO' ? 'ECHO' : 'ASL');
    }

    function privateAllStarTarget(target) {
        return /^1\d{3}$/.test(cleanTarget(target));
    }

    function validTarget(network, target) {
        const clean = cleanTarget(target);
        return networkForTarget(network, clean) === 'ECHO'
            ? /^3\d{6}$/.test(clean)
            : /^\d{1,7}$/.test(clean) && !privateAllStarTarget(clean);
    }

    function networkLabel(value) {
        return String(value || '').toUpperCase() === 'ECHO' ? 'EchoLink' : 'AllStarLink';
    }

    function favoriteKey(network, target) {
        return `${networkForTarget(network, target)}:${cleanTarget(target)}`;
    }

    function findFavorite(network, target) {
        const key = favoriteKey(network, target);
        return state.items.find((item) => favoriteKey(item.network, item.target) === key) || null;
    }

    function identityDescription(identity) {
        return [identity?.description, identity?.location]
            .map((value) => String(value || '').trim())
            .filter((value, index, values) => value !== '' && values.indexOf(value) === index)
            .join(' — ');
    }

    function setStatus(message, isError = false) {
        if (!elements.status) return;
        elements.status.textContent = String(message || '');
        elements.status.classList.toggle('is-error', Boolean(isError));
    }

    function setEditorHeading(title, helper) {
        if (elements.title) elements.title.textContent = title;
        if (elements.helper) elements.helper.textContent = helper;
    }

    function openEditor(options = {}) {
        if (!elements.modal) return;
        elements.modal.hidden = false;
        elements.modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ac-modal-open');

        window.requestAnimationFrame(() => {
            if (options.focusName) {
                elements.name?.focus({ preventScroll: true });
                elements.name?.select();
            } else if (options.focusTarget) {
                elements.target?.focus({ preventScroll: true });
                elements.target?.select();
            }
        });
    }

    function closeEditor() {
        if (elements.modal) {
            elements.modal.hidden = true;
            elements.modal.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('ac-modal-open');
        clearEditor();
    }

    function compareTargets(left, right) {
        const a = String(left || '');
        const b = String(right || '');
        if (/^\d+$/.test(a) && /^\d+$/.test(b)) return Number(a) - Number(b);
        return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    }

    function visibleItems() {
        const query = String(elements.search?.value || '').trim().toLowerCase();
        const items = state.items.filter((item) => !query || [item.target, item.network, item.name, item.description]
            .some((value) => String(value || '').toLowerCase().includes(query)));
        const direction = state.sortDirection === 'desc' ? -1 : 1;
        return items.sort((left, right) => {
            const key = state.sortKey;
            const result = key === 'target'
                ? compareTargets(left.target, right.target)
                : String(left[key] || '').localeCompare(String(right[key] || ''), undefined, { numeric: true, sensitivity: 'base' });
            return result * direction;
        });
    }

    function updateSortIndicators() {
        for (const button of document.querySelectorAll('.ac-favorites-sort-button[data-sort]')) {
            const key = String(button.dataset.sort || '');
            const active = key === state.sortKey;
            const indicator = button.querySelector('.ac-sort-indicator');
            button.setAttribute('aria-sort', active ? (state.sortDirection === 'desc' ? 'descending' : 'ascending') : 'none');
            if (indicator) indicator.textContent = active ? (state.sortDirection === 'desc' ? '▼' : '▲') : '↕';
        }
    }

    function render() {
        const items = visibleItems();
        updateSortIndicators();
        if (elements.count) elements.count.textContent = String(state.items.length);
        updateNodeAddButton();
        if (!elements.body) return;

        if (
            elements.callsignResults
            && !elements.callsignResults.hidden
            && state.callsignMatches.length
        ) {
            const callsign = exactSearchCallsign();
            if (callsign) {
                showFavoriteCallsignMatches(callsign, state.callsignMatches);
            }
        }

        if (!items.length) {
            const searchTarget = exactSearchTarget();
            const message = searchTarget
                ? `Node ${escapeHtml(searchTarget)} is not saved. Click Add Node to add it.`
                : (state.items.length ? 'No Favorites match the search.' : 'No Favorites saved yet.');
            elements.body.innerHTML = `<tr><td colspan="5">${message}</td></tr>`;
            return;
        }
        elements.body.innerHTML = items.map((item) => `
            <tr data-network="${escapeHtml(item.network)}" data-target="${escapeHtml(item.target)}">
                <td><button type="button" class="ac-favorite-row-edit" data-edit-favorite>${escapeHtml(item.target)}</button></td>
                <td><span class="ac-favorite-network-badge is-${String(item.network || 'ASL').toLowerCase()}">${escapeHtml(networkLabel(item.network))}</span></td>
                <td>${escapeHtml(item.name)}</td>
                <td>${escapeHtml(item.description)}</td>
                <td class="ac-favorite-table-actions">
                    <a class="ac-small-button" href="/allstar_connect/public/?network=${encodeURIComponent(item.network)}&target=${encodeURIComponent(item.target)}">Load</a>
                    <button type="button" class="ac-small-button" data-edit-favorite>Edit</button>
                    <button type="button" class="ac-small-button is-danger" data-delete-favorite ${canWrite ? '' : 'disabled'}>Remove</button>
                </td>
            </tr>`).join('');
    }

    function editItem(item, focusName = false) {
        if (!item) return;
        const network = networkForTarget(item.network, item.target);
        elements.network.value = network;
        elements.target.value = cleanTarget(item.target);
        elements.name.value = String(item.name || '');
        elements.description.value = String(item.description || '');
        state.originalNetwork = network;
        state.originalTarget = cleanTarget(item.target);
        state.lookupKey = favoriteKey(network, item.target);
        state.editorDirty = false;
        setEditorHeading('Edit Favorite', `${networkLabel(network)} ${elements.target.value} is already saved.`);
        setStatus('Change the details or leave them as shown, then update the Favorite.');
        openEditor({ focusName });
    }

    function clearEditor() {
        window.clearTimeout(state.lookupTimer);
        state.lookupController?.abort();
        state.lookupController = null;
        state.lookupKey = '';
        state.editorDirty = false;
        if (elements.network) elements.network.value = 'ASL';
        if (elements.target) elements.target.value = '';
        if (elements.name) elements.name.value = '';
        if (elements.description) elements.description.value = '';
        state.originalNetwork = '';
        state.originalTarget = '';
        setEditorHeading('Add Favorite', 'Enter a node number in Target. To find stations by callsign, use Search Favorites or Find a Station.');
        setStatus(canWrite ? 'Ready' : 'View only - login to make changes.');
    }

    async function lookupIdentity(network, target) {
        if (!identityEndpoint) return null;
        state.lookupController?.abort();
        const controller = new AbortController();
        state.lookupController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 4500);
        try {
            const url = new URL(identityEndpoint, window.location.href);
            const networkCode = String(network || '').toUpperCase() === 'ECHO'
                ? 'ECHO'
                : networkForTarget(network, target);
            const lookupTarget = networkCode === 'ECHO'
                ? String(target || '').trim().toUpperCase()
                : cleanTarget(target);
            url.searchParams.set('network', networkCode);
            url.searchParams.set('target', lookupTarget);
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
            if (state.lookupController === controller) state.lookupController = null;
        }
    }

    async function prefillTarget(network, target, options = {}) {
        const clean = cleanTarget(target);
        const networkCode = networkForTarget(network, clean);
        if (!validTarget(networkCode, clean)) return false;

        const key = favoriteKey(networkCode, clean);
        const changed = state.lookupKey !== key;
        if (changed) {
            state.lookupKey = key;
            state.editorDirty = false;
            state.originalNetwork = '';
            state.originalTarget = '';
            if (elements.name) elements.name.value = '';
            if (elements.description) elements.description.value = '';
        }

        if (elements.network) elements.network.value = networkCode;
        if (elements.target) elements.target.value = clean;

        const existing = findFavorite(networkCode, clean);
        if (existing) {
            editItem(existing, Boolean(options.focusName));
            return true;
        }

        setEditorHeading('Add Favorite', `Looking up ${networkLabel(networkCode)} ${clean}…`);
        setStatus(`Looking up ${networkLabel(networkCode)} database information…`);
        try {
            const identity = options.identity || await lookupIdentity(networkCode, clean);
            if (state.lookupKey !== key) return false;
            if (!state.editorDirty) {
                const callsign = String(identity?.callsign || '').trim();
                const description = identityDescription(identity);
                if (elements.name) elements.name.value = callsign || clean;
                if (elements.description) elements.description.value = description;
            }
            if (identity?.found) {
                setEditorHeading('Add Favorite', `${networkLabel(networkCode)} details were filled automatically. Change anything you want before saving.`);
                setStatus('Database information loaded.');
            } else {
                setEditorHeading('Add Favorite', `No database details were found for ${networkLabel(networkCode)} ${clean}. Enter the name and description manually.`);
                setStatus('No database details were found.', true);
            }
            if (options.focusName) {
                elements.name?.focus();
                elements.name?.select();
            }
            return true;
        } catch (error) {
            if (error?.name === 'AbortError' || state.lookupKey !== key) return false;
            setEditorHeading('Add Favorite', error?.message || 'Node identity lookup failed. Enter the details manually.');
            setStatus(error?.message || 'Node identity lookup failed.', true);
            return false;
        }
    }

    function schedulePrefill(network, target, options = {}) {
        window.clearTimeout(state.lookupTimer);
        const clean = cleanTarget(target);
        const networkCode = networkForTarget(network, clean);
        if (!validTarget(networkCode, clean)) return;
        state.lookupTimer = window.setTimeout(() => {
            prefillTarget(networkCode, clean, options);
        }, Number(options.delay ?? 320));
    }

    function exactSearchTarget() {
        const raw = String(elements.search?.value || '').trim();
        if (!/^\d{1,7}$/.test(raw)) return '';
        return cleanTarget(raw);
    }

    function updateNodeAddButton() {
        if (!elements.add) return;

        const raw = String(elements.search?.value || '').trim();
        const target = exactSearchTarget();
        const existing = target
            ? findFavorite(networkForTarget('ASL', target), target)
            : null;

        elements.add.disabled = !canWrite || (raw !== '' && !target);
        elements.add.textContent = existing ? 'Edit Saved' : 'Add Node';
        elements.add.title = raw !== '' && !target
            ? 'Use the station results below to add a callsign.'
            : existing
                ? 'Edit this saved Favorite.'
                : 'Add a Favorite by node number.';
    }

    function exactSearchCallsign() {
        const raw = String(elements.search?.value || '').trim().toUpperCase();
        return (
            raw.length <= 32
            && /^[A-Z0-9*_.\/-]+$/.test(raw)
            && /[A-Z*]/.test(raw)
            && (/\d/.test(raw) || raw.startsWith('*'))
        ) ? raw : '';
    }

    function closeFavoriteCallsignResults() {
        state.callsignMatches = [];
        if (!elements.callsignResults) return;
        elements.callsignResults.replaceChildren();
        elements.callsignResults.hidden = true;
    }

    function showFavoriteCallsignMessage(message, isError = false) {
        if (!elements.callsignResults) return;
        state.callsignMatches = [];
        elements.callsignResults.replaceChildren();

        const status = document.createElement('div');
        status.className = `ac-control-status${isError ? ' is-error' : ''}`;
        status.textContent = String(message || '');
        elements.callsignResults.appendChild(status);
        elements.callsignResults.hidden = false;
    }

    async function searchFavoriteCallsignNetwork(network, callsign, signal) {
        const networkCode = String(network || '').toUpperCase() === 'ECHO' ? 'ECHO' : 'ASL';
        const url = new URL(identityEndpoint, window.location.href);
        url.searchParams.set('network', networkCode);
        url.searchParams.set('target', callsign);
        url.searchParams.set('search', 'callsign');
        url.searchParams.set('_', String(Date.now()));

        const response = await fetch(url.toString(), {
            cache: 'no-store',
            credentials: 'same-origin',
            signal,
        });
        const payload = await response.json().catch(() => ({}));

        if (response.status === 404 || response.status === 422) return [];
        if (!response.ok || !payload?.ok) {
            throw new Error(payload?.message || `${networkLabel(networkCode)} callsign lookup failed.`);
        }

        if (Array.isArray(payload.matches)) return payload.matches;
        return payload.identity && typeof payload.identity === 'object'
            ? [payload.identity]
            : [];
    }

    function showFavoriteCallsignMatches(callsign, matches) {
        if (!elements.callsignResults) return;

        state.callsignMatches = matches;
        elements.callsignResults.replaceChildren();

        const heading = document.createElement('div');
        heading.className = 'ac-control-status';
        heading.textContent = `Stations found for ${callsign}`;
        elements.callsignResults.appendChild(heading);

        for (const match of matches) {
            const network = String(match?.network || '').toUpperCase() === 'ECHO' ? 'ECHO' : 'ASL';
            const target = cleanTarget(match?.target);
            if (!validTarget(network, target)) continue;

            const call = String(match?.callsign || callsign || '').trim().toUpperCase();
            const details = identityDescription(match);
            const saved = Boolean(findFavorite(network, target));

            const result = document.createElement('div');
            result.className = 'ac-callsign-result is-static';

            const title = document.createElement('strong');
            title.textContent = `${networkLabel(network)} ${target} — ${call}`;
            result.appendChild(title);

            if (details) {
                const detail = document.createElement('span');
                detail.textContent = details;
                result.appendChild(detail);
            }

            const actions = document.createElement('div');
            actions.className = 'ac-favorite-table-actions';

            const action = document.createElement('button');
            action.type = 'button';
            action.className = 'ac-small-button';
            action.dataset.callsignNetwork = network;
            action.dataset.callsignTarget = target;
            action.textContent = saved
                ? 'Edit Saved Favorite'
                : '+ Add Favorite';

            actions.appendChild(action);
            result.appendChild(actions);
            elements.callsignResults.appendChild(result);
        }

        elements.callsignResults.hidden =
            elements.callsignResults.childElementCount <= 1;
    }

    async function openFavoriteCallsignMatch(match) {
        const network = String(match?.network || '').toUpperCase() === 'ECHO' ? 'ECHO' : 'ASL';
        const target = cleanTarget(match?.target);
        if (!validTarget(network, target)) return;

        const existing = findFavorite(network, target);

        closeFavoriteCallsignResults();
        clearEditor();

        if (elements.search) elements.search.value = '';
        updateNodeAddButton();
        render();

        if (existing) {
            editItem(existing, true);
            return;
        }

        openEditor();

        await prefillTarget(network, target, {
            focusName: true,
            identity: match,
        });
    }

    async function searchFavoriteCallsign(callsign) {
        if (!identityEndpoint) {
            showFavoriteCallsignMessage('Callsign lookup is unavailable.', true);
            return;
        }

        state.callsignLookupController?.abort();
        const controller = new AbortController();
        state.callsignLookupController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 6000);

        showFavoriteCallsignMessage(
            `Looking up AllStarLink and EchoLink matches for ${callsign}…`
        );

        try {
            const results = await Promise.allSettled([
                searchFavoriteCallsignNetwork('ASL', callsign, controller.signal),
                searchFavoriteCallsignNetwork('ECHO', callsign, controller.signal),
            ]);

            if (controller.signal.aborted || state.callsignLookupController !== controller) return;

            const successfulMatches = results.flatMap((result) =>
                result.status === 'fulfilled' ? result.value : []
            );

            const failures = results.filter((result) =>
                result.status === 'rejected'
                && result.reason?.name !== 'AbortError'
            );

            const unique = new Map();
            for (const match of successfulMatches) {
                const network = String(match?.network || '').toUpperCase() === 'ECHO' ? 'ECHO' : 'ASL';
                const target = cleanTarget(match?.target);
                if (!validTarget(network, target)) continue;
                unique.set(`${network}:${target}`, { ...match, network, target });
            }

            const matches = Array.from(unique.values());

            if (matches.length === 0) {
                if (failures.length > 0 && failures[0]?.reason) {
                    throw failures[0].reason;
                }

                showFavoriteCallsignMessage(
                    `No AllStarLink or EchoLink matches were found for ${callsign}.`,
                    true
                );
                return;
            }

            showFavoriteCallsignMatches(callsign, matches);
        } catch (error) {
            if (error?.name === 'AbortError') return;
            showFavoriteCallsignMessage(
                error?.message || 'Callsign lookup failed.',
                true
            );
        } finally {
            window.clearTimeout(timeout);
            if (state.callsignLookupController === controller) {
                state.callsignLookupController = null;
            }
        }
    }

    async function refresh() {
        if (!endpoint) return;
        try {
            const response = await fetch(`${endpoint}?_=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Unable to load Favorites.');
            state.items = Array.isArray(payload.favorites) ? payload.favorites : [];
            render();
        } catch (error) {
            setStatus(error.message || 'Unable to load Favorites.', true);
        }
    }

    async function postFavorite(payload) {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            cache: 'no-store',
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result?.ok) throw new Error(result?.message || 'Favorite action failed.');
        state.items = Array.isArray(result.favorites) ? result.favorites : state.items;
        render();
        return result;
    }

    elements.search?.addEventListener('input', () => {
        window.clearTimeout(state.callsignLookupTimer);
        state.callsignLookupTimer = 0;
        state.callsignLookupController?.abort();
        closeFavoriteCallsignResults();
        updateNodeAddButton();
        render();

        const callsign = exactSearchCallsign();
        if (callsign.length < 3) return;

        state.callsignLookupTimer = window.setTimeout(() => {
            state.callsignLookupTimer = 0;
            searchFavoriteCallsign(callsign);
        }, 500);
    });

    elements.search?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;

        const target = exactSearchTarget();
        const callsign = exactSearchCallsign();
        if (!target && !callsign) return;

        event.preventDefault();
        window.clearTimeout(state.callsignLookupTimer);
        state.callsignLookupTimer = 0;

        if (callsign) {
            searchFavoriteCallsign(callsign);
            return;
        }

        elements.add?.click();
    });

    elements.callsignResults?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-callsign-target]');
        if (!button) return;

        const network = String(button.dataset.callsignNetwork || '').toUpperCase();
        const target = cleanTarget(button.dataset.callsignTarget);
        const match = state.callsignMatches.find((item) =>
            String(item?.network || '').toUpperCase() === network
            && cleanTarget(item?.target) === target
        );

        if (match) await openFavoriteCallsignMatch(match);
    });

    for (const button of document.querySelectorAll('[data-sort]')) {
        button.addEventListener('click', () => {
            const key = String(button.dataset.sort || 'target');
            if (state.sortKey === key) state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
            else { state.sortKey = key; state.sortDirection = 'asc'; }
            render();
        });
    }

    elements.add?.addEventListener('click', async () => {
        const target = exactSearchTarget();

        if (target) {
            window.clearTimeout(state.callsignLookupTimer);
            state.callsignLookupTimer = 0;
            state.callsignLookupController?.abort();
            closeFavoriteCallsignResults();
            clearEditor();
            if (elements.search) elements.search.value = '';
            updateNodeAddButton();
            render();
            openEditor();
            await prefillTarget(
                networkForTarget('ASL', target),
                target,
                { focusName: true }
            );
            return;
        }

        state.callsignLookupController?.abort();
        closeFavoriteCallsignResults();
        clearEditor();
        openEditor({ focusTarget: true });
    });

    elements.clear?.addEventListener('click', closeEditor);
    elements.close?.addEventListener('click', closeEditor);
    elements.modal?.addEventListener('click', (event) => {
        if (event.target === elements.modal) closeEditor();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        if (elements.modal && !elements.modal.hidden) {
            event.preventDefault();
            closeEditor();
            return;
        }

        const hadSearch = Boolean(elements.search?.value);
        const hadResults = Boolean(
            elements.callsignResults && !elements.callsignResults.hidden
        );

        window.clearTimeout(state.callsignLookupTimer);
        state.callsignLookupTimer = 0;
        state.callsignLookupController?.abort();
        closeFavoriteCallsignResults();

        if (hadSearch || hadResults) {
            event.preventDefault();
            if (elements.search) elements.search.value = '';
            updateNodeAddButton();
            render();
        }
    });

    elements.body?.addEventListener('click', async (event) => {
        const row = event.target.closest('tr[data-target]');
        if (!row) return;
        const item = state.items.find((entry) => favoriteKey(entry.network, entry.target) === favoriteKey(row.dataset.network, row.dataset.target));
        if (!item) return;
        if (event.target.closest('[data-edit-favorite]')) {
            editItem(item, true);
            return;
        }
        if (event.target.closest('[data-delete-favorite]')) {
            if (!canWrite || !window.confirm(`Remove ${networkLabel(item.network)} ${item.target} from Favorites?`)) return;
            try {
                setStatus('Removing Favorite…');
                const result = await postFavorite({ action: 'delete', target: item.target, network: item.network });
                setStatus(result.message || 'Favorite removed.');
                if (state.originalTarget === item.target && state.originalNetwork === item.network) clearEditor();
            } catch (error) {
                setStatus(error.message || 'Unable to remove Favorite.', true);
            }
        }
    });

    elements.network?.addEventListener('change', () => {
        const target = cleanTarget(elements.target?.value);
        if (/^3\d{6}$/.test(target)) elements.network.value = 'ECHO';
        if (validTarget(elements.network.value, target)) schedulePrefill(elements.network.value, target, { delay: 100 });
    });

    elements.target?.addEventListener('input', () => {
        const target = cleanTarget(elements.target.value);
        if (elements.target.value !== target) elements.target.value = target;
        const network = networkForTarget(elements.network?.value, target);
        if (elements.network) elements.network.value = network;
        if (validTarget(network, target)) schedulePrefill(network, target);
    });

    for (const field of [elements.name, elements.description]) {
        field?.addEventListener('input', () => { state.editorDirty = true; });
    }

    elements.form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!canWrite) return;
        const payload = {
            action: 'save',
            network: networkForTarget(elements.network.value, elements.target.value),
            target: cleanTarget(elements.target.value),
            name: elements.name.value,
            description: elements.description.value,
        };
        if (payload.network === 'ASL' && privateAllStarTarget(payload.target)) {
            setStatus('Private AllStar nodes from 1000 through 1999 cannot be saved as AllStarLink Favorites.', true);
            return;
        }
        if (!validTarget(payload.network, payload.target)) {
            setStatus(payload.network === 'ECHO' ? 'Enter the mapped EchoLink target as 3 plus six digits.' : 'Enter a valid AllStar node number.', true);
            return;
        }
        try {
            setStatus('Saving Favorite…');
            if (state.originalTarget && (state.originalTarget !== payload.target || state.originalNetwork !== payload.network)) {
                await postFavorite({ action: 'delete', target: state.originalTarget, network: state.originalNetwork });
            }
            await postFavorite(payload);
            if (elements.search) elements.search.value = '';
            updateNodeAddButton();
            render();
            closeEditor();
        } catch (error) {
            setStatus(error.message || 'Unable to save Favorite.', true);
        }
    });

    clearEditor();
    refresh();
})();
