'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('copy completion has one latest confirmed button and destroy clears it', async () => {
    const { Runtime, window } = loadRuntime(undefined, { manualTimers: true });
    const pending = [];
    window.navigator = {
        clipboard: {
            writeText() {
                return new Promise((resolve, reject) => pending.push({ resolve, reject }));
            }
        }
    };
    function button() {
        const values = new Set();
        return {
            classList: {
                add(value) { values.add(value); },
                remove(value) { values.delete(value); },
                contains(value) { return values.has(value); }
            }
        };
    }
    const notices = [];
    const view = {
        destroyed: false, copySequence: 0, copyTimer: null, confirmedCopyButton: null,
        app: {
            notice(value) { notices.push(value); },
            store: { getState() { return { status: notices[notices.length - 1] || '' }; } }
        }
    };
    const first = button();
    const second = button();
    Runtime.WidgetView.prototype.copyMessage.call(view, 'first', first);
    pending[0].resolve();
    await Promise.resolve();
    same(true, first.classList.contains('is-confirmed'));
    Runtime.WidgetView.prototype.copyMessage.call(view, 'second', second);
    pending[1].resolve();
    await Promise.resolve();
    same(false, first.classList.contains('is-confirmed'));
    same(true, second.classList.contains('is-confirmed'));

    const third = button();
    const fourth = button();
    Runtime.WidgetView.prototype.copyMessage.call(view, 'third', third);
    Runtime.WidgetView.prototype.copyMessage.call(view, 'fourth', fourth);
    pending[3].resolve();
    await Promise.resolve();
    pending[2].resolve();
    await Promise.resolve();
    same(false, third.classList.contains('is-confirmed'));
    same(true, fourth.classList.contains('is-confirmed'));

    let staleFallbacks = 0;
    view.copyFallback = () => { staleFallbacks += 1; };
    Runtime.WidgetView.prototype.copyMessage.call(view, 'stale failure', button());
    Runtime.WidgetView.prototype.copyMessage.call(view, 'new success', fourth);
    pending[5].resolve();
    await Promise.resolve();
    pending[4].reject(new Error('older clipboard failure'));
    await Promise.resolve();
    await Promise.resolve();
    same(0, staleFallbacks);

    Object.assign(view, {
        clearCarouselObservers() {}, expiryTimer: null, documentKeydown: null,
        panelInteraction: null, mobileModalQuery: null, mobileModalListener: null,
        opened: true, syncModalState() {},
        root: { classList: { remove() {} }, removeChild() {} },
        clearReplyContext() {}, launcher: null, panel: null
    });
    Runtime.WidgetView.prototype.destroy.call(view);
    same(false, fourth.classList.contains('is-confirmed'));
});

test('reply context is a structured field and never rewrites customer message bytes', () => {
    const { Runtime } = loadRuntime();
    const app = new Runtime.AssistantApp({ storageKey: 'reply-context-key' });
    app.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 'session',
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890'
        },
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0 },
        widget: {}
    });
    let body = null;
    app.performTurn = envelope => { body = JSON.parse(envelope.body); };
    const exact = '> كتابة عميل عادية\n\nافرغ السلة بالكامل';
    same(true, app.submitMessage(
        exact,
        { reply_quote: 'تفاصيل المنتج السابق' },
        null,
        {
            text: 'تفاصيل المنتج السابق',
            message_id: '33333333-3333-4333-8333-333333333333',
            product_index: 2
        }
    ));
    same(exact, body.message);
    same('تفاصيل المنتج السابق', body.reply_context.text);
    same('33333333-3333-4333-8333-333333333333', body.reply_context.message_id);
    same(2, body.reply_context.product_index);
    same(exact, app.store.getState().messages[0].text);
    same('تفاصيل المنتج السابق', app.store.getState().messages[0].presentation.reply_quote);
});

