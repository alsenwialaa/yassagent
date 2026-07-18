'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('turn deadline and exact retry age use the server-localized timing policy', () => {
    const { Runtime } = loadRuntime(null, {
        config: { turnDeadlineMs: 1440000, retryRetentionMs: 1980000 }
    });
    same(1440000, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS);
    same(1980000, Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS);
});

test('an executing turn envelope cannot be evicted by a second unresolved request or age pressure', () => {
    const { Runtime } = loadRuntime();
    let now = 1000;
    const evicted = [];
    const dependencies = {
        nowMs: () => now,
        setTimeout: () => 1,
        clearTimeout: () => {}
    };
    const store = new Runtime.RetryEnvelopeStore(ids => evicted.push(...ids), dependencies);
    ok(store.put('active', { body: 'a'.repeat(2 * 1024 * 1024) }, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS));
    same(false, store.put('second', { body: '{"n":2}' }));
    same(1, store.ids().length);
    ok(store.has('active'));
    same(false, evicted.includes('active'));

    now += Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS - 1;
    store.prune();
    ok(store.has('active'));
    now += Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS;
    store.prune();
    same(null, store.get('active'));
});

test('evicted unresolved retry storage requires canonical recheck and never becomes ready', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.dispatch({
        type: 'TURN_FAILURE', message: 'Retry later', retryId: 'retry-one',
        retryIdentity: {
            turnId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            conversation: { id: 'conversation', token: 'conversation-token' }
        }
    });
    same('retry-one', store.getState().messages[0].retry_id);
    store.dispatch({ type: 'RETRY_STORAGE_EVICTED', retryIds: ['retry-one'] });
    same('', store.getState().messages[0].retry_id);
    same('blocked', store.getState().phase);
    same(true, store.getState().retryRecheckRequired);
    store.dispatch({ type: 'BOOT_FAILURE', message: 'Recheck unavailable' });
    same('blocked', store.getState().phase);
    same(true, store.getState().retryRecheckRequired);
    store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'session',
        conversation: {}, messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true }, widget: {}, retryRecheckRequired: true
    });
    same('blocked', store.getState().phase);
    same(true, store.getState().retryRecheckRequired);
});

test('a retry control cannot exist without its canonical turn and conversation identity', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.dispatch({
        type: 'TURN_FAILURE', message: 'Ambiguous without identity', retryId: 'retry-invalid'
    });
    same('blocked', store.getState().phase);
    same('', store.getState().messages[0].retry_id);
    same(true, store.getState().retryRecheckRequired);
    same(null, store.getState().retryRecheckIdentity);
    same(0, Object.keys(store.getState().retryIdentities).length);
});

test('retryable transport failure records exact replay identity before exposing retry', () => {
    const { Runtime } = loadRuntime();
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const retryId = 'retry-recorded';
    const envelope = Object.freeze({ body: JSON.stringify({
        conversation_id: conversation.id,
        conversation_token: conversation.token,
        client_turn_id: turnId,
        message: 'request', attachments: []
    }) });
    const app = new Runtime.AssistantApp({ storageKey: 'expired-recheck' });
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'session', conversation,
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true }, widget: {}
    });
    app.store.dispatch({
        type: 'TURN_START', userMessage: canonicalUserMessage('request', turnId, 'local-' + turnId)
    });
    ok(app.retryStore.put(retryId, envelope));
    app.recordTurnFailure(
        new Runtime.ApiError('Timed out', 'request_timeout', 0, 0),
        envelope,
        retryId,
        false,
        false
    );
    same(retryId, app.store.getState().messages[1].retry_id);
    same(turnId, app.store.getState().retryIdentities[retryId].turnId);
    same(conversation.id, app.store.getState().retryIdentities[retryId].conversation.id);
});

