'use strict';
const assert = require('node:assert/strict');
const source = require('node:fs').readFileSync(__dirname + '/../public/assets/photo-viewer.js', 'utf8');
const ids = ['photo-viewer', 'photo-image', 'photo-stage', 'photo-viewer-status', 'photo-viewer-title', 'close-photo-viewer'];
const elements = new Map(ids.map(id => [id, { style: {}, listeners: {}, naturalWidth: 2000, naturalHeight: 1000, clientWidth: 832, clientHeight: 632,
  addEventListener(type, fn) { this.listeners[type] = fn; },
  classList: { toggle() {}, add() {}, remove() {} },
  getBoundingClientRect() { return { left: 16, top: 16, width: 800, height: 400 }; },
  setPointerCapture() {},
  showModal() { this.open = true; }, close() { this.open = false; this.listeners.close(); },
  removeAttribute(name) { delete this[name]; }
}]));
const listeners = {};
new Function('document', 'window', source)({ getElementById: id => elements.get(id), addEventListener(type, fn) { listeners[type] = fn; } }, { addEventListener() {} });
const el = id => elements.get(id);
const link = { href: 'api.php?action=evidence&event=1&photo=0', querySelector: () => ({ alt: '<Photo>' }) };
let prevented = false;
listeners.click({ target: { closest: () => link }, preventDefault() { prevented = true; } });
assert.equal(prevented, true);
assert.equal(el('photo-viewer').open, true);
assert.equal(el('photo-viewer-title').textContent, '<Photo>');
el('photo-image').listeners.load();
assert.equal(el('photo-image').style.width, '800px');
const click = { clientX: 416, clientY: 216 };
el('photo-image').listeners.click(click);
assert.equal(el('photo-image').style.width, '2000px');
const initialLeft = el('photo-stage').scrollLeft;
const initialTop = el('photo-stage').scrollTop;
el('photo-stage').listeners.pointerdown({ pointerId: 1, button: 0, clientX: 100, clientY: 100 });
el('photo-stage').listeners.pointermove({ pointerId: 1, clientX: 80, clientY: 70 });
assert.equal(el('photo-stage').scrollLeft, initialLeft + 20);
assert.equal(el('photo-stage').scrollTop, initialTop + 30);
el('photo-stage').listeners.pointerup();
el('photo-image').listeners.click(click);
assert.equal(el('photo-image').style.width, '2000px');
el('photo-image').listeners.click(click);
assert.equal(el('photo-image').style.width, '800px');
assert.equal(el('photo-stage').scrollLeft, 0);
el('photo-stage').listeners.keydown({ key: 'Enter', preventDefault() {} });
assert.equal(el('photo-image').style.width, '2000px');
el('photo-stage').listeners.keydown({ key: ' ', preventDefault() {} });
assert.equal(el('photo-image').style.width, '800px');
el('photo-image').listeners.error();
assert.equal(el('photo-image').hidden, true);
assert.equal(el('photo-viewer-status').textContent.includes('could not be loaded'), true);
el('close-photo-viewer').listeners.click();
assert.equal(el('photo-viewer').open, false);
assert.equal(el('photo-image').src, undefined);
prevented = false;
listeners.click({ target: { closest: () => link }, ctrlKey: true, preventDefault() { prevented = true; } });
assert.equal(prevented, false);
console.log('PASS: photo modal click zoom, drag panning without toggling, keyboard, errors and closing.');
