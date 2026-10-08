'use strict';
const assert = require('node:assert/strict');
const { escape, attention, displayDevice, filterDevices } = require('../public/assets/model.js');
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
      elements.set(match[1], { textContent: '', value: '', hidden: false, disabled: false,
        classList: { toggle() {} }, querySelectorAll: () => [],
        addEventListener(type, handler) { listeners[match[1] + ':' + type] = handler; },
        set innerHTML(value) { this.markup = value; addElements(value); },
        get innerHTML() { return this.markup || ''; } });
    }
  }
  addElements(page);
  const document = {
    querySelector(selector) { return selector === 'meta[name="inventory-role"]' ? { content: role } : selector === 'meta[name="csrf-token"]' ? { content: 'test' } : elements.get(selector.slice(1)) || null; },
    addEventListener(type, handler) { listeners['document:' + type] = handler; }
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
}
startupSmoke().then(() => startupSmoke('viewer')).catch(error => { console.error(error); process.exitCode = 1; });
