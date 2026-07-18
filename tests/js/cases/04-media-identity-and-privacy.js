'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('canonical image presentation survives response normalization', () => {
    const { Runtime } = loadRuntime();
    const turnId = '35353535-3535-4535-8535-353535353535';
    const user = canonicalUserMessage('Image attachment (available to the model for this turn only)', turnId, '36363636-3636-4636-8636-363636363636');
    user.presentation = {
        image_scope: 'turn_only',
        images: [{ kind: 'image', mime_type: 'image/jpeg', byte_length: 123456 }],
        reply_quote: 'المنتج السابق'
    };
    const reply = canonicalMessage('I inspected the image.', { id: '37373737-3737-4737-8737-373737373737', turn_id: turnId });
    const result = Runtime.contracts.boot(canonicalBoot({
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890',
            messages: [user, reply]
        }
    }));
    same('turn_only', result.messages[0].presentation.image_scope);
    same('image/jpeg', result.messages[0].presentation.images[0].mime_type);
    same(123456, result.messages[0].presentation.images[0].byte_length);
    same('المنتج السابق', result.messages[0].presentation.reply_quote);
});

test('canonical image presentation rejects contradictory scope', () => {
    const { Runtime } = loadRuntime();
    const user = canonicalUserMessage();
    user.presentation = {
        image_scope: 'none',
        images: [{ kind: 'image', mime_type: 'image/png', byte_length: 1024 }],
        reply_quote: ''
    };
    let caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            conversation: {
                id: '22222222-2222-4222-8222-222222222222',
                token: 'conversation-token-1234567890',
                messages: [user]
            }
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('non-durable turn response remains transient outside canonical history', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const turnId = '22222222-2222-4222-8222-222222222224';
    const pending = canonicalUserMessage('Limited request', turnId, '23232323-2323-4232-8232-232323232323');
    const reply = canonicalMessage('Rate limited', { turn_id: turnId, outcome: 'safe_failure', failure_code: 'rate_limited', state_uncertain: false });
    const serverHistory = [canonicalMessage('Canonical earlier answer', { id: '24242424-2424-4242-8242-242424242424', turn_id: '25252525-2525-4252-8252-252525252525' })];
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 's', conversation: {}, messages: [], cartAvailable: true, cart: canonicalCart(), capabilities: { chat_ready: true }, widget: {} });
    store.dispatch({ type: 'TURN_START', userMessage: pending });
    store.dispatch(turnSuccessAction(reply, {
        turnCommitted: false,
        messages: serverHistory,
        pendingUserMessage: pending
    }));
    same(3, store.getState().messages.length);
    same('Canonical earlier answer', store.getState().messages[0].text);
    same('Limited request', store.getState().messages[1].text);
    same('Rate limited', store.getState().messages[2].text);
});

test('cart refresh failure preserves the last display snapshot', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const cart = { item_count: 2, formatted_total: '$20' };
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 's', conversation: {}, messages: [], cartAvailable: true, cart, capabilities: {}, widget: {} });
    store.dispatch(turnSuccessAction(canonicalMessage('Answer'), { cartAvailable: false, cartNotice: 'Refresh failed', cart: null }));
    same(cart, store.getState().cart);
    same('Refresh failed', store.getState().cartNotice);
});

test('failed attachment reads release their reserved slot', () => {
    const notices = [];
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue({ maxImages: 1, maxImageBytes: 1000 }, () => {}, message => notices.push(message));
    queue.select([fakeImageFile('image/png', 1, 1, 'data:image/png;base64,eA==', { failHeader: true })]);
    same(0, queue.publicEntries().length);
    same(1, notices.length);
    queue.select([fakeImageFile('image/png', 1, 1, 'data:image/png;base64,eA==')]);
    same(1, queue.readyPayloads().length);
});

test('selecting one image creates exactly one queue entry and one payload', () => {
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue(
        { maxImages: 2, maxImageBytes: 1000 }, () => {}, () => {}
    );
    queue.select([fakeImageFile('image/png', 1, 1, 'data:image/png;base64,QUJD')]);
    same(1, queue.publicEntries().length);
    same(1, queue.readyPayloads().length);
});

