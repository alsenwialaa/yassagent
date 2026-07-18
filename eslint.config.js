'use strict';

const js = require('@eslint/js');
const globals = require('globals');

const commonRules = {
    ...js.configs.recommended.rules,
    'no-control-regex': 'off',
    'no-empty': ['error', { allowEmptyCatch: true }],
    'no-irregular-whitespace': ['error', { skipComments: false, skipRegExps: true, skipStrings: true, skipTemplates: true }],
    'no-unused-vars': ['error', {
        argsIgnorePattern: '^_',
        caughtErrors: 'none',
        varsIgnorePattern: '^_'
    }]
};

module.exports = [
    {
        ignores: [
            'node_modules/**',
            'assets/js/widget.js',
            'integration/artifacts/**',
            'integration/promotion/runtime/**',
            'integration/promotion/artifacts/**',
            'release/**'
        ]
    },
    {
        files: ['assets/js/admin.js', 'assets/js/widget/**/*.js'],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script',
            globals: { ...globals.browser }
        },
        rules: commonRules
    },
    {
        files: ['tests/**/*.js', 'scripts/**/*.js', 'integration/**/*.js'],
        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'commonjs',
            globals: { ...globals.node, ...globals.browser }
        },
        rules: commonRules
    },
    {
        files: ['tests/js/cases/**/*.js'],
        languageOptions: {
            globals: {
                test: 'readonly', ok: 'readonly', same: 'readonly',
                fs: 'readonly', path: 'readonly', SESSION_TOKEN: 'readonly',
                writeUint32be: 'readonly', pngHeader: 'readonly', jpegHeader: 'readonly',
                webpVp8xHeader: 'readonly', fakeImageFile: 'readonly', response: 'readonly',
                canonicalCart: 'readonly', canonicalMessage: 'readonly',
                canonicalUserMessage: 'readonly', canonicalTurnResponse: 'readonly',
                turnSuccessAction: 'readonly', canonicalBoot: 'readonly',
                canonicalExportPage: 'readonly', loadRuntime: 'readonly'
            }
        }
    },
    {
        files: ['tests/browser/*.spec.js'],
        languageOptions: {
            globals: {
                test: 'readonly', expect: 'readonly', fs: 'readonly', bundle: 'readonly',
                stylesheet: 'readonly', adminStylesheet: 'readonly', adminScript: 'readonly',
                compressedPixelBomb: 'readonly', commonPhoneSource: 'readonly',
                origin: 'readonly', SESSION_TOKEN: 'readonly', emptyCart: 'readonly',
                assistantMessage: 'readonly', userMessage: 'readonly', pairedMessages: 'readonly',
                turnPayload: 'readonly', turnPayloadForEntry: 'readonly', bootPayload: 'readonly',
                install: 'readonly', openReady: 'readonly'
            }
        }
    }
];
