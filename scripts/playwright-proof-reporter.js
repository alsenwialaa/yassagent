'use strict';

const fs = require('fs');
const path = require('path');

class PlaywrightProofReporter {
    constructor() {
        this.outputFile = String(process.env.YSAI_PLAYWRIGHT_PROOF_OUTPUT || '');
        this.expected = 0;
        this.results = new Map();
    }

    onBegin(config, suite) {
        this.expected = suite.allTests().length;
    }

    onTestEnd(test, result) {
        const file = path.basename(String((test.location && test.location.file) || ''));
        const line = Number((test.location && test.location.line) || 0);
        const key = `${file}:${line}`;
        this.results.set(key, {
            file,
            line,
            title: String(test.title || ''),
            expected_status: String(test.expectedStatus || ''),
            status: String(result.status || ''),
            retry: Number(result.retry || 0),
            errors: Array.isArray(result.errors) ? result.errors.length : 0
        });
    }

    onEnd(result) {
        if (!this.outputFile) {
            return;
        }
        const cases = Array.from(this.results.values()).sort((left, right) => (
            left.file.localeCompare(right.file) || left.line - right.line
        ));
        const clean = String(result.status || '') === 'passed'
            && this.expected > 0
            && cases.length === this.expected
            && cases.every(item => item.expected_status === 'passed'
                && item.status === 'passed'
                && item.retry === 0
                && item.errors === 0
            );
        const proof = {
            schema_version: 1,
            status: String(result.status || ''),
            expected: this.expected,
            completed: cases.length,
            clean,
            cases
        };
        const target = path.resolve(this.outputFile);
        const temporary = `${target}.tmp-${process.pid}`;
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.writeFileSync(temporary, `${JSON.stringify(proof, null, 2)}\n`, { encoding: 'utf8', mode: 0o600 });
        fs.renameSync(temporary, target);
    }

    printsToStdio() {
        return false;
    }
}

module.exports = PlaywrightProofReporter;
