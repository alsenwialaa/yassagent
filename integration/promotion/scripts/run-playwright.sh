#!/usr/bin/env bash
set -Eeuo pipefail

node - <<'NODE' > /artifacts/environment-browser.json
'use strict';

const fs = require('fs');
const os = require('os');
const { chromium } = require('@playwright/test');
const playwright = require('/runner/node_modules/@playwright/test/package.json');

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const payload = {
            node_version: process.version,
            playwright_version: String(playwright.version || ''),
            browser_name: 'chromium',
            browser_version: browser.version(),
            browser_executable: chromium.executablePath(),
            platform: process.platform,
            architecture: process.arch,
            kernel: os.release(),
            plugin_version: String(process.env.YSAI_PROMOTION_PLUGIN_VERSION || ''),
            plugin_package_sha256: String(process.env.YSAI_PROMOTION_PLUGIN_SHA256 || '')
        };
        fs.writeFileSync('/artifacts/environment-browser.json', `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
    } finally {
        await browser.close();
    }
})().catch(error => {
    console.error(error && error.stack ? error.stack : String(error));
    process.exit(1);
});
NODE

exec node /runner/node_modules/@playwright/test/cli.js test \
  -c /workspace/plugin/integration/tests/playwright.config.js
