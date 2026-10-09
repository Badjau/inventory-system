<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$localConfig = dirname(__DIR__) . '/config.local.php';
Inventory\Http::start(is_file($localConfig) ? require $localConfig : ['local_only' => true]);
try {
    $user = Inventory\Auth::current();
} catch (Throwable $error) {
    error_log('Inventory authentication: ' . $error->getMessage());
    http_response_code(503);
    exit('Inventory is unavailable. Ask the administrator to check the attendance database connection.');
}
if (!$user) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="inventory-role" content="<?= $user['role'] ?>">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
  <title>Inventory · MC Asia</title>
  <link rel="stylesheet" href="assets/base.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/base.css'), 0, 12) ?>">
  <link rel="stylesheet" href="assets/app.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/app.css'), 0, 12) ?>">
  <link rel="stylesheet" href="assets/stats.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/stats.css'), 0, 12) ?>">
  <link rel="stylesheet" href="assets/devices.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/devices.css'), 0, 12) ?>">
  <link rel="stylesheet" href="assets/navigation.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/navigation.css'), 0, 12) ?>">
  <link rel="stylesheet" href="assets/readability.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/readability.css'), 0, 12) ?>">
  <script src="assets/model.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/model.js'), 0, 12) ?>" defer></script>
  <script src="assets/photos.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/photos.js'), 0, 12) ?>" defer></script>
  <script src="assets/photo-viewer.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/photo-viewer.js'), 0, 12) ?>" defer></script>
  <script src="assets/app.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/app.js'), 0, 12) ?>" defer></script>
  <script src="assets/import.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/import.js'), 0, 12) ?>" defer></script>
</head>
<body data-role="<?= $user['role'] ?>">
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand"><span class="brand-icon" aria-hidden="true">▦</span><span class="sidebar-text">inventory</span></div>
    <nav aria-label="Workspace" id="navigation">
      <button class="nav-item active" data-view="devices" aria-label="Devices"><span aria-hidden="true">▦</span><span class="sidebar-text">Devices</span><b id="count-devices">0</b></button>
      <button class="nav-item" data-view="people" aria-label="People"><span aria-hidden="true">♙</span><span class="sidebar-text">People</span><b id="count-people">0</b></button>
      <button class="nav-item" data-view="shared" aria-label="Shared devices"><span aria-hidden="true">▤</span><span class="sidebar-text">Shared devices</span><b id="count-shared">0</b></button>
      <button class="nav-item" data-view="attention" aria-label="Needs attention"><span aria-hidden="true">⚑</span><span class="sidebar-text">Needs attention</span><b id="count-attention">0</b></button>
      <button class="nav-item" data-view="activity" aria-label="Activity"><span aria-hidden="true">◷</span><span class="sidebar-text">Activity</span></button>
      <button class="nav-item" data-view="preset" aria-label="Preset"><span aria-hidden="true">≡</span><span class="sidebar-text">Preset</span></button>
    </nav>
    <div class="sidebar-bottom"><div class="profile"><span class="avatar">IT</span><div class="sidebar-text"><?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?><small><?= $user['role'] === 'editor' ? 'Editor' : 'Read-only' ?></small></div></div>
      <form action="login.php" method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>"><button class="text-button sidebar-signout" name="logout" value="1" aria-label="Sign out"><span aria-hidden="true">↪</span><span class="sidebar-text">Sign out</span></button></form>
    </div>
  </aside>
  <main>
    <div class="page-content">
      <div class="page-heading"><div><h1 id="page-title">Devices</h1><p id="page-description">Equipment and the people who hold it.</p></div><div class="action-row"><button class="button" id="refresh">Refresh</button><button class="button" id="import-excel">Import Excel</button><a class="button" href="api.php?action=export">Export Excel</a><button class="button primary" id="add-device" disabled>＋ Add device</button></div></div>
      <div id="notice" role="status" hidden></div>
      <div id="error" role="alert" hidden></div>
      <div id="stats" class="stats"></div>
      <section id="preset-panel" class="inventory-panel" aria-label="Device presets" hidden></section>
      <div id="inventory-layout" class="inventory-layout">
        <section class="inventory-panel" aria-label="Directory">
          <div class="toolbar">
            <label class="search"><span aria-hidden="true">⌕</span><input id="search" aria-label="Search" placeholder="Search device, serial, asset tag, or holder…"></label>
            <select id="type-filter" class="button" aria-label="Device type"><option value="">All types</option></select>
            <select id="people-sort" class="button" aria-label="Sort employees by equipment" hidden><option value="name">Name A–Z</option><option value="zero-first">0 devices first</option><option value="has-first">Has devices first</option></select>
            <select id="department-filter" class="button" aria-label="Department"><option value="">All departments</option></select>
            <select id="branch-filter" class="button" aria-label="Branch"><option value="">All branches</option></select>
            <select id="status-filter" class="button" aria-label="Assignment status"><option value="">All statuses</option><option value="assigned">Assigned</option><option value="available">Available</option></select>
          </div>
          <div id="table-scroll" class="table-scroll"><table id="directory-table"><thead id="table-head"></thead><tbody id="table-body"><tr><td class="empty-state">Loading inventory…</td></tr></tbody></table><button type="button" id="activity-more" class="button" hidden>Load more changes</button></div>
          <div class="table-footer"><span id="table-footer"></span><button id="clear-person" class="text-button" hidden>Clear employee filter</button></div>
        </section>
        <aside id="detail" class="detail-panel" aria-label="Device details" hidden></aside>
      </div>
    </div>
  </main>
