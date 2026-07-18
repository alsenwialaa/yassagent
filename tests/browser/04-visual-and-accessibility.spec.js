'use strict';

Object.assign(globalThis, require('./support/widget-fixture'));

test('appearance tokens keep composer actions physical and product presentation responsive in RTL', async ({ page }) => {
    const products = [1, 2, 3].map(id => ({
        id,
        name: `Product ${id}`,
        formatted_price: `$${id}0`,
        short_description: `Description ${id}`,
        in_stock: true,
        requires_variation: false,
        image: '',
        permalink: `${origin}/product/${id}`
    }));
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('Products', { products })])
            },
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await page.locator('#widget').evaluate(root => {
        root.className = 'ysai-widget-root ysai-position-right ysai-product-layout-carousel ysai-product-cards-2 ysai-show-product-description';
        root.style.setProperty('--ysai-brand', '#380000');
        root.style.setProperty('--ysai-header-bg', '#4a1020');
        root.style.setProperty('--ysai-header-fg', '#fffaf5');
        root.style.setProperty('--ysai-chat-bg', '#f7f3f2');
        root.style.setProperty('--ysai-surface', '#ffffff');
        root.style.setProperty('--ysai-assistant-bubble', '#ffffff');
        root.style.setProperty('--ysai-user-bubble', '#380000');
        root.style.setProperty('--ysai-product-cards', '2');
        root.style.setProperty('--ysai-product-ratio', '4 / 3');
        root.style.setProperty('--ysai-card-radius', '25px');
    });
    await openReady(page);

    const metrics = await page.evaluate(() => {
        const rect = selector => document.querySelector(selector).getBoundingClientRect();
        const send = rect('.ysai-send');
        const attach = rect('.ysai-attach');
        const productsNode = rect('.ysai-products');
        const card = rect('.ysai-product-card');
        return {
            sendX: send.x,
            attachX: attach.x,
            chatBackground: getComputedStyle(document.querySelector('.ysai-messages')).backgroundColor,
            assistantBubble: getComputedStyle(document.querySelector('.ysai-bubble')).backgroundColor,
            aspectRatio: getComputedStyle(document.querySelector('.ysai-product-placeholder')).aspectRatio,
            cardShare: card.width / productsNode.width,
            headerBackground: getComputedStyle(document.querySelector('.ysai-header')).backgroundColor,
            headerTitleColor: getComputedStyle(document.querySelector('.ysai-title')).color,
            headerCloseColor: getComputedStyle(document.querySelector('.ysai-close')).color,
            cardRadius: parseFloat(getComputedStyle(document.querySelector('.ysai-product-card')).borderTopLeftRadius)
        };
    });

    expect(metrics.sendX).toBeLessThan(metrics.attachX);
    expect(metrics.chatBackground).not.toBe(metrics.assistantBubble);
    expect(metrics.aspectRatio).toBe('4 / 3');
    expect(metrics.cardShare).toBeGreaterThan(0.4);
    expect(metrics.cardShare).toBeLessThan(0.56);
    expect(metrics.headerBackground).toBe('rgb(74, 16, 32)');
    expect(metrics.headerTitleColor).toBe('rgb(255, 250, 245)');
    expect(metrics.headerCloseColor).toBe('rgb(255, 250, 245)');
    expect(metrics.cardRadius).toBeCloseTo(25, 1);
});

