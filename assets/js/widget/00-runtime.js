(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime = window.YSAIWidgetRuntime || {};
    Runtime.config = window.YSAIWidgetConfig || {};
    var serverOffsetSeconds = 0;
    var serverEpochAtSync = 0;
    var monotonicAtSync = 0;

    function text(key, fallback) {
        var dictionary = Runtime.config.text || {};
        return dictionary[key] ? String(dictionary[key]) : String(fallback || '');
    }

    function create(tag, className, content) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined && content !== null) {
            node.textContent = String(content);
        }
        return node;
    }


    function moneyText(value) {
        var normalized = String(value === undefined || value === null ? '' : value)
            .replace(/[‎‏‪-‮⁦-⁩]/g, '')
            .replace(/[  \s]+/g, ' ')
            .trim();
        return normalized ? '⁨' + normalized + '⁩' : '';
    }

    function utf8ByteLength(value) {
        try {
            return encodeURIComponent(String(value)).replace(/%[0-9a-f]{2}/gi, 'x').length;
        } catch (error) {
            return Infinity;
        }
    }

    function safeUrl(value) {
        if (typeof value !== 'string' || value === '' || utf8ByteLength(value) > 4096
            || /[\x00-\x20\x7f\\]/.test(value)
        ) {
            return '';
        }
        try {
            // Keep this lexical boundary aligned with PublicHttpUrl::isSafe().
            // In particular, relative references and strings that a browser
            // would silently repair are not public server URLs.
            var url = new URL(value);
            var hasPort = url.port !== '';
            var port = hasPort ? Number(url.port) : 0;
            return (url.protocol === 'http:' || url.protocol === 'https:')
                && !url.username && !url.password && Boolean(url.hostname)
                && (!hasPort || (isFinite(port) && Math.floor(port) === port && port >= 1 && port <= 65535))
                ? value
                : '';
        } catch (error) {
            return '';
        }
    }

    function codePointLength(value) {
        return Array.from(String(value === undefined || value === null ? '' : value)).length;
    }

    function sliceCodePoints(value, start, end) {
        return Array.from(String(value === undefined || value === null ? '' : value))
            .slice(start, end)
            .join('');
    }

    function randomId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        var index;
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            throw new Error('Secure browser randomness is unavailable.');
        }
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        var hex = [];
        for (index = 0; index < bytes.length; index += 1) {
            hex.push(('0' + bytes[index].toString(16)).slice(-2));
        }
        return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-'
            + hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-'
            + hex.slice(10, 16).join('');
    }

    function now() {
        if (serverEpochAtSync > 0 && window.performance && typeof window.performance.now === 'function') {
            return Math.floor(serverEpochAtSync + ((window.performance.now() - monotonicAtSync) / 1000));
        }
        return Math.floor(Date.now() / 1000) + serverOffsetSeconds;
    }

    function synchronizeTime(serverTime) {
        var value = Number(serverTime);
        if (!isFinite(value) || Math.floor(value) !== value || value < 1577836800 || value > 4102444800) {
            return false;
        }
        serverOffsetSeconds = value - Math.floor(Date.now() / 1000);
        serverEpochAtSync = value;
        monotonicAtSync = window.performance && typeof window.performance.now === 'function'
            ? window.performance.now()
            : 0;
        return true;
    }

    function safeMessage(payload, fallback) {
        if (payload && typeof payload.message === 'string' && payload.message.trim()) {
            return payload.message.trim();
        }
        return text('genericFailure', fallback || 'تعذر إكمال الطلب بأمان.');
    }

    function isRecord(value) {
        return Boolean(value && typeof value === 'object' && !Array.isArray(value));
    }

    function boundedInteger(value, fallback, minimum, maximum) {
        var number = Number(value);
        if (!isFinite(number) || Math.floor(number) !== number || number < minimum || number > maximum) {
            return fallback;
        }
        return number;
    }

    Runtime.util = Object.freeze({
        text: text,
        create: create,
        moneyText: moneyText,
        safeUrl: safeUrl,
        codePointLength: codePointLength,
        sliceCodePoints: sliceCodePoints,
        randomId: randomId,
        now: now,
        synchronizeTime: synchronizeTime,
        safeMessage: safeMessage,
        isRecord: isRecord,
        boundedInteger: boundedInteger
    });
}(window));
