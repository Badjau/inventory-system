# Implementation and maintenance reference

## Bulk device changes

In Devices, check individual active devices or select all filtered active devices. Selections survive filters and refresh, with hidden selections counted; leaving Devices clears them. Device-name clicks continue to open the drawer. Edit details supports explicitly enabled condition/location changes and multiple specification Set/Remove operations. Set adds missing keys to every selected device; unrelated keys remain untouched. Names ignore case and whitespace differences, preserving existing spellings. Ambiguous existing names must be corrected individually. Assign / return is a separate batch action. Both flows require review and apply; Back preserves the draft. A conflict or unconfirmed write requires Refresh targets and another review. No schema upgrade is needed.

Bulk browser smoke checks (authenticated app and MySQL):

1. Select individual rows, filtered select-all, and an empty result; verify indeterminate state, selected row styling, hidden selection counts, refresh retention, and clearing on navigation. Disposed devices cannot be checked. Clicking checkboxes must not open the drawer; name buttons still do.
2. Select devices with mixed conditions, locations, and specifications. Change condition only, then clear location explicitly. Set Microsoft 365 → Yes and Anti Virus → Yes in the same operation, including a device missing both keys. Try Other text, Remove, repeated names, case/spacing variants, ambiguous existing keys, and the specification count limit. Unrelated values, identifiers, source records, and photos must remain intact.
3. Review additions/changes/removals and per-device differences; Back retains draft values; Cancel and Escape save nothing. Check unchanged records receive no history/version increment and all-no-op reviews disable Apply. Verify keyboard focus restoration, mobile modal layout, and the fixed selection bar.
4. Assign to employee/associate/shared department and return devices with mixed prior holders. Review old holders, handover dates, and notes; verify device History, Activity, and People counts. Previous-holder snapshots must remain available.
5. Change one selected device in another tab after preview. Apply must save nothing, list conflicts, and require target refresh and a new review. Repeat with a disposed/deleted device, expired/replaced preview, failed request, and rapid double submission. Read-only accounts must have no selection controls and writes must remain protected by authorization and CSRF.

Run the normal checks plus `php tests/run.php --bulk-only`, `php tests/run.php --mysql --bulk-only`, and `node tests/bulk-browser.js`. The bulk-only option uses the same isolated fixture/temporary MySQL database as the full suite and does not require the workbook. The browser script uses real headless Chromium with synthetic API responses and no account or live data writes; set `BROWSER_PATH` if Chrome/Edge is elsewhere (Node 24+). It does not replace authenticated browser checks. Browser profiles are created and removed under ignored `.scratch/`.

Preserved from the former README. This document contains detailed operating procedures, feature-specific browser smoke checks, and historical implementation reports. Device counts, dated baseline commands, and past test results describe particular work sessions; they are not current verification results or required setup steps. Start with [README.md](../README.md) for current setup and [AGENTS.md](../AGENTS.md) for contributor instructions. Run bulk data changes only when explicitly intended.

People's **View overview** opens current equipment beside a handover timeline. Filter received/released records, expand a device journey, open a device's existing detail drawer, or follow a linked holder to their profile. Back restores directory filters or the previous profile. HR employees and inventory associates use distinct numeric identities; workbook names without links are not matched automatically. Historical names remain visible after directory deletion. No setup or migration is required.

Person overview smoke checks: open an employee and an associate, including someone with no current devices; verify counts and equipment, received/released filters, initial five records and Show more, and keyboard focus. Follow another holder and go Back; confirm profile filters/scroll and People search/branch/department/sort survive. Open current and previously held equipment, close with Escape, and confirm return to the profile. Check a return to the available pool, an import reassignment, initial assignment, a deleted-holder snapshot, and separate HR/associate records sharing the same numeric ID. Device edits/notes/date-only changes must not create handovers. Confirm handover dates and recording timestamps are distinguished. On mobile, check scrolling, long names, no horizontal clipping, accessible filters and the device drawer. Read-only access must retain the existing restrictions.

Device search includes names saved in assignment history, so searching a previous holder finds equipment after return or reassignment. Smoke check: assign a test device, return or reassign it, reload, and search the former holder's name (including partial names and different case). Confirm the device appears with its current assignment, unrelated devices stay excluded, and branch/status filters and specification searches still work.

The first working slice follows the layout in the original `index.html`: device table, counts, filters, and a detail panel. Run the application from **`public/`**. The original mockup stays at the project root as a design reference.

The website is the main inventory workspace. Register new equipment, edit identifiers/specifications, assign or return devices, and record notes there. Use **Import Excel** to review and approve workbook updates, and **Export Excel** to download current inventory in a reimportable format. Excel synchronization is manual.

