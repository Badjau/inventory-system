/* Pure display/filter helpers, shared with the small Node test script. */
(function (root) {
  'use strict';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[char]);
  const attention = device => ['Needs attention', 'Faulty'].includes(device.device_condition);
  const displayDevice = (device, employees, branches = []) => {
    const locationBranch = branches.find(branch => Number(branch.id) === Number(device.location_branch_id));
    device = { ...device, location: locationBranch?.name || device.location };
    if (device.holder_kind !== 'employee') return { ...device };
    const person = employees.find(employee => Number(employee.id) === Number(device.hr_employee_id));
    return person ? { ...device, holder_name: person.name, department: person.department, department_id: Number(person.department_id) || null,
      branch: person.branch, job_title: person.job_title, employee_missing: false } : { ...device, employee_missing: true };
  };
  function filterDevices(devices, filter) {
    const query = (filter.query || '').trim().toLocaleLowerCase();
    return devices.filter(device => {
      const text = [device.id, device.name, device.device_type, device.serial_number, device.asset_tag,
        device.holder_name, device.department, device.branch, device.job_title].join(' ').toLocaleLowerCase();
      return (!query || text.includes(query)) && (!filter.type || device.device_type === filter.type) &&
        (!filter.department || device.department === filter.department) &&
        (!filter.branch || device.branch === filter.branch) &&
        (!filter.employee || Number(device.hr_employee_id) === Number(filter.employee)) &&
        (filter.view !== 'shared' || device.holder_kind === 'shared') &&
        (filter.view !== 'attention' || attention(device)) &&
        (!filter.status || (filter.status === 'available' ? device.holder_kind === 'unassigned' :
          filter.status === 'imported' ? device.holder_kind === 'imported' : device.holder_kind !== 'unassigned'));
    });
  }
  const model = { escape, attention, displayDevice, filterDevices };
  if (typeof module !== 'undefined' && module.exports) module.exports = model;
  else root.InventoryModel = Object.freeze(model);
})(globalThis);
