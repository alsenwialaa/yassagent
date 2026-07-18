'use strict';

const { defineConfig } = require('@playwright/test');

const launchOptions = {};
if (process.env.CHROMIUM_PATH) {
    launchOptions.executablePath = process.env.CHROMIUM_PATH;
}

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: /\.spec\.js$/,
    timeout: 30000,
    fullyParallel: false,
    forbidOnly: true,
    retries: 0,
    workers: 1,
    reporter: 'line',
    use: {
        browserName: 'chromium',
        headless: true,
        launchOptions
    }
});