The app uses PHP 8.3, PDO, MySQL, and plain HTML/CSS/JavaScript. Employee details come from **`attendance_monitoring`**, the local timekeeping database confirmed during setup. `mcasiafoodtrade_db1` was superseded by that choice. Inventory owns only `inv_devices` and `inv_events`; employee tables receive only SELECT queries.

## Start from Laragon Terminal

Inventory associates use the inventory-only **Other** branch automatically, including existing associates. Select **Other** in the branch filter to find their People records or assigned equipment. HR branches and equipment location choices remain managed by the HR directory.

People's **Add User** creates an inventory-only associate with a name and optional job title/affiliation. Associates appear alongside HR employees and can hold equipment through **Assign / return → Inventory associate**. They do not create attendance employees or login accounts. Run `php tools/setup.php` after updating to create `inv_associates` and add the nullable `inv_devices.associate_id` column. Existing HR records, device IDs, and history are preserved.

Associate smoke check: add an associate in People, reload, assign a test device to them, use View devices to confirm the count, export/reimport that device, then return it and confirm the old name remains in history. Verify the attendance employee directory is unchanged. Check Add User validation, keyboard access, Escape, and mobile layout.

PHP 8.3 with `pdo_mysql`, `zip`, `simplexml`, and `mbstring` is required. These extensions were found in the installed Laragon PHP. Composer, npm, Python, and a virtual environment are not needed. Node is optional for JavaScript checks.

1. Start Laragon's MySQL service. Open **Laragon Terminal**, then run:

   ```bat
   cd /d C:\laragon\www\inventory-tracking-system
   php tools\configure.php C:\laragon\www\attendancemonitoring\.env
   php tools\doctor.php
   ```

   `configure.php` copies only the HR MySQL connection settings into ignored `config.local.php`, without printing passwords. That file was already generated during implementation. Alternatively, copy `config.example.php` to `config.local.php` and enter the settings yourself. `doctor.php` checks the connection and required HR table columns without listing employee records. Don't share the local configuration or passwords.

2. Prepare the inventory tables:

   ```bat
   php tools\setup.php
   ```

   Start with an empty inventory and add devices through the website, or optionally seed the cleaned test workbook:

   ```bat
   php tools\clean-departments.php
   php tools\import.php --location-apo
   php tools\import.php --location-apo --apply
   ```

   `setup.php` creates the two `inv_` tables and adds the location branch ID column to older inventory tables. `--location-apo` reads APO's actual ID from HR, sets it on new imports, and fills blank locations on previously imported devices when applied. Without `--apply` it only previews. A rerun preserves website edits, assignments, and existing locations. Plain `php tools\import.php` still previews the workbook without connecting to MySQL. Do not reorder workbook rows and rerun this importer as an update mechanism: source keys identify the original sheet, row, and component. Previously imported identifiers and source snapshots are not replaced.

   Import with `--apply` validates every nonblank department against the live HR department directory and stores its numeric ID and canonical name. Unresolved labels stop the import before writes.

3. Launch the app using Laragon's PHP:

   ```bat
   php -S 127.0.0.1:8081 -t public
   ```

   Visit **http://127.0.0.1:8081/**. Keep this terminal open; Ctrl+C stops the development server.

For Laragon Apache hosting, copy `deploy/laragon.inventory.conf` to `C:\laragon\etc\apache2\sites-enabled\inventory.conf`, add `127.0.0.1 inventory-tracking.system` to `C:\Windows\System32\drivers\etc\hosts` using an administrator editor, and reload Apache through Laragon. Then open **http://inventory-tracking.system/**. The document root must be `public/` so the workbook and database configuration are not downloadable.

For **https://inventory-tracking.system/**, also copy `deploy/laragon.inventory-https.conf` to `C:\laragon\etc\apache2\sites-enabled\inventory-https.conf` and reload Apache through Laragon. This adds a separate port 443 virtual host using Laragon's existing local certificate and serves only `public/`. Clients must trust the certificate and it must cover this hostname. The port 8081 LAN configuration alone does not handle HTTPS; without the port 443 virtual host, this hostname falls through to Laragon's default site and can show the parent directory listing.

If `php` is missing from your terminal's PATH, use `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` in place of `php`.

## What the first slice does

- Browse and search all devices; filter by type, holder branch, department, and assignment status. The People view can filter employees by branch and department.
- Department choices come only from HR's complete `departments` table, including departments without employees. Shared assignments select an HR department ID; free-text department creation is unavailable.
- Browse HR names, employee numbers, primary branch, department, job title, and HR access role; view linked equipment and sort employees by whether they have devices.
- Preserve recorded workbook holder names as assigned snapshots; link a holder to an HR employee individually through Assign / return when needed.
- Add/edit equipment, condition, branch location, identifiers, and specifications. Location stores the HR branch ID and displays its name; new website devices default to APO when that branch exists.
- Assign equipment to an HR employee or shared department; return it to the available pool.
- Record notes and show per-device history plus all recorded activity entries, displayed in batches of 50 as you scroll.

