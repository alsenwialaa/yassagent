'use strict';

Object.assign(globalThis, require('./support/widget-fixture'));

test('image payload excludes browser-only filename and size metadata', async ({ page }) => {
    let body = null;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({ capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } } }) }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            body = JSON.parse(entry.body);
            return { body: turnPayloadForEntry(entry, assistantMessage('Image received')) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-file-input').setInputFiles({ name: 'secret-name.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZJXcAAAAASUVORK5CYII=', 'base64') });
    await expect(page.locator('.ysai-attachment img')).toHaveCount(1);
    await page.locator('.ysai-input').fill('check this');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Image received')).toBeVisible();
    expect(body.attachments).toHaveLength(1);
    expect(Object.keys(body.attachments[0]).sort()).toEqual(['data', 'mime_type']);
    expect(body.attachments[0].data).toMatch(/^[A-Za-z0-9+/]+={0,2}$/);
    expect(body.attachments[0].data).not.toContain('data:');
});

test('oversized source dimensions are rejected before browser decode', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await openReady(page);
    await page.evaluate(() => {
        window.__ysaiDecodeCalls = { bitmap: 0, image: 0 };
        const nativeBitmap = window.createImageBitmap;
        window.createImageBitmap = function () {
            window.__ysaiDecodeCalls.bitmap += 1;
            return nativeBitmap.apply(window, arguments);
        };
        const NativeImage = window.Image;
        window.Image = function () {
            window.__ysaiDecodeCalls.image += 1;
            return new NativeImage();
        };
    });
    expect(fs.statSync(compressedPixelBomb).size).toBeLessThan(8388608);
    await page.locator('.ysai-file-input').setInputFiles(compressedPixelBomb);
    await expect(page.locator('.ysai-status-line')).toHaveText('أبعاد الصورة كبيرة جداً');
    await expect(page.locator('.ysai-attachment')).toHaveCount(0);
    expect(await page.evaluate(() => window.__ysaiDecodeCalls)).toEqual({ bitmap: 0, image: 0 });
});

test('accepted common phone images request bounded decode-time resizing through createImageBitmap', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await openReady(page);
    await page.evaluate(() => {
        const nativeBitmap = window.createImageBitmap.bind(window);
        window.__ysaiBitmapOptions = null;
        window.createImageBitmap = function (source, options) {
            window.__ysaiBitmapOptions = Object.assign({}, options || {});
            return nativeBitmap(source, options);
        };
    });
    await page.locator('.ysai-file-input').setInputFiles(commonPhoneSource);
    await expect(page.locator('.ysai-attachment img')).toHaveCount(1);
    expect(await page.evaluate(() => window.__ysaiBitmapOptions)).toEqual({
        resizeWidth: 1600,
        resizeHeight: 1200,
        resizeQuality: 'high'
    });
});

test('image-element fallback revokes its temporary object URL', async ({ page }) => {
    const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZJXcAAAAASUVORK5CYII=', 'base64');
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await openReady(page);
    await page.evaluate(() => {
        window.createImageBitmap = undefined;
        window.__ysaiObjectUrls = { created: 0, revoked: 0 };
        const create = URL.createObjectURL.bind(URL);
        const revoke = URL.revokeObjectURL.bind(URL);
        URL.createObjectURL = function (value) {
            window.__ysaiObjectUrls.created += 1;
            return create(value);
        };
        URL.revokeObjectURL = function (value) {
            window.__ysaiObjectUrls.revoked += 1;
            return revoke(value);
        };
    });
    await page.locator('.ysai-file-input').setInputFiles({
        name: 'fallback.png', mimeType: 'image/png', buffer: pixel
    });
    await expect(page.locator('.ysai-attachment img')).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => window.__ysaiObjectUrls)).toEqual({ created: 1, revoked: 1 });
});

