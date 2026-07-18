'use strict';

const { test, expect } = require('@playwright/test');
const {
    reset, configureScenario, globalState, pageControl, openWidget, sendMessage,
    lastAssistant, pageState
} = require('../helpers/harness');

test('a typed product request is revalidated after the product is deleted', async ({ page, request }) => {
    await reset(request, 'answer');
    const fixtures = await globalState(request);
    await openWidget(page);

    await pageControl(page, '/product', { id: fixtures.products.simple, action: 'delete' });
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
    const state = await pageState(page);
    expect(state.database.counts.operations).toBe(0);
});

test('an out-of-stock product cannot produce a verified add receipt', async ({ page, request }) => {
    await reset(request, 'answer');
    const fixtures = await globalState(request);
    await openWidget(page);
    await pageControl(page, '/product', { id: fixtures.products.simple, action: 'out_of_stock' });
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');
    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'safe_failure');

    const state = await pageState(page);
    expect(state.cart.item_count).toBe(0);
    expect(state.database.operations.every(operation => operation.status !== 'verified')).toBe(true);
});

test('a typed variation request is rejected after the variation becomes unavailable', async ({ page, request }) => {
    await reset(request, 'answer');
    const fixtures = await globalState(request);
    await openWidget(page);
    await pageControl(page, '/product', { id: fixtures.products.variations.small, action: 'delete' });
    await configureScenario(request, 'add_variable', { query: 'integration shirt' });
    await sendMessage(page, 'أضف قميص Integration Shirt مقاس Small إلى السلة');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.database.counts.operations).toBe(0);
});

test('typed customer text persists byte-for-byte in canonical history', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'تم استلام الرسالة.' });
    await openWidget(page);
    const responsePromise = page.waitForResponse(response => (
        response.request().method() === 'POST'
        && response.url().includes('/wp-json/yassin-ai/v1/chat')
        && response.status() === 200
    ));
    const exactMessage = 'أخبرني عن Integration Coffee بنسبة خصم %50';
    await sendMessage(page, exactMessage);
    const body = await (await responsePromise).json();
    const canonicalUser = body.conversation.messages.find(message => (
        message.role === 'user' && message.turn_id === body.message.turn_id
    ));
    expect(canonicalUser.text).toBe(exactMessage);
    expect(canonicalUser.presentation).toEqual({ image_scope: 'none', images: [] });
});
