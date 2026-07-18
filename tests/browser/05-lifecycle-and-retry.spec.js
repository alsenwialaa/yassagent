'use strict';

Object.assign(globalThis, require('./support/widget-fixture'));

test('site icon failures fall back cleanly in the storefront and admin preview', async ({ page }) => {
    await page.route('**/broken-site-icon.png', route => route.fulfill({ status: 404, body: '' }));
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    }, true, 0, { siteIconUrl: `${origin}/broken-site-icon.png` });
    await openReady(page);
    await expect(page.locator('.ysai-brand-mark')).not.toHaveClass(/has-site-icon/);
    await expect(page.locator('.ysai-brand-mark .ysai-icon')).toHaveCount(1);

    await page.setContent(`<!doctype html><html><body>
        <div class="ysai-admin-preview" data-ysai-preview="1" inert aria-hidden="true">
            <span class="ysai-preview-mark has-site-icon" aria-hidden="true">
                <img src="${origin}/broken-site-icon.png" alt="">
                <svg class="ysai-preview-mark-fallback" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"></path></svg>
            </span>
        </div>
    </body></html>`);
    await page.addStyleTag({ path: adminStylesheet });
    await page.addScriptTag({ path: adminScript });
    await expect(page.locator('.ysai-preview-mark')).not.toHaveClass(/has-site-icon/);
    await expect(page.locator('.ysai-preview-mark-fallback')).toBeVisible();
    await expect(page.locator('.ysai-preview-mark img')).toBeHidden();
});

test('boot exposes custom-session cart mutation restriction without blocking chat or cart reads', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            capabilities: {
                chat_ready: true,
                images: false,
                max_images: 0,
                max_image_bytes: 0,
                cart_mutations: {
                    available: false,
                    code: 'session_handler_unsupported',
                    notice: 'Chat cart changes are unavailable for this session store.'
                }
            }
        }) })
    });
    await openReady(page);
    await expect(page.locator('.ysai-input')).toBeEnabled();
    await expect(page.locator('.ysai-cart-notice')).toHaveText('Chat cart changes are unavailable for this session store.');
    await expect(page.locator('.ysai-cart-summary')).toBeVisible();
});

test('detaching and reinserting the same root rebuilds exactly one widget surface', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    });
    const root = await page.locator('#widget').elementHandle();
    await expect(page.locator('#widget .ysai-launcher')).toHaveCount(1);
    await page.locator('#widget .ysai-launcher').click();
    await expect(page.locator('#widget .ysai-input')).toBeEnabled();
    await page.evaluate(node => node.remove(), root);
    await page.waitForTimeout(50);
    await page.evaluate(node => document.body.appendChild(node), root);
    await expect(page.locator('#widget .ysai-launcher')).toHaveCount(1);
    await expect(page.locator('#widget .ysai-launcher')).toBeVisible();
    await expect(page.locator('#widget .ysai-panel')).toHaveCount(1);
    expect(await page.evaluate(() => window.__ysaiAssistantApp.views.length)).toBe(1);
});

test('mobile page scroll lock is reference-counted across two open widget views', async ({ page }) => {
    await page.setViewportSize({ width: 400, height: 700 });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    }, false);
    await page.evaluate(() => {
        ['widget-one', 'widget-two'].forEach(id => {
            const root = document.createElement('div');
            root.id = id;
            root.setAttribute('data-ysai-widget', '1');
            document.body.appendChild(root);
        });
    });
    await expect(page.locator('.ysai-launcher')).toHaveCount(2);
    await page.locator('#widget-one .ysai-launcher').evaluate(element => element.click());
    await page.locator('#widget-two .ysai-launcher').evaluate(element => element.click());
    await expect(page.locator('#widget-one .ysai-input')).toBeEnabled();
    await expect(page.locator('#widget-two .ysai-input')).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.classList.contains('ysai-widget-modal-open'))).toBe(true);
    await page.locator('#widget-one .ysai-close').evaluate(element => element.click());
    expect(await page.evaluate(() => document.documentElement.classList.contains('ysai-widget-modal-open'))).toBe(true);
    expect(await page.evaluate(() => document.body.classList.contains('ysai-widget-modal-open'))).toBe(true);
    await page.locator('#widget-two .ysai-close').evaluate(element => element.click());
    expect(await page.evaluate(() => document.documentElement.classList.contains('ysai-widget-modal-open'))).toBe(false);
    expect(await page.evaluate(() => document.body.classList.contains('ysai-widget-modal-open'))).toBe(false);
});

test('mobile card cap overrides the inline desktop three-card token', async ({ page }) => {
    await page.setViewportSize({ width: 400, height: 700 });
    const products = [1, 2, 3].map(id => ({
        id, name: `Product ${id}`, formatted_price: `$${id}`,
        short_description: '', in_stock: true, requires_variation: false,
        image: '', permalink: `${origin}/product/${id}`
    }));
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('Products', { products })])
            }
        }) })
    });
    await page.locator('#widget').evaluate(root => {
        root.classList.remove('ysai-product-cards-1');
        root.classList.add('ysai-product-cards-3');
        root.style.setProperty('--ysai-product-cards', '3');
    });
    await openReady(page);
    expect(await page.locator('#widget').evaluate(root => getComputedStyle(root).getPropertyValue('--ysai-product-cards').trim())).toBe('2');
    const share = await page.evaluate(() => {
        const frame = document.querySelector('.ysai-products').getBoundingClientRect();
        const card = document.querySelector('.ysai-product-card').getBoundingClientRect();
        return card.width / frame.width;
    });
    expect(share).toBeGreaterThan(0.43);
    expect(share).toBeLessThan(0.55);
});

