(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var util = Runtime.util;
    var policy = Runtime.ClientRecoveryPolicy;
    var Contract = Runtime.publicContract;
    if (!Contract || Contract.contractVersion !== 3) {
        throw new Error('The generated public contract is missing or incompatible.');
    }
    var ERROR_CODE = new RegExp(Contract.patterns.code);

    function ApiError(message, code, status, retryAfter) {
        this.name = 'ApiError';
        this.message = String(message || util.text('genericFailure', 'تعذر إكمال الطلب بأمان.'));
        this.code = String(code || 'request_failed');
        this.status = Number(status || 0);
        this.retryAfter = Number(retryAfter || 0);
        if (Error.captureStackTrace) {
            Error.captureStackTrace(this, ApiError);
        }
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    function ApiClient(config) {
        this.config = config || {};
    }

    function validErrorEnvelope(payload) {
        if (!util.isRecord(payload)) {
            return false;
        }
        var expected = Contract.required.error_response.slice();
        if (Object.prototype.hasOwnProperty.call(payload, 'retry_after')) {
            expected.push('retry_after');
        }
        var keys = Object.keys(payload).sort();
        expected.sort();
        if (keys.length !== expected.length || keys.some(function (key, index) {
            return key !== expected[index];
        })) {
            return false;
        }
        if (payload.ok !== false
            || typeof payload.code !== 'string'
            || !ERROR_CODE.test(payload.code)
            || typeof payload.message !== 'string'
            || !payload.message.trim()
            || util.codePointLength(payload.message) > Contract.limits.errorMessageMaxChars
        ) {
            return false;
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'retry_after')) {
            return typeof payload.retry_after === 'number'
                && isFinite(payload.retry_after)
                && Math.floor(payload.retry_after) === payload.retry_after
                && payload.retry_after >= 1
                && payload.retry_after <= Contract.limits.retryAfterMax;
        }
        return true;
    }

    ApiClient.prototype.envelope = function (payload) {
        return Object.freeze({ body: JSON.stringify(payload) });
    };

    ApiClient.prototype.timeoutError = function () {
        return new ApiError(
            util.text(
                'requestTimeout',
                'استغرق الطلب وقتاً أطول من الحد الآمن. أعد المحاولة بنفس الطلب.'
            ),
            'request_timeout',
            0,
            0
        );
    };

    ApiClient.prototype.boot = function (payload, serializedBody, deadlineAt) {
        var self = this;
        var body = typeof serializedBody === 'string'
            ? serializedBody
            : JSON.stringify(payload || {});
        var deadline = Number(deadlineAt || 0);
        if (!isFinite(deadline) || deadline <= 0) {
            deadline = Date.now() + policy.BOOT_TIMEOUT_MS;
        }
        var remaining = deadline - Date.now();
        if (remaining <= 0) {
            return Promise.reject(this.timeoutError());
        }
        return this.post(this.config.bootUrl, body, {}, remaining).catch(function (error) {
            if (!error || error.status !== 409 || error.code !== 'boot_in_progress') {
                throw error;
            }
            var delay = error.retryAfter > 0 ? error.retryAfter * 1000 : 250;
            if (Date.now() + delay >= deadline) {
                throw error;
            }
            return new Promise(function (resolve) {
                window.setTimeout(resolve, delay);
            }).then(function () {
                // Contention retries preserve the byte-identical browser bearer
                // credential and admission identity under one client deadline.
                return self.boot(payload, body, deadline);
            });
        });
    };

    ApiClient.prototype.sendTurn = function (envelope, sessionToken, attempt, deadlineAt) {
        var self = this;
        var currentAttempt = Number(attempt || 0);
        var deadline = Number(deadlineAt || 0);
        if (!isFinite(deadline) || deadline <= 0) {
            deadline = Date.now() + policy.TURN_TIMEOUT_MS;
        }
        var remaining = deadline - Date.now();
        if (remaining <= 0) {
            return Promise.reject(this.timeoutError());
        }
        return this.post(this.config.chatUrl, envelope.body, {
            'X-YSAI-Session': String(sessionToken || '')
        }, remaining).catch(function (error) {
            if (!self.isRetryable(error) || currentAttempt >= 2) {
                throw error;
            }
            var delay = error.retryAfter > 0
                ? error.retryAfter * 1000
                : 300 * (currentAttempt + 1);
            if (Date.now() + delay >= deadline) {
                throw error;
            }
            return new Promise(function (resolve) {
                window.setTimeout(resolve, delay);
            }).then(function () {
                // The exact serialized body and client turn ID are reused, and
                // every automatic attempt consumes one shared client deadline.
                return self.sendTurn(envelope, sessionToken, currentAttempt + 1, deadline);
            });
        });
    };

    ApiClient.prototype.exportConversation = function (payload, sessionToken) {
        return this.post(
            this.config.conversationExportUrl,
            JSON.stringify(payload || {}),
            { 'X-YSAI-Session': String(sessionToken || '') },
            60000
        );
    };

    ApiClient.prototype.deleteConversation = function (payload, sessionToken) {
        return this.post(
            this.config.conversationDeleteUrl,
            JSON.stringify(payload || {}),
            { 'X-YSAI-Session': String(sessionToken || '') },
            60000
        );
    };

    ApiClient.prototype.isRetryable = function (error) {
        var code = String((error && error.code) || '');
        if (code === 'client_config_invalid' || code === 'client_capability_invalid') {
            return false;
        }
        // A malformed response has no trustworthy HTTP/application semantics.
        // Even a proxy-labelled 4xx cannot prove that the origin did not admit
        // the exact turn before its canonical response was replaced.
        if (code === 'response_contract_invalid') {
            return true;
        }
        var status = error && typeof error.status === 'number' ? error.status : 0;
        if (status === 0 || status >= 500) {
            return true;
        }
        if (status === 429) {
            return code === 'chat_ingress_rate_limited';
        }
        if (status !== 409) {
            return false;
        }
        return code !== 'turn_id_conflict';
    };

    ApiClient.prototype.post = function (url, body, extraHeaders, timeoutMs) {
        return this.request(url, 'POST', body, extraHeaders, timeoutMs);
    };

    ApiClient.prototype.request = function (url, method, body, extraHeaders, timeoutMs) {
        var self = this;
        var target;
        try {
            target = new URL(String(url || ''), window.location.href);
            var pageOrigin = new URL(window.location.href).origin;
            if ((target.protocol !== 'http:' && target.protocol !== 'https:')
                || target.username
                || target.password
                || (pageOrigin !== 'null' && target.origin !== pageOrigin)
            ) {
                throw new Error('cross-origin endpoint');
            }
        } catch (configError) {
            return Promise.reject(new ApiError(
                util.text('genericFailure', 'تعذر إكمال الطلب بأمان.'),
                'client_config_invalid',
                0,
                0
            ));
        }

        if (typeof window.AbortController !== 'function') {
            return Promise.reject(new ApiError(
                util.text('genericFailure', 'تعذر إكمال الطلب بأمان.'),
                'client_capability_invalid',
                0,
                0
            ));
        }

        var headers = { 'Accept': 'application/json' };
        var controller = new window.AbortController();
        var options = {
            method: String(method || 'GET'),
            credentials: 'same-origin',
            headers: headers,
            signal: controller.signal
        };
        Object.keys(extraHeaders || {}).forEach(function (key) {
            headers[key] = extraHeaders[key];
        });
        if (options.method !== 'GET' && options.method !== 'HEAD') {
            headers['Content-Type'] = 'application/json';
            options.body = body;
        }

        var duration = Math.max(1, Math.floor(Number(timeoutMs || policy.TURN_TIMEOUT_MS)));
        var timedOut = false;
        var timer = 0;
        var timeoutPromise = new Promise(function (resolve, reject) {
            timer = window.setTimeout(function () {
                timedOut = true;
                controller.abort();
                reject(self.timeoutError());
            }, duration);
        });

        var requestPromise = Promise.resolve().then(function () {
            return window.fetch(target.href, options);
        }).then(function (response) {
            return response.text().then(function (raw) {
                var payload = {};
                var parsed = false;
                if (raw) {
                    try {
                        payload = JSON.parse(raw);
                        parsed = Boolean(payload && typeof payload === 'object' && !Array.isArray(payload));
                    } catch (error) {
                        parsed = false;
                    }
                }
                if (!parsed) {
                    throw new ApiError(
                        util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بنفس الطلب بأمان.'),
                        'response_contract_invalid',
                        response.ok ? 502 : (response.status || 502),
                        0
                    );
                }
                if (response.ok && payload.ok !== true) {
                    throw new ApiError(
                        util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بنفس الطلب بأمان.'),
                        'response_contract_invalid',
                        502,
                        0
                    );
                }
                if (!response.ok) {
                    if (!validErrorEnvelope(payload)) {
                        throw new ApiError(
                            util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بنفس الطلب بأمان.'),
                            'response_contract_invalid',
                            response.status || 502,
                            0
                        );
                    }
                    throw new ApiError(
                        util.safeMessage(payload),
                        payload.code,
                        response.status,
                        Object.prototype.hasOwnProperty.call(payload, 'retry_after') ? payload.retry_after : 0
                    );
                }
                return payload;
            });
        });

        function cleanup() {
            if (timer) {
                window.clearTimeout(timer);
                timer = 0;
            }
        }

        return Promise.race([requestPromise, timeoutPromise]).then(function (payload) {
            cleanup();
            return payload;
        }, function (error) {
            cleanup();
            if (error instanceof ApiError) {
                throw error;
            }
            if (timedOut) {
                throw self.timeoutError();
            }
            throw new ApiError(
                util.text('genericFailure', 'تعذر إكمال الطلب بأمان.'),
                'network_unavailable',
                0,
                0
            );
        });
    };

    Runtime.ApiError = ApiError;
    Runtime.ApiClient = ApiClient;
}(window));