test('customer bubbles stay physically right and assistant bubbles physically left in the Arabic RTL surface', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('رسالة المساعد')], ['رسالة العميل'])
            }
        }) })
    });
    await openReady(page);

    const readGeometry = () => page.evaluate(() => {
        const assistant = document.querySelector('.ysai-message.is-assistant');
        const user = document.querySelector('.ysai-message.is-user');
        const assistantRowNode = assistant.querySelector('.ysai-message-row');
        const assistantBubbleNode = assistant.querySelector('.ysai-bubble');
        const userRowNode = user.querySelector('.ysai-message-row');
        const userBubbleNode = user.querySelector('.ysai-bubble');
        const assistantRow = assistantRowNode.getBoundingClientRect();
        const assistantBubble = assistantBubbleNode.getBoundingClientRect();
        const assistantActions = assistant.querySelector('.ysai-message-actions').getBoundingClientRect();
        const userRow = userRowNode.getBoundingClientRect();
        const userBubble = userBubbleNode.getBoundingClientRect();
        const userActions = user.querySelector('.ysai-message-actions').getBoundingClientRect();
        const assistantStyle = getComputedStyle(assistant.querySelector('.ysai-bubble'));
        const userStyle = getComputedStyle(user.querySelector('.ysai-bubble'));
        return {
            assistantLeftGap: Math.abs(assistantBubble.left - assistantRow.left),
            userRightGap: Math.abs(userRow.right - userBubble.right),
            assistantActionsAfterBubble: assistantActions.left >= assistantBubble.right,
            userActionsBeforeBubble: userActions.right <= userBubble.left,
            assistantContentFirst: assistantRowNode.firstElementChild === assistantBubbleNode,
            userContentFirst: userRowNode.firstElementChild === userBubbleNode,
            assistantRadius: parseFloat(assistantStyle.borderTopRightRadius),
            userRadius: parseFloat(userStyle.borderTopLeftRadius),
            assistantTailLeft: parseFloat(assistantStyle.borderBottomLeftRadius),
            userTailRight: parseFloat(userStyle.borderBottomRightRadius),
            assistantTailClip: getComputedStyle(assistantBubbleNode, '::after').clipPath,
            userTailClip: getComputedStyle(userBubbleNode, '::after').clipPath,
            assistantActionLabels: Array.from(assistant.querySelectorAll('.ysai-message-action')).map(node => node.getAttribute('aria-label')),
            userActionLabels: Array.from(user.querySelectorAll('.ysai-message-action')).map(node => node.getAttribute('aria-label'))
        };
    });

    await expect(page.locator('.ysai-panel')).toHaveAttribute('dir', 'rtl');
    const geometry = await readGeometry();
    expect(geometry.assistantLeftGap).toBeLessThan(1);
    expect(geometry.userRightGap).toBeLessThan(1);
    expect(geometry.assistantActionsAfterBubble).toBe(true);
    expect(geometry.userActionsBeforeBubble).toBe(true);
    expect(geometry.assistantContentFirst).toBe(true);
    expect(geometry.userContentFirst).toBe(true);
    expect(geometry.assistantRadius).toBeCloseTo(20, 1);
    expect(geometry.userRadius).toBeCloseTo(20, 1);
    expect(geometry.assistantTailLeft).toBeCloseTo(6.8, 1);
    expect(geometry.userTailRight).toBeCloseTo(6.8, 1);
    expect(geometry.assistantTailClip).not.toBe(geometry.userTailClip);
    expect(geometry.assistantActionLabels).toEqual(['الرد على الرسالة', 'نسخ الرسالة']);
    expect(geometry.userActionLabels).toEqual(['الرد على الرسالة']);
});