test('dynamic roots mount once and detached roots release their views', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    }, false);
    await page.evaluate(() => {
        const root = document.createElement('div');
        root.id = 'dynamic-widget';
        root.dataset.ysaiWidget = '1';
        document.body.appendChild(root);
    });
    await expect(page.locator('#dynamic-widget .ysai-launcher')).toHaveCount(1);
    await expect(page.locator('#dynamic-widget')).toHaveClass(/ysai-widget-root/);
    await expect(page.locator('#dynamic-widget')).toHaveClass(/ysai-position-right/);
    await expect(page.locator('#dynamic-widget')).toHaveClass(/ysai-product-layout-carousel/);
    await expect(page.locator('#dynamic-widget')).toHaveClass(/ysai-product-cards-1/);
    expect(await page.evaluate(() => window.__ysaiAssistantApp.views.length)).toBe(1);
    await page.evaluate(() => document.getElementById('dynamic-widget').remove());
    await expect.poll(() => page.evaluate(() => window.__ysaiAssistantApp.views.length)).toBe(0);
    await page.evaluate(() => {
        const root = document.createElement('div');
        root.id = 'dynamic-widget-2';
        root.dataset.ysaiWidget = '1';
        document.body.appendChild(root);
    });
    await expect(page.locator('#dynamic-widget-2 .ysai-launcher')).toHaveCount(1);
    expect(await page.evaluate(() => window.__ysaiAssistantApp.views.length)).toBe(1);
});

test('chat_ready false blocks input instead of sending guaranteed failures', async ({ page }) => {
    const calls = await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({ capabilities: { chat_ready: false, images: false, max_images: 0, max_image_bytes: 0, cart_mutations: { available: true, code: 'available', notice: '' } } }) })
    });
    await page.locator('.ysai-launcher').click();
    await expect(page.locator('.ysai-input')).toBeDisabled();
    await expect(page.getByText('المساعد غير متاح')).toBeVisible();
    await expect(page.locator('.ysai-send')).toBeDisabled();
    expect(calls.filter(call => call.path.endsWith('/chat'))).toHaveLength(0);
});

test('blocked widget rechecks readiness on reopen without replacing conversation authority', async ({ page }) => {
    let boots = 0;
    const calls = await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => {
            boots += 1;
            return { body: bootPayload({
                capabilities: { chat_ready: boots > 1, images: false, max_images: 0, max_image_bytes: 0, cart_mutations: { available: true, code: 'available', notice: '' } }
            }) };
        }
    });
    await page.locator('.ysai-launcher').click();
    await expect(page.locator('.ysai-input')).toBeDisabled();
    await expect(page.getByText('المساعد غير متاح')).toBeVisible();
    await page.locator('.ysai-close').click();
    await page.locator('.ysai-launcher').click();
    await expect.poll(() => boots).toBe(2);
    await expect(page.locator('.ysai-input')).toBeEnabled();
    expect(calls.filter(call => call.path.endsWith('/boot'))).toHaveLength(2);
    expect(calls.filter(call => call.path.endsWith('/chat'))).toHaveLength(0);
});

test('canonical image metadata renders a durable placeholder after boot', async ({ page }) => {
    const turnId = '47474747-4747-4747-8747-474747474747';
    const imageUser = userMessage(
        'Image attachment (available to the model for this turn only)',
        turnId,
        '48484848-4848-4848-8848-484848484848'
    );
    imageUser.presentation = {
        image_scope: 'turn_only',
        images: [{ kind: 'image', mime_type: 'image/png', byte_length: 2048 }],
        reply_quote: ''
    };
    const reply = assistantMessage('I inspected the image.', {
        id: '49494949-4949-4949-8949-494949494949',
        turn_id: turnId
    });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: [imageUser, reply]
            }
        }) })
    });
    await openReady(page);
    const placeholder = page.locator('.ysai-message.is-user .ysai-message-image-placeholder');
    await expect(placeholder).toHaveCount(1);
    await expect(placeholder).toContainText('صورة مرفقة');
    await expect(placeholder).toContainText('PNG');
    await expect(placeholder).toContainText('2 KB');
    await expect(page.locator('.ysai-message.is-user .ysai-message-image')).toHaveCount(0);
});

