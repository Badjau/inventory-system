# Inventory tracking

The first working slice follows the layout in the original `index.html`: device table, counts, filters, and a detail panel. Run the application from **`public/`**. The original mockup stays at the project root as a design reference.

The website is the main inventory workspace. Register new equipment, edit identifiers/specifications, assign or return devices, and record notes there. Excel is an optional initial source of test data; the running application does not read it or synchronize website changes back to it.

The app uses PHP 8.3, PDO, MySQL, and plain HTML/CSS/JavaScript. Employee details come from **`attendance_monitoring`**, the local timekeeping database confirmed during setup. `mcasiafoodtrade_db1` was superseded by that choice. Inventory owns only `inv_devices` and `inv_events`; employee tables receive only SELECT queries.

## Start from Laragon Terminal

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
- Record notes and show per-device history plus the latest 100 activity entries.

Assignment records use the HR employee table's numeric `id`. Names and organizational details are read live for display; event snapshots preserve past holders. If HR deletes an employee, the last recorded holder remains visible until equipment is returned or reassigned. Inventory does not have a foreign key that cascades from HR.

Workbook holder names are accepted as current assignments and displayed with **Assigned** status. No employee ID is inferred from a recorded name. If a holder needs to be linked later, use **Assign / return** to select the correct HR employee. Until then, the recorded holder name remains visible and the equipment stays assigned. The handover date may stay blank when unknown. Each PC component is an individual item. Conditions are imported as Unknown, and `--location-apo` assigns the APO branch location independently of the holder's HR branch.

The cleaned workbook produces **216 devices**: 9 printers, 39 desktops, 39 keyboards, 39 mice, 45 monitors, and 45 laptops. Keyboard and mouse serial numbers and asset tags come from separate columns; leading `SN:` is removed from accessory serial numbers. Monitor fields, desktop OS, and MAC address use their shifted columns. Expected PC headers are checked before any import. Eight items have neither identifier. Repeated accessory serials and one repeated asset tag are retained and reported. The internal device ID is the primary key; serials and asset tags are searchable fields. Original row cells, including software details, are preserved in the source JSON, although the first UI displays only hardware specifications. Rows with no laptop hardware do not create a device.

## Checks

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
node tests\frontend.js
```

The default PHP tests use an isolated in-memory SQLite fixture; SQLite is not the app database. They cover real workbook parsing, duplicates/missing identifiers, idempotent import, selective HR reads, assignment/return history, validation, stale writes, transaction rollback, and local-access/CSRF rules.

Once MySQL access works, verify the real MySQL schema and SQL behavior:

```bat
php tests\run.php --mysql
```

This creates a fresh `inventory_tracking_test_*` database with synthetic employees, runs the same tests, and drops only that database. The configured account needs CREATE/DROP DATABASE privileges for this test. Tests do not modify the real HR or inventory tables. A connection/permission failure is a failed check, not a passing test.

Browser smoke checks after import:

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

Local-only access remains the default until explicit LAN activation. Inventory login and authorization protect both page and API access in either mode using attendance Admin/IT Admin accounts. Later slices can add formal issue resolution, export, and workstation group transfers. The architecture is described in `ARCHITECTURE.md`.
#   i n v e n t o r y - s y s t e m  
 