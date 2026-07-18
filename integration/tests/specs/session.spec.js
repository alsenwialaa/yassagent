'use strict';

const { test, expect } = require('@playwright/test');
const {
    reset, configureScenario, pageControl, pageState, openWidget, sendMessage, lastAssistant
} = require('../helpers/harness');

async function storedContinuity(page) {
    return page.evaluate(() => {
        const key = Object.keys(localStorage).find(item => /^ysai_storefront_v1_[a-f0-9]{16}$/.test(item));
        return key ? JSON.parse(localStorage.getItem(key)) : null;
    });
}


test('conversation resume keeps one identity and extends retention atomically', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة استئناف أساسية.' });
    await openWidget(page);
    await sendMessage(page, 'create resumable conversation');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة استئناف أساسية.');
    const before = await pageState(page);
    expect(before.database.conversation.public_id).toMatch(/^[a-f0-9-]{36}$/);

    await page.waitForTimeout(1100);
    await page.reload({ waitUntil: 'domcontentloaded' });
    const launcher = page.locator('.ysai-launcher');
    await expect(launcher).toBeVisible();
    await launcher.click();
    await expect(page.locator('.ysai-input')).toBeEnabled();

    const after = await pageState(page);
    expect(after.database.conversation.public_id).toBe(before.database.conversation.public_id);
    expect(Date.parse(after.database.conversation.updated_at)).toBeGreaterThan(Date.parse(before.database.conversation.updated_at));
    expect(Date.parse(after.database.conversation.expires_at)).toBeGreaterThan(Date.parse(before.database.conversation.expires_at));
    await expect(page.getByText('هذه إجابة استئناف أساسية.')).toBeVisible();
});

test('expired short-lived session token rebases canonical history and replays only the still-pending turn', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة أولية.' });
    await openWidget(page);
    await sendMessage(page, 'first turn');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة أولية.');
    const before = await storedContinuity(page);

    const otherPage = await page.context().newPage();
    await configureScenario(request, 'answer', { text: 'هذه إجابة من علامة تبويب أخرى.' });
    await openWidget(otherPage);
    await sendMessage(otherPage, 'other tab turn');
    await expect(await lastAssistant(otherPage)).toContainText('هذه إجابة من علامة تبويب أخرى.');
    await expect(page.getByText('هذه إجابة من علامة تبويب أخرى.')).toHaveCount(0);

    const expired = await pageControl(page, '/session/expired-token');
    await page.evaluate(token => {
        const app = window.__ysaiAssistantApp;
        app.store.state = Object.assign({}, app.store.getState(), { sessionToken: String(token) });
    }, expired.token);
    await configureScenario(request, 'answer', { text: 'تمت استعادة الإجابة بعد تحديث الجلسة.' });

    await sendMessage(page, 'second turn');
    await expect(await lastAssistant(page)).toContainText('تمت استعادة الإجابة بعد تحديث الجلسة.');
    const after = await storedContinuity(page);
    expect(after.conversation_id).toBe(before.conversation_id);
    await expect(page.getByText('هذه إجابة أولية.')).toBeVisible();
    await expect(page.getByText('هذه إجابة من علامة تبويب أخرى.')).toBeVisible();
    await expect(page.getByText('other tab turn')).toBeVisible();
    await expect(page.getByText('second turn')).toHaveCount(1);
    await otherPage.close();
});

test('expired conversation starts fresh continuity and clears the stale transcript', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'هذه إجابة أولية.' });
    await openWidget(page);
    await sendMessage(page, 'first turn');
    await expect(await lastAssistant(page)).toContainText('هذه إجابة أولية.');
    const before = await storedContinuity(page);
    await pageControl(page, '/conversation/expire', { conversation_id: before.conversation_id });

    await sendMessage(page, 'must be resent');
    await expect(page.locator('.ysai-status-line')).toContainText(/انتهت|expired/i);
    await expect(page.locator('.ysai-input')).toBeEnabled();
    await expect(page.getByText('هذه إجابة أولية.')).toHaveCount(0);
    await expect(page.getByText('first turn')).toHaveCount(0);
    await expect(page.getByText('must be resent')).toHaveCount(0);
    const after = await storedContinuity(page);
    expect(after.conversation_id).not.toBe(before.conversation_id);
});


test('fresh conversation rotates browser admission and continuity credentials', async ({ page, request }) => {
    await reset(request, 'answer', { text: 'جاهز.' });
    const bodies = [];
    page.on('request', candidate => {
        if (candidate.method() === 'POST' && candidate.url().includes('/wp-json/yassin-ai/v1/boot')) {
            bodies.push(JSON.parse(candidate.postData() || '{}'));
        }
    });
    await openWidget(page);
    await page.evaluate(() => window.__ysaiAssistantApp.boot(true));
    await expect.poll(() => bodies.length).toBe(2);
    expect(bodies[0].client_instance_id).toMatch(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/);
    expect(bodies[1].client_instance_id).not.toBe(bodies[0].client_instance_id);
    expect(bodies[0].browser_continuity_secret).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(bodies[1].browser_continuity_secret).not.toBe(bodies[0].browser_continuity_secret);
    expect(Object.keys(bodies[0]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id'
    ]);
    expect(Object.keys(bodies[1]).sort()).toEqual([
        'browser_continuity_secret', 'client_instance_id', 'pending_turn_id'
    ]);
});
