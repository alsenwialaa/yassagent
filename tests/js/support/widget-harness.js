'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

const SESSION_TOKEN = 'eyJ2IjoxfQ.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

let tests = [];
let assertions = 0;
function test(name, fn) { tests.push({ name, fn }); }
function ok(value, message = 'assertion failed') { assertions += 1; if (!value) throw new Error(message); }
function same(expected, actual, message = '') { assertions += 1; if (expected !== actual) throw new Error(message || `expected ${expected}, got ${actual}`); }


function writeUint32be(bytes, offset, value) {
    bytes[offset] = (value >>> 24) & 255;
    bytes[offset + 1] = (value >>> 16) & 255;
    bytes[offset + 2] = (value >>> 8) & 255;
    bytes[offset + 3] = value & 255;
}

function pngHeader(width, height) {
    const bytes = new Uint8Array(24);
    bytes.set([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a], 0);
    writeUint32be(bytes, 8, 13);
    bytes.set([0x49, 0x48, 0x44, 0x52], 12);
    writeUint32be(bytes, 16, width);
    writeUint32be(bytes, 20, height);
    return bytes;
}

function jpegHeader(width, height) {
    return new Uint8Array([
        0xff, 0xd8,
        0xff, 0xc0, 0x00, 0x11, 0x08,
        (height >>> 8) & 255, height & 255,
        (width >>> 8) & 255, width & 255,
        0x03, 0x01, 0x11, 0x00, 0x02, 0x11, 0x00, 0x03, 0x11, 0x00,
        0xff, 0xd9
    ]);
}

function webpVp8xHeader(width, height) {
    const bytes = new Uint8Array(30);
    bytes.set([0x52, 0x49, 0x46, 0x46], 0);
    const riffSize = 22;
    bytes.set([riffSize & 255, (riffSize >>> 8) & 255, (riffSize >>> 16) & 255, (riffSize >>> 24) & 255], 4);
    bytes.set([0x57, 0x45, 0x42, 0x50, 0x56, 0x50, 0x38, 0x58], 8);
    bytes.set([10, 0, 0, 0], 16);
    const w = width - 1;
    const h = height - 1;
    bytes.set([w & 255, (w >>> 8) & 255, (w >>> 16) & 255], 24);
    bytes.set([h & 255, (h >>> 8) & 255, (h >>> 16) & 255], 27);
    return bytes;
}

function fakeImageFile(mimeType, width, height, dataUrl, extras = {}) {
    const header = mimeType === 'image/jpeg'
        ? jpegHeader(width, height)
        : (mimeType === 'image/webp' ? webpVp8xHeader(width, height) : pngHeader(width, height));
    const file = Object.assign({
        name: `sample.${mimeType.split('/')[1]}`,
        type: mimeType,
        size: Math.max(header.length, 10),
        result: dataUrl,
        dataUrlReads: 0,
        slice(start, end) {
            this.sliceStart = start;
            this.sliceEnd = end;
            const copy = header.slice();
            return { arrayBufferResult: copy.buffer, failHeader: Boolean(this.failHeader) };
        }
    }, extras);
    return file;
}

function response(status, payload, rawOverride) {
    return {
        ok: status >= 200 && status < 300,
        status,
        text: () => Promise.resolve(rawOverride !== undefined ? rawOverride : JSON.stringify(payload))
    };
}

function canonicalCart() {
    return {
        item_count: 0,
        formatted_total: '$0',
        cart_url: 'https://example.test/cart',
        checkout_url: 'https://example.test/checkout'
    };
}

