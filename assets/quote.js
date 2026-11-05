/* "Get Quotation" pop-up ([mq_quote]).
 * Step 1: pick items with a set price (quantity) and/or describe custom work (+ optional files).
 * Step 2: name, email, phone (company, needed-by date, notes optional).
 * Step 3: the quotation right away (only set prices), or "we'll get back to you within 24 hours".
 * Buttons anywhere on the website open it: a link to #mq-quote or the CSS class mq-quote-open. */
(function () {
  if (window.__mqpQuoteBooted) return;
  window.__mqpQuoteBooted = true;

  var peso = function (v) { return '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  var openers = [];

  function init(root) {
    if (root.dataset.mqqReady) return;
    root.dataset.mqqReady = '1';
    var cfg = JSON.parse(root.dataset.config || '{}');
    var modal = root.querySelector('.mqq-modal');
    var box = modal.querySelector('.mqq-box');
    var $ = function (s) { return modal.querySelector(s); };
    var steps = modal.querySelectorAll('.mqq-step');
    var dots = modal.querySelectorAll('.mqq-steps span');
    var brandSel = $('.mqq-brand');
    var list = $('.mqq-list');
    var search = $('.mqq-search');
    var customList = $('.mqq-custom-list');
    var sumText = $('.mqq-sum-text');
    var next = $('.mqq-next');
    var msg = $('.mqq-msg');
    var submit = $('.mqq-submit');
    var filesIn = modal.querySelector('input[type=file]');
    var qty = {}; // product id → quantity
    var busy = false;
    var opener = null;

    document.body.appendChild(modal); // So panels around the button can't trap the pop-up.

    function say(t) { msg.textContent = t || ''; msg.className = 'mqq-msg' + (t ? ' is-error' : ''); }
    function show(n) {
      steps.forEach(function (s) { s.hidden = Number(s.dataset.step) !== n; });
      dots.forEach(function (d, i) { d.classList.toggle('is-on', i < n); });
      box.scrollTop = 0;
    }

    // Shops
    (cfg.brands || []).forEach(function (b) { var o = el('option', '', b.name); o.value = b.id; brandSel.appendChild(o); });
    if (cfg.brand) brandSel.value = cfg.brand;
    brandSel.closest('label').hidden = !!cfg.brand || (cfg.brands || []).length < 2;
    brandSel.addEventListener('change', function () { qty = {}; drawList(); sum(); });

    function items() { return (cfg.catalog && cfg.catalog[brandSel.value]) || []; }

    function drawList() {
      var q = (search.value || '').toLowerCase().trim();
      var all = items();
      list.innerHTML = '';
      search.hidden = all.length < 8;
      if (!all.length) {
        list.appendChild(el('p', 'mqq-empty-list', 'No set prices for this shop yet — describe what you need below and we will price it.'));
        return;
      }
      var cat = null;
      all.filter(function (p) { return !q || (p.name + ' ' + p.category).toLowerCase().indexOf(q) !== -1; }).forEach(function (p) {
        if (p.category !== cat) { cat = p.category; if (cat) list.appendChild(el('p', 'mqq-cat', cat)); }
        var row = el('div', 'mqq-item' + (qty[p.id] ? ' is-on' : ''));
        row.setAttribute('role', 'listitem');
        var name = el('span', 'mqq-item-name');
        name.appendChild(el('strong', '', p.name));
        name.appendChild(el('small', '', peso(p.price) + ' / ' + p.unit));
        var box2 = el('span', 'mqq-qty');
        var minus = el('button', '', '−'); minus.type = 'button'; minus.setAttribute('aria-label', 'Less ' + p.name);
        var input = el('input'); input.type = 'number'; input.min = 0; input.step = 1; input.value = qty[p.id] || 0; input.setAttribute('aria-label', 'Quantity of ' + p.name);
        var plus = el('button', '', '+'); plus.type = 'button'; plus.setAttribute('aria-label', 'More ' + p.name);
        var set = function (v) {
          v = Math.max(0, Math.min(100000, Math.floor(Number(v) || 0)));
          if (v) qty[p.id] = v; else delete qty[p.id];
          input.value = v;
          row.classList.toggle('is-on', v > 0);
          sum();
        };
        minus.addEventListener('click', function () { set((qty[p.id] || 0) - 1); });
        plus.addEventListener('click', function () { set((qty[p.id] || 0) + 1); });
        input.addEventListener('change', function () { set(input.value); });
        box2.appendChild(minus); box2.appendChild(input); box2.appendChild(plus);
        row.appendChild(name); row.appendChild(box2);
        list.appendChild(row);
      });
    }
    search.addEventListener('input', drawList);

    // Custom items
    function addCustom() {
      if (customList.children.length >= 10) return;
      var row = el('div', 'mqq-crow');
      var what = el('input'); what.type = 'text'; what.maxLength = 200; what.placeholder = 'What do you need? e.g. Tarpaulin 3×6 ft'; what.setAttribute('aria-label', 'What do you need');
      var q = el('input'); q.type = 'number'; q.min = 1; q.value = 1; q.setAttribute('aria-label', 'Quantity');
      var details = el('textarea'); details.rows = 2; details.maxLength = 1500; details.placeholder = 'Details: size, material, colors, finishing, design ready or not…'; details.setAttribute('aria-label', 'Details');
      var x = el('button', 'mqq-crow-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Remove this item');
      x.addEventListener('click', function () { row.remove(); sum(); });
      [what, q, details].forEach(function (i) { i.addEventListener('input', sum); });
      row.appendChild(what); row.appendChild(q); row.appendChild(details); row.appendChild(x);
      customList.appendChild(row);
      what.focus();
      sum();
    }
    $('.mqq-add-custom').addEventListener('click', addCustom);

    function fixed() {
      return Object.keys(qty).map(function (id) { return { id: Number(id), qty: qty[id] }; });
    }
    function custom() {
      return Array.prototype.map.call(customList.children, function (r) {
        var f = r.querySelectorAll('input, textarea');
        return { name: f[0].value.trim(), qty: Number(f[1].value) || 1, details: f[2].value.trim() };
      }).filter(function (c) { return c.name; });
    }
    function sum() {
      var total = 0;
      var n = 0;
      items().forEach(function (p) { if (qty[p.id]) { total += p.price * qty[p.id]; n++; } });
      var c = custom().length;
      var parts = [];
      if (n) parts.push(n + (n === 1 ? ' item · ' : ' items · ') + peso(total));
      if (c) parts.push(c + ' custom (we price it)');
      sumText.textContent = parts.length ? parts.join(' + ') : 'Nothing added yet';
      next.disabled = !n && !c;
    }

    next.addEventListener('click', function () {
      if (filesIn.files.length > cfg.maxFiles) { window.alert('Please attach at most ' + cfg.maxFiles + ' files.'); return; }
      for (var i = 0; i < filesIn.files.length; i++) {
        var f = filesIn.files[i];
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (cfg.types && cfg.types.length && cfg.types.indexOf(ext) === -1) { window.alert(f.name + ' is not an accepted file type (' + cfg.types.join(', ').toUpperCase() + ').'); return; }
        if (cfg.maxMb && f.size > cfg.maxMb * 1048576) { window.alert(f.name + ' is larger than ' + cfg.maxMb + ' MB. Please message us for large files.'); return; }
      }
      show(2);
      var first = box.querySelector('[name=name]');
      if (first) first.focus({ preventScroll: true });
    });
    $('.mqq-back').addEventListener('click', function () { say(''); show(1); });

    function loadSpamCheck() {
      if (window.turnstile || !modal.querySelector('.cf-turnstile') || document.querySelector('script[src*="challenges.cloudflare.com/turnstile"]')) return;
      var sc = document.createElement('script');
      sc.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
      sc.async = true;
      document.head.appendChild(sc);
    }

    box.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var v = function (n) { var f = box.querySelector('[name=' + n + ']'); return f ? f.value.trim() : ''; };
      if (v('name').length < 2) { say('Please enter your name.'); return box.querySelector('[name=name]').focus(); }
      if (!/^\S+@\S+\.\S+$/.test(v('email'))) { say('Please enter a valid email address — we send your quotation there.'); return box.querySelector('[name=email]').focus(); }
      if (v('phone').replace(/\D/g, '').length < 7) { say('Please enter your mobile number.'); return box.querySelector('[name=phone]').focus(); }
      var consent = box.querySelector('[name=consent]');
      if (consent && !consent.checked) { say('Please tick the box to agree to how we use your details.'); return consent.focus(); }

      var data = new FormData();
      ['name', 'email', 'phone', 'company', 'needed_by', 'notes', 'website'].forEach(function (n) { data.append(n, v(n)); });
      var ts = box.querySelector('[name="cf-turnstile-response"]');
      if (ts) data.append('cf-turnstile-response', ts.value);
      if (consent && consent.checked) data.append('consent', '1');
      data.append('brand_id', brandSel.value);
      data.append('fixed', JSON.stringify(fixed()));
      data.append('custom', JSON.stringify(custom()));
      Array.prototype.forEach.call(filesIn.files, function (f) { data.append('files[]', f, f.name); });

      busy = true;
      submit.disabled = true;
      submit.textContent = 'Sending…';
      say('');
      fetch(cfg.endpoint, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'omit' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok && res.j.ok) done(res.j);
          else say(res.j.message || 'Your request could not be sent. Please try again or message us.');
        })
        .catch(function () { say('Connection problem. Please check your internet and try again.'); })
        .then(function () { busy = false; submit.disabled = false; submit.textContent = 'Get my quotation'; });
    });

    function done(r) {
      var d = $('.mqq-done');
      d.innerHTML = '';
      var icon = el('div', 'mqq-done-icon' + (r.pricing ? ' is-wait' : ''));
      icon.innerHTML = r.pricing
        ? '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
        : '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
      d.appendChild(icon);
      d.appendChild(el('h3', '', r.pricing ? 'Thank you! We\'ll get back to you within 24 hours.' : 'Your quotation is ready'));
      d.appendChild(el('p', '', r.pricing
        ? 'Our team is pricing your custom items. We will email your quotation to ' + r.email + '.'
        : 'We also emailed it to ' + r.email + '. Valid for 15 days.'));
      d.appendChild(el('span', 'mqq-ref', r.number));
      var t = el('table', 'mqq-table');
      r.items.forEach(function (i) {
        var tr = el('tr');
        tr.appendChild(el('td', '', i.description + ' × ' + i.qty + ' ' + i.unit));
        var a = el('td', i.estimate && !i.amount ? 'mqq-tbp' : '', i.estimate && !i.amount ? 'To be priced' : peso(i.amount));
        tr.appendChild(a);
        t.appendChild(tr);
      });
      var tot = el('tr', 'is-total');
      tot.appendChild(el('td', '', r.pricing ? 'Total so far' : 'Total'));
      tot.appendChild(el('td', '', peso(r.total) + (r.pricing ? ' + custom' : '')));
      t.appendChild(tot);
      d.appendChild(t);
      var acts = el('div', 'mqq-done-actions');
      var view = el('a', 'mqq-link', r.pricing ? 'View my request' : 'View / print quotation');
      view.href = r.view_url; view.target = '_blank'; view.rel = 'noopener';
      var ok = el('button', 'mqq-done-btn', 'Done'); ok.type = 'button';
      ok.addEventListener('click', function () { reset(); close(); });
      acts.appendChild(view); acts.appendChild(ok);
      d.appendChild(acts);
      show(3);
      ok.focus({ preventScroll: true });
    }

    function reset() {
      qty = {};
      customList.innerHTML = '';
      box.reset();
      if (cfg.brand) brandSel.value = cfg.brand;
      drawList();
      sum();
      say('');
      show(1);
      if (window.turnstile) window.turnstile.reset();
    }

    function open(btn) {
      opener = btn || document.activeElement;
      modal.hidden = false;
      document.documentElement.classList.add('mqq-lock');
      loadSpamCheck();
      if (!$('.mqq-done').hidden) reset();
    }
    function close() {
      if (busy) return;
      modal.hidden = true;
      document.documentElement.classList.remove('mqq-lock');
      if (!$('.mqq-done').hidden) reset();
      if (opener && opener.focus) opener.focus({ preventScroll: true });
    }
    $('.mqq-close').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

    openers.push(open);
    drawList();
    sum();
  }

  // Buttons anywhere: a link to #mq-quote, or the class mq-quote-open (also on an Elementor widget).
  document.addEventListener('click', function (e) {
    if (!openers.length || e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var a = e.target.closest && e.target.closest('a[href*="#mq-quote"], .mq-quote-open');
    if (!a) return;
    if (a.tagName === 'A' && !a.closest('.mq-quote-open')) {
      var url;
      try { url = new URL(a.href, location.href); } catch (err) { return; }
      if (url.hash !== '#mq-quote' || url.origin !== location.origin) return;
    }
    e.preventDefault();
    openers[0](a);
  }, true);

  function scan(node) {
    if (node.matches && node.matches('.mqq')) init(node);
    if (node.querySelectorAll) Array.prototype.forEach.call(node.querySelectorAll('.mqq'), init);
  }
  function boot() {
    scan(document);
    if (location.hash === '#mq-quote' && openers.length) openers[0]();
    if (window.MutationObserver) {
      new MutationObserver(function (rs) { rs.forEach(function (r) { Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) scan(n); }); }); })
        .observe(document.documentElement, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
