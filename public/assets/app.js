/* Browser -> api.php -> InventoryService/HrDirectory -> PDO/MySQL. */
(() => {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const { escape: esc, attention, displayDevice, filterDevices } = InventoryModel;
  let types = ['Laptop', 'Desktop', 'Monitor', 'Printer', 'Keyboard', 'Mouse', 'Other'];
  const conditions = ['Good', 'Needs attention', 'Faulty', 'Unknown'];
  const titles = { devices: 'Devices', people: 'People', shared: 'Shared devices', attention: 'Needs attention', activity: 'Activity' };
  let state = { devices: [], employees: [], branches: [], departments: [], activity: [] };
  let view = 'devices', selected = null, employeeFilter = null, submitHandler = null, loading = false;
  const createTypeAction = '__inventory_create_device_type_action__';
  let selectCreatedType = false;
  let presetType = '';
  const presetDrafts = new Map();
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
  const portrait = device => `${icon(device.device_type)}${device.photo_event_id ? `<img class="device-portrait" data-thumbnail="api.php?action=evidence&amp;event=${Number(device.photo_event_id)}&amp;photo=${Number(device.photo_index || 0)}&amp;thumbnail=1" width="96" height="96" alt="" loading="lazy" decoding="async" fetchpriority="low">` : ''}`;
  let thumbnailObserver = null;
  let activityObserver = null;
  function observeThumbnails() {
    thumbnailObserver?.disconnect();
    thumbnailObserver = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const image = entry.target;
        image.src = image.dataset.thumbnail;
        thumbnailObserver.unobserve(image);
      });
    }, { rootMargin: '80px', threshold: 0.01 });
    for (const container of [$('#table-body'), $('#detail')]) {
      container.querySelectorAll('[data-thumbnail]').forEach(image => {
        image.addEventListener('load', () => image.classList.add('loaded'), { once: true });
        image.addEventListener('error', () => image.remove(), { once: true });
        if (thumbnailObserver) thumbnailObserver.observe(image);
        else image.src = image.dataset.thumbnail;
      });
    }
  }
  const status = device => device.holder_kind === 'unassigned' ? 'Available' : 'Assigned';
  const badge = device => `<span class="status ${device.holder_kind === 'unassigned' ? 'available' : 'assigned'}">${status(device)}</span>`;
  const conditionBadge = device => `<span class="status ${attention(device) ? 'repair' : device.device_condition === 'Good' ? 'available' : 'unknown'}">${esc(device.device_condition)}</span>`;
  const devices = () => state.devices.map(device => displayDevice(device, state.employees, state.branches));
  // Negative keys distinguish inventory associates from numeric HR IDs in UI filters only.
  const people = () => [...state.employees, ...(state.associates || []).map(person => ({ ...person,
    id: -Number(person.id), employee_id: 'Associate', hr_role: 'Inventory only', department: '', branch: 'Other' }))];
  const chosen = () => devices().find(device => device.id === selected);
  const otherLast = values => [...values.filter(value => value !== 'Other'), ...values.filter(value => value === 'Other')];
  const options = (values, selectedValue = '') => values.map(value => `<option value="${esc(value)}"${value === selectedValue ? ' selected' : ''}>${esc(value)}</option>`).join('');
  const field = (label, name, value = '', extra = '') => `<label>${esc(label)}<input name="${esc(name)}" value="${esc(value)}" maxlength="255" ${extra}></label>`;

  async function request(action, method = 'GET', data = null) {
    if (method === 'POST' && !canEdit) throw new Error('This account has read-only access.');
    const response = await fetch(`api.php?action=${action}`, {
      method, credentials: 'same-origin', headers: method === 'POST' ? { ...(data instanceof FormData ? {} : { 'Content-Type': 'application/json' }), 'X-CSRF-Token': csrf } : {},
      ...(method === 'POST' ? { body: data instanceof FormData ? data : JSON.stringify(data) } : {})
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
      types = otherLast(state.desktop_preset?.device_types || types);
      const currentType = $('#type-filter').value;
      $('#type-filter').innerHTML = '<option value="">All types</option>' + options(types);
      $('#type-filter').value = types.includes(currentType) ? currentType : '';
      const departments = [...new Set(state.departments.map(row => row.name))];
      const branches = [...new Set([...state.branches.map(row => row.name), 'Other'])];
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
    $('#count-people').textContent = people().length;
    $('#count-shared').textContent = rows.filter(device => device.holder_kind === 'shared').length;
    $('#count-attention').textContent = rows.filter(attention).length;
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
    thumbnailObserver?.disconnect();
    activityObserver?.disconnect();
    activityObserver = null;
    $('#activity-more').hidden = true;
    $('#activity-more').onclick = null;
    $('#table-scroll').classList.toggle('activity-scroll', view === 'activity');
    const rows = devices();
    renderStats(rows);
    $('#page-title').textContent = titles[view];
    $('#add-device').textContent = view === 'people' ? '＋ Add User' : '＋ Add device';
    const filteredEmployee = people().find(employee => Number(employee.id) === Number(employeeFilter));
    $('#page-description').textContent = view === 'people' ? 'HR employees and inventory associates. Added users do not get reflected onto HR dashboard.' :
      view === 'activity' ? 'All recorded inventory changes, newest first. Select a device to see its full history.' :
      filteredEmployee ? `Equipment assigned to ${filteredEmployee.name}.` : 'Equipment and the people who hold it.';
    $('#search').placeholder = view === 'people' ? 'Search name, employee number, branch, department, or role…' :
      view === 'activity' ? 'Search change, device ID, or user…' : 'Search device, holder, or spec: RAM: 16GB…';
    $('#search').title = !['people', 'activity'].includes(view) ? 'Search specifications using name: value, e.g. RAM: 16GB or OS: Windows 11. Use the full specification name; values can be partial. Searches ignore case.' : '';
    $('#navigation').querySelectorAll('[data-view]').forEach(button => button.classList.toggle('active', button.dataset.view === view));
    const detailVisible = !['people', 'activity'].includes(view) && Boolean(chosen());
    $('#inventory-layout').classList.toggle('wide', !detailVisible);
    $('#inventory-layout').classList.toggle('drawer-open', detailVisible);
    $('#detail').hidden = !detailVisible;
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
      <td><div class="device-cell"><span class="device-icon" aria-hidden="true">${portrait(device)}</span><div><button class="row-name" data-device="${device.id}" aria-controls="detail" aria-expanded="${device.id === selected}">${esc(device.name)}</button><small>${esc(device.device_type)} · #${device.id}</small></div></div></td>
      <td class="serial">${esc(device.serial_number || 'No serial recorded')}<small>${esc(device.asset_tag || 'No asset tag recorded')}</small></td>
      <td>${esc(device.holder_kind === 'shared' ? 'Shared department' : device.holder_name || 'Unassigned')}<small>${esc(device.department)}</small></td>
      <td>${badge(device)}</td><td>${conditionBadge(device)}</td></tr>`).join('') || '<tr><td colspan="5" class="empty-state">No devices match your filters.</td></tr>';
    $('#table-footer').textContent = `Showing ${visible.length} of ${rows.length} devices`;
    renderDetail();
    observeThumbnails();
  }

  function renderPeople(rows) {
    const query = $('#search').value.toLocaleLowerCase().trim();
    const department = $('#department-filter').value;
    const branch = $('#branch-filter').value;
    const employees = people().filter(employee => (!department || employee.department === department) && (!branch || employee.branch === branch) &&
      (!query || [employee.name, employee.employee_id, employee.branch, employee.department, employee.job_title, employee.hr_role].join(' ').toLocaleLowerCase().includes(query)));
    const equipmentCounts = new Map();
    rows.forEach(device => {
      const employeeId = device.holder_kind === 'associate' ? -Number(device.associate_id) : Number(device.hr_employee_id);
      if (employeeId) equipmentCounts.set(employeeId, (equipmentCounts.get(employeeId) || 0) + 1);
    });
    const equipmentCount = employee => equipmentCounts.get(Number(employee.id)) || 0;
    const sort = $('#people-sort').value;
    employees.sort((a, b) => {
      const countOrder = sort === 'zero-first' ? Number(equipmentCount(a) > 0) - Number(equipmentCount(b) > 0) :
        sort === 'has-first' ? Number(equipmentCount(b) > 0) - Number(equipmentCount(a) > 0) : 0;
      return countOrder || a.name.localeCompare(b.name);
    });
    $('#table-head').innerHTML = '<tr><th>Person</th><th>Branch</th><th>Department</th><th>Job title / HR role</th><th>Equipment</th></tr>';
    $('#table-body').innerHTML = employees.map(employee => `<tr><td>${esc(employee.name)}<small>${esc(employee.employee_id)}</small></td><td>${esc(employee.branch || 'Not set')}</td><td>${esc(employee.department || 'Not set')}</td><td>${esc(employee.job_title)}<small>${esc(employee.hr_role)}</small></td><td><button class="button" data-person="${Number(employee.id)}">View devices (${equipmentCount(employee)})</button></td></tr>`).join('') || '<tr><td colspan="5" class="empty-state">No employees match your filters.</td></tr>';
    $('#table-footer').textContent = `${employees.length} people · HR employees and inventory associates`;
  }

  function photoMarkup(event, photo, index, current = false) {
    const url = `api.php?action=evidence&amp;event=${Number(event.id)}&amp;photo=${index}`;
    const removed = (event.removed_photo_indexes || []).includes(index);
    return `<figure class="saved-photo"><a href="${url}" target="_blank" rel="noopener"><img loading="lazy" decoding="async" src="${url}&amp;thumbnail=1" alt="${esc(photo.name || 'Evidence photo')}" title="${esc(event.recorded_at)}"></a>
      ${current && canEdit ? `<button type="button" class="text-button" data-remove-photo="${index}" data-photo-event="${Number(event.id)}" aria-label="Remove ${esc(photo.name || 'photo')} from current photos">Remove</button>` : removed ? '<figcaption>Removed · retained in history</figcaption>' : ''}</figure>`;
  }
  function eventsMarkup(events) {
    return events.map(event => `<div class="timeline-item"><span class="timeline-dot"></span><strong>${esc(event.message)}</strong><small>Recorded by ${esc(event.actor?.name || event.actor?.username || 'Not recorded')}${event.actor?.name && event.actor?.username ? ` (${esc(event.actor.username)})` : ''}</small><small>Recorded ${esc(event.recorded_at)}${event.handover_date ? ` · Handover ${esc(event.handover_date)}` : ''}</small><div class="evidence-photos">${(event.photos || []).map((photo, index) => photoMarkup(event, photo, index)).join('')}</div></div>`).join('') || '<p class="empty-state">No history recorded.</p>';
  }

  function renderActivity() {
    $('#table-scroll').scrollTop = 0;
    const query = $('#search').value.toLocaleLowerCase().trim();
    const events = state.activity.filter(event => !query || `${event.message} ${event.device_id} ${event.event_type} ${event.actor?.name || ''} ${event.actor?.username || ''}`.toLocaleLowerCase().includes(query));
    $('#table-head').innerHTML = '<tr><th>Device ID</th><th>Change</th><th>Changed by</th><th>Recorded</th><th>Handover date</th></tr>';
    const markup = batch => batch.map(event => `<tr><td><button class="text-button" data-activity-device="${Number(event.device_id)}">#${Number(event.device_id)}</button></td><td>${esc(event.message)}</td><td>${esc(event.actor?.name || event.actor?.username || 'Not recorded')}${event.actor?.username ? `<small>${esc(event.actor.username)}</small>` : ''}</td><td>${esc(event.recorded_at)}</td><td>${esc(event.handover_date || 'Unknown / not applicable')}</td></tr>`).join('');
    let shown = 0;
    const more = $('#activity-more');
    const update = () => {
      more.hidden = shown >= events.length;
      $('#table-footer').textContent = 'Showing ' + shown + ' of ' + events.length + ' changes';
      if (more.hidden) activityObserver?.disconnect();
    };
    const loadMore = () => {
      if (view !== 'activity' || shown >= events.length) return;
      const batch = events.slice(shown, shown + 50);
      $('#table-body').insertAdjacentHTML('beforeend', markup(batch));
      shown += batch.length;
      update();
    };
    $('#table-body').innerHTML = events.length ? '' : '<tr><td colspan="5" class="empty-state">No matching activity.</td></tr>';
    loadMore();
    update();
    more.onclick = loadMore;
    if (!more.hidden && typeof IntersectionObserver !== 'undefined') {
      const observer = new IntersectionObserver(entries => {
        if (activityObserver !== observer || !entries.some(entry => entry.isIntersecting)) return;
        const scroller = $('#table-scroll');
        if (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight > 100) return;
        observer.unobserve(more);
        loadMore();
        if (!more.hidden) requestAnimationFrame(() => {
          if (activityObserver === observer && !more.hidden) observer.observe(more);
        });
      }, { root: $('#table-scroll'), rootMargin: '0px 0px 100px 0px', threshold: 0 });
      activityObserver = observer;
      observer.observe(more);
    }

  }

  async function renderDetail() {
    const generation = ++historyRequest;
    const device = chosen();
    if (!device) { $('#detail').innerHTML = ''; return; }
    const id = device.id;
    const specs = Object.entries(device.specifications).filter(([, value]) => value);
    $('#detail').innerHTML = `<header class="drawer-header"><div class="large-device-icon" aria-hidden="true">${portrait(device)}</div><div class="drawer-identity"><div class="detail-top">DEVICE #${id}</div><h2>${esc(device.name)}</h2><div class="detail-type">${esc(device.device_type)} ${badge(device)}</div></div><button class="close-button" id="close-detail" aria-label="Close device details">×</button></header>
      <div class="drawer-content">
      <div class="serial-card"><span>SERIAL NUMBER</span><strong>${esc(device.serial_number || 'Not recorded')}</strong><span class="source-hint">ASSET TAG</span><strong>${esc(device.asset_tag || 'Not recorded')}</strong></div>
      <div class="drawer-columns"><div class="detail-section"><h3>Current assignment</h3><p>${esc(device.holder_kind === 'shared' ? `${device.department} (shared)` : device.holder_name || 'Unassigned')}</p><small>${esc([device.job_title, device.department, device.branch].filter(Boolean).join(' · '))}</small>
      ${device.employee_missing ? '<p class="assignment-hint">Employee unavailable in HR. Last recorded holder is retained.</p>' : ''}
      <div class="detail-meta"><span>Location</span><strong>${esc(device.location || 'Not recorded')}</strong></div><div class="detail-meta"><span>Condition</span>${conditionBadge(device)}</div>
      <div class="detail-meta"><span>Handover date</span><strong>${esc(device.handover_date || 'Not recorded')}</strong></div>
      </div>
      <div class="detail-section"><h3>Specifications</h3><div class="specs">${specs.map(([key, value]) => `<div><span>${esc(key)}</span><strong>${esc(value)}</strong></div>`).join('') || '<small>No specifications recorded.</small>'}</div>
      ${device.source ? `<p class="source-hint">Imported from ${esc(device.source.sheet)} · row ${Number(device.source.row)}</p>` : ''}</div></div>
      <div class="detail-section"><h3>Evidence photos</h3><div id="device-photos" class="evidence-photos" aria-live="polite"><small>Loading photos…</small></div></div>
      <div class="detail-section history"><div class="drawer-section-heading"><h3>History</h3><button class="text-button" id="add-note">＋ Add note</button></div><div id="history" class="history-list" aria-live="polite"><small>Loading history…</small></div></div></div>
      <footer class="drawer-actions"><button class="button" id="edit-device">Edit device</button><button class="button primary" id="assign-device">Assign / return ↗</button></footer>`;
    try {
      const history = await request(`history&id=${id}`);
      if (historyRequest === generation && selected === id && $('#history')) {
        $('#history').innerHTML = eventsMarkup(history);
        $('#device-photos').innerHTML = history.flatMap(event => (event.photos || []).flatMap((photo, index) => (event.removed_photo_indexes || []).includes(index) ? [] : [photoMarkup(event, photo, index, true)])).join('') || '<small>No current photos. Add photos when creating or editing this device. Removed photos remain in history.</small>';
      }
    } catch (error) {
      if (historyRequest === generation && selected === id && $('#history')) {
        $('#history').textContent = error.message;
        $('#device-photos').textContent = 'Photos could not be loaded. Select Refresh to try again.';
      }
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

  function deviceTypeOptions(selectedType, allowCreate = false) {
    const action = '<option value="' + createTypeAction + '">Create device type...</option>';
    const choices = types.map(type => (allowCreate && type === 'Other' ? action : '') + options([type], selectedType)).join('');
    return choices + (allowCreate && !types.includes('Other') ? action : '');
  }

  function syncDeviceTypes() {
    types = otherLast(state.desktop_preset.device_types);
    for (const selector of [$('#type-filter'), $('#dialog-fields select[name="device_type"]')]) {
      if (!selector) continue;
      const selectedType = selector.value;
      selector.innerHTML = selector === $('#type-filter') ? '<option value="">All types</option>' + options(types, selectedType) : deviceTypeOptions(selectedType, true);
      selector.value = selectedType;
    }
  }

  function deviceValueMarkup(value) {
    const choice = /^(yes|no)$/i.test(value) ? value.toLowerCase() : value ? 'other' : '';
    return '<select data-spec-value aria-label="Specification value"><option value="">Select value</option>' + options(['Yes', 'No', 'Other'], choice ? choice[0].toUpperCase() + choice.slice(1) : '') + '</select><textarea rows="2" maxlength="4000" aria-label="Custom specification value"' + (choice === 'other' ? '' : ' hidden disabled') + '>' + esc(value) + '</textarea>';
  }

  function specMarkup(key = '', value = '', arrange = false, selectable = false) {
    return `<div class="spec-edit-row${arrange ? ' preset-row' : ''}" data-spec-row${arrange ? ` data-spec-default="${esc(value)}"` : ''}>${arrange ? '<div class="preset-order"><button type="button" class="text-button" draggable="true" data-drag-spec aria-label="Drag specification to reorder">↕</button><button type="button" class="text-button" data-move-spec="-1" aria-label="Move specification up">↑</button><button type="button" class="text-button" data-move-spec="1" aria-label="Move specification down">↓</button></div>' : ''}<label>Specification name<input maxlength="100" value="${esc(key)}" required></label>${arrange ? '' : `<label>Value${selectable ? deviceValueMarkup(value) : `<textarea rows="2" maxlength="4000">${esc(value)}</textarea>`}</label>`}<button type="button" class="text-button" data-remove-spec>Remove</button></div>`;
  }

  function specValue(row) {
    const choice = row.querySelector('[data-spec-value]');
    if (choice && choice.value !== 'Other') return choice.value;
    return row.querySelector('textarea')?.value.trim() ?? row.dataset.specDefault ?? '';
  }

  function readSpecs(container) {
    return [...container.querySelectorAll('[data-spec-row]')].map(row => {
      return { name: row.querySelector('input').value.trim(), value: specValue(row) };
    });
  }

  function renderPreset() {
    const panel = $('#preset-panel');
    const preset = state.desktop_preset;
    if (!preset) { panel.innerHTML = '<p class="empty-state">Refresh to load the Desktop preset.</p>'; return; }
    const presetTypes = types.filter(type => type !== 'Other');
    if (!presetTypes.includes(presetType) && presetType !== createTypeAction) presetType = '';
    const creating = presetType === createTypeAction;
    const fields = presetDrafts.get(presetType) || preset.presets?.[presetType] || (presetType === 'Desktop' ? preset.fields : []);
    panel.innerHTML = `<label>Device type<select id="preset-type"><option value="">Select device type</option>${options(presetTypes, presetType)}<option value="${createTypeAction}"${creating ? ' selected' : ''}>Create device type...</option></select></label><form id="preset-form"${presetType && !creating ? '' : ' hidden'}><h2>${esc(presetType)}</h2><p>Drag the reorder handle to arrange fields, or use the arrow buttons. Add and arrange specification names. Enter values when adding a device. Saving affects future creation only.</p><div id="preset-editor">${fields.map(item => specMarkup(item.name, item.value, true)).join('')}</div><div class="action-row"><button type="button" class="button" id="preset-add">Add specification</button><button type="submit" class="button primary">Save preset</button></div><p id="preset-status" role="status"></p></form><form id="create-type-form"${creating ? '' : ' hidden'}><label>Device type name<input name="type_name" id="new-type-name" maxlength="30" required></label><button type="submit" class="button">Save device type</button><p id="type-status" role="status"></p></form>`;
    $('#preset-type').addEventListener('change', () => {
      if (presetType && presetType !== createTypeAction && canEdit) presetDrafts.set(presetType, readSpecs($('#preset-editor')));
      presetType = $('#preset-type').value;
      renderPreset();
      if (presetType === createTypeAction) $('#new-type-name').focus();
    });
    if (!canEdit) { panel.querySelectorAll('input, textarea, button, [data-preset-value]').forEach(element => { element.disabled = true; }); return; }
    $('#create-type-form').addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.submitter;
      if (button) button.disabled = true;
      try {
        const name = $('#new-type-name').value.trim();
        if (presetType && presetType !== createTypeAction) presetDrafts.set(presetType, readSpecs($('#preset-editor')));
        state.desktop_preset = await request('preset', 'POST', { version: preset.version, name });
        syncDeviceTypes();
        if (selectCreatedType) {
          const selector = $('#dialog-fields select[name="device_type"]');
          selector.value = name;
          selector.dispatchEvent(new Event('change', { bubbles: true }));
          selectCreatedType = false;
        }
        presetType = name;
        renderPreset();
        $('#preset-status').textContent = name + ' created. Add its specification names below.';
      } catch (error) { $('#type-status').textContent = error.message; }
      finally { if (button) button.disabled = false; }
    });
    const editor = $('#preset-editor');
    let dragged = null;
    editor.addEventListener('dragstart', event => {
      if (!event.target.closest('[data-drag-spec]')) { event.preventDefault(); return; }
      dragged = event.target.closest('[data-spec-row]');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', 'specification');
    });
    editor.addEventListener('dragover', event => { if (dragged) { event.preventDefault(); event.dataTransfer.dropEffect = 'move'; } });
    editor.addEventListener('drop', event => {
      if (!dragged) return;
      event.preventDefault();
      const row = event.target.closest('[data-spec-row]');
      if (row && row !== dragged) editor.insertBefore(dragged, event.clientY > row.getBoundingClientRect().top + row.getBoundingClientRect().height / 2 ? row.nextSibling : row);
      dragged = null;
    });
    editor.addEventListener('dragend', () => { dragged = null; });
    editor.addEventListener('click', event => {
      const button = event.target.closest('button');
      if (!button) return;
      const row = button.closest('[data-spec-row]');
      if (button.hasAttribute('data-remove-spec')) row.remove();
      if (button.dataset.moveSpec === '-1' && row.previousElementSibling) editor.insertBefore(row, row.previousElementSibling);
      if (button.dataset.moveSpec === '1' && row.nextElementSibling) editor.insertBefore(row.nextElementSibling, row);
    });
    $('#preset-add').addEventListener('click', () => {
      if (editor.querySelectorAll('[data-spec-row]').length >= 128) { $('#preset-status').textContent = 'Use up to 128 fields.'; return; }
      editor.insertAdjacentHTML('beforeend', specMarkup('', '', true));
      editor.lastElementChild.querySelector('input').focus();
    });
    $('#preset-form').addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.submitter;
      const savedType = presetType;
      if (button) button.disabled = true;
      try {
        state.desktop_preset = await request('preset', 'POST', { version: preset.version, device_type: savedType, fields: readSpecs(editor) });
        presetDrafts.delete(savedType);
        renderPreset();
        $('#preset-status').textContent = savedType + ' preset saved.';
      } catch (error) { $('#preset-status').textContent = error.message; }
      finally { if (button) button.disabled = false; }
    });
  }

  function editDevice(device = null, initialType = 'Laptop') {
    if (!canEdit) return;
    let pendingPhotos = () => [];
    const branchId = device ? device.location_branch_id : state.branches.find(branch => branch.name.toUpperCase() === 'APO')?.id;
    const missingBranch = branchId && !state.branches.some(branch => branch.id === branchId);
    const locationOptions = (missingBranch ? `<option value="${Number(branchId)}" selected>${esc(device.location || 'Unavailable branch')}</option>` : '') +
      state.branches.map(branch => `<option value="${branch.id}"${branch.id === branchId ? ' selected' : ''}>${esc(branch.name)}</option>`).join('');
    openDialog(device ? 'Edit device' : 'Add device', 'Serial numbers and asset tags may be blank when unknown.',
      `<div class="field-row"><div><div class="device-type-heading"><label for="device-type">Device type</label>${!device ? '<button type="button" class="text-button" id="manage-preset">Manage preset</button>' : ''}</div><select id="device-type" name="device_type">${deviceTypeOptions(device?.device_type || initialType, !device)}</select></div>${field('Brand / model *', 'name', device?.name, 'required')}</div>
      <div class="field-row">${field('Serial number', 'serial_number', device?.serial_number)}${field('Asset tag', 'asset_tag', device?.asset_tag)}</div>
      <div class="field-row"><label>Condition<select name="device_condition">${options(conditions, device?.device_condition || 'Unknown')}</select></label><label>Location<select name="location_branch_id"><option value="">Not recorded</option>${locationOptions}</select></label></div>
      <label>Evidence photos (optional)<input id="evidence-photos" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Paste an image with Ctrl+V anywhere in this form, or choose files. Up to 5 JPEG, PNG or WebP photos, 20 MiB and 40 megapixels each. Large photos are compressed before upload. Photos are saved when you select Save.</small></label><div id="evidence-selection" aria-live="polite"></div>
      <small id="photo-progress" aria-live="polite"></small><div><h3>Specifications</h3><small>Hardware and software, including OS, LICENSE, MICROSOFT 365, ANTI VIRUS, SAP, ADOBE, and VIBER. Up to 128 fields; blank values are cleared.</small><div id="spec-editor"></div><button type="button" class="button" id="add-specification">Add specification</button></div>`, async form => {
        const specifications = Object.create(null);
        for (const row of $('#spec-editor').querySelectorAll('[data-spec-row]')) {
          const key = row.querySelector('input').value.trim();
          const value = specValue(row);
          if (!key && !value) continue;
          if (!key) throw new Error('Give each specification a name.');
          if (Object.hasOwn(specifications, key)) throw new Error(`Repeated specification: ${key}`);
          specifications[key] = value;
        }
        const files = await InventoryPhotos.prepare(pendingPhotos(), message => {
          $('#photo-progress').textContent = message;
        });
        $('#photo-progress').textContent = files.length ? 'Photos ready. Saving…' : '';
        const payload = { ...form, specifications, ...(device ? { id: device.id, version: device.version } : {}) };
        let data = payload;
        if (files.length) {
          data = new FormData();
          data.append('device', JSON.stringify(payload));
          files.forEach(file => data.append('photos[]', file));
        }
        const saved = await request('device', 'POST', data);
        selected = saved.id;
      });
    pendingPhotos = InventoryPhotos.attach($('#dialog'), $('#evidence-photos'), $('#evidence-selection'), $('#form-error'), () => $('#save-dialog').disabled);
    const addSpec = (key = '', value = '') => {
      if ($('#spec-editor').querySelectorAll('[data-spec-row]').length >= 128) return;
      $('#spec-editor').insertAdjacentHTML('beforeend', specMarkup(key, value, false, !device));
    };
    for (const [key, value] of Object.entries(device?.specifications || {})) addSpec(key, value);
    if (!device) {
      const openPreset = (creating = false) => {
        presetDrafts.clear();
        selectCreatedType = creating;
        const type = $('#dialog-fields select[name="device_type"]').value;
        presetType = creating ? createTypeAction : type === 'Other' ? '' : type;
        renderPreset();
        $('#preset-dialog').showModal();
        if (creating) $('#new-type-name').focus();
      };
      $('#manage-preset').addEventListener('click', () => openPreset());
      const drafts = new Map();
      const selector = $('#dialog-fields select[name="device_type"]');
      let currentType = initialType;
      const populate = type => {
        const fields = drafts.get(type) || (state.desktop_preset?.presets?.[type] || (type === 'Desktop' ? state.desktop_preset?.fields || [] : []));
        $('#spec-editor').innerHTML = '';
        fields.forEach(item => addSpec(item.name, item.value));
      };
      populate(initialType);
      selector.addEventListener('change', () => {
        if (selector.value === createTypeAction) {
          selector.value = currentType;
          openPreset(true);
          return;
        }
        drafts.set(currentType, readSpecs($('#spec-editor'))); currentType = selector.value; populate(currentType);
      });
    }
    $('#add-specification').addEventListener('click', () => addSpec());
    $('#spec-editor').addEventListener('change', event => {
      if (!event.target.matches('[data-spec-value]')) return;
      const text = event.target.closest('[data-spec-row]').querySelector('textarea');
      text.hidden = text.disabled = event.target.value !== 'Other';
      if (!text.hidden) text.focus();
    });
    $('#spec-editor').addEventListener('click', event => { if (event.target.closest('[data-remove-spec]')) event.target.closest('[data-spec-row]').remove(); });
  }

  function assignDevice() {
    const device = chosen();
    if (!device) return;
    openDialog('Assign / return equipment', device.name,
      `<label>Assign to<select name="holder_kind" id="holder-kind"><option value="employee">HR employee</option><option value="associate">Inventory associate</option><option value="shared">Shared department</option><option value="unassigned">Return to unassigned pool</option></select></label>
      <label id="associate-field" hidden>Associate<select name="associate_id"><option value="">Select an associate</option>${(state.associates || []).map(person => `<option value="${Number(person.id)}"${Number(person.id) === Number(device.associate_id) ? ' selected' : ''}>${esc(person.name)}</option>`).join('')}</select></label>
      <label id="employee-field">Employee<select name="hr_employee_id"><option value="">Select an employee</option>${state.employees.map(employee => `<option value="${Number(employee.id)}"${Number(employee.id) === device.hr_employee_id ? ' selected' : ''}>${esc(`${employee.name} · ${employee.employee_id} · ${employee.department}`)}</option>`).join('')}</select></label>
      <label id="department-field" hidden>Shared department<select name="department_id"><option value="">Select a department</option>${state.departments.map(department => `<option value="${Number(department.id)}"${Number(department.id) === device.department_id ? ' selected' : ''}>${esc(department.name)}</option>`).join('')}</select></label>
      <label>Handover date (if known)<input name="handover_date" type="date"><small>Leave blank if you are correcting an imported holder and the original date is unknown.</small></label>
      <label>Handover / return notes<textarea name="notes" rows="3" maxlength="2000"></textarea></label>`,
      async form => { await request('assignment', 'POST', { ...form, id: device.id, version: device.version }); });
    const selector = $('#holder-kind');
    selector.value = ['shared', 'associate'].includes(device.holder_kind) ? device.holder_kind : 'employee';
    const updateFields = () => {
      $('#employee-field').hidden = selector.value !== 'employee';
      $('#associate-field').hidden = selector.value !== 'associate';
      $('#associate-field select').required = selector.value === 'associate';
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
    } finally {
      $('#save-dialog').disabled = false;
      if ($('#photo-progress')) $('#photo-progress').textContent = '';
    }
  });

  function navigate(nextView) {
    selected = null;
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
    if (target.dataset.removePhoto !== undefined) { removePhoto(target); return; }
    if (target.id === 'close-detail') { closeDetail(); return; }
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
    if (target.id === 'add-device') {
      if (view === 'people') {
        openDialog('Add User', 'Create an inventory associate. This does not add an attendance employee or a login account.',
          field('Full name *', 'name', '', 'required') + field('Job title / affiliation', 'job_title') +
          '<label>Branch<input value="Other" readonly></label>',
          async form => { await request('associate', 'POST', form); });
      } else editDevice(null, 'Laptop');
    }
    if (target.id === 'edit-device') editDevice(chosen());
    if (target.id === 'assign-device') assignDevice();
    if (target.id === 'clear-person') { employeeFilter = null; render(); }
    if (target.id === 'close-preset') $('#preset-dialog').close();
    if (target.id === 'close-dialog' || target.id === 'cancel-dialog') $('#dialog').close();
    if (target.id === 'add-note') {
      const device = chosen();
      openDialog('Add a note', device.name, '<label>Note *<textarea name="message" required maxlength="2000" rows="4"></textarea></label>',
        async form => { await request('note', 'POST', { id: device.id, message: form.message }); });
    }
  });
  function closeDetail() {
    const previous = selected;
    selected = null;
    render();
    document.querySelector(`button[data-device="${previous}"]`)?.focus();
  }
  async function removePhoto(button) {
    const device = chosen();
    if (!device || button.disabled) return;
    button.disabled = true;
    showError('');
    try {
      await request('photo-remove', 'POST', { id: device.id, version: device.version,
        event: Number(button.dataset.photoEvent), photo: Number(button.dataset.removePhoto) });
      await reload();
    } catch (error) { showError(error.message); }
    finally { button.disabled = false; }
  }
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && $('#preset-dialog').open) {
      event.preventDefault();
      $('#preset-dialog').close();
      return;
    }
    if (event.key === 'Escape' && selected && !$('#preset-dialog').open && !$('#dialog').open && !$('#import-dialog').open && !$('#photo-viewer').open) {
      event.preventDefault();
      closeDetail();
    }
  });
  $('#search').addEventListener('input', render);
  document.addEventListener('inventory-imported', reload);
  ['#type-filter', '#people-sort', '#department-filter', '#branch-filter', '#status-filter'].forEach(selector => $(selector).addEventListener('change', render));
  $('#type-filter').innerHTML += options(types);
  reload();
})();