test('admin appearance preview preserves physical message ownership under RTL', async ({ page }) => {
    await page.setContent(`<!doctype html><html><body>
        <div class="ysai-admin-preview">
            <div class="ysai-preview-panel">
                <div class="ysai-preview-chat">
                    <div class="ysai-preview-message is-assistant"><span dir="auto">رسالة المساعد</span></div>
                    <div class="ysai-preview-message is-user"><span dir="auto">رسالة العميل</span></div>
                </div>
            </div>
        </div>
    </body></html>`);
    await page.addStyleTag({ path: adminStylesheet });

    const geometry = await page.evaluate(() => {
        const assistantRow = document.querySelector('.ysai-preview-message.is-assistant').getBoundingClientRect();
        const userRow = document.querySelector('.ysai-preview-message.is-user').getBoundingClientRect();
        const assistant = document.querySelector('.ysai-preview-message.is-assistant span').getBoundingClientRect();
        const user = document.querySelector('.ysai-preview-message.is-user span').getBoundingClientRect();
        const assistantStyle = getComputedStyle(document.querySelector('.ysai-preview-message.is-assistant span'));
        const userStyle = getComputedStyle(document.querySelector('.ysai-preview-message.is-user span'));
        return {
            assistantLeftGap: Math.abs(assistant.left - assistantRow.left),
            userRightGap: Math.abs(userRow.right - user.right),
            assistantDirection: assistantStyle.direction,
            userDirection: userStyle.direction,
            assistantRadius: parseFloat(assistantStyle.borderTopRightRadius),
            userRadius: parseFloat(userStyle.borderTopLeftRadius),
            assistantTailLeft: parseFloat(assistantStyle.borderBottomLeftRadius),
            userTailRight: parseFloat(userStyle.borderBottomRightRadius),
            assistantTailClip: getComputedStyle(document.querySelector('.ysai-preview-message.is-assistant span'), '::after').clipPath,
            userTailClip: getComputedStyle(document.querySelector('.ysai-preview-message.is-user span'), '::after').clipPath
        };
    });

    expect(geometry.assistantLeftGap).toBeLessThan(1);
    expect(geometry.userRightGap).toBeLessThan(1);
    expect(geometry.assistantDirection).toBe('rtl');
    expect(geometry.userDirection).toBe('rtl');
    expect(geometry.assistantRadius).toBeCloseTo(20, 1);
    expect(geometry.userRadius).toBeCloseTo(20, 1);
    expect(geometry.assistantTailLeft).toBeCloseTo(6.8, 1);
    expect(geometry.userTailRight).toBeCloseTo(6.8, 1);
    expect(geometry.assistantTailClip).not.toBe(geometry.userTailClip);
});

test('mobile-first composer uses a single messaging row and activates send only for a valid draft', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await openReady(page);

    await expect(page.locator('.ysai-send')).toBeDisabled();
    await page.waitForTimeout(220);
    const metrics = await page.evaluate(() => {
        const node = selector => document.querySelector(selector);
        const rect = selector => node(selector).getBoundingClientRect();
        const row = rect('.ysai-composer-row');
        const send = rect('.ysai-send');
        const shell = rect('.ysai-input-shell');
        const attach = rect('.ysai-attach');
        const rowStyle = getComputedStyle(node('.ysai-composer-row'));
        const shellStyle = getComputedStyle(node('.ysai-input-shell'));
        const inputStyle = getComputedStyle(node('.ysai-input'));
        return {
            sendX: send.x,
            shellX: shell.x,
            attachX: attach.x,
            sendSize: Math.min(send.width, send.height),
            attachSize: Math.min(attach.width, attach.height),
            shellHeight: shell.height,
            rowHeight: row.height,
            rowRadius: parseFloat(rowStyle.borderTopLeftRadius),
            rowBorderWidth: parseFloat(rowStyle.borderTopWidth),
            shellBorderWidth: parseFloat(shellStyle.borderTopWidth),
            inputFontSize: parseFloat(inputStyle.fontSize),
            actionsInsideDock: send.left >= row.left && attach.right <= row.right,
            sendIconPaths: Array.from(node('.ysai-send').querySelectorAll('path')).map(path => path.getAttribute('d')),
            rowCount: document.querySelectorAll('.ysai-composer-row').length,
            toolbarCount: document.querySelectorAll('.ysai-composer-toolbar').length,
            hiddenCartHeight: rect('.ysai-cart-summary').height,
            hiddenAttachmentHeight: rect('.ysai-attachment-previews').height
        };
    });

    expect(metrics.rowCount).toBe(1);
    expect(metrics.toolbarCount).toBe(0);
    expect(metrics.hiddenCartHeight).toBe(0);
    expect(metrics.hiddenAttachmentHeight).toBe(0);
    expect(metrics.sendX).toBeLessThan(metrics.shellX);
    expect(metrics.shellX).toBeLessThan(metrics.attachX);
    expect(metrics.sendSize).toBeGreaterThanOrEqual(48);
    expect(metrics.attachSize).toBeGreaterThanOrEqual(48);
    expect(metrics.shellHeight).toBeGreaterThanOrEqual(48);
    expect(metrics.rowHeight).toBeGreaterThanOrEqual(58);
    expect(metrics.rowRadius).toBeGreaterThanOrEqual(28);
    expect(metrics.rowBorderWidth).toBeGreaterThanOrEqual(1);
    expect(metrics.shellBorderWidth).toBe(0);
    expect(metrics.inputFontSize).toBeGreaterThanOrEqual(16);
    expect(metrics.actionsInsideDock).toBe(true);
    expect(metrics.sendIconPaths).toEqual(['M12 19V5', 'm6.5 10.5 5.5-5.5 5.5 5.5']);

    await page.locator('.ysai-input').fill('رسالة تجريبية');
    await expect(page.locator('.ysai-send')).toBeEnabled();
    await expect(page.locator('.ysai-composer')).toHaveClass(/has-draft/);
});

