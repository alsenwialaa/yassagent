'use strict';

const { defineConfig } = require('@playwright/test');

const reporters = [['line']];
if (process.env.YSAI_JUNIT_OUTPUT) {
    reporters.push(['junit', { outputFile: process.env.YSAI_JUNIT_OUTPUT }]);
}
if (process.env.YSAI_JSON_OUTPUT) {
    reporters.push(['json', { outputFile: process.env.YSAI_JSON_OUTPUT }]);
}

module.exports = defineConfig({
    testDir: './specs',
    timeout: 90000,
    expect: { timeout: 20000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    reporter: reporters,
    outputDir: process.env.YSAI_TEST_OUTPUT_DIR || '/tmp/ysai-integration-test-results',
    use: {
        baseURL: process.env.YSAI_TEST_BASE_URL || 'http://wordpress',
        browserName: 'chromium',
        headless: true,
        trace: process.env.YSAI_PROMOTION_TRACE === '1' ? 'on' : 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure'
    }
});
