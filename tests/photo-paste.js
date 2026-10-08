'use strict';
const assert = require('node:assert/strict');
const source = require('node:fs').readFileSync(__dirname + '/../public/assets/photos.js', 'utf8');
function element() {
  return { listeners: {}, children: [], hidden: true,
    addEventListener(type, fn) { this.listeners[type] = fn; },
    removeEventListener(type) { delete this.listeners[type]; },
    setAttribute() {}, append(...nodes) { this.children.push(...nodes); },
    replaceChildren() { this.children = []; }
  };
}
const scope = {};
new Function('globalThis', 'document', source)(scope, { createElement: element });
const dialog = element(), input = element(), selection = element(), error = element();
dialog.open = true;
let busy = false;
const files = scope.InventoryPhotos.attach(dialog, input, selection, error, () => busy);
const photo = { name: 'Clipboard.png', type: 'image/png', size: 1024 };
let prevented = false;
function paste(items) { dialog.listeners.paste({ clipboardData: { items }, preventDefault() { prevented = true; } }); }
const item = file => ({ kind: 'file', type: file.type, getAsFile: () => file });
paste([{ kind: 'string', type: 'text/plain' }]);
assert.equal(prevented, false);
assert.equal(files().length, 0);
paste([item(photo)]);
assert.equal(prevented, true);
assert.equal(files()[0], photo);
assert.equal(selection.children[0].children[0].textContent, 'Clipboard.png');
input.files = [{ ...photo, name: 'Selected.jpg', type: 'image/jpeg' }];
input.listeners.change();
assert.equal(files().length, 2);
assert.equal(input.value, '');
selection.children[0].children[1].listeners.click();
assert.equal(files()[0].name, 'Selected.jpg');
paste(Array(5).fill(item(photo)));
assert.equal(files().length, 1);
assert.equal(error.hidden, false);
paste([item({ ...photo, type: 'image/heic' })]);
assert.equal(files().length, 1);
assert.equal(error.textContent.includes('JPEG'), true);
paste([item({ ...photo, size: 21 * 1024 * 1024 })]);
assert.equal(files().length, 1);
busy = true;
paste([item(photo)]);
assert.equal(files().length, 1);
busy = false;
dialog.listeners.close();
assert.equal(files().length, 0);
assert.equal(dialog.listeners.paste, undefined);
console.log('PASS: image paste, normal text paste, mixed file selection, removal, limits, busy state and cleanup.');