test('mobile widget opens as a full-height messaging surface without empty footer rows', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: 'اكتب ما تبحث عنه.'
            },
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await openReady(page);
    await page.waitForTimeout(220);
    await expect(page.locator('.ysai-empty-state-hint')).toHaveText('اكتب ما تبحث عنه.');
    await expect(page.locator('.ysai-message')).toHaveCount(0);

    const metrics = await page.evaluate(() => {
        const panel = document.querySelector('.ysai-panel').getBoundingClientRect();
        const composer = document.querySelector('.ysai-composer').getBoundingClientRect();
        const status = document.querySelector('.ysai-status-line');
        return {
            panelX: panel.x,
            panelY: panel.y,
            panelWidth: panel.width,
            panelHeight: panel.height,
            composerBottomGap: Math.abs(panel.bottom - composer.bottom),
            statusHidden: status.hidden,
            htmlLocked: document.documentElement.classList.contains('ysai-widget-modal-open'),
            bodyLocked: document.body.classList.contains('ysai-widget-modal-open'),
            horizontalOverflow: document.querySelector('.ysai-panel').scrollWidth - panel.width
        };
    });

    expect(Math.abs(metrics.panelX)).toBeLessThanOrEqual(1);
    expect(Math.abs(metrics.panelY)).toBeLessThanOrEqual(1);
    expect(Math.abs(metrics.panelWidth - 390)).toBeLessThanOrEqual(1);
    expect(Math.abs(metrics.panelHeight - 844)).toBeLessThanOrEqual(1);
    expect(metrics.composerBottomGap).toBeLessThanOrEqual(1);
    expect(metrics.statusHidden).toBe(true);
    expect(metrics.htmlLocked).toBe(true);
    expect(metrics.bodyLocked).toBe(true);
    expect(metrics.horizontalOverflow).toBeLessThanOrEqual(1);

    await page.locator('.ysai-close').click();
    await expect(page.locator('.ysai-panel')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.classList.contains('ysai-widget-modal-open'))).toBe(false);
    expect(await page.evaluate(() => document.body.classList.contains('ysai-widget-modal-open'))).toBe(false);
});

