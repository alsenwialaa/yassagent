'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('retry envelopes retain one unresolved exact body within UTF-8 byte and age bounds', () => {
    const { Runtime } = loadRuntime();
    same(1, Runtime.ClientRecoveryPolicy.RETRY_MAX_ENTRIES);
    same(3145728, Runtime.ClientRecoveryPolicy.RETRY_MAX_BYTES);
    same(900000, Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS);

    let now = 1000;
    const evicted = [];
    const dependencies = {
        nowMs: () => now,
        setTimeout: () => 1,
        clearTimeout: () => {}
    };
    const store = new Runtime.RetryEnvelopeStore(ids => evicted.push(...ids), dependencies);
    ok(store.put('r1', { body: '{"n":1}' }));
    ok(store.put('r2', { body: '{"n":2}' }));
    same(1, store.ids().length);
    ok(evicted.includes('r1'));
    same(null, store.get('r1'));
    same('{"n":2}', store.get('r2').body);

    const byteEvictions = [];
    const byteStore = new Runtime.RetryEnvelopeStore(ids => byteEvictions.push(...ids), dependencies);
    ok(byteStore.put('large-1', { body: 'x'.repeat(2 * 1024 * 1024) }));
    ok(byteStore.put('large-2', { body: 'y'.repeat(2 * 1024 * 1024) }));
    same(1, byteStore.ids().length);
    ok(byteStore.totalBytes <= Runtime.ClientRecoveryPolicy.RETRY_MAX_BYTES);
    ok(byteEvictions.includes('large-1'));

    const ageEvictions = [];
    now = 2000;
    const ageStore = new Runtime.RetryEnvelopeStore(ids => ageEvictions.push(...ids), dependencies);
    ok(ageStore.put('aged', { body: 'exact-body' }));
    now += Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS;
    ageStore.prune();
    same(0, ageStore.ids().length);
    ok(ageEvictions.includes('aged'));
});

test('exact retry envelopes survive a same-tab reload in bounded session storage', () => {
    const shared = Object.create(null);
    const storage = {
        getItem: key => Object.prototype.hasOwnProperty.call(shared, key) ? shared[key] : null,
        setItem: (key, value) => { shared[key] = String(value); },
        removeItem: key => { delete shared[key]; }
    };
    const firstRuntime = loadRuntime().Runtime;
    const id = 'retry-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const first = new firstRuntime.RetryEnvelopeStore(() => {}, {
        storageKey: 'widget', storage
    });
    ok(first.put(id, { body: '{"client_turn_id":"same"}', visibleText: 'نفس الطلب' }));
    const second = new firstRuntime.RetryEnvelopeStore(() => {}, {
        storageKey: 'widget', storage
    });
    same('{"client_turn_id":"same"}', second.get(id).body);
    same('نفس الطلب', second.get(id).visibleText);
    first.clear();
    same(null, storage.getItem('widget:retry-envelopes'));
});

test('protecting an exact retry preserves one fixed retention deadline across reload', () => {
    const shared = Object.create(null);
    const storage = {
        getItem: key => Object.prototype.hasOwnProperty.call(shared, key) ? shared[key] : null,
        setItem: (key, value) => { shared[key] = String(value); },
        removeItem: key => { delete shared[key]; }
    };
    let now = 1000;
    const dependencies = {
        storageKey: 'protected', storage, nowMs: () => now,
        setTimeout: () => 1, clearTimeout: () => {}
    };
    const { Runtime } = loadRuntime();
    const id = 'retry-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const first = new Runtime.RetryEnvelopeStore(() => {}, dependencies);
    ok(first.put(id, { body: '{"client_turn_id":"same"}' }));
    now += 1000;
    ok(first.protect(id, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS));
    const persisted = JSON.parse(shared['protected:retry-envelopes']).entries[0];
    same(Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS, persisted.expires_at - persisted.created_at);
    const reloaded = new Runtime.RetryEnvelopeStore(() => {}, dependencies);
    same('{"client_turn_id":"same"}', reloaded.get(id).body);
});

test('retry protection degrades to the exact in-memory envelope when persistence is revoked', () => {
    const shared = Object.create(null);
    let writable = true;
    const storage = {
        getItem: key => Object.prototype.hasOwnProperty.call(shared, key) ? shared[key] : null,
        setItem: (key, value) => {
            if (!writable) throw new Error('storage revoked');
            shared[key] = String(value);
        },
        removeItem: key => {
            if (!writable) throw new Error('storage revoked');
            delete shared[key];
        }
    };
    const { Runtime } = loadRuntime();
    const id = 'retry-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const store = new Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'widget', storage });
    ok(store.put(id, { body: '{"client_turn_id":"same"}' }));
    same('persistent', store.persistenceMode());
    writable = false;
    same(true, store.protect(id, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS));
    same('{"client_turn_id":"same"}', store.get(id).body);
    same('memory', store.persistenceMode());
});

