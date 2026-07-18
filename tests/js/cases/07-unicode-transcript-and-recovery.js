'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('message length helpers count and slice Unicode code points rather than UTF-16 units', () => {
    const { Runtime } = loadRuntime();
    const astral = '😀'.repeat(1200);
    same(1200, Runtime.util.codePointLength(astral));
    same(1200, Runtime.util.codePointLength(Runtime.util.sliceCodePoints(astral + 'x', 0, 1200)));
    same(2400, astral.length);
});

test('turn submission enforces the 1200 Unicode code-point request limit', () => {
    const { Runtime } = loadRuntime();
    const app = new Runtime.AssistantApp({});
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'session',
        conversation: { id: 'conversation', token: 'conversation-token' }, messages: [],
        cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0 }, widget: {}
    });
    let starts = 0;
    app.startTurn = () => { starts += 1; return true; };
    same(true, app.submitMessage('😀'.repeat(1200), {}));
    same(1, starts);
    same(false, app.submitMessage('😀'.repeat(1201), {}));
    same(1, starts);
    same('الرسالة أطول من الحد المسموح.', app.store.getState().status);
});

test('turn submission preserves exact customer whitespace and markup bytes', () => {
    const { Runtime } = loadRuntime();
    const app = new Runtime.AssistantApp({ storageKey: 'exact-text-key' });
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'session',
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890'
        },
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0 }, widget: {}
    });
    const exact = '  <b>خصم 100%</b>\n\tالسطر الثاني  ';
    let body = null;
    app.performTurn = envelope => { body = JSON.parse(envelope.body); };
    same(true, app.submitMessage(exact, {}));
    same(exact, body.message);
    same(exact, app.store.getState().messages[0].text);
});

test('normalization preserves complete product and receipt evidence', () => {
    const { Runtime } = loadRuntime();
    const product = {
        id: Number.MAX_SAFE_INTEGER,
        name: 'Product', formatted_price: '$10', short_description: 'Description',
        in_stock: true, requires_variation: false, image: '', permalink: 'https://example.test/product'
    };
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply', changed: true, message: 'Added',
        proof: {
            commands: [{ type: 'add', item: 'Product', quantity: 2 }],
            cart_count: 2, cart_total: '$20', changed_line_count: 1, currency: 'USD'
        },
        created_at: 1700000000
    };
    const message = canonicalMessage('Added', {
        outcome: 'action_verified', products: [], receipts: [receipt]
    });
    const payload = canonicalTurnResponse(message);
    payload.conversation.messages[1] = message;
    const normalized = Runtime.contracts.turn(payload).message;
    same(receipt.id, normalized.receipts[0].id);
    same('add', normalized.receipts[0].proof.commands[0].type);
    same('Product', normalized.receipts[0].proof.commands[0].item);
    same(2, normalized.receipts[0].proof.commands[0].quantity);

    const productMessage = canonicalMessage('Product', { products: [product] });
    const productResult = Runtime.contracts.turn(canonicalTurnResponse(productMessage));
    same(Number.MAX_SAFE_INTEGER, productResult.message.products[0].id);
    same(8, Object.keys(productResult.message.products[0]).length);

    const largeProduct = Object.assign({}, product, { id: 4294967296 });
    const largeResult = Runtime.contracts.turn(canonicalTurnResponse(
        canonicalMessage('Large ID', { products: [largeProduct] })
    ));
    same(4294967296, largeResult.message.products[0].id);

    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(canonicalMessage('Unsafe ID', {
            products: [Object.assign({}, product, { id: 9007199254740992 })]
        })));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
});

test('response text limits count Unicode code points at emoji boundaries', () => {
    const { Runtime } = loadRuntime();
    const product = {
        id: 7, name: '😀'.repeat(500), formatted_price: '$10', short_description: '',
        in_stock: true, requires_variation: false, image: '', permalink: 'https://example.test/product'
    };
    const accepted = Runtime.contracts.turn(canonicalTurnResponse(
        canonicalMessage('😀'.repeat(16384), { products: [product] })
    ));
    same(500, Runtime.util.codePointLength(accepted.message.products[0].name));
    same(16384, Runtime.util.codePointLength(accepted.message.text));

    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(canonicalMessage('Product', {
            products: [Object.assign({}, product, { name: '😀'.repeat(501) })]
        })));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);

    const widget = Object.assign({}, canonicalBoot().widget, { title: '😀'.repeat(300) });
    same(300, Runtime.util.codePointLength(Runtime.contracts.boot(canonicalBoot({ widget })).widget.title));
    caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            widget: Object.assign({}, canonicalBoot().widget, { title: '😀'.repeat(301) })
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
});

