/* [mq_print_collection]: arrows, and the enlarged photo (arrows, swipe, Esc). */
(function () {
  function setup(sec) {
    if (sec.dataset.mqpc) return;
    sec.dataset.mqpc = '1';
    var track = sec.querySelector('.mqpc-track');
    var prev = sec.querySelector('.mqpc-prev');
    var next = sec.querySelector('.mqpc-next');
    var items = [].slice.call(sec.querySelectorAll('.mqpc-item'));
    if (!track) return;

    var arrows = function () {
      var more = track.scrollWidth > track.clientWidth + 2;
      prev.hidden = next.hidden = !more;
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
    };
    prev.addEventListener('click', function () { track.scrollBy({ left: -track.clientWidth, behavior: 'smooth' }); });
    next.addEventListener('click', function () { track.scrollBy({ left: track.clientWidth, behavior: 'smooth' }); });
    track.addEventListener('scroll', function () { window.requestAnimationFrame(arrows); }, { passive: true });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); next.click(); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); prev.click(); }
    });
    window.addEventListener('resize', arrows);
    arrows();

    // Slide by itself (gallery setting): one photo at a time, back to the start at the end.
    // Waits while the visitor points at or touches the photos, and when the tab is hidden.
    var every = parseInt(sec.dataset.autoplay || '0', 10);
    if (every > 0 && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
      var hold = false;
      ['mouseenter', 'touchstart', 'focusin'].forEach(function (ev) { sec.addEventListener(ev, function () { hold = true; }, { passive: true }); });
      ['mouseleave', 'touchend', 'focusout'].forEach(function (ev) { sec.addEventListener(ev, function () { setTimeout(function () { hold = false; }, ev === 'touchend' ? 4000 : 0); }, { passive: true }); });
      setInterval(function () {
        if (hold || document.hidden || !items.length || document.querySelector('.mqpc-box')) return;
        var step = items[0].getBoundingClientRect().width + parseFloat(getComputedStyle(track).columnGap || 0);
        if (track.scrollLeft + track.clientWidth >= track.scrollWidth - 2) track.scrollTo({ left: 0, behavior: 'smooth' });
        else track.scrollBy({ left: step, behavior: 'smooth' });
      }, every * 1000);
    }

    items.forEach(function (a, i) {
      a.addEventListener('click', function (e) { e.preventDefault(); open(i); });
    });

    function open(i) {
      var box = document.createElement('div');
      box.className = 'mqpc-box';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-label', 'Photo');
      box.innerHTML = '<span class="mqpc-box-count"></span><figure><img alt=""><figcaption></figcaption></figure>' +
        '<button type="button" class="mqpc-x" aria-label="Close"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>' +
        '<button type="button" class="mqpc-bprev" aria-label="Previous photo"><svg viewBox="0 0 24 24" width="26" height="26"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
        '<button type="button" class="mqpc-bnext" aria-label="Next photo"><svg viewBox="0 0 24 24" width="26" height="26"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
      var img = box.querySelector('img');
      var cap = box.querySelector('figcaption');
      var count = box.querySelector('.mqpc-box-count');
      var last = document.activeElement;
      var show = function (n) {
        i = (n + items.length) % items.length;
        var a = items[i];
        img.src = a.getAttribute('href');
        img.alt = a.querySelector('img').alt;
        cap.textContent = a.dataset.caption || '';
        cap.hidden = !a.dataset.caption;
        count.textContent = (i + 1) + ' / ' + items.length;
        if (items.length > 1) new Image().src = items[(i + 1) % items.length].getAttribute('href'); // the next one, ready
      };
      var close = function () {
        box.remove();
        document.removeEventListener('keydown', key);
        document.documentElement.style.overflow = '';
        if (last && last.focus) last.focus();
      };
      var key = function (e) {
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowRight') show(i + 1);
        if (e.key === 'ArrowLeft') show(i - 1);
      };
      box.addEventListener('click', function (e) {
        if (e.target === box || e.target.closest('.mqpc-x')) close();
        else if (e.target.closest('.mqpc-bprev')) show(i - 1);
        else if (e.target.closest('.mqpc-bnext')) show(i + 1);
      });
      var x0 = null;
      box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
      box.addEventListener('touchend', function (e) {
        if (x0 === null) return;
        var dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 50) show(dx < 0 ? i + 1 : i - 1);
        x0 = null;
      });
      if (items.length < 2) box.querySelectorAll('.mqpc-bprev, .mqpc-bnext').forEach(function (b) { b.hidden = true; });
      document.addEventListener('keydown', key);
      document.documentElement.style.overflow = 'hidden';
      document.body.appendChild(box);
      show(i);
      box.querySelector('.mqpc-x').focus();
    }
  }
  var all = function () { document.querySelectorAll('.mqpc').forEach(setup); };
  if (document.readyState !== 'loading') all(); else document.addEventListener('DOMContentLoaded', all);
  window.addEventListener('load', all);
})();
