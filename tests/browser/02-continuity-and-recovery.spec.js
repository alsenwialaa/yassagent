'use strict';

Object.assign(globalThis, require('./support/widget-fixture'));

test('English and mixed-language WooCommerce product names remain visible as catalog data', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => ({
            body: turnPayloadForEntry(entry, assistantMessage('هذه نتيجة مناسبة.', {
                products: [{
                    id: 8,
                    name: 'Organic Coffee قهوة 250g',
                    formatted_price: '$12',
                    short_description: 'Premium roast',
                    in_stock: true,
                    requires_variation: false,
                    image: '',
                    permalink: `${origin}/product/organic-coffee`
                }]
            }))
        })
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('أرني القهوة');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Organic Coffee قهوة 250g', { exact: true })).toBeVisible();
});

test('fresh conversation rotates browser admission and continuity credentials', async ({ page }) => {
    let bootCount = 0;
    const calls = await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => {
            bootCount += 1;
            return { body: bootPayload({
                conversation: {
                    id: bootCount === 1
                        ? '00000000-0000-4000-8000-000000000001'
                        : '00000000-0000-4000-8000-000000000002',
                    token: 'conversation-token-1234567890',
                    messages: []
                }
            }) };
        }
    });
    await openReady(page);
    await page.evaluate(() => window.__ysaiAssistantApp.boot(true));
    await expect.poll(() => bootCount).toBe(2);
    const bodies = calls.filter(call => call.path.endsWith('/boot')).map(call => JSON.parse(call.body));
    expect(bodies).toHaveLength(2);
    expect(bodies[0].client_instance_id).toMatch(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
    expect(bodies[1].client_instance_id).not.toBe(bodies[0].client_instance_id);
    expect(bodies[0].browser_continuity_secret).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(bodies[1].browser_continuity_secret).not.toBe(bodies[0].browser_continuity_secret);
    expect(Object.keys(bodies[0]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id'
    ]);
    expect(Object.keys(bodies[1]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id',
        'previous_browser_continuity_secret'
    ]);
    expect(bodies[1].previous_browser_continuity_secret).toBe(bodies[0].browser_continuity_secret);
});

test('a hanging turn is aborted at the hard deadline and becomes an exact manual retry', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    });
    await openReady(page);
    await page.evaluate(() => {
        const realNow = Date.now.bind(Date);
        const realSetTimeout = window.setTimeout.bind(window);
        let offset = 0;
        Date.now = function () { return realNow() + offset; };
        window.setTimeout = function (callback, delay) {
            const turnTimeout = window.YSAIWidgetRuntime.ClientRecoveryPolicy.TURN_TIMEOUT_MS;
            if (Number(delay) > turnTimeout - 1000 && Number(delay) <= turnTimeout) {
                return realSetTimeout(function () {
                    offset += Number(delay) + 1;
                    callback();
                }, 25);
            }
            return realSetTimeout(callback, delay);
        };
        window.__ysaiTimeoutAborted = false;
        window.fetch = function (url, options) {
            return new Promise(function (resolve, reject) {
                options.signal.addEventListener('abort', function () {
                    window.__ysaiTimeoutAborted = true;
                    reject(new DOMException('Aborted', 'AbortError'));
                });
            });
        };
    });
    await page.locator('.ysai-input').fill('deadline request');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('انتهت مهلة الطلب')).toBeVisible();
    await expect(page.locator('.ysai-message-retry')).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => window.__ysaiTimeoutAborted)).toBe(true);
    await expect.poll(() => page.evaluate(() => window.__ysaiAssistantApp.store.getState().phase)).toBe('blocked');
});

test('an ambiguous failed turn blocks subsequent sends and retains one exact envelope', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    });
    await openReady(page);
    await page.evaluate(() => {
        window.__ysaiAssistantApp.api.sendTurn = function () {
            return Promise.reject(new window.YSAIWidgetRuntime.ApiError('Offline', 'network_unavailable', 0, 0));
        };
    });
    await page.locator('.ysai-input').fill('failed request');
    await page.locator('.ysai-send').click();
    await expect.poll(() => page.evaluate(() => window.__ysaiAssistantApp.store.getState().phase)).toBe('blocked');
    await expect(page.locator('.ysai-input')).toBeDisabled();
    await expect(page.locator('.ysai-message-retry')).toHaveCount(1);
    expect(await page.evaluate(() => ({
        count: window.__ysaiAssistantApp.retryStore.ids().length,
        bytes: window.__ysaiAssistantApp.retryStore.totalBytes
    }))).toEqual({
        count: 1,
        bytes: expect.any(Number)
    });
    expect(await page.evaluate(() => window.__ysaiAssistantApp.retryStore.totalBytes)).toBeLessThanOrEqual(3145728);
});

