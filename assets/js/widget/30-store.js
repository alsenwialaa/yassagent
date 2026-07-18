(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var util = Runtime.util;
    var Contract = Runtime.publicContract;

    function degradedCommittedMessages(state, action) {
        var turnId = String((action.message && action.message.turn_id) || '').toLowerCase();
        var retryId = String(action.removeRetryId || '');
        var source = Array.isArray(state.preTurnMessages)
            ? state.preTurnMessages
            : (Array.isArray(state.messages) ? state.messages : []);
        var rows = source.filter(function (message) {
            if (!message || typeof message !== 'object') {
                return true;
            }
            if (turnId && String(message.turn_id || '').toLowerCase() === turnId) {
                return false;
            }
            if (retryId && String(message.retry_id || '') === retryId) {
                return false;
            }
            return true;
        });
        if (action.pendingUserMessage && typeof action.pendingUserMessage === 'object') {
            rows.push(action.pendingUserMessage);
        }
        if (action.message && typeof action.message === 'object') {
            rows.push(action.message);
        }
        return rows;
    }

    function failureMessage(text, retryId, bootFailure) {
        var message = {
            id: 'failure-' + util.randomId(),
            role: 'assistant',
            outcome: 'safe_failure',
            text: String(text || util.text('genericFailure', 'تعذر إكمال الطلب بأمان.')),
            products: [],
            retry_id: retryId || '',
            state_uncertain: false,
            boot_failure: bootFailure === true
        };
        return message;
    }

    function pendingRetryStatus() {
        return util.text(
            'turnRetryPending',
            'تعذر التحقق من نتيجة الطلب. أعد المحاولة نفسها قبل متابعة المحادثة.'
        );
    }

    function retryRecheckStatus() {
        return util.text(
            'turnRecheckPending',
            'انتهت مهلة إعادة المحاولة. يجري التحقق من النتيجة قبل متابعة المحادثة.'
        );
    }

    function normalizedRetryIdentity(value) {
        if (!value || typeof value !== 'object'
            || !value.conversation || typeof value.conversation !== 'object'
        ) {
            return null;
        }
        var turnId = String(value.turnId || '').toLowerCase();
        var conversationId = String(value.conversation.id || '');
        var conversationToken = String(value.conversation.token || '');
        if (!turnId || !conversationId || !conversationToken) {
            return null;
        }
        var startedAt = Number(value.startedAtMs || 0);
        var guardUntil = Number(value.guardUntilMs || 0);
        return {
            turnId: turnId,
            conversation: { id: conversationId, token: conversationToken },
            startedAtMs: isFinite(startedAt) ? Math.max(0, Math.floor(startedAt)) : 0,
            guardUntilMs: isFinite(guardUntil) ? Math.max(0, Math.floor(guardUntil)) : 0
        };
    }


    function reconcileCanonicalMessages(messages, localUserMessage) {
        var rows = Array.isArray(messages) ? messages.slice() : [];
        if (!localUserMessage || typeof localUserMessage !== 'object') {
            return rows;
        }
        var turnId = String(localUserMessage.turn_id || '').toLowerCase();
        if (!turnId) {
            return rows;
        }
        return rows.map(function (message) {
            if (!message || message.role !== 'user'
                || String(message.turn_id || '').toLowerCase() !== turnId
            ) {
                return message;
            }
            var copy = Object.assign({}, message);
            if (localUserMessage.presentation && typeof localUserMessage.presentation === 'object') {
                copy.presentation = Object.assign(
                    {},
                    message.presentation && typeof message.presentation === 'object' ? message.presentation : {},
                    localUserMessage.presentation
                );
            }
            return copy;
        });
    }

    function initialState(config) {
        return {
            phase: 'idle',
            status: '',
            sessionToken: '',
            conversation: null,
            messages: [],
            cart: null,
            cartNotice: '',
            cartMutationNotice: '',
            capabilities: {
                chat_ready: false,
                images: false,
                max_images: util.boundedInteger(config.maxImages, Contract.limits.attachmentMaxItems, 0, Contract.limits.attachmentMaxItems),
                max_image_bytes: util.boundedInteger(config.maxImageBytes, Contract.runtime.imagePolicy.max_decoded_bytes, 0, Contract.runtime.imagePolicy.max_decoded_bytes),
                cart_mutations: { available: false, code: 'runtime_unavailable', notice: '' }
            },
            widget: {},
            attachments: [],
            preTurnMessages: null,
            activeUserMessage: null,
            retryRecheckRequired: false,
            retryRecheckIdentity: null,
            retryIdentities: {},
            privacyBusy: false
        };
    }

    function reduce(state, action) {
        var next;
        var messages;
        switch (action.type) {
        case 'BOOT_START':
            return Object.assign({}, state, {
                phase: 'booting',
                status: util.text('loading', 'جارٍ بدء المساعد…')
            });
        case 'BOOT_SUCCESS':
            var capabilities = action.capabilities || state.capabilities;
            var retryRecheckPending = action.retryRecheckRequired === true;
            messages = Array.isArray(action.messages) ? action.messages.slice() : [];
            return Object.assign({}, state, {
                phase: retryRecheckPending || capabilities.chat_ready === false
                    ? 'blocked'
                    : 'ready',
                status: retryRecheckPending
                        ? retryRecheckStatus()
                        : (capabilities.chat_ready === false
                            ? util.text('unavailable', 'مساعد التسوق غير متاح مؤقتاً.')
                            : ''),
                sessionToken: String(action.sessionToken || ''),
                conversation: action.conversation || null,
                messages: messages,
                cart: action.cartAvailable === false ? state.cart : (action.cart || null),
                cartNotice: action.cartAvailable === false
                    ? String(action.cartNotice || util.text('cartUnavailable', 'تعذر تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.'))
                    : '',
                cartMutationNotice: capabilities.cart_mutations && capabilities.cart_mutations.available === false
                    ? String(capabilities.cart_mutations.notice || '')
                    : '',
                capabilities: capabilities,
                widget: action.widget || {},
                preTurnMessages: null,
                activeUserMessage: null,
                retryRecheckRequired: retryRecheckPending,
                retryRecheckIdentity: retryRecheckPending
                    ? (action.retryRecheckIdentity || state.retryRecheckIdentity || null)
                    : null,
                retryIdentities: {}
            });
        case 'SESSION_REFRESH_START':
            return Object.assign({}, state, {
                phase: 'recovering',
                status: util.text('sessionRefreshing', 'جارٍ تحديث جلسة المساعد…')
            });
        case 'SESSION_REFRESH_SUCCESS':
            capabilities = action.capabilities || state.capabilities;
            var canonicalMessages = Array.isArray(action.messages)
                ? action.messages.slice()
                : state.messages.slice();
            var pendingUserMessage = action.pendingUserMessage && typeof action.pendingUserMessage === 'object'
                ? action.pendingUserMessage
                : null;
            messages = action.resumePending && pendingUserMessage
                ? canonicalMessages.concat([pendingUserMessage])
                : canonicalMessages;
            return Object.assign({}, state, {
                phase: action.resumePending
                    ? (action.retrying ? 'recovering' : 'sending')
                    : (capabilities.chat_ready === false ? 'blocked' : 'ready'),
                status: action.resumePending
                    ? util.text('thinking', 'جارٍ التحقق من معلومات المتجر…')
                    : (capabilities.chat_ready === false
                        ? util.text('unavailable', 'مساعد التسوق غير متاح مؤقتاً.')
                        : ''),
                sessionToken: String(action.sessionToken || ''),
                conversation: action.conversation || state.conversation,
                messages: messages,
                cart: action.cartAvailable === false ? state.cart : (action.cart || null),
                cartNotice: action.cartAvailable === false
                    ? String(action.cartNotice || util.text('cartUnavailable', 'تعذر تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.'))
                    : '',
                cartMutationNotice: capabilities.cart_mutations && capabilities.cart_mutations.available === false
                    ? String(capabilities.cart_mutations.notice || '')
                    : '',
                capabilities: capabilities,
                widget: action.widget || state.widget,
                preTurnMessages: action.resumePending && pendingUserMessage ? canonicalMessages : null,
                activeUserMessage: action.resumePending ? pendingUserMessage : null,
                retryRecheckRequired: false,
                retryRecheckIdentity: null,
                retryIdentities: action.resumePending ? state.retryIdentities : {}
            });
        case 'CONVERSATION_RESET_START':
            return Object.assign({}, state, {
                phase: 'booting',
                status: util.text('loading', 'جارٍ بدء المساعد…'),
                sessionToken: '',
                conversation: null,
                messages: [],
                attachments: [],
                preTurnMessages: null,
                activeUserMessage: null,
                retryRecheckRequired: false,
                retryRecheckIdentity: null,
                retryIdentities: {},
                privacyBusy: false
            });
        case 'BOOT_FAILURE':
            if (action.preserveRetry === true || state.retryRecheckRequired === true) {
                var failedRecheckIdentity = normalizedRetryIdentity(action.retryRecheckIdentity)
                    || state.retryRecheckIdentity;
                return Object.assign({}, state, {
                    phase: 'blocked',
                    status: state.retryRecheckRequired === true || failedRecheckIdentity
                        ? retryRecheckStatus()
                        : pendingRetryStatus(),
                    retryRecheckRequired: Boolean(state.retryRecheckRequired === true || failedRecheckIdentity),
                    retryRecheckIdentity: failedRecheckIdentity || null
                });
            }
            messages = state.messages.filter(function (message) {
                return !message || message.boot_failure !== true;
            }).concat([failureMessage(action.message, '', true)]);
            return Object.assign({}, state, { phase: 'failed', status: '', messages: messages });
        case 'SET_STATUS':
            return Object.assign({}, state, { status: String(action.message || '') });
        case 'PRIVACY_START':
            return Object.assign({}, state, {
                privacyBusy: true,
                status: String(action.message || '')
            });
        case 'PRIVACY_END':
            return Object.assign({}, state, {
                privacyBusy: false,
                status: String(action.message || '')
            });
        case 'ATTACHMENTS_SET':
            return Object.assign({}, state, { attachments: Array.isArray(action.attachments) ? action.attachments.slice() : [] });
        case 'TURN_START':
            messages = state.messages.concat([action.userMessage]);
            return Object.assign({}, state, {
                phase: 'sending',
                status: util.text('thinking', 'جارٍ التحقق من معلومات المتجر…'),
                messages: messages,
                attachments: [],
                preTurnMessages: state.messages.slice(),
                activeUserMessage: action.userMessage || null,
                retryRecheckRequired: false,
                retryRecheckIdentity: null,
                retryIdentities: {}
            });
        case 'RETRY_START':
            return Object.assign({}, state, {
                phase: 'recovering',
                status: util.text('thinking', 'جارٍ التحقق من معلومات المتجر…')
            });
        case 'TURN_SUCCESS':
            var readinessBlocked = (state.capabilities && state.capabilities.chat_ready === false)
                || (action.message
                    && action.message.outcome === 'safe_failure'
                    && action.message.failure_code === 'assistant_not_ready');
            messages = action.turnCommitted === true && action.messagesAvailable === false
                ? degradedCommittedMessages(state, action)
                : reconcileCanonicalMessages(action.messages, action.pendingUserMessage);
            if (action.turnCommitted !== true) {
                if (action.pendingUserMessage && typeof action.pendingUserMessage === 'object') {
                    messages.push(action.pendingUserMessage);
                }
                if (action.message && typeof action.message === 'object') {
                    messages.push(action.message);
                } else {
                    messages.push(failureMessage(util.text('genericFailure', 'تعذر إكمال الطلب بأمان.'), ''));
                }
            }
            next = {
                phase: readinessBlocked ? 'blocked' : 'ready',
                status: readinessBlocked
                    ? util.text('unavailable', 'مساعد التسوق غير متاح مؤقتاً.')
                    : '',
                conversation: action.conversation || state.conversation,
                messages: messages,
                preTurnMessages: null,
                activeUserMessage: null,
                retryRecheckRequired: false,
                retryRecheckIdentity: null,
                retryIdentities: {}
            };
            if (action.cartAvailable === false) {
                // Preserve the last display-only snapshot. A failed refresh must
                // not erase useful UI context or be mistaken for an empty cart.
                next.cart = state.cart;
                next.cartNotice = String(action.cartNotice || util.text(
                    'cartUnavailable',
                    'تعذر تحديث ملخص السلة. افتح صفحة السلة للتحقق منها.'
                ));
            } else {
                next.cart = action.cart || null;
                next.cartNotice = '';
            }
            next.capabilities = Object.assign({}, state.capabilities, {
                cart_mutations: action.cartMutations
            });
            next.cartMutationNotice = action.cartMutations.available === false
                ? String(action.cartMutations.notice || '')
                : '';
            return Object.assign({}, state, next);
        case 'TURN_FAILURE':
            var requestedRetryId = String(action.retryId || '');
            var turnRetryIdentity = normalizedRetryIdentity(action.retryIdentity);
            var turnRetryPending = requestedRetryId !== '' && turnRetryIdentity !== null;
            var retryIdentityMissing = requestedRetryId !== '' && turnRetryIdentity === null;
            messages = state.messages.concat([failureMessage(
                action.message,
                turnRetryPending ? requestedRetryId : '',
                false
            )]);
            var turnRetryIdentities = {};
            if (turnRetryPending) {
                turnRetryIdentities[requestedRetryId] = turnRetryIdentity;
            }
            var turnFailureBlocked = turnRetryPending || retryIdentityMissing
                || (state.capabilities && state.capabilities.chat_ready === false);
            return Object.assign({}, state, {
                phase: turnFailureBlocked ? 'blocked' : 'ready',
                status: turnRetryPending
                    ? pendingRetryStatus()
                    : (retryIdentityMissing
                        ? retryRecheckStatus()
                        : (turnFailureBlocked
                            ? util.text('unavailable', 'مساعد التسوق غير متاح مؤقتاً.')
                            : '')),
                messages: messages,
                preTurnMessages: null,
                activeUserMessage: null,
                retryRecheckRequired: retryIdentityMissing,
                retryRecheckIdentity: null,
                retryIdentities: turnRetryIdentities
            });
        case 'RETRY_FAILURE':
            messages = state.messages.map(function (message) {
                if (!message || message.retry_id !== action.retryId) {
                    return message;
                }
                var copy = Object.assign({}, message, { text: String(action.message || message.text) });
                if (!action.retryable
                    || !normalizedRetryIdentity(state.retryIdentities[String(action.retryId || '')])
                ) {
                    copy.retry_id = '';
                }
                return copy;
            });
            var retryPendingAfterFailure = action.retryable === true
                && String(action.retryId || '') !== ''
                && normalizedRetryIdentity(state.retryIdentities[String(action.retryId || '')]) !== null;
            var retryIdentitiesAfterFailure = Object.assign({}, state.retryIdentities);
            if (!retryPendingAfterFailure) {
                delete retryIdentitiesAfterFailure[String(action.retryId || '')];
            }
            var retryFailureBlocked = retryPendingAfterFailure
                || (state.capabilities && state.capabilities.chat_ready === false);
            return Object.assign({}, state, {
                phase: retryFailureBlocked ? 'blocked' : 'ready',
                status: retryPendingAfterFailure
                    ? pendingRetryStatus()
                    : (retryFailureBlocked
                        ? util.text('unavailable', 'مساعد التسوق غير متاح مؤقتاً.')
                        : ''),
                messages: messages,
                retryRecheckRequired: false,
                retryRecheckIdentity: null,
                retryIdentities: retryIdentitiesAfterFailure
            });
        case 'RETRY_STORAGE_EVICTED':
            var retryIds = Array.isArray(action.retryIds) ? action.retryIds : [];
            if (retryIds.length === 0) {
                return state;
            }
            var unresolvedEvicted = false;
            var retryIdentitiesAfterEviction = Object.assign({}, state.retryIdentities);
            var evictedRecheckIdentity = null;
            retryIds.forEach(function (retryId) {
                var identity = retryIdentitiesAfterEviction[String(retryId || '')];
                identity = normalizedRetryIdentity(identity);
                if (identity) {
                    evictedRecheckIdentity = evictedRecheckIdentity || identity;
                }
                delete retryIdentitiesAfterEviction[String(retryId || '')];
            });
            messages = state.messages.map(function (message) {
                if (!message || retryIds.indexOf(String(message.retry_id || '')) === -1) {
                    return message;
                }
                unresolvedEvicted = true;
                var copy = Object.assign({}, message, { retry_id: '' });
                return copy;
            });
            if (!unresolvedEvicted) {
                return Object.assign({}, state, { messages: messages });
            }
            return Object.assign({}, state, {
                phase: 'blocked',
                status: retryRecheckStatus(),
                messages: messages,
                retryRecheckRequired: true,
                retryRecheckIdentity: evictedRecheckIdentity,
                retryIdentities: retryIdentitiesAfterEviction
            });
        default:
            return state;
        }
    }

    function Store(config) {
        this.state = initialState(config || {});
        this.listeners = [];
    }

    Store.prototype.getState = function () {
        return this.state;
    };

    Store.prototype.dispatch = function (action) {
        this.state = reduce(this.state, action || {});
        this.listeners.slice().forEach(function (listener) {
            try {
                listener();
            } catch (error) {
                // Rendering and observer failures are local UI defects. State
                // has already advanced and transport success must stay final.
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('Yassin AI Assistant listener failed.', error);
                }
            }
        });
    };

    Store.prototype.subscribe = function (listener) {
        if (typeof listener === 'function') {
            this.listeners.push(listener);
        }
    };

    Runtime.Store = Store;
}(window));