test('browser boot identity is stable and separate from conversation authority', () => {
    const { Runtime, storage } = loadRuntime();
    const first = new Runtime.ClientIdentityStore('key');
    const id = first.id();
    ok(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(id));
    same(id, storage.key_client);
    same(id, new Runtime.ClientIdentityStore('key').id());
    storage.key_client = 'not-a-uuid';
    const replacement = new Runtime.ClientIdentityStore('key').id();
    ok(replacement !== 'not-a-uuid');

    const continuity = new Runtime.BrowserContinuitySecretStore('key');
    const secret = continuity.secret();
    ok(/^[A-Za-z0-9_-]{43}$/.test(secret));
    same(secret, JSON.parse(storage.key_continuity_secret).secret);
    same(secret, new Runtime.BrowserContinuitySecretStore('key').secret());
    same(false, continuity.credentials().established);
    ok(continuity.acknowledge(secret));
    const rotated = continuity.rotate();
    ok(rotated !== secret);
    same(secret, continuity.credentials().previous_secret);
    same(secret, new Runtime.BrowserContinuitySecretStore('key').credentials().previous_secret);
    ok(continuity.acknowledge(rotated));
    same('', continuity.credentials().previous_secret);

    // A shape-valid but non-canonical 32-byte base64url value must not be
    // replayed forever to the stricter server decoder.
    storage.key_continuity_secret = 'A'.repeat(42) + '_';
    const canonicalReplacement = new Runtime.BrowserContinuitySecretStore('key').secret();
    ok(canonicalReplacement !== 'A'.repeat(42) + '_');
    ok(/[AEIMQUYcgkosw048]$/.test(canonicalReplacement));
});

test('boot replaces lost or corrupt browser continuity storage without inventing rotation proof', async () => {
    for (const corrupted of [false, true]) {
        const bodies = [];
        const key = corrupted ? 'corrupt-secret-key' : 'lost-secret-key';
        const runtime = loadRuntime((url, options) => {
            bodies.push(JSON.parse(options.body));
            return Promise.resolve(response(200, canonicalBoot()));
        });
        const app = new runtime.Runtime.AssistantApp({
            bootUrl: 'https://example.test/boot',
            chatUrl: 'https://example.test/chat',
            storageKey: key
        });
        const displaced = app.browserContinuity.secret();
        ok(app.browserContinuity.acknowledge(displaced));
        app.continuity.write({
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890'
        });
        if (corrupted) {
            runtime.storage[`${key}_continuity_secret`] = '{broken';
        } else {
            delete runtime.storage[`${key}_continuity_secret`];
        }

        await app.boot(false);

        same(1, bodies.length);
        ok(bodies[0].browser_continuity_secret !== displaced);
        same(false, Object.prototype.hasOwnProperty.call(
            bodies[0], 'previous_browser_continuity_secret'
        ));
        same('22222222-2222-4222-8222-222222222222', bodies[0].conversation_id);
        same('ready', app.store.getState().phase);
        const persisted = JSON.parse(runtime.storage[`${key}_continuity_secret`]);
        same(bodies[0].browser_continuity_secret, persisted.secret);
        same('', persisted.previous_secret);
        same(true, persisted.established);
    }
});

test('browser identity and continuity credentials fall back to current-document memory', () => {
    const { Runtime } = loadRuntime(undefined, { localStorageFailure: true });
    const identity = new Runtime.ClientIdentityStore('key');
    const id = identity.id();
    ok(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(id));
    same(id, new Runtime.ClientIdentityStore('key').id());
    same('memory', identity.persistenceMode());

    const continuity = new Runtime.BrowserContinuitySecretStore('key');
    const secret = continuity.secret();
    ok(/^[A-Za-z0-9_-]{43}$/.test(secret));
    same(secret, new Runtime.BrowserContinuitySecretStore('key').secret());
    same('memory', continuity.persistenceMode());

    const anonymousIdentity = new Runtime.ClientIdentityStore('');
    const anonymousSecret = new Runtime.BrowserContinuitySecretStore('');
    ok(/^[a-f0-9-]{36}$/.test(anonymousIdentity.id()));
    ok(/^[A-Za-z0-9_-]{43}$/.test(anonymousSecret.secret()));
    same('memory', anonymousIdentity.persistenceMode());
    same('memory', anonymousSecret.persistenceMode());
});

test('assistant boot always submits the stable browser identity', async () => {
    const bodies = [];
    const { Runtime } = loadRuntime((url, options) => {
        bodies.push(JSON.parse(options.body));
        return Promise.resolve(response(200, canonicalBoot()));
    });
    const app = new Runtime.AssistantApp({
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'key',
        maxImages: 2,
        maxImageBytes: 524288
    });
    await app.boot(false);
    same(1, bodies.length);
    ok(/^[a-f0-9-]{36}$/.test(bodies[0].client_instance_id));
    ok(/^[A-Za-z0-9_-]{43}$/.test(bodies[0].browser_continuity_secret));
    same('', bodies[0].pending_turn_id);
    same(3, Object.keys(bodies[0]).length);
});

