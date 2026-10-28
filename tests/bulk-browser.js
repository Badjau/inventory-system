'use strict';
// Real DOM smoke checks with synthetic API responses; no login or live inventory writes.
// Run with Node 24+ and Chrome/Edge, or set BROWSER_PATH to a Chromium executable.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const executable = [process.env.BROWSER_PATH, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(file => file && fs.existsSync(file));
if (!executable) throw new Error('Set BROWSER_PATH to a Chromium executable.');
let page = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8');
page = page.slice(page.indexOf('<!doctype html>')).replace(/<\?= \$user\['role'\] \?>/g, 'editor').replace(/<\?[\s\S]*?\?>/g, 'test');
const devices = [
  { id: 1, version: 1, name: 'Laptop <one>', device_type: 'Laptop', lifecycle_status: 'active', holder_kind: 'imported', holder_name: 'Old holder', device_condition: 'Good', specifications: { 'MICROSOFT 365': 'No', Keep: 'Original' } },
  { id: 2, version: 1, name: 'Laptop two', device_type: 'Laptop', lifecycle_status: 'active', holder_kind: 'unassigned', device_condition: 'Faulty', specifications: {} },
  { id: 3, version: 1, name: 'Disposed', device_type: 'Monitor', lifecycle_status: 'for_disposal', holder_kind: 'unassigned', device_condition: 'Unknown', specifications: {} }
];
const state = { devices, employees: [{ id: 1, employee_id: 'E1', name: 'Alex', department: 'Accounting' }], associates: [], branches: [{ id: 3, name: 'APO' }], departments: [{ id: 1, name: 'Accounting' }], activity: [] };
const posts = [];
let conflict = false, noOp = false, role = 'editor';
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname === '/api.php') {
    let body = '';
    for await (const chunk of req) body += chunk;
    const input = body ? JSON.parse(body) : {};
    const action = url.searchParams.get('action');
    if (req.method === 'POST') posts.push({ action, input });
    let data = action === 'history' ? [] : state;
    if (action === 'bulk-preview') data = { token: 'synthetic-token', changed: noOp ? 0 : input.devices.length, unchanged: noOp ? input.devices.length : 0,
      rows: input.devices.map(target => ({ id: target.id, name: devices.find(row => row.id === target.id).name, holder: target.id === 1 ? 'Old holder' : 'Available pool',
        diffs: noOp ? [] : [{ field: 'Spec: Microsoft 365', before: 'No', after: 'Yes', kind: 'change' }] })) };
    if (action === 'bulk-apply') {
      if (conflict) { res.writeHead(409, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ error: 'Device #2 changed.', conflicts: [2] })); return; }
      data = { changed: 2, unchanged: 0 };
    }
    res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ data })); return;
  }
  if (url.pathname === '/') { res.setHeader('Content-Type', 'text/html'); res.end(page.replaceAll('content="editor"', `content="${role}"`).replaceAll('data-role="editor"', `data-role="${role}"`)); return; }
  if (!/^\/assets\/[\w.-]+\.(js|css)$/.test(url.pathname)) { res.writeHead(404); res.end(); return; }
  res.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css');
  res.end(fs.readFileSync(path.join(root, 'public', url.pathname)));
});