test('bubble radius controls both owners, typing aligns left, and long quotes clamp neatly', async ({ page }) => {
    let releaseChat;
    const quotedText = 'هذا نص طويل مقتبس لاختبار عرض جزء من الرسالة الأصلية داخل فقاعة الاقتباس بطريقة مرتبة وواضحة دون أن تختفي الفقاعة أو تتمدد خارج حدود المحادثة على شاشة الهاتف الصغيرة. '.repeat(4).trim().slice(0, 270);
    const quoteTurnId = '87878787-8787-4787-8787-878787878787';
    const quotedUser = userMessage(
        'تم إرسال هذا الرد مع اقتباس.',
        quoteTurnId,
        '86868686-8686-4686-8686-868686868686'
    );
    quotedUser.presentation = { image_scope: 'none', images: [], reply_quote: quotedText };
    const quotedMessage = assistantMessage('تم استلام ردك وسأتابع معك.', {
        id: '88888888-8888-4888-8888-888888888888',
        turn_id: quoteTurnId
    });

    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'متصل الآن', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: [quotedUser, quotedMessage]
            }
        }) }),
        '/wp-json/yassin-ai/v1/chat': async entry => new Promise(resolve => {
            releaseChat = () => resolve({ body: turnPayloadForEntry(entry, assistantMessage('تم')) });
        })
    });
    await page.locator('#widget').evaluate(root => root.style.setProperty('--ysai-bubble-radius', '28px'));
    await openReady(page);

    const quoteMetrics = await page.evaluate(() => {
        const assistant = document.querySelector('.ysai-message.is-assistant .ysai-bubble');
        const quote = document.querySelector('.ysai-quoted-message');
        const quoteCopy = quote.querySelector('.ysai-quoted-copy');
        const assistantStyle = getComputedStyle(assistant);
        const quoteStyle = getComputedStyle(quoteCopy);
        return {
            assistantRadius: parseFloat(assistantStyle.borderTopRightRadius),
            assistantTailRadius: parseFloat(assistantStyle.borderBottomLeftRadius),
            quoteClamp: quoteStyle.webkitLineClamp,
            quoteWhiteSpace: quoteStyle.whiteSpace,
            quoteHeight: quoteCopy.getBoundingClientRect().height,
            quoteScrollHeight: quoteCopy.scrollHeight,
            quoteText: quote.textContent
        };
    });
    expect(quoteMetrics.assistantRadius).toBeCloseTo(28, 1);
    expect(quoteMetrics.assistantTailRadius).toBeCloseTo(9.5, 1);
    expect(quoteMetrics.quoteClamp).toBe('3');
    expect(quoteMetrics.quoteWhiteSpace).toBe('normal');
    expect(quoteMetrics.quoteHeight).toBeLessThan(90);
    expect(quoteMetrics.quoteScrollHeight).toBeGreaterThanOrEqual(quoteMetrics.quoteHeight);
    expect(quoteMetrics.quoteText.length).toBeGreaterThan(120);

    await page.locator('.ysai-input').fill('اختبار الكتابة');
    await page.locator('.ysai-send').click();
    await expect(page.locator('.ysai-typing-message')).toBeVisible();
    const typingGeometry = await page.evaluate(() => {
        const messages = document.querySelector('.ysai-messages').getBoundingClientRect();
        const typing = document.querySelector('.ysai-typing-message').getBoundingClientRect();
        const bubble = document.querySelector('.ysai-typing-bubble').getBoundingClientRect();
        const style = getComputedStyle(document.querySelector('.ysai-typing-bubble'));
        return {
            leftGap: Math.abs(bubble.left - messages.left - parseFloat(getComputedStyle(document.querySelector('.ysai-messages')).paddingLeft)),
            containerLeft: typing.left,
            tailRadius: parseFloat(style.borderBottomLeftRadius),
            tailClip: getComputedStyle(document.querySelector('.ysai-typing-bubble'), '::after').clipPath
        };
    });
    expect(typingGeometry.leftGap).toBeLessThan(1.5);
    expect(typingGeometry.tailRadius).toBeCloseTo(9.5, 1);
    expect(typingGeometry.tailClip).toContain('100%');

    releaseChat();
    await expect(page.getByText('تم', { exact: true })).toBeVisible();
});

