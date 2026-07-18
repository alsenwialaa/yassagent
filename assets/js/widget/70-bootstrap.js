(function (window) {
    'use strict';

    var Runtime = window.YSAIWidgetRuntime;
    var SELECTOR = '[data-ysai-widget="1"]';

    function WidgetMountManager(app) {
        this.app = app;
        this.roots = [];
        this.observer = null;
    }

    WidgetMountManager.prototype.attach = function (root) {
        if (!root || this.roots.indexOf(root) !== -1) {
            return;
        }
        this.roots.push(root);
        this.app.attach(root);
    };

    WidgetMountManager.prototype.scan = function (scope) {
        var self = this;
        if (!scope) {
            return;
        }
        if (scope.nodeType === 1 && typeof scope.matches === 'function' && scope.matches(SELECTOR)) {
            this.attach(scope);
        }
        if (typeof scope.querySelectorAll === 'function') {
            Array.prototype.forEach.call(scope.querySelectorAll(SELECTOR), function (root) {
                self.attach(root);
            });
        }
    };

    WidgetMountManager.prototype.prune = function () {
        var self = this;
        this.roots = this.roots.filter(function (root) {
            if (document.documentElement.contains(root)) {
                return true;
            }
            self.app.detach(root);
            return false;
        });
    };

    WidgetMountManager.prototype.start = function () {
        var self = this;
        this.scan(document);
        if (typeof window.MutationObserver !== 'function' || !document.body) {
            return;
        }
        this.observer = new window.MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes || [], function (node) {
                    self.scan(node);
                });
            });
            self.prune();
        });
        this.observer.observe(document.body, { childList: true, subtree: true });
    };


    function start() {
        if (window.__ysaiWidgetMountManager) {
            window.__ysaiWidgetMountManager.scan(document);
            return;
        }
        var app = window.__ysaiAssistantApp || new Runtime.AssistantApp(Runtime.config);
        var manager = new WidgetMountManager(app);
        window.__ysaiAssistantApp = app;
        window.__ysaiWidgetMountManager = manager;
        manager.start();
    }


    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}(window));
