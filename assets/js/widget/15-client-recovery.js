(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;

    function configuredMilliseconds(name, fallback, minimum, maximum) {
        var value = Number((Runtime.config || {})[name]);
        if (!isFinite(value) || Math.floor(value) !== value || value < minimum || value > maximum) {
            return fallback;
        }
        return value;
    }

    var turnTimeoutMs = configuredMilliseconds('turnDeadlineMs', 360000, 60000, 3600000);
    var retryMaxAgeMs = configuredMilliseconds('retryRetentionMs', 900000, 60001, 7200000);
    if (retryMaxAgeMs <= turnTimeoutMs) {
        retryMaxAgeMs = Math.min(7200000, turnTimeoutMs + 540000);
    }

    var POLICY = Object.freeze({
        BOOT_TIMEOUT_MS: 20000,
        TURN_TIMEOUT_MS: turnTimeoutMs,
        RETRY_MAX_ENTRIES: 1,
        RETRY_MAX_BYTES: 3145728,
        RETRY_MAX_AGE_MS: retryMaxAgeMs
    });

    function utf8ByteLength(value) {
        var text = String(value || '');
        var bytes = 0;
        var index;
        var code;
        var next;
        for (index = 0; index < text.length; index += 1) {
            code = text.charCodeAt(index);
            if (code < 128) {
                bytes += 1;
            } else if (code < 2048) {
                bytes += 2;
            } else if (code >= 55296 && code <= 56319 && index + 1 < text.length) {
                next = text.charCodeAt(index + 1);
                if (next >= 56320 && next <= 57343) {
                    bytes += 4;
                    index += 1;
                } else {
                    bytes += 3;
                }
            } else {
                bytes += 3;
            }
        }
        return bytes;
    }

    function RetryEnvelopeStore(onEvict, dependencies) {
        var deps = dependencies && typeof dependencies === 'object' ? dependencies : {};
        this.onEvict = typeof onEvict === 'function' ? onEvict : function () {};
        this.nowMs = typeof deps.nowMs === 'function' ? deps.nowMs : function () { return Date.now(); };
        this.setTimer = typeof deps.setTimeout === 'function'
            ? deps.setTimeout
            : function (callback, delay) { return window.setTimeout(callback, delay); };
        this.clearTimer = typeof deps.clearTimeout === 'function'
            ? deps.clearTimeout
            : function (timer) { window.clearTimeout(timer); };
        this.entries = Object.create(null);
        this.totalBytes = 0;
        this.sequence = 0;
        this.timer = 0;
        this.storageKey = typeof deps.storageKey === 'string' && deps.storageKey
            ? deps.storageKey + ':retry-envelopes'
            : '';
        this.storage = null;
        this.failureReason = '';
        if (this.storageKey) {
            this.storage = deps.storage
                ? new Runtime.ResilientStorageArea('session', deps.storage)
                : Runtime.BrowserStorage.area('session');
        }
        this.restore();
    }

    RetryEnvelopeStore.prototype.ids = function () {
        return Object.keys(this.entries);
    };

    RetryEnvelopeStore.prototype.persistenceMode = function () {
        return this.storageKey && this.storage && typeof this.storage.mode === 'function'
            ? this.storage.mode()
            : 'memory';
    };

    RetryEnvelopeStore.prototype.lastFailureReason = function () {
        return this.failureReason;
    };

    RetryEnvelopeStore.prototype.restore = function () {
        if (!this.storageKey || !this.storage || typeof this.storage.getItem !== 'function') {
            return;
        }
        var raw = this.storage.getItem(this.storageKey);
        var value = null;
        try {
            value = raw ? JSON.parse(raw) : null;
        } catch (error) {
            value = null;
        }
        if (raw === null) {
            return;
        }
        if (!value || value.version !== 1 || !Array.isArray(value.entries)
            || value.entries.length !== 1
        ) {
            // More than one retained body is ambiguous and violates the
            // single-unresolved-turn invariant. Discard the whole container;
            // the bounded pending-turn identity can still be reconciled with
            // the server without replaying an untrusted body.
            this.storage.removeItem(this.storageKey);
            return;
        }
        var stored = value.entries[0];
        var id = stored && typeof stored.id === 'string' ? stored.id : '';
        var body = stored && typeof stored.body === 'string' ? stored.body : '';
        var visibleText = stored && typeof stored.visible_text === 'string'
            ? stored.visible_text
            : '';
        var bytes = utf8ByteLength(body);
        var createdAt = Number(stored && stored.created_at);
        var expiresAt = Number(stored && stored.expires_at);
        var protectedUntil = Number(stored && stored.protected_until);
        var sequence = Number(stored && stored.sequence);
        var now = this.nowMs();
        if (!/^retry-[a-f0-9-]{36}$/.test(id) || !body
            || bytes > POLICY.RETRY_MAX_BYTES
            || !isFinite(createdAt) || Math.floor(createdAt) !== createdAt
            || !isFinite(expiresAt) || Math.floor(expiresAt) !== expiresAt
            || !isFinite(protectedUntil) || Math.floor(protectedUntil) !== protectedUntil
            || !isFinite(sequence) || Math.floor(sequence) !== sequence || sequence < 1
            || createdAt < 1 || createdAt > now
            || expiresAt <= createdAt || expiresAt <= now
            || expiresAt - createdAt > POLICY.RETRY_MAX_AGE_MS
            || protectedUntil < createdAt || protectedUntil > expiresAt
        ) {
            this.storage.removeItem(this.storageKey);
            return;
        }
        this.entries[id] = {
            envelope: Object.freeze({ body: body, visibleText: visibleText.slice(0, 4000) }),
            bytes: bytes,
            createdAt: createdAt,
            expiresAt: expiresAt,
            protectedUntil: protectedUntil,
            sequence: sequence
        };
        this.totalBytes = bytes;
        this.sequence = sequence;
        // Rewrite the record through the canonical serializer so unknown
        // fields and non-canonical values never remain durable authority.
        this.persist();
        this.schedule();
    };

    RetryEnvelopeStore.prototype.persist = function () {
        if (!this.storageKey || !this.storage) {
            return true;
        }
        var self = this;
        var value = {
            version: 1,
            entries: this.ids().map(function (id) {
                var entry = self.entries[id];
                return {
                    id: id,
                    body: entry.envelope.body,
                    visible_text: typeof entry.envelope.visibleText === 'string'
                        ? entry.envelope.visibleText.slice(0, 4000)
                        : '',
                    created_at: entry.createdAt,
                    expires_at: entry.expiresAt,
                    protected_until: entry.protectedUntil,
                    sequence: entry.sequence
                };
            })
        };
        if (value.entries.length === 0) {
            this.storage.removeItem(this.storageKey);
        } else {
            this.storage.setItem(this.storageKey, JSON.stringify(value));
        }
        // Same-tab exact replay always remains available in this store. When
        // the shared storage area degrades, its serialized mirror also remains
        // available to other widget instances in the current document only.
        return true;
    };

    RetryEnvelopeStore.prototype.removeEntry = function (id, evicted) {
        var entry = this.entries[id];
        if (!entry) {
            return;
        }
        delete this.entries[id];
        this.totalBytes = Math.max(0, this.totalBytes - entry.bytes);
        if (Array.isArray(evicted)) {
            evicted.push(id);
        }
    };

    RetryEnvelopeStore.prototype.isProtected = function (entry, now) {
        return Boolean(entry && entry.protectedUntil > now);
    };

    RetryEnvelopeStore.prototype.oldestEvictableId = function () {
        var oldest = '';
        var sequence = Number.MAX_SAFE_INTEGER;
        var now = this.nowMs();
        this.ids().forEach(function (id) {
            var entry = this.entries[id];
            if (entry && !this.isProtected(entry, now) && entry.sequence < sequence) {
                sequence = entry.sequence;
                oldest = id;
            }
        }, this);
        return oldest;
    };

    RetryEnvelopeStore.prototype.publish = function (ids) {
        var unique = [];
        (Array.isArray(ids) ? ids : []).forEach(function (id) {
            if (id && unique.indexOf(id) === -1) {
                unique.push(id);
            }
        });
        if (unique.length > 0) {
            try {
                this.onEvict(unique);
            } catch (error) {
                // Retry retention is a safety boundary. UI notification failure
                // must not restore discarded request bodies.
            }
        }
    };

    RetryEnvelopeStore.prototype.schedule = function () {
        var self = this;
        if (this.timer) {
            this.clearTimer(this.timer);
            this.timer = 0;
        }
        var now = this.nowMs();
        var delay = 0;
        this.ids().forEach(function (id) {
            var entry = self.entries[id];
            var remaining = Math.max(entry.expiresAt, entry.protectedUntil) - now;
            if (delay === 0 || remaining < delay) {
                delay = remaining;
            }
        });
        if (delay === 0) {
            return;
        }
        this.timer = this.setTimer(function () {
            self.timer = 0;
            self.prune();
        }, Math.max(1, Math.min(2147483647, delay)));
    };

    RetryEnvelopeStore.prototype.prune = function () {
        var now = this.nowMs();
        var evicted = [];
        this.ids().forEach(function (id) {
            var entry = this.entries[id];
            if (!entry || (entry.expiresAt <= now && !this.isProtected(entry, now))) {
                this.removeEntry(id, evicted);
            }
        }, this);
        this.schedule();
        if (evicted.length > 0) {
            this.persist();
        }
        this.publish(evicted);
        return evicted;
    };

    RetryEnvelopeStore.prototype.put = function (id, envelope, protectForMs) {
        this.failureReason = '';
        var key = String(id || '');
        var body = envelope && typeof envelope.body === 'string' ? envelope.body : '';
        var bytes = utf8ByteLength(body);
        var protection = Number(protectForMs || 0);
        if (!isFinite(protection) || protection < 0) {
            protection = 0;
        }
        protection = Math.min(POLICY.TURN_TIMEOUT_MS, Math.floor(protection));
        this.prune();
        var evicted = [];
        if (!key || !body) {
            this.failureReason = 'invalid_envelope';
            return false;
        }
        if (bytes > POLICY.RETRY_MAX_BYTES) {
            this.failureReason = 'envelope_too_large';
            return false;
        }
        this.removeEntry(key);
        this.sequence += 1;
        var now = this.nowMs();
        this.entries[key] = {
            envelope: envelope,
            bytes: bytes,
            createdAt: now,
            expiresAt: now + POLICY.RETRY_MAX_AGE_MS,
            protectedUntil: now + protection,
            sequence: this.sequence
        };
        this.totalBytes += bytes;
        while (this.ids().length > POLICY.RETRY_MAX_ENTRIES || this.totalBytes > POLICY.RETRY_MAX_BYTES) {
            var oldest = this.oldestEvictableId();
            if (!oldest) {
                this.removeEntry(key);
                this.failureReason = 'unresolved_turn_active';
                this.schedule();
                this.publish(evicted);
                return false;
            }
            this.removeEntry(oldest, evicted);
        }
        this.schedule();
        this.persist();
        this.publish(evicted);
        return Boolean(this.entries[key]);
    };

    RetryEnvelopeStore.prototype.protect = function (id, protectForMs) {
        this.failureReason = '';
        this.prune();
        var entry = this.entries[String(id || '')];
        var protection = Number(protectForMs || 0);
        if (!entry) {
            this.failureReason = 'retry_envelope_missing';
            return false;
        }
        if (!isFinite(protection) || protection <= 0) {
            this.failureReason = 'invalid_protection';
            return false;
        }
        var now = this.nowMs();
        var requiredUntil = now + Math.min(
            POLICY.TURN_TIMEOUT_MS,
            Math.floor(protection)
        );
        // Retention has one fixed authority: createdAt + RETRY_MAX_AGE_MS.
        // Starting a request that could outlive that deadline would allow its
        // exact replay body to disappear while the server is still executing.
        if (requiredUntil > entry.expiresAt) {
            this.failureReason = 'retry_envelope_expired';
            return false;
        }
        entry.protectedUntil = Math.max(entry.protectedUntil, requiredUntil);
        this.schedule();
        this.persist();
        return true;
    };

    RetryEnvelopeStore.prototype.get = function (id) {
        this.prune();
        var entry = this.entries[String(id || '')];
        return entry ? entry.envelope : null;
    };

    RetryEnvelopeStore.prototype.has = function (id) {
        return this.get(id) !== null;
    };

    RetryEnvelopeStore.prototype.remove = function (id) {
        this.removeEntry(String(id || ''));
        this.schedule();
        this.persist();
    };

    RetryEnvelopeStore.prototype.clear = function () {
        if (this.timer) {
            this.clearTimer(this.timer);
            this.timer = 0;
        }
        this.entries = Object.create(null);
        this.totalBytes = 0;
        this.sequence = 0;
        this.persist();
    };

    Runtime.ClientRecoveryPolicy = POLICY;
    Runtime.RetryEnvelopeStore = RetryEnvelopeStore;
}(window));
