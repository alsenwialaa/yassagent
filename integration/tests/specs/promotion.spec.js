'use strict';

const { test, expect } = require('@playwright/test');
const {
    reset,
    configureScenario,
    openWidget,
    sendMessage,
    lastAssistant,
    pageState,
    fakeState,
    captureNextChat,
    replayCaptured
} = require('../helpers/harness');

const promotionMode = String(process.env.YSAI_PROMOTION_MODE || '') === '1';
const exactQuestion = 'كم قطعة تريد من القهوة &amp; الشاي؟';
const expectedPluginVersion = String(process.env.YSAI_PROMOTION_PLUGIN_VERSION || '');

function containsKey(value, key) {
    if (!value || typeof value !== 'object') {
        return false;
    }
    if (Object.prototype.hasOwnProperty.call(value, key)) {
        return true;
    }
    return Object.values(value).some(child => containsKey(child, key));
}

if (promotionMode) {
    test('installed artifact boots with no fabricated assistant turn', async ({ page, request }) => {
        await reset(request, 'answer', { text: 'هذه إجابة من الحزمة المثبتة.' });
        await openWidget(page);

        await expect(page.locator('.ysai-message[data-role="assistant"]')).toHaveCount(0);
        await expect(page.locator('.ysai-message[data-role="user"]')).toHaveCount(0);

        const state = await pageState(page);
        expect(expectedPluginVersion).not.toBe('');
        expect(state.plugin.version).toBe(expectedPluginVersion);
        expect(state.plugin.installed_under_wp_plugins).toBe(true);
        expect(state.plugin.source_workspace_mount).toBe(false);
        expect(state.database.counts.messages).toBe(0);
        expect(state.database.counts.turns).toBe(0);
    });

    test('exact model clarification survives refresh and resolves one idempotent Woo mutation', async ({ page, request }) => {
        await reset(request, 'add_simple', { query: 'integration coffee' });
        await openWidget(page);
        await sendMessage(page, 'أضف قهوة التكامل إلى السلة');
        await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'action_verified');

        let state = await pageState(page);
        expect(state.cart.item_count).toBe(1);
        expect(state.database.operations).toHaveLength(1);

        await configureScenario(request, 'clarify_quantity_then_update', { question: exactQuestion });
        const capturedClarificationPromise = captureNextChat(page);
        const clarificationResponsePromise = page.waitForResponse(response => (
            response.request().method() === 'POST'
            && response.url().includes('/wp-json/yassin-ai/v1/chat')
            && response.status() === 200
        ));
        await sendMessage(page, 'غيّر كمية القهوة في السلة');
        const capturedClarification = await capturedClarificationPromise;
        const clarificationBody = await (await clarificationResponsePromise).json();

        const clarification = await lastAssistant(page);
        await expect(clarification).toHaveAttribute('data-outcome', 'follow_up');
        await expect(clarification).toHaveText(exactQuestion);
        expect(clarificationBody.message.text).toBe(exactQuestion);
        expect(clarificationBody.message.outcome).toBe('follow_up');
        expect(containsKey(clarificationBody, 'model_question')).toBe(false);
        expect(containsKey(clarificationBody, 'provider_call_id')).toBe(false);
        expect(containsKey(clarificationBody, 'tool_call_id')).toBe(false);
        expect(containsKey(clarificationBody, 'model_step_id')).toBe(false);
        expect(containsKey(clarificationBody, 'accepted_at')).toBe(false);

        state = await pageState(page);
        const persisted = state.database.messages.find(message => message.outcome === 'follow_up');
        expect(persisted).toBeTruthy();
        expect(persisted.content).toBe(exactQuestion);
        expect(persisted.public_text).toBe(exactQuestion);
        expect(persisted.public_outcome).toBe('follow_up');
        expect(persisted.public_contains_private_question).toBe(false);
        expect(persisted.model_question).toBeTruthy();
        expect(Object.keys(persisted.model_question).sort()).toEqual([
            'accepted_at',
            'client_turn_id',
            'conversation_id',
            'model_step_id',
            'provider_call_id',
            'purpose',
            'text',
            'tool_call_id'
        ]);
        const capturedClarificationBody = JSON.parse(capturedClarification.body);
        expect(persisted.model_question.text).toBe(exactQuestion);
        expect(persisted.model_question.model_step_id).not.toBe('');
        expect(persisted.model_question.tool_call_id).not.toBe('');
        expect(persisted.model_question.provider_call_id).not.toBe('');
        expect(persisted.model_question.client_turn_id).toBe(capturedClarificationBody.client_turn_id);
        expect(persisted.model_question.conversation_id).toBe(state.database.conversation.public_id);
        expect(persisted.model_question.purpose).toBe('cart_continuation');
        expect(persisted.model_question.accepted_at).toBeGreaterThan(0);

        const providerBeforeDuplicate = await fakeState(request);
        const duplicateClarification = await replayCaptured(page, capturedClarification);
        expect(duplicateClarification.status).toBe(200);
        expect(duplicateClarification.body.ok).toBe(true);
        expect(duplicateClarification.body.message.text).toBe(exactQuestion);
        expect(duplicateClarification.body.message.outcome).toBe('follow_up');
        const providerAfterDuplicate = await fakeState(request);
        expect(providerAfterDuplicate.calls).toHaveLength(providerBeforeDuplicate.calls.length);

        const providerBeforeRefresh = await fakeState(request);
        const callsBeforeRefresh = providerBeforeRefresh.calls.length;
        await openWidget(page);
        await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'follow_up');
        await expect(await lastAssistant(page)).toHaveText(exactQuestion);
        const providerAfterRefresh = await fakeState(request);
        expect(providerAfterRefresh.calls).toHaveLength(callsBeforeRefresh);

        const capturedPromise = captureNextChat(page);
        const updateResponsePromise = page.waitForResponse(response => (
            response.request().method() === 'POST'
            && response.url().includes('/wp-json/yassin-ai/v1/chat')
            && response.status() === 200
        ));
        await sendMessage(page, '3');
        const captured = await capturedPromise;
        const updateBody = await (await updateResponsePromise).json();
        await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'action_verified');
        expect(updateBody.message.outcome).toBe('action_verified');

        state = await pageState(page);
        expect(state.cart.line_count).toBe(1);
        expect(state.cart.item_count).toBe(3);
        expect(state.cart.items[0].quantity).toBe(3);
        expect(state.database.operations).toHaveLength(2);
        const providerBeforeReplay = await fakeState(request);

        const replay = await replayCaptured(page, captured);
        expect(replay.status).toBe(200);
        expect(replay.body.ok).toBe(true);
        expect(replay.body.message).toEqual(updateBody.message);
        const replayedState = await pageState(page);
        expect(replayedState.cart.item_count).toBe(3);
        expect(replayedState.database.operations).toHaveLength(2);
        const providerAfterReplay = await fakeState(request);
        expect(providerAfterReplay.calls).toHaveLength(providerBeforeReplay.calls.length);
    });

    test('invalid model question fails closed without a server-authored replacement', async ({ page, request }) => {
        await reset(request, 'follow_up_outer_whitespace');
        await openWidget(page);
        await sendMessage(page, 'اطلب توضيحاً غير صالح');

        const message = await lastAssistant(page);
        await expect(message).toHaveAttribute('data-outcome', 'safe_failure');
        await expect(message).not.toHaveText('  ما الذي تبحث عنه؟  ');
        await expect(message).not.toHaveText('ما الذي تبحث عنه؟');

        const state = await pageState(page);
        expect(state.database.operations).toHaveLength(0);
        const assistant = state.database.messages.find(row => row.role === 'assistant');
        expect(assistant).toBeTruthy();
        expect(assistant.outcome).toBe('safe_failure');
        expect(assistant.model_question).toBeNull();
    });
}