test('manual retry after a malformed success reuses the exact original body', async ({ page }) => {
    const bodies = [];
    let valid = false;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(entry.body);
            if (!valid) {
                return { body: { ok: true, message: { text: '' }, cart_available: true } };
            }
            return { body: turnPayloadForEntry(entry, assistantMessage('Retried safely')) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('same turn');
    await page.locator('.ysai-send').click();
    await expect(page.locator('.ysai-message-retry')).toBeVisible();
    valid = true;
    await page.locator('.ysai-message-retry').click();
    await expect(page.getByText('Retried safely')).toBeVisible();
    expect(bodies).toHaveLength(2);
    expect(bodies[1]).toBe(bodies[0]);
});

test('cart refresh failure preserves the last visible snapshot and displays notice', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({ cart: { item_count: 2, formatted_total: '$20', cart_url: `${origin}/cart`, checkout_url: `${origin}/checkout` } }) }),
        '/wp-json/yassin-ai/v1/chat': async entry => ({ body: turnPayloadForEntry(entry, assistantMessage('Done'), { cart: null, cart_available: false, cart_notice: 'Refresh failed' }) })
    });
    await openReady(page);
    await expect(page.getByText(/السلة: 2 منتجات/)).toBeVisible();
    await page.locator('.ysai-input').fill('do it');
    await page.locator('.ysai-send').click();
    await expect(page.getByText('Refresh failed')).toBeVisible();
    await expect(page.getByText(/السلة: 2 منتجات/)).toBeVisible();
});

test('verified action result renders only with a matching server receipt', async ({ page }) => {
    const receipt = {
        id: '33333333-3333-4333-8333-333333333333',
        action: 'cart_apply',
        changed: true,
        message: 'Added securely',
        proof: {
            commands: [{ type: 'add', item: 'Product', quantity: 1 }],
            cart_count: 1,
            cart_total: '$10',
            changed_line_count: 1,
            currency: 'USD'
        },
        created_at: 1700000000
    };
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => ({ body: turnPayloadForEntry(entry,
            assistantMessage('Added securely', { outcome: 'action_verified', receipts: [receipt] }),
            { cart: { item_count: 1, formatted_total: '$10', cart_url: `${origin}/cart`, checkout_url: `${origin}/checkout` } }
        ) })
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('add it');
    await page.locator('.ysai-send').click();
    const verified = page.locator('.ysai-message[data-outcome="action_verified"]');
    await expect(verified).toContainText('Added securely');
    await expect(page.locator('.ysai-message-retry')).toHaveCount(0);
});

test('verified replace receipt renders from a live response without a retry failure', async ({ page }) => {
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
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => ({ body: turnPayloadForEntry(entry,
            assistantMessage('Replaced securely', { outcome: 'action_verified', receipts: [receipt] }),
            { cart: { item_count: 2, formatted_total: '$20', cart_url: `${origin}/cart`, checkout_url: `${origin}/checkout` } }
        ) })
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('replace it');
    await page.locator('.ysai-send').click();
    const verified = page.locator('.ysai-message[data-outcome="action_verified"]');
    await expect(verified).toContainText('Replaced securely');
    await expect(page.locator('.ysai-message-retry')).toHaveCount(0);
});

test('verified replace receipt renders from canonical boot history after reload', async ({ page }) => {
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
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([
                    assistantMessage('Replaced securely', { outcome: 'action_verified', receipts: [receipt] })
                ], ['replace it'])
            },
            cart: { item_count: 2, formatted_total: '$20', cart_url: `${origin}/cart`, checkout_url: `${origin}/checkout` }
        }) })
    });
    await openReady(page);
    const verified = page.locator('.ysai-message[data-outcome="action_verified"]');
    await expect(verified).toContainText('Replaced securely');
    await expect(page.locator('.ysai-message-retry')).toHaveCount(0);
});
