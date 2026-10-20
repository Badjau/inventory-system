(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const model = InventoryPhotoImport, esc = InventoryModel.escape;
  const dialog = $('#photo-import-dialog'), rowsElement = $('#photo-import-rows');
  const csrf = $('meta[name="csrf-token"]').content;
  let preview = null, rows = [], busy = false, stop = false;
  const employeeLabel = employee => `${employee.name} · ${employee.employee_id || ''} (#${employee.id})`;
  const error = message => { $('#photo-import-error').textContent = message; $('#photo-import-error').hidden = !message; };
  const status = message => { $('#photo-import-status').textContent = message; };
  function reset() {
    for (const row of rows) if (row.url) URL.revokeObjectURL(row.url);
    rows = []; preview = null; rowsElement.replaceChildren();
    $('#photo-import-employees').replaceChildren();
    error(''); status('Nothing is uploaded until approval.'); controls();
  }
  async function send(action, body, multipart = false) {
    const response = await fetch(`api.php?action=${action}`, { method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, ...(multipart ? {} : { 'Content-Type': 'application/json' }) },
      body: multipart ? body : JSON.stringify(body) });
    if (response.status === 401) { window.location.assign('login.php'); throw new Error('Sign in again.'); }
    let result;
    try { result = await response.json(); } catch { throw new Error('The upload could not be processed. Check PHP upload limits and try again.'); }
    if (!response.ok) throw new Error(result.error || `Photo import failed (${response.status}).`);
    return result.data;
  }
  function controls() {
    dialog.querySelectorAll('input, select, button').forEach(element => { element.disabled = busy; });
    if (!busy) {
      for (const row of rows) {
        const element = rowsElement.querySelector(`[data-photo-row="${row.id}"]`);
        if (!element) continue;
        if (row.done) element.querySelectorAll('input, select, button').forEach(control => { control.disabled = true; });
        else if (!row.file || !row.importable) element.querySelector('[data-photo-select]').disabled = true;
      }
    }
    $('#photo-import-approve').disabled = busy || !rows.some(row => row.selected && model.uploadable(row));
    $('#photo-import-stop').hidden = !busy;
    $('#photo-import-stop').disabled = !busy || stop;
  }
  function targets(row) {
    if (row.allDevices) return preview.devices;
    return model.devicesFor(preview.devices, preview.employees.find(person => Number(person.id) === Number(row.employeeId)), preview.employees);
  }
  function chooseEmployee(row, id) {
    row.employeeId = id; row.allDevices = false; row.selected = false;
    row.deviceId = model.automaticLaptop(targets(row));
    row.selected = model.uploadable(row);
  }
  function renderRow(row) {
    const element = rowsElement.querySelector(`[data-photo-row="${row.id}"]`);
    const employee = preview.employees.find(person => Number(person.id) === Number(row.employeeId));
    const suggestions = row.matches.slice(0, 3).filter(match => match.score >= 45);
    const candidates = targets(row);
    element.innerHTML = `<div class="photo-import-preview">${row.url ? `<a href="${esc(row.url)}" target="_blank" rel="noopener" aria-label="Open ${esc(row.fileName)}"><img src="${esc(row.url)}" alt="${esc(row.fileName)}" loading="lazy"></a>` : '<span>No matching file</span>'}</div>
      <div class="photo-import-fields"><label class="import-selection"><input type="checkbox" data-photo-select ${row.selected ? 'checked' : ''} ${!row.file || !row.importable || row.done ? 'disabled' : ''}>${esc(row.fileName)}</label>
      <p>Read name: <strong>${esc(row.name || 'Unreadable')}</strong>${row.review ? ' · Needs review' : ''}</p>
      ${!row.importable ? '<p>This scanner record is unfinished or failed. Scan it again first.</p>' : ''}
      <label>Employee<input type="text" data-photo-employee list="photo-import-employees" value="${esc(employee ? employeeLabel(employee) : '')}" placeholder="Search HR name or choose a suggestion" autocomplete="off" ${row.done ? 'disabled' : ''}></label>
      <div class="photo-import-suggestions">${suggestions.map(match => `<button type="button" class="text-button" data-photo-suggest="${Number(match.employee.id)}" ${row.done ? 'disabled' : ''}>${esc(match.employee.name)} · ${match.score}% similarity (${esc(match.reason)})</button>`).join('')}</div>
      <label>Attach to device<select data-photo-device ${row.done ? 'disabled' : ''}><option value="">Choose a device</option>${candidates.map(device => `<option value="${Number(device.id)}" ${Number(device.id) === Number(row.deviceId) ? 'selected' : ''}>${esc(`#${device.id} · ${device.device_type} · ${device.name} · ${device.asset_tag || device.serial_number || 'No identifier'} · ${device.holder_name || 'Unassigned'}`)}</option>`).join('')}${!row.allDevices ? '<option value="all">Show all active devices…</option>' : ''}</select></label>
      <p data-photo-message role="status">${esc(row.message || '')}</p></div>`;
  }
  function render() {
    rowsElement.replaceChildren();
    for (const row of rows) {
      const element = document.createElement('section');
      element.className = 'import-group photo-import-row'; element.dataset.photoRow = row.id;
      rowsElement.append(element); renderRow(row);
    }
    controls();
  }
  const trigger = $('#import-photos');
  trigger.hidden = $('meta[name="inventory-role"]').content === 'viewer';
  trigger.addEventListener('click', () => { dialog.showModal(); controls(); });
  $('#photo-import-close').addEventListener('click', () => dialog.close());
  $('#photo-import-cancel').addEventListener('click', () => dialog.close());
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  dialog.addEventListener('close', () => { if (!busy) reset(); });
  for (const id of ['#photo-import-manifest', '#photo-import-files', '#photo-import-folder']) {
    $(id).addEventListener('change', () => {
      if (id === '#photo-import-files') $('#photo-import-folder').value = '';
      if (id === '#photo-import-folder') $('#photo-import-files').value = '';
      reset();
    });
  }
  $('#photo-import-form').addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return;
    reset();
    const manifestFile = $('#photo-import-manifest').files[0];
    const folder = $('#photo-import-folder').files.length > 0;
    const files = Array.from($(folder ? '#photo-import-folder' : '#photo-import-files').files);
    if (!manifestFile || manifestFile.size > 20 * 1024 * 1024 || !files.length) { error('Choose results.json (up to 20 MiB) and the original photos.'); return; }
    busy = true; controls(); status('Preparing preview…');
    try {
      let data;
      try { data = JSON.parse(await manifestFile.text()); } catch { throw new Error('Cannot read results.json. Choose the scanner output file.'); }
      const records = model.manifest(data);
      const body = JSON.stringify({ records });
      if (new TextEncoder().encode(body).length > 1048576) throw new Error('The manifest is too large. Import a smaller scanner batch.');
      preview = await send('photo-import-preview', { records });
      const matched = model.linkFiles(preview.rows, files, folder);
      $('#photo-import-employees').innerHTML = preview.employees.map(person => `<option value="${esc(employeeLabel(person))}"></option>`).join('');
      rows = preview.rows.map((record, index) => {
        const file = matched[index];
        const matches = model.rank(record.name, preview.employees);
        const row = { ...record, matches, fileName: record.file, file, employeeId: null, deviceId: null, selected: false, done: false,
          url: file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && file.size <= 20 * 1024 * 1024 ? URL.createObjectURL(file) : null };
        chooseEmployee(row, model.automaticEmployee(matches));
        return row;
      });
      status(`${rows.length} records · ${rows.filter(row => row.file && row.importable).length} files ready for review. Similarity is a spelling score, not a guarantee. Preview expires in 30 minutes.`);
    } catch (failure) { reset(); error(failure.message); }
    finally { busy = false; render(); }
  });
  rowsElement.addEventListener('change', event => {
    if (busy) return;
    const element = event.target.closest('[data-photo-row]'); if (!element) return;
    const row = rows[Number(element.dataset.photoRow)]; if (row.done) return;
    if (event.target.matches('[data-photo-select]')) row.selected = event.target.checked;
    if (event.target.matches('[data-photo-employee]')) {
      const employee = preview.employees.find(person => employeeLabel(person) === event.target.value);
      chooseEmployee(row, employee?.id || null);
    }
    if (event.target.matches('[data-photo-device]')) {
      if (event.target.value === 'all') { row.allDevices = true; row.deviceId = null; row.selected = false; }
      else row.deviceId = Number(event.target.value) || null;
    }
    renderRow(row); controls();
  });
  rowsElement.addEventListener('click', event => {
    const button = event.target.closest('[data-photo-suggest]'); if (!button || busy) return;
    const row = rows[Number(button.closest('[data-photo-row]').dataset.photoRow)]; if (row.done) return;
    chooseEmployee(row, Number(button.dataset.photoSuggest)); renderRow(row); controls();
  });
  $('#photo-import-select').addEventListener('click', () => {
    for (const row of rows) row.selected = model.uploadable(row);
    render();
  });
  $('#photo-import-clear').addEventListener('click', () => { rows.forEach(row => { row.selected = false; }); render(); });
  $('#photo-import-stop').addEventListener('click', () => { stop = true; controls(); status('Stopping after the current photo finishes…'); });
  $('#photo-import-approve').addEventListener('click', async () => {
    if (busy || !preview) return;
    const requested = rows.filter(row => row.selected && !row.done);
    const unresolved = requested.filter(row => !model.uploadable(row));
    const selected = requested.filter(model.uploadable);
    for (const row of unresolved) {
      row.selected = false;
      row.message = 'Skipped: choose an employee, matching file and target device to upload this photo.';
      renderRow(row);
    }
    if (!selected.length) { status('No ready photos selected. Entries without an employee or target device are skipped.'); controls(); return; }
    busy = true; stop = false; error(''); controls();
    let added = 0, skipped = 0, failed = 0, interrupted = false;
    for (const [index, row] of selected.entries()) {
      if (stop) { interrupted = true; break; }
      status(`Uploading ${index + 1} of ${selected.length}: ${row.fileName}`);
      try {
        if (row.file.size > 20 * 1024 * 1024) throw new Error('Original exceeds 20 MiB. Resize a separate copy and scan that copy again.');
        if (await model.sha256(row.file) !== row.contentHash) throw new Error('File changed since scanning. Choose the original file or scan this version again.');
        const [photo] = await InventoryPhotos.prepare([row.file]);
        const body = new FormData();
        body.append('selection', JSON.stringify({ token: preview.token, row: row.id, id: row.deviceId }));
        body.append('photos[]', photo, row.file.name);
        const saved = await send('photo-import', body, true);
        row.done = true; row.selected = false;
        row.message = saved.skipped ? 'Already imported to this device; skipped.' : 'Uploaded.';
        if (saved.skipped) skipped++; else added++;
      } catch (failure) {
        row.message = failure.message; failed++;
        // Preserve unattempted rows. Re-preview resolves expired or stale device versions.
        if (/expired|changed since|Sign in|Preview photos first/i.test(failure.message)) { interrupted = true; break; }
      } finally { renderRow(row); controls(); }
    }
    busy = false; stop = false; render();
    status(`${added} uploaded · ${skipped} already imported · ${failed} failed${unresolved.length ? ` · ${unresolved.length} unresolved entries skipped` : ''}${interrupted ? ' · Stopped; remaining selections are retained.' : ''}`);
    if (failed) error('Some photos were not uploaded. See each row. Fix the files and retry; for expired or changed previews, choose Preview again.');
    if (added || skipped) {
      $('#notice').textContent = `Photo import: ${added} uploaded, ${skipped} already imported, ${failed} failed${unresolved.length ? `, ${unresolved.length} unresolved entries skipped` : ''}.`;
      $('#notice').hidden = false;
      document.dispatchEvent(new Event('inventory-imported'));
    }
  });
})();
