'use strict';

Object.assign(globalThis, require('./support/widget-fixture'));

test('boots, sends, renders text safely, and stores continuity credentials only', async ({ page }) => {
    let chatBody = null;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chatBody = JSON.parse(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Safe answer', {
                products: [{ id: 7, name: '<img onerror="window.__xss=1">', formatted_price: '$10', short_description: '', in_stock: true, requires_variation: false, image: '', permalink: `${origin}/product` }]
            }), {
                cart: { item_count: 1, formatted_total: '$10', cart_url: `${origin}/cart`, checkout_url: `${origin}/checkout` }
            }) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('<img src=x onerror="window.__xss=1">');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Safe answer')).toBeVisible();
    await expect(page.getByText('<img onerror="window.__xss=1">')).toBeVisible();
    await expect(page.getByText(/السلة: 1 منتجات/)).toBeVisible();
    expect(await page.evaluate(() => window.__xss)).toBe(0);
    expect(chatBody.message).toBe('<img src=x onerror="window.__xss=1">');
    expect(chatBody.client_turn_id).toMatch(/^[a-f0-9-]{36}$/);
    expect(await page.evaluate(() => Object.keys(JSON.parse(localStorage.getItem('ysai_test'))).sort())).toEqual(['conversation_id', 'conversation_token', 'revision']);
});

test('chat remains available when sessionStorage is unavailable', async ({ page }) => {
    const bodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Memory fallback answer')) };
        }
    }, true, 0, { __testSessionStorageMode: 'unavailable' });
    await openReady(page);
    await page.locator('.ysai-input').fill('send without session storage');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Memory fallback answer')).toBeVisible();
    expect(bodies).toHaveLength(1);
    expect(await page.evaluate(() => ({
        retry: window.__ysaiAssistantApp.retryStore.persistenceMode(),
        pending: window.__ysaiAssistantApp.continuity.pendingPersistenceMode(),
        retained: window.__ysaiAssistantApp.retryStore.ids().length
    }))).toEqual({ retry: 'memory', pending: 'memory', retained: 0 });
});

test('chat remains available when sessionStorage writes fail', async ({ page }) => {
    const bodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Write fallback answer')) };
        }
    }, true, 0, { __testSessionStorageMode: 'write-failure' });
    await openReady(page);
    await page.locator('.ysai-input').fill('send after storage write failure');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Write fallback answer')).toBeVisible();
    expect(bodies).toHaveLength(1);
    expect(await page.evaluate(() => ({
        retry: window.__ysaiAssistantApp.retryStore.persistenceMode(),
        pending: window.__ysaiAssistantApp.continuity.pendingPersistenceMode(),
        retained: window.__ysaiAssistantApp.retryStore.ids().length
    }))).toEqual({ retry: 'memory', pending: 'memory', retained: 0 });
});

test('chat boots and sends when localStorage is unavailable', async ({ page }) => {
    const bootBodies = [];
    const chatBodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            bootBodies.push(JSON.parse(entry.body));
            return { body: bootPayload() };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chatBodies.push(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Local memory answer')) };
        }
    }, true, 0, { __testLocalStorageMode: 'unavailable' });
    await openReady(page);
    await expect(page.locator('.ysai-status-line')).toHaveText(/متابعة المحادثة في هذه الصفحة/);
    await page.locator('.ysai-input').fill('send without local storage');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Local memory answer')).toBeVisible();
    expect(bootBodies).toHaveLength(1);
    expect(chatBodies).toHaveLength(1);
    expect(bootBodies[0].client_instance_id).toMatch(/^[a-f0-9-]{36}$/);
    expect(bootBodies[0].browser_continuity_secret).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(await page.evaluate(() => window.__ysaiAssistantApp.browserStorageStatus())).toEqual({
        local: 'memory',
        session: 'persistent',
        current_tab_chat: true,
        current_tab_retry: true,
        refresh_continuity: false,
        unresolved_refresh_recovery: false,
        cross_tab_continuity: false,
        server_idempotency_authoritative: true
    });
});