test('reload replays the persisted exact absent turn instead of minting a new turn ID', async () => {
    const local = Object.create(null);
    const session = Object.create(null);
    const config = {
        bootUrl: 'https://example.test/boot',
        chatUrl: 'https://example.test/chat',
        storageKey: 'reload-key',
        maxImages: 0,
        maxImageBytes: 0
    };
    const firstRuntime = loadRuntime(undefined, {
        config, localStorageData: local, sessionStorageData: session
    }).Runtime;
    const conversation = {
        id: '22222222-2222-4222-8222-222222222222',
        token: 'conversation-token-1234567890'
    };
    const first = new firstRuntime.AssistantApp(config);
    first.continuity.write(conversation);
    first.store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN, conversation, messages: [],
        cartAvailable: true, cart: canonicalCart(), capabilities: {
            chat_ready: true, images: false, max_images: 0, max_image_bytes: 0,
            cart_mutations: { available: true, code: 'available', notice: '' }
        }, widget: {}
    });
    first.performTurn = () => Promise.resolve();
    ok(first.submitMessage('أضف المنتج', {}));
    const pending = first.continuity.readPending();
    const exact = first.retryStore.get(pending.retry_id).body;

    const requests = [];
    const secondLoaded = loadRuntime((url, options) => {
        requests.push(JSON.parse(options.body));
        return Promise.resolve(response(200, canonicalBoot({
            conversation: Object.assign({}, conversation, { messages: [] }),
            pending_turn: { id: pending.turn_id, status: 'absent' }
        })));
    }, { config, localStorageData: local, sessionStorageData: session });
    const second = new secondLoaded.Runtime.AssistantApp(config);
    let replayed = null;
    second.performTurn = (envelope, retryId) => {
        replayed = { envelope, retryId };
        return Promise.resolve();
    };
    await second.boot(false);
    same(pending.turn_id, requests[0].pending_turn_id);
    same(exact, replayed.envelope.body);
    same(pending.retry_id, replayed.retryId);
    same(pending.turn_id, JSON.parse(replayed.envelope.body).client_turn_id);
});

test('attachment removal aborts an in-flight FileReader and releases its slot', () => {
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue(
        { maxImages: 1, maxImageBytes: 1000 }, () => {}, () => {}
    );
    const file = fakeImageFile('image/png', 1, 1, 'data:image/png;base64,QUJD', {
        deferRead: true
    });
    queue.select([file]);
    const id = queue.publicEntries()[0].id;
    queue.remove(id);
    same(true, file.reader.aborted);
    same(0, queue.publicEntries().length);
});

test('repeated attachment churn never starts more than one concurrent bitmap decode', async () => {
    const pending = [];
    let active = 0;
    let maximum = 0;
    const loaded = loadRuntime(undefined, {
        createImageBitmap: () => new Promise(resolve => {
            active += 1;
            maximum = Math.max(maximum, active);
            pending.push(() => {
                active -= 1;
                resolve({ close() {} });
            });
        })
    });
    const queue = new loaded.Runtime.AttachmentQueue(
        { maxImages: 1, maxImageBytes: 1000 }, () => {}, () => {}
    );
    for (let index = 0; index < 5; index += 1) {
        const file = fakeImageFile('image/png', 1, 1, 'data:image/png;base64,QUJD');
        queue.select([file]);
        queue.remove(queue.publicEntries()[0].id);
    }
    same(1, pending.length);
    same(1, maximum);
    while (pending.length > 0) {
        pending.shift()();
        await Promise.resolve();
        await Promise.resolve();
    }
    same(0, active);
    same(1, maximum);
});

test('listener exceptions cannot turn committed transport state back into failure', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.subscribe(() => { throw new Error('view failed'); });
    store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: SESSION_TOKEN,
        conversation: { id: '22222222-2222-4222-8222-222222222222', token: 'conversation-token-1234567890' },
        messages: [], cartAvailable: true, cart: canonicalCart(),
        capabilities: { chat_ready: true }, widget: {}
    });
    const message = canonicalMessage('Committed');
    store.dispatch(turnSuccessAction(message));
    same('ready', store.getState().phase);
    same('Committed', store.getState().messages[1].text);
});

test('malformed persisted continuity is removed instead of poisoning boot', () => {
    const { Runtime, storage } = loadRuntime();
    const continuity = new Runtime.ContinuityStore('key');
    storage.key = JSON.stringify({ conversation_id: 'not-a-uuid', conversation_token: 'bad token' });
    same(0, Object.keys(continuity.read()).length);
    same(undefined, storage.key);
    same(false, continuity.write({ id: 'not-a-uuid', token: 'bad token' }));
    same(undefined, storage.key);
});

test('API client rejects credential-bearing endpoints before fetch', async () => {
    let fetched = false;
    const { Runtime } = loadRuntime(() => { fetched = true; return Promise.reject(new Error('unexpected')); });
    const api = new Runtime.ApiClient({ chatUrl: 'https://user:password@example.test/chat' });
    let caught = null;
    try { await api.sendTurn(api.envelope({ client_turn_id: 'x' }), 'session', 2); } catch (error) { caught = error; }
    same(false, fetched);
    ok(caught instanceof Runtime.ApiError);
    same('client_config_invalid', caught.code);
});