</div>
<dialog id="dialog" class="modal">
  <form id="dialog-form">
    <div class="modal-header"><div><h2 id="dialog-title"></h2><p id="dialog-subtitle"></p></div><button type="button" class="close-button" id="close-dialog" aria-label="Close">×</button></div>
    <div class="modal-body"><div id="dialog-fields"></div><p id="form-error" role="alert" hidden></p></div>
    <div class="modal-footer"><span>Changes are saved to inventory.</span><div><button class="button" type="button" id="cancel-dialog">Cancel</button><button class="button primary" type="submit" id="save-dialog">Save</button></div></div>
  </form>
</dialog>
<noscript><p>Enable JavaScript to use inventory.</p></noscript>
<dialog id="photo-viewer" class="photo-viewer" aria-labelledby="photo-viewer-title">
  <header class="photo-viewer-header"><h2 id="photo-viewer-title">Evidence photo</h2><button type="button" class="close-button" id="close-photo-viewer" aria-label="Close photo viewer">×</button></header>
  <small class="photo-viewer-hint" id="photo-viewer-hint">Click to zoom · Click again to fit · Drag to move</small>
  <div id="photo-stage" class="photo-stage" tabindex="0" aria-label="Photo viewer" aria-describedby="photo-viewer-hint"><img id="photo-image" alt="" draggable="false" hidden><p id="photo-viewer-status" role="status">Loading photo…</p></div>
</dialog>
<dialog id="import-dialog" class="modal import-modal" aria-labelledby="import-title">
  <form id="import-form">
    <div class="modal-header"><div><h2 id="import-title">Import Excel</h2><p>Review holders and approve their devices together.</p></div><button type="button" class="close-button" id="close-import" aria-label="Close import">×</button></div>
    <div class="modal-body">
      <label>Excel workbook<input id="import-file" name="workbook" type="file" accept=".xlsx" required></label>
      <p class="source-hint">Up to 5 MiB, subject to the server upload limit. Original workbook rows identify devices: keep their row order when updating. Specifications in approved rows replace current specifications; blank cells clear values. Equipment absent from the upload stays unchanged.</p>
      <button type="submit" class="button" id="preview-import">Preview workbook</button>
      <div id="import-review" hidden><p id="import-summary"></p><div class="action-row"><button type="button" class="text-button" id="select-import">Select all</button><button type="button" class="text-button" id="clear-import">Clear selection</button></div><div id="import-groups"></div></div>
      <p id="import-error" role="alert" hidden></p>
    </div>
    <div class="modal-footer"><span id="import-status">Nothing is saved until approval.</span><div><button type="button" class="button" id="cancel-import">Cancel</button><button type="button" class="button primary" id="approve-import" disabled>Approve selected groups</button></div></div>
  </form>
</dialog>
</body>
</html>
