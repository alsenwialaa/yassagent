'use strict';

const { expect } = require('@playwright/test');

const baseURL = String(process.env.YSAI_TEST_BASE_URL || 'http://wordpress').replace(/\/$/, '');
const fakeURL = String(process.env.YSAI_FAKE_GEMINI_URL || 'http://fake-gemini:8787').replace(/\/$/, '');
const token = String(process.env.YSAI_TEST_CONTROL_TOKEN || 'ysai-integration-control');

async function jsonRequest(context, url, options = {}) {
    const headers = Object.assign({ 'x-ysai-test-token': token }, options.headers || {});
    const response = await context.fetch(url, Object.assign({}, options, { headers }));
    const text = await response.text();
    let body = {};
    try { body = text ? JSON.parse(text) : {}; } catch (error) { body = { raw: text }; }
    if (!response.ok()) {
        throw new Error(`Harness request failed ${response.status()} ${url}: ${text}`);
    }
    return body;
}

async function reset(request, scenario = 'answer', options = {}) {
    await jsonRequest(request, `${fakeURL}/control/reset`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        data: { scenario, options }
    });
    await jsonRequest(request, `${baseURL}/wp-json/ysai-test/v1/reset`, { method: 'POST' });
}

async function configureScenario(request, scenario = 'answer', options = {}) {
    return jsonRequest(request, `${fakeURL}/control/reset`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        data: { scenario, options }
    });
}

async function setFault(request, name) {
    return jsonRequest(request, `${baseURL}/wp-json/ysai-test/v1/fault`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        data: { name: String(name || '') }
    });
}

async function globalState(request) {
    return jsonRequest(request, `${baseURL}/wp-json/ysai-test/v1/state`, { method: 'GET' });
}

async function fakeState(request) {
    return jsonRequest(request, `${fakeURL}/control/state`, { method: 'GET' });
}


async function waitForFakeState(request, predicate, timeoutMs = 10000) {
    const deadline = Date.now() + timeoutMs;
    let state = null;
    while (Date.now() < deadline) {
        state = await fakeState(request);
        if (predicate(state)) {
            return state;
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Timed out waiting for fake Gemini state: ${JSON.stringify(state)}`);
}

async function pageControl(page, path, body) {
    return page.evaluate(async ({ path, body, token }) => {
        const response = await fetch(`/wp-json/ysai-test/v1${path}`, {
            method: body === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: Object.assign(
                { 'X-YSAI-Test-Token': token },
                body === undefined ? {} : { 'Content-Type': 'application/json' }
            ),
            body: body === undefined ? undefined : JSON.stringify(body)
        });
        const text = await response.text();
        const payload = text ? JSON.parse(text) : {};
        if (!response.ok) {
            throw new Error(`Control request failed ${response.status}: ${text}`);
        }
        return payload;
    }, { path, body, token });
}

async function pageState(page) {
    return pageControl(page, '/state');
}

async function openWidget(page) {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const launcher = page.locator('.ysai-launcher');
    await expect(launcher).toBeVisible();
    await launcher.click();
    await expect(page.locator('.ysai-input')).toBeEnabled();
}

async function sendMessage(page, text) {
    const input = page.locator('.ysai-input');
    await input.fill(text);
    await page.locator('.ysai-send').click();
}

async function lastAssistant(page) {
    return page.locator('.ysai-message[data-role="assistant"]').last();
}

async function captureNextChat(page) {
    const request = await page.waitForRequest(candidate => (
        candidate.method() === 'POST' && candidate.url().includes('/wp-json/yassin-ai/v1/chat')
    ));
    const headers = await request.allHeaders();
    return {
        url: request.url(),
        body: request.postData() || '',
        session: String(headers['x-ysai-session'] || '')
    };
}

async function replayCaptured(page, captured) {
    return page.evaluate(async input => {
        const response = await fetch(input.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-YSAI-Session': input.session
            },
            body: input.body
        });
        return { status: response.status, body: await response.json() };
    }, captured);
}

module.exports = {
    baseURL,
    reset,
    configureScenario,
    setFault,
    globalState,
    fakeState,
    waitForFakeState,
    pageControl,
    pageState,
    openWidget,
    sendMessage,
    lastAssistant,
    captureNextChat,
    replayCaptured
};
