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
const specRows = [
  { ...current, id: 20, specifications: { RAM: '16GB DDR4', OS: 'Windows 11 Pro', 'MAC address': '38-D5-47-20-0E-6C', 'Microsoft 365': 'Premium', Notes: 'Line one\nLine two' } },
  { ...current, id: 21, specifications: { RAM: '8GB', OS: 'Windows 10', 'Microsoft 365': '' } },
  { ...current, id: 22, serial_number: '38:D5:47:20:0E:6C', specifications: { PROGRAM: '16GB' } },
  { ...current, id: 23 }
];
assert.equal(filterDevices(specRows, { query: 'RAM: 16GB' })[0].id, 20);
assert.equal(filterDevices(specRows, { query: 'RAM: 16GB' }).length, 1);
assert.equal(filterDevices(specRows, { query: '  ram :   16gb  ' }).length, 1);
assert.equal(filterDevices(specRows, { query: 'OS: Windows 11' })[0].id, 20);
assert.equal(filterDevices(specRows, { query: ' MICROSOFT   365: prem ' })[0].id, 20);
assert.equal(filterDevices(specRows, { query: 'Notes: line one line two' })[0].id, 20);
assert.equal(filterDevices(specRows, { query: 'Microsoft 365:' }).length, 1);
assert.equal(filterDevices(specRows, { query: 'MAC address: 38-D5' })[0].id, 20);
assert.equal(filterDevices(specRows, { query: 'RAM: 32GB' }).length, 0);
assert.equal(filterDevices(specRows, { query: 'RAM: 16GB', branch: 'Other' }).length, 0);
assert.equal(filterDevices(specRows, { query: '38:D5:47' })[0].id, 22);
assert.equal(filterDevices(specRows, { query: 'Acer' }).length, 4);
console.log('PASS: frontend escaping, HR display, and inventory filters.');
const reviewGroups = [{ id: 1, label: 'First name', records: [{ name: 'Desktop' }], conflict: false },
  { id: 2, label: 'Second name', records: [{ name: 'Laptop' }], conflict: false }];
const reviewChoices = { 1: { employeeId: 10, selected: true, resolved: true }, 2: { employeeId: 10, selected: true, resolved: true } };
assert.equal(importGroups(reviewGroups, reviewChoices).length, 1);
assert.equal(importGroups(reviewGroups, reviewChoices)[0].records.length, 2);
assert.equal(importGroups(reviewGroups, { ...reviewChoices, 2: { ...reviewChoices[2], employeeId: null } }).length, 2);
assert.equal(importGroups([{ ...reviewGroups[0], conflict: true }], { 1: { ...reviewChoices[1], resolved: false } })[0].conflict, true);
assert.equal(importGroups(reviewGroups, { ...reviewChoices, 2: { ...reviewChoices[2], selected: false } })[0].selected, false);
const associateDevice = { id: 99, holder_kind: 'associate', associate_id: 10, hr_employee_id: null };
assert.equal(filterDevices([current, associateDevice], { employee: -10 })[0].id, 99);
assert.equal(filterDevices([current, associateDevice], { employee: 10 }).length, 1);

