/* Customer upload form ([mq_upload]).
 * Step 1: customers click or drop files on the big box.
 * Step 2: a pop-up opens with the chosen files (remove / add more) and the details form.
 * Step 3: the same pop-up shows the reference number.
 * Buttons that link to #mq-upload (or have the class mq-upload-open) open the pop-up straight away,
 * with the upload box at the top of it.
 * Each form carries its own settings (data-* attributes), so it works even when caching or
 * optimisation plugins move or delay scripts, and forms that appear later (pop-ups, page
 * builders) are picked up too. Without this script the form still posts normally. */
(function () {
  if (window.__mqpUploadBooted) return;
  window.__mqpUploadBooted = true;

  var MAX_FILES = 20;
  var openers = []; // One per form; buttons open the first one on the page.

  function size(b) { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function ext(name) { return (name.split('.').pop() || '').toLowerCase(); }

  function init(form) {
    if (form.dataset.mqpReady) return;
    form.dataset.mqpReady = '1';
    form.classList.add('is-js');

    var cfg = {
      endpoint: form.dataset.endpoint || form.action,
      maxBytes: Number(form.dataset.max || 0),
      types: form.dataset.types ? form.dataset.types.split(',') : [],
      success: form.dataset.success || 'Thank you! We received your files.',
      title: form.dataset.title || '',
      popupTitle: form.dataset.popupTitle || form.dataset.title || '',
    };
    var modal = form.querySelector('.mqp-modal');
    var $ = function (sel) { return modal.querySelector(sel); };
    var drop = form.querySelector('.mqp-drop-main'); // The box on the page (none with box="no").
    var input = drop && drop.querySelector('input[type=file]');
    var mini = $('.mqp-drop-mini'); // The box inside the pop-up.
    var miniInput = mini.querySelector('input[type=file]');
    var title = $('.mqp-details-title');
    var picked = $('.mqp-picked');
    var count = $('.mqp-picked-count');
    var list = $('.mqp-file-list');
    var addBtn = $('.mqp-add');
    var brand = $('.mqp-brand');
    var branch = $('.mqp-branch');
    var branchWrap = $('.mqp-branch-wrap');
    var msg = $('.mqp-msg');
    var bar = $('.mqp-progress');
    var submit = $('.mqp-submit');
    var sent = $('.mqp-sent');
    var files = [];
    var busy = false;

    // The pop-up lives at the end of the page, so see-through or blurred panels around the
    // form can't trap it, and it covers the whole screen.
    modal.classList.add('is-floating');
    document.body.appendChild(modal);

    function say(text, kind) {
      msg.className = 'mqp-msg' + (kind ? ' is-' + kind : '');
      msg.textContent = text || '';
    }

    function syncBranches() {
      if (!brand || !branch) return;
      var opt = brand.options[brand.selectedIndex];
      var show = opt && opt.dataset.branches === '1';
      var first = null;
      Array.prototype.forEach.call(branch.options, function (o) {
        var match = o.dataset.brand === brand.value;
        o.hidden = !match;
        o.disabled = !match;
        if (match && !first) first = o;
      });
      if (branch.selectedOptions[0] && branch.selectedOptions[0].disabled && first) branch.value = first.value;
      branchWrap.hidden = !show || !first;
    }
    if (brand) brand.addEventListener('change', syncBranches);
    syncBranches();

    function render() {
      picked.hidden = !files.length;
      modal.classList.toggle('has-files', files.length > 0);
      if (!modal.classList.contains('is-sent')) title.textContent = files.length ? cfg.title : cfg.popupTitle;
      list.innerHTML = '';
      files.forEach(function (f, i) {
        var li = document.createElement('li');
        var badge = document.createElement('span');
        badge.className = 'mqp-file-icon';
        badge.textContent = ext(f.name).toUpperCase().slice(0, 4) || 'FILE';
        var name = document.createElement('span');
        name.className = 'mqp-file-name';
        var strong = document.createElement('strong');
        strong.textContent = f.name;
        var small = document.createElement('small');
        small.textContent = size(f.size);
        name.appendChild(strong);
        name.appendChild(small);
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'mqp-file-remove';
        rm.setAttribute('aria-label', 'Remove ' + f.name);
        rm.textContent = '×';
        rm.addEventListener('click', function () {
          files.splice(i, 1);
          say('');
          render(); // With no files left, the upload box shows again.
        });
        li.appendChild(badge);
        li.appendChild(name);
        li.appendChild(rm);
        list.appendChild(li);
      });
      var total = files.reduce(function (a, f) { return a + f.size; }, 0);
      count.textContent = files.length + (files.length === 1 ? ' file' : ' files') + ' ready · ' + size(total);
      if (!busy) submit.textContent = files.length > 1 ? 'Upload ' + files.length + ' files' : 'Upload file';
      addBtn.hidden = files.length >= MAX_FILES;
    }

    function open() {
      if (modal.classList.contains('is-open')) return;
      modal.classList.add('is-open');
      document.documentElement.classList.add('mqp-lock');
      loadSpamCheck();
      var first = $('input[name=name]');
      setTimeout(function () {
        var el = !files.length ? miniInput : (first && !first.value ? first : submit);
        el.focus({ preventScroll: true });
      }, 80);
    }

    // The pop-up-only form (on every page) loads the spam check when it is first opened.
    function loadSpamCheck() {
      if (window.turnstile || !$('.cf-turnstile') || document.querySelector('script[src*="challenges.cloudflare.com/turnstile"]')) return;
      var sc = document.createElement('script');
      sc.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
      sc.async = true;
      document.head.appendChild(sc);
    }

    // Closing before sending puts the files back (details typed so far are kept).
    function close() {
      if (busy) return;
      var wasSent = modal.classList.contains('is-sent');
      modal.classList.remove('is-open', 'is-sent');
      document.documentElement.classList.remove('mqp-lock');
      sent.hidden = true;
      files = [];
      say('');
      if (wasSent) {
        form.reset();
        modal.querySelectorAll('input:not([type=hidden]), textarea').forEach(function (el) { el.value = ''; });
        syncBranches();
        if (window.turnstile) window.turnstile.reset();
      }
      render();
      if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
      else if (input) input.focus({ preventScroll: true });
      opener = null;
    }
    var opener = null; // The button that opened the pop-up gets the focus back.

    // Adds files, skipping wrong types, too-big files and duplicates; explains anything skipped.
    function add(fileList) {
      var skipped = [];
      Array.prototype.forEach.call(fileList, function (f) {
        if (cfg.types.length && cfg.types.indexOf(ext(f.name)) === -1) skipped.push(f.name + ' is not an accepted file type (' + cfg.types.join(', ').toUpperCase() + ').');
        else if (cfg.maxBytes && f.size > cfg.maxBytes) skipped.push(f.name + ' is too large (' + size(f.size) + '). Please message us for large files.');
        else if (files.length >= MAX_FILES) skipped.push('You can send up to ' + MAX_FILES + ' files at a time.');
        else if (!files.some(function (x) { return x.name === f.name && x.size === f.size; })) files.push(f);
      });
      var text = skipped.filter(function (t, i, a) { return a.indexOf(t) === i; }).join(' ');
      render();
      if (files.length || modal.classList.contains('is-open')) {
        open();
        say(text, text ? 'error' : '');
      } else if (text) {
        window.alert(text); // Nothing usable was chosen, so there is no pop-up to show it in.
      }
    }

    [input, miniInput].forEach(function (el) {
      if (!el) return;
      el.addEventListener('change', function () {
        add(el.files);
        el.value = '';
      });
    });
    addBtn.addEventListener('click', function () { miniInput.click(); });
    $('.mqp-close').addEventListener('click', close);
    $('.mqp-again').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });

    // Drag and drop on the box, or onto the open pop-up to add more.
    function over(on) { [drop, mini].forEach(function (d) { if (d) d.classList.toggle('is-over', on); }); }
    [drop, modal].forEach(function (zone) {
      if (!zone) return;
      var depth = 0;
      zone.addEventListener('dragenter', function (e) { e.preventDefault(); depth++; over(true); });
      zone.addEventListener('dragover', function (e) { e.preventDefault(); });
      zone.addEventListener('dragleave', function () { if (--depth <= 0) { depth = 0; over(false); } });
      zone.addEventListener('drop', function (e) {
        e.preventDefault();
        depth = 0;
        over(false);
        if (e.dataTransfer && e.dataTransfer.files.length) add(e.dataTransfer.files);
      });
    });

    function send() {
      if (busy) return;
      var nameEl = $('[name=name]');
      var contactEl = $('[name=contact]');
      if (!files.length) { say('Please choose at least one file.', 'error'); return; }
      if (!nameEl.value.trim() || !contactEl.value.trim()) {
        say('Please enter your name and mobile number.', 'error');
        (nameEl.value.trim() ? contactEl : nameEl).focus();
        return;
      }
      var consentEl = $('[name=consent]');
      if (consentEl && !consentEl.checked) {
        say('Please tick the box to agree to how we use your details.', 'error');
        consentEl.focus();
        return;
      }

      // The fields are in the pop-up (moved out of the form), so collect them here.
      var data = new FormData();
      modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (el) {
        if (el.disabled || el.type === 'file' || (el.type === 'checkbox' && !el.checked)) return;
        data.append(el.name, el.value);
      });
      files.forEach(function (f) { data.append('files[]', f, f.name); });

      var xhr = new XMLHttpRequest();
      xhr.open('POST', cfg.endpoint);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', function (ev) {
        if (ev.lengthComputable) bar.firstElementChild.style.width = Math.round(ev.loaded / ev.total * 100) + '%';
      });
      function finish() {
        busy = false;
        submit.disabled = false;
        bar.hidden = true;
        render();
      }
      xhr.onload = function () {
        var res = {};
        try { res = JSON.parse(xhr.responseText); } catch (err) { /* ignore */ }
        finish();
        if (xhr.status >= 200 && xhr.status < 300 && res.ok) {
          say('');
          $('.mqp-sent-text').textContent = res.message || cfg.success;
          var ref = $('.mqp-sent-ref');
          ref.textContent = 'Your reference number';
          var b = document.createElement('b');
          b.textContent = res.reference;
          ref.appendChild(b);
          sent.hidden = false;
          modal.classList.add('is-sent');
          $('.mqp-modal-box').scrollTop = 0;
          $('.mqp-again').focus({ preventScroll: true });
        } else {
          say(res.message || 'Upload failed. Please try again or message us your files.', 'error');
        }
      };
      xhr.onerror = function () {
        finish();
        say('Connection problem. Please check your internet and try again.', 'error');
      };
      busy = true;
      submit.disabled = true;
      submit.textContent = 'Uploading…';
      bar.hidden = false;
      bar.firstElementChild.style.width = '0';
      say('');
      xhr.send(data);
    }

    submit.addEventListener('click', function (e) { e.preventDefault(); send(); });
    modal.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); send(); }
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); if (files.length) open(); });

    // Prefer a form that shows its box on the page over the hidden pop-up-only one.
    openers[form.classList.contains('is-popup-only') ? 'push' : 'unshift'](function (btn) {
      if (modal.classList.contains('is-sent')) close();
      opener = btn || null;
      open();
    });
    render();
  }

  // Header, menu or hero buttons: a link to #mq-upload / #mqp-upload, or the class mq-upload-open
  // (on the button itself or, in Elementor, on the widget around it).
  function isOpener(el) {
    var a = el.closest && el.closest('a[href*="#mq-upload"], a[href*="#mqp-upload"], .mq-upload-open');
    if (!a) return null;
    if (a.tagName === 'A' && !a.classList.contains('mq-upload-open') && !a.closest('.mq-upload-open')) {
      var url;
      try { url = new URL(a.href, location.href); } catch (err) { return null; }
      if ((url.hash !== '#mq-upload' && url.hash !== '#mqp-upload') || url.origin !== location.origin) return null;
    }
    return a;
  }
  document.addEventListener('click', function (e) {
    if (!openers.length || e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    var btn = isOpener(e.target);
    if (!btn) return;
    e.preventDefault();
    openers[0](btn);
  }, true);

  function scan(root) {
    if (root.matches && root.matches('form.mqp-upload')) init(root);
    if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll('form.mqp-upload'), init);
  }

  function boot() {
    scan(document);
    // Opened from a link on another page, e.g. https://mqprintsph.com/#mq-upload
    if ((location.hash === '#mq-upload' || location.hash === '#mqp-upload-open') && openers.length) openers[0]();
    // Pop-ups and page builders may add the form after the page has loaded.
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (r) { Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) scan(n); }); });
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
