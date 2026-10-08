const assert = require('node:assert/strict');
const source = require('node:fs').readFileSync(__dirname + '/../public/assets/photos.js', 'utf8');

async function run() {
  let revoked = 0, draws = [], qualities = [], failDecode = false, oversized = false;
  const canvas = { width: 0, height: 0,
    getContext: () => ({ fillRect() {}, drawImage(...args) { draws.push(args); } }),
    toBlob(callback, type, quality) {
      qualities.push(quality);
      callback({ size: oversized || quality === 0.85 ? 3 * 1024 * 1024 : 1024, type });
    } };
  class Image {
    constructor() { this.naturalWidth = 4000; this.naturalHeight = 3000; }
    set src(value) { if (value) queueMicrotask(() => failDecode ? this.onerror() : this.onload()); }
  }
  class File {
    constructor(parts, name, options) { Object.assign(this, { name, type: options.type, size: parts[0].size }); }
  }
  const scope = {};
  new Function('globalThis', 'document', 'Image', 'URL', 'File', source)(scope,
    { createElement: () => canvas }, Image, { createObjectURL: () => 'blob:test', revokeObjectURL() { revoked++; } }, File);
  const file = { name: 'phone.png', type: 'image/png', size: 10 * 1024 * 1024 };
  const status = [];
  const [prepared] = await scope.InventoryPhotos.prepare([file], message => status.push(message));
  assert.equal(prepared.name, 'phone.jpg');
  assert.equal(prepared.type, 'image/jpeg');
  assert.equal(prepared.size <= 2 * 1024 * 1024, true);
  assert.deepEqual(draws[0].slice(1), [0, 0, 2000, 1500]);
  assert.deepEqual(qualities, [0.85, 0.75]);
  assert.equal(status.length, 1);
  assert.equal(revoked, 1);
  assert.equal(canvas.width, 0);
  await assert.rejects(scope.InventoryPhotos.prepare(Array(6).fill(file)), /5 photos/);
  await assert.rejects(scope.InventoryPhotos.prepare([{ ...file, size: 21 * 1024 * 1024 }]), /20 MiB/);
  await assert.rejects(scope.InventoryPhotos.prepare([{ ...file, type: 'image/heic' }]), /JPEG/);
  failDecode = true;
  await assert.rejects(scope.InventoryPhotos.prepare([file]), /Cannot read/);
  assert.equal(revoked, 2);
  failDecode = false;
  oversized = true;
  await assert.rejects(scope.InventoryPhotos.prepare([file]), /still too large/);
  assert.equal(revoked, 3);
  console.log('PASS: photo resizing, quality retries, upload bounds, decode errors and resource cleanup.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