Assignment records use the HR employee table's numeric `id`. Names and organizational details are read live for display; event snapshots preserve past holders. If HR deletes an employee, the last recorded holder remains visible until equipment is returned or reassigned. Inventory does not have a foreign key that cascades from HR.

CLI-seeded workbook holder names are accepted as current assignments and displayed with **Assigned** status without inferring employee IDs. Website import suggests only unique names matching HR after case/whitespace normalization, or a valid existing numeric link. Review the suggested selection before approval. Blank HR selections retain the recorded holder name and assigned status. The handover date may stay blank when unknown. Each PC component is an individual item. New original-format imports use Unknown condition; existing condition/location/handover dates are preserved. CLI `--location-apo` assigns APO independently of the holder's HR branch.

The cleaned workbook produces **216 devices**: 9 printers, 39 desktops, 39 keyboards, 39 mice, 45 monitors, and 45 laptops. Keyboard and mouse serial numbers and asset tags come from separate columns; leading `SN:` is removed from accessory serial numbers. Monitor fields, desktop OS, and MAC address use their shifted columns. Expected PC headers are checked before any import. Eight items have neither identifier. Repeated accessory serials and one repeated asset tag are retained and reported. The internal device ID is the primary key; serials and asset tags are searchable fields. Original row cells are preserved in source JSON; desktop/laptop specifications include all software columns on website import and new CLI seeds. Rows with no laptop hardware do not create a device.

## Excel import and export

PC fields are located by normalized header names. Both separate accessory serial/tag columns and combined `KEYBOARD SERIAL NO#/ASSET TAG` / `MOUSE SERIAL NO#/ASSET TAG` columns are supported. Shifting columns does not change sheet/row/component source keys. The current workbook previews 228 devices: 9 printers, 41 desktops, 40 keyboards, 40 mice, 46 monitors, and 52 laptops. The older 216-device counts elsewhere describe the original baseline.

1. Select **Import Excel** and upload `INVENTORY 2026.xlsx` or a previously exported workbook. Maximum upload is 5 MiB, subject to PHP's `upload_max_filesize` and `post_max_size`; raise those in Laragon's PHP configuration if needed. Expanded ZIP contents are limited to 32 MiB and imports to 2,000 devices.
2. Preview groups of devices per HR employee or recorded holder. Change or clear the HR selector; groups selecting the same employee merge for one approval. Conflicting existing links require an explicit review. Shared printers group by department. Select the groups to import, then choose **Approve selected groups**. No changes occur before approval.
3. Original-format imports update matching sheet/row/component source keys. Keep original row order when updating; reordering changes device identity. Serial numbers and asset tags are never used to merge devices. Other equipment assigned to the employee remains unchanged.
4. Approved rows replace device names/identifiers/specifications and update holder links. Blank specification cells clear values; specifications absent from the incoming row are removed. Original-format updates preserve condition, location, and handover date. Original source records stay intact; history captures before/after values and the incoming row. Selected groups save atomically; stale previews require a new upload. A preview lasts 30 minutes and is released after approval, including unselected groups.
5. Select **Export Excel** to download all current devices as one row per device. Inventory IDs/source keys are identity metadata; retain them for updates. To add a new device using an exported row, clear **Inventory ID** and **Source key**. The remaining columns contain assignments, condition, location, handover date, and all current specifications. Strings retain leading zeros and formula-like text as literal values.

Specifications include OS, LICENSE, MICROSOFT 365, ANTI VIRUS, SAP, Adobe fields, VIBER, and every additional workbook column. PC software belongs to the desktop; peripherals retain the complete original row in source JSON. Duplicate headers use column suffixes (`OR NUMBER (Z)`); populated unnamed columns use `Column Q`. Specifications support 128 fields, labels up to 100 characters, and values up to 4,000 characters. The editor uses individual name/value fields and preserves multiline values. Existing devices acquire previously missing software specifications when their website import groups are approved; no automatic database backfill occurs.

The CLI importer remains append-only and skips existing keys; new CLI seeds include software columns. HR data remains read-only. No schema migration is required. Apache needs write access to `storage/` for temporary exports, which are removed after download.

Excel browser smoke checks (run with MySQL/Laragon available):

