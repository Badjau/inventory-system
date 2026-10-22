# Inventory Tracking System

A web application for tracking company equipment, who has it, and what has happened to it over time. Manage devices, assignments, photos, and handover history in one place, with Excel import and export for existing inventory records.

Built for teams using the Attendance Monitoring HR database, the app reads employee information from that directory while keeping inventory records and history separate.

## What you can do

- **Manage equipment:** register devices, edit specifications, record condition and location, and organize custom device types.
- **Track assignments:** assign equipment to HR employees, inventory-only associates, or shared departments, and return it to the available pool.
- **Browse people:** see each person's current equipment and previous handovers.
- **Search inventory:** find devices by name, serial number, asset tag, specifications, or current and previous holders.
- **Keep a history:** review changes, notes, handovers, and the user who recorded them.
- **Attach photos:** upload or paste evidence images, or review and import photos from scanner results.
- **Work with Excel:** preview and approve workbook updates, then export current inventory for reporting or later reimport.
- **Record disposal:** mark equipment for disposal and register reusable components as separate devices.

Returning or disposing of equipment preserves its history. Missing or repeated serial numbers and asset tags are supported.

## Requirements

- PHP 8.3 with `pdo_mysql`, `zip`, `simplexml`, and `mbstring`.
- MySQL and an existing Attendance Monitoring database with its employee directory and user accounts.
- A modern browser. Laragon is the documented Windows hosting environment.

The application uses PHP, PDO MySQL, and vanilla HTML/CSS/JavaScript. No Composer, npm, or Python dependencies are needed to run it.

## Get started

1. Clone the repository, start MySQL, and open a terminal in the project folder.
2. Copy `config.example.php` to `config.local.php` and enter your database settings. Keep this ignored file private. Alternatively, configure the connection from your Attendance Monitoring installation:

   ```bat
   php tools\configure.php C:\path\to\attendancemonitoring\.env
   ```

3. Check the connection and prepare the inventory tables:

   ```bat
   php tools\doctor.php
   php tools\setup.php
   ```

   Setup creates or upgrades inventory tables without changing HR tables. Run it after updates that require inventory schema changes.

4. Start the local server:

   ```bat
   php -S 127.0.0.1:8081 -t public
   ```

5. Open [http://127.0.0.1:8081/](http://127.0.0.1:8081/) and sign in with an Attendance Monitoring **Admin** or **IT Admin** account, using its username or email and password.

Serve the **`public/`** directory. The root `index.html` is a design reference. Account creation, password resets, and access permissions are managed in Attendance Monitoring.

## Using the app

Start in **Devices** to add equipment or browse existing records. Open a device to review its details, photos, and history, then use **Assign / return** to record a handover. In **People**, browse employees or use **Add User** to create an inventory-only associate; this does not create an HR employee or a login account.

Use **Import Excel** to upload a supported inventory workbook or an earlier export. Review holder selections and device changes before approving them. Use **Export Excel** to download current inventory. Synchronization is manual, and a workbook is not required to use the app. Keep Inventory ID and Source key columns intact when updating exported records; original-format workbook updates depend on keeping source row order.

Photos are stored separately from Excel exports. Back up the inventory database and `storage/`, including evidence photos and saved device presets.

## Hosting

Access is local only by default. For Laragon Apache, use the supplied [local configuration](deploy/laragon.inventory.conf) with `public/` as the document root. The [HTTPS configuration](deploy/laragon.inventory-https.conf) supports a local certificate trusted by your client devices.

For shared access on a trusted LAN, `tools/host-lan.ps1` checks attendance admin access and configures Apache and a restricted Windows Firewall rule. See the [LAN hosting instructions](docs/IMPLEMENTATION_NOTES.md#lan-hosting-and-inventory-accounts) before enabling it. Browser sign-in is required for both local and LAN access.

## Development

Contributor guidance lives in [AGENTS.md](AGENTS.md). See [ARCHITECTURE.md](ARCHITECTURE.md) for data ownership and endpoint contracts, and the [maintenance reference](docs/IMPLEMENTATION_NOTES.md) for detailed operating procedures, browser smoke checks, and historical implementation notes.
