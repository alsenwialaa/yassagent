'use strict';

Object.assign(globalThis, require('../support/widget-harness'));

test('server error message is the single customer-safe display field', () => {
    const { Runtime } = loadRuntime();
    same('Server detail', Runtime.util.safeMessage({ message: ' Server detail ' }));
});

test('money display preserves server-canonical text and restores bidi isolation', () => {
    const { Runtime } = loadRuntime();
    const value = Runtime.util.moneyText('1,250 ر.ي');
    same('\u20681,250 ر.ي\u2069', value);
    ok(!Object.prototype.hasOwnProperty.call(Runtime.util, 'decodeDisplayText'));
});

test('fallback random ID is RFC 4122 version 4', () => {
    const { Runtime } = loadRuntime();
    const id = Runtime.util.randomId();
    ok(/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(id), id);
});

test('turn retry reuses the exact serialized request body', async () => {
    const bodies = [];
    let call = 0;
    const { Runtime } = loadRuntime((url, options) => {
        bodies.push(options.body);
        call += 1;
        return Promise.resolve(call === 1
            ? response(503, { ok: false, code: 'busy', message: 'Busy' })
            : response(200, { ok: true, message: { text: 'Done' } }));
    });
    const api = new Runtime.ApiClient({ chatUrl: '/chat' });
    const envelope = api.envelope({ client_turn_id: 'same-id', message: 'hello' });
    const result = await api.sendTurn(envelope, 'session', 0);
    same(true, result.ok);
    same(2, bodies.length);
    same(bodies[0], bodies[1]);
    same(envelope.body, bodies[0]);
});

test('boot lease contention retries the byte-identical bearer envelope', async () => {
    const bodies = [];
    let calls = 0;
    const runtime = loadRuntime((url, options) => {
        bodies.push(options.body);
        calls += 1;
        return Promise.resolve(calls === 1
            ? response(409, {
                ok: false,
                code: 'boot_in_progress',
                message: 'Wait',
                retry_after: 1
            })
            : response(200, canonicalBoot()));
    }, { manualTimers: true });
    const api = new runtime.Runtime.ApiClient({ bootUrl: '/boot' });
    const request = {
        client_instance_id: '11111111-1111-4111-8111-111111111111',
        browser_continuity_secret: 'A'.repeat(43),
        pending_turn_id: ''
    };
    const pending = api.boot(request);
    for (let index = 0; index < 12; index += 1) await Promise.resolve();
    same(1, bodies.length);
    ok(runtime.fireTimer(1000));
    const result = await pending;
    same(true, result.ok);
    same(2, bodies.length);
    same(bodies[0], bodies[1]);
});

test('pre-turn chat ingress throttling honors retry_after and retains the exact body', async () => {
    const bodies = [];
    let call = 0;
    const runtime = loadRuntime((url, options) => {
        bodies.push(options.body);
        call += 1;
        return Promise.resolve(call === 1
            ? response(429, {
                ok: false,
                code: 'chat_ingress_rate_limited',
                message: 'Wait',
                retry_after: 60
            })
            : response(200, { ok: true }));
    }, { manualTimers: true });
    const api = new runtime.Runtime.ApiClient({ chatUrl: '/chat' });
    const envelope = api.envelope({ client_turn_id: 'same-id', message: 'hello' });
    const pending = api.sendTurn(envelope, 'session', 0, Date.now() + 120000);
    for (let index = 0; index < 12; index += 1) await Promise.resolve();
    same(1, bodies.length);
    ok(runtime.fireTimer(60000), 'server retry_after was not honored');
    const result = await pending;
    same(true, result.ok);
    same(2, bodies.length);
    same(bodies[0], bodies[1]);
});

test('malformed HTTP 200 transport remains retryable with the same body', async () => {
    const bodies = [];
    let call = 0;
    const { Runtime } = loadRuntime((url, options) => {
        bodies.push(options.body);
        call += 1;
        return Promise.resolve(call === 1
            ? response(200, null, '<html>bad</html>')
            : response(200, { ok: true }));
    });
    const api = new Runtime.ApiClient({ chatUrl: '/chat' });
    const envelope = api.envelope({ client_turn_id: 'same-id' });
    const result = await api.sendTurn(envelope, 'session', 0);
    same(true, result.ok);
    same(2, bodies.length);
    same(bodies[0], bodies[1]);
});

test('boot and chat transports abort at their hard client deadlines', async () => {
    const signals = [];
    const runtime = loadRuntime((url, options) => new Promise((resolve, reject) => {
        signals.push(options.signal);
        options.signal.addEventListener('abort', () => {
            const error = new Error('aborted');
            error.name = 'AbortError';
            reject(error);
        });
    }), { manualTimers: true });
    const api = new runtime.Runtime.ApiClient({ bootUrl: '/boot', chatUrl: '/chat' });

    let bootError = null;
    const boot = api.boot({}).catch(error => { bootError = error; });
    ok(runtime.fireTimer(runtime.Runtime.ClientRecoveryPolicy.BOOT_TIMEOUT_MS));
    await boot;
    same('request_timeout', bootError.code);
    same(true, signals[0].aborted);

    let chatError = null;
    const chat = api.request('/chat', 'POST', '{}', {}, runtime.Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS)
        .catch(error => { chatError = error; });
    ok(runtime.fireTimer(runtime.Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS));
    await chat;
    same('request_timeout', chatError.code);
    same(true, signals[1].aborted);
    same(true, api.isRetryable(chatError));
});