test('committed top-level message must exactly equal its full canonical transcript message', () => {
    const { Runtime } = loadRuntime();
    const product = {
        id: 7, name: 'Product', formatted_price: '$10', short_description: '',
        in_stock: true, requires_variation: false, image: '', permalink: 'https://example.test/product'
    };
    const message = canonicalMessage('Product', { products: [product] });
    const payload = canonicalTurnResponse(message);
    payload.conversation.messages[1] = JSON.parse(JSON.stringify(message));
    payload.conversation.messages[1].products[0].id = 8;
    let caught = null;
    try { Runtime.contracts.turn(payload); } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('canonical transcript accepts at most twelve complete ordered unique pairs', () => {
    const { Runtime } = loadRuntime();
    const rows = [];
    for (let index = 1; index <= 12; index += 1) {
        const suffix = index.toString(16).padStart(12, '0');
        const turnId = `10000000-0000-4000-8000-${suffix}`;
        rows.push(canonicalUserMessage(`Request ${index}`, turnId, `20000000-0000-4000-8000-${suffix}`));
        rows.push(canonicalMessage(`Answer ${index}`, {
            id: `30000000-0000-4000-8000-${suffix}`,
            turn_id: turnId
        }));
    }
    same(24, Runtime.contracts.boot(canonicalBoot({
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890',
            messages: rows
        }
    })).messages.length);

    const tooMany = rows.concat([
        canonicalUserMessage('Request 13', '10000000-0000-4000-8000-00000000000d', '20000000-0000-4000-8000-00000000000d'),
        canonicalMessage('Answer 13', { id: '30000000-0000-4000-8000-00000000000d', turn_id: '10000000-0000-4000-8000-00000000000d' })
    ]);
    let caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            conversation: {
                id: '22222222-2222-4222-8222-222222222222',
                token: 'conversation-token-1234567890',
                messages: tooMany
            }
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
});

test('canonical transcript rejects orphan, reversed, mismatched, and duplicate pairs', () => {
    const { Runtime } = loadRuntime();
    const turn = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const user = canonicalUserMessage('Request', turn);
    const assistant = canonicalMessage('Answer', { turn_id: turn });
    const variants = [
        [assistant],
        [assistant, user],
        [user, canonicalMessage('Answer', { turn_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' })],
        [user, assistant, Object.assign({}, user), Object.assign({}, assistant)]
    ];
    variants.forEach(messages => {
        let caught = null;
        try {
            Runtime.contracts.boot(canonicalBoot({
                conversation: {
                    id: '22222222-2222-4222-8222-222222222222',
                    token: 'conversation-token-1234567890', messages
                }
            }));
        } catch (error) { caught = error; }
        ok(caught instanceof Runtime.ApiError);
    });
});

test('only the current transient rate-limit safe failure may be uncommitted', () => {
    const { Runtime } = loadRuntime();
    const rateLimited = canonicalMessage('Rate limited', {
        outcome: 'safe_failure', failure_code: 'rate_limited',
        state_uncertain: false
    });
    const accepted = Runtime.contracts.turn(canonicalTurnResponse(rateLimited, { turn_committed: false }));
    same(false, accepted.turnCommitted);

    const invalidMessages = [
        canonicalMessage('Uncommitted answer'),
        canonicalMessage('Other failure', {
            outcome: 'safe_failure', failure_code: 'product_not_found',
            state_uncertain: false
        })
    ];
    invalidMessages.forEach(message => {
        let caught = null;
        try { Runtime.contracts.turn(canonicalTurnResponse(message, { turn_committed: false })); }
        catch (error) { caught = error; }
        ok(caught instanceof Runtime.ApiError);
    });
});

test('provider readiness remains blocked after turn completion, refresh, and transport failure', () => {
    const { Runtime } = loadRuntime();
    const capabilities = { chat_ready: false, images: false, max_images: 0, max_image_bytes: 0 };
    const boot = {
        type: 'BOOT_SUCCESS', sessionToken: 'session',
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890'
        },
        messages: [], cartAvailable: true, cart: canonicalCart(), capabilities, widget: {}
    };

    const completedStore = new Runtime.Store({});
    completedStore.dispatch(boot);
    completedStore.dispatch(turnSuccessAction(canonicalMessage('Cart updated')));
    same('blocked', completedStore.getState().phase);

    const refreshedStore = new Runtime.Store({});
    refreshedStore.dispatch(boot);
    refreshedStore.dispatch({
        type: 'SESSION_REFRESH_SUCCESS', resumePending: false,
        sessionToken: 'new-session', capabilities, messages: []
    });
    same('blocked', refreshedStore.getState().phase);

    const failedStore = new Runtime.Store({});
    failedStore.dispatch(boot);
    failedStore.dispatch({
        type: 'TURN_FAILURE', message: 'Retry later', retryId: 'retry-one',
        retryIdentity: {
            turnId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            conversation: {
                id: '22222222-2222-4222-8222-222222222222',
                token: 'conversation-token-1234567890'
            }
        }
    });
    same('blocked', failedStore.getState().phase);
    failedStore.dispatch({ type: 'RETRY_FAILURE', message: 'Still unavailable', retryId: 'retry-one', retryable: true });
    same('blocked', failedStore.getState().phase);
});

test('HTTP errors require the exact closed error envelope', async () => {
    const malformed = [
        { ok: false, code: 'busy' },
        { ok: false, code: 'busy', message: 'Busy', extra: true },
        { ok: true, code: 'busy', message: 'Busy' },
        { ok: false, code: 'Busy!', message: 'Busy' }
    ];
    for (const payload of malformed) {
        const { Runtime } = loadRuntime(() => Promise.resolve(response(401, payload)));
        const api = new Runtime.ApiClient({ chatUrl: '/chat' });
        let caught = null;
        try { await api.sendTurn(api.envelope({ client_turn_id: 'x' }), 'session', 2); }
        catch (error) { caught = error; }
        same('response_contract_invalid', caught.code);
        same(401, caught.status);
    }
});

test('HTTP error envelope lengths use Unicode code points like the server', async () => {
    const message = '😀'.repeat(4096);
    const { Runtime } = loadRuntime(() => Promise.resolve(response(429, {
        ok: false,
        code: 'busy',
        message,
        retry_after: 1
    })));
    const api = new Runtime.ApiClient({ chatUrl: '/chat' });
    let caught = null;
    try { await api.sendTurn(api.envelope({ client_turn_id: 'x' }), 'session', 2); }
    catch (error) { caught = error; }
    same('busy', caught.code);
    same(429, caught.status);
    same(1, caught.retryAfter);
});

test('conversation recovery requires both a valid envelope and HTTP 401', async () => {
    for (const status of [403, 401]) {
        const { Runtime } = loadRuntime();
        const app = new Runtime.AssistantApp({});
        app.store.dispatch({
            type: 'BOOT_SUCCESS', sessionToken: 'session',
            conversation: { id: 'old', token: 'old-token' }, messages: [],
            cartAvailable: true, cart: canonicalCart(),
            capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0 }, widget: {}
        });
        app.api.sendTurn = () => Promise.reject(new Runtime.ApiError('Expired', 'conversation_invalid', status, 0));
        let recoveries = 0;
        let failures = 0;
        app.recoverInvalidConversation = () => { recoveries += 1; return Promise.resolve(); };
        app.recordTurnFailure = () => { failures += 1; };
        await app.performTurn(app.api.envelope({ client_turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' }), '');
        same(status === 401 ? 1 : 0, recoveries);
        same(status === 401 ? 0 : 1, failures);
    }
});

test('pre-admission conversation expiry rebinds and resends the original customer turn', async () => {
    const { Runtime } = loadRuntime();
    const app = new Runtime.AssistantApp({ storageKey: 'conversation-rebind-key' });
    const oldConversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'old-conversation-token-1234567890'
    };
    const replacement = {
        id: '33333333-3333-4333-8333-333333333333',
        token: 'replacement-conversation-token-1234567890'
    };
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN,
        conversation: oldConversation, messages: [],
        cartAvailable: true, cart: canonicalCart(),
        capabilities: canonicalBoot().capabilities, widget: {}
    });
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const retryId = 'retry-bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    const envelope = Object.freeze({
        body: JSON.stringify({
            conversation_id: oldConversation.id,
            conversation_token: oldConversation.token,
            client_turn_id: turnId,
            message: 'أضف هذا المنتج',
            attachments: [],
            reply_context: {
                text: 'منتج مقتبس',
                message_id: '44444444-4444-4444-8444-444444444444',
                product_index: 0
            }
        }),
        visibleText: 'أضف هذا المنتج'
    });
    ok(app.retryStore.put(retryId, envelope, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS));
    ok(app.continuity.writePending({
        turn_id: turnId,
        conversation_id: oldConversation.id,
        retry_id: retryId,
        started_at_ms: Date.now(),
        guard_until_ms: Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS
    }));

    let bootCalls = 0;
    app.boot = () => {
        bootCalls += 1;
        app.store.dispatch({
            type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN,
            conversation: replacement, messages: [],
            cartAvailable: true, cart: canonicalCart(),
            capabilities: canonicalBoot().capabilities, widget: {}
        });
        return Promise.resolve();
    };
    const bodies = [];
    let recoveryCalls = 0;
    let recoveryFailure = null;
    const originalRecordTurnFailure = app.recordTurnFailure.bind(app);
    const originalRecoverInvalidConversation = app.recoverInvalidConversation.bind(app);
    app.recoverInvalidConversation = (...args) => {
        recoveryCalls += 1;
        return originalRecoverInvalidConversation(...args);
    };
    app.recordTurnFailure = (error, ...args) => {
        recoveryFailure = error;
        return originalRecordTurnFailure(error, ...args);
    };
    app.api.sendTurn = rebound => {
        const body = JSON.parse(rebound.body);
        bodies.push(body);
        if (bodies.length === 1) {
            return Promise.reject(new Runtime.ApiError(
                'Expired', 'conversation_invalid', 401, 0
            ));
        }
        const assistant = canonicalMessage('تمت المعالجة', { turn_id: turnId });
        return Promise.resolve(canonicalTurnResponse(assistant, {
            conversation: replacement,
            messages: [canonicalUserMessage('أضف هذا المنتج', turnId), assistant]
        }));
    };

    await app.performTurn(
        envelope,
        retryId,
        0,
        Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS,
        false
    );

    same(1, recoveryCalls);
    same(1, bootCalls);
    same(null, recoveryFailure, recoveryFailure ? recoveryFailure.code : '');
    same(2, bodies.length);
    same(oldConversation.id, bodies[0].conversation_id);
    same(replacement.id, bodies[1].conversation_id);
    same(replacement.token, bodies[1].conversation_token);
    same(turnId, bodies[1].client_turn_id);
    same('أضف هذا المنتج', bodies[1].message);
    same('منتج مقتبس', bodies[1].reply_context.text);
    same(false, Object.prototype.hasOwnProperty.call(bodies[1].reply_context, 'message_id'));
    same(false, Object.prototype.hasOwnProperty.call(bodies[1].reply_context, 'product_index'));
    same('ready', app.store.getState().phase);
    same(replacement.id, app.store.getState().conversation.id);
    same(false, app.retryStore.has(retryId));
});

test('fresh conversation reset clears authoritative attachments retry envelopes and every view context', () => {
    const { Runtime } = loadRuntime();
    const app = new Runtime.AssistantApp({ maxImages: 2, maxImageBytes: 1000 });
    const view = { resets: 0, render() {}, resetConversationState() { this.resets += 1; } };
    app.views.push(view);
    app.retryStore.put('retry-one', { body: '{"client_turn_id":"one"}' });
    app.attachments.select([fakeImageFile('image/png', 1, 1, 'data:image/png;base64,QUJD')]);
    same(1, app.attachments.publicEntries().length);
    same(true, app.retryStore.has('retry-one'));
    app.resetConversationState();
    same(0, app.attachments.publicEntries().length);
    same(false, app.retryStore.has('retry-one'));
    same(1, view.resets);
    same(0, app.store.getState().attachments.length);
    same(0, app.store.getState().messages.length);
});

test('repeated boot failures replace the synthetic failure instead of duplicating it', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.dispatch({ type: 'BOOT_FAILURE', message: 'First' });
    store.dispatch({ type: 'BOOT_FAILURE', message: 'Second' });
    same(1, store.getState().messages.length);
    same('Second', store.getState().messages[0].text);
    same(true, store.getState().messages[0].boot_failure);
});
