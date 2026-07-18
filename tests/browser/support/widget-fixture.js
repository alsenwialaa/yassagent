'use strict';

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

const bundle = path.resolve(__dirname, '../../../assets/js/widget.js');
const stylesheet = path.resolve(__dirname, '../../../assets/css/widget.css');
const adminStylesheet = path.resolve(__dirname, '../../../assets/css/admin.css');
const adminScript = path.resolve(__dirname, '../../../assets/js/admin.js');
const compressedPixelBomb = path.resolve(__dirname, '../../fixtures/compressed-8000x8000.jpg');
const commonPhoneSource = path.resolve(__dirname, '../../fixtures/phone-4032x3024.jpg');
const origin = 'http://127.0.0.1:41739';
const SESSION_TOKEN = 'eyJ2IjoxfQ.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const emptyCart = { item_count: 0, formatted_total: '$0', cart_url: `${origin}/cart`, checkout_url: `${origin}/checkout` };


function assistantMessage(text, extras = {}) {
    const message = Object.assign({
        id: '11111111-1111-4111-8111-111111111111',
        turn_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        role: 'assistant',
        outcome: 'answer',
        text,
        products: [],
        receipts: [],
        presentation: { image_scope: 'none', images: [], reply_quote: '' },
        created_at: 1700000000
    }, extras);
    return message;
}

function userMessage(text, turnId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', id = '22222222-2222-4222-8222-222222222222') {
    return {
        id,
        turn_id: turnId,
        role: 'user',
        outcome: '',
        text,
        products: [],
        receipts: [],
        presentation: { image_scope: 'none', images: [], reply_quote: '' },
        created_at: 1700000000
    };
}

function pairedMessages(assistantMessages, userTexts = []) {
    return assistantMessages.flatMap((source, index) => {
        const suffix = String(index + 1).padStart(12, '0');
        const turnId = `90000000-0000-4000-8000-${suffix}`;
        const assistant = Object.assign({}, source, {
            id: `91000000-0000-4000-8000-${suffix}`,
            turn_id: turnId
        });
        return [
            userMessage(userTexts[index] || `Previous request ${index + 1}`, turnId, `92000000-0000-4000-8000-${suffix}`),
            assistant
        ];
    });
}

function turnPayload(message, overrides = {}) {
    const committed = overrides.turn_committed !== false;
    const messages = Array.isArray(overrides.messages)
        ? overrides.messages
        : (committed ? [userMessage('Request', message.turn_id), message] : []);
    const credentials = overrides.conversation && typeof overrides.conversation === 'object'
        ? overrides.conversation
        : {};
    return {
        ok: true,
        message,
        turn_committed: committed,
        conversation: {
            id: String(credentials.id || '00000000-0000-4000-8000-000000000001'),
            token: String(credentials.token || 'conversation-token-1234567890'),
            messages
        },
        messages_available: Object.prototype.hasOwnProperty.call(overrides, 'messages_available') ? overrides.messages_available : true,
        messages_notice: Object.prototype.hasOwnProperty.call(overrides, 'messages_notice') ? overrides.messages_notice : '',
        cart: Object.prototype.hasOwnProperty.call(overrides, 'cart') ? overrides.cart : emptyCart,
        cart_available: Object.prototype.hasOwnProperty.call(overrides, 'cart_available') ? overrides.cart_available : true,
        cart_notice: Object.prototype.hasOwnProperty.call(overrides, 'cart_notice') ? overrides.cart_notice : '',
        cart_mutations: Object.prototype.hasOwnProperty.call(overrides, 'cart_mutations')
            ? overrides.cart_mutations
            : { available: true, code: 'available', notice: '' }
    };
}

function turnPayloadForEntry(entry, message, overrides = {}) {
    const request = JSON.parse(entry.body || '{}');
    const turnId = String(request.client_turn_id || message.turn_id);
    const canonicalMessage = Object.assign({}, message, { turn_id: turnId });
    const history = Array.isArray(overrides.history) ? overrides.history : [];
    const messages = Array.isArray(overrides.messages)
        ? overrides.messages
        : history.concat([
            userMessage(String(request.message || 'Selection'), turnId),
            canonicalMessage
        ]);
    return turnPayload(canonicalMessage, Object.assign({}, overrides, { messages }));
}