test('clipboard fallback reports failure when execCommand returns false', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('Copy me')])
            }
        }) })
    });
    await openReady(page);
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        document.execCommand = () => false;
    });
    await page.getByRole('button', { name: 'نسخ الرسالة' }).click();
    await expect(page.locator('.ysai-status-line')).toHaveText('تعذر نسخ الرسالة.');
    await expect(page.getByRole('button', { name: 'نسخ الرسالة' })).not.toHaveClass(/is-confirmed/);
});

test('manual retry conversation replacement clears queued image and reply authority', async ({ page }) => {
    let boots = 0;
    let chats = 0;
    let reboundRequest = null;
    const product = {
        id: 7, name: 'Quoted product', formatted_price: '$10', short_description: '',
        in_stock: true, requires_variation: false, image: '', permalink: `${origin}/product/7`
    };
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => {
            boots += 1;
            return { body: bootPayload({
                conversation: {
                    id: `00000000-0000-4000-8000-${String(boots).padStart(12, '0')}`,
                    token: `conversation-token-${String(boots).padStart(12, '0')}`,
                    messages: boots === 1
                        ? pairedMessages([assistantMessage('Product', { products: [product] })])
                        : []
                },
                capabilities: {
                    chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288,
                    cart_mutations: { available: true, code: 'available', notice: '' }
                }
            }) };
        },
        '/wp-json/yassin-ai/v1/chat': async entry => {
            chats += 1;
            reboundRequest = JSON.parse(entry.body || '{}');
            return {
                body: turnPayloadForEntry(entry, assistantMessage('Recovered safely'), {
                    conversation: {
                        id: reboundRequest.conversation_id,
                        token: reboundRequest.conversation_token
                    }
                })
            };
        }
    });
    await openReady(page);
    await page.locator('.ysai-file-input').setInputFiles({
        name: 'queued.png', mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZJXcAAAAASUVORK5CYII=', 'base64')
    });
    await expect(page.locator('.ysai-attachment')).toHaveCount(1);
    await page.locator('.ysai-product-quote').click();
    await expect(page.locator('.ysai-reply-preview')).toBeVisible();

    await page.evaluate(async () => {
        const Runtime = window.YSAIWidgetRuntime;
        const app = window.__ysaiAssistantApp;
        const state = app.store.getState();
        const turnId = '93000000-0000-4000-8000-000000000001';
        const retryId = 'retry-94000000-0000-4000-8000-000000000001';
        const quotedText = 'Quoted product — ⁨$10⁩';
        const envelope = Object.freeze({
            body: JSON.stringify({
                conversation_id: state.conversation.id,
                conversation_token: state.conversation.token,
                client_turn_id: turnId,
                message: 'retry this',
                reply_context: {
                    text: quotedText,
                    message_id: '91000000-0000-4000-8000-000000000001',
                    product_index: 0
                },
                attachments: []
            }),
            visibleText: 'retry this'
        });
        app.retryStore.put(retryId, envelope, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS);
        const startedAt = Date.now();
        app.continuity.writePending({
            turn_id: turnId,
            conversation_id: state.conversation.id,
            retry_id: retryId,
            started_at_ms: startedAt,
            guard_until_ms: startedAt + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS + 5000
        });
        return app.recoverInvalidConversation(
            envelope,
            retryId,
            true,
            new Runtime.ApiError('Conversation expired', 'conversation_invalid', 401, 0)
        );
    });

    await expect.poll(() => boots).toBe(2);
    await expect.poll(() => chats).toBe(1);
    await expect(page.locator('.ysai-attachment')).toHaveCount(0);
    await expect(page.locator('.ysai-reply-preview')).toBeHidden();
    await expect(page.getByText('Recovered safely')).toBeVisible();
    expect(await page.evaluate(() => window.__ysaiAssistantApp.attachments.publicEntries().length)).toBe(0);
    expect(await page.evaluate(() => window.__ysaiAssistantApp.retryStore.ids().length)).toBe(0);
    expect(reboundRequest.conversation_id).toBe('00000000-0000-4000-8000-000000000002');
    expect(reboundRequest.conversation_token).toBe('conversation-token-000000000002');
    expect(reboundRequest.client_turn_id).toBe('93000000-0000-4000-8000-000000000001');
    expect(reboundRequest.message).toBe('retry this');
    expect(reboundRequest.attachments).toEqual([]);
    expect(reboundRequest.reply_context).toEqual({ text: 'Quoted product — ⁨$10⁩' });
});

test('repeated boot failures render one current synthetic failure', async ({ page }) => {
    let boots = 0;
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => {
            boots += 1;
            const message = `Boot failure ${boots}`;
            return { status: 503, body: { ok: false, code: 'boot_failed', message } };
        }
    });
    await page.locator('.ysai-launcher').click();
    await expect(page.getByText('Boot failure 1')).toBeVisible();
    await page.locator('.ysai-close').click();
    await page.locator('.ysai-launcher').click();
    await expect(page.getByText('Boot failure 2')).toBeVisible();
    await expect(page.getByText('Boot failure 1')).toHaveCount(0);
    await expect(page.locator('.ysai-message[data-outcome="safe_failure"]')).toHaveCount(1);
});