1. Upload the current workbook and confirm 228 devices with separate PC components; inspect combined accessory identifiers, shifted monitor fields, software columns beyond MAC address, and distinct duplicate headers. The historical baseline workbook produces 216 devices.
2. Confirm unique name suggestions, blank unmatched/ambiguous selections, editable links, conflict resolution, and merged employee groups. Approve one group and verify only its devices change and People counts update.
3. Reimport a group with changed OS/LICENSE/MICROSOFT 365/SAP/Adobe/VIBER and blank cells. Verify overwritten/removed specifications, retained device IDs/source snapshots, preserved condition/location/date, and new import-update history.
4. Edit a device after preview, then approve its group: expect a conflict and no partial writes. Cancel/close, invalid uploads, keyboard navigation, Escape, and narrow/mobile layouts must work.
5. Export, inspect leading-zero identifiers and all specification columns, reimport the export, and confirm no duplicate devices. Check current HR names and retained deleted-employee snapshots. Confirm export/import require login and writes require CSRF.

Implementation verification: PHP lint and 1,344 isolated SQLite assertions passed, including both PC layouts and all current workbook software columns; JavaScript syntax and 49 frontend assertions passed in the V8 fallback. Fixed count/history regressions use the archived `INVENTORY 2026.xlsx.before-department-cleanup.2.xlsx` baseline so editing the working workbook does not invalidate them. Required native Node commands abort with `ncrypto::CSPRNG` initialization failure, MySQL tests/doctor fail with PDO 2002, and the PHP server cannot bind localhost. Actual Excel/browser/LAN smoke checks remain unverified in this environment.

## Checks

Device type smoke checks: open **Add device**, choose **Create device type...** from the Device type dropdown, enter a name and save it; confirm it appears in preset settings, Add device, and the type filter, with Other always last. Create a device of that type, edit it, and export/reimport it. Blank, duplicate (including different case), names over 30 characters, and stale saves must fail without changing Desktop defaults or inventory records. New types start with empty presets; each type uses its saved preset when creating a device. Existing devices of every type must keep their saved specs when opened for editing. Verify cancel, Escape, keyboard/mobile access, and read-only controls.

Device preset smoke checks: open **Add device**, select **Desktop**, then **Manage preset**, and verify the supplied 30 fields, drag a field and use up/down buttons, add a custom specification name, confirm there is no Value editor, remove a field, save and reload. In Add device, choose Yes/No or Other for custom text, and check that Other reveals and focuses the text field. Select another type, save its own fields, and verify its creation defaults without changing Desktop. Check that switching types retains unsaved drafts. Keep a device name, serial, specification value, and selected photo in the draft while saving a preset and creating a type; verify these remain unchanged. Check Manage preset is right-aligned beside the Device type label. Choose Create device type from either dropdown, cancel to preserve the prior type, then create a type and confirm it is selected with Other remaining last. Close or press Escape and confirm only settings closes and focus returns to its opener. Reopen Add device, choose Desktop, and verify field order/defaults; switch device types and back to retain the form draft. Save a new device and confirm values in its details. Existing device edits must retain their original specs. Try duplicate/blank names and a stale save from another tab. Verify keyboard, touch arrow buttons, narrow layouts and read-only access. Presets are stored outside public/ in `storage/desktop-preset.json`; include it in backups. No setup/migration or existing-data update is required.

Device creation and editing support optional evidence photos: choose up to five JPEG, PNG or WebP files, at most 2 MiB each. History shows clickable thumbnails with the corresponding saved change. PHP `post_max_size` must accommodate the combined upload plus device details, `upload_max_filesize` must allow 2 MiB, and `max_file_uploads` must allow five files. Apache/PHP needs write access to `storage/evidence/`, created automatically. Include that directory in inventory backups; photos are not included in Excel exports.

Evidence smoke checks: create a device with multiple photos, edit it with another photo, reload and open each history thumbnail. Save without photos and confirm earlier evidence remains. Try an invalid file, oversized photo, six photos, and a stale edit; confirm errors leave device/history unchanged. Check cancel, keyboard access, mobile layout, and that signed-out photo URLs return 401.

Clipboard smoke check: in Add/Edit device, copy a screenshot or image and press Ctrl+V while the form is open. Confirm it appears in the pending photo list alongside browsed files; remove one and save the others. Verify compression, evidence history, the photo viewer and first-upload portrait work for pasted images too. Check five-photo/size/type limits, normal text pasting, failed-save retry, cancel/reopen clearing pending images, and that pasting outside Add/Edit never attaches a photo. Nothing uploads until Save. Run `node tests/photo-paste.js` with frontend checks.

Saved photo removal smoke check: each current Evidence photo has its own Remove button. Remove one of several photos and verify only that image disappears from the current gallery. History must retain its original thumbnail/viewer link and mark it removed; Activity logs its filename and the acting user. Removing the portrait advances to the first remaining uploaded photo; removing the final one restores the symbol. Verify reload, a second tab's stale removal, duplicate removal, cross-device rejection and signed-out access. Files and original upload snapshots are retained; these are archival removals, not file deletion.

