(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var util = Runtime.util;
    var Contract = Runtime.publicContract;

    function localMessagePresentation(presentation, imagePreviews) {
        var visual = presentation && typeof presentation === 'object' ? presentation : {};
        var result = {};
        var replyQuote = String(visual.reply_quote || '').trim();
        if (replyQuote) {
            result.reply_quote = util.sliceCodePoints(replyQuote, 0, Contract.limits.replyQuoteMaxChars);
        }
        var quote = visual.quote && typeof visual.quote === 'object' ? visual.quote : null;
        var quoteImage = quote ? util.safeUrl(quote.image) : '';
        if (quoteImage) {
            result.quote = {
                image: quoteImage,
                alt: String(quote.alt || '').slice(0, 500)
            };
        }
        var images = (Array.isArray(imagePreviews) ? imagePreviews : []).slice(0, Contract.limits.presentationMaxImages).map(function (image) {
            return {
                src: String((image && image.src) || ''),
                alt: String((image && image.alt) || util.text('imageAttachment', 'صورة مرفقة')).slice(0, 500)
            };
        }).filter(function (image) { return image.src !== ''; });
        if (images.length > 0) {
            result.images = images;
        }
        return result;
    }

    function imageTurnText(count) {
        var total = Math.max(1, Number(count || 0));
        if (total === 1) {
            return util.text(
                'imageTurnOnly',
                'صورة مرفقة (متاحة للمعالجة في هذا الطلب فقط)'
            );
        }
        return util.text(
            'imagesTurnOnly',
            'صور مرفقة × {count} (متاحة للمعالجة في هذا الطلب فقط)'
        ).replace('{count}', String(total));
    }

    function visibleTurnText(message, attachmentCount) {
        var exact = String(message || '');
        var parts = [];
        if (exact !== '') {
            parts.push(exact);
        }
        if (attachmentCount > 0) {
            parts.push(imageTurnText(attachmentCount));
        }
        return parts.join('\n');
    }

    var EXPORT_ROW_FIELDS = [
        'messages', 'verified_cart_receipts', 'turns',
        'cart_operations', 'cart_operation_steps', 'cart_step_attempts'
    ];

    function exportPage(response) {
        if (!util.isRecord(response) || response.ok !== true
            || Object.keys(response).sort().join('|') !== 'export|ok'
            || !util.isRecord(response.export)
        ) {
            throw new Runtime.ApiError(
                util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة.'),
                'response_contract_invalid',
                502,
                0
            );
        }
        var page = response.export;
        var expected = [
            'schema', 'conversation_id', 'created_at', 'updated_at', 'expires_at',
            'state', 'messages', 'verified_cart_receipts', 'turns',
            'cart_operations', 'cart_operation_steps', 'cart_step_attempts',
            'next_cursor', 'complete'
        ].sort();
        if (Object.keys(page).sort().join('|') !== expected.join('|')
            || page.schema !== 1
            || typeof page.conversation_id !== 'string'
            || !/^[a-f0-9-]{36}$/.test(page.conversation_id)
            || typeof page.complete !== 'boolean'
            || !util.isRecord(page.state)
            || ['created_at', 'updated_at', 'expires_at'].some(function (field) {
                return !Number.isInteger(page[field]) || page[field] < 0;
            })
            || EXPORT_ROW_FIELDS.some(function (field) { return !Array.isArray(page[field]); })
            || (page.complete && page.next_cursor !== null)
            || (!page.complete && (typeof page.next_cursor !== 'string'
                || page.next_cursor.length > 2048
                || !/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/.test(page.next_cursor)))
        ) {
            throw new Runtime.ApiError(
                util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة.'),
                'response_contract_invalid',
                502,
                0
            );
        }
        return page;
    }

    function AssistantApp(config) {
        var self = this;
        this.config = config || {};
        this.store = new Runtime.Store(this.config);
        this.api = new Runtime.ApiClient(this.config);
        this.continuity = new Runtime.ContinuityStore(this.config.storageKey);
        this.clientIdentity = new Runtime.ClientIdentityStore(this.config.storageKey);
        this.browserContinuity = new Runtime.BrowserContinuitySecretStore(this.config.storageKey);
        this.views = [];
        this.bootPromise = null;
        this.sessionRefreshPromise = null;
        this.pendingRecheckTimer = 0;
        this.pendingRecheckAttempt = 0;
        this.draftOwner = null;
        this.activeView = null;
        this.sessionContinuitySecret = '';
        this.privacyPromise = null;
        this.browserStorageDegradedNotified = false;
        this.retryStore = new Runtime.RetryEnvelopeStore(function (retryIds) {
            self.store.dispatch({ type: 'RETRY_STORAGE_EVICTED', retryIds: retryIds });
            if (self.store.getState().retryRecheckRequired === true) {
                Promise.resolve().then(function () {
                    if (self.store.getState().retryRecheckRequired === true
                        && !self.bootPromise
                    ) {
                        self.boot(false);
                    }
                });
            }
        }, { storageKey: this.config.storageKey });
        this.attachments = new Runtime.AttachmentQueue(
            this.config,
            function (entries) {
                self.store.dispatch({ type: 'ATTACHMENTS_SET', attachments: entries });
            },
            function (message) {
                self.notice(message);
            }
        );
        this.store.subscribe(function () { self.render(); });
    }

    AssistantApp.prototype.attach = function (root) {
        root.classList.add('ysai-widget-root');
        if (!root.classList.contains('ysai-position-left') && !root.classList.contains('ysai-position-right')) {
            root.classList.add('ysai-position-right');
        }
        if (!root.classList.contains('ysai-product-layout-list')
            && !root.classList.contains('ysai-product-layout-grid')
            && !root.classList.contains('ysai-product-layout-carousel')
        ) {
            root.classList.add('ysai-product-layout-carousel');
        }
        if (!root.classList.contains('ysai-product-cards-1')
            && !root.classList.contains('ysai-product-cards-2')
            && !root.classList.contains('ysai-product-cards-3')
        ) {
            root.classList.add('ysai-product-cards-1');
        }
        var existing = null;
        this.views.some(function (view) {
            if (view.root === root) {
                existing = view;
                return true;
            }
            return false;
        });
        if (existing) {
            return existing;
        }
        var view = new Runtime.WidgetView(root, this);
        this.views.push(view);
        view.render(this.store.getState());
        return view;
    };

    AssistantApp.prototype.detach = function (root) {
        var self = this;
        this.views = this.views.filter(function (view) {
            if (view.root !== root) {
                return true;
            }
            if (self.activeView === view) {
                self.activeView = null;
            }
            view.destroy();
            return false;
        });
    };

    AssistantApp.prototype.render = function () {
        var state = this.store.getState();
        this.views.slice().forEach(function (view) {
            try {
                view.render(state);
            } catch (error) {
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('Yassin AI Assistant view failed.', error);
                }
            }
        });
    };

    AssistantApp.prototype.clearPendingRecheckTimer = function () {
        if (this.pendingRecheckTimer) {
            window.clearTimeout(this.pendingRecheckTimer);
            this.pendingRecheckTimer = 0;
        }
    };

    AssistantApp.prototype.schedulePendingRecheck = function () {
        var self = this;
        this.clearPendingRecheckTimer();
        if (this.store.getState().retryRecheckRequired !== true) {
            this.pendingRecheckAttempt = 0;
            return;
        }
        this.pendingRecheckAttempt += 1;
        var delay = Math.min(60000, 3000 * Math.pow(2, Math.min(5, this.pendingRecheckAttempt - 1)));
        this.pendingRecheckTimer = window.setTimeout(function () {
            self.pendingRecheckTimer = 0;
            if (self.store.getState().retryRecheckRequired === true && !self.bootPromise) {
                self.boot(false);
            }
        }, delay);
    };

    AssistantApp.prototype.ensureReady = function () {
        var phase = this.store.getState().phase;
        if (phase === 'idle' || phase === 'failed' || phase === 'blocked') {
            return this.boot(false);
        }
        return this.bootPromise || Promise.resolve();
    };

    AssistantApp.prototype.acceptBootResult = function (
        result,
        retryRecheckRequired,
        retryRecheckIdentity
    ) {
        var existing = this.store.getState().conversation;
        if (existing && !this.sameConversation(existing, result.conversation)) {
            var acceptedSecret = this.sessionContinuitySecret;
            this.resetConversationState();
            this.sessionContinuitySecret = acceptedSecret;
            retryRecheckRequired = false;
            retryRecheckIdentity = null;
        }
        this.persistConversation(result.conversation);
        this.attachments.setLimits(result.capabilities.max_images, result.capabilities.max_image_bytes);
        this.store.dispatch({
            type: 'BOOT_SUCCESS',
            sessionToken: result.sessionToken,
            conversation: result.conversation,
            messages: result.messages,
            cart: result.cart,
            cartAvailable: result.cartAvailable,
            cartNotice: result.cartNotice,
            capabilities: result.capabilities,
            widget: result.widget,
            retryRecheckRequired: retryRecheckRequired === true,
            retryRecheckIdentity: retryRecheckIdentity || null
        });
        if (this.store.getState().retryRecheckRequired === true) {
            this.schedulePendingRecheck();
        } else {
            this.clearPendingRecheckTimer();
            this.pendingRecheckAttempt = 0;
        }
    };

    AssistantApp.prototype.resetConversationState = function () {
        this.clearPendingRecheckTimer();
        this.draftOwner = null;
        this.sessionContinuitySecret = '';
        var pending = this.continuity.readPending();
        if (pending) {
            this.continuity.clearPending(pending.turn_id);
        }
        this.attachments.clear();
        this.retryStore.clear();
        this.views.slice().forEach(function (view) {
            if (view && typeof view.resetConversationState === 'function') {
                view.resetConversationState();
            }
        });
        this.store.dispatch({ type: 'CONVERSATION_RESET_START' });
    };

    AssistantApp.prototype.coordinatedContinuity = function (continuity, forceFresh) {
        var self = this;
        var ignoreSharedContinuity = forceFresh === true;
        if ((!ignoreSharedContinuity
                && String((continuity && continuity.conversation_id) || '') !== '')
            || !this.config.storageKey
        ) {
            return Promise.resolve({
                continuity: ignoreSharedContinuity ? {} : (continuity || {}),
                leaseOwner: ''
            });
        }
        if (window.navigator && window.navigator.locks
            && typeof window.navigator.locks.request === 'function'
        ) {
            return new Promise(function (resolve, reject) {
                var released = false;
                window.navigator.locks.request(
                    'ysai-boot-' + self.config.storageKey,
                    { mode: 'exclusive' },
                    function () {
                        var shared = ignoreSharedContinuity ? {} : self.continuity.read();
                        return new Promise(function (release) {
                            resolve({
                                continuity: shared,
                                leaseOwner: '',
                                release: function () {
                                    if (!released) {
                                        released = true;
                                        release();
                                    }
                                }
                            });
                        });
                    }
                ).catch(reject);
            });
        }
        var owner = util.randomId();
        var deadline = Date.now() + Runtime.ClientRecoveryPolicy.BOOT_TIMEOUT_MS;

        function attempt() {
            var shared = self.continuity.read();
            if (!ignoreSharedContinuity && String(shared.conversation_id || '') !== '') {
                return Promise.resolve({ continuity: shared, leaseOwner: '' });
            }
            if (self.continuity.tryAcquireBootLease(owner, Runtime.ClientRecoveryPolicy.BOOT_TIMEOUT_MS)) {
                return new Promise(function (resolve) {
                    window.setTimeout(resolve, 30);
                }).then(function () {
                    if (self.continuity.tryAcquireBootLease(owner, Runtime.ClientRecoveryPolicy.BOOT_TIMEOUT_MS)) {
                        return {
                            continuity: ignoreSharedContinuity ? {} : self.continuity.read(),
                            leaseOwner: owner,
                            release: null
                        };
                    }
                    return attempt();
                });
            }
            if (Date.now() >= deadline) {
                return Promise.reject(new Runtime.ApiError(
                    util.text('genericFailure', 'تعذر بدء جلسة المساعد.'),
                    'boot_coordination_timeout',
                    0,
                    0
                ));
            }
            return new Promise(function (resolve) {
                window.setTimeout(resolve, 100);
            }).then(attempt);
        }

        return attempt();
    };

    AssistantApp.prototype.persistedPendingFor = function (continuity) {
        var pending = this.continuity.readPending();
        var conversationId = String((continuity && continuity.conversation_id) || '').toLowerCase();
        if (!pending) {
            return null;
        }
        if (conversationId && pending.conversation_id !== conversationId) {
            this.continuity.clearPending(pending.turn_id);
            this.retryStore.remove(pending.retry_id);
            return null;
        }
        return pending;
    };

    AssistantApp.prototype.recheckPendingFor = function (conversation) {
        var state = this.store.getState();
        var identity = state.retryRecheckRequired === true
            && state.retryRecheckIdentity
            && typeof state.retryRecheckIdentity === 'object'
            ? state.retryRecheckIdentity
            : null;
        if (!identity || !identity.conversation || typeof identity.conversation !== 'object') {
            return null;
        }
        var turnId = String(identity.turnId || '').toLowerCase();
        var identityConversation = {
            id: String(identity.conversation.id || '').toLowerCase(),
            token: String(identity.conversation.token || '')
        };
        if (!turnId || !identityConversation.id || !identityConversation.token
            || (conversation && !this.sameConversation(conversation, identityConversation))
        ) {
            return null;
        }
        return {
            turn_id: turnId,
            conversation_id: identityConversation.id,
            retry_id: '',
            started_at_ms: Math.floor(Number(identity.startedAtMs || 0)),
            guard_until_ms: Math.floor(Number(identity.guardUntilMs || 0)),
            identity_only: true,
            conversation: identityConversation
        };
    };

    AssistantApp.prototype.boot = function (forceNew, continuityOverride) {
        var self = this;
        if (this.bootPromise) {
            return this.bootPromise;
        }
        if (this.store.getState().phase === 'ready' && !forceNew) {
            return Promise.resolve();
        }

        var adoptingShared = !forceNew && continuityOverride
            && typeof continuityOverride === 'object'
            && String(continuityOverride.conversation_id || '') !== '';
        var initialContinuity = forceNew
            ? {}
            : (adoptingShared ? continuityOverride : this.continuity.read());
        if (forceNew) {
            // Rotate the shared browser bearer before publishing the reset
            // tombstone. An old tab that finishes during the new boot can no
            // longer republish credentials under its superseded bearer.
            try {
                this.browserContinuity.rotate();
            } catch (error) {
                this.store.dispatch({
                    type: 'BOOT_FAILURE',
                    message: error && error.message
                        ? error.message
                        : util.text('genericFailure', 'تعذر بدء جلسة المساعد.')
                });
                return Promise.resolve(false);
            }
            var abandoned = this.continuity.readPending();
            if (abandoned) {
                this.continuity.clearPending(abandoned.turn_id);
            }
            this.continuity.clear();
            this.resetConversationState();
        } else if (adoptingShared) {
            this.resetConversationState();
        } else {
            this.store.dispatch({ type: 'BOOT_START' });
        }
        var requestedPending = null;
        var requestedConversation = null;
        var leaseOwner = '';
        var releaseCoordination = null;
        var resumeAfterBoot = null;
        var recoverConversationAfterBoot = null;
        var bootContinuitySecret = '';
        var operation = this.coordinatedContinuity(initialContinuity, forceNew).then(function (coordinated) {
            leaseOwner = coordinated.leaseOwner;
            releaseCoordination = typeof coordinated.release === 'function'
                ? coordinated.release
                : null;
            var continuity = coordinated.continuity;
            requestedConversation = self.conversationFromContinuity(continuity);
            requestedPending = forceNew ? null : self.persistedPendingFor(continuity);
            if (!requestedPending && !forceNew) {
                requestedPending = self.recheckPendingFor(requestedConversation);
                if (requestedPending && !requestedConversation) {
                    requestedConversation = requestedPending.conversation;
                    continuity = {
                        conversation_id: requestedConversation.id,
                        conversation_token: requestedConversation.token
                    };
                }
            }
            var ownsFreshReplacement = forceNew === true;
            var clientInstanceId = ownsFreshReplacement
                ? self.clientIdentity.rotate()
                : self.clientIdentity.id();
            var continuityCredentials = self.browserContinuity.credentials();
            bootContinuitySecret = continuityCredentials.secret;
            var bootRequest = Object.assign({}, continuity, {
                client_instance_id: clientInstanceId,
                browser_continuity_secret: continuityCredentials.secret,
                pending_turn_id: requestedPending ? requestedPending.turn_id : ''
            });
            if (continuityCredentials.previous_secret) {
                bootRequest.previous_browser_continuity_secret = continuityCredentials.previous_secret;
            }
            return self.api.boot(bootRequest);
        }).then(function (payload) {
            var result = Runtime.contracts.boot(payload, self.config);
            var recheckRequiredAfterBoot = false;
            var recheckIdentityAfterBoot = null;
            if ((requestedPending === null && result.pendingTurn !== null)
                || (requestedPending !== null
                    && (!result.pendingTurn || result.pendingTurn.id !== requestedPending.turn_id))
            ) {
                throw new Runtime.ApiError(
                    util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بأمان.'),
                    'response_contract_invalid',
                    502,
                    0
                );
            }
            if (requestedPending
                && String(result.conversation.id || '').toLowerCase()
                    !== String(requestedPending.conversation_id || '').toLowerCase()
            ) {
                if (requestedPending.retry_id) {
                    self.retryStore.remove(requestedPending.retry_id);
                }
                self.continuity.clearPending(requestedPending.turn_id);
                requestedPending = null;
            }
            if (requestedPending && result.pendingTurn) {
                var pendingConversation = {
                    id: requestedPending.conversation_id,
                    token: String(result.conversation.token || '')
                };
                var retainedEnvelope = requestedPending.retry_id
                    ? self.retryStore.get(requestedPending.retry_id)
                    : null;
                var retainedIdentity = retainedEnvelope
                    ? self.retryIdentityForEnvelope(retainedEnvelope)
                    : null;
                if (retainedEnvelope
                    && (!retainedIdentity
                        || self.turnIdForEnvelope(retainedEnvelope) !== requestedPending.turn_id
                        || !self.sameConversation(
                            retainedIdentity.conversation,
                            pendingConversation
                        ))
                ) {
                    throw new Runtime.ApiError(
                        util.text('invalidResponse', 'تعذر التحقق من بيانات إعادة المحاولة بأمان.'),
                        'response_contract_invalid',
                        502,
                        0
                    );
                }
                if (result.pendingTurn.status === 'terminal') {
                    if (!self.canonicalTurnIsComplete(result.messages, requestedPending.turn_id)) {
                        throw new Runtime.ApiError(
                            util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بأمان.'),
                            'response_contract_invalid',
                            502,
                            0
                        );
                    }
                    self.continuity.clearPending(requestedPending.turn_id);
                    if (requestedPending.retry_id) {
                        self.retryStore.remove(requestedPending.retry_id);
                    }
                    recheckRequiredAfterBoot = false;
                    recheckIdentityAfterBoot = null;
                } else if (result.pendingTurn.status === 'pending') {
                    recheckRequiredAfterBoot = true;
                    recheckIdentityAfterBoot = {
                        turnId: requestedPending.turn_id,
                        conversation: pendingConversation,
                        startedAtMs: Number(requestedPending.started_at_ms || 0),
                        guardUntilMs: Number(requestedPending.guard_until_ms || 0)
                    };
                } else if (retainedEnvelope) {
                    recheckRequiredAfterBoot = false;
                    recheckIdentityAfterBoot = null;
                    resumeAfterBoot = {
                        envelope: retainedEnvelope,
                        retryId: requestedPending.retry_id,
                        userMessage: self.pendingUserMessageForEnvelope(retainedEnvelope)
                    };
                } else {
                    var guardUntil = Number(requestedPending.guard_until_ms || 0);
                    if (guardUntil > 0 && Date.now() >= guardUntil) {
                        // The shared server/client execution deadline has
                        // elapsed and the canonical server still proves that
                        // this exact turn identity is absent. It was never
                        // admitted, so forgetting the bodyless marker is safe.
                        self.continuity.clearPending(requestedPending.turn_id);
                        if (requestedPending.retry_id) {
                            self.retryStore.remove(requestedPending.retry_id);
                        }
                        recheckRequiredAfterBoot = false;
                        recheckIdentityAfterBoot = null;
                    } else {
                        recheckRequiredAfterBoot = true;
                        recheckIdentityAfterBoot = {
                            turnId: requestedPending.turn_id,
                            conversation: pendingConversation,
                            startedAtMs: Number(requestedPending.started_at_ms || 0),
                            guardUntilMs: guardUntil
                        };
                    }
                }
            }
            self.sessionContinuitySecret = bootContinuitySecret;
            self.acceptBootResult(
                result,
                recheckRequiredAfterBoot,
                recheckIdentityAfterBoot
            );
            self.browserContinuity.acknowledge(bootContinuitySecret);
            if (forceNew) {
                self.notice(util.text(
                    'conversationReset',
                    'انتهت المحادثة السابقة. ابدأ طلباً جديداً.'
                ));
            }
            self.noticeBrowserStorageDegraded();
        }).catch(function (error) {
            if (!forceNew && requestedConversation
                && error && error.status === 401 && error.code === 'conversation_invalid'
            ) {
                var shared = self.continuity.read();
                var sharedConversation = self.conversationFromContinuity(shared);
                recoverConversationAfterBoot = sharedConversation
                    && !self.sameConversation(requestedConversation, sharedConversation)
                    ? { continuity: shared, clear: false }
                    : { continuity: {}, clear: true };
                return;
            }
            self.store.dispatch({
                type: 'BOOT_FAILURE',
                message: error && error.message
                    ? error.message
                    : util.text('genericFailure', 'تعذر بدء جلسة المساعد.'),
                preserveRetry: self.store.getState().retryRecheckRequired === true
                    || requestedPending !== null,
                retryRecheckIdentity: requestedPending && requestedConversation
                    ? {
                        turnId: requestedPending.turn_id,
                        conversation: requestedConversation,
                        startedAtMs: Number(requestedPending.started_at_ms || 0),
                        guardUntilMs: Number(requestedPending.guard_until_ms || 0)
                    }
                    : null
            });
            if (self.store.getState().retryRecheckRequired === true) {
                self.schedulePendingRecheck();
            }
        });
        this.bootPromise = operation.then(function () {
            if (leaseOwner) {
                self.continuity.releaseBootLease(leaseOwner);
            }
            if (releaseCoordination) {
                releaseCoordination();
            }
            self.bootPromise = null;
            if (recoverConversationAfterBoot) {
                if (recoverConversationAfterBoot.clear) {
                    var stalePending = self.continuity.readPending();
                    if (stalePending) {
                        self.continuity.clearPending(stalePending.turn_id);
                    }
                    self.continuity.clear();
                    self.resetConversationState();
                }
                return self.boot(false, recoverConversationAfterBoot.continuity);
            }
            if (resumeAfterBoot && self.store.getState().phase === 'ready') {
                if (resumeAfterBoot.userMessage) {
                    self.store.dispatch({ type: 'TURN_START', userMessage: resumeAfterBoot.userMessage });
                }
                return self.performTurn(
                    resumeAfterBoot.envelope,
                    resumeAfterBoot.retryId,
                    0,
                    Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS,
                    true
                );
            }
            return null;
        }).catch(function (error) {
            if (leaseOwner) {
                self.continuity.releaseBootLease(leaseOwner);
            }
            if (releaseCoordination) {
                releaseCoordination();
            }
            self.bootPromise = null;
            throw error;
        });
        return this.bootPromise;
    };

    AssistantApp.prototype.canAttach = function () {
        var state = this.store.getState();
        return state.phase === 'ready' && state.capabilities.images === true;
    };

    AssistantApp.prototype.claimDraftOwner = function (owner) {
        if (!owner || typeof owner !== 'object') {
            return;
        }
        if (this.draftOwner && this.draftOwner !== owner) {
            this.attachments.clear();
        }
        this.draftOwner = owner;
    };

    AssistantApp.prototype.activateView = function (view) {
        this.activeView = view || null;
    };

    AssistantApp.prototype.isActiveView = function (view) {
        return this.activeView === view;
    };

    AssistantApp.prototype.isDraftOwner = function (view) {
        return Boolean(view && this.draftOwner === view);
    };

    AssistantApp.prototype.selectFiles = function (files, owner) {
        if (this.canAttach()) {
            this.claimDraftOwner(owner);
            this.attachments.select(files);
        }
    };

    AssistantApp.prototype.removeAttachment = function (id, owner) {
        if (['sending', 'recovering'].indexOf(this.store.getState().phase) === -1) {
            if (this.draftOwner && owner && this.draftOwner !== owner) {
                return;
            }
            this.attachments.remove(String(id || ''));
        }
    };

    AssistantApp.prototype.notice = function (message) {
        this.store.dispatch({ type: 'SET_STATUS', message: String(message || '') });
    };

    AssistantApp.prototype.browserStorageStatus = function () {
        var status = Runtime.BrowserStorage.status();
        return Object.freeze({
            local: status.local,
            session: status.session,
            current_tab_chat: true,
            current_tab_retry: true,
            refresh_continuity: status.refresh_continuity,
            unresolved_refresh_recovery: status.unresolved_refresh_recovery,
            cross_tab_continuity: status.cross_tab_continuity,
            server_idempotency_authoritative: true
        });
    };

    AssistantApp.prototype.noticeBrowserStorageDegraded = function () {
        if (this.browserStorageDegradedNotified === true) {
            return;
        }
        var status = this.browserStorageStatus();
        if (status.local === 'persistent' && status.session === 'persistent') {
            return;
        }
        this.browserStorageDegradedNotified = true;
        this.notice(util.text(
            'browserStorageDegraded',
            'يمكنك متابعة المحادثة في هذه الصفحة بأمان، لكن الاستمرارية بعد إعادة التحميل أو بين علامات التبويب محدودة لأن تخزين المتصفح غير متاح.'
        ));
    };

    AssistantApp.prototype.retryRetentionFailureMessage = function () {
        var reason = typeof this.retryStore.lastFailureReason === 'function'
            ? this.retryStore.lastFailureReason()
            : '';
        if (reason === 'unresolved_turn_active') {
            return util.text(
                'turnRetryPending',
                'تعذر التحقق من نتيجة الطلب. أعد المحاولة نفسها قبل متابعة المحادثة.'
            );
        }
        if (reason === 'retry_envelope_expired' || reason === 'retry_envelope_missing') {
            return util.text(
                'retryExpired',
                'انتهت صلاحية إعادة المحاولة المحفوظة. أرسل الطلب مرة أخرى.'
            );
        }
        return util.text(
            'retryRetentionFailed',
            'تعذر الاحتفاظ بالطلب الحالي لإعادة المحاولة الآمنة. لم يتم إرساله.'
        );
    };

    AssistantApp.prototype.exportConversation = function () {
        var self = this;
        var state = this.store.getState();
        if (this.privacyPromise || state.phase !== 'ready' || !state.sessionToken || !state.conversation) {
            return this.privacyPromise || Promise.resolve(false);
        }
        var credentials = {
            conversation_id: String(state.conversation.id || ''),
            conversation_token: String(state.conversation.token || '')
        };
        var combined = null;
        var cursor = null;
        var seen = {};
        var pages = 0;
        this.store.dispatch({
            type: 'PRIVACY_START',
            message: util.text('exportingConversation', 'جارٍ تجهيز ملف المحادثة…')
        });

        function nextPage() {
            var payload = Object.assign({}, credentials);
            if (cursor !== null) { payload.cursor = cursor; }
            return self.api.exportConversation(payload, state.sessionToken).then(function (response) {
                var page = exportPage(response);
                pages += 1;
                if (pages > 10000) {
                    throw new Runtime.ApiError(
                        util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة.'),
                        'response_contract_invalid', 502, 0
                    );
                }
                if (combined === null) {
                    combined = {
                        schema: page.schema,
                        conversation_id: page.conversation_id,
                        created_at: page.created_at,
                        updated_at: page.updated_at,
                        expires_at: page.expires_at,
                        state: page.state
                    };
                    EXPORT_ROW_FIELDS.forEach(function (field) { combined[field] = []; });
                } else if (combined.conversation_id !== page.conversation_id
                    || combined.created_at !== page.created_at
                    || combined.updated_at !== page.updated_at
                    || combined.expires_at !== page.expires_at
                    || JSON.stringify(combined.state) !== JSON.stringify(page.state)
                ) {
                    throw new Runtime.ApiError(
                        util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة.'),
                        'response_contract_invalid', 502, 0
                    );
                }
                EXPORT_ROW_FIELDS.forEach(function (field) {
                    combined[field] = combined[field].concat(page[field]);
                });
                if (page.complete) { return combined; }
                if (seen[page.next_cursor]) {
                    throw new Runtime.ApiError(
                        util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة.'),
                        'response_contract_invalid', 502, 0
                    );
                }
                seen[page.next_cursor] = true;
                cursor = page.next_cursor;
                return nextPage();
            });
        }

        this.privacyPromise = nextPage().then(function (documentData) {
            if (typeof window.Blob !== 'function' || !window.URL
                || typeof window.URL.createObjectURL !== 'function'
            ) {
                throw new Runtime.ApiError(
                    util.text('genericFailure', 'تعذر إنشاء ملف المحادثة في هذا المتصفح.'),
                    'client_capability_invalid', 0, 0
                );
            }
            var blob = new window.Blob(
                [JSON.stringify(documentData, null, 2)],
                { type: 'application/json;charset=utf-8' }
            );
            var url = window.URL.createObjectURL(blob);
            var anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = 'yassin-assistant-conversation-' + documentData.conversation_id + '.json';
            anchor.hidden = true;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 0);
            self.store.dispatch({
                type: 'PRIVACY_END',
                message: util.text('conversationExported', 'تم تنزيل ملف المحادثة.')
            });
            self.privacyPromise = null;
            return true;
        }).catch(function (error) {
            self.store.dispatch({
                type: 'PRIVACY_END',
                message: error && error.message
                    ? error.message
                    : util.text('genericFailure', 'تعذر إكمال الطلب بأمان.')
            });
            self.privacyPromise = null;
            return false;
        });
        return this.privacyPromise;
    };

    AssistantApp.prototype.deleteConversation = function () {
        var self = this;
        var state = this.store.getState();
        if (this.privacyPromise || state.phase !== 'ready' || !state.sessionToken || !state.conversation) {
            return this.privacyPromise || Promise.resolve(false);
        }
        if (!window.confirm(util.text(
            'confirmDeleteConversation',
            'هل تريد حذف هذه المحادثة نهائياً؟ لا يمكن التراجع عن الحذف.'
        ))) {
            return Promise.resolve(false);
        }
        this.store.dispatch({
            type: 'PRIVACY_START',
            message: util.text('deletingConversation', 'جارٍ حذف المحادثة…')
        });
        this.privacyPromise = this.api.deleteConversation({
            conversation_id: String(state.conversation.id || ''),
            conversation_token: String(state.conversation.token || '')
        }, state.sessionToken).then(function (response) {
            if (!util.isRecord(response) || response.ok !== true || response.deleted !== true
                || Object.keys(response).sort().join('|') !== 'deleted|ok'
            ) {
                throw new Runtime.ApiError(
                    util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة.'),
                    'response_contract_invalid', 502, 0
                );
            }
            self.continuity.clear();
            self.resetConversationState();
            return self.boot(true).then(function () {
                var nextState = self.store.getState();
                if (nextState.phase !== 'ready' || !nextState.conversation) {
                    throw new Runtime.ApiError(
                        util.text(
                            'conversationDeletedBootFailed',
                            'تم حذف المحادثة، لكن تعذر بدء محادثة جديدة. أعد تحميل الصفحة.'
                        ),
                        'conversation_reboot_failed', 503, 0
                    );
                }
                self.privacyPromise = null;
                self.notice(util.text(
                    'conversationDeleted',
                    'تم حذف المحادثة وبدء محادثة جديدة.'
                ));
                return true;
            });
        }).catch(function (error) {
            self.store.dispatch({
                type: 'PRIVACY_END',
                message: error && error.message
                    ? error.message
                    : util.text('genericFailure', 'تعذر إكمال الطلب بأمان.')
            });
            self.privacyPromise = null;
            return false;
        });
        return this.privacyPromise;
    };

    AssistantApp.prototype.submitMessage = function (message, presentation, owner, replyContext) {
        var state = this.store.getState();
        if (state.phase !== 'ready' || state.privacyBusy === true
            || !state.sessionToken || !state.conversation
        ) {
            this.ensureReady();
            this.notice(util.text('loading', 'جارٍ بدء المساعد…'));
            return false;
        }
        var ownsSharedDraft = this.isDraftOwner(owner);
        if (ownsSharedDraft && this.attachments.hasPending()) {
            this.notice(util.text('imageReading', 'جارٍ تجهيز الصورة…'));
            return false;
        }
        var attachments = ownsSharedDraft ? this.attachments.readyPayloads() : [];
        var exactMessage = String(message || '');
        if (util.codePointLength(exactMessage) > Contract.limits.messageMaxChars) {
            this.notice(util.text('messageTooLong', 'الرسالة أطول من الحد المسموح.'));
            return false;
        }
        if (!exactMessage.trim() && attachments.length === 0) {
            this.notice(util.text('empty', 'اكتب رسالة أو أرفق صورة.'));
            return false;
        }
        var visible = visibleTurnText(exactMessage, attachments.length);
        return this.startTurn(
            exactMessage,
            visible,
            attachments,
            presentation,
            replyContext
        );
    };

    AssistantApp.prototype.startTurn = function (
        message,
        visibleText,
        attachments,
        presentation,
        replyContext
    ) {
        var state = this.store.getState();
        var turnId = util.randomId();
        var payload = {
            conversation_id: String(state.conversation.id),
            conversation_token: String(state.conversation.token),
            client_turn_id: turnId,
            message: String(message || ''),
            attachments: Array.isArray(attachments) ? attachments : []
        };
        if (replyContext && typeof replyContext.text === 'string'
            && replyContext.text.trim()
        ) {
            payload.reply_context = {
                text: util.sliceCodePoints(replyContext.text, 0, Contract.replyContext.textMaxChars)
            };
            if (typeof replyContext.message_id === 'string'
                && /^[a-f0-9-]{36}$/i.test(replyContext.message_id)
                && Number.isInteger(replyContext.product_index)
                && replyContext.product_index >= 0
                && replyContext.product_index <= Contract.replyContext.productIndexMax
            ) {
                payload.reply_context.message_id = replyContext.message_id.toLowerCase();
                payload.reply_context.product_index = replyContext.product_index;
            }
        }
        var serialized = this.api.envelope(payload);
        var userMessage = {
            id: 'local-' + turnId,
            turn_id: turnId,
            role: 'user',
            outcome: '',
            text: String(visibleText || ''),
            products: [],
            state_uncertain: false,
            created_at: util.now()
        };
        var visual = localMessagePresentation(presentation, this.attachments.readyPreviews());
        if (Object.keys(visual).length > 0) {
            userMessage.presentation = visual;
        }
        var envelope = Object.freeze({
            body: serialized.body,
            visibleText: String(visibleText || '').slice(0, 4000)
        });
        var retryId = 'retry-' + util.randomId();
        if (!this.retryStore.put(
            retryId,
            envelope,
            Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS
        )) {
            this.notice(this.retryRetentionFailureMessage());
            return false;
        }
        var startedAt = Date.now();
        if (!this.continuity.writePending({
            turn_id: turnId,
            conversation_id: String(state.conversation.id),
            retry_id: retryId,
            started_at_ms: startedAt,
            guard_until_ms: startedAt + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS + 5000
        })) {
            this.retryStore.remove(retryId);
            this.notice(util.text(
                'invalidResponse',
                'تعذر إنشاء هوية آمنة للطلب الحالي. لم يتم إرساله.'
            ));
            return false;
        }
        this.noticeBrowserStorageDegraded();
        this.store.dispatch({ type: 'TURN_START', userMessage: userMessage });
        this.attachments.clear();
        this.draftOwner = null;
        this.performTurn(
            envelope,
            retryId,
            0,
            Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS,
            false
        );
        return true;
    };

    AssistantApp.prototype.recordTurnFailure = function (error, envelope, retryId, forceRetryable, wasRetry) {
        var safe = error && error.message
            ? String(error.message)
            : util.text('genericFailure', 'تعذر إكمال الطلب بأمان.');
        var retryable = forceRetryable === true || this.api.isRetryable(error);
        if (retryId && wasRetry === true) {
            if (!retryable || !this.retryStore.has(retryId)) {
                this.retryStore.remove(retryId);
                retryable = false;
            }
            this.store.dispatch({
                type: 'RETRY_FAILURE',
                retryId: retryId,
                message: safe,
                retryable: retryable
            });
            return;
        }

        var newRetryId = retryable && retryId && this.retryStore.has(retryId)
            ? retryId
            : '';
        if (!newRetryId && retryId) {
            this.retryStore.remove(retryId);
        }
        this.store.dispatch({
            type: 'TURN_FAILURE',
            message: safe,
            retryId: newRetryId,
            retryIdentity: newRetryId
                ? this.retryIdentityForEnvelope(envelope)
                : null
        });
    };

    AssistantApp.prototype.sameConversation = function (expected, actual) {
        return Boolean(expected && actual
            && String(expected.id || '') === String(actual.id || '')
            && String(expected.token || '') === String(actual.token || '')
        );
    };

    AssistantApp.prototype.persistConversation = function (conversation) {
        if (!this.config.storageKey || !this.sessionContinuitySecret) {
            return false;
        }
        try {
            var current = this.browserContinuity.credentials();
            if (current.secret !== this.sessionContinuitySecret) {
                return false;
            }
            return this.continuity.write(conversation);
        } catch (error) {
            // Browser continuity persistence must not reclassify an already
            // accepted server response as a transport failure.
            return false;
        }
    };

    AssistantApp.prototype.conversationFromContinuity = function (continuity) {
        if (!continuity || typeof continuity !== 'object') {
            return null;
        }
        var id = String(continuity.conversation_id || '');
        var token = String(continuity.conversation_token || '');
        return id && token ? { id: id, token: token } : null;
    };

    AssistantApp.prototype.recoverInvalidConversation = function (
        envelope,
        retryId,
        wasRetry,
        originalError
    ) {
        var self = this;
        var recoveryCount = Number((envelope && envelope.conversationRecoveryCount) || 0);
        if (!envelope || recoveryCount >= 1) {
            this.recordTurnFailure(originalError, envelope, retryId, false, wasRetry === true);
            return Promise.resolve(false);
        }
        var pendingUserMessage = this.pendingUserMessageForEnvelope(envelope);
        var current = this.store.getState().conversation;
        var shared = this.continuity.read();
        var sharedConversation = this.conversationFromContinuity(shared);
        var replacementBoot;
        if (sharedConversation && !this.sameConversation(current, sharedConversation)) {
            replacementBoot = this.boot(false, shared);
        } else {
            // A valid 401 conversation_invalid is emitted only before turn
            // admission. Preserve the customer envelope locally, discard the
            // stale public conversation pair, and obtain canonical replacement
            // authority without rotating the browser bearer.
            this.continuity.clear();
            this.resetConversationState();
            replacementBoot = this.boot(false, {});
        }
        return Promise.resolve(replacementBoot).then(function () {
            var state = self.store.getState();
            if (state.phase !== 'ready' || !state.sessionToken || !state.conversation) {
                if (pendingUserMessage) {
                    self.store.dispatch({ type: 'TURN_START', userMessage: pendingUserMessage });
                }
                self.recordTurnFailure(originalError, envelope, retryId, false, wasRetry === true);
                return false;
            }
            var rebound = self.rebindEnvelopeForConversation(
                envelope,
                state.conversation,
                recoveryCount + 1
            );
            var retainedRetryId = String(retryId || ('retry-' + util.randomId()));
            var turnId = self.turnIdForEnvelope(rebound);
            var startedAt = Date.now();
            if (!rebound) {
                if (pendingUserMessage) {
                    self.store.dispatch({ type: 'TURN_START', userMessage: pendingUserMessage });
                }
                self.recordTurnFailure(
                    new Runtime.ApiError(
                        util.text('invalidResponse', 'تعذر التحقق من استجابة الخادم.'),
                        'client_response_invalid',
                        0,
                        0
                    ),
                    envelope,
                    retainedRetryId,
                    false,
                    wasRetry === true
                );
                return false;
            }
            if (!self.retryStore.put(
                retainedRetryId,
                rebound,
                Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS
            )) {
                if (pendingUserMessage) {
                    self.store.dispatch({ type: 'TURN_START', userMessage: pendingUserMessage });
                }
                self.recordTurnFailure(
                    new Runtime.ApiError(
                        self.retryRetentionFailureMessage(),
                        'client_retry_retention_failed',
                        0,
                        0
                    ),
                    rebound,
                    retainedRetryId,
                    false,
                    wasRetry === true
                );
                return false;
            }
            if (!self.continuity.writePending({
                turn_id: turnId,
                conversation_id: String(state.conversation.id),
                retry_id: retainedRetryId,
                started_at_ms: startedAt,
                guard_until_ms: startedAt
                    + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS + 5000
            })) {
                self.retryStore.remove(retainedRetryId);
                if (pendingUserMessage) {
                    self.store.dispatch({ type: 'TURN_START', userMessage: pendingUserMessage });
                }
                self.recordTurnFailure(
                    new Runtime.ApiError(
                        util.text(
                            'invalidResponse',
                            'تعذر التحقق من هوية الطلب الآمن. لم يتم إرساله.'
                        ),
                        'client_pending_identity_invalid',
                        0,
                        0
                    ),
                    rebound,
                    retainedRetryId,
                    false,
                    wasRetry === true
                );
                return false;
            }
            self.noticeBrowserStorageDegraded();
            if (pendingUserMessage) {
                self.store.dispatch({ type: 'TURN_START', userMessage: pendingUserMessage });
            }
            return self.performTurn(
                rebound,
                retainedRetryId,
                0,
                Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS,
                wasRetry === true
            );
        });
    };

    AssistantApp.prototype.rebindEnvelopeForConversation = function (
        envelope,
        conversation,
        recoveryCount
    ) {
        try {
            var body = JSON.parse(String((envelope && envelope.body) || ''));
            if (!util.isRecord(body)
                || !conversation
                || typeof conversation.id !== 'string'
                || typeof conversation.token !== 'string'
                || typeof body.client_turn_id !== 'string'
                || typeof body.message !== 'string'
                || !Array.isArray(body.attachments)
            ) {
                return null;
            }
            body.conversation_id = conversation.id;
            body.conversation_token = conversation.token;
            if (util.isRecord(body.reply_context)) {
                // A product-card locator belongs to the expired conversation.
                // Preserve its customer-visible quote as context, but require
                // the new turn to rediscover live product authority.
                body.reply_context = { text: String(body.reply_context.text || '') };
            }
            return Object.freeze({
                body: JSON.stringify(body),
                visibleText: String((envelope && envelope.visibleText) || '').slice(0, 4000),
                conversationRecoveryCount: Math.max(1, Number(recoveryCount || 1))
            });
        } catch (error) {
            return null;
        }
    };

    AssistantApp.prototype.turnIdForEnvelope = function (envelope) {
        try {
            var body = JSON.parse(String((envelope && envelope.body) || ''));
            return body && typeof body.client_turn_id === 'string'
                ? body.client_turn_id.toLowerCase()
                : '';
        } catch (error) {
            return '';
        }
    };

    AssistantApp.prototype.retryIdentityForEnvelope = function (envelope) {
        try {
            var body = JSON.parse(String((envelope && envelope.body) || ''));
            var turnId = body && typeof body.client_turn_id === 'string'
                ? body.client_turn_id.toLowerCase()
                : '';
            var conversation = {
                id: body && typeof body.conversation_id === 'string'
                    ? body.conversation_id
                    : '',
                token: body && typeof body.conversation_token === 'string'
                    ? body.conversation_token
                    : ''
            };
            if (!turnId || !conversation.id || !conversation.token) {
                return null;
            }
            var pending = this.continuity.readPending();
            var identity = { turnId: turnId, conversation: conversation };
            if (pending && pending.turn_id === turnId
                && pending.conversation_id === String(conversation.id).toLowerCase()
            ) {
                identity.startedAtMs = pending.started_at_ms;
                identity.guardUntilMs = pending.guard_until_ms;
            }
            return identity;
        } catch (error) {
            return null;
        }
    };

    AssistantApp.prototype.canonicalTurnIsComplete = function (messages, turnId) {
        if (!turnId) {
            return false;
        }
        var user = false;
        var assistant = false;
        (Array.isArray(messages) ? messages : []).forEach(function (message) {
            if (!message || String(message.turn_id || '').toLowerCase() !== turnId) {
                return;
            }
            user = user || message.role === 'user';
            assistant = assistant || message.role === 'assistant';
        });
        return user && assistant;
    };

    AssistantApp.prototype.pendingUserMessageForEnvelope = function (envelope) {
        var state = this.store.getState();
        var turnId = this.turnIdForEnvelope(envelope);
        if (state.activeUserMessage && typeof state.activeUserMessage === 'object'
            && String(state.activeUserMessage.turn_id || '').toLowerCase() === turnId
        ) {
            return state.activeUserMessage;
        }
        if (!turnId) {
            return null;
        }
        var localId = 'local-' + turnId;
        var found = null;
        state.messages.some(function (message) {
            if (message && message.role === 'user'
                && String(message.id || '').toLowerCase() === localId
                && String(message.turn_id || '').toLowerCase() === turnId
            ) {
                found = message;
                return true;
            }
            return false;
        });
        if (!found && typeof envelope.visibleText === 'string' && envelope.visibleText) {
            found = {
                id: localId,
                turn_id: turnId,
                role: 'user',
                outcome: '',
                text: envelope.visibleText,
                products: [],
                state_uncertain: false,
                created_at: util.now()
            };
        }
        return found;
    };

    AssistantApp.prototype.refreshSessionAndRetry = function (
        envelope,
        retryId,
        refreshCount,
        deadlineAt,
        wasRetry
    ) {
        var self = this;
        if (this.sessionRefreshPromise) {
            return this.sessionRefreshPromise;
        }

        var expected = this.store.getState().conversation;
        if (!expected) {
            return this.boot(true);
        }

        this.store.dispatch({ type: 'SESSION_REFRESH_START' });
        var continuity = {
            conversation_id: String(expected.id || ''),
            conversation_token: String(expected.token || '')
        };
        var continuityCredentials = this.browserContinuity.credentials();
        var bootRequest = Object.assign({}, continuity, {
            client_instance_id: this.clientIdentity.id(),
            browser_continuity_secret: continuityCredentials.secret,
            pending_turn_id: this.turnIdForEnvelope(envelope)
        });
        if (continuityCredentials.previous_secret) {
            bootRequest.previous_browser_continuity_secret = continuityCredentials.previous_secret;
        }
        var operation = this.api.boot(bootRequest).then(function (payload) {
            var result = Runtime.contracts.boot(payload, self.config);
            self.browserContinuity.acknowledge(continuityCredentials.secret);
            self.sessionContinuitySecret = continuityCredentials.secret;
            var pendingTurnId = self.turnIdForEnvelope(envelope);
            if (!result.pendingTurn || result.pendingTurn.id !== pendingTurnId) {
                throw new Runtime.ApiError(
                    util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بأمان.'),
                    'response_contract_invalid',
                    502,
                    0
                );
            }
            if (!self.sameConversation(expected, result.conversation)) {
                self.retryStore.clear();
                self.acceptBootResult(result);
                self.notice(util.text(
                    'conversationReset',
                    'انتهت المحادثة السابقة. ابدأ طلباً جديداً.'
                ));
                return;
            }

            var alreadyCanonical = self.canonicalTurnIsComplete(result.messages, pendingTurnId);
            if (result.pendingTurn.status === 'terminal' && !alreadyCanonical) {
                throw new Runtime.ApiError(
                    util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بأمان.'),
                    'response_contract_invalid',
                    502,
                    0
                );
            }
            var pendingUserMessage = alreadyCanonical
                ? null
                : self.pendingUserMessageForEnvelope(envelope);
            if (alreadyCanonical && retryId) {
                self.continuity.clearPending(pendingTurnId);
                self.retryStore.remove(retryId);
            }
            if (result.pendingTurn.status === 'pending') {
                self.acceptBootResult(
                    result,
                    true,
                    self.retryIdentityForEnvelope(envelope)
                );
                return;
            }
            self.persistConversation(result.conversation);
            self.attachments.setLimits(result.capabilities.max_images, result.capabilities.max_image_bytes);
            self.store.dispatch({
                type: 'SESSION_REFRESH_SUCCESS',
                sessionToken: result.sessionToken,
                conversation: result.conversation,
                messages: result.messages,
                pendingUserMessage: pendingUserMessage,
                cart: result.cart,
                cartAvailable: result.cartAvailable,
                cartNotice: result.cartNotice,
                capabilities: result.capabilities,
                widget: result.widget,
                retrying: Boolean(retryId),
                resumePending: !alreadyCanonical
            });
            if (alreadyCanonical) {
                return;
            }
            return self.performTurn(envelope, retryId, refreshCount + 1, deadlineAt, wasRetry);
        });

        this.sessionRefreshPromise = operation.then(function (value) {
            self.sessionRefreshPromise = null;
            return value;
        }, function (error) {
            self.sessionRefreshPromise = null;
            if (error && error.status === 401 && error.code === 'conversation_invalid') {
                return self.recoverInvalidConversation(
                    envelope,
                    retryId,
                    wasRetry,
                    error
                );
            }
            self.recordTurnFailure(error, envelope, retryId, false, wasRetry);
        });
        return this.sessionRefreshPromise;
    };

    AssistantApp.prototype.performTurn = function (envelope, retryId, refreshCount, deadlineAt, wasRetry) {
        var self = this;
        var state = this.store.getState();
        var sessionToken = state.sessionToken;
        var refreshed = Number(refreshCount || 0);
        var deadline = Number(deadlineAt || 0);
        var retrying = wasRetry === true;
        if (retryId && !this.retryStore.protect(retryId, Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS)) {
            this.continuity.clearPending(this.turnIdForEnvelope(envelope));
            this.retryStore.remove(retryId);
            this.recordTurnFailure(
                new Runtime.ApiError(
                    util.text(
                        'retryExpired',
                        'انتهت صلاحية إعادة المحاولة المحفوظة. أرسل الطلب مرة أخرى.'
                    ),
                    'client_capability_invalid',
                    0,
                    0
                ),
                envelope,
                retryId,
                false,
                retrying
            );
            return Promise.resolve(false);
        }
        if (retryId) {
            this.noticeBrowserStorageDegraded();
        }
        if (retryId && refreshed === 0 && retrying) {
            this.store.dispatch({ type: 'RETRY_START' });
        }
        return this.api.sendTurn(envelope, sessionToken, 0, deadline).then(function (response) {
            var result = Runtime.contracts.turn(response);
            var expectedTurnId = self.turnIdForEnvelope(envelope);
            if (!self.sameConversation(state.conversation, result.conversation)
                || !expectedTurnId
                || String(result.message.turn_id || '').toLowerCase() !== expectedTurnId
            ) {
                throw new Runtime.ApiError(
                    util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بنفس الطلب بأمان.'),
                    'response_contract_invalid',
                    502,
                    0
                );
            }
            var pendingUserMessage = self.pendingUserMessageForEnvelope(envelope);
            self.continuity.clearPending(expectedTurnId);
            if (retryId) {
                self.retryStore.remove(retryId);
            }
            self.persistConversation(result.conversation);
            self.store.dispatch({
                type: 'TURN_SUCCESS',
                message: result.message,
                turnCommitted: result.turnCommitted,
                conversation: result.conversation,
                messages: result.messages,
                messagesAvailable: result.messagesAvailable,
                messagesNotice: result.messagesNotice,
                pendingUserMessage: pendingUserMessage,
                removeRetryId: retryId,
                cartAvailable: result.cartAvailable,
                cartNotice: result.cartNotice,
                cart: result.cart,
                cartMutations: result.cartMutations
            });
            var projectionNotices = [result.messagesNotice].filter(function (notice, index, rows) {
                return String(notice || '').trim() !== '' && rows.indexOf(notice) === index;
            });
            if (projectionNotices.length > 0) {
                self.notice(projectionNotices.join(' '));
            }
        }).catch(function (error) {
            if (error && error.status === 401 && error.code === 'session_invalid' && refreshed < 1) {
                return self.refreshSessionAndRetry(
                    envelope,
                    retryId,
                    refreshed,
                    deadline,
                    retrying
                );
            }
            if (error && error.status === 401 && error.code === 'conversation_invalid') {
                return self.recoverInvalidConversation(
                    envelope,
                    retryId,
                    retrying,
                    error
                );
            }
            if (error && error.status === 401 && error.code === 'session_invalid' && refreshed >= 1) {
                // A second credential failure does not prove the conversation
                // itself is invalid. Keep its authority and allow one explicit
                // exact retry instead of silently forking visible history.
                self.recordTurnFailure(error, envelope, retryId, true, retrying);
                return;
            }
            if (error && error.status >= 400 && error.status < 500
                && !self.api.isRetryable(error)
            ) {
                self.continuity.clearPending(self.turnIdForEnvelope(envelope));
            }
            self.recordTurnFailure(error, envelope, retryId, false, retrying);
        });
    };

    AssistantApp.prototype.retry = function (retryId) {
        var id = String(retryId || '');
        var state = this.store.getState();
        var envelope = this.retryStore.get(id);
        if (!id || !envelope) {
            this.store.dispatch({ type: 'RETRY_STORAGE_EVICTED', retryIds: id ? [id] : [] });
            this.notice(util.text(
                'retryExpired',
                'انتهت صلاحية إعادة المحاولة المحفوظة. أرسل الطلب مرة أخرى.'
            ));
            return false;
        }
        var blockedExactRetry = state.phase === 'blocked' && Boolean(envelope);
        if ((state.phase === 'ready' || blockedExactRetry) && state.sessionToken) {
            this.performTurn(
                envelope,
                id,
                0,
                Date.now() + Runtime.ClientRecoveryPolicy.TURN_TIMEOUT_MS,
                true
            );
            return true;
        }
        return false;
    };

    Runtime.AssistantApp = AssistantApp;
}(window));