test('per-tab recovery storage keeps only bounded unresolved-turn identity', () => {
    const { Runtime, storage, sessionStorage } = loadRuntime();
    const continuity = new Runtime.ContinuityStore('key');
    const pending = {
        turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        conversation_id: '22222222-2222-4222-8222-222222222222',
        retry_id: 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        started_at_ms: 1000,
        guard_until_ms: 61000,
        body: 'must-not-be-shared'
    };
    ok(continuity.writePending(pending));
    same(pending.turn_id, continuity.readPending().turn_id);
    same(undefined, storage['key:pending-turn']);
    ok(!String(sessionStorage['key:pending-turn']).includes('must-not-be-shared'));
    continuity.clearPending(pending.turn_id);
    same(null, continuity.readPending());
});

test('retry storage keeps one exact in-memory envelope when session storage is unavailable or rejects writes', () => {
    const unavailable = loadRuntime(undefined, { sessionStorageUnavailable: true });
    const unavailableStore = new unavailable.Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'widget' });
    const firstId = 'retry-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    ok(unavailableStore.put(firstId, { body: '{"client_turn_id":"one"}', visibleText: 'one' }));
    same('memory', unavailableStore.persistenceMode());
    same('{"client_turn_id":"one"}', unavailableStore.get(firstId).body);

    const rejected = loadRuntime(undefined, { sessionStorageWriteFailure: true });
    const rejectedStore = new rejected.Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'widget' });
    const secondId = 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    ok(rejectedStore.put(secondId, { body: '{"client_turn_id":"two"}', visibleText: 'two' }));
    same('memory', rejectedStore.persistenceMode());
    same('{"client_turn_id":"two"}', rejectedStore.get(secondId).body);
    same(undefined, rejected.sessionStorage['widget:retry-envelopes']);
});

test('pending-turn identity falls back to memory when session storage is unavailable or rejects writes', () => {
    const pending = {
        turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        conversation_id: '22222222-2222-4222-8222-222222222222',
        retry_id: 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        started_at_ms: 1000,
        guard_until_ms: 61000
    };
    const unavailable = loadRuntime(undefined, { sessionStorageUnavailable: true });
    const unavailableContinuity = new unavailable.Runtime.ContinuityStore('widget');
    ok(unavailableContinuity.writePending(pending));
    same('memory', unavailableContinuity.pendingPersistenceMode());
    same(pending.turn_id, unavailableContinuity.readPending().turn_id);

    const rejected = loadRuntime(undefined, { sessionStorageWriteFailure: true });
    const rejectedContinuity = new rejected.Runtime.ContinuityStore('widget');
    ok(rejectedContinuity.writePending(pending));
    same('memory', rejectedContinuity.pendingPersistenceMode());
    same(pending.retry_id, rejectedContinuity.readPending().retry_id);
    rejectedContinuity.clearPending(pending.turn_id);
    same(null, rejectedContinuity.readPending());
});

test('all browser continuity stores share one current-document fallback when both storage areas are unavailable', () => {
    const loaded = loadRuntime(undefined, {
        localStorageUnavailable: true,
        sessionStorageUnavailable: true
    });
    const { Runtime } = loaded;
    const identity = new Runtime.ClientIdentityStore('shared');
    const clientId = identity.id();
    same(clientId, new Runtime.ClientIdentityStore('shared').id());

    const browserSecret = new Runtime.BrowserContinuitySecretStore('shared');
    const secret = browserSecret.secret();
    same(secret, new Runtime.BrowserContinuitySecretStore('shared').secret());

    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const continuity = new Runtime.ContinuityStore('shared');
    ok(continuity.write(conversation));
    same(conversation.id, new Runtime.ContinuityStore('shared').read().conversation_id);

    const pending = {
        turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        conversation_id: conversation.id,
        retry_id: 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        started_at_ms: Date.now(),
        guard_until_ms: Date.now() + 61000
    };
    ok(continuity.writePending(pending));
    same(pending.turn_id, new Runtime.ContinuityStore('shared').readPending().turn_id);

    const retry = new Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'shared' });
    ok(retry.put(pending.retry_id, {
        body: '{"client_turn_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}',
        visibleText: 'same turn'
    }));
    const secondRetry = new Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'shared' });
    same(retry.get(pending.retry_id).body, secondRetry.get(pending.retry_id).body);

    const status = Runtime.BrowserStorage.status();
    same('memory', status.local);
    same('memory', status.session);
    same(true, status.current_tab_chat);
    same(true, status.current_tab_retry);
    same(false, status.refresh_continuity);
    same(false, status.unresolved_refresh_recovery);
    same(false, status.cross_tab_continuity);
    same(true, status.server_idempotency_authoritative);
});

