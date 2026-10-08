'use strict';
const assert = require('node:assert/strict');
const { escape, attention, displayDevice, filterDevices, importGroups } = require('../public/assets/model.js');
assert.equal(escape('<script>"&\''), '&lt;script&gt;&quot;&amp;&#39;');
assert.equal(attention({ device_condition: 'Unknown' }), false);
assert.equal(attention({ device_condition: 'Faulty' }), true);
const employee = { id: 10, name: 'New name', department: 'Sales', branch: 'Main', job_title: 'Manager' };
const current = displayDevice({ id: 1, name: 'Acer', device_type: 'Laptop', holder_kind: 'employee', hr_employee_id: 10,
  holder_name: 'Previous name', department: 'Old department', device_condition: 'Good' }, [employee]);
assert.equal(current.holder_name, 'New name');
assert.equal(filterDevices([current], { query: 'new NAME', department: 'Sales', branch: 'Main', employee: 10 }).length, 1);
assert.equal(filterDevices([current], { branch: 'Other' }).length, 0);
assert.equal(filterDevices([current], { view: 'attention' }).length, 0);
assert.equal(filterDevices([{ ...current, holder_kind: 'unassigned' }], { status: 'available' }).length, 1);
assert.equal(displayDevice({ holder_kind: 'imported', holder_name: 'Workbook holder', location_branch_id: 3, location: 'Old name' }, [], [{ id: 3, name: 'APO' }]).location, 'APO');
console.log('PASS: frontend escaping, HR display, and inventory filters.');
const reviewGroups = [{ id: 1, label: 'First name', records: [{ name: 'Desktop' }], conflict: false },
  { id: 2, label: 'Second name', records: [{ name: 'Laptop' }], conflict: false }];
const reviewChoices = { 1: { employeeId: 10, selected: true, resolved: true }, 2: { employeeId: 10, selected: true, resolved: true } };
assert.equal(importGroups(reviewGroups, reviewChoices).length, 1);
assert.equal(importGroups(reviewGroups, reviewChoices)[0].records.length, 2);
assert.equal(importGroups(reviewGroups, { ...reviewChoices, 2: { ...reviewChoices[2], employeeId: null } }).length, 2);
assert.equal(importGroups([{ ...reviewGroups[0], conflict: true }], { 1: { ...reviewChoices[1], resolved: false } })[0].conflict, true);
assert.equal(importGroups(reviewGroups, { ...reviewChoices, 2: { ...reviewChoices[2], selected: false } })[0].selected, false);

