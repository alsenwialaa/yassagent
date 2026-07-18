(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var UUID_V4 = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
    var TOKEN = /^[A-Za-z0-9_-]{24,180}$/;

    function ContinuityStore(storageKey) {
        this.storageKey = String(storageKey || '');
        this.observedRevision = null;
        this.localArea = Runtime.BrowserStorage.area('local');
        this.sessionArea = Runtime.BrowserStorage.area('session');
        this.pendingKey = this.storageKey ? this.storageKey + ':pending-turn' : '';
        this.pendingMemory = null;
        this.bootLeaseKey = this.storageKey ? this.storageKey + ':boot-lease' : '';
    }

    ContinuityStore.prototype.persistenceMode = function () {
        return this.storageKey ? this.localArea.mode() : 'memory';
    };

    ContinuityStore.prototype.readRecord = function () {
        if (!this.storageKey) {
            return null;
        }
        var raw = this.localArea.getItem(this.storageKey);
        var parsed = null;
        try {
            parsed = raw ? JSON.parse(raw) : null;
        } catch (error) {
            parsed = null;
        }
        if (!Runtime.util.isRecord(parsed)) {
            if (raw !== null) {
                this.localArea.removeItem(this.storageKey);
            }
            return null;
        }
        var id = typeof parsed.conversation_id === 'string' ? parsed.conversation_id : '';
        var token = typeof parsed.conversation_token === 'string' ? parsed.conversation_token : '';
        var revision = typeof parsed.revision === 'string'
            ? parsed.revision.toLowerCase()
            : '';
        if (!UUID_V4.test(revision)
            || ((id === '' || token === '') && (id !== '' || token !== ''))
            || (id !== '' && (!UUID_V4.test(id.toLowerCase()) || !TOKEN.test(token)))
        ) {
            this.localArea.removeItem(this.storageKey);
            return null;
        }
        return {
            conversation_id: id.toLowerCase(),
            conversation_token: token,
            revision: revision
        };
    };

    ContinuityStore.prototype.read = function () {
        var record = this.readRecord();
        if (!record) {
            return {};
        }
        this.observedRevision = record.revision;
        return record.conversation_id ? {
            conversation_id: record.conversation_id,
            conversation_token: record.conversation_token
        } : {};
    };

    ContinuityStore.prototype.write = function (conversation) {
        var id = conversation && typeof conversation.id === 'string' ? conversation.id.toLowerCase() : '';
        var token = conversation && typeof conversation.token === 'string' ? conversation.token : '';
        if (!this.storageKey || !UUID_V4.test(id) || !TOKEN.test(token)) {
            return false;
        }
        var current = this.readRecord();
        if (current && current.conversation_id === id
            && current.conversation_token === token
        ) {
            this.observedRevision = current.revision;
            return true;
        }
        var currentRevision = current ? current.revision : '';
        var expectedRevision = this.observedRevision === null
            ? ''
            : this.observedRevision;
        if (currentRevision !== expectedRevision) {
            // Compare-and-set continuity fencing prevents a response that
            // started before another tab's reset/replacement from republishing
            // stale public conversation credentials.
            return false;
        }
        var revision = Runtime.util.randomId().toLowerCase();
        if (!UUID_V4.test(revision)) {
            return false;
        }
        this.localArea.setItem(this.storageKey, JSON.stringify({
            conversation_id: id,
            conversation_token: token,
            revision: revision
        }));
        current = this.readRecord();
        if (!current || current.conversation_id !== id
            || current.conversation_token !== token
            || current.revision !== revision
        ) {
            return false;
        }
        this.observedRevision = revision;
        return true;
    };

    ContinuityStore.prototype.clear = function () {
        if (!this.storageKey) {
            this.observedRevision = null;
            return;
        }
        var revision = Runtime.util.randomId().toLowerCase();
        if (!UUID_V4.test(revision)) {
            return;
        }
        // Keep a revisioned tombstone. Removing the key would make the
        // pre-reset and post-reset empty states indistinguishable to a stale
        // in-flight tab. The same fence is retained in current-document memory when
        // durable browser storage is unavailable.
        this.localArea.setItem(this.storageKey, JSON.stringify({
            conversation_id: '',
            conversation_token: '',
            revision: revision
        }));
        var current = this.readRecord();
        if (current && current.revision === revision
            && current.conversation_id === ''
            && current.conversation_token === ''
        ) {
            this.observedRevision = revision;
        }
    };

    ContinuityStore.prototype.normalizePending = function (value) {
        var turnId = value && typeof value.turn_id === 'string'
            ? value.turn_id.toLowerCase()
            : '';
        var conversationId = value && typeof value.conversation_id === 'string'
            ? value.conversation_id.toLowerCase()
            : '';
        var retryId = value && typeof value.retry_id === 'string' ? value.retry_id : '';
        var startedAt = Number(value && value.started_at_ms);
        var guardUntil = Number(value && value.guard_until_ms);
        if (!UUID_V4.test(turnId) || !UUID_V4.test(conversationId)
            || !/^retry-[a-f0-9-]{36}$/.test(retryId)
            || !isFinite(startedAt) || Math.floor(startedAt) !== startedAt || startedAt < 1
            || !isFinite(guardUntil) || Math.floor(guardUntil) !== guardUntil
            || guardUntil <= startedAt || guardUntil - startedAt > 7200000
        ) {
            return null;
        }
        return {
            turn_id: turnId,
            conversation_id: conversationId,
            retry_id: retryId,
            started_at_ms: startedAt,
            guard_until_ms: guardUntil
        };
    };

    ContinuityStore.prototype.pendingPersistenceMode = function () {
        return this.pendingKey ? this.sessionArea.mode() : 'memory';
    };

    ContinuityStore.prototype.readPending = function () {
        if (!this.pendingKey) {
            return this.pendingMemory ? Object.assign({}, this.pendingMemory) : null;
        }
        var raw = this.sessionArea.getItem(this.pendingKey);
        var parsed = null;
        try {
            parsed = raw ? JSON.parse(raw) : null;
        } catch (error) {
            parsed = null;
        }
        var stored = this.normalizePending(parsed);
        if (raw !== null && !stored) {
            this.sessionArea.removeItem(this.pendingKey);
        }
        this.pendingMemory = stored;
        return stored ? Object.assign({}, stored) : null;
    };

    ContinuityStore.prototype.writePending = function (pending) {
        var value = this.normalizePending(pending);
        if (!value) {
            return false;
        }
        // The exact body lives in RetryEnvelopeStore; this per-tab marker keeps
        // only the bounded identity required for reconciliation.
        this.pendingMemory = value;
        if (!this.pendingKey) {
            return true;
        }
        this.sessionArea.setItem(this.pendingKey, JSON.stringify(value));
        var stored = this.readPending();
        return Boolean(stored
            && stored.turn_id === value.turn_id
            && stored.conversation_id === value.conversation_id
            && stored.retry_id === value.retry_id
        );
    };

    ContinuityStore.prototype.clearPending = function (turnId) {
        var expected = String(turnId || '').toLowerCase();
        var current = this.readPending();
        if (expected && current && current.turn_id !== expected) {
            return;
        }
        this.pendingMemory = null;
        if (this.pendingKey) {
            this.sessionArea.removeItem(this.pendingKey);
        }
    };

    ContinuityStore.prototype.tryAcquireBootLease = function (owner, ttlMs) {
        if (!this.bootLeaseKey) {
            return true;
        }
        var identity = String(owner || '');
        var now = Date.now();
        var lifetime = Math.max(1000, Math.min(30000, Math.floor(Number(ttlMs || 20000))));
        var raw = this.localArea.getItem(this.bootLeaseKey);
        var current = null;
        try {
            current = raw ? JSON.parse(raw) : null;
        } catch (error) {
            current = null;
        }
        if (current && typeof current.owner === 'string'
            && current.owner !== identity
            && Number(current.expires_at_ms || 0) > now
        ) {
            return false;
        }
        this.localArea.setItem(this.bootLeaseKey, JSON.stringify({
            owner: identity,
            expires_at_ms: now + lifetime
        }));
        raw = this.localArea.getItem(this.bootLeaseKey);
        try {
            current = raw ? JSON.parse(raw) : null;
        } catch (error) {
            current = null;
        }
        return Boolean(current && current.owner === identity);
    };

    ContinuityStore.prototype.releaseBootLease = function (owner) {
        if (!this.bootLeaseKey) {
            return;
        }
        var raw = this.localArea.getItem(this.bootLeaseKey);
        var current = null;
        try {
            current = raw ? JSON.parse(raw) : null;
        } catch (error) {
            current = null;
        }
        if (current && current.owner === String(owner || '')) {
            this.localArea.removeItem(this.bootLeaseKey);
        }
    };

    Runtime.ContinuityStore = ContinuityStore;
}(window));