test('site icon, quoted product thumbnail, and sent image thumbnail are presentation-only', async ({ page }) => {
    const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await page.route('**/site-icon.png', route => route.fulfill({ status: 200, contentType: 'image/png', body: pixel }));
    await page.route('**/quoted-product.png', route => route.fulfill({ status: 200, contentType: 'image/png', body: pixel }));
    const bodies = [];
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('Choose this product', {
                    products: [{
                        id: 21,
                        name: 'Quoted product',
                        formatted_price: '$12',
                        short_description: '',
                        in_stock: true,
                        requires_variation: false,
                        image: `${origin}/quoted-product.png`,
                        permalink: `${origin}/product/quoted`
                    }]
                })])
            },
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            bodies.push(JSON.parse(entry.body));
            return { body: turnPayloadForEntry(entry, assistantMessage('Received', {
                id: bodies.length === 1
                    ? '88888888-8888-4888-8888-888888888888'
                    : '99999999-9999-4999-8999-999999999999'
            })) };
        }
    }, true, 0, { siteIconUrl: `${origin}/site-icon.png` });

    await openReady(page);
    await expect(page.locator('.ysai-brand-avatar')).toHaveAttribute('src', `${origin}/site-icon.png`);

    await page.locator('.ysai-product-quote').click();
    await expect(page.locator('.ysai-reply-preview.has-media .ysai-reply-preview-image'))
        .toHaveAttribute('src', `${origin}/quoted-product.png`);
    await page.locator('.ysai-input').fill('Add this one');
    await page.locator('.ysai-send').click();
    await expect(page.locator('.ysai-message.is-user .ysai-quoted-thumbnail'))
        .toHaveAttribute('src', `${origin}/quoted-product.png`);
    expect(Object.keys(bodies[0]).sort()).toEqual([
        'attachments', 'client_turn_id', 'conversation_id', 'conversation_token', 'message', 'reply_context'
    ]);
    expect(bodies[0].reply_context).toEqual({
        text: 'Quoted product — ⁨$12⁩',
        message_id: '91000000-0000-4000-8000-000000000001',
        product_index: 0
    });
    expect(JSON.stringify(bodies[0])).not.toContain('quoted-product.png');

    await expect(page.locator('.ysai-input')).toBeEnabled();
    await page.locator('.ysai-file-input').setInputFiles({
        name: 'sent.png',
        mimeType: 'image/png',
        buffer: pixel
    });
    await expect(page.locator('.ysai-attachment img')).toHaveCount(1);
    await page.locator('.ysai-input').fill('What is this?');
    await page.locator('.ysai-send').click();
    await expect(page.locator('.ysai-message.is-user .ysai-message-image')).toHaveCount(1);
    await expect(page.locator('.ysai-message.is-user .ysai-message-image')).toHaveAttribute('src', /^data:image\/(?:webp|png);base64,/);
    expect(Object.keys(bodies[1]).sort()).toEqual([
        'attachments', 'client_turn_id', 'conversation_id', 'conversation_token', 'message'
    ]);
    expect(bodies[1].attachments).toHaveLength(1);
    expect(Object.keys(bodies[1].attachments[0]).sort()).toEqual(['data', 'mime_type']);
});

test('busy state uses one typing indicator and truthful header presence', async ({ page }) => {
    let releaseTurn;
    const pendingTurn = new Promise(resolve => { releaseTurn = resolve; });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() }),
        '/wp-json/yassin-ai/v1/chat': async entry => {
            await pendingTurn;
            return { body: turnPayloadForEntry(entry, assistantMessage('Done')) };
        }
    });
    await openReady(page);
    await page.locator('.ysai-input').fill('wait for this');
    await page.locator('.ysai-send').click();

    await expect(page.locator('.ysai-typing-message')).toHaveCount(1);
    await expect(page.locator('.ysai-typing-message')).toHaveAttribute('role', 'status');
    await expect(page.locator('.ysai-typing-message')).toHaveAttribute('aria-label', 'جارٍ التحقق');
    await expect(page.locator('.ysai-typing-bubble')).toHaveAttribute('aria-hidden', 'true');
    await expect(page.locator('.ysai-status-line')).toBeHidden();
    await expect(page.locator('.ysai-composer-hint')).toHaveCount(0);
    await expect(page.locator('.ysai-subtitle')).toHaveText('جارٍ التحقق');
    await expect(page.locator('.ysai-subtitle')).toHaveAttribute('aria-live', 'off');
    await expect(page.locator('#widget')).toHaveClass(/is-busy/);

    const busyColor = await page.locator('.ysai-presence-dot').evaluate(node => getComputedStyle(node).backgroundColor);
    expect(busyColor).toBe('rgb(213, 138, 24)');

    releaseTurn();
    await expect(page.getByText('Done')).toBeVisible();
    await expect(page.locator('.ysai-subtitle')).toHaveText('مساعدة مباشرة');
    await expect(page.locator('#widget')).not.toHaveClass(/is-busy/);
});

