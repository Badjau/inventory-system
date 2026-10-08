# Inventory implementation guidance

- Stack: PHP 8.3 + PDO MySQL, vanilla HTML/CSS/JavaScript. Runtime has no Composer/npm/Python dependencies.
- Serve `public/`. The root `index.html` is the design reference, not the application entrypoint.
- Read `README.md` for setup and checks; read `ARCHITECTURE.md` for ownership and endpoint contracts.
- All HR access goes through `app/HrDirectory.php`, with SELECT only. Never migrate or write HR tables.
- Inventory owns `inv_devices` and `inv_events` in `attendance_monitoring`. Keep changes and history atomic.
- Preserve internal device IDs and workbook source records. Serial numbers/asset tags can be missing or repeated.
- Employee links use numeric HR IDs. Do not fuzzy-match imported holder names. Keep old holder snapshots after return or HR deletion.
- The initial app is local only. Do not disable the local-access gate without implementing the agreed authentication slice.
- Keep credentials in ignored `config.local.php`; never print passwords or copy the file into `public/`.
- Follow existing small classes/helpers. Add abstractions or dependencies only for a concrete need.
- For application changes run `php tools/check.php`, `php tests/run.php`, JavaScript syntax checks, and `node tests/frontend.js`.
- Run `php tests/run.php --mysql` when MySQL is reachable. It uses its own temporary database, never live HR fixtures.
- Perform the affected browser smoke checks listed in README. Report unavailable commands/checks accurately.
- Do not spawn sub-agents unless explicitly requested by the user.