function canonicalMessage(text = 'Answer', extras = {}) {
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

function canonicalUserMessage(text = 'Request', turnId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', id = '22222222-2222-4222-8222-222222222223') {
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

function canonicalTurnResponse(message, extras = {}) {
    const committed = extras.turn_committed !== false;
    const messages = Array.isArray(extras.messages)
        ? extras.messages
        : (committed ? [canonicalUserMessage('Request', message.turn_id), message] : []);
    const credentials = extras.conversation && typeof extras.conversation === 'object'
        ? extras.conversation
        : {};
    return {
        ok: true,
        message,
        turn_committed: committed,
        conversation: {
            id: credentials.id || '22222222-2222-4222-8222-222222222222',
            token: credentials.token || 'conversation-token-1234567890',
            messages
        },
        messages_available: Object.prototype.hasOwnProperty.call(extras, 'messages_available') ? extras.messages_available : true,
        messages_notice: Object.prototype.hasOwnProperty.call(extras, 'messages_notice') ? extras.messages_notice : '',
        cart: Object.prototype.hasOwnProperty.call(extras, 'cart') ? extras.cart : canonicalCart(),
        cart_available: Object.prototype.hasOwnProperty.call(extras, 'cart_available') ? extras.cart_available : true,
        cart_notice: Object.prototype.hasOwnProperty.call(extras, 'cart_notice') ? extras.cart_notice : '',
        cart_mutations: Object.prototype.hasOwnProperty.call(extras, 'cart_mutations')
            ? extras.cart_mutations
            : { available: true, code: 'available', notice: '' }
    };
}

function turnSuccessAction(message, extras = {}) {
    const committed = extras.turnCommitted !== false;
    const pending = Object.prototype.hasOwnProperty.call(extras, 'pendingUserMessage')
        ? extras.pendingUserMessage
        : canonicalUserMessage('Request', message.turn_id);
    return {
        type: 'TURN_SUCCESS',
        message,
        turnCommitted: committed,
        conversation: extras.conversation || {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890'
        },
        messages: Array.isArray(extras.messages)
            ? extras.messages
            : (committed ? [pending, message] : []),
        messagesAvailable: Object.prototype.hasOwnProperty.call(extras, 'messagesAvailable') ? extras.messagesAvailable : true,
        messagesNotice: extras.messagesNotice || '',
        pendingUserMessage: pending,
        cartAvailable: Object.prototype.hasOwnProperty.call(extras, 'cartAvailable') ? extras.cartAvailable : true,
        cartNotice: extras.cartNotice || '',
        cart: Object.prototype.hasOwnProperty.call(extras, 'cart') ? extras.cart : canonicalCart(),
        cartMutations: Object.prototype.hasOwnProperty.call(extras, 'cartMutations')
            ? extras.cartMutations
            : { available: true, code: 'available', notice: '' }
    };
}

function canonicalBoot(extras = {}) {
    return Object.assign({
        ok: true,
        server_time: Math.floor(Date.now() / 1000),
        session: { token: SESSION_TOKEN },
        conversation: {
            id: '22222222-2222-4222-8222-222222222222',
            token: 'conversation-token-1234567890',
            messages: []
        },
        widget: {
            title: 'مساعد المتجر',
            subtitle: '',
            button_text: 'اسأل',
            empty_state_hint: 'اكتب ما تبحث عنه.',
        },
        cart: canonicalCart(),
        cart_available: true,
        cart_notice: '',
        pending_turn: null,
        capabilities: { chat_ready: true, images: false, max_images: 0, max_image_bytes: 0, cart_mutations: { available: true, code: 'available', notice: '' } }
    }, extras);
}

function canonicalExportPage(extras = {}) {
    return {
        ok: true,
        export: Object.assign({
            schema: 1,
            conversation_id: '22222222-2222-4222-8222-222222222222',
            created_at: 1700000000,
            updated_at: 1700000001,
            expires_at: 1700600000,
            state: {},
            messages: [],
            verified_cart_receipts: [],
            turns: [],
            cart_operations: [],
            cart_operation_steps: [],
            cart_step_attempts: [],
            next_cursor: null,
            complete: true
        }, extras)
    };
}

function loadRuntime(fetchImpl, options = {}) {
    const storage = options.localStorageData || Object.create(null);
    const sessionStorage = options.sessionStorageData || Object.create(null);
    const documentListeners = Object.create(null);
    const document = {
        readyState: 'loading',
        addEventListener: (type, listener) => {
            if (!documentListeners[type]) documentListeners[type] = [];
            documentListeners[type].push(listener);
        },
        removeEventListener: (type, listener) => {
            documentListeners[type] = (documentListeners[type] || [])
                .filter(candidate => candidate !== listener);
        },
        querySelectorAll: () => [],
        createElement: tag => {
            const node = {
                tagName: tag,
                childNodes: [],
                appendChild(child) { this.childNodes.push(child); return child; },
                setAttribute() {},
                addEventListener() {},
                textContent: '', className: '', hidden: false
            };
            if (tag === 'canvas') {
                node.getContext = () => ({ drawImage() {}, clearRect() {} });
                node.toDataURL = mimeType => `data:${mimeType};base64,QUJD`;
            }
            return node;
        }
    };
    const timers = [];
    let timerSequence = 0;
    let randomSequence = Number(options.randomSeed || 0);
    class FakeAbortSignal {
        constructor() { this.aborted = false; this.listeners = []; }
        addEventListener(type, listener) {
            if (type === 'abort' && typeof listener === 'function') this.listeners.push(listener);
        }
        removeEventListener(type, listener) {
            if (type === 'abort') this.listeners = this.listeners.filter(item => item !== listener);
        }
    }
    class FakeAbortController {
        constructor() { this.signal = new FakeAbortSignal(); }
        abort() {
            if (this.signal.aborted) return;
            this.signal.aborted = true;
            this.signal.listeners.slice().forEach(listener => listener());
        }
    }
    class FakeImage {
        constructor() { this.naturalWidth = 1; this.naturalHeight = 1; this._src = ''; }
        set src(value) {
            this._src = String(value || '');
            if (this._src && typeof this.onload === 'function') this.onload();
        }
        get src() { return this._src; }
    }
    const window = {
        YSAIWidgetConfig: Object.assign(
            { text: { genericFailure: 'Generic failure' } },
            options.config && typeof options.config === 'object' ? options.config : {}
        ),
        location: { href: 'https://example.test/shop' },
        localStorage: {
            getItem: key => {
                if (options.localStorageFailure || options.localStorageReadFailure) {
                    throw new Error('local storage read unavailable');
                }
                return Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null;
            },
            setItem: (key, value) => {
                if (options.localStorageFailure || options.localStorageWriteFailure) {
                    throw new Error('local storage write unavailable');
                }
                storage[key] = String(value);
            },
            removeItem: key => {
                if (options.localStorageFailure || options.localStorageRemoveFailure) {
                    throw new Error('local storage remove unavailable');
                }
                delete storage[key];
            }
        },
        sessionStorage: {
            getItem: key => {
                if (options.sessionStorageFailure || options.sessionStorageReadFailure) {
                    throw new Error('session storage read unavailable');
                }
                return Object.prototype.hasOwnProperty.call(sessionStorage, key) ? sessionStorage[key] : null;
            },
            setItem: (key, value) => {
                if (options.sessionStorageFailure || options.sessionStorageWriteFailure) {
                    throw new Error('session storage write rejected');
                }
                sessionStorage[key] = String(value);
            },
            removeItem: key => {
                if (options.sessionStorageFailure || options.sessionStorageRemoveFailure
                    || options.sessionStorageWriteFailure
                ) {
                    throw new Error('session storage remove rejected');
                }
                delete sessionStorage[key];
            }
        },
        crypto: {
            getRandomValues(bytes) {
                randomSequence += 1;
                for (let i = 0; i < bytes.length; i += 1) bytes[i] = (i + randomSequence) & 255;
                return bytes;
            }
        },
        AbortController: FakeAbortController,
        Image: FakeImage,
        setTimeout(fn, delay) {
            timerSequence += 1;
            const timer = { id: timerSequence, fn, delay: Number(delay || 0), cleared: false };
            timers.push(timer);
            if (options.manualTimers !== true && timer.delay <= 3000) fn();
            return timer.id;
        },
        clearTimeout(id) {
            const timer = timers.find(item => item.id === id);
            if (timer) timer.cleared = true;
        },
        fetch: fetchImpl || (() => Promise.reject(new Error('not configured')))
    };
    if (options.localStorageUnavailable) {
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get() { throw new Error('local storage unavailable'); }
        });
    }
    if (options.sessionStorageUnavailable) {
        Object.defineProperty(window, 'sessionStorage', {
            configurable: true,
            get() { throw new Error('session storage unavailable'); }
        });
    }
    if (typeof options.createImageBitmap === 'function') {
        window.createImageBitmap = options.createImageBitmap;
    }
    class Reader {
        readAsArrayBuffer(file) {
            if (file) file.reader = this;
            if (file && file.deferRead) return;
            this.result = file && file.arrayBufferResult ? file.arrayBufferResult : new ArrayBuffer(0);
            if (file && (file.fail || file.failHeader)) {
                if (this.onerror) this.onerror(new Error('header read failed'));
            } else if (this.onload) {
                this.onload();
            }
        }
        readAsDataURL(file) {
            if (file) file.reader = this;
            if (file && file.deferRead) return;
            if (file) file.dataUrlReads = Number(file.dataUrlReads || 0) + 1;
            this.result = file && file.result ? file.result : '';
            if (file && (file.fail || file.failData)) {
                if (this.onerror) this.onerror(new Error('data read failed'));
            } else if (this.onload) {
                this.onload();
            }
        }
        abort() { this.aborted = true; }
    }
    const context = {
        window, document, URL, Uint8Array, ArrayBuffer, Math, Date, JSON, Object, Array,
        String, Number, Boolean, Promise, Error, FileReader: Reader, console
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../../assets/js/widget.js'), 'utf8'), context);
    return {
        Runtime: window.YSAIWidgetRuntime,
        window,
        document,
        documentListeners,
        storage,
        sessionStorage,
        timers,
        fireTimer(delay) {
            const timer = timers.find(item => !item.cleared && (delay === undefined || item.delay === delay));
            if (!timer) return false;
            timer.cleared = true;
            timer.fn();
            return true;
        }
    };
}

async function run() {
    let failures = 0;
    for (let i = 0; i < tests.length; i += 1) {
        try { await tests[i].fn(); console.log(`ok ${i + 1} - ${tests[i].name}`); }
        catch (error) { failures += 1; console.log(`not ok ${i + 1} - ${tests[i].name}`); console.log(`  ${error.stack || error}`); }
    }
    console.log(`\n${tests.length} tests, ${assertions} assertions, ${failures} failures`);
    process.exitCode = failures === 0 ? 0 : 1;
}

module.exports = {
    fs,
    path,
    SESSION_TOKEN,
    test,
    ok,
    same,
    writeUint32be,
    pngHeader,
    jpegHeader,
    webpVp8xHeader,
    fakeImageFile,
    response,
    canonicalCart,
    canonicalMessage,
    canonicalUserMessage,
    canonicalTurnResponse,
    turnSuccessAction,
    canonicalBoot,
    canonicalExportPage,
    loadRuntime,
    run
};
