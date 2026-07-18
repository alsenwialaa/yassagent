'use strict';

const { test, expect } = require('@playwright/test');
const {
    reset, configureScenario, openWidget, sendMessage,
    lastAssistant, pageState, fakeState,
    captureNextChat, replayCaptured
} = require('../helpers/harness');

test('real REST turn commits one canonical user/assistant pair', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة تكامل حقيقية.' });
    await openWidget(page);
    await sendMessage(page, 'hello integration store');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة تكامل حقيقية.');

    const state = await pageState(page);
    expect(state.database.counts.turns).toBe(1);
    expect(state.database.counts.messages).toBe(2);
    expect(state.database.turns[0].status).toBe('complete');
    expect(state.database.operations).toHaveLength(0);
    const model = await fakeState(request);
    expect(model.calls).toHaveLength(1);
});

test('same serialized turn replays without a second model call or mutation', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة آمنة لإعادة التشغيل.' });
    await openWidget(page);
    const chat = captureNextChat(page);
    await sendMessage(page, 'replay this exact turn');
    const captured = await chat;
    await expect(await lastAssistant(page)).toContainText('هذه إجابة آمنة لإعادة التشغيل.');

    const replay = await replayCaptured(page, captured);
    expect(replay.status).toBe(200);
    expect(replay.body.ok).toBe(true);
    expect(replay.body.message.text).toBe('هذه إجابة آمنة لإعادة التشغيل.');

    const state = await pageState(page);
    expect(state.database.counts.turns).toBe(1);
    expect(state.database.counts.messages).toBe(2);
    const model = await fakeState(request);
    expect(model.calls).toHaveLength(1);
});

test('a concurrent duplicate mutation waits behind the request fence and replays one side effect', async ({ page, request }) => {
    await reset(request, 'answer');
    await openWidget(page);
    await configureScenario(request, 'add_simple', {
        query: 'integration coffee',
        transport_delay_ms: 800
    });
    const chat = captureNextChat(page);
    await sendMessage(page, 'أضف Integration Coffee إلى السلة');
    const captured = await chat;
    const concurrent = await replayCaptured(page, captured);
    expect(concurrent.status).toBe(200);
    expect(concurrent.body.ok).toBe(true);
    await expect(await lastAssistant(page)).toHaveAttribute('data-outcome', 'action_verified');

    const finalReplay = await replayCaptured(page, captured);
    expect(finalReplay.status).toBe(200);
    expect(finalReplay.body.ok).toBe(true);
    const state = await pageState(page);
    expect(state.cart.item_count).toBe(1);
    expect(state.database.operations).toHaveLength(1);
    expect(state.database.counts.turns).toBe(1);
    const model = await fakeState(request);
    expect(model.calls.filter(call => call.declaration_names.length === 20)).toHaveLength(2);
    expect(model.calls.filter(call => (
        call.declaration_names.length === 1
        && call.declaration_names[0] === 'verify_current_cart_intent'
    ))).toHaveLength(1);
});

test('successful chat responses include canonical history from prior turns', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة تكامل قانونية.' });
    await openWidget(page);
    await sendMessage(page, 'first canonical request');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة تكامل قانونية.');

    const responsePromise = page.waitForResponse(response => (
        response.request().method() === 'POST'
        && response.url().includes('/wp-json/yassin-ai/v1/chat')
        && response.status() === 200
    ));
    await sendMessage(page, 'second canonical request');
    const response = await responsePromise;
    const body = await response.json();
    expect(body.ok).toBe(true);
    expect(body.turn_committed).toBe(true);
    expect(body.conversation.messages).toHaveLength(4);
    expect(body.conversation.messages.map(message => message.role)).toEqual([
        'user', 'assistant', 'user', 'assistant'
    ]);
    expect(body.conversation.messages[0].text).toBe('first canonical request');
    expect(body.conversation.messages[2].text).toBe('second canonical request');
    expect(body.message).toEqual(body.conversation.messages[3]);
});
test('visible canonical history is exactly the raw model context window', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة متوافقة مع نافذة السياق.' });
    await openWidget(page);

    let thirteenth = null;
    for (let index = 1; index <= 13; index += 1) {
        const responsePromise = page.waitForResponse(response => (
            response.request().method() === 'POST'
            && response.url().includes('/wp-json/yassin-ai/v1/chat')
            && response.status() === 200
        ));
        await sendMessage(page, `window request ${index}`);
        const response = await responsePromise;
        thirteenth = await response.json();
        await expect(page.locator('.ysai-input')).toBeEnabled();
    }

    expect(thirteenth.ok).toBe(true);
    expect(thirteenth.conversation.messages).toHaveLength(24);
    const visibleBeforeNextTurn = thirteenth.conversation.messages.map(message => message.text);
    expect(visibleBeforeNextTurn[0]).toBe('window request 2');
    expect(visibleBeforeNextTurn[22]).toBe('window request 13');

    const responsePromise = page.waitForResponse(response => (
        response.request().method() === 'POST'
        && response.url().includes('/wp-json/yassin-ai/v1/chat')
        && response.status() === 200
    ));
    await sendMessage(page, 'window request 14');
    const fourteenth = await (await responsePromise).json();
    const model = await fakeState(request);
    const contents = model.calls[model.calls.length - 1].payload.contents;
    const modelHistory = contents.slice(0, -1).map(row => row.parts[0].text);

    expect(modelHistory).toEqual(visibleBeforeNextTurn);
    expect(contents[contents.length - 1].parts[0].text).toBe('window request 14');
    expect(fourteenth.conversation.messages).toHaveLength(24);
    expect(fourteenth.conversation.messages[0].text).toBe('window request 3');
    expect(fourteenth.conversation.messages[22].text).toBe('window request 14');
});