test('retry storage expires retained bodies automatically by age', async ({ page }) => {
    await install(page, {}, false);
    const result = await page.evaluate(async () => {
        const Runtime = window.YSAIWidgetRuntime;
        const realSetTimeout = window.setTimeout.bind(window);
        const evicted = [];
        let now = Date.now();
        const store = new Runtime.RetryEnvelopeStore(ids => evicted.push(...ids), {
            nowMs: () => now,
            setTimeout(callback, delay) {
                if (delay === Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS) {
                    return realSetTimeout(function () {
                        now += Runtime.ClientRecoveryPolicy.RETRY_MAX_AGE_MS;
                        callback();
                    }, 20);
                }
                return realSetTimeout(callback, delay);
            },
            clearTimeout: window.clearTimeout.bind(window)
        });
        store.put('aged-browser-retry', { body: '{"request":"exact"}' });
        await new Promise(resolve => realSetTimeout(resolve, 50));
        return { stats: { count: store.ids().length, bytes: store.totalBytes }, evicted };
    });
    expect(result.stats).toEqual({ count: 0, bytes: 0 });
    expect(result.evicted).toContain('aged-browser-retry');
});

test('every successful turn rebases the tab onto canonical server history', async ({ page }) => {
    const initialTurn = '11111111-1111-4111-8111-111111111111';
    const otherTurn = '22222222-2222-4222-8222-222222222222';
    const initialHistory = [
        userMessage('Earlier request', initialTurn, '33333333-3333-4333-8333-333333333333'),
        assistantMessage('Earlier answer', { id: '44444444-4444-4444-8444-444444444444', turn_id: initialTurn })
    ];
    const otherTabHistory = [
        userMessage('Hidden request from another tab', otherTurn, '55555555-5555-4555-8555-555555555555'),
        assistantMessage('Hidden answer from another tab', { id: '66666666-6666-4666-8666-666666666666', turn_id: otherTurn })
    ];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: initialHistory
            }
        }) }),
        '/wp-json/yassin-ai/v1/chat': async entry => ({ body: turnPayloadForEntry(
            entry,
            assistantMessage('Current answer'),
            { history: initialHistory.concat(otherTabHistory) }
        ) })
    });
    await openReady(page);
    await expect(page.getByText('Hidden request from another tab')).toHaveCount(0);
    await page.locator('.ysai-input').fill('Current request');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Hidden request from another tab')).toBeVisible();
    await expect(page.getByText('Hidden answer from another tab')).toBeVisible();
    await expect(page.getByText('Current request')).toHaveCount(1);
    await expect(page.getByText('Current answer')).toHaveCount(1);
});

test('conversation invalidation adopts newer shared continuity and safely replays the unadmitted request', async ({ page }) => {
    let boots = 0;
    let chats = 0;
    const bootBodies = [];
    const chatBodies = [];
    const newId = '77777777-7777-4777-8777-777777777777';
    const newToken = 'conversation-token-shared-1234567890';
    const sharedTurn = '88888888-8888-4888-8888-888888888888';
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            boots += 1;
            bootBodies.push(JSON.parse(entry.body || '{}'));
            if (boots === 1) {
                return { body: bootPayload({
                    conversation: {
                        id: '00000000-0000-4000-8000-000000000001',
                        token: 'conversation-token-1234567890',
                        messages: pairedMessages([assistantMessage('Old visible history')])
                    }
                }) };
            }
            return { body: bootPayload({
                conversation: {
                    id: newId,
                    token: newToken,
                    messages: [
                        userMessage('Shared tab request', sharedTurn, '99999999-9999-4999-8999-999999999999'),
                        assistantMessage('Shared tab answer', { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaab', turn_id: sharedTurn })
                    ]
                }
            }) };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chats += 1;
            chatBodies.push(JSON.parse(entry.body || '{}'));
            if (chats === 1) {
                return {
                    status: 401,
                    body: { ok: false, code: 'conversation_invalid', message: 'Conversation expired' }
                };
            }
            return { body: turnPayloadForEntry(entry, assistantMessage('Replayed safely'), {
                conversation: { id: newId, token: newToken },
                history: [
                    userMessage('Shared tab request', sharedTurn, '99999999-9999-4999-8999-999999999999'),
                    assistantMessage('Shared tab answer', { id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaab', turn_id: sharedTurn })
                ]
            }) };
        }
    });
    await openReady(page);
    await page.evaluate(({ id, token }) => {
        const current = JSON.parse(localStorage.getItem('ysai_test'));
        localStorage.setItem('ysai_test', JSON.stringify({
            conversation_id: id,
            conversation_token: token,
            revision: current.revision
        }));
    }, { id: newId, token: newToken });
    await page.locator('.ysai-input').fill('Request from stale tab');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Shared tab answer')).toBeVisible();
    await expect(page.getByText('Replayed safely')).toBeVisible();
    await expect(page.getByText('Old visible history')).toHaveCount(0);
    await expect(page.getByText('Request from stale tab')).toHaveCount(1);
    expect(boots).toBe(2);
    expect(chats).toBe(2);
    expect(bootBodies[1]).toEqual({
        browser_continuity_secret: bootBodies[0].browser_continuity_secret,
        client_instance_id: bootBodies[0].client_instance_id,
        conversation_id: newId,
        conversation_token: newToken,
        pending_turn_id: ''
    });
    expect(chatBodies[1].client_turn_id).toBe(chatBodies[0].client_turn_id);
    expect(chatBodies[1].message).toBe(chatBodies[0].message);
    expect(chatBodies[1].conversation_id).toBe(newId);
    expect(chatBodies[1].conversation_token).toBe(newToken);
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('ysai_test')).conversation_id)).toBe(newId);
});