test('expired stored conversation is replaced after one exact boot rejection', async () => {
    const bodies = [];
    let attempt = 0;
    const replacement = {
        id: '33333333-3333-4333-8333-333333333333',
        token: 'replacement-conversation-token-1234567890',
        messages: []
    };
    const { Runtime, storage } = loadRuntime((url, options) => {
        bodies.push(JSON.parse(options.body));
        attempt += 1;
        if (attempt === 1) {
            return Promise.resolve(response(401, {
                ok: false,
                code: 'conversation_invalid',
                message: 'Expired'
            }));
        }
        return Promise.resolve(response(200, canonicalBoot({ conversation: replacement })));
    });
    const app = new Runtime.AssistantApp({
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'expired-conversation-key'
    });
    app.continuity.write({
        id: '22222222-2222-4222-8222-222222222222',
        token: 'expired-conversation-token-1234567890'
    });

    await app.boot(false);

    same(2, bodies.length);
    same('22222222-2222-4222-8222-222222222222', bodies[0].conversation_id);
    same(false, Object.prototype.hasOwnProperty.call(bodies[1], 'conversation_id'));
    same(bodies[0].client_instance_id, bodies[1].client_instance_id);
    same(bodies[0].browser_continuity_secret, bodies[1].browser_continuity_secret);
    same('ready', app.store.getState().phase);
    same(replacement.id, app.store.getState().conversation.id);
    same(replacement.id, JSON.parse(storage['expired-conversation-key']).conversation_id);
});

test('bodyless unresolved identity is sent on boot and cannot become ready while absent', async () => {
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const loaded = loadRuntime(undefined, { manualTimers: true });
    const app = new loaded.Runtime.AssistantApp({ storageKey: 'identity-recheck-key' });
    app.continuity.write(conversation);
    app.store.dispatch({
        type: 'BOOT_SUCCESS',
        sessionToken: SESSION_TOKEN,
        conversation,
        messages: [],
        cartAvailable: true,
        cart: canonicalCart(),
        capabilities: canonicalBoot().capabilities,
        widget: {},
        retryRecheckRequired: true,
        retryRecheckIdentity: {
            turnId, conversation,
            startedAtMs: Date.now(),
            guardUntilMs: Date.now() + 60000
        }
    });
    const requests = [];
    app.api.boot = payload => {
        requests.push(payload);
        return Promise.resolve(canonicalBoot({
            conversation: Object.assign({}, conversation, { messages: [] }),
            pending_turn: { id: turnId, status: 'absent' }
        }));
    };

    await app.boot(false);

    same(turnId, requests[0].pending_turn_id);
    same('blocked', app.store.getState().phase);
    same(true, app.store.getState().retryRecheckRequired);
    same(turnId, app.store.getState().retryRecheckIdentity.turnId);

    const user = canonicalUserMessage('request', turnId);
    const assistant = canonicalMessage('complete', { turn_id: turnId });
    app.api.boot = payload => {
        requests.push(payload);
        return Promise.resolve(canonicalBoot({
            conversation: Object.assign({}, conversation, { messages: [user, assistant] }),
            pending_turn: { id: turnId, status: 'terminal' }
        }));
    };
    await app.boot(false);
    same(turnId, requests[1].pending_turn_id);
    same('ready', app.store.getState().phase);
    same(false, app.store.getState().retryRecheckRequired);
});

test('bodyless absent turn is forgotten only after its execution guard expires', async () => {
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const { Runtime } = loadRuntime(undefined, { manualTimers: true });
    const app = new Runtime.AssistantApp({ storageKey: 'expired-identity-key' });
    app.continuity.write(conversation);
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN, conversation, messages: [],
        cartAvailable: true, cart: canonicalCart(), capabilities: canonicalBoot().capabilities,
        widget: {}, retryRecheckRequired: true,
        retryRecheckIdentity: {
            turnId, conversation,
            startedAtMs: Date.now() - 120000,
            guardUntilMs: Date.now() - 1000
        }
    });
    app.api.boot = () => Promise.resolve(canonicalBoot({
        conversation: Object.assign({}, conversation, { messages: [] }),
        pending_turn: { id: turnId, status: 'absent' }
    }));
    await app.boot(false);
    same('ready', app.store.getState().phase);
    same(false, app.store.getState().retryRecheckRequired);
});

