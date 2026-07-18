(function () {
    'use strict';

    function setupConnectionTest() {
        var button = document.getElementById('ysai-test-connection');
        var result = document.getElementById('ysai-test-result');
        if (!button || !result || typeof window.YSAIAdmin !== 'object') {
            return;
        }

        button.addEventListener('click', function () {
            var controller = new AbortController();
            var timeoutMs = Number(window.YSAIAdmin.timeoutMs);
            if (!Number.isFinite(timeoutMs) || timeoutMs < 1000) {
                timeoutMs = 80000;
            }
            var timeoutId = window.setTimeout(function () {
                controller.abort();
            }, timeoutMs);
            button.disabled = true;
            result.className = '';
            result.textContent = window.YSAIAdmin.testing;

            fetch(window.YSAIAdmin.testUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.YSAIAdmin.nonce
                },
                body: '{}',
                signal: controller.signal
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (payload) {
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || window.YSAIAdmin.failed);
                    }
                    return payload;
                });
            }).then(function () {
                result.className = 'is-success';
                result.textContent = window.YSAIAdmin.connected;
            }).catch(function (error) {
                result.className = 'is-error';
                result.textContent = error && error.name === 'AbortError'
                    ? window.YSAIAdmin.timedOut
                    : (error && error.message ? error.message : window.YSAIAdmin.failed);
            }).then(function () {
                window.clearTimeout(timeoutId);
                button.disabled = false;
            });
        });
    }

    function setupRangeOutputs() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ysai-range="1"]'), function (input) {
            var output = input.parentNode ? input.parentNode.querySelector('output') : null;
            var update = function () {
                if (output) {
                    output.value = String(input.value || '');
                    output.textContent = String(input.value || '');
                }
            };
            input.addEventListener('input', update);
            update();
        });
    }

    function setupAppearancePreview() {
        var preview = document.querySelector('[data-ysai-preview="1"]');
        if (!preview) {
            return;
        }

        var previewMark = preview.querySelector('.ysai-preview-mark');
        var previewIcon = previewMark ? previewMark.querySelector('img') : null;
        if (previewIcon) {
            var useFallback = function () {
                previewMark.classList.remove('has-site-icon');
            };
            previewIcon.addEventListener('error', useFallback, { once: true });
            if (previewIcon.complete && previewIcon.naturalWidth === 0) {
                useFallback();
            }
        }

        var styleMap = {
            'ysai-widget-brand-color': '--ysai-brand',
            'ysai-widget-header-background-color': '--ysai-header-bg',
            'ysai-widget-header-foreground-color': '--ysai-header-fg',
            'ysai-widget-chat-background': '--ysai-chat-bg',
            'ysai-widget-surface-color': '--ysai-surface',
            'ysai-widget-assistant-bubble-color': '--ysai-assistant-bubble',
            'ysai-widget-user-bubble-color': '--ysai-user-bubble',
            'ysai-widget-user-text-color': '--ysai-user-text',
            'ysai-widget-text-color': '--ysai-text',
            'ysai-widget-muted-color': '--ysai-muted',
            'ysai-widget-border-color': '--ysai-border'
        };
        var pixelMap = {
            'ysai-widget-panel-width': '--ysai-panel-width',
            'ysai-widget-panel-height': '--ysai-panel-height',
            'ysai-widget-panel-radius': '--ysai-panel-radius',
            'ysai-widget-bubble-radius': '--ysai-bubble-radius',
            'ysai-widget-product-card-radius': '--ysai-card-radius',
            'ysai-widget-font-size': '--ysai-font-size'
        };
        var ratioMap = {
            '1-1': '1 / 1',
            '4-3': '4 / 3',
            '3-4': '3 / 4',
            '16-9': '16 / 9'
        };

        function value(id, fallback) {
            var input = document.getElementById(id);
            return input ? String(input.value || fallback) : String(fallback || '');
        }

        function render() {
            Object.keys(styleMap).forEach(function (id) {
                preview.style.setProperty(styleMap[id], value(id, ''));
            });
            Object.keys(pixelMap).forEach(function (id) {
                preview.style.setProperty(pixelMap[id], value(id, '0') + 'px');
            });

            var layout = value('ysai-widget-product-layout', 'carousel');
            preview.classList.toggle('is-layout-carousel', layout === 'carousel');
            preview.classList.toggle('is-layout-grid', layout === 'grid');
            preview.classList.toggle('is-layout-list', layout === 'list');
            preview.style.setProperty('--ysai-product-cards', value('ysai-widget-product-cards-per-view', '1'));
            preview.style.setProperty('--ysai-product-ratio', ratioMap[value('ysai-widget-product-image-ratio', '1-1')] || '1 / 1');

            var descriptions = document.getElementById('ysai-widget-product-show-description');
            preview.classList.toggle('is-show-description', Boolean(descriptions && descriptions.checked));

            var title = document.getElementById('ysai-widget-title');
            var previewTitle = preview.querySelector('[data-ysai-preview-title]');
            if (title && previewTitle) {
                previewTitle.textContent = String(title.value || 'Assistant');
            }
        }

        var controls = Object.keys(styleMap).concat(Object.keys(pixelMap), [
            'ysai-widget-title',
            'ysai-widget-product-layout',
            'ysai-widget-product-cards-per-view',
            'ysai-widget-product-image-ratio',
            'ysai-widget-product-show-description'
        ]);
        controls.forEach(function (id) {
            var input = document.getElementById(id);
            if (!input) {
                return;
            }
            input.addEventListener('input', render);
            input.addEventListener('change', render);
        });
        render();
    }

    setupConnectionTest();
    setupRangeOutputs();
    setupAppearancePreview();
}());
