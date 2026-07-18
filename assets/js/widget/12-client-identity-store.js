(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var UUID_V4 = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
    var CONTINUITY_SECRET_SHAPE = /^[A-Za-z0-9_-]{43}$/;
    var BASE64_URL_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    var CONTINUITY_SECRET = {
        test: function (value) {
            var text = String(value || '');
            if (!CONTINUITY_SECRET_SHAPE.test(text)) {
                return false;
            }
            // Thirty-two bytes encode to 43 unpadded base64url characters.
            // The final character carries four data bits, so its two low bits
            // must be zero. This mirrors the server's decode/re-encode proof.
            var trailing = BASE64_URL_ALPHABET.indexOf(text.charAt(42));
            return trailing >= 0 && (trailing & 3) === 0;
        }
    };

    function ClientIdentityStore(storageKey) {
        var base = String(storageKey || '');
        this.storageKey = base ? base + '_client' : '';
        this.localArea = Runtime.BrowserStorage.area('local');
        this.memoryId = '';
    }

    ClientIdentityStore.prototype.persistenceMode = function () {
        return this.storageKey ? this.localArea.mode() : 'memory';
    };

    ClientIdentityStore.prototype.read = function () {
        if (!this.storageKey) {
            return UUID_V4.test(this.memoryId) ? this.memoryId : '';
        }
        var value = String(this.localArea.getItem(this.storageKey) || '').toLowerCase();
        if (!value) {
            return '';
        }
        if (!UUID_V4.test(value)) {
            this.localArea.removeItem(this.storageKey);
            return '';
        }
        return value;
    };

    ClientIdentityStore.prototype.write = function (value) {
        var exact = String(value || '').toLowerCase();
        if (!UUID_V4.test(exact)) {
            throw new Error('تعذر إنشاء هوية بدء آمنة للمتصفح.');
        }
        if (!this.storageKey) {
            this.memoryId = exact;
            return exact;
        }
        this.localArea.setItem(this.storageKey, exact);
        var stored = this.read();
        if (stored !== exact) {
            throw new Error('تعذر إنشاء هوية بدء آمنة للمتصفح.');
        }
        return stored;
    };

    ClientIdentityStore.prototype.id = function () {
        var stored = this.read();
        if (stored) {
            return stored;
        }
        var generated = String(Runtime.util.randomId() || '').toLowerCase();
        return this.write(generated);
    };

    ClientIdentityStore.prototype.rotate = function () {
        if (this.storageKey) {
            this.localArea.removeItem(this.storageKey);
        } else {
            this.memoryId = '';
        }
        return this.id();
    };

    function base64Url(bytes) {
        var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        var output = '';
        var index;
        for (index = 0; index < bytes.length; index += 3) {
            var first = bytes[index];
            var hasSecond = index + 1 < bytes.length;
            var hasThird = index + 2 < bytes.length;
            var second = hasSecond ? bytes[index + 1] : 0;
            var third = hasThird ? bytes[index + 2] : 0;
            output += alphabet.charAt(first >> 2);
            output += alphabet.charAt(((first & 3) << 4) | (second >> 4));
            if (hasSecond) {
                output += alphabet.charAt(((second & 15) << 2) | (third >> 6));
            }
            if (hasThird) {
                output += alphabet.charAt(third & 63);
            }
        }
        return output;
    }

    function normalizeRecord(value) {
        if (!value || typeof value !== 'object'
            || !CONTINUITY_SECRET.test(value.secret)
            || typeof value.established !== 'boolean'
            || (value.previous_secret !== ''
                && !CONTINUITY_SECRET.test(value.previous_secret))
            || (value.previous_secret !== ''
                && value.previous_secret === value.secret)
        ) {
            return null;
        }
        return {
            secret: String(value.secret),
            previous_secret: String(value.previous_secret || ''),
            established: value.established === true
        };
    }

    function sameRecord(left, right) {
        return Boolean(left && right
            && left.secret === right.secret
            && left.previous_secret === right.previous_secret
            && left.established === right.established
        );
    }

    function BrowserContinuitySecretStore(storageKey) {
        var base = String(storageKey || '');
        this.storageKey = base ? base + '_continuity_secret' : '';
        this.localArea = Runtime.BrowserStorage.area('local');
        this.memoryRecord = null;
    }

    BrowserContinuitySecretStore.prototype.persistenceMode = function () {
        return this.storageKey ? this.localArea.mode() : 'memory';
    };

    BrowserContinuitySecretStore.prototype.readRecord = function () {
        if (!this.storageKey) {
            return this.memoryRecord ? Object.assign({}, this.memoryRecord) : null;
        }
        var raw = this.localArea.getItem(this.storageKey);
        var value = null;
        try {
            value = raw ? JSON.parse(raw) : null;
        } catch (error) {
            value = null;
        }
        var normalized = normalizeRecord(value);
        if (raw !== null && !normalized) {
            this.localArea.removeItem(this.storageKey);
        }
        return normalized;
    };

    BrowserContinuitySecretStore.prototype.writeRecord = function (record) {
        var normalized = normalizeRecord(record);
        if (!normalized) {
            throw new Error('تعذر إنشاء بيانات استمرارية آمنة للمتصفح.');
        }
        if (!this.storageKey) {
            this.memoryRecord = normalized;
            return Object.assign({}, normalized);
        }
        this.localArea.setItem(this.storageKey, JSON.stringify(normalized));
        var stored = this.readRecord();
        if (!sameRecord(stored, normalized)) {
            throw new Error('تعذر إنشاء بيانات استمرارية آمنة للمتصفح.');
        }
        return stored;
    };

    BrowserContinuitySecretStore.prototype.generate = function () {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            throw new Error('تعذر إنشاء بيانات استمرارية آمنة للمتصفح.');
        }
        var bytes = new Uint8Array(32);
        window.crypto.getRandomValues(bytes);
        var generated = base64Url(bytes);
        if (!CONTINUITY_SECRET.test(generated)) {
            throw new Error('تعذر إنشاء بيانات استمرارية آمنة للمتصفح.');
        }
        return generated;
    };

    BrowserContinuitySecretStore.prototype.credentials = function () {
        var stored = this.readRecord();
        if (stored) {
            return stored;
        }
        return this.writeRecord({
            secret: this.generate(),
            previous_secret: '',
            established: false
        });
    };

    BrowserContinuitySecretStore.prototype.secret = function () {
        return this.credentials().secret;
    };

    BrowserContinuitySecretStore.prototype.rotate = function () {
        var credentials = this.credentials();
        var current = credentials.secret;
        if (!credentials.established || credentials.previous_secret !== '') {
            return current;
        }
        var next = this.generate();
        while (next === current) {
            next = this.generate();
        }
        return this.writeRecord({
            secret: next,
            previous_secret: current,
            established: false
        }).secret;
    };

    BrowserContinuitySecretStore.prototype.acknowledge = function (secret) {
        var current = this.credentials();
        if (current.secret !== String(secret || '')) {
            return false;
        }
        if (current.previous_secret === '' && current.established) {
            return true;
        }
        this.writeRecord({
            secret: current.secret,
            previous_secret: '',
            established: true
        });
        return true;
    };

    Runtime.ClientIdentityStore = ClientIdentityStore;
    Runtime.BrowserContinuitySecretStore = BrowserContinuitySecretStore;
}(window));