test('fresh conversation rotates both browser boot credentials', async () => {
    const bodies = [];
    const { Runtime } = loadRuntime((url, options) => {
        bodies.push(JSON.parse(options.body));
        return Promise.resolve(response(200, canonicalBoot()));
    });
    const app = new Runtime.AssistantApp({
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'rotate-key'
    });
    await app.boot(false);
    await app.boot(true);
    same(2, bodies.length);
    ok(bodies[0].client_instance_id !== bodies[1].client_instance_id);
    ok(bodies[0].browser_continuity_secret !== bodies[1].browser_continuity_secret);
    same(bodies[0].browser_continuity_secret, bodies[1].previous_browser_continuity_secret);
});

test('conversation export restores the widget when local file creation fails', async () => {
    const { Runtime } = loadRuntime();
    const app = new Runtime.AssistantApp({ storageKey: 'privacy-export-key' });
    const boot = canonicalBoot();
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN,
        conversation: boot.conversation, messages: [],
        cartAvailable: true, cart: boot.cart,
        capabilities: boot.capabilities, widget: boot.widget
    });
    app.api.exportConversation = () => Promise.resolve(canonicalExportPage());
    same(false, await app.exportConversation());
    same(false, app.store.getState().privacyBusy);
    same(null, app.privacyPromise);
    same('ready', app.store.getState().phase);
});

test('malformed conversation deletion success cannot wedge privacy state', async () => {
    const { Runtime, window } = loadRuntime();
    window.confirm = () => true;
    const app = new Runtime.AssistantApp({ storageKey: 'privacy-delete-key' });
    const boot = canonicalBoot();
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN,
        conversation: boot.conversation, messages: [],
        cartAvailable: true, cart: boot.cart,
        capabilities: boot.capabilities, widget: boot.widget
    });
    app.api.deleteConversation = () => Promise.resolve({ ok: true, deleted: 'yes' });
    same(false, await app.deleteConversation());
    same(false, app.store.getState().privacyBusy);
    same(null, app.privacyPromise);
    same('ready', app.store.getState().phase);
});

test('conversation deletion reports a failed replacement boot without claiming success', async () => {
    const { Runtime, window } = loadRuntime();
    window.confirm = () => true;
    const app = new Runtime.AssistantApp({ storageKey: 'privacy-reboot-key' });
    const boot = canonicalBoot();
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN,
        conversation: boot.conversation, messages: [],
        cartAvailable: true, cart: boot.cart,
        capabilities: boot.capabilities, widget: boot.widget
    });
    app.api.deleteConversation = () => Promise.resolve({ ok: true, deleted: true });
    app.boot = () => {
        app.store.dispatch({ type: 'BOOT_FAILURE', message: 'boot failed' });
        return Promise.resolve(null);
    };
    same(false, await app.deleteConversation());
    same(null, app.privacyPromise);
    same('failed', app.store.getState().phase);
    ok(app.store.getState().status.includes('تم حذف المحادثة'));
});

test('force-new ignores continuity published by another tab while reset waits', async () => {
    const bodies = [];
    const runtime = loadRuntime((url, options) => {
        bodies.push(JSON.parse(options.body));
        return Promise.resolve(response(200, canonicalBoot()));
    }, { manualTimers: true });
    const app = new runtime.Runtime.AssistantApp({
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'force-race-key'
    });
    const oldClient = app.clientIdentity.id();
    const oldSecret = app.browserContinuity.secret();
    app.browserContinuity.acknowledge(oldSecret);
    app.continuity.write({
        id: '22222222-2222-4222-8222-222222222222',
        token: 'old-conversation-token-1234567890'
    });

    const pending = app.boot(true);
    app.continuity.write({
        id: '33333333-3333-4333-8333-333333333333',
        token: 'racing-conversation-token-1234567890'
    });
    ok(runtime.fireTimer(30), 'force-new coordination settle timer was not scheduled');
    for (let index = 0; index < 16; index += 1) await Promise.resolve();
    await pending;

    same(1, bodies.length);
    same(false, Object.prototype.hasOwnProperty.call(bodies[0], 'conversation_id'));
    same(false, Object.prototype.hasOwnProperty.call(bodies[0], 'conversation_token'));
    ok(bodies[0].client_instance_id !== oldClient);
    ok(bodies[0].browser_continuity_secret !== oldSecret);
});