test('browser-storage read failures degrade every authority to one coherent current-document fallback', () => {
    const { Runtime } = loadRuntime(undefined, {
        localStorageReadFailure: true,
        sessionStorageReadFailure: true
    });
    const identity = new Runtime.ClientIdentityStore('read-rejected');
    const browserSecret = new Runtime.BrowserContinuitySecretStore('read-rejected');
    const continuity = new Runtime.ContinuityStore('read-rejected');
    const retry = new Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'read-rejected' });
    const clientId = identity.id();
    const secret = browserSecret.secret();
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const pending = {
        turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        conversation_id: conversation.id,
        retry_id: 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        started_at_ms: Date.now(),
        guard_until_ms: Date.now() + 61000
    };
    ok(continuity.write(conversation));
    ok(continuity.writePending(pending));
    ok(retry.put(pending.retry_id, { body: '{"client_turn_id":"same"}' }));

    same(clientId, new Runtime.ClientIdentityStore('read-rejected').id());
    same(secret, new Runtime.BrowserContinuitySecretStore('read-rejected').secret());
    same(conversation.id, new Runtime.ContinuityStore('read-rejected').read().conversation_id);
    same(pending.turn_id, new Runtime.ContinuityStore('read-rejected').readPending().turn_id);
    same('{"client_turn_id":"same"}', new Runtime.RetryEnvelopeStore(() => {}, {
        storageKey: 'read-rejected'
    }).get(pending.retry_id).body);
    same('memory', Runtime.BrowserStorage.status().local);
    same('memory', Runtime.BrowserStorage.status().session);
    same('read_failed', Runtime.BrowserStorage.status().local_reason);
    same('read_failed', Runtime.BrowserStorage.status().session_reason);
});

test('rejected removal quarantines corrupt persistent records for the current document', () => {
    const local = Object.assign(Object.create(null), {
        quarantine_client: 'not-a-uuid',
        quarantine_continuity_secret: '{broken',
        quarantine: '{broken'
    });
    const session = Object.assign(Object.create(null), {
        'quarantine:pending-turn': '{broken',
        'quarantine:retry-envelopes': '{broken'
    });
    const { Runtime, storage, sessionStorage } = loadRuntime(undefined, {
        localStorageData: local,
        sessionStorageData: session,
        localStorageRemoveFailure: true,
        sessionStorageRemoveFailure: true
    });
    const identity = new Runtime.ClientIdentityStore('quarantine').id();
    const secret = new Runtime.BrowserContinuitySecretStore('quarantine').secret();
    const continuity = new Runtime.ContinuityStore('quarantine');
    const retry = new Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'quarantine' });

    ok(/^[a-f0-9-]{36}$/.test(identity));
    ok(/^[A-Za-z0-9_-]{43}$/.test(secret));
    same(0, Object.keys(continuity.read()).length);
    same(null, continuity.readPending());
    same(0, retry.ids().length);
    same('not-a-uuid', storage.quarantine_client);
    same('{broken', sessionStorage['quarantine:pending-turn']);
    same('memory', Runtime.BrowserStorage.status().local);
    same('memory', Runtime.BrowserStorage.status().session);
    same('remove_failed', Runtime.BrowserStorage.status().local_reason);
    same('remove_failed', Runtime.BrowserStorage.status().session_reason);
});

test('a rejected local-storage write degrades identity secret and conversation continuity together', () => {
    const { Runtime, storage } = loadRuntime(undefined, { localStorageWriteFailure: true });
    const identity = new Runtime.ClientIdentityStore('write-rejected');
    const clientId = identity.id();
    const browserSecret = new Runtime.BrowserContinuitySecretStore('write-rejected');
    const secret = browserSecret.secret();
    const continuity = new Runtime.ContinuityStore('write-rejected');
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    ok(continuity.write(conversation));

    same(undefined, storage['write-rejected_client']);
    same(undefined, storage['write-rejected_continuity_secret']);
    same(undefined, storage['write-rejected']);
    same(clientId, new Runtime.ClientIdentityStore('write-rejected').id());
    same(secret, new Runtime.BrowserContinuitySecretStore('write-rejected').secret());
    same(conversation.id, new Runtime.ContinuityStore('write-rejected').read().conversation_id);
    same('memory', identity.persistenceMode());
    same('memory', browserSecret.persistenceMode());
    same('memory', continuity.persistenceMode());
    same('memory', Runtime.BrowserStorage.status().local);
    same('write_failed', Runtime.BrowserStorage.status().local_reason);
});