test('expired unresolved body auto-rechecks its turn identity and stays blocked until canonical completion', async () => {
    const { Runtime } = loadRuntime();
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const retryId = 'retry-expiring';
    const envelope = Object.freeze({ body: JSON.stringify({
        conversation_id: conversation.id,
        conversation_token: conversation.token,
        client_turn_id: turnId,
        message: 'may mutate cart', attachments: []
    }) });
    const identity = { turnId, conversation };
    const app = new Runtime.AssistantApp({ storageKey: 'expired-recheck-boot' });
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'session', conversation,
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0 }, widget: {}
    });
    app.store.dispatch({
        type: 'TURN_START',
        userMessage: canonicalUserMessage('may mutate cart', turnId, 'local-' + turnId)
    });
    ok(app.retryStore.put(retryId, envelope));
    app.store.dispatch({
        type: 'TURN_FAILURE', message: 'Ambiguous', retryId, retryIdentity: identity
    });
    app.api.boot = () => Promise.resolve(canonicalBoot({
        conversation: Object.assign({}, conversation, {
            messages: []
        }),
        pending_turn: { id: turnId, status: 'pending' }
    }));

    app.retryStore.remove(retryId);
    app.retryStore.publish([retryId]);
    await Promise.resolve();
    if (app.bootPromise) await app.bootPromise;
    same('blocked', app.store.getState().phase);
    same(true, app.store.getState().retryRecheckRequired);
    same(turnId, app.store.getState().retryRecheckIdentity.turnId);
    same(0, app.store.getState().messages.filter(message => message.retry_id).length);

    app.api.boot = () => Promise.resolve(canonicalBoot({
        conversation: Object.assign({}, conversation, {
            messages: [
                canonicalUserMessage('may mutate cart', turnId),
                canonicalMessage('Canonical result', { turn_id: turnId })
            ]
        }),
        pending_turn: { id: turnId, status: 'terminal' }
    }));
    await app.boot(false);
    same('ready', app.store.getState().phase);
    same(false, app.store.getState().retryRecheckRequired);
});

test('a canonical boot into a different conversation discards stale recheck identity', () => {
    const { Runtime } = loadRuntime();
    const oldConversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const oldIdentity = {
        turnId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        conversation: oldConversation
    };
    const app = new Runtime.AssistantApp({});
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'old-session', conversation: oldConversation,
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true }, widget: {},
        retryRecheckRequired: true, retryRecheckIdentity: oldIdentity
    });
    const result = Runtime.contracts.boot(canonicalBoot({
        conversation: {
            id: '33333333-3333-4333-8333-333333333333',
            token: 'different-conversation-token',
            messages: []
        }
    }));
    app.acceptBootResult(result, true, oldIdentity);
    same('ready', app.store.getState().phase);
    same(false, app.store.getState().retryRecheckRequired);
    same(null, app.store.getState().retryRecheckIdentity);
    same('33333333-3333-4333-8333-333333333333', app.store.getState().conversation.id);
});

test('every ambiguous retryable turn blocks new input until exact reconciliation', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.dispatch({
        type: 'TURN_START',
        userMessage: canonicalUserMessage('May mutate cart')
    });
    store.dispatch({
        type: 'TURN_FAILURE',
        message: 'Ambiguous transport result',
        retryId: 'retry-any-turn',
        retryIdentity: {
            turnId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            conversation: { id: 'conversation', token: 'conversation-token' }
        }
    });
    same('blocked', store.getState().phase);
    store.dispatch({
        type: 'RETRY_FAILURE',
        retryId: 'retry-any-turn',
        message: 'Still ambiguous',
        retryable: true
    });
    same('blocked', store.getState().phase);
});

test('malformed 401 response preserves expiry status', async () => {
    const { Runtime } = loadRuntime(() => Promise.resolve(response(401, null, '<html>bad</html>')));
    const api = new Runtime.ApiClient({ chatUrl: '/chat' });
    let caught = null;
    try { await api.sendTurn(api.envelope({ client_turn_id: 'x' }), 'session', 2); } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same(401, caught.status);
    same('response_contract_invalid', caught.code);
});

test('malformed 4xx response remains an ambiguous exact retry instead of proving rejection', async () => {
    const { Runtime } = loadRuntime();
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const retryId = 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    const app = new Runtime.AssistantApp({ storageKey: 'malformed-4xx-key' });
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN, conversation,
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: canonicalBoot().capabilities, widget: {}
    });
    const envelope = app.api.envelope({
        conversation_id: conversation.id,
        conversation_token: conversation.token,
        client_turn_id: turnId,
        message: 'request',
        attachments: []
    });
    ok(app.retryStore.put(retryId, envelope));
    ok(app.continuity.writePending({
        turn_id: turnId,
        conversation_id: conversation.id,
        retry_id: retryId,
        started_at_ms: Date.now(),
        guard_until_ms: Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS
    }));
    app.store.dispatch({
        type: 'TURN_START',
        userMessage: canonicalUserMessage('request', turnId, 'local-' + turnId)
    });
    app.api.sendTurn = () => Promise.reject(
        new Runtime.ApiError('Malformed', 'response_contract_invalid', 401, 0)
    );

    await app.performTurn(
        envelope,
        retryId,
        0,
        Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS,
        false
    );

    same('blocked', app.store.getState().phase);
    same(retryId, app.store.getState().messages[1].retry_id);
    same(turnId, app.continuity.readPending().turn_id);
    same(true, app.retryStore.has(retryId));
});

