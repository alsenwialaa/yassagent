(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var AREAS = Object.create(null);
    var MEMORY = {
        local: Object.create(null),
        session: Object.create(null)
    };

    function hasStorageShape(storage) {
        return Boolean(storage
            && typeof storage.getItem === 'function'
            && typeof storage.setItem === 'function'
            && typeof storage.removeItem === 'function'
        );
    }

    function browserStorage(name) {
        try {
            return name === 'local' ? window.localStorage : window.sessionStorage;
        } catch (error) {
            return null;
        }
    }

    function ResilientStorageArea(name, candidate, memory) {
        this.name = name === 'session' ? 'session' : 'local';
        this.memory = memory && typeof memory === 'object'
            ? memory
            : Object.create(null);
        this.storage = hasStorageShape(candidate) ? candidate : null;
        this.persistence = this.storage ? 'persistent' : 'memory';
        this.degradedReason = this.storage ? '' : 'storage_unavailable';
    }

    ResilientStorageArea.prototype.degrade = function (reason) {
        this.storage = null;
        this.persistence = 'memory';
        if (!this.degradedReason) {
            this.degradedReason = String(reason || 'storage_unavailable');
        }
    };

    ResilientStorageArea.prototype.mode = function () {
        return this.persistence;
    };

    ResilientStorageArea.prototype.reason = function () {
        return this.degradedReason;
    };

    ResilientStorageArea.prototype.getItem = function (key) {
        var name = String(key || '');
        if (!name) {
            return null;
        }
        if (this.persistence === 'persistent' && this.storage) {
            try {
                var stored = this.storage.getItem(name);
                if (stored === null || stored === undefined) {
                    delete this.memory[name];
                    return null;
                }
                this.memory[name] = String(stored);
                return this.memory[name];
            } catch (error) {
                this.degrade('read_failed');
            }
        }
        return Object.prototype.hasOwnProperty.call(this.memory, name)
            ? this.memory[name]
            : null;
    };

    ResilientStorageArea.prototype.setItem = function (key, value) {
        var name = String(key || '');
        if (!name) {
            return false;
        }
        var exact = String(value);
        this.memory[name] = exact;
        if (this.persistence !== 'persistent' || !this.storage) {
            return true;
        }
        try {
            this.storage.setItem(name, exact);
            var stored = this.storage.getItem(name);
            if (stored === null || String(stored) !== exact) {
                this.degrade('write_verification_failed');
                return true;
            }
            this.memory[name] = String(stored);
            return true;
        } catch (error) {
            this.degrade('write_failed');
            return true;
        }
    };

    ResilientStorageArea.prototype.removeItem = function (key) {
        var name = String(key || '');
        if (!name) {
            return false;
        }
        delete this.memory[name];
        if (this.persistence !== 'persistent' || !this.storage) {
            return true;
        }
        try {
            this.storage.removeItem(name);
            if (this.storage.getItem(name) !== null) {
                this.degrade('remove_verification_failed');
            }
            return true;
        } catch (error) {
            this.degrade('remove_failed');
            return true;
        }
    };

    function area(name) {
        var normalized = name === 'session' ? 'session' : 'local';
        if (!AREAS[normalized]) {
            AREAS[normalized] = new ResilientStorageArea(
                normalized,
                browserStorage(normalized),
                MEMORY[normalized]
            );
        }
        return AREAS[normalized];
    }

    function status() {
        var local = area('local');
        var session = area('session');
        var localPersistent = local.mode() === 'persistent';
        var sessionPersistent = session.mode() === 'persistent';
        return Object.freeze({
            local: local.mode(),
            session: session.mode(),
            local_reason: local.reason(),
            session_reason: session.reason(),
            current_tab_chat: true,
            current_tab_retry: true,
            refresh_continuity: localPersistent,
            unresolved_refresh_recovery: localPersistent && sessionPersistent,
            cross_tab_continuity: localPersistent,
            server_idempotency_authoritative: true
        });
    }

    Runtime.ResilientStorageArea = ResilientStorageArea;
    Runtime.BrowserStorage = Object.freeze({
        area: area,
        status: status
    });
}(window));
