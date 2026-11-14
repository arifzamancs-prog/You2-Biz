// Passport scan window: choose a photo, frame the passport page, then hand the
// cropped image to the scanner. Everything runs in this browser.
// Usage: window.PassportCropper.open({onScan: file => ...})
(function () {
  'use strict';

  const MAX_SOURCE = 4200;      // longest side kept for cropping (keeps memory sane)
  const MIN_BOX = 48;           // smallest frame, in screen pixels
  const ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,image/heif';

  const icon = {
    close: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    file: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M12 17v-6M9 14l3-3 3 3"/></svg>',
    camera: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>',
    left: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>',
    right: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>',
    expand: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
    swap: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h13l-3-3M20 17H7l3 3"/></svg>',
    scan: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/></svg>',
    page: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><rect x="7" y="6" width="4" height="5" rx="1"/><path d="M13 7h4M13 10h4"/></svg>',
    lines: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 15h16M4 19h16"/><path d="M4 5h9M4 9h6" opacity=".45"/></svg>',
    sun: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
  };

  // Simple drawing of a passport data page: photo, text lines and the two code lines.
  const artwork = '<svg class="pc-art" viewBox="0 0 220 150" aria-hidden="true">'
    + '<rect x="6" y="6" width="208" height="138" rx="12" fill="#fff" stroke="#c9d8ec" stroke-width="2"/>'
    + '<rect x="20" y="22" width="48" height="60" rx="6" fill="#e3edf9"/>'
    + '<circle cx="44" cy="44" r="11" fill="#b9cde8"/><path d="M27 78c3-14 31-14 34 0" fill="#b9cde8"/>'
    + '<rect x="80" y="24" width="70" height="7" rx="3.5" fill="#dbe6f3"/><rect x="80" y="38" width="110" height="6" rx="3" fill="#e8eff8"/>'
    + '<rect x="80" y="50" width="92" height="6" rx="3" fill="#e8eff8"/><rect x="80" y="62" width="104" height="6" rx="3" fill="#e8eff8"/>'
    + '<rect x="80" y="74" width="60" height="6" rx="3" fill="#e8eff8"/>'
    + '<rect x="14" y="100" width="192" height="34" rx="7" fill="#eaf8f5" stroke="#31c6bd" stroke-width="1.5" stroke-dasharray="4 3"/>'
    + '<text x="22" y="114" font-family="monospace" font-size="9.5" fill="#1d6f68" letter-spacing=".6">P&lt;BGDNAME&lt;&lt;GIVEN&lt;NAMES&lt;&lt;&lt;&lt;&lt;&lt;</text>'
    + '<text x="22" y="127" font-family="monospace" font-size="9.5" fill="#1d6f68" letter-spacing=".6">A01234567&lt;8BGD9001012M3&lt;&lt;&lt;</text>'
    + '</svg>';

  let root = null, opts = null, lastFocus = null;
  let original = null;   // HTMLImageElement of the chosen photo
  let rotation = 0;      // 0, 90, 180, 270
  let source = null;     // canvas: rotated photo at working resolution
  let box = null;        // frame in display pixels {x,y,w,h}
  let drag = null;

  const $ = sel => root.querySelector(sel);

  function build() {
    root = document.createElement('div');
    root.className = 'pc-backdrop';
    root.hidden = true;
    root.innerHTML = `
      <div class="pc-dialog" role="dialog" aria-modal="true" aria-labelledby="pc-title" tabindex="-1">
        <header class="pc-head">
          <div>
            <h3 id="pc-title">Scan passport</h3>
            <p>Pick a photo, frame the passport page, then scan.</p>
          </div>
          <button type="button" class="pc-close" data-pc="close" aria-label="Close">${icon.close}</button>
        </header>
        <ol class="pc-steps">
          <li data-step="1"><span>1</span>Choose photo</li>
          <li data-step="2"><span>2</span>Frame passport</li>
          <li data-step="3"><span>3</span>Scan</li>
        </ol>
        <div class="pc-error" role="alert" hidden></div>
        <section class="pc-view pc-pick" data-view="pick">
          <div class="pc-drop" tabindex="0" aria-label="Drop a passport photo here">
            ${artwork}
            <strong>Drop the passport photo here</strong>
            <span class="pc-or">or paste it with Ctrl+V</span>
            <div class="pc-pick-buttons">
              <label class="pc-btn pc-primary" tabindex="0">${icon.file} Choose photo<input type="file" accept="${ACCEPT}" data-pc="file"></label>
              <label class="pc-btn pc-light pc-camera" tabindex="0">${icon.camera} Use camera<input type="file" accept="image/*" capture="environment" data-pc="file"></label>
            </div>
            <small>JPG, PNG or WEBP &middot; up to 15 MB</small>
          </div>
          <ul class="pc-tips">
            <li>${icon.page}<span><b>Photo page</b>The page with your picture, opened flat</span></li>
            <li>${icon.lines}<span><b>Bottom code lines</b>Keep both lines fully in view</span></li>
            <li>${icon.sun}<span><b>Sharp &amp; bright</b>No glare, blur or shadow</span></li>
          </ul>
        </section>
        <section class="pc-view pc-crop" data-view="crop" hidden>
          <div class="pc-stage">
            <canvas class="pc-canvas"></canvas>
            <div class="pc-box" tabindex="0" aria-label="Passport frame. Use arrow keys to move, Shift and arrow keys to resize.">
              <span class="pc-guide"><em>Code lines here</em></span>
              <i data-h="nw"></i><i data-h="n"></i><i data-h="ne"></i><i data-h="e"></i>
              <i data-h="se"></i><i data-h="s"></i><i data-h="sw"></i><i data-h="w"></i>
            </div>
          </div>
          <p class="pc-hint">Drag the frame over the passport page and resize it from the edges. Keep the two code lines inside the green band.</p>
          <div class="pc-tools">
            <button type="button" class="pc-tool" data-pc="rotate-left" title="Rotate left">${icon.left}<span>Rotate</span></button>
            <button type="button" class="pc-tool" data-pc="rotate-right" title="Rotate right">${icon.right}<span>Rotate</span></button>
            <button type="button" class="pc-tool" data-pc="whole" title="Select the whole photo">${icon.expand}<span>Whole photo</span></button>
            <button type="button" class="pc-tool" data-pc="change" title="Choose another photo">${icon.swap}<span>Change photo</span></button>
          </div>
        </section>
        <footer class="pc-foot">
          <button type="button" class="pc-btn pc-ghost" data-pc="close">Cancel</button>
          <button type="button" class="pc-btn pc-primary" data-pc="scan" disabled>${icon.scan} Scan selected area</button>
        </footer>
      </div>`;
    document.body.appendChild(root);

    root.addEventListener('click', onClick);
    root.addEventListener('change', e => {
      if (e.target.matches('[data-pc="file"]')) {
        const file = e.target.files && e.target.files[0];
        e.target.value = '';
        if (file) loadFile(file);
      }
    });
    // Close only when both press and release happen on the backdrop (not after a drag).
    let downOnBackdrop = false;
    root.addEventListener('pointerdown', e => { downOnBackdrop = e.target === root; });
    root.addEventListener('pointerup', e => { if (downOnBackdrop && e.target === root) close(); downOnBackdrop = false; });

    const drop = $('.pc-drop');
    ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', e => {
      e.preventDefault();
      const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (file) loadFile(file);
    });
    drop.addEventListener('keydown', e => {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      e.preventDefault();
      const label = e.target.closest('label.pc-btn');
      (label ? label.querySelector('input') : $('.pc-pick-buttons input')).click();
    });

    const stage = $('.pc-stage');
    stage.addEventListener('pointerdown', startDrag);
    window.addEventListener('pointermove', moveDrag);
    window.addEventListener('pointerup', endDrag);
    window.addEventListener('pointercancel', endDrag);
    $('.pc-box').addEventListener('keydown', nudge);
    window.addEventListener('resize', () => { if (!root.hidden && source) layout(true); });
    document.addEventListener('keydown', e => {
      if (root.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key === 'Tab') trapFocus(e);
    });
    document.addEventListener('paste', e => {
      if (root.hidden) return;
      const item = Array.from((e.clipboardData && e.clipboardData.items) || []).find(x => x.type.startsWith('image/'));
      if (item) { e.preventDefault(); loadFile(item.getAsFile()); }
    });
  }

  function onClick(e) {
    const b = e.target.closest('[data-pc]');
    if (!b || b.tagName === 'INPUT') return;
    const act = b.dataset.pc;
    if (act === 'close') close();
    else if (act === 'rotate-left') { rotation = (rotation + 270) % 360; prepareSource(); layout(false); }
    else if (act === 'rotate-right') { rotation = (rotation + 90) % 360; prepareSource(); layout(false); }
    else if (act === 'whole') { const s = stageSize(); box = { x: 0, y: 0, w: s.w, h: s.h }; drawBox(); }
    else if (act === 'change') showView('pick');
    else if (act === 'scan') scan();
  }

  function showView(view) {
    root.querySelectorAll('.pc-view').forEach(v => { v.hidden = v.dataset.view !== view; });
    root.querySelectorAll('.pc-steps li').forEach(li => {
      const n = Number(li.dataset.step), current = view === 'pick' ? 1 : 2;
      li.classList.toggle('is-current', n === current);
      li.classList.toggle('is-done', n < current);
    });
    $('[data-pc="scan"]').disabled = view !== 'crop';
    if (view === 'pick') setTimeout(() => $('.pc-drop').focus(), 0);
  }

  function showError(message) {
    const el = $('.pc-error');
    el.textContent = message || '';
    el.hidden = !message;
  }

  function loadFile(file) {
    showError('');
    if (!file || !/^image\//.test(file.type || '')) { showError('Please choose an image file (JPG, PNG or WEBP).'); return; }
    if (file.size > 15 * 1024 * 1024) { showError('This photo is larger than 15 MB. Please choose a smaller one.'); return; }
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      original = img; rotation = 0;
      prepareSource();
      showView('crop');
      layout(false);
      setTimeout(() => $('.pc-box').focus(), 0);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      showError('This photo could not be opened here. Please use a JPG or PNG image.');
    };
    img.src = url;
  }

  // Rotated copy of the photo, limited to MAX_SOURCE on the longest side.
  function prepareSource() {
    const iw = original.naturalWidth, ih = original.naturalHeight;
    const scale = Math.min(1, MAX_SOURCE / Math.max(iw, ih));
    const w = Math.round(iw * scale), h = Math.round(ih * scale);
    const turned = rotation % 180 !== 0;
    if (source) { source.width = 0; source.height = 0; }
    source = document.createElement('canvas');
    source.width = turned ? h : w;
    source.height = turned ? w : h;
    const ctx = source.getContext('2d');
    ctx.translate(source.width / 2, source.height / 2);
    ctx.rotate(rotation * Math.PI / 180);
    ctx.drawImage(original, -w / 2, -h / 2, w, h);
  }

  function stageSize() {
    const c = $('.pc-canvas');
    return { w: c.clientWidth, h: c.clientHeight };
  }

  // Fit the photo into the window. keepBox = rescale the current frame instead of resetting it.
  function layout(keepBox) {
    const stage = $('.pc-stage'), canvas = $('.pc-canvas');
    const old = stageSize();
    const box_ = getComputedStyle(stage.parentElement);
    const maxW = stage.parentElement.clientWidth - parseFloat(box_.paddingLeft) - parseFloat(box_.paddingRight);
    const maxH = Math.max(220, Math.min(window.innerHeight * 0.56, 560));
    const scale = Math.min(maxW / source.width, maxH / source.height, 1);
    const w = Math.max(1, Math.round(source.width * scale)), h = Math.max(1, Math.round(source.height * scale));
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr);
    canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
    stage.style.width = w + 'px'; stage.style.height = h + 'px';
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    if (keepBox && box && old.w) {
      const r = w / old.w;
      box = { x: box.x * r, y: box.y * r, w: box.w * r, h: box.h * r };
    } else {
      const mx = w * 0.05, my = h * 0.05;
      box = { x: mx, y: my, w: w - mx * 2, h: h - my * 2 };
    }
    drawBox();
  }

  function clampBox() {
    const s = stageSize();
    box.w = Math.min(Math.max(box.w, Math.min(MIN_BOX, s.w)), s.w);
    box.h = Math.min(Math.max(box.h, Math.min(MIN_BOX, s.h)), s.h);
    box.x = Math.min(Math.max(box.x, 0), s.w - box.w);
    box.y = Math.min(Math.max(box.y, 0), s.h - box.h);
  }

  function drawBox() {
    clampBox();
    const el = $('.pc-box');
    el.style.left = box.x + 'px'; el.style.top = box.y + 'px';
    el.style.width = box.w + 'px'; el.style.height = box.h + 'px';
  }

  function point(e) {
    const r = $('.pc-stage').getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  function startDrag(e) {
    if (e.button !== undefined && e.button !== 0) return;
    const p = point(e);
    const handle = e.target.closest('[data-h]');
    if (handle) drag = { mode: handle.dataset.h, p, start: { ...box } };
    else if (e.target.closest('.pc-box')) drag = { mode: 'move', p, start: { ...box } };
    else { box = { x: p.x, y: p.y, w: 1, h: 1 }; drag = { mode: 'draw', p, start: { ...box } }; }
    $('.pc-stage').setPointerCapture?.(e.pointerId);
    $('.pc-stage').classList.add('is-dragging');
    e.preventDefault();
  }

  function moveDrag(e) {
    if (!drag) return;
    const p = point(e), s = stageSize(), st = drag.start;
    const dx = p.x - drag.p.x, dy = p.y - drag.p.y;
    if (drag.mode === 'move') {
      box = { ...st, x: st.x + dx, y: st.y + dy };
    } else if (drag.mode === 'draw') {
      const x = Math.min(Math.max(p.x, 0), s.w), y = Math.min(Math.max(p.y, 0), s.h);
      box = { x: Math.min(x, st.x), y: Math.min(y, st.y), w: Math.abs(x - st.x), h: Math.abs(y - st.y) };
      const el = $('.pc-box');
      el.style.left = box.x + 'px'; el.style.top = box.y + 'px'; el.style.width = box.w + 'px'; el.style.height = box.h + 'px';
      return;
    } else {
      let { x, y, w, h } = st;
      const m = drag.mode;
      if (m.includes('e')) w = Math.min(Math.max(MIN_BOX, st.w + dx), s.w - st.x);
      if (m.includes('s')) h = Math.min(Math.max(MIN_BOX, st.h + dy), s.h - st.y);
      if (m.includes('w')) { const nx = Math.min(Math.max(0, st.x + dx), st.x + st.w - MIN_BOX); w = st.w + (st.x - nx); x = nx; }
      if (m.includes('n')) { const ny = Math.min(Math.max(0, st.y + dy), st.y + st.h - MIN_BOX); h = st.h + (st.y - ny); y = ny; }
      box = { x, y, w, h };
    }
    drawBox();
  }

  function endDrag() {
    if (!drag) return;
    if (drag.mode === 'draw' && (box.w < MIN_BOX || box.h < MIN_BOX)) box = { ...drag.start, w: Math.max(box.w, MIN_BOX * 2), h: Math.max(box.h, MIN_BOX * 2) };
    drag = null;
    $('.pc-stage').classList.remove('is-dragging');
    drawBox();
  }

  function nudge(e) {
    const step = e.altKey ? 1 : 8;
    const keys = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (!keys[e.key]) return;
    e.preventDefault();
    const [dx, dy] = keys[e.key];
    if (e.shiftKey) { box.w += dx; box.h += dy; } else { box.x += dx; box.y += dy; }
    drawBox();
  }

  function trapFocus(e) {
    const items = Array.from(root.querySelectorAll('button:not([disabled]), [tabindex="0"], label.pc-btn'))
      .filter(el => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function scan() {
    if (!source || !box) return;
    const s = stageSize();
    const r = source.width / s.w;
    const sx = Math.round(box.x * r), sy = Math.round(box.y * r);
    const sw = Math.max(1, Math.min(source.width - sx, Math.round(box.w * r)));
    const sh = Math.max(1, Math.min(source.height - sy, Math.round(box.h * r)));
    const out = document.createElement('canvas');
    out.width = sw; out.height = sh;
    const ctx = out.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, sw, sh);
    ctx.drawImage(source, sx, sy, sw, sh, 0, 0, sw, sh);
    const button = $('[data-pc="scan"]');
    button.disabled = true;
    out.toBlob(blob => {
      out.width = 0; out.height = 0;
      button.disabled = false;
      if (!blob) { showError('Could not prepare the selected area. Please try again.'); return; }
      const file = new File([blob], 'passport-crop.jpg', { type: 'image/jpeg' });
      const callback = opts && opts.onScan;
      close();
      if (callback) callback(file);
    }, 'image/jpeg', 0.95);
  }

  function open(options) {
    if (!root) build();
    opts = options || {};
    lastFocus = document.activeElement;
    original = null; rotation = 0; box = null;
    showError('');
    root.hidden = false;
    document.documentElement.classList.add('pc-open');
    showView('pick');
  }

  function close() {
    if (!root || root.hidden) return;
    root.hidden = true;
    document.documentElement.classList.remove('pc-open');
    if (source) { source.width = 0; source.height = 0; source = null; }
    original = null; drag = null; opts = null;
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
  }

  window.PassportCropper = { open, close };
})();