test('exact current-document retry survives when all browser storage is unavailable', async ({ page }) => {
    const bodies = [];
    let attempts = 0;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(entry.body);
            attempts += 1;
            if (attempts <= 3) {
                return { status: 503, body: { ok: false, code: 'busy', message: 'Busy' } };
            }
            return { body: turnPayloadForEntry(entry, assistantMessage('Exact memory retry answer')) };
        }
    }, true, 0, {
        __testLocalStorageMode: 'unavailable',
        __testSessionStorageMode: 'unavailable'
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('retry this exact request');
    await page.locator('.ysai-send').click();
    await expect(page.locator('.ysai-message-retry')).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => window.__ysaiAssistantApp.store.getState().phase)).toBe('blocked');
    expect(await page.evaluate(() => window.__ysaiAssistantApp.retryStore.ids().length)).toBe(1);
    await page.locator('.ysai-message-retry').click();
    await expect(page.getByText('Exact memory retry answer')).toBeVisible();
    expect(bodies).toHaveLength(4);
    bodies.slice(1).forEach(body => expect(body).toBe(bodies[0]));
    expect(JSON.parse(bodies[3]).client_turn_id).toBe(JSON.parse(bodies[0]).client_turn_id);
    expect(await page.evaluate(() => ({
        modes: window.__ysaiAssistantApp.browserStorageStatus(),
        retryCount: window.__ysaiAssistantApp.retryStore.ids().length,
        pending: window.__ysaiAssistantApp.continuity.readPending()
    }))).toEqual({
        modes: {
            local: 'memory', session: 'memory', current_tab_chat: true,
            current_tab_retry: true, refresh_continuity: false,
            unresolved_refresh_recovery: false, cross_tab_continuity: false,
            server_idempotency_authoritative: true
        },
        retryCount: 0,
        pending: null
    });
});

test('rejected browser-storage reads degrade coherently and still admit chat', async ({ page }) => {
    const bootBodies = [];
    const chatBodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            bootBodies.push(JSON.parse(entry.body));
            return { body: bootPayload() };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chatBodies.push(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Read rejection answer')) };
        }
    }, true, 0, {
        __testLocalStorageMode: 'read-failure',
        __testSessionStorageMode: 'read-failure'
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('send after storage reads fail');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Read rejection answer')).toBeVisible();
    expect(bootBodies).toHaveLength(1);
    expect(chatBodies).toHaveLength(1);
    expect(Object.keys(bootBodies[0]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id'
    ]);
    expect(await page.evaluate(() => window.__ysaiAssistantApp.browserStorageStatus())).toEqual({
        local: 'memory', session: 'memory', current_tab_chat: true,
        current_tab_retry: true, refresh_continuity: false,
        unresolved_refresh_recovery: false, cross_tab_continuity: false,
        server_idempotency_authoritative: true
    });
});

test('corrupt records with rejected removal are quarantined without blocking boot', async ({ page }) => {
    const bootBodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            bootBodies.push(JSON.parse(entry.body));
            return { body: bootPayload() };
        }
    }, true, 0, {
        __testLocalStorageMode: 'remove-failure',
        __testSessionStorageMode: 'remove-failure',
        __testLocalStorageValues: {
            ysai_test_client: 'not-a-uuid',
            ysai_test_continuity_secret: '{broken',
            ysai_test: '{broken'
        },
        __testSessionStorageValues: {
            'ysai_test:pending-turn': '{broken',
            'ysai_test:retry-envelopes': '{broken'
        }
    });
    await openReady(page);
    expect(bootBodies).toHaveLength(1);
    expect(bootBodies[0].pending_turn_id).toBe('');
    expect(bootBodies[0].client_instance_id).toMatch(/^[a-f0-9-]{36}$/);
    expect(bootBodies[0].browser_continuity_secret).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(await page.evaluate(() => ({
        status: window.__ysaiAssistantApp.browserStorageStatus(),
        staleClient: window.__ysaiTestStorage.localValues.ysai_test_client,
        stalePending: window.__ysaiTestStorage.sessionValues['ysai_test:pending-turn']
    }))).toEqual({
        status: {
            local: 'memory', session: 'memory', current_tab_chat: true,
            current_tab_retry: true, refresh_continuity: false,
            unresolved_refresh_recovery: false, cross_tab_continuity: false,
            server_idempotency_authoritative: true
        },
        staleClient: 'not-a-uuid',
        stalePending: '{broken'
    });
});

test('rejected local and session writes degrade coherently without suppressing chat', async ({ page }) => {
    const bodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Write rejection answer')) };
        }
    }, true, 0, {
        __testLocalStorageMode: 'write-failure',
        __testSessionStorageMode: 'write-failure'
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('send after both writes fail');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Write rejection answer')).toBeVisible();
    expect(bodies).toHaveLength(1);
    expect(await page.evaluate(() => ({
        app: window.__ysaiAssistantApp.browserStorageStatus(),
        identity: window.__ysaiAssistantApp.clientIdentity.persistenceMode(),
        secret: window.__ysaiAssistantApp.browserContinuity.persistenceMode(),
        conversation: window.__ysaiAssistantApp.continuity.persistenceMode(),
        pending: window.__ysaiAssistantApp.continuity.pendingPersistenceMode(),
        retry: window.__ysaiAssistantApp.retryStore.persistenceMode()
    }))).toEqual({
        app: {
            local: 'memory', session: 'memory', current_tab_chat: true,
            current_tab_retry: true, refresh_continuity: false,
            unresolved_refresh_recovery: false, cross_tab_continuity: false,
            server_idempotency_authoritative: true
        },
        identity: 'memory', secret: 'memory', conversation: 'memory',
        pending: 'memory', retry: 'memory'
    });
});

