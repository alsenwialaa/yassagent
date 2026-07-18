(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var util = Runtime.util;
    var Contract = Runtime.publicContract;
    if (!Contract || Contract.contractVersion !== 3) {
        throw new Error('The generated public contract is missing or incompatible.');
    }
    var Fields = Contract.fields;
    var Required = Contract.required;
    var Limits = Contract.limits;
    var Enums = Contract.enums;
    var Constants = Contract.constants;
    var OUTCOMES = Enums.assistantOutcomes;
    var UUID_V4 = new RegExp(Contract.patterns.uuidV4);
    var CODE = new RegExp(Contract.patterns.code);
    var SESSION_TOKEN = new RegExp(Contract.patterns.sessionToken);
    var CONVERSATION_TOKEN = new RegExp(Contract.patterns.conversationToken);
    var CURRENCY = new RegExp('^[A-Z]{' + Limits.currencyChars + '}$');
    var CART_COMMANDS = Contract.cartCommand.types;
    var CART_QUANTITY_COMMANDS = Contract.cartCommand.quantityTypes;

    function invalid(message) {
        throw new Runtime.ApiError(
            message || util.text('invalidResponse', 'أعاد الخادم استجابة غير صالحة. يمكن إعادة المحاولة بنفس الطلب بأمان.'),
            'response_contract_invalid',
            502,
            0
        );
    }

    function requiredString(value, maximum, message) {
        if (typeof value !== 'string' || !value.trim() || util.codePointLength(value) > maximum) {
            invalid(message);
        }
        return value;
    }

    function requiredStringValue(value, maximum) {
        if (typeof value !== 'string' || util.codePointLength(value) > maximum) {
            invalid();
        }
        return value;
    }

    function exactKeys(value, expected) {
        if (!util.isRecord(value)) {
            invalid();
        }
        var actual = Object.keys(value).sort();
        var canonical = expected.slice().sort();
        if (actual.length !== canonical.length || actual.some(function (key, index) {
            return key !== canonical[index];
        })) {
            invalid();
        }
    }

    function optionalString(value, maximum) {
        if (value === undefined || value === null || value === '') {
            return '';
        }
        if (typeof value !== 'string' || util.codePointLength(value) > maximum) {
            invalid();
        }
        return value;
    }

    function requiredInteger(value, minimum, maximum) {
        if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value
            || value < minimum || value > maximum
        ) {
            invalid();
        }
        return value;
    }

    function requiredUuid(value) {
        var id = requiredString(value, Limits.uuidLength).toLowerCase();
        if (!UUID_V4.test(id)) {
            invalid();
        }
        return id;
    }

    function requiredList(value, maximum) {
        if (!Array.isArray(value) || value.length > maximum) {
            invalid();
        }
        return value;
    }

    function normalizeProduct(product) {
        exactKeys(product, Fields.product);
        var id = requiredInteger(product.id, 1, Limits.productIdMax);
        if (typeof product.in_stock !== 'boolean' || typeof product.requires_variation !== 'boolean') {
            invalid();
        }
        var image = requiredStringValue(product.image, Limits.publicUrlMaxChars);
        var permalink = requiredString(product.permalink, Limits.publicUrlMaxChars);
        if ((image && !util.safeUrl(image)) || !util.safeUrl(permalink)) {
            invalid();
        }
        return {
            id: id,
            name: requiredString(product.name, Limits.productNameMaxChars),
            formatted_price: util.moneyText(requiredStringValue(product.formatted_price, Limits.formattedPriceMaxChars)),
            short_description: requiredStringValue(product.short_description, Limits.shortDescriptionMaxChars),
            in_stock: product.in_stock,
            requires_variation: product.requires_variation,
            image: image,
            permalink: permalink
        };
    }

    function normalizeReceipt(receipt, messageText) {
        exactKeys(receipt, Fields.receipt);
        exactKeys(receipt.proof, Fields.receipt_proof);
        if (typeof receipt.changed !== 'boolean') {
            invalid();
        }
        var receiptId = requiredUuid(receipt.id);
        if (requiredString(receipt.action, Limits.codeMaxChars) !== Constants.receiptAction) {
            invalid();
        }
        var receiptMessage = requiredString(receipt.message, Limits.receiptMessageMaxChars);
        if (receiptMessage !== messageText) {
            invalid();
        }
        var receiptCreatedAt = requiredInteger(receipt.created_at, 1, Limits.createdAtMax);
        if (receiptCreatedAt > util.now() + 300) {
            invalid();
        }
        var proof = receipt.proof;
        requiredInteger(proof.cart_count, 0, Limits.cartCountMax);
        requiredInteger(proof.changed_line_count, 0, Limits.cartCountMax);
        util.moneyText(requiredStringValue(proof.cart_total, Limits.moneyTextMaxChars));
        if (typeof proof.currency !== 'string' || !CURRENCY.test(proof.currency)) {
            invalid();
        }
        var commands = requiredList(proof.commands, Limits.receiptCommandMaxItems);
        if (commands.length !== Limits.receiptCommandMaxItems) {
            invalid();
        }
        commands = commands.map(function (command) {
            if (!util.isRecord(command)) {
                invalid();
            }
            var type = requiredString(command.type, Limits.codeMaxChars);
            if (CART_COMMANDS.indexOf(type) === -1) {
                invalid();
            }
            var expected = Contract.cartCommand.fieldsByType[type].slice();
            if (type !== 'clear') {
                requiredString(command.item, Contract.cartCommand.itemMaxChars);
            }
            if (CART_QUANTITY_COMMANDS.indexOf(type) !== -1) {
                if (typeof command.quantity !== 'number' || !isFinite(command.quantity)
                    || Math.floor(command.quantity) !== command.quantity
                    || command.quantity <= 0 || command.quantity > Contract.cartCommand.quantityMax
                ) {
                    invalid();
                }
            }
            exactKeys(command, expected);
            var canonical = { type: type };
            if (type !== 'clear') {
                canonical.item = command.item;
            }
            if (expected.indexOf('quantity') !== -1) {
                canonical.quantity = command.quantity;
            }
            return canonical;
        });
        return {
            id: receiptId,
            action: Constants.receiptAction,
            changed: receipt.changed,
            message: receiptMessage,
            proof: {
                commands: commands,
                cart_count: proof.cart_count,
                cart_total: util.moneyText(proof.cart_total),
                changed_line_count: proof.changed_line_count,
                currency: proof.currency
            },
            created_at: receiptCreatedAt
        };
    }

    function normalizePresentation(value, role) {
        exactKeys(value, Fields.presentation);
        var images = requiredList(value.images, Limits.presentationMaxImages).map(function (image) {
            exactKeys(image, Fields.image_metadata);
            if (requiredString(image.kind, Limits.imageKindMaxChars) !== Constants.imageKind) {
                invalid();
            }
            var mime = requiredString(image.mime_type, Limits.imageMimeMaxChars).toLowerCase();
            if (Enums.imageMimeTypes.indexOf(mime) === -1) {
                invalid();
            }
            return {
                kind: Constants.imageKind,
                mime_type: mime,
                byte_length: requiredInteger(image.byte_length, Limits.imageMetadataMinBytes, Limits.imageMetadataMaxBytes)
            };
        });
        var scope = requiredString(value.image_scope, Limits.imageKindMaxChars);
        var replyQuote = optionalString(value.reply_quote, Limits.replyQuoteMaxChars);
        if ((images.length === 0 && scope !== 'none')
            || (images.length > 0 && scope !== 'turn_only')
            || (role === 'assistant' && images.length > 0)
            || (role === 'assistant' && replyQuote !== '')
            || (replyQuote !== '' && !replyQuote.trim())
        ) {
            invalid();
        }
        return { image_scope: scope, images: images, reply_quote: replyQuote };
    }

    function normalizeMessage(message, allowUser) {
        if (!util.isRecord(message)) {
            invalid();
        }
        var messageKeys = Required.message.slice();
        if (message.outcome === 'safe_failure') {
            messageKeys = messageKeys.concat(Contract.runtime.messageOptionalFailureFields);
        }
        exactKeys(message, messageKeys);
        var messageId = requiredUuid(message.id);
        var turnId = requiredUuid(message.turn_id);
        var messageCreatedAt = requiredInteger(message.created_at, 1, Limits.createdAtMax);
        if (messageCreatedAt > util.now() + 300) {
            invalid();
        }

        var role = message.role;
        if (role !== 'assistant' && !(allowUser && role === 'user')) {
            invalid();
        }
        var outcome = optionalString(message.outcome, Limits.messageOutcomeMaxChars);
        if (role === 'assistant' && OUTCOMES.indexOf(outcome) === -1) {
            invalid();
        }
        if (role === 'user' && outcome !== '') {
            invalid();
        }

        var text = requiredString(message.text, Limits.messageTextMaxChars);
        var products = requiredList(message.products, Limits.productMaxItems).map(normalizeProduct);
        var receipts = requiredList(message.receipts, Limits.receiptMaxItems);
        var presentation = normalizePresentation(message.presentation, role);
        var failureCode = optionalString(message.failure_code, Limits.codeMaxChars);
        var uncertain = message.state_uncertain === true;

        if (role === 'user') {
            if (products.length || receipts.length || failureCode || uncertain) {
                invalid();
            }
        } else {
            if ((outcome === 'action_verified' || outcome === 'safe_failure') && products.length) {
                invalid();
            }
            if (outcome === 'action_verified') {
                if (receipts.length !== 1) {
                    invalid();
                }
                receipts = [normalizeReceipt(receipts[0], text)];
            } else if (receipts.length) {
                invalid();
            }
            if (outcome === 'safe_failure') {
                if (!CODE.test(failureCode)
                    || typeof message.state_uncertain !== 'boolean'
                ) {
                    invalid();
                }
            } else if (failureCode || uncertain) {
                invalid();
            }
        }

        return {
            id: messageId,
            turn_id: turnId,
            role: role,
            outcome: outcome,
            text: text,
            products: products,
            receipts: receipts,
            failure_code: failureCode,
            state_uncertain: uncertain,
            presentation: presentation,
            created_at: messageCreatedAt
        };
    }

    function normalizeCartMutationCapability(value) {
        if (!util.isRecord(value)) { invalid(); }
        exactKeys(value, Fields.cart_mutation_capability);
        var mutationAvailable = value.available;
        var mutationCode = requiredString(value.code, Limits.codeMaxChars);
        var mutationNotice = requiredStringValue(value.notice, Limits.noticeMaxChars);
        if (typeof mutationAvailable !== 'boolean'
            || Enums.cartMutationCodes.indexOf(mutationCode) === -1
            || (mutationAvailable && (mutationCode !== Constants.mutationAvailableCode || mutationNotice !== ''))
            || (!mutationAvailable && (mutationCode === Constants.mutationAvailableCode || !mutationNotice.trim()))
        ) {
            invalid();
        }
        return {
            available: mutationAvailable,
            code: mutationCode,
            notice: mutationNotice
        };
    }

    function normalizeCapabilities(value) {
        exactKeys(value, Fields.capabilities);
        if (typeof value.chat_ready !== 'boolean' || typeof value.images !== 'boolean') {
            invalid();
        }
        var maxImages = requiredInteger(value.max_images, 0, Limits.attachmentMaxItems);
        var maxImageBytes = requiredInteger(value.max_image_bytes, 0, Contract.runtime.imagePolicy.max_decoded_bytes);
        if ((value.images && (maxImages !== Limits.attachmentMaxItems || maxImageBytes !== Contract.runtime.imagePolicy.max_decoded_bytes))
            || (!value.images && (maxImages !== 0 || maxImageBytes !== 0))
        ) {
            invalid();
        }
        return {
            chat_ready: value.chat_ready,
            images: value.images,
            max_images: maxImages,
            max_image_bytes: maxImageBytes,
            cart_mutations: normalizeCartMutationCapability(value.cart_mutations)
        };
    }

    function normalizeWidget(value) {
        exactKeys(value, Fields.widget);
        return {
            title: requiredStringValue(value.title, Limits.widgetTitleMaxChars),
            subtitle: requiredStringValue(value.subtitle, Limits.widgetSubtitleMaxChars),
            button_text: requiredStringValue(value.button_text, Limits.widgetButtonMaxChars),
            empty_state_hint: requiredStringValue(value.empty_state_hint, Limits.widgetEmptyStateHintMaxChars)
        };
    }

    function normalizeCart(payload) {
        if (typeof payload.cart_available !== 'boolean') {
            invalid();
        }
        if (payload.cart_available === false) {
            if (payload.cart !== null) {
                invalid();
            }
            return null;
        }
        exactKeys(payload.cart, Fields.cart);
        var cartUrl = requiredString(payload.cart.cart_url, Limits.publicUrlMaxChars);
        var checkoutUrl = requiredString(payload.cart.checkout_url, Limits.publicUrlMaxChars);
        if (!util.safeUrl(cartUrl) || !util.safeUrl(checkoutUrl)) {
            invalid();
        }
        return {
            item_count: requiredInteger(payload.cart.item_count, 0, Limits.cartCountMax),
            formatted_total: util.moneyText(requiredStringValue(payload.cart.formatted_total, Limits.moneyTextMaxChars)),
            cart_url: cartUrl,
            checkout_url: checkoutUrl
        };
    }

    function normalizeConversation(value) {
        exactKeys(value, Fields.conversation);
        var token = requiredString(value.token, Limits.conversationTokenMaxChars);
        if (token.length < Limits.conversationTokenMinChars || !CONVERSATION_TOKEN.test(token)) {
            invalid();
        }
        var messages = requiredList(value.messages, Contract.runtime.transcriptMaxRows).map(function (message) {
            return normalizeMessage(message, true);
        });
        var messageIds = Object.create(null);
        var turnIds = Object.create(null);
        if (messages.length % 2 !== 0) {
            invalid();
        }
        for (var index = 0; index < messages.length; index += 2) {
            var user = messages[index];
            var assistant = messages[index + 1];
            if (user.role !== 'user' || assistant.role !== 'assistant'
                || user.turn_id !== assistant.turn_id
                || user.id === assistant.id
                || Object.prototype.hasOwnProperty.call(turnIds, user.turn_id)
                || Object.prototype.hasOwnProperty.call(messageIds, user.id)
                || Object.prototype.hasOwnProperty.call(messageIds, assistant.id)
            ) {
                invalid();
            }
            turnIds[user.turn_id] = true;
            messageIds[user.id] = true;
            messageIds[assistant.id] = true;
        }
        return {
            conversation: {
                id: requiredUuid(value.id),
                token: token
            },
            messages: messages
        };
    }

    function exactlyEqual(left, right) {
        if (left === right) {
            return true;
        }
        if (Array.isArray(left) || Array.isArray(right)) {
            return Array.isArray(left) && Array.isArray(right)
                && left.length === right.length
                && left.every(function (value, index) { return exactlyEqual(value, right[index]); });
        }
        if (!util.isRecord(left) || !util.isRecord(right)) {
            return false;
        }
        var leftKeys = Object.keys(left).sort();
        var rightKeys = Object.keys(right).sort();
        return leftKeys.length === rightKeys.length
            && leftKeys.every(function (key, index) {
                return key === rightKeys[index] && exactlyEqual(left[key], right[key]);
            });
    }

    function boot(payload) {
        if (!util.isRecord(payload) || payload.ok !== true) {
            invalid(util.safeMessage(payload, 'تعذر بدء جلسة المساعد.'));
        }
        exactKeys(payload, Fields.boot_response);
        exactKeys(payload.session, Fields.session);
        var serverTime = requiredInteger(payload.server_time, Limits.serverTimeMin, Limits.serverTimeMax);
        if (!util.synchronizeTime(serverTime)) {
            invalid();
        }
        var canonicalConversation = normalizeConversation(payload.conversation);
        var sessionToken = requiredString(payload.session.token, Limits.sessionTokenMaxChars);
        if (!SESSION_TOKEN.test(sessionToken)) {
            invalid();
        }
        var cartNotice = requiredStringValue(payload.cart_notice, Limits.noticeMaxChars);
        if ((payload.cart_available === false && !cartNotice.trim())
            || (payload.cart_available === true && cartNotice !== '')
        ) {
            invalid();
        }
        var pendingTurn = null;
        if (payload.pending_turn !== null) {
            exactKeys(payload.pending_turn, Fields.pending_turn);
            var pendingStatus = requiredString(payload.pending_turn.status, Limits.pendingStatusMaxChars);
            if (Enums.pendingTurnStatuses.indexOf(pendingStatus) === -1) {
                invalid();
            }
            pendingTurn = {
                id: requiredUuid(payload.pending_turn.id),
                status: pendingStatus
            };
        }
        return {
            sessionToken: sessionToken,
            conversation: canonicalConversation.conversation,
            messages: canonicalConversation.messages,
            cart: normalizeCart(payload),
            cartAvailable: payload.cart_available,
            cartNotice: cartNotice,
            capabilities: normalizeCapabilities(payload.capabilities),
            widget: normalizeWidget(payload.widget),
            pendingTurn: pendingTurn
        };
    }

    function turn(payload) {
        if (!util.isRecord(payload) || payload.ok !== true) {
            invalid();
        }
        exactKeys(payload, Fields.turn_response);
        if (typeof payload.turn_committed !== 'boolean'
            || typeof payload.messages_available !== 'boolean'
        ) {
            invalid();
        }
        var message = normalizeMessage(payload.message, false);
        var canonicalConversation = normalizeConversation(payload.conversation);
        var messagesNotice = requiredStringValue(payload.messages_notice, Limits.noticeMaxChars);
        if ((payload.messages_available === false && !messagesNotice.trim())
            || (payload.messages_available === true && messagesNotice !== '')
            || (payload.turn_committed === false && payload.messages_available === false)
            || (payload.messages_available === false && canonicalConversation.messages.length !== 0)
        ) {
            invalid();
        }

        var canonicalUserCount = 0;
        var canonicalAssistant = null;
        var canonicalAssistantRaw = null;
        canonicalConversation.messages.forEach(function (candidate) {
            if (candidate.turn_id !== message.turn_id) {
                return;
            }
            if (candidate.role === 'user') {
                canonicalUserCount += 1;
            } else if (candidate.role === 'assistant') {
                if (canonicalAssistant !== null) {
                    invalid();
                }
                canonicalAssistant = candidate;
            }
        });
        requiredList(payload.conversation.messages, Contract.runtime.transcriptMaxRows).forEach(function (candidate) {
            if (util.isRecord(candidate)
                && String(candidate.turn_id || '').toLowerCase() === message.turn_id
                && candidate.role === 'assistant'
            ) {
                if (canonicalAssistantRaw !== null) {
                    invalid();
                }
                canonicalAssistantRaw = candidate;
            }
        });
        if (payload.turn_committed && payload.messages_available) {
            if (canonicalUserCount !== 1 || canonicalAssistant === null
                || canonicalAssistantRaw === null
                || !exactlyEqual(canonicalAssistantRaw, payload.message)
            ) {
                invalid();
            }
        } else if (payload.messages_available
            && (canonicalUserCount !== 0 || canonicalAssistant !== null)
        ) {
            invalid();
        }

        if (payload.turn_committed === false
            && (message.outcome !== 'safe_failure'
                || message.failure_code !== 'rate_limited'
                || message.state_uncertain !== false
                || payload.messages_available !== true)
        ) {
            invalid();
        }

        var cartNotice = requiredStringValue(payload.cart_notice, Limits.noticeMaxChars);
        if ((payload.cart_available === false && !cartNotice.trim())
            || (payload.cart_available === true && cartNotice !== '')
        ) {
            invalid();
        }
        return {
            message: message,
            turnCommitted: payload.turn_committed,
            conversation: canonicalConversation.conversation,
            messages: canonicalConversation.messages,
            messagesAvailable: payload.messages_available,
            messagesNotice: messagesNotice,
            cart: normalizeCart(payload),
            cartAvailable: payload.cart_available,
            cartNotice: cartNotice,
            cartMutations: normalizeCartMutationCapability(payload.cart_mutations)
        };
    }

    Runtime.contracts = Object.freeze({ boot: boot, turn: turn });
}(window));
