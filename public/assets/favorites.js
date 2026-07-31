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

    function validTarget(network, target) {
        const clean = cleanTarget(target);
        return networkForTarget(network, clean) === 'ECHO' ? /^3\d{6}$/.test(clean) : /^\d{1,7}$/.test(clean);
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
        if (!elements.body) return;
        if (!items.length) {
            elements.body.innerHTML = `<tr><td colspan="5">${state.items.length ? 'No Favorites match the search.' : 'No Favorites saved yet.'}</td></tr>`;
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
        if (focusName) {
            elements.name?.focus();
            elements.name?.select();
        }
    }

    function clearEditor(focusTarget = false) {
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
        setEditorHeading('Add Favorite', 'Type or paste a node number in Search or Target. AllStarLink/EchoLink details will fill automatically.');
        setStatus(canWrite ? 'Ready' : 'View only - login to make changes.');
        if (focusTarget) elements.target?.focus();
    }

    async function lookupIdentity(network, target) {
        if (!identityEndpoint) return null;
        state.lookupController?.abort();
        const controller = new AbortController();
        state.lookupController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 4500);
        try {
            const url = new URL(identityEndpoint, window.location.href);
            url.searchParams.set('network', networkForTarget(network, target));
            url.searchParams.set('target', cleanTarget(target));
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
            const identity = await lookupIdentity(networkCode, clean);
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

    async function refresh() {
        if (!endpoint) return;
        try {
            const response = await fetch(`${endpoint}?_=${Date.now()}`, { cache: 'no-store', credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload?.ok) throw new Error(payload?.message || 'Unable to load Favorites.');
            state.items = Array.isArray(payload.favorites) ? payload.favorites : [];
            render();
            const searchTarget = exactSearchTarget();
            if (searchTarget) schedulePrefill(networkForTarget('ASL', searchTarget), searchTarget, { delay: 40 });
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
        render();
        const target = exactSearchTarget();
        if (target) schedulePrefill(networkForTarget('ASL', target), target);
    });

    for (const button of document.querySelectorAll('[data-sort]')) {
        button.addEventListener('click', () => {
            const key = String(button.dataset.sort || 'target');
            if (state.sortKey === key) state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
            else { state.sortKey = key; state.sortDirection = 'asc'; }
            render();
        });
    }

    elements.add?.addEventListener('click', () => {
        const target = exactSearchTarget();
        if (target) {
            prefillTarget(networkForTarget('ASL', target), target, { focusName: true });
            return;
        }
        clearEditor(true);
    });
    elements.clear?.addEventListener('click', () => clearEditor(true));

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
        if (!validTarget(payload.network, payload.target)) {
            setStatus(payload.network === 'ECHO' ? 'Enter the mapped EchoLink target as 3 plus six digits.' : 'Enter a valid AllStar node number.', true);
            return;
        }
        try {
            setStatus('Saving Favorite…');
            if (state.originalTarget && (state.originalTarget !== payload.target || state.originalNetwork !== payload.network)) {
                await postFavorite({ action: 'delete', target: state.originalTarget, network: state.originalNetwork });
            }
            const result = await postFavorite(payload);
            setStatus(result.message || 'Favorite saved.');
            editItem(result.favorite || payload);
        } catch (error) {
            setStatus(error.message || 'Unable to save Favorite.', true);
        }
    });

    clearEditor();
    refresh();
})();