test('blocked readiness does not keep a green online presence', async ({ page }) => {
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            capabilities: { chat_ready: false, images: false, max_images: 0, max_image_bytes: 0, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await page.locator('.ysai-launcher').click();
    await expect(page.locator('#widget')).toHaveClass(/is-unavailable/);
    await expect(page.getByText('المساعد غير متاح')).toHaveCount(1);
    await expect(page.locator('.ysai-subtitle')).toHaveText('المساعد غير متاح');
    await expect(page.locator('.ysai-subtitle')).toHaveAttribute('aria-live', 'polite');
    const color = await page.locator('.ysai-presence-dot').evaluate(node => getComputedStyle(node).backgroundColor);
    expect(color).toBe('rgb(147, 135, 138)');
});

test('desktop open state removes the inert launcher and anchors the panel cleanly', async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 820 });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload() })
    });
    await openReady(page);
    await expect(page.locator('.ysai-launcher')).toBeHidden();
    await page.waitForTimeout(250);
    const metrics = await page.evaluate(() => {
        const panel = document.querySelector('.ysai-panel').getBoundingClientRect();
        return {
            viewportHeight: window.innerHeight,
            panelBottom: panel.bottom,
            rootBottom: parseFloat(getComputedStyle(document.querySelector('#widget')).bottom)
        };
    });
    expect(Math.abs(metrics.viewportHeight - metrics.panelBottom - metrics.rootBottom)).toBeLessThanOrEqual(1);
    await page.locator('.ysai-close').click();
    await expect(page.locator('.ysai-launcher')).toBeVisible();
    await expect(page.locator('.ysai-launcher')).toBeFocused();
});

test('one attachment uses a compact preview aligned to the physical right', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            capabilities: { chat_ready: true, images: true, max_images: 2, max_image_bytes: 524288, cart_mutations: { available: true, code: 'available', notice: '' } }
        }) })
    });
    await openReady(page);
    await page.locator('.ysai-file-input').setInputFiles({
        name: 'preview.png', mimeType: 'image/png', buffer: pixel
    });
    await expect(page.locator('.ysai-attachment img')).toHaveCount(1);
    const geometry = await page.evaluate(() => {
        const preview = document.querySelector('.ysai-attachment-previews').getBoundingClientRect();
        const panel = document.querySelector('.ysai-panel').getBoundingClientRect();
        const remove = document.querySelector('.ysai-attachment > button').getBoundingClientRect();
        return {
            previewWidth: preview.width,
            rightGap: panel.right - preview.right,
            removeWidth: remove.width,
            removeHeight: remove.height
        };
    });
    expect(geometry.previewWidth).toBeLessThan(100);
    expect(geometry.rightGap).toBeGreaterThanOrEqual(8);
    expect(geometry.rightGap).toBeLessThanOrEqual(11);
    expect(geometry.removeWidth).toBeGreaterThanOrEqual(25.5);
    expect(geometry.removeHeight).toBeGreaterThanOrEqual(25.5);
});

