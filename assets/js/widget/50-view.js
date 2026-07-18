(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var util = Runtime.util;
    var Contract = Runtime.publicContract;
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var mobileModalLocks = [];

    function setMobileModalLock(view, locked) {
        var index = mobileModalLocks.indexOf(view);
        if (locked && index === -1) {
            mobileModalLocks.push(view);
        } else if (!locked && index !== -1) {
            mobileModalLocks.splice(index, 1);
        }
        var active = mobileModalLocks.length > 0;
        document.documentElement.classList.toggle('ysai-widget-modal-open', active);
        if (document.body) {
            document.body.classList.toggle('ysai-widget-modal-open', active);
        }
    }

    function iconNode(name) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.classList.add('ysai-icon');

        function path(d, fill) {
            var node = document.createElementNS(SVG_NS, 'path');
            node.setAttribute('d', d);
            node.setAttribute('fill', fill || 'none');
            node.setAttribute('stroke', 'currentColor');
            node.setAttribute('stroke-width', '1.8');
            node.setAttribute('stroke-linecap', 'round');
            node.setAttribute('stroke-linejoin', 'round');
            svg.appendChild(node);
        }

        if (name === 'chat') {
            path('M7.5 18.5 4 20l1.2-3.6A8 8 0 1 1 20 12a8 8 0 0 1-12.5 6.5Z');
            path('M8 10.5h8M8 14h5');
        } else if (name === 'close') {
            path('m7 7 10 10M17 7 7 17');
        } else if (name === 'image') {
            path('M4.5 5.5h15v13h-15z');
            path('m5 16 4.2-4.2 3.1 3.1 2.1-2.1 5.1 5.1');
            path('M15.8 9.2h.1');
        } else if (name === 'send') {
            path('M12 19V5');
            path('m6.5 10.5 5.5-5.5 5.5 5.5');
        } else if (name === 'copy') {
            path('M9 8h9v11H9z');
            path('M6 16H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v1');
        } else if (name === 'reply') {
            path('m10 8-5 4 5 4');
            path('M6 12h6.5a5.5 5.5 0 0 1 5.5 5.5V19');
        } else if (name === 'chevron-left') {
            path('m14.5 6-6 6 6 6');
        } else if (name === 'chevron-right') {
            path('m9.5 6 6 6-6 6');
        } else if (name === 'remove') {
            path('m8 8 8 8M16 8l-8 8');
        } else if (name === 'privacy') {
            path('M12 3.5 19 6v5.2c0 4.4-2.8 7.5-7 9.3-4.2-1.8-7-4.9-7-9.3V6z');
            path('M9.5 12h5M12 9.5v5');
        } else if (name === 'product') {
            path('M5 7.5 12 4l7 3.5v9L12 20l-7-3.5z');
            path('M5 7.5 12 11l7-3.5M12 11v9');
        }
        return svg;
    }

    function iconButton(className, icon, label) {
        var button = util.create('button', className);
        button.type = 'button';
        button.setAttribute('aria-label', label);
        button.title = label;
        button.appendChild(iconNode(icon));
        return button;
    }

    function presentationImageSource(value) {
        var source = String(value || '').trim();
        if (/^data:image\/(?:jpeg|png|webp);base64,[a-z0-9+/=\s]+$/i.test(source)) {
            return source;
        }
        return util.safeUrl(source);
    }

    function WidgetView(root, app) {
        this.root = root;
        this.app = app;
        this.opened = false;
        this.lastMessages = null;
        this.lastEmptyStateHint = null;
        this.lastCart = null;
        this.lastCartNotice = null;
        this.lastAttachments = null;
        this.copyTimer = null;
        this.copySequence = 0;
        this.confirmedCopyButton = null;
        this.destroyed = false;
        this.carouselObservers = [];
        this.replyContext = null;
        this.previousFocus = null;
        this.mobileModalQuery = typeof window.matchMedia === 'function'
            ? window.matchMedia('(max-width: 520px)')
            : null;
        this.mobileModalListener = null;
        this.build();
        this.bind();
    }

    WidgetView.prototype.build = function () {
        this.root.dir = 'rtl';
        this.launcher = util.create('button', 'ysai-launcher');
        this.launcher.type = 'button';
        this.launcher.setAttribute('aria-expanded', 'false');
        this.launcher.setAttribute('aria-label', util.text('open', 'مساعدة التسوق'));
        this.launcher.setAttribute('aria-controls', this.root.id + '-panel');
        this.launcherIcon = util.create('span', 'ysai-launcher-icon');
        this.launcherIcon.appendChild(iconNode('chat'));
        this.launcherLabel = util.create('span', 'ysai-launcher-label', util.text('open', 'مساعدة التسوق'));
        this.launcher.appendChild(this.launcherIcon);
        this.launcher.appendChild(this.launcherLabel);

        this.panel = util.create('section', 'ysai-panel');
        this.panel.dir = 'rtl';
        this.panel.hidden = true;
        this.panel.id = this.root.id + '-panel';
        this.panel.tabIndex = -1;
        this.panel.setAttribute('role', 'dialog');
        this.panel.setAttribute('aria-modal', 'true');
        this.panel.setAttribute('aria-label', util.text('open', 'مساعدة التسوق'));

        var header = util.create('header', 'ysai-header');
        var brandMark = util.create('span', 'ysai-brand-mark');
        brandMark.setAttribute('aria-hidden', 'true');
        var siteIconUrl = util.safeUrl(Runtime.config.siteIconUrl);
        if (siteIconUrl) {
            var brandAvatar = util.create('img', 'ysai-brand-avatar');
            brandAvatar.src = siteIconUrl;
            brandAvatar.alt = '';
            brandAvatar.decoding = 'async';
            brandAvatar.addEventListener('error', function () {
                brandMark.classList.remove('has-site-icon');
                brandMark.textContent = '';
                brandMark.appendChild(iconNode('chat'));
            }, { once: true });
            brandMark.classList.add('has-site-icon');
            brandMark.appendChild(brandAvatar);
        } else {
            brandMark.appendChild(iconNode('chat'));
        }
        var heading = util.create('div', 'ysai-heading');
        this.title = util.create('strong', 'ysai-title', util.text('open', 'مساعدة التسوق'));
        this.presence = util.create('span', 'ysai-presence');
        this.presenceDot = util.create('span', 'ysai-presence-dot');
        this.presenceDot.setAttribute('aria-hidden', 'true');
        this.presence.appendChild(this.presenceDot);
        this.configuredSubtitle = util.text('online', 'متصل الآن');
        this.subtitle = util.create('span', 'ysai-subtitle', this.configuredSubtitle);
        this.subtitle.setAttribute('aria-live', 'off');
        this.subtitle.setAttribute('aria-atomic', 'true');
        this.presence.appendChild(this.subtitle);
        heading.appendChild(this.title);
        heading.appendChild(this.presence);
        this.closeButton = iconButton('ysai-close', 'close', util.text('close', 'إغلاق المساعد'));
        this.privacyButton = iconButton(
            'ysai-privacy-toggle',
            'privacy',
            util.text('privacy', 'بيانات المحادثة')
        );
        this.privacyButton.setAttribute('aria-expanded', 'false');
        header.appendChild(brandMark);
        header.appendChild(heading);
        header.appendChild(this.privacyButton);
        header.appendChild(this.closeButton);

        this.privacyPanel = util.create('div', 'ysai-privacy-panel');
        this.privacyPanel.hidden = true;
        this.privacyPanel.setAttribute('role', 'group');
        this.privacyPanel.setAttribute('aria-label', util.text('privacy', 'بيانات المحادثة'));
        this.exportConversationButton = util.create(
            'button',
            'ysai-privacy-action',
            util.text('exportConversation', 'تصدير المحادثة')
        );
        this.exportConversationButton.type = 'button';
        this.deleteConversationButton = util.create(
            'button',
            'ysai-privacy-action is-danger',
            util.text('deleteConversation', 'حذف المحادثة')
        );
        this.deleteConversationButton.type = 'button';
        this.privacyPanel.appendChild(this.exportConversationButton);
        this.privacyPanel.appendChild(this.deleteConversationButton);

        this.messagesNode = util.create('div', 'ysai-messages');
        this.messagesNode.setAttribute('role', 'log');
        this.messagesNode.setAttribute('aria-live', 'polite');
        this.messagesNode.setAttribute('aria-relevant', 'additions');

        this.cartNode = util.create('div', 'ysai-cart-summary');
        this.cartNode.hidden = true;
        this.previewNode = util.create('div', 'ysai-attachment-previews');
        this.previewNode.hidden = true;

        this.replyNode = util.create('div', 'ysai-reply-preview');
        this.replyNode.hidden = true;
        this.replyMedia = util.create('span', 'ysai-reply-preview-media');
        this.replyMedia.hidden = true;
        this.replyImage = util.create('img', 'ysai-reply-preview-image');
        this.replyImage.alt = '';
        this.replyMedia.appendChild(this.replyImage);
        var replyCopy = util.create('div', 'ysai-reply-preview-copy');
        this.replyLabel = util.create('strong', '', util.text('replyingTo', 'الرد على'));
        this.replyText = util.create('span', '');
        replyCopy.appendChild(this.replyLabel);
        replyCopy.appendChild(this.replyText);
        this.replyCancel = iconButton('ysai-reply-cancel', 'remove', util.text('cancelReply', 'إلغاء الرد'));
        this.replyNode.appendChild(this.replyMedia);
        this.replyNode.appendChild(replyCopy);
        this.replyNode.appendChild(this.replyCancel);

        this.form = util.create('form', 'ysai-composer');
        var composerRow = util.create('div', 'ysai-composer-row');
        this.sendButton = iconButton('ysai-send', 'send', util.text('send', 'إرسال'));
        this.sendButton.type = 'submit';

        var inputShell = util.create('div', 'ysai-input-shell');
        this.textarea = util.create('textarea', 'ysai-input');
        this.textarea.rows = 1;
        this.textarea.setAttribute('data-max-code-points', String(Contract.limits.messageMaxChars));
        this.textarea.placeholder = util.text('placeholder', 'اكتب رسالتك…');
        this.textarea.setAttribute('aria-label', this.textarea.placeholder);
        this.textarea.setAttribute('dir', 'auto');
        inputShell.appendChild(this.textarea);

        this.fileInput = util.create('input', 'ysai-file-input');
        this.fileInput.type = 'file';
        this.fileInput.accept = Contract.enums.imageMimeTypes.join(',');
        this.fileInput.multiple = true;
        this.fileInput.hidden = true;
        this.attachButton = iconButton('ysai-attach', 'image', util.text('attach', 'إرفاق صور'));

        composerRow.appendChild(this.sendButton);
        composerRow.appendChild(inputShell);
        composerRow.appendChild(this.fileInput);
        composerRow.appendChild(this.attachButton);
        this.form.appendChild(composerRow);

        this.statusNode = util.create('div', 'ysai-status-line');
        this.statusNode.hidden = true;
        this.statusNode.setAttribute('role', 'status');
        this.statusNode.setAttribute('aria-live', 'polite');

        this.panel.appendChild(header);
        this.panel.appendChild(this.privacyPanel);
        this.panel.appendChild(this.messagesNode);
        this.panel.appendChild(this.cartNode);
        this.panel.appendChild(this.previewNode);
        this.panel.appendChild(this.replyNode);
        this.panel.appendChild(this.statusNode);
        this.panel.appendChild(this.form);
        this.root.appendChild(this.launcher);
        this.root.appendChild(this.panel);
    };

    WidgetView.prototype.bind = function () {
        var self = this;
        this.launcher.addEventListener('click', function () { self.toggle(true); });
        this.closeButton.addEventListener('click', function () { self.toggle(false); });
        this.privacyButton.addEventListener('click', function () {
            var open = self.privacyPanel.hidden;
            self.privacyPanel.hidden = !open;
            self.privacyButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        this.exportConversationButton.addEventListener('click', function () {
            self.privacyPanel.hidden = true;
            self.privacyButton.setAttribute('aria-expanded', 'false');
            self.app.exportConversation();
        });
        this.deleteConversationButton.addEventListener('click', function () {
            self.privacyPanel.hidden = true;
            self.privacyButton.setAttribute('aria-expanded', 'false');
            self.app.deleteConversation();
        });
        this.replyCancel.addEventListener('click', function () { self.clearReplyContext(); });
        this.form.addEventListener('submit', function (event) {
            event.preventDefault();
            self.submitComposer();
        });
        this.textarea.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                self.submitComposer();
            }
        });
        this.textarea.addEventListener('input', function () {
            self.app.claimDraftOwner(self);
            if (util.codePointLength(self.textarea.value) > Contract.limits.messageMaxChars) {
                self.textarea.value = util.sliceCodePoints(self.textarea.value, 0, Contract.limits.messageMaxChars);
            }
            self.resizeComposer();
            self.updateComposerState();
        });
        this.attachButton.addEventListener('click', function () {
            if (self.app.canAttach()) {
                self.fileInput.click();
            }
        });
        this.fileInput.addEventListener('change', function () {
            self.app.selectFiles(Array.prototype.slice.call(self.fileInput.files || []), self);
            self.fileInput.value = '';
        });
        this.documentKeydown = function (event) {
            if (!self.opened) {
                return;
            }
            if (event.key === 'Escape') {
                if (self.app.isActiveView(self)) {
                    self.toggle(false);
                }
            } else if (event.key === 'Tab' && self.app.isActiveView(self)) {
                self.trapFocus(event);
            }
        };
        document.addEventListener('keydown', this.documentKeydown);
        this.panelInteraction = function () { self.app.activateView(self); };
        this.panel.addEventListener('focusin', this.panelInteraction);
        this.panel.addEventListener('pointerdown', this.panelInteraction);

        if (this.mobileModalQuery) {
            this.mobileModalListener = function () { self.syncModalState(); };
            if (typeof this.mobileModalQuery.addEventListener === 'function') {
                this.mobileModalQuery.addEventListener('change', this.mobileModalListener);
            } else if (typeof this.mobileModalQuery.addListener === 'function') {
                this.mobileModalQuery.addListener(this.mobileModalListener);
            }
        }
    };

    WidgetView.prototype.submitComposer = function () {
        this.updateComposerState();
        if (this.sendButton.disabled) {
            return;
        }
        var raw = String(this.textarea.value || '');
        var replyText = this.replyContext
            ? util.sliceCodePoints(
                String(this.replyContext.text || '').replace(/\s+/g, ' ').trim(),
                0,
                Contract.replyContext.textMaxChars
            )
            : '';
        var presentation = replyText ? { reply_quote: replyText } : {};
        if (replyText && this.replyContext.image) {
            presentation.quote = {
                image: this.replyContext.image,
                alt: this.replyContext.imageAlt
            };
        }
        var submittedReply = null;
        if (replyText) {
            submittedReply = { text: replyText };
            if (this.replyContext.messageId && Number.isInteger(this.replyContext.productIndex)) {
                submittedReply.message_id = String(this.replyContext.messageId);
                submittedReply.product_index = this.replyContext.productIndex;
            }
        }
        var accepted = this.app.submitMessage(
            raw,
            presentation,
            this,
            submittedReply
        );
        if (accepted) {
            this.textarea.value = '';
            this.resizeComposer();
            this.updateComposerState();
            this.clearReplyContext(false);
        }
    };

    WidgetView.prototype.resizeComposer = function () {
        this.textarea.style.height = 'auto';
        this.textarea.style.height = Math.min(120, Math.max(24, this.textarea.scrollHeight || 24)) + 'px';
    };

    WidgetView.prototype.updateComposerState = function (state) {
        var current = state || this.app.store.getState();
        var ownsSharedDraft = this.app.isDraftOwner(this);
        var rows = ownsSharedDraft && Array.isArray(current.attachments)
            ? current.attachments
            : [];
        var hasPendingAttachment = rows.some(function (attachment) {
            return attachment && attachment.status === 'reading';
        });
        var hasReadyAttachment = rows.some(function (attachment) {
            return attachment && attachment.status === 'ready' && attachment.data;
        });
        var hasText = String(this.textarea.value || '').trim() !== '';
        var ready = current.phase === 'ready';
        var canSend = ready && !hasPendingAttachment
            && (hasText || hasReadyAttachment);

        this.sendButton.disabled = !canSend;
        this.form.classList.toggle('has-draft', hasText || hasReadyAttachment);
        this.form.classList.toggle('has-pending-attachment', hasPendingAttachment);
        this.form.classList.toggle('has-image-input', current.capabilities.images === true);
    };

    WidgetView.prototype.toggle = function (open) {
        this.opened = Boolean(open);
        if (this.opened) {
            this.app.activateView(this);
        } else if (this.app.isActiveView(this)) {
            this.app.activateView(null);
        }
        this.panel.hidden = !this.opened;
        this.launcher.setAttribute('aria-expanded', this.opened ? 'true' : 'false');
        this.root.classList.toggle('is-open', this.opened);
        this.syncModalState();
        if (this.opened) {
            this.previousFocus = document.activeElement;
            var self = this;
            Promise.resolve(this.app.ensureReady()).then(function () {
                if (self.opened && self.app.store.getState().phase === 'ready') {
                    self.textarea.focus();
                } else if (self.opened) {
                    self.panel.focus();
                }
            });
        } else if (this.previousFocus && document.documentElement.contains(this.previousFocus)
            && typeof this.previousFocus.focus === 'function'
        ) {
            this.previousFocus.focus();
        } else {
            this.launcher.focus();
        }
    };


    WidgetView.prototype.syncModalState = function () {
        var locked = this.opened && this.mobileModalQuery && this.mobileModalQuery.matches;
        setMobileModalLock(this, Boolean(locked));
    };

    WidgetView.prototype.trapFocus = function (event) {
        var focusable = Array.prototype.filter.call(
            this.panel.querySelectorAll('button:not([disabled]), a[href], textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
            function (node) { return !node.hidden && node.offsetParent !== null; }
        );
        if (focusable.length === 0) {
            event.preventDefault();
            this.panel.focus();
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    WidgetView.prototype.render = function (state) {
        var busy = ['booting', 'sending', 'recovering'].indexOf(state.phase) !== -1;
        var ready = state.phase === 'ready' && state.privacyBusy !== true;
        var emptyStateHint = state.widget && state.widget.empty_state_hint;
        var nearBottom = this.messagesNode.scrollHeight - this.messagesNode.scrollTop - this.messagesNode.clientHeight < 80;
        var messagesChanged = this.lastMessages !== state.messages
            || this.lastEmptyStateHint !== emptyStateHint;
        this.applyWidgetConfig(state.widget || {});
        if (messagesChanged) {
            this.renderMessages(state.messages, emptyStateHint);
            this.lastMessages = state.messages;
            this.lastEmptyStateHint = emptyStateHint;
        }
        this.renderTyping(busy);
        var effectiveCartNotice = String(state.cartNotice || state.cartMutationNotice || '');
        if (this.lastCart !== state.cart || this.lastCartNotice !== effectiveCartNotice) {
            this.renderCart(state.cart, effectiveCartNotice);
            this.lastCart = state.cart;
            this.lastCartNotice = effectiveCartNotice;
        }
        var ownsSharedDraft = this.app.isDraftOwner(this);
        var visibleAttachments = ownsSharedDraft ? state.attachments : null;
        if (this.lastAttachments !== visibleAttachments) {
            this.renderAttachments(visibleAttachments);
            this.lastAttachments = visibleAttachments;
        }

        this.textarea.disabled = !ready;
        this.attachButton.disabled = !ready || !state.capabilities.images;
        this.attachButton.hidden = !state.capabilities.images;
        this.privacyButton.disabled = !state.conversation || busy || state.privacyBusy === true;
        this.exportConversationButton.disabled = !state.conversation || busy || state.privacyBusy === true;
        this.deleteConversationButton.disabled = !state.conversation || busy || state.privacyBusy === true;
        var unavailable = ['blocked', 'failed'].indexOf(state.phase) !== -1;
        var presenceText = this.updatePresence(state.phase, busy, unavailable);
        var statusText = busy ? '' : String(state.status || '');
        if (statusText === presenceText) {
            statusText = '';
        }
        this.statusNode.textContent = statusText;
        this.statusNode.hidden = statusText === '';
        this.panel.setAttribute('aria-busy', busy ? 'true' : 'false');
        this.updateComposerState(state);
        this.root.classList.toggle('is-busy', busy);
        this.root.classList.toggle('is-unavailable', unavailable);
        if ((messagesChanged || busy) && (nearBottom || state.phase === 'sending' || state.phase === 'ready')) {
            this.messagesNode.scrollTop = this.messagesNode.scrollHeight;
        }
    };

    WidgetView.prototype.updatePresence = function (phase, busy, unavailable) {
        var text = this.configuredSubtitle || util.text('online', 'متصل الآن');
        if (unavailable) {
            text = util.text('unavailable', 'مساعد التسوق غير متاح مؤقتاً.');
        } else if (busy) {
            text = phase === 'booting'
                ? util.text('loading', 'جارٍ بدء المساعد…')
                : util.text('thinking', 'جارٍ التحقق من معلومات المتجر…');
        }
        this.subtitle.setAttribute('aria-live', unavailable ? 'polite' : 'off');
        this.subtitle.textContent = text;
        return text;
    };

    WidgetView.prototype.applyWidgetConfig = function (widget) {
        var title = widget.title ? String(widget.title) : util.text('open', 'مساعدة التسوق');
        this.title.textContent = title;
        this.panel.setAttribute('aria-label', title);
        this.configuredSubtitle = widget.subtitle
            ? String(widget.subtitle)
            : util.text('online', 'متصل الآن');
        this.subtitle.textContent = this.configuredSubtitle;
        if (widget.button_text) {
            this.launcherLabel.textContent = String(widget.button_text);
            this.launcher.setAttribute('aria-label', String(widget.button_text));
        }
    };

    WidgetView.prototype.renderMessages = function (messages, emptyStateHint) {
        var self = this;
        this.clearCarouselObservers();
        this.messagesNode.textContent = '';
        var rows = Array.isArray(messages) ? messages : [];
        this.messagesNode.classList.toggle('is-empty', rows.length === 0);
        if (rows.length === 0 && emptyStateHint) {
            var hint = util.create('div', 'ysai-empty-state-hint', String(emptyStateHint));
            hint.setAttribute('role', 'note');
            hint.setAttribute('dir', 'auto');
            this.messagesNode.appendChild(hint);
            return;
        }
        rows.forEach(function (message) {
            self.messagesNode.appendChild(self.messageNode(message));
        });
    };

    WidgetView.prototype.renderTyping = function (busy) {
        var existing = this.messagesNode.querySelector('.ysai-typing-message');
        if (!busy) {
            if (existing) {
                existing.remove();
            }
            return;
        }
        if (existing) {
            return;
        }
        var typing = util.create('div', 'ysai-typing-message');
        typing.setAttribute('role', 'status');
        typing.setAttribute('aria-label', util.text('thinking', 'جارٍ التحقق من معلومات المتجر…'));
        var bubble = util.create('div', 'ysai-typing-bubble');
        bubble.setAttribute('aria-hidden', 'true');
        bubble.appendChild(util.create('span', ''));
        bubble.appendChild(util.create('span', ''));
        bubble.appendChild(util.create('span', ''));
        typing.appendChild(bubble);
        this.messagesNode.appendChild(typing);
    };

    WidgetView.prototype.messageNode = function (message) {
        var self = this;
        var role = message && message.role === 'user' ? 'user' : 'assistant';
        var article = util.create('article', 'ysai-message is-' + role
            + (message && message.state_uncertain ? ' is-uncertain' : ''));
        if (message && message.outcome) {
            article.setAttribute('data-outcome', String(message.outcome));
        }

        var row = util.create('div', 'ysai-message-row');
        var actions = util.create('div', 'ysai-message-actions');
        var messageText = message && message.text
            ? String(message.text)
            : util.text('genericFailure', 'تعذر إكمال الطلب بأمان.');
        var replyButton = iconButton('ysai-message-action', 'reply', util.text('reply', 'الرد على الرسالة'));
        replyButton.addEventListener('click', function () {
            self.setReplyContext(util.text('replyingTo', 'الرد على'), messageText);
        });
        actions.appendChild(replyButton);
        if (role === 'assistant') {
            var copyButton = iconButton('ysai-message-action', 'copy', util.text('copy', 'نسخ الرسالة'));
            copyButton.addEventListener('click', function () {
                self.copyMessage(messageText, copyButton);
            });
            actions.appendChild(copyButton);
        }

        var bubble = util.create('div', 'ysai-bubble');
        bubble.setAttribute('dir', 'auto');
        this.appendMessageText(bubble, messageText, message && message.presentation);
        row.appendChild(bubble);
        row.appendChild(actions);
        article.appendChild(row);

        if (message && Array.isArray(message.products) && message.products.length > 0) {
            article.appendChild(this.productGroupNode(message.products, String(message.id || '')));
        }

        if (message && message.retry_id) {
            var retryActions = util.create('div', 'ysai-message-retry-actions');
            var retryButton = util.create('button', 'ysai-message-retry', util.text('retry', 'إعادة المحاولة'));
            retryButton.type = 'button';
            retryButton.addEventListener('click', function () { self.app.retry(message.retry_id); });
            retryActions.appendChild(retryButton);
            article.appendChild(retryActions);
        }
        return article;
    };

    WidgetView.prototype.appendMessageText = function (bubble, text, presentation) {
        var value = String(text || '');
        var visual = presentation && typeof presentation === 'object' ? presentation : {};
        var replyQuote = String(visual.reply_quote || '');
        if (replyQuote) {
            bubble.appendChild(this.quotedMessageNode(replyQuote, visual.quote));
        }
        this.appendSentImages(bubble, visual.images);
        if (value) {
            bubble.appendChild(util.create('div', 'ysai-message-text', value));
        }
    };

    WidgetView.prototype.quotedMessageNode = function (text, quotePresentation) {
        var quoted = util.create('div', 'ysai-quoted-message');
        quoted.setAttribute('dir', 'auto');
        var image = presentationImageSource(quotePresentation && quotePresentation.image);
        if (image) {
            quoted.classList.add('has-thumbnail');
            var thumbnail = util.create('img', 'ysai-quoted-thumbnail');
            thumbnail.src = image;
            thumbnail.alt = String((quotePresentation && quotePresentation.alt) || '');
            thumbnail.loading = 'lazy';
            thumbnail.decoding = 'async';
            thumbnail.addEventListener('error', function () {
                quoted.classList.remove('has-thumbnail');
                thumbnail.remove();
            }, { once: true });
            quoted.appendChild(thumbnail);
        }
        quoted.appendChild(util.create('span', 'ysai-quoted-copy', text));
        return quoted;
    };

    WidgetView.prototype.appendSentImages = function (bubble, images) {
        var rows = Array.isArray(images) ? images.slice(0, 2) : [];
        var media = util.create('div', 'ysai-message-media');
        rows.forEach(function (item) {
            var source = presentationImageSource(item && item.src);
            if (source) {
                var image = util.create('img', 'ysai-message-image');
                image.src = source;
                image.alt = String((item && item.alt) || util.text('imageAttachment', 'صورة مرفقة'));
                image.decoding = 'async';
                media.appendChild(image);
                return;
            }
            if (!item || item.kind !== 'image') {
                return;
            }
            var placeholder = util.create('div', 'ysai-message-image-placeholder');
            placeholder.appendChild(iconNode('image'));
            var copy = util.create('span', 'ysai-message-image-placeholder-copy');
            copy.appendChild(util.create('strong', '', util.text('imageAttachment', 'صورة مرفقة')));
            var mime = String(item.mime_type || '').split('/').pop().toUpperCase();
            var bytes = Number(item.byte_length || 0);
            var details = mime;
            if (bytes > 0) {
                details += (details ? ' · ' : '') + Math.max(1, Math.round(bytes / 1024)) + ' KB';
            }
            if (details) {
                copy.appendChild(util.create('small', '', details));
            }
            placeholder.appendChild(copy);
            media.appendChild(placeholder);
        });
        if (media.childNodes.length > 0) {
            media.classList.toggle('has-multiple', media.childNodes.length > 1);
            bubble.appendChild(media);
        }
    };

    WidgetView.prototype.setReplyContext = function (label, text, media, authority) {
        var value = util.sliceCodePoints(String(text || '').replace(/\s+/g, ' ').trim(), 0, Contract.replyContext.textMaxChars);
        if (!value) {
            return;
        }
        var image = presentationImageSource(media && media.image);
        this.replyContext = {
            label: String(label || ''),
            text: value,
            image: image,
            imageAlt: image ? String((media && media.alt) || '') : '',
            messageId: authority && typeof authority.messageId === 'string'
                ? authority.messageId : '',
            productIndex: authority && Number.isInteger(authority.productIndex)
                ? authority.productIndex : -1
        };
        this.replyLabel.textContent = this.replyContext.label || util.text('replyingTo', 'الرد على');
        this.replyText.textContent = value;
        this.replyNode.classList.toggle('has-media', Boolean(image));
        this.replyMedia.hidden = !image;
        this.replyImage.src = image || '';
        this.replyImage.alt = this.replyContext.imageAlt;
        this.replyNode.hidden = false;
        this.root.classList.add('has-reply');
        this.textarea.focus();
    };

    WidgetView.prototype.clearReplyContext = function (focusComposer) {
        this.replyContext = null;
        this.replyNode.hidden = true;
        this.replyNode.classList.remove('has-media');
        this.replyMedia.hidden = true;
        this.replyImage.removeAttribute('src');
        this.replyImage.alt = '';
        this.replyText.textContent = '';
        this.root.classList.remove('has-reply');
        if (focusComposer !== false && !this.textarea.disabled) {
            this.textarea.focus();
        }
    };

    WidgetView.prototype.copyMessage = function (text, button) {
        var self = this;
        var value = String(text || '');
        this.copySequence += 1;
        var sequence = this.copySequence;
        var completed = function () {
            if (self.destroyed || sequence !== self.copySequence) {
                return;
            }
            if (self.confirmedCopyButton && self.confirmedCopyButton !== button) {
                self.confirmedCopyButton.classList.remove('is-confirmed');
            }
            self.confirmedCopyButton = button;
            button.classList.add('is-confirmed');
            var copiedNotice = util.text('copied', 'تم النسخ');
            self.app.notice(copiedNotice);
            if (self.copyTimer !== null) {
                window.clearTimeout(self.copyTimer);
            }
            self.copyTimer = window.setTimeout(function () {
                self.copyTimer = null;
                if (self.confirmedCopyButton === button) {
                    button.classList.remove('is-confirmed');
                    self.confirmedCopyButton = null;
                }
                if (!self.destroyed
                    && String(self.app.store.getState().status || '') === copiedNotice
                ) {
                    self.app.notice('');
                }
            }, 1400);
        };

        if (window.navigator && window.navigator.clipboard
            && typeof window.navigator.clipboard.writeText === 'function'
        ) {
            window.navigator.clipboard.writeText(value).then(completed).catch(function () {
                if (!self.destroyed && sequence === self.copySequence) {
                    self.copyFallback(value, completed);
                }
            });
            return;
        }
        this.copyFallback(value, completed);
    };

    WidgetView.prototype.copyFallback = function (text, completed) {
        var temporary = util.create('textarea', 'ysai-copy-buffer');
        temporary.value = text;
        temporary.setAttribute('readonly', 'readonly');
        document.body.appendChild(temporary);
        temporary.select();
        try {
            if (document.execCommand('copy') === true) {
                completed();
            } else {
                this.app.notice(util.text('copyFailed', 'تعذر نسخ الرسالة.'));
            }
        } catch (error) {
            this.app.notice(util.text('copyFailed', 'تعذر نسخ الرسالة.'));
        }
        temporary.remove();
    };

    WidgetView.prototype.resetConversationState = function () {
        this.clearReplyContext(false);
        this.privacyPanel.hidden = true;
        this.privacyButton.setAttribute('aria-expanded', 'false');
        this.textarea.value = '';
        this.fileInput.value = '';
        this.resizeComposer();
        this.updateComposerState();
    };

    WidgetView.prototype.productGroupNode = function (products, messageId) {
        var self = this;
        var section = util.create('section', 'ysai-product-section');
        var controls = util.create('div', 'ysai-product-controls');
        var previous = iconButton('ysai-product-nav', 'chevron-left', util.text('previousProducts', 'المنتجات السابقة'));
        var next = iconButton('ysai-product-nav', 'chevron-right', util.text('nextProducts', 'المنتجات التالية'));
        controls.appendChild(previous);
        controls.appendChild(next);

        var container = util.create('div', 'ysai-products');
        container.id = this.root.id + '-products-' + util.randomId();
        previous.setAttribute('aria-controls', container.id);
        next.setAttribute('aria-controls', container.id);
        var cards = [];
        products.forEach(function (product, productIndex) {
            var card = self.productNode(product, messageId, productIndex);
            cards.push(card);
            container.appendChild(card);
        });

        var index = 0;
        var scrollFrame = null;
        function cardsPerView() {
            var parsed = parseInt(window.getComputedStyle(self.root).getPropertyValue('--ysai-product-cards'), 10);
            return Math.max(1, Math.min(3, isFinite(parsed) ? parsed : 1));
        }
        function maximumIndex() {
            return Math.max(0, cards.length - cardsPerView());
        }
        function syncButtons() {
            index = Math.max(0, Math.min(maximumIndex(), index));
            previous.disabled = index <= 0;
            next.disabled = index >= maximumIndex();
            controls.hidden = maximumIndex() === 0;
        }
        function nearestIndex() {
            if (!cards.length) {
                return 0;
            }
            var frame = container.getBoundingClientRect();
            var rtl = window.getComputedStyle(container).direction === 'rtl';
            var closest = 0;
            var distance = Infinity;
            cards.forEach(function (card, cardIndex) {
                var rect = card.getBoundingClientRect();
                var current = Math.abs((rtl ? rect.right : rect.left) - (rtl ? frame.right : frame.left));
                if (current < distance) {
                    distance = current;
                    closest = cardIndex;
                }
            });
            return Math.max(0, Math.min(maximumIndex(), closest));
        }
        function move(delta) {
            index = Math.max(0, Math.min(maximumIndex(), index + delta));
            var card = cards[index];
            if (card && typeof card.scrollIntoView === 'function') {
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
            }
            syncButtons();
        }
        previous.addEventListener('click', function () { move(-1); });
        next.addEventListener('click', function () { move(1); });
        container.addEventListener('scroll', function () {
            if (scrollFrame !== null) {
                return;
            }
            scrollFrame = window.requestAnimationFrame(function () {
                scrollFrame = null;
                index = nearestIndex();
                syncButtons();
            });
        }, { passive: true });
        if (typeof window.ResizeObserver === 'function') {
            var observer = new window.ResizeObserver(function () {
                index = nearestIndex();
                syncButtons();
            });
            observer.observe(container);
            this.carouselObservers.push(observer);
        }
        syncButtons();

        section.appendChild(controls);
        section.appendChild(container);
        return section;
    };

    WidgetView.prototype.productNode = function (product, messageId, productIndex) {
        var self = this;
        var card = util.create('article', 'ysai-product-card');
        card.classList.toggle('is-out-of-stock', Boolean(product && product.in_stock === false));
        card.classList.toggle('requires-options', Boolean(product && product.requires_variation));
        var url = util.safeUrl(product && product.permalink);
        var imageUrl = util.safeUrl(product && product.image);
        var media = util.create(url ? 'a' : 'div', 'ysai-product-media');
        if (url) {
            media.href = url;
            media.target = '_self';
            media.setAttribute('aria-label', product && product.name ? String(product.name) : util.text('productImage', 'صورة المنتج'));
        }
        if (imageUrl) {
            var image = util.create('img', 'ysai-product-image');
            image.src = imageUrl;
            image.alt = product && product.name ? String(product.name) : util.text('productImage', 'صورة المنتج');
            image.loading = 'lazy';
            image.decoding = 'async';
            image.addEventListener('error', function () {
                media.textContent = '';
                var fallback = util.create('span', 'ysai-product-placeholder');
                fallback.appendChild(iconNode('product'));
                media.appendChild(fallback);
            }, { once: true });
            media.appendChild(image);
        } else {
            var placeholder = util.create('span', 'ysai-product-placeholder');
            placeholder.appendChild(iconNode('product'));
            media.appendChild(placeholder);
        }
        card.appendChild(media);

        var body = util.create('div', 'ysai-product-body');
        var name = util.create(url ? 'a' : 'strong', 'ysai-product-name', product && product.name ? product.name : '');
        if (url) {
            name.href = url;
            name.target = '_self';
        }
        body.appendChild(name);
        if (product && product.formatted_price) {
            var productPrice = util.create('bdi', 'ysai-product-price ysai-money', product.formatted_price);
            productPrice.setAttribute('dir', 'auto');
            body.appendChild(productPrice);
        }
        if (product && product.short_description) {
            body.appendChild(util.create('p', 'ysai-product-description', product.short_description));
        }
        var badges = util.create('div', 'ysai-product-badges');
        if (product && product.requires_variation) {
            badges.appendChild(util.create('span', 'ysai-badge', util.text('requiresOptions', 'يتطلب تحديد الخيارات')));
        }
        if (product && product.in_stock === false) {
            badges.appendChild(util.create('span', 'ysai-badge is-warning', util.text('outOfStock', 'غير متوفر')));
        }
        if (badges.childNodes.length > 0) {
            body.appendChild(badges);
        }
        card.appendChild(body);

        var quote = iconButton('ysai-product-quote', 'reply', util.text('quoteProduct', 'الرد باستخدام هذا المنتج'));
        quote.addEventListener('click', function () {
            var label = product && product.name ? String(product.name) : '';
            var price = product && product.formatted_price ? String(product.formatted_price) : '';
            self.setReplyContext(
                util.text('replyingTo', 'الرد على'),
                (label + (price ? ' — ' + price : '')).trim(),
                { image: product && product.image, alt: label },
                { messageId: String(messageId || ''), productIndex: Number(productIndex) }
            );
        });
        card.appendChild(quote);
        return card;
    };

    WidgetView.prototype.renderCart = function (cart, notice) {
        this.cartNode.textContent = '';
        var hasNotice = Boolean(notice);
        if (hasNotice) {
            this.cartNode.appendChild(util.create('span', 'ysai-cart-notice', notice));
        }
        if (!cart || Number(cart.item_count || 0) <= 0) {
            this.cartNode.hidden = !hasNotice;
            return;
        }
        this.cartNode.hidden = false;
        var cartText = util.create('span', 'ysai-cart-text');
        cartText.appendChild(document.createTextNode(
            util.text('cart', 'السلة') + ': ' + String(cart.item_count) + ' '
                + util.text('items', 'منتجات') + ' · '
        ));
        var cartTotal = util.create('bdi', 'ysai-money', String(cart.formatted_total || ''));
        cartTotal.setAttribute('dir', 'auto');
        cartText.appendChild(cartTotal);
        this.cartNode.appendChild(cartText);
        var links = util.create('span', 'ysai-cart-links');
        var cartUrl = util.safeUrl(cart.cart_url);
        var checkoutUrl = util.safeUrl(cart.checkout_url);
        if (cartUrl) {
            var cartLink = util.create('a', '', util.text('cart', 'السلة'));
            cartLink.href = cartUrl;
            links.appendChild(cartLink);
        }
        if (checkoutUrl) {
            var checkoutLink = util.create('a', '', util.text('checkout', 'إتمام الطلب'));
            checkoutLink.href = checkoutUrl;
            links.appendChild(checkoutLink);
        }
        this.cartNode.appendChild(links);
    };

    WidgetView.prototype.renderAttachments = function (attachments) {
        var self = this;
        var rows = Array.isArray(attachments) ? attachments : [];
        this.previewNode.textContent = '';
        this.previewNode.hidden = rows.length === 0;
        rows.forEach(function (attachment) {
            var item = util.create('div', 'ysai-attachment');
            if (attachment.data) {
                var image = util.create('img', '');
                image.src = attachment.data;
                image.alt = attachment.name || util.text('imageAttachment', 'صورة مرفقة');
                item.appendChild(image);
            } else {
                item.appendChild(util.create(
                    'span',
                    'ysai-attachment-status',
                    util.text('imageReading', 'جارٍ تجهيز الصورة…')
                ));
            }
            var remove = iconButton('', 'remove', util.text('remove', 'إزالة'));
            remove.addEventListener('click', function () { self.app.removeAttachment(attachment.id, self); });
            item.appendChild(remove);
            self.previewNode.appendChild(item);
        });
    };

    WidgetView.prototype.clearCarouselObservers = function () {
        this.carouselObservers.forEach(function (observer) {
            if (observer && typeof observer.disconnect === 'function') {
                observer.disconnect();
            }
        });
        this.carouselObservers = [];
    };

    WidgetView.prototype.destroy = function () {
        this.destroyed = true;
        this.clearCarouselObservers();
        if (this.copyTimer !== null) {
            window.clearTimeout(this.copyTimer);
            this.copyTimer = null;
        }
        this.copySequence += 1;
        if (this.confirmedCopyButton) {
            this.confirmedCopyButton.classList.remove('is-confirmed');
            this.confirmedCopyButton = null;
        }
        if (this.documentKeydown) {
            document.removeEventListener('keydown', this.documentKeydown);
            this.documentKeydown = null;
        }
        if (this.panelInteraction) {
            this.panel.removeEventListener('focusin', this.panelInteraction);
            this.panel.removeEventListener('pointerdown', this.panelInteraction);
            this.panelInteraction = null;
        }
        if (this.mobileModalQuery && this.mobileModalListener) {
            if (typeof this.mobileModalQuery.removeEventListener === 'function') {
                this.mobileModalQuery.removeEventListener('change', this.mobileModalListener);
            } else if (typeof this.mobileModalQuery.removeListener === 'function') {
                this.mobileModalQuery.removeListener(this.mobileModalListener);
            }
            this.mobileModalListener = null;
        }
        this.opened = false;
        this.syncModalState();
        this.root.classList.remove('is-open', 'is-busy', 'is-unavailable');
        this.clearReplyContext(false);
        if (this.launcher && this.launcher.parentNode === this.root) {
            this.root.removeChild(this.launcher);
        }
        if (this.panel && this.panel.parentNode === this.root) {
            this.root.removeChild(this.panel);
        }
    };

    Runtime.WidgetView = WidgetView;
}(window));