Photo preparation uses temporary browser `blob:` image URLs, allowed by the image-only CSP directive. After updating, reload the page before selecting photos so the new policy takes effect. Smoke-check a phone JPEG above 2 MiB: it should resize before upload and appear in the saved event's evidence thumbnails. Actual browser upload remains unverified in the agent environment.

Photo viewer smoke check: click a thumbnail in Evidence photos or History, click the image to zoom in, drag to pan, and click again to fit. Drag release must not toggle zoom. Check touch dragging and keyboard Enter/Space to toggle (0 resets to fit). Escape closes the viewer while keeping the device drawer open; closing returns focus to the thumbnail. Check load errors and narrow/mobile layout. Specifications scroll with the drawer content rather than in a separate pane. Run `node --check public/assets/photo-viewer.js` and `node tests/photo-viewer.js` with the frontend checks.

Device portraits use the first photo of the earliest evidence upload, preserving it after later uploads. State sends only the event ID; photos load when rows approach the visible area, including after filtering. Authenticated evidence requests with `thumbnail=1` serve cached 96×96 JPEGs at quality 72, generated by PHP GD outside public/. These derivatives can be regenerated and never replace originals; PHP GD is required for portraits (installed in Laragon). Browser caching revalidates through authentication before returning 304. Smoke-check scrolling/filtering with the browser Network panel: only visible/nearby portraits should download, each as a small JPEG; reopen/refresh to check cache revalidation. Upload another photo and confirm the first stays as the portrait; check missing-photo/failed-image symbols, drawer portraits, and signed-out thumbnail requests returning 401.

The workbook's 90 department cells were standardized using the HR names supplied by the user. A subsequent live HR preview confirmed the exact spelling **Credit and Collections**, and eight cells were corrected to match. Beverage and Sales-Beverage remain separate; Accounting and Credit and Collections remain separate. SALES MEAT maps to Sales-Frozen. The first backup is `INVENTORY 2026.xlsx.before-department-cleanup.xlsx`; later changes preserve existing backups and create numbered backups such as `.before-department-cleanup.2.xlsx`. All other cell values, row numbers, and device identifiers were verified unchanged.

To reconcile existing inventory department labels and IDs against live HR, run:

```bat
php tools\setup.php
php tools\clean-departments.php --apply --reconcile-inventory
```

The cleanup tool defaults to reading HR through `HrDirectory`. Explicit aliases in `data/department-aliases.json` handle abbreviations; capitalization, spacing, and punctuation variations are matched only when the HR target is unique. Unknown or ambiguous labels stop cleanup. The inventory reconciliation preserves device IDs, assignments, source snapshots, and prior events, and records changes atomically. The initial Excel cleanup used `--hr-list=data/hr-departments.reference.json` because MySQL was unreachable; that file records the user-supplied HR names for offline cleanup and is never used as the website's directory. Future live HR names and IDs take precedence.

To apply the requested condition and handover baseline to all existing equipment (including website-created devices), run after setup/import:

```bat
php tools\setup.php
php tools\set-equipment-baseline.php --date=2026-10-02
php tools\set-equipment-baseline.php --date=2026-10-02 --apply
```

This sets condition to Good and the current handover date to October 2, 2026, preserving holders, locations, identifiers, and source records. Each change gets a new history snapshot; old import/history dates remain intact. Repeating the same baseline skips matching devices. Without `--date`, the tool uses today's date in Asia/Manila. This is an explicit bulk update to current devices, not a recurring rule for future equipment. The date appears in the device detail panel. Future assignments save their entered handover date; returns clear the current handover date and retain the return event's date in history.

Run these before changing the application:

```bat
php tools\check.php
php tests\run.php
node --check public\assets\app.js
node --check public\assets\model.js
node --check public\assets\import.js
node tests\frontend.js
```

The default PHP tests use an isolated in-memory SQLite fixture; SQLite is not the app database. They cover real workbook parsing, duplicates/missing identifiers, idempotent import, selective HR reads, assignment/return history, validation, stale writes, transaction rollback, and local-access/CSRF rules.

Once MySQL access works, verify the real MySQL schema and SQL behavior:

```bat
php tests\run.php --mysql
```

This creates a fresh `inventory_tracking_test_*` database with synthetic employees, runs the same tests, and drops only that database. The configured account needs CREATE/DROP DATABASE privileges for this test. Tests do not modify the real HR or inventory tables. A connection/permission failure is a failed check, not a passing test.

Browser smoke checks after import:

