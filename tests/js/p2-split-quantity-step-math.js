'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const client = fs.readFileSync(path.join(root, 'js/p2-split-admin.js'), 'utf8');
const hooks = {};

vm.runInNewContext(client, {
    window: {
        wcosSplitAdminStrings: {},
        wcosSplitAdminTestHooks: hooks
    },
    document: {
        querySelector() { return null; }
    },
    String,
    BigInt,
    Error
});

assert.equal(hooks.decimalToUnits('0.1') + hooks.decimalToUnits('0.2'), 300000n, '0.1 + 0.2 must use exact integer units');
assert.equal(hooks.unitsToDecimal(300000n), '0.3', 'exact units must format as a canonical decimal string');
assert.equal(hooks.decimalToUnits('0.250000'), 250000n);
assert.equal(hooks.decimalToUnits('999999999999.999999'), 999999999999999999n, 'large valid quantities must not lose precision');

for (const invalid of ['1e0', '0,25', '-0.25', '0.0000001', '01.00', '', 'NaN']) {
    assert.throws(() => hooks.decimalToUnits(invalid), undefined, `invalid decimal syntax must fail: ${invalid}`);
}

function row(attributes) {
    return {
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null;
        }
    };
}

const quarter = hooks.rowQuantityAuthority(row({
    'data-source-units': '3500000',
    'data-step-units': '250000',
    'data-maximum-units': '3250000',
    'data-splittable': '1'
}));
assert.equal(quarter.source, 3500000n);
assert.equal(quarter.step, 250000n);
assert.equal(hooks.decimalToUnits('1.75') % quarter.step, 0n);
assert.notEqual(hooks.decimalToUnits('0.1') % quarter.step, 0n);
assert.notEqual(hooks.decimalToUnits('0.000001') % quarter.step, 0n);

const integer = hooks.rowQuantityAuthority(row({
    'data-source-units': '4000000',
    'data-step-units': '1000000',
    'data-maximum-units': '3000000',
    'data-splittable': '1'
}));
assert.notEqual(hooks.decimalToUnits('1.000001') % integer.step, 0n);
assert.equal(integer.maximum, 3000000n);

assert.throws(() => hooks.rowQuantityAuthority(row({
    'data-source-units': '1000000',
    'data-step-units': '250000',
    'data-maximum-units': '1000000',
    'data-splittable': '1'
})), undefined, 'a browser-authored maximum must not pass client integrity checks');

console.log('Split quantity-step integer-unit math regression passed.');
