'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('successful turn replaces the display cart with the returned snapshot', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const oldCart = { item_count: 1, formatted_total: '$10' };
    const newCart = { item_count: 3, formatted_total: '$30' };
    store.dispatch({ type: 'BOOT_SUCCESS', sessionToken: 's', conversation: {}, messages: [], cartAvailable: true, cart: oldCart, capabilities: {}, widget: {} });
    store.dispatch(turnSuccessAction(canonicalMessage('Answer'), { cartAvailable: true, cartNotice: '', cart: newCart }));
    same(newCart, store.getState().cart);
    same('', store.getState().cartNotice);
});

test('API client rejects cross-origin endpoints before fetch', async () => {
    let fetched = false;
    const { Runtime } = loadRuntime(() => { fetched = true; return Promise.reject(new Error('unexpected')); });
    const api = new Runtime.ApiClient({ chatUrl: 'https://evil.test/chat' });
    let caught = null;
    try { await api.sendTurn(api.envelope({ client_turn_id: 'x' }), 'session', 2); } catch (error) { caught = error; }
    same(false, fetched);
    ok(caught instanceof Runtime.ApiError);
    same('client_config_invalid', caught.code);
});

test('boot contract rejects scalar capability flags', () => {
    const { Runtime } = loadRuntime();
    let caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            capabilities: { chat_ready: 'false', images: false, max_images: 0, max_image_bytes: 0, cart_mutations: { available: true, code: 'available', notice: '' } }
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('boot contract rejects image capability above the first-release memory bound', () => {
    const { Runtime } = loadRuntime();
    let caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524289, cart_mutations: { available: true, code: 'available', notice: '' } }
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('disabled image capability requires zero limits', () => {
    const { Runtime } = loadRuntime();
    let caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            capabilities: { chat_ready: true, images: false, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('server image maximum can disable attachment capacity', () => {
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue({ maxImages: 2, maxImageBytes: 1000 }, () => {}, () => {});
    queue.setLimits(0, 0);
    queue.select([{ name: 'good.png', type: 'image/png', size: 10, result: 'data:image/png;base64,eA==' }]);
    same(0, queue.publicEntries().length);
});

test('canonical boot response is accepted and projected to display-only state', () => {
    const { Runtime } = loadRuntime();
    const result = Runtime.contracts.boot(canonicalBoot());
    same(SESSION_TOKEN, result.sessionToken);
    same(0, result.cart.item_count);
    same('مساعد المتجر', result.widget.title);
    same(null, result.pendingTurn);
    same(9, Object.keys(result).length);
});

test('closed boot contract rejects removed legacy response fields', () => {
    const { Runtime } = loadRuntime();
    const payload = canonicalBoot();
    payload.session.expires_at = 1700000000;
    let caught = null;
    try { Runtime.contracts.boot(payload); } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('verified action result is rejected without one matching receipt', () => {
    const { Runtime } = loadRuntime();
    const message = canonicalMessage('Added', { outcome: 'action_verified' });
    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(message));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('verified action result accepts one receipt whose message matches exactly', () => {
    const { Runtime } = loadRuntime();
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Added',
        proof: {
            commands: [{ type: 'add', item: 'Product', quantity: 1 }],
            cart_count: 1,
            cart_total: '$10',
            changed_line_count: 1,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    const result = Runtime.contracts.turn(canonicalTurnResponse(
        canonicalMessage('Added', { outcome: 'action_verified', receipts: [receipt] })
    ));
    same('action_verified', result.message.outcome);
    same('Added', result.message.text);
});

test('replace receipt is accepted identically for the live response and exact replay', () => {
    const { Runtime } = loadRuntime();
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Replaced securely',
        proof: {
            commands: [{ type: 'replace', item: 'Replacement product', quantity: 3 }],
            cart_count: 3,
            cart_total: '$30',
            changed_line_count: 2,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    const exactBody = JSON.stringify(canonicalTurnResponse(
        canonicalMessage('Replaced securely', { outcome: 'action_verified', receipts: [receipt] }),
        { cart: {
            item_count: 3,
            formatted_total: '$30',
            cart_url: 'https://example.test/cart',
            checkout_url: 'https://example.test/checkout'
        } }
    ));
    const first = Runtime.contracts.turn(JSON.parse(exactBody));
    const replay = Runtime.contracts.turn(JSON.parse(exactBody));
    same('replace', first.message.receipts[0].proof.commands[0].type);
    same(3, first.message.receipts[0].proof.commands[0].quantity);
    same(JSON.stringify(first), JSON.stringify(replay));
});

test('boot transcript accepts a committed replace receipt after reload', () => {
    const { Runtime } = loadRuntime();
    const turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Replaced securely',
        proof: {
            commands: [{ type: 'replace', item: 'Replacement product', quantity: 2 }],
            cart_count: 2,
            cart_total: '$20',
            changed_line_count: 2,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    const assistant = canonicalMessage('Replaced securely', {
        turn_id: turnId,
        outcome: 'action_verified',
        receipts: [receipt]
    });
    const result = Runtime.contracts.boot(canonicalBoot({
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890',
            messages: [canonicalUserMessage('Replace it', turnId), assistant]
        },
        cart: {
            item_count: 2,
            formatted_total: '$20',
            cart_url: 'https://example.test/cart',
            checkout_url: 'https://example.test/checkout'
        }
    }));
    same(2, result.messages.length);
    same('action_verified', result.messages[1].outcome);
    same('replace', result.messages[1].receipts[0].proof.commands[0].type);
    same(2, result.messages[1].receipts[0].proof.commands[0].quantity);
});

test('replace receipt requires its canonical whole-number quantity', () => {
    const { Runtime } = loadRuntime();
    const base = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Replaced securely',
        proof: {
            commands: [{ type: 'replace', item: 'Replacement product' }],
            cart_count: 1,
            cart_total: '$10',
            changed_line_count: 2,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(canonicalMessage('Replaced securely', {
            outcome: 'action_verified', receipts: [base]
        })));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('receipt command quantities must be integers on the frontend boundary', () => {
    const { Runtime } = loadRuntime();
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Added',
        proof: {
            commands: [{ type: 'add', item: 'Product', quantity: 1.5 }],
            cart_count: 1,
            cart_total: '$10',
            changed_line_count: 1,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(
            canonicalMessage('Added', { outcome: 'action_verified', receipts: [receipt] })
        ));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('receipt proof rejects unknown or over-permissive command fields', () => {
    const { Runtime } = loadRuntime();
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Added',
        proof: {
            commands: [{ type: 'add', quantity: 1, product_id: 7 }],
            cart_count: 1,
            cart_total: '$10',
            changed_line_count: 1,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(
            canonicalMessage('Added', { outcome: 'action_verified', receipts: [receipt] })
        ));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
});

test('cart availability and notice must describe one consistent state', () => {
    const { Runtime } = loadRuntime();
    let caught = null;
    try {
        Runtime.contracts.turn(canonicalTurnResponse(canonicalMessage(), {
            cart: null, cart_available: false, cart_notice: ''
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
});

test('credential-bearing display URLs are rejected', () => {
    const { Runtime } = loadRuntime();
    same('', Runtime.util.safeUrl('https://user:password@example.test/private'));
});

test('unsafe JavaScript execution sinks are absent', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../../assets/js/widget.js'), 'utf8');
    for (const sink of ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(']) {
        ok(!source.includes(sink), sink);
    }
});

test('late shortcode rendering prints its registered stylesheet after wp_head', () => {
    const source = fs.readFileSync(
        path.join(__dirname, '../../../src/Presentation/Widget/Widget.php'),
        'utf8'
    );
    ok(source.includes("did_action('wp_head') > 0"));
    ok(source.includes("wp_style_is('ysai-widget', 'done')"));
    ok(source.includes("wp_print_styles(array('ysai-widget'))"));
    ok(!source.includes("function_exists('is_checkout')"));
    ok(!source.includes("function_exists('is_wc_endpoint_url')"));
});

test('attachment queue keeps request bytes separate from local presentation previews', () => {
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue({ maxImages: 2, maxImageBytes: 1000 }, () => {}, () => {});
    const source = 'data:image/png;base64,QUJD';
    queue.select([fakeImageFile('image/png', 1, 1, source, { name: 'sample.png', size: 3 })]);
    same('QUJD', queue.readyPayloads()[0].data);
    same('data:image/webp;base64,QUJD', queue.readyPreviews()[0].src);
    same('sample.png', queue.readyPreviews()[0].alt);
});

test('source header policy accepts common phone dimensions for PNG JPEG and WebP', () => {
    const { Runtime } = loadRuntime();
    for (const mimeType of ['image/png', 'image/jpeg', 'image/webp']) {
        const queue = new Runtime.AttachmentQueue({
            maxImages: 1,
            maxImageBytes: 1000,
            maxSourceImageWidth: 4096,
            maxSourceImageHeight: 4096,
            maxSourceImagePixels: 12582912
        }, () => {}, () => {});
        queue.select([fakeImageFile(mimeType, 4032, 3024, `data:${mimeType};base64,QUJD`)]);
        same(1, queue.readyPayloads().length, mimeType);
    }
});

test('compressed pixel bombs are rejected from the bounded header before image decode', () => {
    const notices = [];
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue({
        maxImages: 1,
        maxImageBytes: 1000,
        maxSourceImageWidth: 4096,
        maxSourceImageHeight: 4096,
        maxSourceImagePixels: 12582912
    }, () => {}, message => notices.push(message));
    const file = fakeImageFile('image/jpeg', 8000, 8000, 'data:image/jpeg;base64,QUJD', { size: 375283 });
    queue.select([file]);
    same(0, queue.publicEntries().length);
    same(0, file.dataUrlReads);
    same(0, file.sliceStart);
    same(262144, file.sliceEnd);
    same(1, notices.length);
});

test('declared MIME type must match a verifiable bounded image header', () => {
    const notices = [];
    const { Runtime } = loadRuntime();
    const queue = new Runtime.AttachmentQueue({ maxImages: 1, maxImageBytes: 1000 }, () => {}, message => notices.push(message));
    const file = fakeImageFile('image/png', 1, 1, 'data:image/png;base64,QUJD');
    const jpeg = jpegHeader(1, 1);
    file.slice = () => ({ arrayBufferResult: jpeg.buffer });
    queue.select([file]);
    same(0, queue.publicEntries().length);
    same(0, file.dataUrlReads);
    same(1, notices.length);
});

test('boot contract requires a closed cart-mutation capability', () => {
    const { Runtime } = loadRuntime();
    let caught = null;
    try {
        Runtime.contracts.boot(canonicalBoot({
            capabilities: {
                chat_ready: true,
                images: false,
                max_images: 0,
                max_image_bytes: 0,
                cart_mutations: { available: false, code: 'available', notice: 'Blocked' }
            }
        }));
    } catch (error) { caught = error; }
    ok(caught instanceof Runtime.ApiError);
    same('response_contract_invalid', caught.code);
});

test('boot state exposes cart-mutation unavailability without blocking chat or cart reads', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    const capabilities = {
        chat_ready: true,
        images: false,
        max_images: 0,
        max_image_bytes: 0,
        cart_mutations: {
            available: false,
            code: 'version_not_promotion_tested',
            notice: 'Chat cart changes are unavailable.'
        }
    };
    store.dispatch({
        type: 'BOOT_SUCCESS',
        sessionToken: 'session',
        conversation: {},
        messages: [],
        cartAvailable: true,
        cart: canonicalCart(),
        cartNotice: '',
        capabilities,
        widget: {}
    });
    same('ready', store.getState().phase);
    same('Chat cart changes are unavailable.', store.getState().cartMutationNotice);
    same(0, store.getState().cart.item_count);
});

test('each successful turn replaces stale cart-mutation capability', () => {
    const { Runtime } = loadRuntime();
    const store = new Runtime.Store({});
    store.dispatch({
        type: 'BOOT_SUCCESS', sessionToken: 's', conversation: {}, messages: [],
        cartAvailable: true, cart: canonicalCart(), cartNotice: '',
        capabilities: {
            chat_ready: true, images: false, max_images: 0, max_image_bytes: 0,
            cart_mutations: { available: true, code: 'available', notice: '' }
        },
        widget: {}
    });
    store.dispatch(turnSuccessAction(canonicalMessage('Answer'), {
        cartMutations: {
            available: false,
            code: 'session_authority_unavailable',
            notice: 'Reload required.'
        }
    }));
    same(false, store.getState().capabilities.cart_mutations.available);
    same('Reload required.', store.getState().cartMutationNotice);
    same('ready', store.getState().phase);
});

test('public URL policy rejects browser-repaired values and preserves canonical absolute URLs', () => {
    const { Runtime } = loadRuntime();
    same('', Runtime.util.safeUrl('/relative/product'));
    same('', Runtime.util.safeUrl(' https://example.test/product'));
    same('', Runtime.util.safeUrl('https://example.test\\@attacker.test/product'));
    same('', Runtime.util.safeUrl('https://example%40evil.test/product'));
    same('', Runtime.util.safeUrl('https://example.test/a\tb'));
    same('', Runtime.util.safeUrl('https://example.test:0/product'));
    same('https://example.test/a%20b?x=1', Runtime.util.safeUrl('https://example.test/a%20b?x=1'));
    const withinBytes = `https://example.test/${'ع'.repeat(2000)}`;
    const beyondBytes = `https://example.test/${'ع'.repeat(2040)}`;
    same(withinBytes, Runtime.util.safeUrl(withinBytes));
    same('', Runtime.util.safeUrl(beyondBytes));
});