test('session refresh rebases to canonical history and preserves only the still-pending local turn', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const conversation = { id: '22222222-2222-4222-8222-222222222222', token: 'conversation-token-1234567890' };
    const pending = {
        id: 'local-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        turn_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        role: 'user', outcome: '', text: 'Pending request', products: [],
        state_uncertain: false, created_at: 1700000001
    };
    const canonical = canonicalMessage('Server-only history', {
        id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        turn_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
    });
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 'old-session', conversation, messages: [canonicalMessage('Stale local history')], cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true }, widget: {} });
    store.dispatch({ type: 'TURN_START', userMessage: pending });
    store.dispatch({ type: 'SESSION_REFRESH_START' });
    store.dispatch({
        type: 'SESSION_REFRESH_SUCCESS', sessionToken: 'new-session', conversation,
        messages: [canonical], pendingUserMessage: pending, resumePending: true,
        cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true },
        widget: {}, retrying: false
    });
    same('sending', store.getState().phase);
    same('new-session', store.getState().sessionToken);
    same(conversation, store.getState().conversation);
    same(2, store.getState().messages.length);
    same('Server-only history', store.getState().messages[0].text);
    same('Pending request', store.getState().messages[1].text);
    same('Server-only history', store.getState().preTurnMessages[0].text);
});

test('session refresh adopts an already-canonical pending turn without replay UI duplication', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const conversation = { id: '22222222-2222-4222-8222-222222222222', token: 'conversation-token-1234567890' };
    const turnId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    const user = {
        id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', turn_id: turnId,
        role: 'user', outcome: '', text: 'Already committed', products: [],
        receipts: [], created_at: 1700000000
    };
    const assistant = canonicalMessage('Canonical result', {
        id: 'ffffffff-ffff-4fff-8fff-ffffffffffff', turn_id: turnId
    });
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 'old-session', conversation, messages: [], cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true }, widget: {} });
    store.dispatch({
        type: 'SESSION_REFRESH_SUCCESS', sessionToken: 'new-session', conversation,
        messages: [user, assistant], pendingUserMessage: null, resumePending: false,
        cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true },
        widget: {}, retrying: false
    });
    same('ready', store.getState().phase);
    same(2, store.getState().messages.length);
    same('Already committed', store.getState().messages[0].text);
    same('Canonical result', store.getState().messages[1].text);
    same(null, store.getState().activeUserMessage);
});

test('conversation reset removes stale local history before fresh authority is adopted', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 'session', conversation: { id: 'old', token: 'old-token' }, messages: [canonicalMessage('Old answer')], cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true }, widget: {} });
    store.dispatch({ type: 'TURN_START', userMessage: { id: 'local', role: 'user', outcome: '', text: 'Unaccepted request', products: [], state_uncertain: false } });
    store.dispatch({ type: 'CONVERSATION_RESET_START' });
    same('booting', store.getState().phase);
    same('', store.getState().sessionToken);
    same(null, store.getState().conversation);
    same(0, store.getState().messages.length);
    same(null, store.getState().activeUserMessage);
});

test('successful turn rebases stale local history to the canonical server transcript', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const currentTurn = '15151515-1515-4151-8151-151515151515';
    const hiddenTurn = '16161616-1616-4161-8161-161616161616';
    const pending = canonicalUserMessage('Current request', currentTurn, '17171717-1717-4171-8171-171717171717');
    const hiddenUser = canonicalUserMessage('Other tab request', hiddenTurn, '18181818-1818-4181-8181-181818181818');
    const hiddenAssistant = canonicalMessage('Other tab answer', { id: '19191919-1919-4191-8191-191919191919', turn_id: hiddenTurn });
    const canonicalUser = canonicalUserMessage('Current request', currentTurn, '20202020-2020-4020-8020-202020202020');
    const reply = canonicalMessage('Current answer', { id: '21212121-2121-4121-8121-212121212122', turn_id: currentTurn });
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 's', conversation: {}, messages: [canonicalMessage('Stale local history')], cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true }, widget: {} });
    store.dispatch({ type: 'TURN_START', userMessage: pending });
    store.dispatch(turnSuccessAction(reply, {
        messages: [hiddenUser, hiddenAssistant, canonicalUser, reply],
        pendingUserMessage: pending
    }));
    same(4, store.getState().messages.length);
    same('Other tab request', store.getState().messages[0].text);
    same('Other tab answer', store.getState().messages[1].text);
    same('Current request', store.getState().messages[2].text);
    same('Current answer', store.getState().messages[3].text);
});
