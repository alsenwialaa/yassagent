'use strict';

const cases = [
    './cases/01-transport-and-retry',
    './cases/02-storage-and-continuity',
    './cases/03-reconciliation',
    './cases/04-media-identity-and-privacy',
    './cases/05-interaction-and-security',
    './cases/06-public-contract-and-cart',
    './cases/07-unicode-transcript-and-recovery'
];

for (const testCase of cases) {
    require(testCase);
}

require('./support/widget-harness').run();
