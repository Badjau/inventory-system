/* Click to toggle zoom; dragging pans without triggering a zoom click. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const dialog = $('photo-viewer'), image = $('photo-image'), stage = $('photo-stage');
  const status = $('photo-viewer-status');
  let scale = 1, ready = false, zoomed = false, drag = null, suppressClick = false;
  function size(next) {
    scale = next;
    image.style.width = `${Math.round(image.naturalWidth * scale)}px`;
    image.style.height = `${Math.round(image.naturalHeight * scale)}px`;
    stage.classList.toggle('zoomed', zoomed);
  }
  function fit() {
    zoomed = false;
    size(Math.min(1, Math.max(1, stage.clientWidth - 32) / image.naturalWidth,
      Math.max(1, stage.clientHeight - 32) / image.naturalHeight));
    stage.scrollTop = stage.scrollLeft = 0;
  }
  function toggleZoom(event = null) {
    if (!ready) return;
    if (zoomed) { fit(); return; }
    const rect = image.getBoundingClientRect();
    const x = event ? (event.clientX - rect.left) / rect.width : 0.5;
    const y = event ? (event.clientY - rect.top) / rect.height : 0.5;
    zoomed = true;
    size(Math.min(4, Math.max(1, scale * 2)));
    stage.scrollLeft = Math.max(0, x * image.naturalWidth * scale - stage.clientWidth / 2 + 16);
    stage.scrollTop = Math.max(0, y * image.naturalHeight * scale - stage.clientHeight / 2 + 16);
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('.evidence-photos a');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
    event.preventDefault();
    ready = false; drag = null; suppressClick = false; zoomed = false;
    stage.classList.toggle('zoomed', false);
    image.hidden = true;
    status.hidden = false;
    status.textContent = 'Loading photo…';
    const name = link.querySelector('img')?.alt || 'Evidence photo';
    $('photo-viewer-title').textContent = name;
    image.alt = name;
    dialog.showModal();
    image.src = link.href;
  });
  image.addEventListener('load', () => {
    if (!dialog.open) return;
    ready = true; image.hidden = false; status.hidden = true;
    fit();
  });
  image.addEventListener('error', () => {
    if (!dialog.open) return;
    ready = false; image.hidden = true; status.hidden = false;
    status.textContent = 'Photo could not be loaded. Close and try again.';
  });
  image.addEventListener('click', event => {
    if (suppressClick) { suppressClick = false; return; }
    toggleZoom(event);
  });
  stage.addEventListener('pointerdown', event => {
    if (!ready || !zoomed || event.button !== 0 || event.isPrimary === false) return;
    suppressClick = false;
    drag = { id: event.pointerId, x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop };
    image.setPointerCapture(event.pointerId);
    stage.classList.add('dragging');
  });
  stage.addEventListener('pointermove', event => {
    if (!drag || drag.id !== event.pointerId) return;
    const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
    if (Math.hypot(dx, dy) > 5) suppressClick = true;
    if (suppressClick) { stage.scrollLeft = drag.left - dx; stage.scrollTop = drag.top - dy; }
  });
  function endDrag() { drag = null; stage.classList.remove('dragging'); }
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);
  $('close-photo-viewer').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => { ready = false; endDrag(); image.removeAttribute('src'); });
  stage.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleZoom(); }
    if (event.key === '0' && ready) { event.preventDefault(); fit(); }
  });
  window.addEventListener('resize', () => { if (dialog.open && ready && !zoomed) fit(); });
})();
