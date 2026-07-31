(() => {
    'use strict';

    const storageKey = 'allstar_connect_audio_alerts_enabled';
    const control = document.getElementById('audio_alerts') || document.getElementById('allstar-connect-audio-toggle');
    const recent = new Map();
    const expectedDisconnects = new Map();
    let enabled = true;
    let initialized = false;
    let stable = new Map();
    let latest = new Map();
    let observedSignature = '';
    let settleTimer = 0;

    function clientIdentity(item) {
        for (const value of [item?.callsign, item?.node, item?.username]) {
            const identity = String(value || '')
                .trim()
                .toUpperCase()
                .replace(/-P$/i, '')
                .replace(/[^A-Z0-9]/g, '');
            if (identity) return identity.toLowerCase();
        }
        return '';
    }

    function keyFor(item) {
        const kind = String(item?.kind || '').trim().toLowerCase();
        const clientType = String(item?.client_type || '').trim().toLowerCase();
        const node = String(item?.node || '').trim();
        const source = String(item?.source || '').trim().toLowerCase();
        const channel = String(item?.channel || '').trim();

        // Asterisk can expose a temporary IAX/WebPhone identity and refine it
        // on the next poll. The exact channel remains stable for that session.
        if (/^IAX2\/[A-Za-z0-9_.:@-]{1,96}$/i.test(channel)) {
            return `iax-channel:${channel.toLowerCase()}`;
        }

        // A Web/Phone session can briefly change from CALLSIGN to CALLSIGN-P.
        // Keep one stable identity so that refinement is not a second event.
        if (kind === 'client'
            || clientType === 'web_phone'
            || /-P$/i.test(node)
            || source.includes('web/phone')
            || source.includes('web / client')) {
            const identity = clientIdentity(item);
            if (identity) return `client:${identity}`;
        }

        const key = String(item?.key || '').trim();
        if (key) return key;

        return [kind, clientType, item?.reported_node, node, item?.channel]
            .map((value) => String(value || '').trim())
            .join(':');
    }

    function score(item) {
        return (String(item?.client_type || '').toLowerCase() === 'web_phone' ? 8 : 0)
            + (/-P$/i.test(String(item?.node || '')) ? 4 : 0)
            + (String(item?.callsign || '').trim() ? 2 : 0)
            + (String(item?.channel || '').trim() ? 1 : 0);
    }

    function snapshot(items) {
        const result = new Map();
        for (const item of Array.isArray(items) ? items : []) {
            const key = keyFor(item);
            if (!key) continue;
            if (!result.has(key) || score(item) >= score(result.get(key))) result.set(key, item);
        }
        return result;
    }

    function signature(items) {
        return Array.from(items.keys()).sort().join('|');
    }

    function updateButton() {
        if (!control) return;
        if (control instanceof HTMLInputElement && control.type === 'checkbox') {
            control.checked = enabled;
            control.title = enabled ? 'Audio Alerts: On' : 'Audio Alerts: Off';
            return;
        }
        control.classList.toggle('is-on', enabled);
        control.classList.toggle('is-off', !enabled);
        control.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        control.setAttribute('title', enabled
            ? 'Audio Alerts: On — click to turn off'
            : 'Audio Alerts: Off — click to turn on');
        control.textContent = 'Audio Alerts';
    }

    function loadPreference() {
        try {
            enabled = window.localStorage.getItem(storageKey) !== '0';
        } catch (error) {
            enabled = true;
        }
        updateButton();

        if ('speechSynthesis' in window) {
            try {
                window.speechSynthesis.getVoices();
            } catch (error) {
                // Voice enumeration is optional.
            }
        }
    }

    function cancelSpeech() {
        if (!('speechSynthesis' in window)) return;
        try {
            window.speechSynthesis.cancel();
        } catch (error) {
            // Audio must never interfere with monitoring.
        }
    }

    function primeSpeech() {
        if (!enabled || !('speechSynthesis' in window)) return;
        try {
            window.speechSynthesis.resume();
            window.speechSynthesis.getVoices();
        } catch (error) {
            // Browser speech priming is best-effort only.
        }
    }

    function setEnabled(next, confirm = false) {
        enabled = Boolean(next);
        try {
            window.localStorage.setItem(storageKey, enabled ? '1' : '0');
        } catch (error) {
            // Keep the current in-memory preference.
        }
        updateButton();

        if (!enabled) {
            window.clearTimeout(settleTimer);
            cancelSpeech();
        } else if (confirm) {
            speak('Audio alerts on', 'audio-toggle', 0);
        }
    }

    function spell(value) {
        return String(value || '').replace(/[^A-Za-z0-9]/g, '').split('').join(' ');
    }

    function label(item) {
        const kind = String(item?.kind || '').trim().toLowerCase();
        const clientType = String(item?.client_type || '').trim().toLowerCase();
        const node = String(item?.node || '').trim();
        const callsign = String(item?.callsign || '').trim().toUpperCase();

        if (kind === 'echo') {
            if (callsign.includes('*')) return 'EchoLink conference';
            if (callsign) return `EchoLink ${spell(callsign)}`;
            return node ? `EchoLink node ${spell(node)}` : 'EchoLink connection';
        }
        if (clientType === 'web_phone' || /-P$/i.test(node)) {
            const identity = callsign || node.replace(/-P$/i, '');
            return identity ? `Web phone client ${spell(identity)}` : 'Web phone client';
        }
        if (kind === 'client') {
            const identity = callsign || node;
            return identity ? `Client ${spell(identity)}` : 'Client connection';
        }
        if (kind === 'iax' || String(item?.source || '').toLowerCase().includes('iax')) {
            return 'IAX client';
        }
        return node ? `Node ${spell(node)}` : 'Connection';
    }

    function speak(text, eventSignature, cooldownMs = 3500) {
        if (!enabled || !('speechSynthesis' in window)) return;

        const now = Date.now();
        for (const [key, time] of recent.entries()) {
            if (now - time > 6000) recent.delete(key);
        }
        if (cooldownMs > 0 && now - (recent.get(eventSignature) || 0) < cooldownMs) return;
        recent.set(eventSignature, now);

        cancelSpeech();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.35;
        utterance.pitch = 1.0;

        try {
            const voices = window.speechSynthesis.getVoices();
            const zira = voices.find((voice) => String(voice.name || '').toLowerCase().includes('zira'));
            if (zira) utterance.voice = zira;
            window.speechSynthesis.speak(utterance);
        } catch (error) {
            // Audio must never interfere with monitoring.
        }
    }

    function announceSettledChanges() {
        for (const [key, item] of latest.entries()) {
            if (!stable.has(key)) speak(`${label(item)} has connected`, `connect:${key}`);
        }
        for (const [key, item] of stable.entries()) {
            if (!latest.has(key)) speak(`${label(item)} has disconnected`, `disconnect:${key}`);
        }
        stable = new Map(latest);
    }

    function pruneExpectedDisconnects() {
        const now = Date.now();
        for (const [key, expected] of expectedDisconnects.entries()) {
            if (now > Number(expected?.expiresAt || 0)) expectedDisconnects.delete(key);
        }
    }

    function handleConnections(items) {
        const previousLatest = latest;
        const nextLatest = snapshot(items);
        const nextSignature = signature(nextLatest);

        pruneExpectedDisconnects();
        for (const [key, expected] of expectedDisconnects.entries()) {
            const previousItem = previousLatest.get(key) || stable.get(key) || expected.item;
            if ((previousLatest.has(key) || stable.has(key)) && !nextLatest.has(key)) {
                speak(`${label(previousItem)} has disconnected`, `disconnect:${key}`);
                expectedDisconnects.delete(key);
                stable.delete(key);
            }
        }
        latest = nextLatest;

        if (!initialized) {
            stable = new Map(latest);
            observedSignature = nextSignature;
            initialized = true;
            return;
        }
        if (nextSignature === observedSignature) {
            for (const [key, item] of latest.entries()) {
                if (stable.has(key)) stable.set(key, item);
            }
            return;
        }

        observedSignature = nextSignature;
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(announceSettledChanges, 1400);
    }

    if (control instanceof HTMLInputElement && control.type === 'checkbox') {
        control.addEventListener('change', () => setEnabled(control.checked, control.checked));
    } else {
        control?.addEventListener('click', () => setEnabled(!enabled, !enabled));
    }
    document.addEventListener('pointerdown', primeSpeech, { passive: true });
    document.addEventListener('keydown', primeSpeech);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) primeSpeech();
    });
    window.addEventListener('pageshow', primeSpeech);
    window.addEventListener('storage', (event) => {
        if (event.key === storageKey) loadPreference();
    });
    window.addEventListener('allstar_connect:disconnect_expected', (event) => {
        const item = event.detail?.item;
        const key = keyFor(item);
        if (key) expectedDisconnects.set(key, { item, expiresAt: Date.now() + 12000 });
    });
    window.addEventListener('allstar_connect:disconnect_cancelled', (event) => {
        const key = keyFor(event.detail?.item);
        if (key) expectedDisconnects.delete(key);
    });
    window.addEventListener('allstar_connect:connections', (event) => {
        handleConnections(event.detail);
    });

    loadPreference();
})();
