/* Prepare evidence locally so original photo sizes do not hit PHP upload limits. */
(() => {
  'use strict';
  const MiB = 1024 * 1024;
  function validate(files) {
    if (files.length > 5) throw new Error('Choose up to 5 photos.');
    for (const file of files) {
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) throw new Error('Choose JPEG, PNG or WebP photos.');
      if (!file.size || file.size > 20 * MiB) throw new Error('Choose photos up to 20 MiB each.');
    }
  }
  function attach(dialog, input, selection, error, busy = () => false) {
    let queued = [];
    function render() {
      selection.replaceChildren();
      for (const [index, file] of queued.entries()) {
        const row = document.createElement('div');
        row.className = 'queued-photo';
        const name = document.createElement('span');
        name.textContent = file.name || `Pasted image ${index + 1}`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'text-button';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', `Remove ${name.textContent}`);
        remove.addEventListener('click', () => {
          if (busy()) return;
          queued.splice(index, 1);
          error.hidden = true;
          render();
        });
        row.append(name, remove);
        selection.append(row);
      }
    }
    function add(files) {
      if (busy() || !files.length) return;
      try {
        validate([...queued, ...files]);
        queued.push(...files);
        error.hidden = true;
        render();
      } catch (problem) { error.textContent = problem.message; error.hidden = false; }
    }
    const change = () => { add(Array.from(input.files)); input.value = ''; };
    const paste = event => {
      if (!dialog.open) return;
      const files = Array.from(event.clipboardData?.items || [])
        .filter(item => item.kind === 'file' && item.type.startsWith('image/'))
        .map(item => item.getAsFile()).filter(Boolean);
      if (!files.length) return;
      event.preventDefault();
      add(files);
    };
    input.addEventListener('change', change);
    dialog.addEventListener('paste', paste);
    dialog.addEventListener('close', () => {
      dialog.removeEventListener('paste', paste);
      input.removeEventListener('change', change);
      queued = [];
    }, { once: true });
    return () => queued.slice();
  }
  async function prepare(files, progress = () => {}) {
    validate(files);
    const result = [];
    for (const [index, file] of files.entries()) {
      progress(`Preparing photos… ${index + 1} of ${files.length}`);
      const url = URL.createObjectURL(file);
      const image = new Image();
      let canvas;
      try {
        await new Promise((resolve, reject) => {
          image.onload = resolve;
          image.onerror = () => reject(new Error(`Cannot read photo: ${file.name}. Choose another JPEG, PNG or WebP image.`));
          image.src = url;
        });
        const width = image.naturalWidth, height = image.naturalHeight;
        if (!width || !height || width * height > 40000000) throw new Error('Choose photos up to 40 megapixels.');
        if (file.size <= 2 * MiB && Math.max(width, height) <= 2000) {
          result.push(file);
          continue;
        }
        canvas = document.createElement('canvas');
        const scale = Math.min(1, 2000 / Math.max(width, height));
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Photo compression is unavailable in this browser.');
        // JPEG has no transparency; use a white background for PNG/WebP input.
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        let compressed;
        for (const quality of [0.85, 0.75, 0.65, 0.55]) {
          compressed = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
          if (!compressed) throw new Error(`Cannot compress photo: ${file.name}. Try another image.`);
          if (compressed.size <= 2 * MiB) break;
        }
        if (compressed.size > 2 * MiB) throw new Error(`Photo ${file.name} is still too large after compression. Choose a smaller image.`);
        result.push(new File([compressed], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
      } finally {
        URL.revokeObjectURL(url);
        image.src = '';
        if (canvas) { canvas.width = 0; canvas.height = 0; }
      }
    }
    return result;
  }
  globalThis.InventoryPhotos = { prepare, attach };
})();