async function startupSmoke(role = 'editor') {
  const { readFileSync } = require('node:fs');
  const page = readFileSync(__dirname + '/../public/index.php', 'utf8');
  const app = readFileSync(__dirname + '/../public/assets/app.js', 'utf8');
  assert.equal(page.includes('Holder review'), false);
  assert.equal(app.includes('holder-links'), false);
  const elements = new Map();
  const listeners = {};
  function addElements(markup) {
    for (const match of markup.matchAll(/id="([^"]+)"/g)) {
      if (elements.has(match[1])) continue;
      elements.set(match[1], { textContent: '', value: '', hidden: false, disabled: false, files: [],
        showModal() { this.open = true; }, close() { this.open = false; },
        classList: { toggle() {} }, querySelectorAll: () => [],
        addEventListener(type, handler) { listeners[match[1] + ':' + type] = handler; },
        set innerHTML(value) { this.markup = value; addElements(value); },
        get innerHTML() { return this.markup || ''; } });
    }
  }
  addElements(page);
  const document = {
    querySelector(selector) { return selector === 'meta[name="inventory-role"]' ? { content: role } : selector === 'meta[name="csrf-token"]' ? { content: 'test' } : elements.get(selector.slice(1)) || null; },
    addEventListener(type, handler) { listeners['document:' + type] = handler; },
    dispatchEvent(event) { listeners['document:' + event.type]?.(event); }
  };
  const devices = [
    { id: 1, name: 'Recorded holder laptop', device_type: 'Laptop', holder_kind: 'imported', holder_name: 'Recorded holder',
      device_condition: 'Good', specifications: {} },
    { id: 2, name: 'Unassigned mouse', device_type: 'Mouse', holder_kind: 'unassigned', device_condition: 'Good', specifications: {} },
    { id: 3, name: 'Faulty monitor', device_type: 'Monitor', holder_kind: 'unassigned', device_condition: 'Faulty', specifications: {} }
  ];
  const fetch = async url => ({ ok: true, json: async () => ({ data: url.includes('action=history') ? [] :
    { devices, employees: [], departments: [], branches: [], activity: [] } }) });
  new Function('document', 'InventoryModel', 'fetch', app)(document, require('../public/assets/model.js'), fetch);
  for (let i = 0; i < 10; i++) await Promise.resolve();
  assert.equal(elements.get('add-device').disabled, role === 'viewer');
  assert.equal(elements.get('stats').innerHTML.match(/data-stat=/g).length, 4);
  assert.equal(elements.get('table-body').innerHTML.includes('Recorded holder laptop'), true);
  listeners['document:click']({ target: { closest: () => ({ dataset: { stat: 'available' } }) } });
  assert.equal(elements.get('table-body').innerHTML.includes('Unassigned mouse'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('Recorded holder laptop'), false);
  listeners['document:click']({ target: { closest: () => ({ dataset: { stat: 'attention' } }) } });
  assert.equal(elements.get('table-body').innerHTML.includes('Faulty monitor'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('Unassigned mouse'), false);
  console.log('PASS: app startup, removed Holder Review, and summary card views.');
  const importApp = readFileSync(__dirname + '/../public/assets/import.js', 'utf8');
  const requests = [];
  const source = { sheet: 'PC', row: 2 };
  const record = { name: '<script>Desktop</script>', operation: 'Update', target_id: 1,
    holder_kind: 'imported', holder_name: 'Workbook name', device_type: 'Desktop', source,
    specifications: { OS: 'Windows', 'MICROSOFT 365': 'Yes', VIBER: '' }, before: { specifications: { VIBER: 'Old' } } };
  const preview = { token: 'preview-token', groups: [
    { id: 1, label: 'First', hr_employee_id: 10, conflict: false, records: [record, { ...record, name: 'Keyboard' }] },
    { id: 2, label: 'Second', hr_employee_id: null, conflict: false, records: [{ ...record, name: 'Laptop' }] }
  ], employees: [{ id: 10, name: 'Alex', employee_id: 'E001', department: 'IT' }] };
  const importFetch = async (url, options) => {
    requests.push({ url, options });
    return { ok: true, json: async () => ({ data: url.includes('import-preview') ? preview : { added: 1, updated: 2 } }) };
  };
  class TestFormData { constructor() { this.values = []; } append(key, value) { this.values.push([key, value]); } }
  class TestEvent { constructor(type) { this.type = type; } }
  new Function('document', 'InventoryModel', 'fetch', 'FormData', 'Event', importApp)(document, require('../public/assets/model.js'), importFetch, TestFormData, TestEvent);
  assert.equal(elements.get('import-excel').hidden, role === 'viewer');
  if (role === 'viewer') return;
  listeners['import-excel:click']();
  assert.equal(elements.get('import-dialog').open, true);
  elements.get('import-file').files = [{ name: 'inventory.xlsx', size: 1024 }];
  await listeners['import-form:submit']({ preventDefault() {} });
  assert.equal(requests[0].options.headers['X-CSRF-Token'], 'test');
  assert.equal(requests[0].options.headers['Content-Type'], undefined);
  assert.equal(elements.get('import-review').hidden, false);
  assert.equal(elements.get('import-groups').innerHTML.includes('MICROSOFT 365'), true);
  assert.equal(elements.get('import-groups').innerHTML.includes('&lt;script&gt;Desktop&lt;/script&gt;'), true);
  assert.equal(elements.get('import-groups').innerHTML.includes('Blank — clears value'), true);
  listeners['import-groups:change']({ target: { dataset: { importEmployee: '1' }, value: '10' } });
  assert.equal(elements.get('import-groups').innerHTML.match(/data-import-group=/g).length, 1);
  assert.equal(elements.get('import-status').textContent, '1 groups selected · 3 devices');
  listeners['clear-import:click']();
  assert.equal(elements.get('approve-import').disabled, true);
  listeners['select-import:click']();
  await listeners['approve-import:click']();
  const approval = JSON.parse(requests[1].options.body);
  assert.equal(approval.token, 'preview-token');
  assert.equal(approval.groups.length, 2);
  assert.equal(approval.groups.every(group => group.hr_employee_id === 10), true);
  assert.equal(elements.get('import-dialog').open, false);
  assert.equal(elements.get('notice').textContent, 'Excel import complete: 1 added, 2 updated.');
  console.log('PASS: Excel upload, escaped full specification preview, regrouping, batch approval, and viewer controls.');
}
startupSmoke().then(() => startupSmoke('viewer')).catch(error => { console.error(error); process.exitCode = 1; });