async function startupSmoke(role = 'editor') {
  const { readFileSync } = require('node:fs');
  const page = readFileSync(__dirname + '/../public/index.php', 'utf8');
  const app = readFileSync(__dirname + '/../public/assets/app.js', 'utf8');
  assert.equal(page.includes('Holder review'), false);
  assert.equal(app.includes('holder-links'), false);
  const elements = new Map();
  const listeners = {};
  const thumbnails = [];
  const observers = [];
  class TestObserver {
    constructor(callback) { this.callback = callback; this.targets = []; observers.push(this); }
    observe(image) { this.targets.push(image); }
    unobserve(image) { this.targets = this.targets.filter(target => target !== image); }
    disconnect() { this.targets = []; }
  }
  function addElements(markup) {
    for (const match of markup.matchAll(/id="([^"]+)"/g)) {
      if (elements.has(match[1])) continue;
      elements.set(match[1], { textContent: '', value: '', hidden: false, disabled: false, files: [],
        showModal() { this.open = true; }, close() { this.open = false; },
        classList: { toggle() {} }, querySelectorAll(selector) {
          if (selector === '[data-spec-row]') return [...this.innerHTML.matchAll(/<input maxlength="100" value="([^"]*)" required><\/label><label>Value<textarea rows="2" maxlength="4000">([\s\S]*?)<\/textarea>/g)].map(match => ({
            querySelector(selector) { return { value: match[selector === 'input' ? 1 : 2] }; }
          }));
          if (selector !== '[data-thumbnail]') return [];
          return [...this.innerHTML.matchAll(/data-thumbnail="([^"]+)"/g)].map(match => {
            const image = { dataset: { thumbnail: match[1] }, classList: { add() {} }, addEventListener() {}, remove() {} };
            thumbnails.push(image);
            return image;
          });
        },
        addEventListener(type, handler) { listeners[match[1] + ':' + type] = handler; },
        insertAdjacentHTML(position, value) { this.innerHTML += value; },
        set innerHTML(value) { this.markup = value; addElements(value); },
        get innerHTML() { return this.markup || ''; } });
    }
  }
  addElements(page);
  const deviceTypeSelector = { value: 'Laptop', addEventListener(type, handler) { listeners['device-type:' + type] = handler; } };
  const document = {
    querySelector(selector) { return selector === '#dialog-fields select[name="device_type"]' ? deviceTypeSelector : selector === 'meta[name="inventory-role"]' ? { content: role } : selector === 'meta[name="csrf-token"]' ? { content: 'test' } : elements.get(selector.slice(1)) || null; },
    addEventListener(type, handler) { listeners['document:' + type] = handler; },
    dispatchEvent(event) { listeners['document:' + event.type]?.(event); }
  };
  const devices = [
    { id: 1, name: 'Recorded holder laptop', device_type: 'Laptop', holder_kind: 'imported', holder_name: 'Recorded holder',
      device_condition: 'Good', specifications: { 'Original laptop spec': 'Keep this value' }, photo_event_id: 7 },
    { id: 2, name: 'Unassigned mouse', device_type: 'Mouse', holder_kind: 'unassigned', device_condition: 'Good', specifications: {} },
    { id: 3, name: 'Faulty monitor', device_type: 'Monitor', holder_kind: 'unassigned', device_condition: 'Faulty', specifications: {} }
  ];
  const fetch = async url => ({ ok: true, json: async () => ({ data: url.includes('action=history') ? (url.endsWith('id=2') ? [
    { id: 9, message: 'Uploaded photos', photos: [{ name: 'Removed.jpg' }, { name: 'Kept.jpg' }], removed_photo_indexes: [0] }
  ] : []) :
    { desktop_preset: { version: 0, device_types: ['Laptop', 'Desktop', 'Monitor', 'Printer', 'Keyboard', 'Mouse', 'Other', 'Router'], fields: [{ name: 'MAC ADDRESS', value: '' }, { name: 'Custom <spec>', value: '<default>' }] }, devices, employees: [], associates: [{ id: 10, name: '<Associate>', job_title: 'Partner' }], departments: [], branches: [], activity: [
      { device_id: 1, message: 'Device edited', actor: { id: 1, name: '<Admin>', username: 'audit-user' } },
      { device_id: 2, message: 'Legacy edit', actor: null }
    ] } }) });
  new Function('document', 'InventoryModel', 'fetch', 'IntersectionObserver', 'InventoryPhotos', app)(document, require('../public/assets/model.js'), fetch, TestObserver, { attach: () => () => [] });
  for (let i = 0; i < 10; i++) await Promise.resolve();
  assert.equal(elements.get('add-device').disabled, role === 'viewer');
  assert.equal(elements.get('stats').innerHTML.match(/data-stat=/g).length, 4);
  assert.equal(elements.get('table-body').innerHTML.includes('Recorded holder laptop'), true);
  assert.equal(thumbnails[0].src, undefined);
  const portraitObserver = observers[observers.length - 1];
  portraitObserver.callback([{ target: thumbnails[0], isIntersecting: false }]);
  assert.equal(thumbnails[0].src, undefined);
  portraitObserver.callback([{ target: thumbnails[0], isIntersecting: true }]);
  assert.equal(thumbnails[0].src.includes('event=7'), true);
  assert.equal(thumbnails[0].src.includes('thumbnail=1'), true);
  assert.equal(portraitObserver.targets.length, 0);
  assert.equal(elements.get('detail').hidden, true);
  listeners['document:click']({ target: { closest: () => ({ dataset: { device: '1' } }) } });
  for (let i = 0; i < 10; i++) await Promise.resolve();
  assert.equal(elements.get('detail').hidden, false);
  assert.equal(elements.get('detail').innerHTML.includes('Current assignment'), true);
  assert.equal(elements.get('detail').innerHTML.includes('Evidence photos'), true);
  assert.equal(elements.get('device-photos').innerHTML.includes('No current photos'), true);
  listeners['document:click']({ target: { closest: () => ({ id: 'close-detail', dataset: {} }) } });
  assert.equal(elements.get('detail').hidden, true);
  listeners['document:click']({ target: { closest: () => ({ dataset: { device: '2' } }) } });
  for (let i = 0; i < 10; i++) await Promise.resolve();
  assert.equal(elements.get('device-photos').innerHTML.includes('Removed.jpg'), false);
  assert.equal(elements.get('device-photos').innerHTML.includes('Kept.jpg'), true);
  assert.equal(elements.get('device-photos').innerHTML.includes('data-remove-photo="1"'), role !== 'viewer');
  assert.equal(elements.get('device-photos').innerHTML.includes('data-remove-photo="0"'), false);
  assert.equal(elements.get('history').innerHTML.includes('Removed.jpg'), true);
  assert.equal(elements.get('history').innerHTML.includes('retained in history'), true);
  let escaped = false;
  listeners['document:keydown']({ key: 'Escape', preventDefault() { escaped = true; } });
  assert.equal(escaped, true);
  assert.equal(elements.get('detail').hidden, true);
  listeners['document:click']({ target: { closest: () => ({ dataset: { stat: 'available' } }) } });
  assert.equal(elements.get('table-body').innerHTML.includes('Unassigned mouse'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('Recorded holder laptop'), false);
  listeners['document:click']({ target: { closest: () => ({ dataset: { stat: 'attention' } }) } });
  assert.equal(elements.get('table-body').innerHTML.includes('Faulty monitor'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('Unassigned mouse'), false);
  listeners['document:click']({ target: { closest: () => ({ dataset: { view: 'people' } }) } });
  assert.equal(elements.get('add-device').textContent, '＋ Add User');
  assert.equal(elements.get('table-body').innerHTML.includes('&lt;Associate&gt;'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('data-person="-10"'), true);
  listeners['document:click']({ target: { closest: () => ({ dataset: { person: '-10' } }) } });
  assert.equal(elements.get('add-device').textContent, '＋ Add device');
  assert.equal(elements.get('table-body').innerHTML.includes('Recorded holder laptop'), false);
  console.log('PASS: app startup, removed Holder Review, and summary card views.');
  listeners['document:click']({ target: { closest: () => ({ dataset: { view: 'preset' } }) } });
  assert.equal(elements.get('inventory-layout').hidden, true);
  assert.equal(elements.get('preset-panel').hidden, false);
  assert.equal(elements.get('stats').hidden, true);
  assert.equal(elements.get('preset-panel').innerHTML.includes('MAC ADDRESS'), true);
  assert.equal(elements.get('preset-panel').innerHTML.includes('Custom &lt;spec&gt;'), true);
  assert.equal(elements.get('preset-panel').innerHTML.includes('&lt;default&gt;'), true);
  assert.equal(elements.get('add-device').textContent, '＋ Create Desktop');
  assert.equal(elements.get('preset-panel').innerHTML.includes('Create Device Type'), true);
  assert.equal(elements.get('type-filter').innerHTML.includes('Router'), true);
  if (role !== 'viewer') {
    listeners['create-device-type:click']();
    assert.equal(elements.get('dialog-title').textContent, 'Create Device Type');
    assert.equal(elements.get('dialog-fields').innerHTML.includes('name="name" maxlength="30" required'), true);
    listeners['document:click']({ target: { closest: () => ({ id: 'add-device', dataset: {} }) } });
    assert.equal(elements.get('spec-editor').querySelectorAll('[data-spec-row]').length, 2);
    assert.equal(elements.get('spec-editor').innerHTML.includes('&lt;default&gt;'), true);
    assert.equal(elements.get('dialog-fields').innerHTML.includes('Router'), true);
    deviceTypeSelector.value = 'Laptop';
    listeners['device-type:change']();
    assert.equal(elements.get('spec-editor').innerHTML, '');
    deviceTypeSelector.value = 'Router';
    listeners['device-type:change']();
    assert.equal(elements.get('spec-editor').innerHTML, '');
    deviceTypeSelector.value = 'Desktop';
    listeners['device-type:change']();
    assert.equal(elements.get('spec-editor').querySelectorAll('[data-spec-row]').length, 2);
    listeners['document:click']({ target: { closest: () => ({ dataset: { view: 'devices' } }) } });
    listeners['document:click']({ target: { closest: () => ({ dataset: { device: '1' } }) } });
    elements.get('spec-editor').innerHTML = '';
    listeners['document:click']({ target: { closest: () => ({ id: 'edit-device', dataset: {} }) } });
    assert.equal(elements.get('spec-editor').innerHTML.includes('Original laptop spec'), true);
    assert.equal(elements.get('spec-editor').innerHTML.includes('Keep this value'), true);
    assert.equal(elements.get('spec-editor').innerHTML.includes('MAC ADDRESS'), false);
    console.log('PASS: custom type choices, Desktop defaults, empty other types, and existing specifications.');
  }
  listeners['document:click']({ target: { closest: () => ({ dataset: { view: 'activity' } }) } });
  assert.equal(elements.get('inventory-layout').hidden, false);
  assert.equal(elements.get('table-head').innerHTML.includes('Changed by'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('&lt;Admin&gt;'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('Not recorded'), true);
  elements.get('search').value = 'AUDIT-USER';
  listeners['search:input']();
  assert.equal(elements.get('table-body').innerHTML.includes('Device edited'), true);
  assert.equal(elements.get('table-body').innerHTML.includes('Legacy edit'), false);
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