function bootPayload(overrides = {}) {
    return Object.assign({
        ok: true,
        server_time: Math.floor(Date.now() / 1000),
        session: { token: SESSION_TOKEN },
        conversation: { id: '00000000-0000-4000-8000-000000000001', token: 'conversation-token-1234567890', messages: [] },
        widget: {
            title: 'مساعد المتجر', subtitle: 'مساعدة مباشرة', button_text: 'اسألنا',
            empty_state_hint: 'اكتب ما تبحث عنه.'
        },
        cart: emptyCart,
        cart_available: true,
        cart_notice: '',
        pending_turn: null,
        capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0, cart_mutations: { available: true, code: 'available', notice: '' } },
    }, overrides);
}

async function install(page, routes, withRoot = true, clockSkewSeconds = 0, configOverrides = {}) {
    const calls = [];
    await page.exposeFunction('__ysaiHandleFetch', async request => {
        const url = new URL(request.url);
        const entry = { path: url.pathname, method: request.method, body: request.body || '' };
        calls.push(entry);
        const handler = routes[url.pathname];
        if (!handler) {
            return { status: 404, body: { ok: false } };
        }
        return handler(entry, calls);
    });
    await page.setContent(`<!doctype html><html><body>${withRoot ? '<div id="widget" data-ysai-widget="1"></div>' : ''}</body></html>`);
    await page.addStyleTag({ path: stylesheet });
    await page.evaluate(config => {
        const skew = Number(config.clockSkewSeconds || 0);
        delete config.clockSkewSeconds;
        if (skew !== 0) {
            const actualNow = Date.now.bind(Date);
            Date.now = function () { return actualNow() + (skew * 1000); };
        }
        const localValues = Object.assign(
            Object.create(null),
            config.__testLocalStorageValues && typeof config.__testLocalStorageValues === 'object'
                ? config.__testLocalStorageValues
                : {}
        );
        const sessionValues = Object.assign(
            Object.create(null),
            config.__testSessionStorageValues && typeof config.__testSessionStorageValues === 'object'
                ? config.__testSessionStorageValues
                : {}
        );
        const localStorageMode = String(config.__testLocalStorageMode || 'available');
        const sessionStorageMode = String(config.__testSessionStorageMode || 'available');
        delete config.__testLocalStorageMode;
        delete config.__testSessionStorageMode;
        delete config.__testLocalStorageValues;
        delete config.__testSessionStorageValues;
        window.__ysaiTestStorage = { localValues, sessionValues };
        if (localStorageMode === 'unavailable') {
            Object.defineProperty(window, 'localStorage', {
                configurable: true,
                get() { throw new DOMException('Storage unavailable', 'SecurityError'); }
            });
        } else {
            Object.defineProperty(window, 'localStorage', {
                configurable: true,
                value: {
                    getItem(key) {
                        if (localStorageMode === 'read-failure') {
                            throw new DOMException('Storage read rejected', 'SecurityError');
                        }
                        return Object.prototype.hasOwnProperty.call(localValues, key) ? localValues[key] : null;
                    },
                    setItem(key, value) {
                        if (localStorageMode === 'write-failure') {
                            throw new DOMException('Storage write rejected', 'QuotaExceededError');
                        }
                        localValues[key] = String(value);
                    },
                    removeItem(key) {
                        if (localStorageMode === 'remove-failure') {
                            throw new DOMException('Storage remove rejected', 'SecurityError');
                        }
                        delete localValues[key];
                    }
                }
            });
        }
        if (sessionStorageMode === 'unavailable') {
            Object.defineProperty(window, 'sessionStorage', {
                configurable: true,
                get() { throw new DOMException('Storage unavailable', 'SecurityError'); }
            });
        } else {
            Object.defineProperty(window, 'sessionStorage', {
                configurable: true,
                value: {
                    getItem(key) {
                        if (sessionStorageMode === 'read-failure') {
                            throw new DOMException('Storage read rejected', 'SecurityError');
                        }
                        return Object.prototype.hasOwnProperty.call(sessionValues, key) ? sessionValues[key] : null;
                    },
                    setItem(key, value) {
                        if (sessionStorageMode === 'write-failure') {
                            throw new DOMException('Storage write rejected', 'QuotaExceededError');
                        }
                        sessionValues[key] = String(value);
                    },
                    removeItem(key) {
                        if (sessionStorageMode === 'remove-failure') {
                            throw new DOMException('Storage remove rejected', 'SecurityError');
                        }
                        delete sessionValues[key];
                    }
                }
            });
        }
        window.YSAIWidgetConfig = config;
        window.__xss = 0;
        window.fetch = function (url, options) {
            return window.__ysaiHandleFetch({
                url: String(url),
                method: String((options && options.method) || 'GET'),
                body: options && options.body ? String(options.body) : ''
            }).then(function (result) {
                var status = Number(result.status || 200);
                var body = typeof result.body === 'string' ? result.body : JSON.stringify(result.body);
                return {
                    ok: status >= 200 && status < 300,
                    status: status,
                    text: function () { return Promise.resolve(body); }
                };
            });
        };
    }, Object.assign({
        bootUrl: `${origin}/wp-json/yassin-ai/v1/boot`,
        chatUrl: `${origin}/wp-json/yassin-ai/v1/chat`,
        storageKey: 'ysai_test',
        maxImageBytes: 524288,
        maxSourceImageBytes: 8388608,
        maxSourceImageHeaderBytes: 262144,
        maxSourceImageWidth: 4096,
        maxSourceImageHeight: 4096,
        maxSourceImagePixels: 12582912,
        maxImages: 2,
        siteIconUrl: '',
        clockSkewSeconds,
        text: {
            open: 'اسألنا', close: 'إغلاق المساعد', send: 'إرسال', attach: 'إرفاق صور',
            placeholder: 'اسأل عن المنتجات', loading: 'جارٍ بدء المساعد', thinking: 'جارٍ التحقق', retry: 'أعد المحاولة',
            imageLimit: 'لا يمكن إرفاق المزيد من الصور', imageTooLarge: 'الصورة كبيرة جداً', imageDimensionsTooLarge: 'أبعاد الصورة كبيرة جداً', unsupportedImage: 'صيغة الصورة غير مدعومة',
            empty: 'اكتب رسالة أو أرفق صورة', cart: 'السلة', cartUnavailable: 'تعذر تحديث السلة', checkout: 'إتمام الطلب',
            items: 'منتجات', selected: 'تم الاختيار', imageAttachment: 'صورة مرفقة', imageReading: 'جارٍ تجهيز الصورة',
            imageReadFailure: 'تعذرت قراءة الصورة', remove: 'إزالة', sessionRefreshing: 'جارٍ تحديث الجلسة',
            conversationReset: 'انتهت المحادثة السابقة. ابدأ من جديد.',
            genericFailure: 'تعذر إكمال الطلب', requestTimeout: 'انتهت مهلة الطلب', retryExpired: 'انتهت صلاحية إعادة المحاولة', retryRetentionFailed: 'تعذر الاحتفاظ بالطلب الحالي لإعادة المحاولة الآمنة. لم يتم إرساله.', browserStorageDegraded: 'يمكنك متابعة المحادثة في هذه الصفحة بأمان، لكن الاستمرارية بعد إعادة التحميل أو بين علامات التبويب محدودة لأن تخزين المتصفح غير متاح.', invalidResponse: 'استجابة غير صالحة', requiresOptions: 'يتطلب تحديد الخيارات',
            outOfStock: 'غير متوفر', unavailable: 'المساعد غير متاح'
        }
    }, configOverrides));
    await page.addScriptTag({ path: bundle });
    return calls;
}
async function openReady(page) {
    await page.locator('.ysai-launcher').click();
    await expect(page.locator('.ysai-input')).toBeEnabled();
    await expect(page.locator('.ysai-input')).toBeFocused();
}


module.exports = {
    fs,
    test,
    expect,
    bundle,
    stylesheet,
    adminStylesheet,
    adminScript,
    compressedPixelBomb,
    commonPhoneSource,
    origin,
    SESSION_TOKEN,
    emptyCart,
    assistantMessage,
    userMessage,
    pairedMessages,
    turnPayload,
    turnPayloadForEntry,
    bootPayload,
    install,
    openReady
};
