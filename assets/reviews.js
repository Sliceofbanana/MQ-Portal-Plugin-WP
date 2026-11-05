/* Customer reviews ([mq_reviews]): the swipeable card carousel (arrows, dots, "Read more")
 * and the "Leave a Review" pop-up. Each block carries its own settings (data-* attributes),
 * and blocks added later (pop-ups, page builders) are picked up too. */
(function () {
  if (window.__mqpReviewsBooted) return;
  window.__mqpReviewsBooted = true;

  var LABELS = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

  function carousel(root) {
    var stage = root.querySelector('.mqr-stage');
    if (!stage) return;
    var track = stage.querySelector('.mqr-track');
    var prev = stage.querySelector('.mqr-prev');
    var next = stage.querySelector('.mqr-next');
    var dots = root.querySelector('.mqr-dots');
    var cards = Array.prototype.slice.call(track.children);

    function step() { return cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : track.clientWidth; }
    function perView() { return Math.max(1, Math.round(track.clientWidth / (step() || 1))); }
    function pages() { return Math.max(1, Math.ceil(cards.length - perView() + 1)); }
    function page() { return Math.min(pages() - 1, Math.round(track.scrollLeft / (step() || 1))); }
    function go(i) { track.scrollTo({ left: Math.max(0, Math.min(pages() - 1, i)) * step() }); }

    function drawDots() {
      var n = pages();
      dots.innerHTML = '';
      stage.classList.toggle('is-static', n <= 1);
      if (n <= 1) return;
      for (var i = 0; i < n; i++) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'tab');
        b.setAttribute('aria-label', 'Show reviews ' + (i + 1));
        b.addEventListener('click', go.bind(null, i));
        dots.appendChild(b);
      }
      sync();
    }
    function sync() {
      var p = page();
      Array.prototype.forEach.call(dots.children, function (d, i) { d.setAttribute('aria-selected', String(i === p)); });
      prev.disabled = track.scrollLeft <= 8;
      next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 8;
    }

    // "Read more" only on cards whose text is cut off.
    function readMore() {
      cards.forEach(function (card) {
        var text = card.querySelector('.mqr-text');
        var more = card.querySelector('.mqr-more');
        if (card.classList.contains('is-open')) return;
        more.hidden = text.scrollHeight <= text.clientHeight + 2;
      });
    }
    cards.forEach(function (card) {
      var more = card.querySelector('.mqr-more');
      more.addEventListener('click', function () {
        var open = card.classList.toggle('is-open');
        more.textContent = open ? 'Show less' : 'Read more';
      });
      // A Google profile picture that can't load falls back to the initials.
      var img = card.querySelector('.mqr-avatar img');
      if (img) img.addEventListener('error', function () { img.remove(); card.querySelector('.mqr-initials').hidden = false; });
    });

    prev.addEventListener('click', function () { go(page() - 1); });
    next.addEventListener('click', function () { go(page() + 1); });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); go(page() + 1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(page() - 1); }
    });
    var t;
    track.addEventListener('scroll', function () { clearTimeout(t); t = setTimeout(sync, 60); }, { passive: true });
    var w = 0;
    function resize() { if (track.clientWidth === w) return; w = track.clientWidth; drawDots(); readMore(); }
    if (window.ResizeObserver) new ResizeObserver(resize).observe(track);
    else window.addEventListener('resize', resize);
    resize();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(readMore);
  }

  function form(root) {
    var openBtn = root.querySelector('.mqr-open');
    var modal = root.querySelector('.mqr-modal');
    if (!openBtn || !modal) return;
    var box = modal.querySelector('.mqr-box');
    var stepEl = modal.querySelector('.mqr-step');
    var done = modal.querySelector('.mqr-done');
    var msg = modal.querySelector('.mqr-msg');
    var submit = modal.querySelector('.mqr-submit');
    var rating = modal.querySelector('input[name=rating]');
    var stars = Array.prototype.slice.call(modal.querySelectorAll('.mqr-rate button'));
    var rateLabel = modal.querySelector('.mqr-rate-label');
    var photo = modal.querySelector('input[name=photo]');
    var preview = modal.querySelector('.mqr-photo-preview img');
    var plus = modal.querySelector('.mqr-photo-icon');
    var removePhoto = modal.querySelector('.mqr-photo-remove');
    var endpoint = root.dataset.endpoint;
    var opener = null;
    var busy = false;

    // The pop-up lives at the end of the page, so panels around the block can't trap it.
    document.body.appendChild(modal);

    function say(text) { msg.textContent = text || ''; msg.className = 'mqr-msg' + (text ? ' is-error' : ''); }
    function paint(v) { stars.forEach(function (s, i) { s.classList.toggle('is-on', i < v); }); }
    function setRating(v) {
      rating.value = v ? String(v) : '';
      stars.forEach(function (s, i) { s.setAttribute('aria-checked', String(i + 1 === v)); s.tabIndex = i + 1 === (v || 1) ? 0 : -1; });
      paint(v);
      rateLabel.textContent = v ? LABELS[v] : 'Tap a star to rate';
    }
    stars.forEach(function (s, i) {
      s.addEventListener('click', function () { setRating(i + 1); say(''); });
      s.addEventListener('mouseenter', function () { paint(i + 1); });
      s.addEventListener('keydown', function (e) {
        var d = e.key === 'ArrowRight' || e.key === 'ArrowUp' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowDown' ? -1 : 0;
        if (!d) return;
        e.preventDefault();
        var v = Math.max(1, Math.min(5, (Number(rating.value) || 0) + d));
        setRating(v);
        stars[v - 1].focus();
      });
    });
    modal.querySelector('.mqr-rate').addEventListener('mouseleave', function () { paint(Number(rating.value) || 0); });

    function showPhoto() {
      var f = photo.files && photo.files[0];
      if (f && !/^image\/(jpeg|png|webp)$/.test(f.type)) { say('The picture must be a JPG, PNG or WebP image.'); photo.value = ''; f = null; }
      else if (f && f.size > 3 * 1048576) { say('The picture is too large (3 MB at most).'); photo.value = ''; f = null; }
      if (preview.src) URL.revokeObjectURL(preview.src);
      preview.hidden = !f;
      plus.hidden = !!f;
      removePhoto.hidden = !f;
      if (f) preview.src = URL.createObjectURL(f); else preview.removeAttribute('src');
    }
    photo.addEventListener('change', showPhoto);
    removePhoto.addEventListener('click', function () { photo.value = ''; showPhoto(); });

    function loadSpamCheck() {
      if (window.turnstile || !modal.querySelector('.cf-turnstile') || document.querySelector('script[src*="challenges.cloudflare.com/turnstile"]')) return;
      var sc = document.createElement('script');
      sc.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
      sc.async = true;
      document.head.appendChild(sc);
    }

    function open() {
      opener = document.activeElement;
      modal.hidden = false;
      document.documentElement.classList.add('mqr-lock');
      loadSpamCheck();
      setTimeout(function () { (stars[(Number(rating.value) || 1) - 1]).focus({ preventScroll: true }); }, 60);
    }
    function close() {
      if (busy) return;
      modal.hidden = true;
      document.documentElement.classList.remove('mqr-lock');
      if (!done.hidden) {
        box.reset();
        setRating(0);
        showPhoto();
        done.hidden = true;
        stepEl.hidden = false;
        if (window.turnstile) window.turnstile.reset();
      }
      say('');
      if (opener && opener.focus) opener.focus({ preventScroll: true });
    }
    openBtn.addEventListener('click', open);
    modal.querySelector('.mqr-close').addEventListener('click', close);
    modal.querySelector('.mqr-close-done').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

    box.addEventListener('submit', function (e) {
      e.preventDefault();
      if (busy) return;
      var name = box.querySelector('[name=name]');
      var body = box.querySelector('[name=body]');
      if (!rating.value) { say('Please choose a star rating.'); stars[0].focus(); return; }
      if (name.value.trim().length < 2) { say('Please enter your name.'); name.focus(); return; }
      if (body.value.trim().length < 10) { say('Please write a few words about your experience.'); body.focus(); return; }

      var data = new FormData(box);
      if (!photo.files || !photo.files.length) data.delete('photo');
      busy = true;
      submit.disabled = true;
      submit.textContent = 'Sending…';
      say('');
      fetch(endpoint, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'omit' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (res.ok && res.j.ok) {
            modal.querySelector('.mqr-done-text').textContent = res.j.message;
            stepEl.hidden = true;
            done.hidden = false;
            box.scrollTop = 0;
            modal.querySelector('.mqr-close-done').focus({ preventScroll: true });
          } else {
            say(res.j.message || 'Your review could not be sent. Please try again.');
          }
        })
        .catch(function () { say('Connection problem. Please check your internet and try again.'); })
        .then(function () { busy = false; submit.disabled = false; submit.textContent = 'Send review'; });
    });
    setRating(0);
  }

  function init(root) {
    if (root.dataset.mqrReady) return;
    root.dataset.mqrReady = '1';
    carousel(root);
    form(root);
  }

  function scan(node) {
    if (node.matches && node.matches('section.mqr')) init(node);
    if (node.querySelectorAll) Array.prototype.forEach.call(node.querySelectorAll('section.mqr'), init);
  }
  function boot() {
    scan(document);
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (r) { Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) scan(n); }); });
      }).observe(document.documentElement, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
