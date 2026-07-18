'use strict';

const { test, expect } = require('@playwright/test');
const {
    reset, configureScenario, setFault, openWidget, sendMessage,
    lastAssistant, pageState
} = require('../helpers/harness');

test('termination after WooCommerce persistence never duplicates or claims success', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await setFault(request, 'terminate_after_add');
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(1);
    expect(state.cart.line_count).toBe(1);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).toBe('uncertain');
    expect(Number(state.database.operations[0].has_receipt)).toBe(0);
});

test('lease loss after the side effect prevents stale-worker commit', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await setFault(request, 'lose_lease_after_add');
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(1);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.operations[0].status).toBe('uncertain');
    expect(Number(state.database.operations[0].has_receipt)).toBe(0);
    expect(state.database.turns[state.database.turns.length - 1].status).toBe('complete');
});

test('unattributed custom cart divergence is left untouched and marked uncertain', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await setFault(request, 'diverge_after_add');
    await configureScenario(request, 'add_simple', { query: 'integration coffee' });
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');

    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.cart.items[0].test_custom).toBe('injected-divergence');
    expect(state.database.operations[0].status).toBe('uncertain');
    expect(Number(state.database.operations[0].has_receipt)).toBe(0);
});