Specification search: enter `RAM: 16GB`, `OS: Windows 11`, or `MICROSOFT 365: Premium` in a device view. Use the full specification name; values may be partial. Case and repeated whitespace are ignored. `RAM:` finds devices with a recorded RAM value. Existing type/branch/department/status filters still apply, and ordinary device/serial/holder searches remain available. Raw identifiers containing colons keep ordinary search unless their prefix is an actual specification name. Check no matches, missing/blank specs, multiline values and clearing the query. People and Activity retain their own search behavior.

Activity scroll smoke check: open Activity with more than 100 changes and verify 50 initially, then the next 50 near the bottom. Check the final partial batch, no duplicates, and that scrolling keeps its position. Search for an older change outside the initial batch; clearing search, refresh, and navigation should reset to 50. Verify Load more changes with the keyboard and narrow/mobile scrolling.

Activity attribution smoke check: sign in with two different admin accounts and create/edit/assign/return a test device, add a note, and approve an Excel group. Confirm each device History entry shows Recorded by (name and username), and Activity's Changed by column records each initiating account and search finds their name or username. Old events show Not recorded; new CLI changes show System / CLI. Rename an attendance account and confirm earlier activity retains its saved name. Verify the page begins at its heading, with Refresh alongside page actions and no Workspace breadcrumb bar.

Device drawer smoke check: Devices starts with the full table. Click a row or use its device-name button with the keyboard to open the compact list beside the expanded details drawer. Switch devices and confirm identifiers, assignment, specifications, photos, and history belong to the current device. Open an evidence thumbnail, edit or assign/return the selected device, and add a note. Close with × or Escape and confirm the full table returns with focus on the selected device's name. Escape must close an open edit/import dialog before the drawer. On mobile, details precede the list. Check long names, multiline specifications, missing identifiers, empty photos/history, and history request errors. Switching workspace views closes the drawer.

Drawer verification: PHP lint and 1,368 isolated SQLite assertions passed; JavaScript syntax and 77 frontend assertions passed using the V8 fallback, including opening, closing, Escape, and empty evidence display. Required native Node checks cannot start because of `ncrypto::CSPRNG` initialization failure. MySQL tests fail with PDO 2002; actual browser smoke checks remain unverified in the agent environment.

1. Confirm 216 devices and nine shared printers; search a workbook serial and filter a department.
2. Select a device; confirm its identifiers/specifications and an import history entry with unknown handover date. For the PC row 2 setup, check separate keyboard/mouse serials and tags, two VIEW PLUS monitors, Windows 10, and MAC address `2C-F0-5D-75-5B-24`.
3. Confirm workbook holder names display as Assigned and appear in the Assigned filter. Check APO is shown as Location and selected in Edit device. Change the location to another HR branch, reload, and confirm it persists. Assign and return a device through the website; confirm employee equipment counts update after a full page reload. A recorded workbook holder can be linked individually through **Assign / return**.
4. Add a test device, edit it, assign it, return it, and add a note. Confirm return makes it available and history keeps the previous holder.
5. Open the same device in two tabs, edit in one, and save stale data in the other. Confirm the stale save is rejected; refresh before retrying.
6. Enter literal `<script>` text in a device name or note; confirm it displays as text.
7. Temporarily set the inventory config's port to an unused port, refresh, and confirm an actionable error appears without credentials; restore the port and refresh.
8. Check narrow/mobile layout, keyboard selection, modal validation, and Escape to close.
9. After applying the baseline, confirm all devices are Good and show handover date `2026-10-02`. Check new baseline history entries and preserved original import entries. Assign and return a test device, confirming its current handover date updates and clears while dated history remains.
10. Confirm the department dropdown lists HR names once each, including departments without employees. Filter Accounting, Credit and collections, Beverage, and Sales-Beverage separately. Assign a shared device using the department selector and confirm its canonical name persists after reload. After department reconciliation, confirm the previous source snapshot and history remain intact.

Backup correction checks: 111 PHP assertions and 30 frontend assertions passed (frontend via the V8 fallback). These include applying a second HR capitalization correction while retaining both the original and immediately preceding workbook backups. The eight-cell spelling correction was applied to Excel successfully. Live inventory reconciliation and MySQL tests still fail in the agent environment with PDO error 2002.

Current checks passed: import preview confirms 216 devices, all PHP syntax checks pass, and 107 PHP assertions pass, including workbook mapping, preservation of website edits/assignments, APO location handling, condition/handover baseline, HR department resolution, Excel cleanup, and atomic department reconciliation. JavaScript syntax and 30 frontend assertions passed in V8 with minimal Node shims because Node failed its cryptographic initialization before running the required commands. Frontend coverage includes application startup against IDs from the real page, the full HR department dropdown without employee-derived labels, workbook assignment rendering, APO and handover date display, and detail history using a small DOM fixture. Asset URLs include content hashes so updated pages load the matching JavaScript and CSS after changes. Database connection, setup, APO import, baseline update, and department reconciliation attempts failed with PDO error 2002, and the PHP development server could not listen on localhost. Excel cleanup completed; inventory database updates, MySQL tests, and actual browser smoke checks have **not** been completed. Run the commands above in Laragon Terminal to complete those checks.