test('automatic retry reuses the exact serialized turn body', async ({ page }) => {
    const bodies = [];
    let attempts = 0;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(entry.body);
            attempts += 1;
            if (attempts === 1) {
                return { status: 503, body: { ok: false, code: 'busy', message: 'Busy' } };
            }
            return { body: turnPayloadForEntry(entry, assistantMessage('Recovered')) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('retry me');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Recovered')).toBeVisible();
    expect(bodies).toHaveLength(2);
    expect(bodies[1]).toBe(bodies[0]);
});

test('expired session renews the short-lived token, keeps conversation authority, and automatically replays the exact turn', async ({ page }) => {
    let boots = 0;
    let chats = 0;
    const bodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            boots += 1;
            const request = JSON.parse(entry.body || '{}');
            const otherTurn = '22222222-2222-4222-8222-222222222222';
            const messages = boots === 1 ? [] : [
                userMessage('Other tab request', otherTurn, '33333333-3333-4333-8333-333333333333'),
                assistantMessage('Canonical history from another tab', {
                    id: '44444444-4444-4444-8444-444444444444',
                    turn_id: otherTurn
                })
            ];
            return { body: bootPayload({
                session: { token: `eyJ2IjoxfQ.${String(boots).repeat(64)}` },
                conversation: { id: '00000000-0000-4000-8000-000000000001', token: 'conversation-token-1234567890', messages },
                pending_turn: request.pending_turn_id
                    ? { id: request.pending_turn_id, status: 'absent' }
                    : null
            }) };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chats += 1;
            bodies.push(entry.body);
            if (chats === 1) {
                return { status: 401, body: { ok: false, code: 'session_invalid', message: 'Expired' } };
            }
            const message = assistantMessage('Recovered after refresh');
            const history = [
                userMessage('Other tab request', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'),
                assistantMessage('Canonical history from another tab', {
                    id: '44444444-4444-4444-8444-444444444444',
                    turn_id: '22222222-2222-4222-8222-222222222222'
                })
            ];
            return { body: turnPayloadForEntry(entry, message, { history }) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('preserve request');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Recovered after refresh')).toBeVisible();
    await expect(page.getByText('Canonical history from another tab')).toBeVisible();
    expect(boots).toBe(2);
    expect(chats).toBe(2);
    expect(bodies[1]).toBe(bodies[0]);
    await expect(page.locator('.ysai-message.is-user')).toHaveCount(2);
    await expect(page.getByText('preserve request')).toHaveCount(1);
    await expect(page.locator('.ysai-input')).toBeEnabled();
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('ysai_test')).conversation_id)).toBe('00000000-0000-4000-8000-000000000001');
});

