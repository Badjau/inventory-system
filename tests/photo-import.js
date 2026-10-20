'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { createHash, webcrypto } = require('node:crypto');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/photo-import-model.js'), 'utf8');
const context = vm.createContext({ Uint8Array, DataView, Int32Array });
vm.runInContext(source, context);
const model = context.InventoryPhotoImport;
const employees = [
  { id: 1, name: 'Ma. Clara Santos' }, { id: 2, name: 'Juan Dela Cruz' },
  { id: 3, name: 'Alex Santos' }, { id: 4, name: 'Alex Santos' },
];
assert.equal(model.rank('Maria Clara Santos', employees)[0].employee.id, 1);
assert.equal(model.rank('Maria Clara Santos', employees)[0].score, 96);
assert.equal(model.suggestedEmployee('Maria Clara Santos', employees), null);
assert.equal(model.rank('Jvan Dela Cruz', employees)[0].employee.id, 2);
assert.equal(model.suggestedEmployee('Jvan Dela Cruz', employees), null);
assert.equal(model.suggestedEmployee(' JUAN  DELA CRUZ ', employees), 2);
assert.equal(model.suggestedEmployee('Alex Santos', employees), null);
assert.equal(model.rank(null, employees).length, 0);
assert.equal(model.rank('Cruz Juan Dela', employees)[0].employee.id, 2);
assert.ok(model.rank('Someone Else', employees)[0].score < 90);
const devices = [
  { id: 10, holder_kind: 'employee', hr_employee_id: 2 },
  { id: 11, holder_kind: 'imported', holder_name: 'JUAN DELA CRUZ' },
  { id: 12, holder_kind: 'imported', holder_name: 'Alex Santos' },
  { id: 13, holder_kind: 'associate', holder_name: 'Juan Dela Cruz' },
];
assert.deepEqual(Array.from(model.devicesFor(devices, employees[1], employees), row => row.id), [10, 11]);
assert.equal(model.devicesFor(devices, employees[2], employees).length, 0);
assert.equal(model.devicesFor(devices, null, employees).length, 0);
const nested = { name: 'same.jpg', webkitRelativePath: 'Photos/A/same.jpg' };
const other = { name: 'same.jpg', webkitRelativePath: 'Photos/B/same.jpg' };
const rows = [{ file: 'A/same.jpg' }, { file: 'B/same.jpg' }, { file: 'missing.jpg' }];
assert.deepEqual(Array.from(model.linkFiles(rows, [nested, other], true)), [nested, other, null]);
assert.equal(model.linkFiles([{ file: 'same.jpg' }], [nested, other], false)[0], null);
assert.equal(model.linkFiles([{ file: 'A/same.jpg' }], [nested], false)[0], null);
assert.equal(model.linkFiles([{ file: 'same.jpg' }], [nested], false)[0], nested);
assert.throws(() => model.manifest({ records: [] }), /results.json/);
assert.throws(() => model.manifest({ records: [null] }), /record/);
assert.equal(model.manifest({ records: [{ file: 'one.jpg', status: 'ok', name: 'Juan', needs_review: false, contentHash: 'hash', secret: 'omitted' }] })[0].secret, undefined);
// Automatic selection respects the threshold, ties, and laptop identity.
assert.equal(model.automaticEmployee([{ employee: employees[1], score: 60 }, { employee: employees[0], score: 59 }]), 2);
assert.equal(model.automaticEmployee([{ employee: employees[1], score: 59 }]), null);
assert.equal(model.automaticEmployee([{ employee: employees[1], score: 80 }, { employee: employees[0], score: 80 }]), null);
assert.equal(model.automaticEmployee([]), null);
assert.equal(model.automaticEmployee(model.rank('Maria Clara Santos', employees)), 1);
assert.equal(model.automaticEmployee(model.rank('Jvan Dela Cruz', employees)), 2);
assert.equal(model.automaticEmployee(model.rank('Alex Santos', employees)), null);
const mixedDevices = [{ id: 20, device_type: 'Desktop' }, { id: 21, device_type: 'Laptop' }];
assert.equal(model.automaticLaptop(mixedDevices), 21);
assert.equal(model.automaticLaptop(mixedDevices), 21, 'Another photo can select the same laptop');
assert.equal(model.automaticLaptop([{ id: 20, device_type: 'Desktop' }]), null);
assert.equal(model.automaticLaptop([{ id: 21, device_type: 'Laptop' }, { id: 22, device_type: 'Laptop' }]), null);
const readyRow = { employeeId: 2, deviceId: 21, file: {}, importable: true, done: false };
assert.equal(model.uploadable(readyRow), true);
assert.equal(model.uploadable({ ...readyRow, employeeId: null }), false);
assert.equal(model.uploadable({ ...readyRow, deviceId: null }), false);
assert.equal(model.uploadable({ ...readyRow, file: null }), false);
assert.equal(model.uploadable({ ...readyRow, done: true }), false);
assert.equal(model.uploadable({ ...readyRow, importable: false }), false);
assert.equal([readyRow, { ...readyRow, employeeId: null }].filter(model.uploadable).length, 1, 'Unresolved rows do not block ready rows');
async function hashes() {
  for (const size of [0, 1, 3, 55, 56, 63, 64, 65, 1000, 1024 * 1024]) {
    const bytes = Buffer.alloc(size, 0xab);
    const file = { arrayBuffer: async () => bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) };
    const expected = createHash('sha256').update(bytes).digest('hex');
    assert.equal(await model.sha256(file), expected, `LAN fallback SHA-256 at ${size} bytes`);
    context.crypto = webcrypto;
    assert.equal(await model.sha256(file), expected, `WebCrypto SHA-256 at ${size} bytes`);
    delete context.crypto;
  }
  console.log('PASS: ranked names, Maria/Ma. review, duplicate names, numeric device links, relative paths, reduced manifests, and verified SHA-256 in secure/LAN contexts.');
}
hashes().catch(error => { console.error(error); process.exitCode = 1; });