## LAN hosting and inventory accounts

Inventory uses the existing **`attendance_monitoring.users`** accounts on this PC and over the LAN. Sign in through the website with your attendance dashboard **username or email and password**. Only users with `is_admin` or `is_it_admin` enabled can access inventory; both can browse and change inventory. Other attendance users are denied. Create accounts, reset passwords, and change admin flags in the attendance dashboard. No separate inventory account or terminal password setup is needed.

`HrDirectory` reads the users table only for authentication; its password hashes are never included in directory/API responses or stored in inventory account files. Inventory does not change attendance accounts or passwords. Give the configured database account SELECT access to `users` in addition to the four HR directory tables, and CRUD access to the two inventory tables. Give Apache read/write access to `storage/` for login throttling, and restrict filesystem access to host administrators and the Apache account. The employee directory's `employees.role` is display data and does not grant inventory access.

For this Laragon installation, open **PowerShell as Administrator**, then run:

```powershell
cd C:\laragon\www\inventory-tracking-system
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\host-lan.ps1
```

The script checks that the attendance database is reachable and at least one Admin or IT Admin exists. It then enables `local_only => false` in ignored `config.local.php`, backs up and replaces Laragon's `inventory.conf`, and creates a Windows Firewall rule for TCP 8081 on the **Private** network profile, limited to the local subnet. It requires the existing database configuration and never prompts for a login password. If a later step fails, fix the reported issue and rerun. This script is only for Apache/firewall setup; regular login happens in the browser.

Stop any `php -S` development server on port 8081, then **reload Apache through Laragon** and keep MySQL running. Open **http://127.0.0.1:8081/** on the host and sign in. Other devices on the same LAN open **http://HOST-IP:8081/** using the IPv4 address printed by the script (or shown by `ipconfig`). No client hosts-file entry is needed. Reserve the host IP in your router and keep the PC awake. The dedicated Apache port maps requests by IP to this application rather than another Laragon project. The network must be marked Private for the firewall rule to apply.

The provided [LAN Apache configuration](deploy/laragon.inventory-lan.conf) serves only `public/` and permits loopback and private IPv4 ranges. Tighten its `Require ip` to your actual subnet if needed. HTTP login traffic is unencrypted; use this configuration on your trusted LAN. For networks needing transport protection, configure Apache HTTPS with a certificate trusted by the client devices; HTTPS automatically enables Secure session cookies. Do not forward this port from the internet.

Resetting an attendance user's password, deleting that user, or removing both Admin/IT Admin flags revokes its inventory session on the next request. Sign out is a CSRF-protected POST. Sessions expire after 30 minutes idle or eight hours total. Ten failed attempts per attendance user ID or client IP block sign-in for the remainder of a 15-minute window, shared across browsers/workers in ignored `storage/login-attempts.local.json`. Username and email attempts for the same account share the user-ID limit. Unknown/ambiguous credentials and non-admin users receive the same sign-in failure message. Inventory retains its own PHP session; an attendance login does not automatically sign you into inventory.

To retain local-only hosting, leave `local_only` true and use the existing local Apache configuration; browser login still uses attendance users. To enable LAN manually, run `php tools/enable-lan.php`, install the LAN Apache configuration as `C:\laragon\etc\apache2\sites-enabled\inventory.conf`, and configure the matching restricted firewall rule. Do not install both Apache configurations for inventory or duplicate `Listen 8081`. Older `storage/accounts.local.json` files, if present, are no longer used; separate inventory accounts cannot sign in.

LAN/authentication smoke checks:

1. Signed-out page access redirects to login; signed-out `api.php?action=state` and `history` return 401 without HR/device data.
2. An attendance Admin or IT Admin can sign in by username and by email and complete the add/edit/assign/return/note smoke checks above; signing out prevents further API access. Old session IDs and CSRF tokens must stop working after login/logout.
3. An attendance user with neither Admin nor IT Admin enabled cannot sign in, even with the correct password. Employee HR roles do not bypass this restriction. Password hashes must never appear in People/state/history responses.
4. Wrong credentials display the same error for unknown users and wrong passwords. Verify repeated failures block sign-in across a fresh browser session and the block expires after 15 minutes.
5. In the attendance dashboard, reset/remove a test account or remove both admin flags and confirm its existing inventory session loses access. Create the test account in attendance, not through inventory.
6. On a second LAN device, sign in at `http://HOST-IP:8081/`; check logout and narrow/mobile layout. Confirm the project config/workbook cannot be downloaded through this public document root.

