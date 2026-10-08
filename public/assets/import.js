(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const { escape: esc, importGroups } = InventoryModel;
  const csrf = $('meta[name="csrf-token"]').content;
  let preview = null, employees = [], choices = {}, busy = false;
  const error = message => { $('#import-error').textContent = message; $('#import-error').hidden = !message; };

  async function send(action, body, multipart = false) {
    const response = await fetch(`api.php?action=${action}`, { method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, ...(multipart ? {} : { 'Content-Type': 'application/json' }) },
      body: multipart ? body : JSON.stringify(body) });
    if (response.status === 401) { window.location.assign('login.php'); throw new Error('Sign in again.'); }
    let result;
    try { result = await response.json(); } catch { throw new Error('The server could not process the workbook. Check PHP upload limits and setup.'); }
    if (!response.ok) throw new Error(result.error || `Import failed (${response.status}).`);
    return result.data;
  }

  function controls() {
    $('#preview-import').disabled = busy;
    $('#import-file').disabled = busy;
    $('#approve-import').disabled = busy || !preview || !Object.values(choices).some(choice => choice.selected);
    $('#import-groups').querySelectorAll('input, select, button').forEach(element => { element.disabled = busy; });
    $('#select-import').disabled = $('#clear-import').disabled = busy;
    $('#cancel-import').disabled = $('#close-import').disabled = busy;
  }

  function render() {
    if (!preview) { $('#import-review').hidden = true; controls(); return; }
    const groups = importGroups(preview.groups, choices);
    $('#import-review').hidden = false;
    $('#import-summary').textContent = `${groups.length} holder groups · ${groups.reduce((sum, group) => sum + group.records.length, 0)} devices. Blank HR links keep recorded holders. Preview expires after 30 minutes.`;
    $('#import-groups').innerHTML = groups.map((group, index) => {
      const person = employees.find(employee => Number(employee.id) === Number(group.employeeId));
      const canLink = group.records.every(record => !['shared', 'unassigned'].includes(record.holder_kind));
      const recorded = [...new Set(group.records.map(record => record.holder_name).filter(Boolean))].join(', ');
      return `<section class="import-group" data-import-group="${index}">
        <label class="import-selection"><input type="checkbox" data-import-select="${index}" ${group.selected ? 'checked' : ''}>Approve ${esc(person?.name || group.label)} (${group.records.length} devices)</label>
        ${canLink ? `<label>HR employee<select data-import-employee="${index}"><option value="">No HR link — keep recorded holder</option>${employees.map(employee => `<option value="${Number(employee.id)}"${Number(employee.id) === Number(group.employeeId) ? ' selected' : ''}>${esc(`${employee.name} · ${employee.employee_id} · ${employee.department}`)}</option>`).join('')}</select></label><small>Recorded holder: ${esc(recorded)}</small>` : ''}
        ${group.conflict ? `<label class="import-conflict"><input type="checkbox" data-import-resolve="${index}">I reviewed conflicting existing links; use the selection above for this group.</label>` : ''}
        ${group.records.map(record => `<details><summary>${esc(record.operation)}${record.target_id ? ` #${Number(record.target_id)}` : ''} · ${esc(record.name)} · ${esc(record.serial_number || record.asset_tag || 'No identifier')}</summary>
          <p>${esc(record.source.sheet)} · row ${Number(record.source.row)} · ${esc(record.device_type)}</p>
          <div class="specs">${Object.entries(record.specifications).map(([key, value]) => `<div><span>${esc(key)}</span><strong>${esc(value || 'Blank — clears value')}${record.before?.specifications[key] && record.before.specifications[key] !== value ? `<small>Previously: ${esc(record.before.specifications[key])}</small>` : ''}</strong></div>`).join('')}</div>
          ${record.before ? Object.entries(record.before.specifications).filter(([key]) => !Object.hasOwn(record.specifications, key)).map(([key, value]) => `<p>Removes ${esc(key)}: ${esc(value)}</p>`).join('') : ''}
          ${record.before ? `<p>Current holder: ${esc(record.before.holder_name || record.before.department || 'Unassigned')}. Current name: ${esc(record.before.name)}. Serial: ${esc(record.before.serial_number || 'Blank')}. Asset tag: ${esc(record.before.asset_tag || 'Blank')}.</p>` : ''}
        </details>`).join('')}</section>`;
    }).join('');
    const selected = groups.filter(group => group.selected);
    $('#import-status').textContent = `${selected.length} groups selected · ${selected.reduce((sum, group) => sum + group.records.length, 0)} devices`;
    controls();
  }

  $('#import-excel').hidden = $('meta[name="inventory-role"]').content === 'viewer';
  $('#import-excel').addEventListener('click', () => { error(''); render(); $('#import-dialog').showModal(); });
  for (const id of ['#close-import', '#cancel-import']) $(id).addEventListener('click', () => $('#import-dialog').close());
  $('#import-dialog').addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  $('#import-file').addEventListener('change', () => { preview = null; choices = {}; error(''); render(); });
  $('#import-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    const file = $('#import-file').files[0];
    if (!file || file.size > 5 * 1024 * 1024 || !file.name.toLowerCase().endsWith('.xlsx')) { error('Choose an XLSX workbook no larger than 5 MiB.'); return; }
    const body = new FormData(); body.append('workbook', file);
    busy = true; preview = null; choices = {}; error(''); render();
    try {
      const result = await send('import-preview', body, true);
      preview = result; employees = result.employees;
      choices = Object.fromEntries(preview.groups.map(group => [group.id, { employeeId: group.hr_employee_id, selected: true, resolved: !group.conflict }]));
    } catch (failure) { error(failure.message); }
    finally { busy = false; render(); }
  });
  $('#import-groups').addEventListener('change', event => {
    if (busy || !preview) return;
    const target = event.target;
    const index = target.dataset.importSelect ?? target.dataset.importEmployee ?? target.dataset.importResolve;
    if (index === undefined) return;
    const group = importGroups(preview.groups, choices)[Number(index)];
    for (const id of group.origins) {
      if (target.dataset.importSelect !== undefined) choices[id].selected = target.checked;
      if (target.dataset.importEmployee !== undefined) { choices[id].employeeId = Number(target.value) || null; choices[id].resolved = true; }
      if (target.dataset.importResolve !== undefined) choices[id].resolved = target.checked;
    }
    render();
  });
  for (const [id, selected] of [['#select-import', true], ['#clear-import', false]]) $(id).addEventListener('click', () => {
    if (busy) return;
    Object.values(choices).forEach(choice => { choice.selected = selected; }); render();
  });
  $('#approve-import').addEventListener('click', async () => {
    if (busy || !preview) return;
    const groups = preview.groups.filter(group => choices[group.id].selected).map(group => ({ id: group.id,
      hr_employee_id: choices[group.id].employeeId, resolved: choices[group.id].resolved }));
    if (preview.groups.some(group => choices[group.id].selected && group.conflict && !choices[group.id].resolved)) { error('Review conflicting links before approving.'); return; }
    busy = true; error(''); controls();
    try {
      const result = await send('import-apply', { token: preview.token, groups });
      preview = null; choices = {}; $('#import-file').value = '';
      $('#import-dialog').close();
      $('#notice').textContent = `Excel import complete: ${result.added} added, ${result.updated} updated.`;
      $('#notice').hidden = false;
      document.dispatchEvent(new Event('inventory-imported'));
    } catch (failure) { error(failure.message); }
    finally { busy = false; render(); }
  });
})();
