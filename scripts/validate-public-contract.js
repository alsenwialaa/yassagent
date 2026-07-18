#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const Ajv2020 = require('ajv/dist/2020');

const root = path.resolve(__dirname, '..');
const schemaPath = path.join(root, 'config/public-api-contract.json');
const fixturePath = path.join(root, 'tests/fixtures/public-api-contract-examples.json');

function readJson(file) {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch (error) {
        throw new Error(`Unable to read ${path.relative(root, file)}: ${error.message}`);
    }
}

function fail(message) {
    process.stderr.write(`${message}\n`);
    process.exit(1);
}

const schema = readJson(schemaPath);
const fixtures = readJson(fixturePath);
if (!fixtures || !Array.isArray(fixtures.valid) || !Array.isArray(fixtures.invalid)
    || fixtures.valid.length < 9 || fixtures.invalid.length < 9
) {
    fail('Public-contract fixtures must contain valid and invalid coverage for every endpoint schema.');
}

const ajv = new Ajv2020({
    allErrors: true,
    strict: true,
    // Conditional branches intentionally constrain properties declared by a parent schema.
    strictRequired: false
});
['x-contract-version', 'x-namespace', 'x-runtime'].forEach((keyword) => {
    ajv.addKeyword({ keyword, valid: true });
});

try {
    ajv.addSchema(schema);
} catch (error) {
    fail(`Public contract schema does not compile: ${error.message}`);
}

const endpointSchemas = schema['x-runtime'] && schema['x-runtime'].endpoint_schemas;
if (!endpointSchemas || typeof endpointSchemas !== 'object' || Array.isArray(endpointSchemas)) {
    fail('Public contract endpoint schema map is missing.');
}
const endpointNames = Object.keys(endpointSchemas);
const validators = {};
endpointNames.forEach((name) => {
    const ref = endpointSchemas[name];
    const validator = ajv.getSchema(`${schema.$id}${ref}`);
    if (typeof validator !== 'function') {
        fail(`Public contract endpoint schema did not compile: ${name}.`);
    }
    validators[name] = validator;
});

function validatorFor(row) {
    if (!row || typeof row !== 'object' || Array.isArray(row)
        || typeof row.name !== 'string' || !row.name
        || typeof row.schema !== 'string' || !validators[row.schema]
        || !Object.prototype.hasOwnProperty.call(row, 'value')
    ) {
        fail('A public-contract fixture row is malformed.');
    }
    return validators[row.schema];
}

function assertUniqueNames(rows, group) {
    const names = new Set();
    rows.forEach((row) => {
        validatorFor(row);
        if (names.has(row.name)) {
            fail(`Duplicate ${group} public-contract fixture name: ${row.name}.`);
        }
        names.add(row.name);
    });
}

assertUniqueNames(fixtures.valid, 'valid');
assertUniqueNames(fixtures.invalid, 'invalid');

const validCoverage = new Set(fixtures.valid.map((row) => row.schema));
const invalidCoverage = new Set(fixtures.invalid.map((row) => row.schema));
endpointNames.forEach((name) => {
    if (!validCoverage.has(name)) {
        fail(`Valid public-contract fixtures do not cover endpoint: ${name}.`);
    }
    if (!invalidCoverage.has(name)) {
        fail(`Invalid public-contract fixtures do not cover endpoint: ${name}.`);
    }
});

fixtures.valid.forEach((row) => {
    const validate = validatorFor(row);
    if (!validate(row.value)) {
        fail(`Valid public-contract fixture was rejected (${row.name}): ${ajv.errorsText(validate.errors, { separator: '\n' })}`);
    }
});

fixtures.invalid.forEach((row) => {
    const validate = validatorFor(row);
    if (validate(row.value)) {
        fail(`Invalid public-contract fixture was accepted: ${row.name}.`);
    }
});

process.stdout.write(
    `Public contract schema validated: ${fixtures.valid.length} valid examples accepted; `
    + `${fixtures.invalid.length} invalid examples rejected across ${endpointNames.length} endpoints.\n`
);