async function main() {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const profileRoot = path.join(root, '.scratch');
  fs.mkdirSync(profileRoot, { recursive: true });
  const profile = fs.mkdtempSync(path.join(profileRoot, 'inventory-bulk-browser-'));
  const browser = spawn(executable, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--no-sandbox', '--remote-debugging-pipe', '--user-data-dir=' + profile, 'about:blank'], { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'], windowsHide: true });
  const pending = new Map();
  const errors = [];
  let seq = 0, buffer = '';
  browser.stdio[4].on('data', bytes => {
    buffer += bytes.toString();
    let end;
    while ((end = buffer.indexOf('\0')) >= 0) {
      const raw = buffer.slice(0, end); buffer = buffer.slice(end + 1);
      if (!raw) continue;
      const message = JSON.parse(raw);
      if (message.id) {
        const task = pending.get(message.id); if (!task) continue;
        pending.delete(message.id); clearTimeout(task.timer);
        message.error ? task.reject(new Error(JSON.stringify(message.error))) : task.resolve(message.result);
      } else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text + ': ' + (message.params.exceptionDetails.exception?.description || ''));
    }
  });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++seq;
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
    pending.set(id, { resolve, reject, timer });
    browser.stdio[3].write(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0');
  });
  try {
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    const command = (method, params) => send(method, params, sessionId);
    const evaluate = async expression => {
      const result = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
      return result.result.value;
    };
    const waitFor = async expression => {
      for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await new Promise(resolve => setTimeout(resolve, 30)); }
      throw new Error('Timed out: ' + expression);
    };
    const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    const change = (selector, value) => evaluate(`{ const input = document.querySelector(${JSON.stringify(selector)}); input.value = ${JSON.stringify(value)}; input.dispatchEvent(new Event('change', {bubbles:true})); }`);
    await command('Runtime.enable');
    await command('Page.enable');
    await command('Page.navigate', { url: 'http://127.0.0.1:' + server.address().port });
    await waitFor('document.querySelectorAll("[data-bulk-device]").length === 3');
    assert.equal(await evaluate('document.querySelectorAll("[data-bulk-device]")[2].disabled'), true);
    await click('[data-bulk-device="1"]');
    assert.equal(await evaluate('document.querySelector("#bulk-all").indeterminate'), true);
    assert.equal(await evaluate('document.querySelector("#detail").hidden'), true);
    await click('button[data-device="1"]');
    assert.equal(await evaluate('getComputedStyle(document.querySelector("#table-body tr td:nth-child(2)")).display'), 'table-cell');
    assert.equal(await evaluate('document.querySelector("[data-bulk-device]").checked'), true);
    await click('#close-detail');
    await click('#bulk-all');
    assert.equal(await evaluate('document.querySelector("#bulk-count").textContent'), '2 selected');
    await evaluate('document.querySelector("#search").value = "two"; document.querySelector("#search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelector("#bulk-count").textContent'), '2 selected · 1 hidden by filters');
    await click('#bulk-edit');
    assert.equal(await evaluate('document.querySelector("#bulk-dialog").open'), true);
    assert.equal(await evaluate('document.querySelector("#bulk-condition").disabled'), true);
    await click('#bulk-add-spec');
    await change('[data-bulk-name]', 'Microsoft 365');
    await click('#bulk-add-spec');
    await change('[data-bulk-spec]:last-child [data-bulk-name]', 'Anti Virus');
    await change('[data-bulk-spec]:last-child [data-bulk-value]', 'Other');
    await change('[data-bulk-spec]:last-child [data-bulk-custom]', 'Installed');
    await click('#bulk-submit');
    await waitFor('!document.querySelector("#bulk-review").hidden');
    const sent = posts.at(-1).input;
    assert.deepEqual(sent.devices.map(row => row.id), [1, 2]);
    assert.deepEqual(sent.patch, {});
    assert.deepEqual(sent.specifications, [{ name: 'Microsoft 365', action: 'set', value: 'Yes' }, { name: 'Anti Virus', action: 'set', value: 'Installed' }]);
    assert.equal(await evaluate('document.querySelector("#bulk-review").innerHTML.includes("Laptop &lt;one&gt;")'), true);
    await click('#bulk-back');
    assert.equal(await evaluate('document.querySelector("[data-bulk-name]").value'), 'Microsoft 365');
    await click('#bulk-submit');
    await waitFor('!document.querySelector("#bulk-review").hidden');
    conflict = true;
    await click('#bulk-submit');
    await waitFor('!document.querySelector("#bulk-refresh").hidden');
    assert.equal(await evaluate('document.querySelector("#bulk-submit").disabled'), true);
    assert.deepEqual(posts.at(-1).input, { token: 'synthetic-token' });
    conflict = false;
    await click('#bulk-refresh');
    await waitFor('!document.querySelector("#bulk-submit").disabled');
    assert.equal(await evaluate('document.querySelector("[data-bulk-name]").value'), 'Microsoft 365');
    await click('#bulk-submit'); await waitFor('!document.querySelector("#bulk-review").hidden');
    const applyCount = posts.filter(post => post.action === 'bulk-apply').length;
    await evaluate('document.querySelector("#bulk-form").requestSubmit(); document.querySelector("#bulk-form").requestSubmit()');
    await waitFor('!document.querySelector("#bulk-dialog").open');
    assert.equal(posts.filter(post => post.action === 'bulk-apply').length, applyCount + 1);
    assert.equal(await evaluate('document.querySelector("#notice").textContent.includes("2 devices updated")'), true);
    assert.equal(await evaluate('document.querySelector("#bulk-bar").hidden'), true);
    await click('#bulk-all'); await click('#bulk-assign');
    await change('#bulk-kind', 'employee'); await change('#bulk-employee', '1');
    await change('#bulk-date', '2026-10-28');
    await click('#bulk-submit'); await waitFor('!document.querySelector("#bulk-review").hidden');
    assert.equal(posts.at(-1).input.assignment.hr_employee_id, '1');
    assert.equal(posts.at(-1).input.assignment.handover_date, '2026-10-28');
    await click('#bulk-back'); await change('#bulk-kind', 'unassigned');
    assert.equal(await evaluate('document.querySelector("#bulk-date").value'), '');
    noOp = true;
    await click('#bulk-submit'); await waitFor('!document.querySelector("#bulk-review").hidden');
    assert.equal(await evaluate('document.querySelector("#bulk-submit").disabled'), true);
    await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await click('#bulk-cancel');
    assert.equal(await evaluate('getComputedStyle(document.querySelector("#bulk-bar")).position'), 'fixed');
    await click('#bulk-edit');
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await waitFor('!document.querySelector("#bulk-dialog").open');
    await click('[data-view="people"]');
    assert.equal(await evaluate('document.querySelector("#bulk-bar").hidden'), true);
    role = 'viewer';
    await command('Page.reload'); await waitFor('document.querySelector("#add-device").disabled && document.querySelector("#table-body").textContent.includes("Laptop")');
    assert.equal(await evaluate('document.querySelectorAll("[data-bulk-device]").length'), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: real Chromium bulk selection, specs, review, conflict recovery, assignment, no-op, mobile, Escape, and viewer smoke checks (synthetic API).');
  } finally {
    browser.kill();
    await new Promise(resolve => { if (browser.exitCode !== null) resolve(); else browser.once('exit', resolve); });
    await new Promise(resolve => server.close(resolve));
    // Only the exact temporary directory created above is removed.
    if (path.dirname(path.resolve(profile)) !== profileRoot) throw new Error('Unexpected browser profile directory.');
    try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 }); } catch { /* Browser may retain a file briefly. */ }
  }
}
main().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
