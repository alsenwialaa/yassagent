'use strict';

const { test, expect } = require('@playwright/test');
const {
    reset, configureScenario, setFault, openWidget, sendMessage,
    lastAssistant, pageState, fakeState
} = require('../helpers/harness');

test('simple add executes once and renders only a verified receipt', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'action_verified');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(1);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).toBe('verified');
    expect(Number(state.database.operations[0].has_effects)).toBe(1);
    expect(Number(state.database.operations[0].has_receipt)).toBe(1);
    const visible = await message.locator('.ysai-bubble').innerText();
    expect(visible).toBe(state.database.operations[0].receipt_message);
});

test('polite direct add with omitted quantity resolves one unique live product', async ({ page, request }) => {
    await reset(request, 'add_simple', { query: 'integration coffee' });
    await openWidget(page);
    await sendMessage(page, 'هل يمكنك إضافة Integration Coffee إلى السلة؟');

    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'action_verified');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(1);
    expect(state.cart.items[0].quantity).toBe(1);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).toBe('verified');
});

test('mutation with a sibling is rejected before execution and corrected to one mutation', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await configureScenario(request, 'mutation_with_sibling', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');
    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'action_verified');

    const state = await pageState(page);
    expect(state.cart.item_count).toBe(1);
    expect(state.database.operations).toHaveLength(1);
    const model = await fakeState(request);
    expect(model.calls.some(call => call.feedback_names.includes('cart_apply'))).toBe(true);
});

test('WooCommerce add rejection is visible without another provider request and never produces a receipt', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await setFault(request, 'reject_add');
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(0);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).not.toBe('verified');
    expect(Number(state.database.operations[0].has_receipt)).toBe(0);
    const model = await fakeState(request);
    expect(model.calls.filter(call => call.declaration_names.length === 20)).toHaveLength(2);
    expect(model.calls.filter(call => call.declaration_names[0] === 'verify_current_cart_intent')).toHaveLength(1);
    expect(model.calls.some(call => call.feedback_names.includes('cart_apply'))).toBe(false);
});

test('a WooCommerce exception is contained and never becomes verified success', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await setFault(request, 'throw_add');
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(0);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).not.toBe('verified');
    expect(Number(state.database.operations[0].has_receipt)).toBe(0);
});

test('a hook that changes quantity before persistence restores the request-local cart and rejects the action', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await setFault(request, 'change_quantity_after_add');
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(0);
    expect(state.cart.items).toEqual([]);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).toBe('rejected');
    expect(Number(state.database.operations[0].has_receipt)).toBe(0);
});

test('catalog search renders grounded live products without a cart operation', async ({ page, request }) => {
    await reset(request, 'search_answer', { text: 'هذه نتيجة تكامل موثقة.' });
    await openWidget(page);
    await sendMessage(page, 'show integration coffee');

    await expect(await lastAssistant(page)).toContainText('هذه نتيجة تكامل موثقة.');
    await expect(page.locator('.ysai-product-card')).toHaveCount(1);
    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);
});

test('queryless newest discovery returns the newest eligible live product', async ({ page, request }) => {
    await reset(request, 'newest_answer');
    await openWidget(page);
    await sendMessage(page, 'show the newest integration fixture');

    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'answer');
    const cards = page.locator('.ysai-product-card');
    await expect(cards).toHaveCount(1);
    await expect(cards.first()).toContainText('Integration Shirt');
    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);

    const model = await fakeState(request);
    expect(model.calls).toHaveLength(2);
    const discoverCall = model.calls[1].payload.contents
        .flatMap(row => Array.isArray(row.parts) ? row.parts : [])
        .map(part => part.functionCall)
        .find(call => call && call.name === 'catalog_discover');
    expect(discoverCall.args).toEqual(expect.objectContaining({ sort: 'newest', limit: 1 }));
    expect(discoverCall.args).not.toHaveProperty('queries');
});

test('queryless best-selling discovery fills from a later product after budget filtering', async ({ page, request }) => {
    await reset(request, 'best_selling_budget_answer', { max_price: 12 });
    await openWidget(page);
    await sendMessage(page, 'show the best selling integration fixture under twelve dollars');

    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'answer');
    const cards = page.locator('.ysai-product-card');
    await expect(cards).toHaveCount(1);
    await expect(cards.first()).toContainText('Integration Coffee');
    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);

    const model = await fakeState(request);
    expect(model.calls).toHaveLength(2);
    const discoverCall = model.calls[1].payload.contents
        .flatMap(row => Array.isArray(row.parts) ? row.parts : [])
        .map(part => part.functionCall)
        .find(call => call && call.name === 'catalog_discover');
    expect(discoverCall.args).toEqual(expect.objectContaining({
        sort: 'best_selling',
        limit: 1,
        max_price: 12
    }));
    expect(discoverCall.args).not.toHaveProperty('queries');
});



test('AI-led recommendation persists typed memory and uses grounded fit ranking', async ({ page, request }) => {
    await reset(request, 'recommendation_answer', {
        query: 'integration',
        goal: 'Choose an in-stock integration product under 25 dollars',
        max_price: 25,
        text: 'هذه توصية تكامل موثقة.'
    });
    await openWidget(page);
    await sendMessage(page, 'recommend an in-stock integration product under 25 dollars');

    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'answer');
    await expect(message).toContainText('هذه توصية تكامل موثقة.');
    expect(await page.locator('.ysai-product-card').count()).toBeGreaterThanOrEqual(2);

    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);
    expect(state.database.durable_state.schema).toBe(1);
    expect(state.database.durable_state.shopping.goal).toBe('Choose an in-stock integration product under 25 dollars');
    expect(state.database.durable_state.shopping.stage).toBe('discovering');
    expect(state.database.durable_state.shopping.constraints[0].key).toBe('budget');

    const model = await fakeState(request);
    expect(model.calls).toHaveLength(3);
    expect(model.calls[1].feedback_names).toEqual(expect.arrayContaining(['shopping_memory_update', 'catalog_discover']));
    expect(model.calls[2].feedback_names).toContain('catalog_rank_candidates');
    expect(model.calls[0].declaration_names).toContain('catalog_rank_candidates');
    expect(model.calls[0].declaration_names).not.toContain('catalog_search');
});

test('boot discloses chat cart mutation capability before any cart action', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    const capability = await page.evaluate(() => {
        const app = window.__ysaiAssistantApp;
        return app && app.store ? app.store.getState().capabilities.cart_mutations : null;
    });
    expect(capability).toEqual({ available: true, code: 'available', notice: '' });
    await expect(page.locator('.ysai-cart-notice')).toHaveCount(0);
});