Authentication implementation checks: 153 isolated SQLite assertions passed, including username/email login, Admin/IT Admin access, non-admin rejection, shared throttling, password-change/session revocation, and read-only attendance authentication. JavaScript syntax and 27 frontend assertions previously passed in the V8 fallback. CLI endpoint probes confirmed anonymous state/history/write requests return 401, the page redirects to login with 302, and the login page renders. Required Node commands could not run because Node's random-number initialization failed; MySQL tests failed with PDO 2002. PowerShell could not initialize, Apache configuration validation failed before parsing because its random-number generator could not initialize, and PHP could not listen on localhost. The hosting script, Apache configuration, actual browser checks, and access from a second LAN device therefore still need verification in Laragon on the host. LAN activation was not performed in the agent environment; the setup script activates hosting using your existing attendance users.

## Next slice

Local-only access remains the default until explicit LAN activation. Inventory login and authorization protect both page and API access in either mode using attendance Admin/IT Admin accounts. Later slices can add formal issue resolution and workstation group transfers. The architecture is described in `ARCHITECTURE.md`.


Disposal & component recovery: run `php tools/setup.php` after updating to add inventory-only `lifecycle_status` (existing rows default to active). In Assign / return, select For disposal, choose populated specification rows representing reusable physical hardware, and confirm the disposal date. Each selection creates an unassigned Other device; selecting none is supported. The original remains permanently For disposal with its identity, specifications, source and evidence preserved. Assignment, detail edits and import updates are blocked; notes and evidence remain accessible. Excel exports include Lifecycle status; older exports remain readable and imports cannot change lifecycle.

Disposal smoke checks: cancel and Back without saving; confirm unchecked initial rows, today's local date, oversized-name explanations, zero/multiple recovery, exact names and retained selections after failure. Verify Escape, keyboard focus and narrow/mobile layout. After saving, reload, inspect reciprocal history, assign a recovered device, confirm the original cannot be edited/reassigned/disposed again, and check Total/Assigned/Available/attention, status filtering and the previous holder's People count. Attempt approval of an import batch containing a disposed target and verify the whole batch rolls back. Export/reimport active devices and test an older export without Lifecycle status. Browser smoke checks require a running authenticated app and MySQL.

## Bulk photo import

Choose **Import photos**, select the photo scanner's results.json and either individual originals or the original scanner input folder. Folder selection preserves subfolder paths; individual selection matches exact filenames only. Up to 2,000 scanner records and a 20 MiB results file are accepted. Failed/pending scanner records must be scanned again before import.

Preview shows local photos, extracted names, ranked HR suggestions, and target devices. Percentages measure spelling similarity, not OCR certainty or identity probability. The unique strongest name match at 60% or higher preselects an employee; tied scores remain unresolved. Maria/Ma. variants and typos use the same 60% threshold. Duplicate HR names remain unresolved. Employee choices do not write to HR or change assignments. A single current laptop is selected automatically even if the employee also has a desktop. Multiple photos may use the same laptop; multiple laptops require a choice. Eligible rows are checked automatically. **Show all active devices** supports manual matching, including associates or unlinked holders. Review each image and target before selecting it. **Select ready rows** selects eligible rows with a device, including named review rows. Entries without an employee or target device are skipped without blocking ready uploads; skipped entries remain retryable.

Approval verifies original file hashes against the scanner results, prepares photos using existing browser compression, then uploads sequentially (one file per request, at most 2 MiB prepared). No AI/API calls occur. Each upload appends an actor-attributed photo-import event and increments the device version, without changing details, holders, existing photos, or original source records. Existing galleries/thumbnails show these photos normally. No migration or new dependencies are required.

Successful photos remain saved if a later photo fails. Retry failed selections or choose **Stop after current photo**. Closing/reloading requires previewing again; repeating an import skips the same original hash or prepared-image hash on the same device, including photos later removed from the gallery. The same photo may be explicitly attached to another device. Previews expire after 30 minutes; stale device changes return a conflict requiring a fresh preview. Starting a new preview replaces the previous one in that browser session. File uploads use the existing authenticated, CSRF-protected private evidence storage. Back it up with inventory tables.

Checks: run `node tests/photo-import.js` alongside the normal checks. Browser smoke: preview a nested folder with duplicate basenames; try a missing/changed photo, duplicate HR names, a typo and Maria/Ma. variant; inspect manual device selection and Needs review handling. Upload multiple photos to the same device, repeat the import, stop/retry, and check history/gallery and unchanged assignments. Try an oversized/corrupt photo, expired preview, and a concurrent device edit. Confirm keyboard/Escape, mobile layout, and existing Excel import still work.