test('corrupt browser storage records are discarded without becoming current authority', () => {
    const local = Object.create(null);
    const session = Object.create(null);
    local.corrupt_client = 'not-a-uuid';
    local.corrupt_continuity_secret = '{broken';
    local.corrupt = JSON.stringify({
        conversation_id: 'not-a-uuid',
        conversation_token: 'bad token',
        revision: 'not-a-revision'
    });
    session['corrupt:pending-turn'] = '{broken';
    session['corrupt:retry-envelopes'] = JSON.stringify({ version: 99, entries: [] });
    const { Runtime, storage, sessionStorage } = loadRuntime(undefined, {
        localStorageData: local,
        sessionStorageData: session
    });

    const identity = new Runtime.ClientIdentityStore('corrupt').id();
    const secret = new Runtime.BrowserContinuitySecretStore('corrupt').secret();
    const continuity = new Runtime.ContinuityStore('corrupt');
    const retry = new Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'corrupt' });

    ok(/^[a-f0-9-]{36}$/.test(identity));
    ok(/^[A-Za-z0-9_-]{43}$/.test(secret));
    same(0, Object.keys(continuity.read()).length);
    same(null, continuity.readPending());
    same(0, retry.ids().length);
    same(identity, storage.corrupt_client);
    same(secret, JSON.parse(storage.corrupt_continuity_secret).secret);
    same(undefined, storage.corrupt);
    same(undefined, sessionStorage['corrupt:pending-turn']);
    same(undefined, sessionStorage['corrupt:retry-envelopes']);
    same('persistent', Runtime.BrowserStorage.status().local);
    same('persistent', Runtime.BrowserStorage.status().session);
});

test('retry restoration rejects ambiguous or malformed durable bodies and canonicalizes one valid envelope', () => {
    const now = Date.now();
    const valid = {
        id: 'retry-aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        body: '{"client_turn_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"}',
        visible_text: 'exact request',
        created_at: now,
        expires_at: now + 600000,
        protected_until: now + 1000,
        sequence: 7,
        unknown: 'discard me'
    };

    const ambiguousSession = Object.assign(Object.create(null), {
        'restore:retry-envelopes': JSON.stringify({ version: 1, entries: [valid, Object.assign({}, valid, {
            id: 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            sequence: 8
        })] })
    });
    const ambiguous = loadRuntime(undefined, { sessionStorageData: ambiguousSession });
    const ambiguousStore = new ambiguous.Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'restore' });
    same(0, ambiguousStore.ids().length);
    same(undefined, ambiguous.sessionStorage['restore:retry-envelopes']);

    const malformedSession = Object.assign(Object.create(null), {
        'restore:retry-envelopes': JSON.stringify({ version: 1, entries: [Object.assign({}, valid, {
            protected_until: now + 700000
        })] })
    });
    const malformed = loadRuntime(undefined, { sessionStorageData: malformedSession });
    const malformedStore = new malformed.Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'restore' });
    same(0, malformedStore.ids().length);
    same(undefined, malformed.sessionStorage['restore:retry-envelopes']);

    const canonicalSession = Object.assign(Object.create(null), {
        'restore:retry-envelopes': JSON.stringify({ version: 1, entries: [valid], extra: true })
    });
    const canonical = loadRuntime(undefined, { sessionStorageData: canonicalSession });
    const canonicalStore = new canonical.Runtime.RetryEnvelopeStore(() => {}, { storageKey: 'restore' });
    same(valid.body, canonicalStore.get(valid.id).body);
    const persisted = JSON.parse(canonical.sessionStorage['restore:retry-envelopes']);
    same('entries,version', Object.keys(persisted).sort().join(','));
    same('body,created_at,expires_at,id,protected_until,sequence,visible_text', Object.keys(persisted.entries[0]).sort().join(','));
    same(undefined, persisted.entries[0].unknown);
});

