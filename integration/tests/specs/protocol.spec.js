'use strict';

const { test, expect } = require('@playwright/test');
const { reset, openWidget, sendMessage, lastAssistant, pageState } = require('../helpers/harness');

test('plain model prose is corrected before a terminal answer is accepted', async ({ page, request }) => {
    await reset(request, 'plain_then_terminal', { text: 'هذه إجابة نهائية صحيحة.' });
    await openWidget(page);
    await sendMessage(page, 'force a plain response first');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة نهائية صحيحة.');
    const state = await pageState(page);
    expect(state.database.counts.messages).toBe(2);
});

test('English terminal prose is rejected and corrected to Arabic before publication', async ({ page, request }) => {
    await reset(request, 'english_terminal_then_arabic', { text: 'هذه إجابة عربية بعد تصحيح اللغة.' });
    await openWidget(page);
    await sendMessage(page, 'اختبر حد اللغة العربية');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة عربية بعد تصحيح اللغة.');
    const state = await pageState(page);
    expect(state.provider.calls).toBe(2);
});

test('malformed provider success becomes a visible safe failure', async ({ page, request }) => {
    await reset(request, 'malformed_success');
    await openWidget(page);
    await sendMessage(page, 'malformed provider response');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
});

test('upstream unavailability never yields a blank assistant message', async ({ page, request }) => {
    await reset(request, 'upstream_500');
    await openWidget(page);
    await sendMessage(page, 'provider outage');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
});

test('schema-invalid tool arguments exhaust safely without executing a mutation', async ({ page, request }) => {
    await reset(request, 'invalid_tool_arguments');
    await openWidget(page);
    await sendMessage(page, 'send invalid tool arguments');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);
});

test('a tool call missing a required field exhausts safely without execution', async ({ page, request }) => {
    await reset(request, 'missing_required_tool_field');
    await openWidget(page);
    await sendMessage(page, 'omit the required product reference');
    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);
});

test('visible prose mixed with an executable call is rejected without side effects', async ({ page, request }) => {
    await reset(request, 'mixed_output');
    await openWidget(page);
    await sendMessage(page, 'mix prose and a call');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    const state = await pageState(page);
    expect(state.database.operations).toHaveLength(0);
});

test('an empty provider candidate becomes a visible nonblank failure', async ({ page, request }) => {
    await reset(request, 'empty_candidate');
    await openWidget(page);
    await sendMessage(page, 'return no candidate');
    const message = await lastAssistant(page);
    await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
    await expect(message).not.toHaveText('');
});