test('session renewal adopts an already-committed pending turn and does not replay it into the UI twice', async ({ page }) => {
    let boots = 0;
    let chats = 0;
    let pendingTurnId = '';
    let pendingText = '';
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            boots += 1;
            const request = JSON.parse(entry.body || '{}');
            const messages = boots === 1 || !pendingTurnId ? [] : [
                userMessage(pendingText, pendingTurnId, '55555555-5555-4555-8555-555555555555'),
                assistantMessage('Canonical result after lost response', {
                    id: '66666666-6666-4666-8666-666666666666',
                    turn_id: pendingTurnId
                })
            ];
            return { body: bootPayload({
                session: { token: `eyJ2IjoxfQ.${String(boots).repeat(64)}` },
                conversation: {
                    id: '00000000-0000-4000-8000-000000000001',
                    token: 'conversation-token-1234567890',
                    messages
                },
                pending_turn: request.pending_turn_id
                    ? { id: request.pending_turn_id, status: 'terminal' }
                    : null
            }) };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chats += 1;
            const body = JSON.parse(entry.body);
            pendingTurnId = body.client_turn_id;
            pendingText = body.message;
            if (chats === 1) {
                return { status: 200, body: '<html>response lost after commit</html>' };
            }
            return { status: 401, body: { ok: false, code: 'session_invalid', message: 'Expired' } };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('committed once');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Canonical result after lost response')).toBeVisible();
    await expect(page.getByText('committed once')).toHaveCount(1);
    expect(boots).toBe(2);
    expect(chats).toBe(2);
    await expect(page.locator('.ysai-input')).toBeEnabled();
});

test('repeated session credential failure never replaces a still-unrefuted conversation', async ({ page }) => {
    let boots = 0;
    let chats = 0;
    const conversation = {
        id: '00000000-0000-4000-8000-000000000001',
        token: 'conversation-token-1234567890',
        messages: pairedMessages([assistantMessage('Existing conversation history')])
    };
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            boots += 1;
            const request = JSON.parse(entry.body || '{}');
            return { body: bootPayload({
                session: { token: `eyJ2IjoxfQ.${String(boots).repeat(64)}` },
                conversation,
                pending_turn: request.pending_turn_id
                    ? { id: request.pending_turn_id, status: 'absent' }
                    : null
            }) };
        },
        '/wp-json/yassin-ai/v1/chat': async () => {
            chats += 1;
            return { status: 401, body: { ok: false, code: 'session_invalid', message: 'Credential refresh failed' } };
        }
    });
    await openReady(page);
    const before = await page.evaluate(() => JSON.parse(localStorage.getItem('ysai_test')));
    await page.locator('.ysai-input').fill('keep this pending turn');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Credential refresh failed')).toBeVisible();
    expect(boots).toBe(2);
    expect(chats).toBe(2);
    await expect(page.getByText('Existing conversation history')).toBeVisible();
    await expect(page.getByText('keep this pending turn')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'أعد المحاولة' })).toBeVisible();
    const after = await page.evaluate(() => JSON.parse(localStorage.getItem('ysai_test')));
    expect(after).toEqual(before);
});

test('expired conversation clears stale transcript and safely replays the unadmitted request', async ({ page }) => {
    let boots = 0;
    let chats = 0;
    const bodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => {
            boots += 1;
            return { body: bootPayload({
                session: { token: `eyJ2IjoxfQ.${String(boots).repeat(64)}` },
                conversation: {
                    id: `00000000-0000-4000-8000-${String(boots).padStart(12, '0')}`,
                    token: `conversation-token-${String(boots).padStart(12, '0')}`,
                    messages: []
                }
            }) };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chats += 1;
            bodies.push(JSON.parse(entry.body || '{}'));
            if (chats === 1) {
                return { body: turnPayloadForEntry(entry, assistantMessage('Old conversation answer'), {
                    conversation: { id: '00000000-0000-4000-8000-000000000001', token: 'conversation-token-000000000001' }
                }) };
            }
            if (chats === 2) {
                return {
                    status: 401,
                    body: { ok: false, code: 'conversation_invalid', message: 'Conversation expired' }
                };
            }
            return { body: turnPayloadForEntry(entry, assistantMessage('Recovered in the new conversation'), {
                conversation: { id: '00000000-0000-4000-8000-000000000002', token: 'conversation-token-000000000002' }
            }) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('first request');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Old conversation answer')).toBeVisible();
    await page.locator('.ysai-input').fill('unaccepted request');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Recovered in the new conversation')).toBeVisible();
    await expect(page.getByText('Old conversation answer')).toHaveCount(0);
    await expect(page.getByText('first request')).toHaveCount(0);
    await expect(page.getByText('unaccepted request')).toHaveCount(1);
    await expect(page.locator('.ysai-input')).toBeEnabled();
    expect(boots).toBe(2);
    expect(chats).toBe(3);
    expect(bodies[2].client_turn_id).toBe(bodies[1].client_turn_id);
    expect(bodies[2].message).toBe(bodies[1].message);
    expect(bodies[2].conversation_id).toBe('00000000-0000-4000-8000-000000000002');
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('ysai_test')).conversation_id)).toBe('00000000-0000-4000-8000-000000000002');
});