test('a new document receives fresh browser authority after memory-only continuity', async () => {
    const firstBodies = [];
    const firstLoaded = loadRuntime((url, options) => {
        firstBodies.push(JSON.parse(options.body));
        return Promise.resolve(response(200, canonicalBoot()));
    }, {
        localStorageUnavailable: true,
        sessionStorageUnavailable: true,
        randomSeed: 0
    });
    const config = {
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'memory-reload'
    };
    const first = new firstLoaded.Runtime.AssistantApp(config);
    await first.boot(false);
    same('ready', first.store.getState().phase);
    ok(first.continuity.read().conversation_id);

    const secondBodies = [];
    const secondLoaded = loadRuntime((url, options) => {
        secondBodies.push(JSON.parse(options.body));
        return Promise.resolve(response(200, canonicalBoot({
            conversation: {
                id: '33333333-3333-4333-8333-333333333333',
                token: 'replacement-conversation-token-1234',
                messages: []
            }
        })));
    }, {
        localStorageUnavailable: true,
        sessionStorageUnavailable: true,
        randomSeed: 80
    });
    const second = new secondLoaded.Runtime.AssistantApp(config);
    await second.boot(false);

    same(1, firstBodies.length);
    same(1, secondBodies.length);
    same(false, Object.prototype.hasOwnProperty.call(secondBodies[0], 'conversation_id'));
    same(false, Object.prototype.hasOwnProperty.call(secondBodies[0], 'conversation_token'));
    same('', secondBodies[0].pending_turn_id);
    ok(secondBodies[0].client_instance_id !== firstBodies[0].client_instance_id);
    ok(secondBodies[0].browser_continuity_secret !== firstBodies[0].browser_continuity_secret);
    same('33333333-3333-4333-8333-333333333333', second.store.getState().conversation.id);
});

test('boot and a complete turn remain available when all browser storage is blocked', async () => {
    const requests = [];
    const loaded = loadRuntime((url, options) => {
        const body = JSON.parse(options.body);
        requests.push({ url: String(url), body });
        if (String(url).includes('/boot')) {
            return Promise.resolve(response(200, canonicalBoot()));
        }
        return Promise.resolve(response(200, canonicalTurnResponse(
            canonicalMessage('Memory fallback answer', { turn_id: body.client_turn_id }),
            {
                messages: [
                    canonicalUserMessage(body.message, body.client_turn_id),
                    canonicalMessage('Memory fallback answer', { turn_id: body.client_turn_id })
                ]
            }
        )));
    }, {
        localStorageUnavailable: true,
        sessionStorageUnavailable: true,
        config: {
            text: {
                genericFailure: 'Generic failure',
                browserStorageDegraded: 'Storage continuity is limited.'
            }
        }
    });
    const app = new loaded.Runtime.AssistantApp({
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'blocked-storage'
    });
    await app.boot(false);
    same('ready', app.store.getState().phase);
    same('Storage continuity is limited.', app.store.getState().status);
    same(true, app.submitMessage('normal language request', {}));
    for (let index = 0; index < 8; index += 1) {
        await Promise.resolve();
    }
    await new Promise(resolve => setImmediate(resolve));
    same(2, requests.length);
    same('ready', app.store.getState().phase);
    same('Memory fallback answer', app.store.getState().messages[1].text);
    same(0, app.retryStore.ids().length);
    same(null, app.continuity.readPending());
    same('memory', app.browserStorageStatus().local);
    same('memory', app.browserStorageStatus().session);
});

test('two tabs cannot overwrite each other unresolved turn identity', () => {
    const local = Object.create(null);
    const firstSession = Object.create(null);
    const secondSession = Object.create(null);
    const firstLoaded = loadRuntime(undefined, {
        localStorageData: local, sessionStorageData: firstSession
    });
    const secondLoaded = loadRuntime(undefined, {
        localStorageData: local, sessionStorageData: secondSession
    });
    const first = new firstLoaded.Runtime.ContinuityStore('key');
    const second = new secondLoaded.Runtime.ContinuityStore('key');
    const base = {
        conversation_id: '22222222-2222-4222-8222-222222222222',
        started_at_ms: 1000,
        guard_until_ms: 61000
    };
    const firstPending = Object.assign({}, base, {
        turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        retry_id: 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
    });
    const secondPending = Object.assign({}, base, {
        turn_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        retry_id: 'retry-dddddddd-dddd-4ddd-8ddd-dddddddddddd'
    });
    ok(first.writePending(firstPending));
    ok(second.writePending(secondPending));
    same(firstPending.turn_id, first.readPending().turn_id);
    same(secondPending.turn_id, second.readPending().turn_id);
    same(undefined, local['key:pending-turn']);
});