test('continuity storage persists canonical credentials only', () => {
    const { Runtime, storage } = loadRuntime();
    const continuity = new Runtime.ContinuityStore('key');
    continuity.write({
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890',
        product_id: 99,
        cart: { authority: true }
    });
    const parsed = JSON.parse(storage.key);
    same('22222222-2222-4222-8222-222222222222', parsed.conversation_id);
    same('conversation-token-1234567890', parsed.conversation_token);
    ok(/^[a-f0-9-]{36}$/.test(parsed.revision));
    same(3, Object.keys(parsed).length);
});

test('a stale tab response cannot overwrite newer shared conversation authority', () => {
    const { Runtime, storage } = loadRuntime();
    const continuity = new Runtime.ContinuityStore('stale-key');
    const older = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'older-conversation-token-1234567890'
    };
    const newer = {
        id: '33333333-3333-4333-8333-333333333333',
        token: 'newer-conversation-token-1234567890'
    };
    ok(continuity.write(older));
    storage['stale-key'] = JSON.stringify({
        conversation_id: newer.id,
        conversation_token: newer.token,
        revision: '44444444-4444-4444-8444-444444444444'
    });
    same(false, continuity.write(older));
    same(newer.id, continuity.read().conversation_id);
});

test('a reset tombstone fences a stale in-flight tab before replacement boot finishes', () => {
    const local = Object.create(null);
    const firstLoaded = loadRuntime(undefined, { localStorageData: local });
    const secondLoaded = loadRuntime(undefined, { localStorageData: local });
    const stale = new firstLoaded.Runtime.ContinuityStore('reset-race');
    const resetting = new secondLoaded.Runtime.ContinuityStore('reset-race');
    const older = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'older-conversation-token-1234567890'
    };
    const newer = {
        id: '33333333-3333-4333-8333-333333333333',
        token: 'newer-conversation-token-1234567890'
    };
    ok(stale.write(older));
    same(older.id, resetting.read().conversation_id);
    secondLoaded.Runtime.util.randomId(); // Independent test realms must not reuse their first deterministic UUID.
    resetting.clear();
    same(false, stale.write(older));
    same(0, Object.keys(resetting.read()).length);
    ok(resetting.write(newer));
    same(newer.id, resetting.read().conversation_id);
});

test('only the interacted open widget traps Tab and handles Escape', () => {
    const { Runtime, documentListeners } = loadRuntime();
    function target() {
        const handlers = Object.create(null);
        return {
            handlers,
            disabled: false,
            files: [],
            addEventListener(type, listener) {
                if (!handlers[type]) handlers[type] = [];
                handlers[type].push(listener);
            },
            removeEventListener(type, listener) {
                handlers[type] = (handlers[type] || []).filter(item => item !== listener);
            },
            focus() {}
        };
    }
    const app = {
        active: null,
        activateView(view) { this.active = view; },
        isActiveView(view) { return this.active === view; },
        canAttach() { return false; }, claimDraftOwner() {},
        selectFiles() {}, exportConversation() {}, deleteConversation() {}
    };
    function view() {
        const instance = {
            app, opened: true,
            launcher: target(), closeButton: target(), replyCancel: target(),
            privacyButton: target(), exportConversationButton: target(),
            deleteConversationButton: target(), privacyPanel: { hidden: true },
form: target(), textarea: target(),
            attachButton: target(), fileInput: target(), panel: target(),
            mobileModalQuery: null,
            trapCount: 0, closeCount: 0,
            trapFocus() { this.trapCount += 1; },
            toggle(open) { if (!open) this.closeCount += 1; },
            submitComposer() {}, clearReplyContext() {}, resizeComposer() {},
            updateComposerState() {}
        };
        Runtime.WidgetView.prototype.bind.call(instance);
        return instance;
    }
    const first = view();
    const second = view();
    const keyHandlers = documentListeners.keydown.slice(-2);
    app.activateView(first);
    keyHandlers.forEach(handler => handler({ key: 'Tab' }));
    same(1, first.trapCount);
    same(0, second.trapCount);

    second.panel.handlers.pointerdown[0]();
    keyHandlers.forEach(handler => handler({ key: 'Tab' }));
    same(1, first.trapCount);
    same(1, second.trapCount);
    keyHandlers.forEach(handler => handler({ key: 'Escape' }));
    same(0, first.closeCount);
    same(1, second.closeCount);
    first.panel.handlers.focusin[0]();
    same(first, app.active);
});