test('corrupt persisted browser authority is discarded before boot admission', async ({ page }) => {
    const bootBodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            bootBodies.push(JSON.parse(entry.body));
            return { body: bootPayload() };
        }
    }, true, 0, {
        __testLocalStorageValues: {
            ysai_test_client: 'not-a-uuid',
            ysai_test_continuity_secret: '{broken',
            ysai_test: '{broken'
        },
        __testSessionStorageValues: {
            'ysai_test:pending-turn': '{broken',
            'ysai_test:retry-envelopes': '{broken'
        }
    });
    await openReady(page);
    expect(bootBodies).toHaveLength(1);
    expect(Object.keys(bootBodies[0]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id'
    ]);
    expect(bootBodies[0].pending_turn_id).toBe('');
    expect(bootBodies[0].client_instance_id).toMatch(/^[a-f0-9-]{36}$/);
    expect(bootBodies[0].browser_continuity_secret).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(await page.evaluate(() => ({
        client: window.__ysaiTestStorage.localValues.ysai_test_client,
        secret: JSON.parse(window.__ysaiTestStorage.localValues.ysai_test_continuity_secret).secret,
        pending: window.__ysaiTestStorage.sessionValues['ysai_test:pending-turn'],
        retry: window.__ysaiTestStorage.sessionValues['ysai_test:retry-envelopes']
    }))).toEqual({
        client: bootBodies[0].client_instance_id,
        secret: bootBodies[0].browser_continuity_secret,
        pending: undefined,
        retry: undefined
    });
});

test('memory-only browser authority is fresh in a new document', async ({ page, context }) => {
    const firstBodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            firstBodies.push(JSON.parse(entry.body));
            return { body: bootPayload() };
        }
    }, true, 0, {
        __testLocalStorageMode: 'unavailable',
        __testSessionStorageMode: 'unavailable'
    });
    await openReady(page);

    const secondPage = await context.newPage();
    const secondBodies = [];
    await install(secondPage, {
        '/wp-json/yassin-ai/v1/boot': async entry => {
            secondBodies.push(JSON.parse(entry.body));
            return { body: bootPayload({
                conversation: {
                    id: '00000000-0000-4000-8000-000000000099',
                    token: 'replacement-conversation-token-1234',
                    messages: []
                }
            }) };
        }
    }, true, 0, {
        __testLocalStorageMode: 'unavailable',
        __testSessionStorageMode: 'unavailable'
    });
    await openReady(secondPage);

    expect(firstBodies).toHaveLength(1);
    expect(secondBodies).toHaveLength(1);
    expect(Object.keys(secondBodies[0]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id'
    ]);
    expect(secondBodies[0].client_instance_id).not.toBe(firstBodies[0].client_instance_id);
    expect(secondBodies[0].browser_continuity_secret).not.toBe(firstBodies[0].browser_continuity_secret);
    expect(await secondPage.evaluate(() => window.__ysaiAssistantApp.store.getState().conversation.id))
        .toBe('00000000-0000-4000-8000-000000000099');
    await secondPage.close();
});

test('two widget views share one memory-only continuity authority in the current tab', async ({ page }) => {
    let bootCount = 0;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => {
            bootCount += 1;
            return { body: bootPayload() };
        }
    }, false, 0, {
        __testLocalStorageMode: 'unavailable',
        __testSessionStorageMode: 'unavailable'
    });
    await page.evaluate(() => {
        ['storage-view-one', 'storage-view-two'].forEach(id => {
            const root = document.createElement('div');
            root.id = id;
            root.setAttribute('data-ysai-widget', '1');
            document.body.appendChild(root);
        });
    });
    await expect(page.locator('.ysai-launcher')).toHaveCount(2);
    await page.locator('#storage-view-one .ysai-launcher').evaluate(button => button.click());
    await expect(page.locator('#storage-view-one .ysai-input')).toBeEnabled();
    await expect(page.locator('#storage-view-two .ysai-input')).toBeEnabled();
    expect(bootCount).toBe(1);
    expect(await page.evaluate(() => ({
        views: window.__ysaiAssistantApp.views.length,
        identity: window.__ysaiAssistantApp.clientIdentity.id(),
        secret: window.__ysaiAssistantApp.browserContinuity.secret(),
        status: window.__ysaiAssistantApp.browserStorageStatus()
    }))).toEqual({
        views: 2,
        identity: expect.stringMatching(/^[a-f0-9-]{36}$/),
        secret: expect.stringMatching(/^[A-Za-z0-9_-]{43}$/),
        status: {
            local: 'memory', session: 'memory', current_tab_chat: true,
            current_tab_retry: true, refresh_continuity: false,
            unresolved_refresh_recovery: false, cross_tab_continuity: false,
            server_idempotency_authoritative: true
        }
    });
});