test('carousel navigation respects cards per view and synchronizes after scrolling', async ({ page }) => {
    const products = [1, 2, 3].map(id => ({
        id,
        name: `Product ${id}`,
        formatted_price: `$${id}0`,
        short_description: '',
        in_stock: true,
        requires_variation: false,
        image: '',
        permalink: `${origin}/product/${id}`
    }));
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('Products', { products })])
            }
        }) })
    });
    await page.locator('#widget').evaluate(root => {
        root.className = 'ysai-widget-root ysai-position-right ysai-product-layout-carousel ysai-product-cards-2';
        root.style.setProperty('--ysai-product-cards', '2');
    });
    await openReady(page);

    const previous = page.getByRole('button', { name: 'المنتجات السابقة' });
    const next = page.getByRole('button', { name: 'المنتجات التالية' });
    const productsNode = page.locator('.ysai-products');
    const controlledId = await next.getAttribute('aria-controls');
    expect(controlledId).toBeTruthy();
    await expect(productsNode).toHaveAttribute('id', controlledId);
    await expect(previous).toBeDisabled();
    await expect(next).toBeEnabled();

    await next.click();
    await expect(previous).toBeEnabled();
    await expect(next).toBeDisabled();

    await previous.click();
    await expect(previous).toBeDisabled();
    await expect(next).toBeEnabled();

    await page.locator('.ysai-product-card').nth(1).evaluate(node => {
        node.scrollIntoView({ behavior: 'instant', block: 'nearest', inline: 'start' });
    });
    await page.waitForTimeout(120);
    await expect(previous).toBeEnabled();
    await expect(next).toBeDisabled();
});

test('mobile UI has labeled controls, valid media alternatives, and no horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 568 });
    await install(page, {
        '/wp-json/yassin-ai/v1/boot': async () => ({ body: bootPayload({
            widget: {
                title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
                empty_state_hint: ''
            },
            conversation: {
                id: '00000000-0000-4000-8000-000000000001',
                token: 'conversation-token-1234567890',
                messages: pairedMessages([assistantMessage('Choose a product', {
                    outcome: 'follow_up',
                    products: [{
                        id: 91,
                        name: 'Accessible product',
                        formatted_price: '$12',
                        short_description: 'A concise description.',
                        in_stock: true,
                        requires_variation: false,
                        image: `${origin}/accessible-product.png`,
                        permalink: `${origin}/product/accessible`
                    }]
                })])
            }
        }) })
    });
    await page.route('**/accessible-product.png', route => route.fulfill({
        status: 200,
        contentType: 'image/png',
        body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64')
    }));
    await openReady(page);

    const audit = await page.evaluate(() => {
        const panel = document.querySelector('.ysai-panel');
        const visible = node => {
            const style = getComputedStyle(node);
            const rect = node.getBoundingClientRect();
            return !node.hidden && style.display !== 'none' && style.visibility !== 'hidden'
                && rect.width > 0 && rect.height > 0;
        };
        const controls = Array.from(panel.querySelectorAll('button, a[href], textarea')).filter(visible);
        const unlabeled = controls.filter(node => {
            const name = String(node.getAttribute('aria-label') || node.textContent || node.title || '').trim();
            return name === '';
        }).map(node => node.className || node.tagName);
        const smallButtons = controls.filter(node => node.tagName === 'BUTTON').filter(node => {
            const rect = node.getBoundingClientRect();
            return rect.width < 24 || rect.height < 24;
        }).map(node => ({ className: node.className, width: node.getBoundingClientRect().width, height: node.getBoundingClientRect().height }));
        const imagesWithoutAlt = Array.from(panel.querySelectorAll('img')).filter(visible)
            .filter(image => !image.hasAttribute('alt')).map(image => image.className);
        const idCounts = {};
        Array.from(document.querySelectorAll('[id]')).forEach(node => {
            idCounts[node.id] = (idCounts[node.id] || 0) + 1;
        });
        return {
            unlabeled,
            smallButtons,
            imagesWithoutAlt,
            duplicateIds: Object.keys(idCounts).filter(id => idCounts[id] > 1),
            panelOverflow: panel.scrollWidth - panel.clientWidth,
            pageOverflow: document.documentElement.scrollWidth - window.innerWidth
        };
    });

    expect(audit.unlabeled).toEqual([]);
    expect(audit.smallButtons).toEqual([]);
    expect(audit.imagesWithoutAlt).toEqual([]);
    expect(audit.duplicateIds).toEqual([]);
    expect(audit.panelOverflow).toBeLessThanOrEqual(1);
    expect(audit.pageOverflow).toBeLessThanOrEqual(1);
});
