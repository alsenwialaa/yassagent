#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

function fail(message) {
    process.stderr.write(`Browser suite verification failed: ${message}\n`);
    process.exit(1);
}

function readJson(filename, label) {
    let value;
    try {
        value = JSON.parse(fs.readFileSync(filename, 'utf8'));
    } catch (error) {
        fail(`${label} is not valid JSON: ${error.message}`);
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        fail(`${label} must be a JSON object.`);
    }
    return value;
}

function collectSpecs(suites, target) {
    (Array.isArray(suites) ? suites : []).forEach(suite => {
        (Array.isArray(suite.specs) ? suite.specs : []).forEach(spec => target.push(spec));
        collectSpecs(suite.suites, target);
    });
    return target;
}

function specKey(spec) {
    return `${String(spec.file || '')}:${Number(spec.line || 0)}`;
}

function assertNoRunnerErrors(report, label) {
    const errors = Array.isArray(report.errors) ? report.errors : [];
    if (errors.length > 0) {
        fail(`${label} reports ${errors.length} runner error(s).`);
    }
}

function discover(listFile, outputFile, browserDir) {
    const report = readJson(listFile, 'Playwright discovery report');
    assertNoRunnerErrors(report, 'Playwright discovery');
    const specs = collectSpecs(report.suites, []);
    if (specs.length === 0) {
        fail('Playwright discovered no browser cases.');
    }

    const expectedFiles = fs.readdirSync(browserDir)
        .filter(name => name.endsWith('.spec.js'))
        .sort();
    if (expectedFiles.length === 0) {
        fail('No top-level .spec.js files exist in the browser test directory.');
    }

    const seen = new Set();
    const discoveredFiles = new Set();
    const cases = specs.map(spec => {
        const file = String(spec.file || '');
        const line = Number(spec.line || 0);
        const title = String(spec.title || '');
        const id = String(spec.id || '');
        const tests = Array.isArray(spec.tests) ? spec.tests : [];
        const key = specKey(spec);
        if (!file || !Number.isInteger(line) || line < 1 || !title || !id) {
            fail(`Discovered case has incomplete identity: ${key}.`);
        }
        if (seen.has(key)) {
            fail(`Duplicate browser case location discovered: ${key}.`);
        }
        seen.add(key);
        discoveredFiles.add(path.basename(file));
        if (spec.ok !== true || tests.length !== 1 || tests[0].expectedStatus !== 'passed') {
            fail(`Case is skipped, fixed, duplicated across projects, or otherwise non-runnable: ${key} (${title}).`);
        }
        if (Array.isArray(tests[0].annotations) && tests[0].annotations.length > 0) {
            fail(`Case carries an annotation that can hide execution: ${key} (${title}).`);
        }
        return {
            id,
            file: path.basename(file),
            line,
            title,
            selector: `tests/browser/${path.basename(file)}:${line}`
        };
    });

    const actualFiles = Array.from(discoveredFiles).sort();
    if (JSON.stringify(actualFiles) !== JSON.stringify(expectedFiles)) {
        fail(`Discovered spec files ${JSON.stringify(actualFiles)} do not match ${JSON.stringify(expectedFiles)}.`);
    }

    fs.writeFileSync(outputFile, JSON.stringify({ count: cases.length, cases }, null, 2) + '\n');
    process.stdout.write(`Discovered ${cases.length} mandatory browser cases across ${actualFiles.length} spec file(s).\n`);
}

function verifyReport(reportFile, expectedFile) {
    const report = readJson(reportFile, 'Playwright execution report');
    const expected = readJson(expectedFile, 'Expected browser batch');
    assertNoRunnerErrors(report, 'Playwright execution');
    const cases = Array.isArray(expected.cases) ? expected.cases : [];
    if (cases.length === 0) {
        fail('Expected browser batch is empty.');
    }

    const stats = report.stats && typeof report.stats === 'object' ? report.stats : {};
    for (const field of ['expected', 'skipped', 'unexpected', 'flaky']) {
        if (!Number.isInteger(stats[field])) {
            fail(`Execution stats omit integer field ${field}.`);
        }
    }
    if (stats.expected !== cases.length || stats.skipped !== 0 || stats.unexpected !== 0 || stats.flaky !== 0) {
        fail(`Execution stats are not exact: ${JSON.stringify(stats)}; expected ${cases.length} clean passes.`);
    }

    const specs = collectSpecs(report.suites, []);
    if (specs.length !== cases.length) {
        fail(`Execution report contains ${specs.length} cases; expected ${cases.length}.`);
    }
    const expectedByKey = new Map(cases.map(item => [`${item.file}:${item.line}`, item]));
    const seen = new Set();
    specs.forEach(spec => {
        const key = `${path.basename(String(spec.file || ''))}:${Number(spec.line || 0)}`;
        const wanted = expectedByKey.get(key);
        if (!wanted || seen.has(key)) {
            fail(`Execution contains an unexpected or duplicate case: ${key}.`);
        }
        seen.add(key);
        if (String(spec.id || '') !== wanted.id || String(spec.title || '') !== wanted.title || spec.ok !== true) {
            fail(`Execution identity or status drifted for ${key}.`);
        }
        const tests = Array.isArray(spec.tests) ? spec.tests : [];
        if (tests.length !== 1 || tests[0].expectedStatus !== 'passed' || tests[0].status !== 'expected') {
            fail(`Case did not finish in the exact expected state: ${key}.`);
        }
        const results = Array.isArray(tests[0].results) ? tests[0].results : [];
        if (results.length !== 1 || results[0].status !== 'passed' || results[0].retry !== 0
            || (Array.isArray(results[0].errors) && results[0].errors.length > 0)) {
            fail(`Case was retried, failed, or produced hidden errors: ${key}.`);
        }
    });
    if (seen.size !== cases.length) {
        fail(`Only ${seen.size} of ${cases.length} expected cases were executed.`);
    }
    process.stdout.write(`Verified ${cases.length} browser cases with no skips, retries, flakes, or unexpected outcomes.\n`);
}

const [command, ...args] = process.argv.slice(2);
if (command === 'discover' && args.length === 3) {
    discover(args[0], args[1], args[2]);
} else if (command === 'report' && args.length === 2) {
    verifyReport(args[0], args[1]);
} else {
    fail('Usage: verify-browser-suite.js discover <list.json> <cases.json> <browser-dir> | report <report.json> <expected.json>');
}
