/* Matching suggestions are advisory. Stored assignments always use device IDs. */
(() => {
  'use strict';
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
  const alias = value => normalize(value).split(' ').map(word => word === 'ma' ? 'maria' : word).join(' ');
  function distance(a, b) {
    let previous = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
      const next = [i];
      for (let j = 1; j <= b.length; j++) next[j] = Math.min(next[j - 1] + 1, previous[j] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      previous = next;
    }
    return previous[b.length];
  }
  function rank(name, employees) {
    const raw = normalize(name), canonical = alias(name);
    if (!raw) return [];
    return employees.map(employee => {
      const target = normalize(employee.name), comparable = alias(employee.name);
      const exact = raw === target;
      const score = exact ? 100 : canonical === comparable ? 96 : Math.min(95, Math.round(100 *
        Math.max(1 - distance(canonical, comparable) / Math.max(canonical.length, comparable.length, 1),
          0.93 * (1 - distance(canonical.split(' ').sort().join(' '), comparable.split(' ').sort().join(' ')) / Math.max(canonical.length, comparable.length, 1)))));
      return { employee, score, exact, reason: exact ? 'Exact' : canonical === comparable ? 'Maria / Ma. variant' : 'Similar spelling' };
    }).sort((a, b) => b.score - a.score || Number(a.employee.id) - Number(b.employee.id));
  }
  function suggestedEmployee(name, employees) {
    const matches = rank(name, employees).filter(match => match.exact);
    return matches.length === 1 ? matches[0].employee.id : null;
  }
  function automaticEmployee(matches) {
    const best = matches[0];
    return best && best.score >= 60 && (!matches[1] || best.score > matches[1].score) ? best.employee.id : null;
  }
  function uploadable(row) {
    return !!(row.employeeId && row.deviceId && row.file && row.importable && !row.done);
  }
  function automaticLaptop(devices) {
    const laptops = devices.filter(device => device.device_type === 'Laptop');
    return laptops.length === 1 ? laptops[0].id : null;
  }
  function devicesFor(devices, employee, employees) {
    if (!employee) return [];
    const unique = employees.filter(person => normalize(person.name) === normalize(employee.name)).length === 1;
    return devices.filter(device => device.holder_kind === 'employee' && Number(device.hr_employee_id) === Number(employee.id)
      || device.holder_kind === 'imported' && unique && normalize(device.holder_name) === normalize(employee.name));
  }
  function linkFiles(rows, files, folder) {
    const byPath = new Map();
    for (const file of files) {
      const path = folder ? String(file.webkitRelativePath || '').split('/').slice(1).join('/') : file.name;
      if (!path) continue;
      byPath.set(path, byPath.has(path) ? null : file);
    }
    // Folder selection preserves relative paths. Individual selection requires an exact filename.
    return rows.map(row => byPath.get(row.file) || null);
  }
  function manifest(data) {
    if (!data || !Array.isArray(data.records) || !data.records.length || data.records.length > 2000) throw new Error('Choose the scanner results.json with 1 to 2,000 records.');
    return data.records.map(row => {
      if (!row || typeof row.file !== 'string') throw new Error('Invalid scanner record.');
      return { file: row.file, name: row.name ?? null, status: row.status, needs_review: row.needs_review, contentHash: row.contentHash ?? null };
    });
  }
  async function sha256(file) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    if (globalThis.crypto?.subtle) {
      return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), byte => byte.toString(16).padStart(2, '0')).join('');
    }
    // HTTP LAN hosts lack WebCrypto. Keep file verification available there too.
    const constants = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
    const state = [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
    const padded = new Uint8Array(Math.ceil((bytes.length + 9) / 64) * 64);
    padded.set(bytes); padded[bytes.length] = 128;
    const view = new DataView(padded.buffer);
    view.setUint32(padded.length - 8, Math.floor(bytes.length / 0x20000000));
    view.setUint32(padded.length - 4, bytes.length * 8);
    const rotate = (x, n) => (x >>> n) | (x << (32 - n));
    const words = new Int32Array(64);
    for (let offset = 0; offset < padded.length; offset += 64) {
      for (let i = 0; i < 16; i++) words[i] = view.getInt32(offset + i * 4);
      for (let i = 16; i < 64; i++) {
        const a = words[i - 15], b = words[i - 2];
        words[i] = words[i - 16] + (rotate(a, 7) ^ rotate(a, 18) ^ (a >>> 3)) + words[i - 7] + (rotate(b, 17) ^ rotate(b, 19) ^ (b >>> 10));
      }
      let [a,b,c,d,e,f,g,h] = state;
      for (let i = 0; i < 64; i++) {
        const t1 = (h + (rotate(e, 6) ^ rotate(e, 11) ^ rotate(e, 25)) + ((e & f) ^ (~e & g)) + constants[i] + words[i]) | 0;
        const t2 = ((rotate(a, 2) ^ rotate(a, 13) ^ rotate(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
        h=g; g=f; f=e; e=(d+t1)|0; d=c; c=b; b=a; a=(t1+t2)|0;
      }
      [a,b,c,d,e,f,g,h].forEach((value, i) => { state[i] = (state[i] + value) | 0; });
    }
    return state.map(value => (value >>> 0).toString(16).padStart(8, '0')).join('');
  }
  globalThis.InventoryPhotoImport = { normalize, rank, suggestedEmployee, automaticEmployee, automaticLaptop, uploadable, devicesFor, linkFiles, manifest, sha256 };
})();
