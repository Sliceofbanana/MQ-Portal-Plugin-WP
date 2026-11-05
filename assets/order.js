/* MQ Printing Services online order page (/order/) and order tracking (/order/track/). No customer account, no price, pay later. */
(function () {
  'use strict';
  var C = window.MQO;
  var P = C.prices;
  var app = document.getElementById('o-app');

  /* ---------- helpers ---------- */
  function h(tag, attrs) {
    var m = tag.match(/^([a-z0-9]+)?((?:[.#][\w-]+)*)$/i);
    var el = document.createElement((m && m[1]) || 'div');
    if (m && m[2]) m[2].replace(/([.#])([\w-]+)/g, function (_, t, v) { if (t === '.') el.classList.add(v); else el.id = v; });
    var kids = Array.prototype.slice.call(arguments, 2);
    if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k.slice(0, 2) === 'on' && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'class') el.className += ' ' + v;
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k === 'value' || k === 'checked' || k === 'disabled' || k === 'hidden') el[k] = v;
      else el.setAttribute(k, v === true ? '' : v);
    });
    (function add(list) {
      list.forEach(function (c) {
        if (c === null || c === undefined || c === false) return;
        if (Array.isArray(c)) add(c); else el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
      });
    })(kids);
    return el;
  }
  function clear(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
    (function add(list) {
      list.forEach(function (c) {
        if (c === null || c === undefined || c === false) return;
        if (Array.isArray(c)) add(c); else el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
      });
    })(Array.prototype.slice.call(arguments, 1));
    return el;
  }
  var peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
  function money(v) { return peso.format(Number(v) || 0); }
  function size(b) { return b > 1048576 ? (b / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function find(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; }
  function api(path, body) {
    return fetch(C.restUrl + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { if (!r.ok) throw new Error(d.message || 'Something went wrong. Please try again.'); return d; }); },
        function () { throw new Error('No internet connection. Please check your connection and try again.'); });
  }
  function upload(file, purpose, onProgress) {
    return new Promise(function (resolve, reject) {
      var fd = new FormData();
      fd.append('file', file);
      fd.append('purpose', purpose);
      var x = new XMLHttpRequest();
      x.open('POST', C.restUrl + 'order-files');
      x.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      x.onload = function () {
        var d = {}; try { d = JSON.parse(x.responseText); } catch (e) { /* ignore */ }
        if (x.status >= 200 && x.status < 300) resolve(d); else reject(new Error(d.message || 'Upload failed.'));
      };
      x.onerror = function () { reject(new Error('Upload failed. Check your connection.')); };
      x.send(fd);
    });
  }
  function countPdfPages(file) {
    if (!window.pdfjsLib || !/pdf$/i.test(file.name)) return Promise.resolve(0);
    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    return file.arrayBuffer().then(function (buf) { return window.pdfjsLib.getDocument({ data: buf }).promise; })
      .then(function (doc) { return doc.numPages; }, function () { return 0; });
  }
  function svg(d) {
    var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '28'); s.setAttribute('height', '28'); s.setAttribute('fill', 'none');
    s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path'); p.setAttribute('d', d); s.appendChild(p);
    return s;
  }
  var ICON = {
    printer: 'M7 9V3h10v6M7 17H4v-7a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v7h-3M7 14h10v7H7z',
    id: 'M3 5h18v14H3zM8 11a2 2 0 1 0 0-.01M5.5 16a3 3 0 0 1 5 0M14 9h4M14 13h4',
    upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
  };
  function withParam(url, k, v) { return url + (url.indexOf('?') === -1 ? '?' : '&') + k + '=' + encodeURIComponent(v); }
  // Inside the website popup: tell it whether an order is half-done (so closing asks first).
  function tellPopup(dirty) { if (C.embed && window.parent !== window) window.parent.postMessage({ mqo: 'state', dirty: dirty }, location.origin); }
  function remember(ref, email) { try { sessionStorage.setItem('mqo_last', JSON.stringify({ ref: ref, email: email })); } catch (e) { /* private mode */ } }
  function remembered() { try { return JSON.parse(sessionStorage.getItem('mqo_last')) || {}; } catch (e) { return {}; } }

  function toast(msg, bad) {
    var t = h('div.o-toast', { class: bad ? 'is-bad' : '' }, msg);
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); }, 3500);
    setTimeout(function () { t.remove(); }, 4000);
  }

  ICON.pin = 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z';
  ICON.bike = 'M5 18a3 3 0 1 0 0-.01M19 18a3 3 0 1 0 0-.01M5 18l4-7h6l4 7M9 11l-2-4H4M15 11l-2-5h3';
  ICON.download = 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3';
  ICON.school = 'M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5M22 9v6';

  /** Saves a picture (the payment QR codes) to the phone or computer; opens it when saving is not allowed. */
  function saveImage(url, name) {
    fetch(url).then(function (r) { if (!r.ok) throw new Error(); return r.blob(); }).then(function (blob) {
      var a = h('a', { href: URL.createObjectURL(blob), download: name });
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
      toast('Saved: ' + name);
    }).catch(function () { window.open(url, '_blank', 'noopener'); });
  }

  /** The payment QR codes, large, each with a Save button. No amount: we message the price. */
  function qrSection(title, text) {
    var list = P.payment.filter(function (p) { return p.qr; });
    if (!list.length) return null;
    return h('section.o-qr-box',
      h('h3', title),
      text ? h('p.o-muted', text) : null,
      h('div.o-qr-list', list.map(function (p) {
        var ext = ((p.qr.split('?')[0].split('.').pop() || 'png').toLowerCase()).slice(0, 4);
        var file = 'MQ-Prints-' + p.name.replace(/[^\w]+/g, '-') + '-QR.' + ext;
        return h('figure.o-qr-card',
          h('a', { href: p.qr, target: '_blank', rel: 'noopener', title: 'Open ' + p.name + ' QR code' }, h('img.o-qr', { src: p.qr, alt: p.name + ' QR code' })),
          h('figcaption', h('strong', p.name),
            p.account_name ? h('small', p.account_name) : null,
            p.account_number ? h('small', p.account_number) : null),
          h('button.o-btn.o-btn-primary', { type: 'button', onclick: function () { saveImage(p.qr, file); } }, svg(ICON.download), 'Save QR'));
      })),
      h('p.o-muted.o-small', 'On a phone you can also press and hold the QR code to save it.'));
  }

  function messengerButton(label) {
    return P.messenger_url ? h('a.o-btn.o-btn-primary', { href: P.messenger_url, target: '_blank', rel: 'noopener' }, label || 'Message us on Facebook') : null;
  }

  if (C.mode === 'track') return track();

  /* ---------- order state ---------- */
  var S = {
    step: 1,
    files: [],                // {name,size,progress,id,token,pages,pagesKnown,ext,kind,note,error}
    supplies: {},
    delivery: { type: 'pickup', courier: '', location: '' },
    contact: { name: '', phone: '', email: '', consent: false },
    notes: '',
    result: null,
  };
  // Files chosen in the website's upload box ([mq_upload]) arrive from the pop-up around this page.
  var handed = [];
  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !e.data || e.data.mqo !== 'files') return;
    handed = handed.concat(Array.prototype.slice.call(e.data.files || []));
    S.step = 1;
    render();
  });
  var shownStep = 0;
  var STEPS = ['Your order', 'Details & place order', 'Done'];
  var EXT = {};
  P.kinds.forEach(function (k) { k.ext.forEach(function (x) { (EXT[x] = EXT[x] || []).push(k); }); });
  var ACCEPT = Object.keys(EXT);

  function uploaded() { return S.files.filter(function (f) { return f.id; }); }
  function totalPages() { return uploaded().reduce(function (a, f) { return a + (f.ext === 'pdf' ? Math.max(1, f.pages || 1) : 0); }, 0); }
  function kindName(id, f) { var k = find(P.kinds, id); if (!k) return ''; return k.specify ? k.name + ((f && (f.specify || '').trim()) ? ': ' + f.specify.trim() : '') : k.name; }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  /* ---------- layout ---------- */
  var stepper = h('ol.o-steps');
  var panel = h('section.o-panel');
  var summary = h('aside.o-summary');
  var nav = h('div.o-actions');
  clear(app,
    h('div.o-hero', h('p.o-kicker', C.company.name), h('h1', 'Order online'), h('p', 'Upload your files and tell us what you need. No payment needed now — we check your files and message you the price. No account needed.')),
    stepper,
    h('div.o-grid', h('div', panel, nav), summary));

  function render() {
    clear(stepper);
    STEPS.forEach(function (s, i) {
      stepper.appendChild(h('li', { class: i + 1 < S.step ? 'done' : i + 1 === S.step ? 'current' : '' }, h('span', i + 1 < S.step ? '✓' : String(i + 1)), h('em', s)));
    });
    clear(panel, [null, stepFiles, stepDetails, stepDone][S.step]());
    renderSummary();
    renderNav();
    if (S.step !== shownStep) { shownStep = S.step; window.scrollTo({ top: 0, behavior: 'smooth' }); } // Not when an option on the same step is picked.
    tellPopup(S.step < 3 && (S.step > 1 || S.files.length > 0));
  }

  function renderSummary() {
    var files = uploaded();
    var sup = P.supplies.filter(function (s) { return S.supplies[s.id] > 0; });
    var pages = totalPages();
    summary.hidden = S.step === 3;
    clear(summary,
      h('h3', 'Your order'),
      files.length || sup.length ? h('ul.o-lines',
        files.map(function (f) { return h('li', h('div', h('strong', f.name), h('small', [kindName(f.kind, f) || 'Choose what it is for', f.ext === 'pdf' ? plural(f.pages || 1, 'page', 'pages') : ''].filter(Boolean).join(' · ')))); }),
        sup.map(function (s) { return h('li', h('div', h('strong', s.name), h('small', s.unit)), h('span', '× ' + S.supplies[s.id])); }))
        : h('p.o-muted', 'Your files and supplies appear here.'),
      pages ? h('div.o-total', h('span', 'Pages to print'), h('strong', String(pages))) : null,
      S.step > 1 ? h('p.o-muted.o-small', getItText()) : null,
      h('p.o-small.o-note', 'No payment needed now. We confirm the price with you first.'),
      h('p.o-muted.o-small', P.notice));
  }

  function renderNav() {
    if (S.step >= 2) { clear(nav); return; }
    clear(nav,
      S.step > 1 ? h('button.o-btn.o-btn-ghost', { type: 'button', onclick: function () { S.step--; render(); } }, '← Back') : h('span'),
      h('button.o-btn.o-btn-primary', { type: 'button', onclick: next }, 'Next: details →'));
  }

  function next() {
    var err = validate(S.step);
    if (err) return toast(err, true);
    S.step++;
    render();
  }

  function validate(step) {
    if (step === 1) {
      if (S.files.some(function (f) { return !f.id && !f.error; })) return 'Please wait for your files to finish uploading.';
      if (!uploaded().length) return 'Upload at least one file.';
      var noKind = uploaded().filter(function (f) { return !f.kind; })[0];
      if (noKind) return 'Choose what ' + noKind.name + ' is for.';
      var noWhat = uploaded().filter(function (f) { var k = find(P.kinds, f.kind); return k && k.specify && !(f.specify || '').trim(); })[0];
      if (noWhat) return 'Please specify what ' + noWhat.name + ' is for.';
    }
    if (step === 2) {
      if (S.delivery.type === 'courier' && !S.delivery.courier) return 'Choose Maxim, Lalamove or Grab Express.';
      if (S.delivery.type === 'campus' && !S.delivery.location) return 'Choose your campus.';
    }
    if (step === 2) {
      if (!S.contact.name.trim()) return 'Enter your name.';
      if (!/^[0-9+()\s-]{7,}$/.test(S.contact.phone.trim())) return 'Enter a valid mobile number.';
      if (!/^\S+@\S+\.\S+$/.test(S.contact.email.trim())) return 'Enter a valid email — we send your order number there.';
      if (!S.contact.consent) return 'Please tick the box to agree to how we use your details.';
    }
    return '';
  }

  /* ---------- step 1: files, notes and office supplies ---------- */
  function stepFiles() {
    var list = h('ul.o-files');
    function drawFiles() {
      clear(list);
      S.files.forEach(function (f, i) {
        var status = f.error ? f.error : !f.id ? 'uploading ' + Math.round(f.progress * 100) + '%' : 'uploaded';
        var kind = find(P.kinds, f.kind);
        list.appendChild(h('li.o-file', { class: f.error ? 'is-bad' : '' },
          h('div.o-file-head',
            h('div.o-file-main', h('strong', f.name), h('small', size(f.size) + ' · ' + status),
              !f.id && !f.error ? h('span.o-bar', h('span', { style: { width: Math.round(f.progress * 100) + '%' } })) : null),
            h('button.o-x', { type: 'button', 'aria-label': 'Remove ' + f.name, onclick: function () { S.files.splice(i, 1); drawFiles(); renderSummary(); } }, '×')),
          f.error ? null : h('div.o-file-body',
            // Asked for every file: documents are PDF; images JPEG / PNG; HD designs JPEG / PNG / PSD.
            h('div.o-field', h('span.o-label', 'What is this file for?'),
              h('div.o-seg.o-seg-kinds', P.kinds.map(function (k) {
                return h('button', { type: 'button', class: f.kind === k.id ? 'is-on' : '', onclick: function () {
                  if (k.ext.indexOf(f.ext) === -1) return toast(k.name + ' files must be ' + k.ext.join(', ').toUpperCase() + '. Please upload ' + (k.ext.length === 1 ? 'a ' : '') + k.ext.join(' or ').toUpperCase() + ' file for ' + k.name.toLowerCase() + '.', true);
                  f.kind = k.id; drawFiles(); renderSummary();
                } }, k.specify ? k.name + ': please specify' : k.name);
              })),
              kind && kind.specify ? h('input.o-specify', { type: 'text', maxlength: 80, value: f.specify || '', placeholder: 'Please specify, e.g. mugs, tote bags, ID lace', 'aria-label': 'What ' + f.name + ' is for', oninput: function (e) { f.specify = e.target.value; renderSummary(); } }) : null),
            f.ext === 'pdf' ? h('label.o-pages', h('span.o-label', 'Pages'),
              h('input', { type: 'number', min: 1, max: 5000, value: f.pages || 1, disabled: !f.id, 'aria-label': 'Pages in ' + f.name, onchange: function (e) { f.pages = Math.max(1, parseInt(e.target.value, 10) || 1); e.target.value = f.pages; renderSummary(); } }),
              h('small', f.id ? (f.pagesKnown ? 'counted for you' : 'please enter the number of pages') : 'counting…')) : null,
            h('label.o-field', h('span.o-label', 'Complete instructions for this file'),
              h('textarea', { rows: 2, placeholder: P.note_guide, oninput: function (e) { f.note = e.target.value; } }, f.note || '')))));
      });
    }
    function addFiles(fileList) {
      Array.prototype.slice.call(fileList).forEach(function (file) {
        if (S.files.length >= 30) { toast('You can upload up to 30 files.', true); return; }
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!EXT[ext]) { toast(file.name + ': please upload PDF for documents, JPEG / PNG for images, or JPEG / PNG / PSD for HD designs.', true); return; }
        if (file.size > C.maxBytes) { toast(file.name + ' is too large (' + size(file.size) + '). Upload a screenshot of the design instead and add a Google Drive link to your files in the instructions.', true); return; }
        var f = { name: file.name, size: file.size, progress: 0, pages: 0, ext: ext, kind: '', note: '' };
        S.files.push(f);
        drawFiles();
        tellPopup(true);
        countPdfPages(file).then(function (n) {
          f.clientPages = n;
          return upload(file, 'order', function (p) { f.progress = p; drawFiles(); });
        }).then(function (d) {
          f.id = d.id; f.token = d.token;
          f.pages = ext === 'pdf' ? Math.max(d.pages || 0, f.clientPages || 0) || 1 : 0;
          f.pagesKnown = ext !== 'pdf' || (d.pages || f.clientPages) > 0;
          drawFiles(); renderSummary();
        }).catch(function (e) { f.error = e.message; drawFiles(); });
      });
    }
    var input = h('input', { type: 'file', multiple: true, accept: ACCEPT.map(function (x) { return '.' + x; }).join(','), onchange: function (e) { addFiles(e.target.files); e.target.value = ''; } });
    var drop = h('label.o-drop', input,
      h('span.o-drop-icon', svg(ICON.upload)),
      h('strong', S.files.length ? 'Add more files' : 'You can upload 1 or more files'),
      h('small', 'Up to ' + size(C.maxBytes) + ' each · or drag files here'));
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); addFiles(e.dataTransfer.files); });
    drawFiles();
    if (handed.length) { var got = handed; handed = []; addFiles(got); }
    return [
      h('h2', 'Upload your files'),
      h('div.o-types',
        h('span', '✔️ ', h('strong', 'PDF'), ' format for documents'),
        h('span', '✔️ ', h('strong', 'JPEG / PNG'), ' format for images'),
        h('span', '✔️ ', h('strong', 'JPEG / PNG / PSD'), ' format for HD designs')),
      drop, list,
      h('p.o-muted.o-small', 'Write complete instructions under each file: ' + P.note_guide),
      supplies(),
      h('label.o-field', h('span.o-label', 'Other notes for the whole order (optional)'), h('textarea', { rows: 2, placeholder: 'e.g. I need it by Friday', oninput: function (e) { S.notes = e.target.value; } }, S.notes)),
      h('p.o-muted.o-small', 'If original files are too large to upload, please upload a screenshot of the design instead and attach in the order a Google Drive link of your files.'),
    ];
  }

  /** Office supplies in a dropdown the customer opens if they want any: a tile per item with − and +. */
  function supplies() {
    var state = (P.features || {}).supplies || 'on';
    if (!P.supplies.length || state === 'off') return null;
    if (state === 'soon') return h('section.o-supplies.is-soon', h('h3', 'Office supplies ', h('span.o-soon', 'Coming soon')), h('p.o-muted.o-small', 'Soon you can add bond paper, folders, envelopes and more to your order.'));
    var count = h('span.o-supply-count');
    function showCount() {
      var n = P.supplies.filter(function (s) { return S.supplies[s.id] > 0; }).length;
      count.textContent = n ? n + ' added' : '';
    }
    showCount();
    var box = h('details.o-supplies',
      h('summary', h('span.o-supply-title', h('strong', 'Add office supplies'), h('span.o-optional', 'Optional')), count),
      h('p.o-muted.o-small', 'Bond paper, folders, envelopes and more — we prepare them with your order.'),
      h('div.o-supply-list', P.supplies.map(function (s) {
        var qty = h('input', { type: 'number', min: 0, max: 100, value: S.supplies[s.id] || 0, 'aria-label': s.name + ' quantity', oninput: function (e) { set(parseInt(e.target.value, 10) || 0, true); } });
        var tile = h('div.o-supply', { class: S.supplies[s.id] > 0 ? 'is-on' : '' },
          h('span', h('strong', s.name), h('small', s.unit)),
          h('div.o-stepper',
            h('button', { type: 'button', 'aria-label': 'Less ' + s.name, onclick: function () { set((S.supplies[s.id] || 0) - 1); } }, '−'),
            qty,
            h('button', { type: 'button', 'aria-label': 'More ' + s.name, onclick: function () { set((S.supplies[s.id] || 0) + 1); } }, '+')));
        function set(n, typing) {
          S.supplies[s.id] = Math.max(0, Math.min(100, n));
          if (!typing) qty.value = S.supplies[s.id];
          tile.classList.toggle('is-on', S.supplies[s.id] > 0);
          showCount();
          renderSummary();
        }
        return tile;
      })));
    // Keep it open after a re-render if the customer opened it or already added something.
    if (S.suppliesOpen || P.supplies.some(function (s) { return S.supplies[s.id] > 0; })) box.open = true;
    box.addEventListener('toggle', function () { S.suppliesOpen = box.open; });
    return box;
  }

  /* ---------- pick up, courier or campus delivery (part of step 2) ---------- */
  function stepDelivery() {
    var d = S.delivery;
    var F = P.features || {};
    // A feature switched to "soon" in Settings is shown with a Coming soon badge and can't be chosen.
    var opt = function (type, icon, title, text, hint) {
      var state = type === 'pickup' ? 'on' : F[type] || 'on';
      if (state === 'off') return null;
      var soon = state === 'soon';
      return h('button.o-choice', { type: 'button', class: (d.type === type ? 'is-on' : '') + (soon ? ' is-soon' : ''), disabled: soon, 'aria-disabled': soon ? 'true' : null,
        onclick: function () { if (!soon) { d.type = type; render(); } } },
        h('span.o-choice-icon', svg(icon)), h('strong', title, soon ? h('span.o-soon', 'Coming soon') : null), h('small', text),
        hint && !soon ? h('small.o-choice-hint', hint) : null);
    };
    var names = P.couriers.map(function (c) { return c.name; });
    var body = [
      h('h3.o-sub', 'How do you want to get your order?'),
      h('div.o-choices.o-choices-3',
        opt('pickup', ICON.pin, 'Pick up', 'Free — at ' + P.pickup.name + '.', P.pickup_hint),
        P.couriers.length ? opt('courier', ICON.bike, names.join(' / '), 'You book the rider when your order is ready.', P.courier_hint) : null,
        P.campuses.length ? opt('campus', ICON.school, 'Campus delivery', 'We deliver to your school.') : null),
    ];
    if (d.type === 'campus') {
      body.push(h('label.o-field', h('span.o-label', 'Campus'), h('select', { onchange: function (e) { d.location = e.target.value; renderSummary(); } },
        h('option', { value: '' }, '— Choose your campus —'),
        P.campuses.map(function (c) { return h('option', { value: c, selected: d.location === c }, c); }))));
    }
    if (d.type === 'pickup') {
      body.push(
        h('label.o-field', h('span.o-label', 'Pick-up branch'), h('select', null, h('option', { selected: true }, P.pickup.name + (P.pickup.address ? ' — ' + P.pickup.address : '')))),
        h('div.o-map',
          h('iframe', { src: P.map_embed, title: 'Map: ' + P.pickup.name, loading: 'lazy', referrerpolicy: 'no-referrer-when-downgrade', allowfullscreen: true }),
          h('div.o-map-foot', h('span', h('strong', P.pickup.name), P.pickup.address ? h('small', P.pickup.address) : null),
            h('a.o-btn', { href: P.map_link, target: '_blank', rel: 'noopener' }, 'Open in Google Maps ↗'))));
    } else {
      body.push(
        h('div.o-field', h('span.o-label', 'Which app will you book?'),
          h('div.o-seg.o-seg-lg', P.couriers.map(function (c) { return h('button', { type: 'button', class: d.courier === c.id ? 'is-on' : '', onclick: function () { d.courier = c.id; render(); } }, c.name); }))),
        h('div.o-pins', P.couriers.map(function (c) {
          return h('div.o-pin', { class: d.courier === c.id ? 'is-on' : '' }, h('span', c.name + ' pin address'), h('strong', c.pin || P.pickup.name));
        })),
        h('div.o-note-box', svg(ICON.bike), h('p', 'Use the pin above as the pick-up point in your app. ', P.courier_note)));
    }
    return body;
  }

  /* ---------- contact (part of step 2) ---------- */
  function stepContact() {
    var c = S.contact;
    var f = function (label, key, type, ac, hint) {
      return h('label.o-field', h('span.o-label', label), h('input', { type: type, value: c[key], autocomplete: ac, oninput: function (e) { c[key] = e.target.value; } }), hint ? h('small.o-muted', hint) : null);
    };
    return [h('h3.o-sub', 'Your contact details'), h('p.o-muted', 'No account needed. We send your order number and updates to your email.'),
      f('Full name', 'name', 'text', 'name'), h('div.o-row', f('Mobile number', 'phone', 'tel', 'tel'), f('Email', 'email', 'email', 'email', 'Your order number is sent here.')),
      consentBox(c)];
  }

  /** Privacy consent (RA 10173); the sentence comes from the server with the policy link. */
  function consentBox(c) {
    var text = h('span');
    text.innerHTML = C.consent || 'I agree to the Privacy Policy.';
    return h('label.o-consent', h('input', { type: 'checkbox', checked: !!c.consent, onchange: function (e) { c.consent = e.target.checked; } }), text);
  }

  function getItText() {
    var d = S.delivery;
    var c = find(P.couriers, d.courier);
    if (d.type === 'campus') return 'Campus delivery — ' + d.location;
    return d.type === 'pickup' ? 'Pick up at ' + P.pickup.name : (c ? c.name + ' — you book the rider (pin: ' + (c.pin || P.pickup.name) + ')' : '');
  }

  /* ---------- step 2: how to get it, contact details, check and place the order ---------- */
  function stepDetails() {
    var sup = P.supplies.filter(function (s) { return S.supplies[s.id] > 0; });
    var btn = h('button.o-btn.o-btn-primary.o-btn-lg', { type: 'button', onclick: submit }, 'Place my order');
    var tsBox = C.turnstile ? h('div.cf-turnstile', { 'data-sitekey': C.turnstile }) : null;
    function submit() {
      var err = validate(2);
      if (err) return toast(err, true);
      btn.disabled = true; btn.textContent = 'Placing your order…';
      var ts = document.querySelector('[name="cf-turnstile-response"]');
      api('orders', {
        files: uploaded().map(function (f) { return { id: f.id, token: f.token, pages: f.pages, kind: f.kind, specify: f.specify || '', note: f.note }; }),
        supplies: S.supplies,
        delivery: S.delivery, contact: S.contact, notes: S.notes,
        website: document.getElementById('o-hp').value,
        turnstile: ts ? ts.value : '',
      }).then(function (res) {
        S.result = res; S.step = 3; remember(res.reference, res.email); render();
      }).catch(function (e) {
        btn.disabled = false; btn.textContent = 'Place my order';
        toast(e.message, true);
        if (window.turnstile) window.turnstile.reset();
      });
    }
    return [
      h('h2', 'Almost done'),
      stepDelivery(),
      h('hr.o-sep'),
      stepContact(),
      h('hr.o-sep'),
      h('h3.o-sub', 'Check your order'),
      h('ul.o-review-files', uploaded().map(function (f) {
        return h('li', h('div', h('strong', f.name), h('small', [kindName(f.kind, f), f.ext === 'pdf' ? plural(f.pages || 1, 'page', 'pages') : ''].filter(Boolean).join(' · '))),
          f.note ? h('p', f.note) : h('p.o-muted', 'No notes'));
      })),
      sup.length || S.notes ? h('dl.o-review',
        sup.length ? [h('dt', 'Office supplies'), h('dd', sup.map(function (s) { return s.name + ' × ' + S.supplies[s.id]; }).join(', '))] : null,
        S.notes ? [h('dt', 'Other notes'), h('dd', S.notes)] : null) : null,
      h('div.o-note-box', h('p', h('strong', 'No payment needed now. '), 'We check your files and message you the price. You can pay later with the QR codes on the next page.')),
      h('input#o-hp.o-hp', { type: 'text', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
      tsBox,
      h('div.o-actions', h('button.o-btn.o-btn-ghost', { type: 'button', onclick: function () { S.step = 1; render(); } }, '← Back to files'), btn)];
  }

  /* ---------- step 3: done ---------- */
  function stepDone() {
    var r = S.result;
    var copy = h('button.o-btn.o-btn-ghost', { type: 'button', onclick: function () {
      (navigator.clipboard ? navigator.clipboard.writeText(r.reference) : Promise.reject()).then(function () { copy.textContent = 'Copied ✓'; }, function () {});
    } }, 'Copy');
    return h('div.o-done',
      h('div.o-done-icon', '✓'),
      h('h2', 'Thank you! Your order is placed.'),
      h('p', 'Your order number:'),
      h('div.o-ref', h('strong', r.reference), copy),
      h('p.o-muted', 'We also sent it to ', h('strong', r.email), '. Use this order number when you message us on our Facebook page about this order. We will message you the price once we have checked your files.'),
      h('div.o-actions.o-actions-center', messengerButton('Message us about this order')),
      qrSection('Pay when you are ready', 'No need to pay now. Save the QR code, and once we tell you the price, pay and send us the screenshot with your order number.'),
      h('div.o-actions.o-actions-center',
        C.embed ? null : h('a.o-btn', { href: withParam(C.trackUrl, 'ref', r.reference) }, 'Track my order'),
        h('a.o-btn.o-btn-ghost', { href: C.orderUrl }, 'Place another order')));
  }

  render();

  /* ---------- tracking page ---------- */
  function track() {
    var qs = new URLSearchParams(location.search);
    var last = remembered();
    var ref0 = qs.get('ref') || '';
    var email0 = qs.get('email') || (last.ref && last.ref === ref0 ? last.email : '');
    var result = h('div');
    var form = h('form.o-panel.o-track-form', { onsubmit: function (e) {
      e.preventDefault();
      lookup(form.ref.value.trim(), form.email.value.trim());
    } },
    h('h2', 'Track your order'),
    h('p.o-muted', 'Enter the order number from your email and the email you used.'),
    h('div.o-row',
      h('label.o-field', h('span.o-label', 'Order number'), h('input', { name: 'ref', required: true, placeholder: 'MQ-261027-ABCD', value: ref0 })),
      h('label.o-field', h('span.o-label', 'Email'), h('input', { name: 'email', type: 'email', required: true, value: email0 }))),
    h('button.o-btn.o-btn-primary', { type: 'submit' }, 'Check status'));
    clear(app, h('div.o-track', form, result));
    if (ref0 && email0) lookup(ref0, email0);

    function lookup(ref, email) {
      clear(result, h('p.o-loading', 'Looking up your order…'));
      api('orders/track', { reference: ref, email: email }).then(function (o) { show(o, email); }, function (e) { clear(result, h('p.o-error', e.message)); });
    }
    function show(o, email) {
      var st = o.status;
      var pending = o.payment_status === 'pending';
      var pay = pending
        ? (o.invoice_status === 'paid' ? 'Paid ✓' : o.total > 0 ? (o.invoice_status === 'partial' ? 'Partly paid — ' + money(o.balance) + ' left' : 'Not paid yet') : 'We will message you the price')
        : o.payment_method + ' — ' + ({ submitted: 'being verified', verified: 'verified ✓', rejected: 'not verified' }[o.payment_status] || o.payment_status);
      clear(result, h('section.o-panel',
        h('p.o-kicker', o.reference),
        h('h2', st.label),
        st.key === 'cancelled' ? null : h('ol.o-track-steps', { style: { gridTemplateColumns: 'repeat(' + st.steps.length + ', 1fr)' } }, st.steps.map(function (s) { return h('li', { class: s.done ? 'done' : s.current ? 'current' : '' }, h('span', s.done ? '✓' : ''), h('em', s.label)); })),
        o.payment_status === 'rejected' ? h('div.o-alert', h('strong', 'We could not verify your payment.'), o.payment_note ? h('p', 'Reason: ' + o.payment_note) : null) : null,
        h('dl.o-review',
          h('dt', 'Ordered'), h('dd', new Date(o.created_at.replace(' ', 'T')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })),
          h('dt', 'Order'), h('dd', o.summary || 'Printing'),
          h('dt', o.delivery_type === 'pickup' ? 'Pick up at' : 'Delivery'), h('dd', o.delivery),
          pending && o.total > 0 ? [h('dt', 'Price'), h('dd', h('strong', money(o.total)))] : null,
          h('dt', 'Payment'), h('dd', pay)),
        o.files && o.files.length ? h('ul.o-review-files', o.files.map(function (f) {
          return h('li', h('div', h('strong', f.name), h('small', [f.kind, f.pages ? plural(f.pages, 'page', 'pages') : ''].filter(Boolean).join(' · '))), f.note ? h('p', f.note) : null);
        })) : null,
        !pending && o.items && o.items.length ? [h('ul.o-lines.o-lines-lg', o.items.map(function (l) { return h('li', h('div', h('strong', l.description), h('small', l.specs || '')), h('span', money(l.amount))); })),
          h('div.o-total.o-total-lg', h('span', 'Total'), h('strong', money(o.total)))] : null,
        pending && st.key !== 'cancelled' ? paymentSection(o) : null,
        h('div.o-actions.o-actions-center', messengerButton('Message us about this order')),
        o.can_repay ? repayForm(o, email) : null));
    }
    /** Payment: the computation staff set on the invoice (each line, total, paid, balance) and the QR codes. */
    function paymentSection(o) {
      var priced = o.items && o.items.length && o.total > 0;
      var paid = o.invoice_status === 'paid';
      var qty = function (l) { var q = Number(l.qty); return (q % 1 ? q.toFixed(2) : String(q)) + ' ' + (l.unit || ''); };
      return h('section.o-payment',
        h('h3', 'Payment'),
        priced ? [
          h('table.o-bill',
            h('thead', h('tr', h('th', 'Item'), h('th.num', 'Qty × price'), h('th.num', 'Amount'))),
            h('tbody', o.items.map(function (l) {
              return h('tr', h('td', h('strong', l.description), l.specs ? h('small', l.specs) : null), h('td.num', qty(l) + ' × ' + money(l.unit_price)), h('td.num', money(l.amount)));
            })),
            h('tfoot',
              o.discount > 0 ? [h('tr', h('td', { colspan: 2 }, 'Subtotal'), h('td.num', money(o.subtotal))), h('tr', h('td', { colspan: 2 }, 'Discount'), h('td.num', '− ' + money(o.discount)))] : null,
              h('tr.o-bill-total', h('td', { colspan: 2 }, 'Total amount'), h('td.num', money(o.total))),
              o.amount_paid > 0 ? h('tr', h('td', { colspan: 2 }, 'Paid'), h('td.num', '− ' + money(o.amount_paid))) : null,
              h('tr.o-bill-due', h('td', { colspan: 2 }, paid ? 'Balance' : 'Amount to pay'), h('td.num', paid ? 'Paid ✓' : money(o.balance))))),
        ] : h('p.o-muted', 'We are checking your files. We will message you the price here and on Facebook — then you can pay with the QR codes below.'),
        paid ? null : qrSection('Pay with QR', priced
          ? 'Scan a QR code and pay ' + money(o.balance) + ', then send us the screenshot with your order number.'
          : 'Pay once we have told you the price, then send us the screenshot with your order number.'));
    }
    function repayForm(o, email) {
      var pay = { method: P.payment[0] ? P.payment[0].id : '', proof: null };
      var info = h('small.o-muted', 'Optional screenshot of your payment.');
      var f = h('form.o-repay', { onsubmit: function (e) {
        e.preventDefault();
        var btn = f.querySelector('button[type=submit]');
        btn.disabled = true;
        api('orders/payment', { reference: o.reference, email: email, method: f.paymethod.value, payment_reference: f.payref.value.trim(), proof: pay.proof })
          .then(function () { toast('Thank you! We will verify your new payment shortly.'); lookup(o.reference, email); })
          .catch(function (err) { btn.disabled = false; toast(err.message, true); });
      } },
      h('h3', 'Send your payment again'),
      h('div.o-row',
        h('label.o-field', h('span.o-label', 'Paid with'), h('select', { name: 'paymethod' }, P.payment.map(function (p) { return h('option', { value: p.id }, p.name); }))),
        h('label.o-field', h('span.o-label', 'Reference number'), h('input', { name: 'payref', required: true, minlength: 4 }))),
      h('label.o-field', h('span.o-label', 'Screenshot'), h('input', { type: 'file', accept: '.png,.jpg,.jpeg,.webp,.pdf', onchange: function (e) {
        var file = e.target.files[0]; if (!file) return;
        upload(file, 'payment', function (p) { info.textContent = 'Uploading ' + Math.round(p * 100) + '%…'; })
          .then(function (d) { pay.proof = { id: d.id, token: d.token }; info.textContent = '✓ ' + file.name; }, function (err) { info.textContent = err.message; });
      } }), info),
      h('button.o-btn.o-btn-primary', { type: 'submit' }, 'Send payment details'));
      return f;
    }
  }
})();
