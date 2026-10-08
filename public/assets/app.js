/* Browser -> api.php -> InventoryService/HrDirectory -> PDO/MySQL. */
(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const { escape: esc, attention, displayDevice, filterDevices } = InventoryModel;
  const types = ['Laptop', 'Desktop', 'Monitor', 'Printer', 'Keyboard', 'Mouse', 'Other'];
  const conditions = ['Good', 'Needs attention', 'Faulty', 'Unknown'];
  const titles = { devices: 'Devices', people: 'People', shared: 'Shared equipment', attention: 'Needs attention', activity: 'Activity' };
  let state = { devices: [], employees: [], branches: [], departments: [], activity: [] };
  let view = 'devices', selected = null, employeeFilter = null, submitHandler = null, loading = false;
  let historyRequest = 0;
  const csrf = $('meta[name="csrf-token"]').content;
  const canEdit = $('meta[name="inventory-role"]')?.content !== 'viewer';
  const icons = {
    Laptop: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4" y="3.5" width="16" height="12.5" rx="1.5"/><path d="M2 19h20l-1.5 2h-17z"/></svg>',
    Desktop: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="2.5" y="3.5" width="12.5" height="11.5" rx="1.4"/><path d="M8.75 15v3m-3 0h6M18 5h4v14h-4zM19.2 8h1.6m-1.6 2.5h1.6"/></svg>',
    Monitor: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="2.5" y="3.5" width="19" height="13" rx="1.6"/><path d="M12 16.5v4m-4 0h8"/></svg>',
    Printer: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 8V3.5h10V8m-10 9h10v3.5H7z"/><rect x="3" y="8" width="18" height="10" rx="2"/><path d="M7 14h10m-10 2h7m6-5h.01"/></svg>',
    Keyboard: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M5 9h.01M8 9h.01M11 9h.01M14 9h.01M17 9h.01M19 9h.01M5 12h.01M8 12h.01M11 12h.01M14 12h.01M17 12h.01M6 15h.01M9 15h.01M12 15h6"/></svg>',
    Mouse: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="6.5" y="2.5" width="11" height="19" rx="5.5"/><path d="M12 2.5v6m-5.3 0h10.6"/></svg>',
    Other: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 2.5 9 5v9l-9 5-9-5v-9zM3.5 7.8 12 12.5l8.5-4.7M12 12.5v9"/><path d="m8 5 8.5 4.7"/></svg>'
  };
  const icon = type => icons[type] || icons.Other;
  const status = device => device.holder_kind === 'unassigned' ? 'Available' : 'Assigned';
  const badge = device => `<span class="status ${device.holder_kind === 'unassigned' ? 'available' : 'assigned'}">${status(device)}</span>`;
  const conditionBadge = device => `<span class="status ${attention(device) ? 'repair' : device.device_condition === 'Good' ? 'available' : 'unknown'}">${esc(device.device_condition)}</span>`;
  const devices = () => state.devices.map(device => displayDevice(device, state.employees, state.branches));
  const chosen = () => devices().find(device => device.id === selected);
  const options = (values, selectedValue = '') => values.map(value => `<option value="${esc(value)}"${value === selectedValue ? ' selected' : ''}>${esc(value)}</option>`).join('');
  const field = (label, name, value = '', extra = '') => `<label>${esc(label)}<input name="${esc(name)}" value="${esc(value)}" maxlength="255" ${extra}></label>`;

  async function request(action, method = 'GET', data = null) {
    if (method === 'POST' && !canEdit) throw new Error('This account has read-only access.');
    const response = await fetch(`api.php?action=${action}`, {
      method, credentials: 'same-origin', headers: method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {},
      ...(method === 'POST' ? { body: JSON.stringify(data) } : {})
    });
    if (response.status === 401) {
      window.location.assign('login.php');
      throw new Error('Your session expired. Sign in again.');
    }
    let result;
    try { result = await response.json(); } catch { throw new Error('The server did not return JSON. Check the Laragon document root and PHP setup.'); }
    if (!response.ok) throw new Error(result.error || `Request failed (${response.status}).`);
    return result.data;
  }

  function showError(message) { $('#error').textContent = message; $('#error').hidden = !message; }

  async function reload() {
    if (loading) return;
    loading = true;
    $('#refresh').disabled = true;
    $('#add-device').disabled = true;
    showError('');
    try {
      state = await request('state');
      const departments = [...new Set(state.departments.map(row => row.name))];
      const branches = [...new Set(state.branches.map(row => row.name))];
      const currentDepartment = $('#department-filter').value;
      const currentBranch = $('#branch-filter').value;
      $('#department-filter').innerHTML = '<option value="">All departments</option>' + options(departments);
      $('#department-filter').value = departments.includes(currentDepartment) ? currentDepartment : '';
      $('#branch-filter').innerHTML = '<option value="">All branches</option>' + options(branches);
      $('#branch-filter').value = branches.includes(currentBranch) ? currentBranch : '';
      render();
      $('#add-device').disabled = !canEdit;
    } catch (error) {
      showError(error.message);
      if (!state.devices.length) $('#table-body').innerHTML = '<tr><td class="empty-state">Complete database setup, then select Refresh.</td></tr>';
    } finally {
      loading = false;
      $('#refresh').disabled = false;
    }
  }

  function renderStats(rows) {
    $('#count-devices').textContent = rows.length;
    $('#count-people').textContent = state.employees.length;
    $('#count-shared').textContent = rows.filter(device => device.holder_kind === 'shared').length;
    const cards = [
      ['total', 'Total devices', rows.length, 'Individual inventory items'],
      ['assigned', 'Assigned', rows.filter(device => device.holder_kind !== 'unassigned').length, 'People and shared departments'],
      ['available', 'Available', rows.filter(device => device.holder_kind === 'unassigned').length, 'In the unassigned pool'],
      ['attention', 'Needs attention', rows.filter(attention).length, 'Flagged by equipment condition']
    ];
    const activeCard = view === 'attention' ? 'attention' : view === 'devices' ? ($('#status-filter').value || 'total') : '';
    $('#stats').innerHTML = cards.map(([key, label, count, description]) =>
      `<button type="button" class="stat stat-action${activeCard === key ? ' active' : ''}" data-stat="${key}" aria-pressed="${activeCard === key}"><span>${label}</span><strong>${count}</strong><small>${description}</small></button>`).join('');
  }

  function render() {
    const rows = devices();
    renderStats(rows);
    $('#page-title').textContent = titles[view];
    $('#breadcrumb').textContent = titles[view];
    const filteredEmployee = state.employees.find(employee => Number(employee.id) === Number(employeeFilter));
    $('#page-description').textContent = view === 'people' ? 'Employee details are managed in timekeeping.' :
      view === 'activity' ? 'The latest 100 inventory changes. Select a device to see its full history.' :
      filteredEmployee ? `Equipment assigned to ${filteredEmployee.name}.` : 'Equipment and the people who hold it.';
    $('#search').placeholder = view === 'people' ? 'Search name, employee number, branch, department, or role…' :
      'Search device, serial, asset tag, or holder…';
    $('#navigation').querySelectorAll('[data-view]').forEach(button => button.classList.toggle('active', button.dataset.view === view));
    $('#inventory-layout').classList.toggle('wide', view === 'people' || view === 'activity');
    $('#type-filter').hidden = view === 'people' || view === 'activity';
    $('#people-sort').hidden = view !== 'people';
    $('#status-filter').hidden = view === 'people' || view === 'activity';
    $('#department-filter').hidden = view === 'activity';
    $('#branch-filter').hidden = view === 'activity';
    $('#clear-person').hidden = !employeeFilter || view === 'people' || view === 'activity';
    if (view === 'people') { renderPeople(rows); return; }
    if (view === 'activity') { renderActivity(); return; }
    const visible = filterDevices(rows, { query: $('#search').value, type: $('#type-filter').value,
      department: $('#department-filter').value, branch: $('#branch-filter').value, status: $('#status-filter').value, employee: employeeFilter, view });
    $('#table-head').innerHTML = '<tr><th>Device</th><th>Serial / asset tag</th><th>Assigned to</th><th>Status</th><th>Condition</th></tr>';
    $('#table-body').innerHTML = visible.map(device => `<tr class="device-row ${device.id === selected ? 'selected-row' : ''}" data-device="${device.id}">
      <td><div class="device-cell"><span class="device-icon" aria-hidden="true">${icon(device.device_type)}</span><div><button class="row-name" data-device="${device.id}">${esc(device.name)}</button><small>${esc(device.device_type)} · #${device.id}</small></div></div></td>
      <td class="serial">${esc(device.serial_number || 'No serial recorded')}<small>${esc(device.asset_tag || 'No asset tag recorded')}</small></td>
      <td>${esc(device.holder_kind === 'shared' ? 'Shared department' : device.holder_name || 'Unassigned')}<small>${esc(device.department)}</small></td>
      <td>${badge(device)}</td><td>${conditionBadge(device)}</td></tr>`).join('') || '<tr><td colspan="5" class="empty-state">No devices match your filters.</td></tr>';
    $('#table-footer').textContent = `Showing ${visible.length} of ${rows.length} devices`;
    renderDetail();
  }

  function renderPeople(rows) {
    const query = $('#search').value.toLocaleLowerCase().trim();
    const department = $('#department-filter').value;
    const branch = $('#branch-filter').value;
    const employees = state.employees.filter(employee => (!department || employee.department === department) && (!branch || employee.branch === branch) &&
      (!query || [employee.name, employee.employee_id, employee.branch, employee.department, employee.job_title, employee.hr_role].join(' ').toLocaleLowerCase().includes(query)));
    const equipmentCounts = new Map();
    rows.forEach(device => {
      const employeeId = Number(device.hr_employee_id);
      if (employeeId) equipmentCounts.set(employeeId, (equipmentCounts.get(employeeId) || 0) + 1);
    });
    const equipmentCount = employee => equipmentCounts.get(Number(employee.id)) || 0;
    const sort = $('#people-sort').value;
    employees.sort((a, b) => {
      const countOrder = sort === 'zero-first' ? Number(equipmentCount(a) > 0) - Number(equipmentCount(b) > 0) :
        sort === 'has-first' ? Number(equipmentCount(b) > 0) - Number(equipmentCount(a) > 0) : 0;
      return countOrder || a.name.localeCompare(b.name);
    });
    $('#table-head').innerHTML = '<tr><th>Employee</th><th>Branch</th><th>Department</th><th>Job title / HR role</th><th>Equipment</th></tr>';
    $('#table-body').innerHTML = employees.map(employee => `<tr><td>${esc(employee.name)}<small>${esc(employee.employee_id)}</small></td><td>${esc(employee.branch || 'Not set')}</td><td>${esc(employee.department || 'Not set')}</td><td>${esc(employee.job_title)}<small>${esc(employee.hr_role)}</small></td><td><button class="button" data-person="${Number(employee.id)}">View devices (${equipmentCount(employee)})</button></td></tr>`).join('') || '<tr><td colspan="5" class="empty-state">No employees match your filters.</td></tr>';
    $('#table-footer').textContent = `${employees.length} employees · Live HR directory`;
  }

  function eventsMarkup(events) {
    return events.map(event => `<div class="timeline-item"><span class="timeline-dot"></span><strong>${esc(event.message)}</strong><small>Recorded ${esc(event.recorded_at)}${event.handover_date ? ` · Handover ${esc(event.handover_date)}` : ''}</small></div>`).join('') || '<p class="empty-state">No history recorded.</p>';
  }

  function renderActivity() {
    const query = $('#search').value.toLocaleLowerCase().trim();
    const events = state.activity.filter(event => !query || `${event.message} ${event.device_id} ${event.event_type}`.toLocaleLowerCase().includes(query));
    $('#table-head').innerHTML = '<tr><th>Device ID</th><th>Change</th><th>Recorded</th><th>Handover date</th></tr>';
    $('#table-body').innerHTML = events.map(event => `<tr><td><button class="text-button" data-activity-device="${Number(event.device_id)}">#${Number(event.device_id)}</button></td><td>${esc(event.message)}</td><td>${esc(event.recorded_at)}</td><td>${esc(event.handover_date || 'Unknown / not applicable')}</td></tr>`).join('') || '<tr><td colspan="4" class="empty-state">No matching activity.</td></tr>';
    $('#table-footer').textContent = `${events.length} recent changes`;
  }

  async function renderDetail() {
    const generation = ++historyRequest;
    const device = chosen();
    if (!device) { $('#detail').innerHTML = '<p class="empty-state">Select a device to view its details.</p>'; return; }
    const id = device.id;
    const specs = Object.entries(device.specifications).filter(([, value]) => value);
    $('#detail').innerHTML = `<div class="detail-top"><span>DEVICE #${id}</span></div><div class="large-device-icon" aria-hidden="true">${icon(device.device_type)}</div>
      <h2>${esc(device.name)}</h2><div class="detail-type">${esc(device.device_type)} ${badge(device)}</div>
      <div class="serial-card"><span>SERIAL NUMBER</span><strong>${esc(device.serial_number || 'Not recorded')}</strong><span class="source-hint">ASSET TAG</span><strong>${esc(device.asset_tag || 'Not recorded')}</strong></div>
      <div class="detail-section"><h3>Current assignment</h3><p>${esc(device.holder_kind === 'shared' ? `${device.department} (shared)` : device.holder_name || 'Unassigned')}</p><small>${esc([device.job_title, device.department, device.branch].filter(Boolean).join(' · '))}</small>
      ${device.employee_missing ? '<p class="assignment-hint">Employee unavailable in HR. Last recorded holder is retained.</p>' : ''}
      <div class="detail-meta"><span>Location</span><strong>${esc(device.location || 'Not recorded')}</strong></div><div class="detail-meta"><span>Condition</span>${conditionBadge(device)}</div>
      <div class="detail-meta"><span>Handover date</span><strong>${esc(device.handover_date || 'Not recorded')}</strong></div>
      <button class="button full" id="assign-device">Assign / return ↗</button><button class="button full" id="edit-device">Edit device</button></div>
      <div class="detail-section"><h3>Specifications</h3><div class="specs">${specs.map(([key, value]) => `<div><span>${esc(key)}</span><strong>${esc(value)}</strong></div>`).join('') || '<small>No specifications recorded.</small>'}</div>
      ${device.source ? `<p class="source-hint">Imported from ${esc(device.source.sheet)} · row ${Number(device.source.row)}</p>` : ''}</div>
      <div class="detail-section history"><h3>History</h3><div id="history" class="history-list"><small>Loading history…</small></div><button class="text-button" id="add-note">＋ Add note</button></div>`;
    try {
      const history = await request(`history&id=${id}`);
      if (historyRequest === generation && selected === id && $('#history')) $('#history').innerHTML = eventsMarkup(history);
    } catch (error) {
      if (historyRequest === generation && selected === id && $('#history')) $('#history').textContent = error.message;
    }
  }

  function openDialog(title, subtitle, fields, handler) {
    if (!canEdit) return;
    $('#dialog-title').textContent = title;
    $('#dialog-subtitle').textContent = subtitle;
    $('#dialog-fields').innerHTML = fields;
    $('#form-error').hidden = true;
    $('#save-dialog').disabled = false;
    submitHandler = handler;
    $('#dialog').showModal();
  }

  function editDevice(device = null) {
    const branchId = device ? device.location_branch_id : state.branches.find(branch => branch.name.toUpperCase() === 'APO')?.id;
    const missingBranch = branchId && !state.branches.some(branch => branch.id === branchId);
    const locationOptions = (missingBranch ? `<option value="${Number(branchId)}" selected>${esc(device.location || 'Unavailable branch')}</option>` : '') +
      state.branches.map(branch => `<option value="${branch.id}"${branch.id === branchId ? ' selected' : ''}>${esc(branch.name)}</option>`).join('');
    openDialog(device ? 'Edit device' : 'Add device', 'Serial numbers and asset tags may be blank when unknown.',
      `<div class="field-row"><label>Device type<select name="device_type">${options(types, device?.device_type || 'Laptop')}</select></label>${field('Brand / model *', 'name', device?.name, 'required')}</div>
      <div class="field-row">${field('Serial number', 'serial_number', device?.serial_number)}${field('Asset tag', 'asset_tag', device?.asset_tag)}</div>
      <div class="field-row"><label>Condition<select name="device_condition">${options(conditions, device?.device_condition || 'Unknown')}</select></label><label>Location<select name="location_branch_id"><option value="">Not recorded</option>${locationOptions}</select></label></div>
      <label>Specifications (one 'Name: value' per line)<textarea name="specs" rows="5">${esc(Object.entries(device?.specifications || {}).map(([key, value]) => `${key}: ${value}`).join('\n'))}</textarea><small>Example: CPU: Intel i5-1235U</small></label>`, async form => {
        const specifications = {};
        for (const line of form.specs.split('\n').filter(line => line.trim())) {
          const colon = line.indexOf(':');
          if (colon < 1) throw new Error('Write each specification as Name: value.');
          specifications[line.slice(0, colon).trim()] = line.slice(colon + 1).trim();
        }
        const { specs: unused, ...values } = form;
        const saved = await request('device', 'POST', { ...values, specifications,
          ...(device ? { id: device.id, version: device.version } : {}) });
        selected = saved.id;
      });
  }

  function assignDevice() {
    const device = chosen();
    if (!device) return;
    openDialog('Assign / return equipment', device.name,
      `<label>Assign to<select name="holder_kind" id="holder-kind"><option value="employee">HR employee</option><option value="shared">Shared department</option><option value="unassigned">Return to unassigned pool</option></select></label>
      <label id="employee-field">Employee<select name="hr_employee_id"><option value="">Select an employee</option>${state.employees.map(employee => `<option value="${Number(employee.id)}"${Number(employee.id) === device.hr_employee_id ? ' selected' : ''}>${esc(`${employee.name} · ${employee.employee_id} · ${employee.department}`)}</option>`).join('')}</select></label>
      <label id="department-field" hidden>Shared department<select name="department_id"><option value="">Select a department</option>${state.departments.map(department => `<option value="${Number(department.id)}"${Number(department.id) === device.department_id ? ' selected' : ''}>${esc(department.name)}</option>`).join('')}</select></label>
      <label>Handover date (if known)<input name="handover_date" type="date"><small>Leave blank if you are correcting an imported holder and the original date is unknown.</small></label>
      <label>Handover / return notes<textarea name="notes" rows="3" maxlength="2000"></textarea></label>`,
      async form => { await request('assignment', 'POST', { ...form, id: device.id, version: device.version }); });
    const selector = $('#holder-kind');
    selector.value = device.holder_kind === 'shared' ? 'shared' : 'employee';
    const updateFields = () => {
      $('#employee-field').hidden = selector.value !== 'employee';
      $('#department-field').hidden = selector.value !== 'shared';
      $('#employee-field select').required = selector.value === 'employee';
      $('#department-field select').required = selector.value === 'shared';
    };
    selector.addEventListener('change', updateFields);
    updateFields();
  }

  $('#dialog-form').addEventListener('submit', async event => {
    event.preventDefault();
    $('#save-dialog').disabled = true;
    $('#form-error').hidden = true;
    try {
      await submitHandler(Object.fromEntries(new FormData(event.currentTarget)));
      $('#dialog').close();
      await reload();
    } catch (error) {
      $('#form-error').textContent = error.message;
      $('#form-error').hidden = false;
    } finally { $('#save-dialog').disabled = false; }
  });

  function navigate(nextView) {
    view = nextView;
    employeeFilter = null;
    $('#search').value = '';
    $('#type-filter').value = '';
    $('#department-filter').value = '';
    $('#branch-filter').value = '';
    $('#status-filter').value = '';
    $('#people-sort').value = 'name';
    render();
  }

  document.addEventListener('click', event => {
    const target = event.target.closest('button, [data-device]');
    if (!target) return;
    if (target.dataset.stat) {
      navigate(target.dataset.stat === 'attention' ? 'attention' : 'devices');
      if (target.dataset.stat === 'assigned' || target.dataset.stat === 'available') {
        $('#status-filter').value = target.dataset.stat;
      }
      render();
      return;
    }
    if (target.dataset.view) navigate(target.dataset.view);
    if (target.dataset.device) { selected = Number(target.dataset.device); render(); }
    if (target.dataset.person) { navigate('devices'); employeeFilter = Number(target.dataset.person); render(); }
    if (target.dataset.activityDevice) { navigate('devices'); selected = Number(target.dataset.activityDevice); render(); }
    if (target.id === 'refresh') reload();
    if (target.id === 'add-device') editDevice();
    if (target.id === 'edit-device') editDevice(chosen());
    if (target.id === 'assign-device') assignDevice();
    if (target.id === 'clear-person') { employeeFilter = null; render(); }
    if (target.id === 'close-dialog' || target.id === 'cancel-dialog') $('#dialog').close();
    if (target.id === 'add-note') {
      const device = chosen();
      openDialog('Add a note', device.name, '<label>Note *<textarea name="message" required maxlength="2000" rows="4"></textarea></label>',
        async form => { await request('note', 'POST', { id: device.id, message: form.message }); });
    }
  });
  $('#search').addEventListener('input', render);
  ['#type-filter', '#people-sort', '#department-filter', '#branch-filter', '#status-filter'].forEach(selector => $(selector).addEventListener('change', render));
  $('#type-filter').innerHTML += options(types);
  reload();
})();
