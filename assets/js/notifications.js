// The bell in the top bar: notifications for this person (see MQP_Notify).
// Checks for new ones every 45 seconds while the portal is open, and right away when the tab
// comes back into view. New ones can also pop up on the computer/phone and play a short sound
// (My settings → Notifications; remembered per device).

import { cfg, h, clear, api, toast } from './core.js';
import { icon } from './icons.js';

const POLL_MS = 45000;
const ICONS = {
  job_creatives: 'creatives', job_production: 'production', job_qc: 'check', job_ready: 'package',
  job_mine: 'sales', upload_new: 'upload', quote_new: 'sales', quote_accepted: 'check', quote_late: 'alert', order_new: 'pos', review_new: 'star', signup_new: 'user', system_problem: 'alert',
};

/* ---------- This device: pop-ups and sound ---------- */

const store = {
  get(k) { try { return localStorage.getItem('mqp_' + k) === '1'; } catch (e) { return false; } },
  set(k, on) { try { localStorage.setItem('mqp_' + k, on ? '1' : '0'); } catch (e) { /* private mode */ } },
};
export const device = {
  get popups() { return store.get('notify_popups') && 'Notification' in window && Notification.permission === 'granted'; },
  set popups(on) { store.set('notify_popups', on); },
  get sound() { return store.get('notify_sound'); },
  set sound(on) { store.set('notify_sound', on); },
};

/** Asks the browser for permission to show pop-ups. Resolves true when allowed. */
export async function askPopups() {
  if (!('Notification' in window)) { toast('This browser cannot show notifications.', 'bad'); return false; }
  if (Notification.permission === 'denied') {
    toast('Notifications are blocked for this site. Allow them in the browser\'s site settings (the lock icon next to the address).', 'bad');
    return false;
  }
  const res = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
  return res === 'granted';
}

let audio = null;
export function beep() {
  try {
    audio = audio || new (window.AudioContext || window.webkitAudioContext)();
    const now = audio.currentTime;
    [880, 1320].forEach((f, i) => {
      const o = audio.createOscillator();
      const g = audio.createGain();
      o.type = 'sine';
      o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, now + i * 0.16);
      g.gain.exponentialRampToValueAtTime(0.18, now + i * 0.16 + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.16 + 0.22);
      o.connect(g).connect(audio.destination);
      o.start(now + i * 0.16);
      o.stop(now + i * 0.16 + 0.25);
    });
  } catch (e) { /* no sound available */ }
}

/* ---------- Opening a notification ---------- */

export async function openLink(link) {
  if (!link) return;
  if (link.startsWith('job:')) {
    const { openJob } = await import('./jobs.js');
    openJob(Number(link.slice(4)));
  } else if (link.startsWith('#')) {
    location.hash = link;
  } else if (/^https?:/.test(link)) {
    window.open(link, '_blank', 'noopener');
  }
}

function ago(s) {
  const mins = Math.max(0, (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return Math.floor(mins) + ' min ago';
  if (mins < 1440) return Math.floor(mins / 60) + ' h ago';
  if (mins < 2880) return 'Yesterday';
  return Math.floor(mins / 1440) + ' days ago';
}

/* ---------- The bell ---------- */

export function bell() {
  let items = [];
  let unread = Number(cfg.unread) || 0;
  let lastTop = null; // Newest id seen, to spot new ones.
  let timer = null;

  const count = h('span.bell-count', { hidden: true, 'aria-hidden': 'true' });
  const btn = h('button.icon-btn.bell-btn', {
    type: 'button', 'aria-haspopup': 'dialog', 'aria-expanded': 'false',
    onclick: e => { e.stopPropagation(); toggle(panel.hidden); },
  }, icon('bell', 20), count);
  const list = h('div.notif-list', { role: 'list' });
  const readAll = h('button.btn-link', { type: 'button', onclick: () => markRead({ all: true }) }, 'Mark all as read');
  const panel = h('div.notif-panel', { role: 'dialog', 'aria-label': 'Notifications', hidden: true },
    h('div.notif-head', h('strong', 'Notifications'), readAll),
    list,
    h('a.notif-foot', { href: '#/settings/notifications', onclick: () => toggle(false) }, icon('settings', 14), 'Notification settings'));
  const wrap = h('div.bell-wrap', btn, panel);

  function paintCount() {
    count.hidden = !unread;
    count.textContent = unread > 99 ? '99+' : String(unread);
    btn.setAttribute('aria-label', unread ? `Notifications, ${unread} unread` : 'Notifications');
    readAll.hidden = !unread;
    // The browser tab shows the number too.
    document.title = document.title.replace(/^\(\d+\+?\) /, '');
    if (unread) document.title = `(${unread > 99 ? '99+' : unread}) ` + document.title;
  }

  function paintList() {
    if (!items.length) {
      clear(list, h('div.notif-empty', icon('bell', 28), h('p', 'No notifications yet.'), h('small', 'You will see job orders, customer uploads and more here.')));
      return;
    }
    clear(list, items.map(n => h('button.notif-item', {
      type: 'button', role: 'listitem', class: n.read ? '' : 'is-unread',
      onclick: () => {
        if (!n.read) markRead({ ids: [n.id] });
        toggle(false);
        openLink(n.link);
      },
    },
    h('span.notif-icon', { class: 'is-' + n.type }, icon(ICONS[n.type] || 'bell', 16)),
    h('span.notif-text', h('strong', n.title), n.body ? h('span', n.body) : null, h('small', ago(n.created_at))),
    n.read ? null : h('span.notif-dot', { 'aria-label': 'Unread' }))));
  }

  async function markRead(body) {
    items.forEach(n => { if (body.all || body.ids.includes(n.id)) n.read = true; });
    unread = items.filter(n => !n.read).length;
    paintCount();
    paintList();
    try { unread = (await api('me/notifications/read', { method: 'POST', body })).unread; paintCount(); } catch (e) { /* offline: try again next time */ }
  }

  function toggle(open) {
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', String(open));
    if (open) { paintList(); load(); }
  }
  document.addEventListener('click', e => { if (!panel.hidden && !wrap.contains(e.target) && !e.target.closest('.modal')) toggle(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) toggle(false); });

  async function load() {
    if (!navigator.onLine) return;
    let res;
    try { res = await api('me/notifications'); } catch (e) { return; }
    const fresh = lastTop === null ? [] : res.items.filter(n => n.id > lastTop && !n.read);
    items = res.items;
    unread = res.unread;
    lastTop = items.length ? items[0].id : 0;
    paintCount();
    if (!panel.hidden) paintList();
    if (fresh.length) announce(fresh);
  }

  // New ones while the portal is open: a toast (or a pop-up when the tab is in the background).
  function announce(fresh) {
    const n = fresh[0];
    const more = fresh.length > 1 ? ` (+${fresh.length - 1} more)` : '';
    if (device.sound) beep();
    if (document.hidden && device.popups) {
      try {
        const pop = new Notification(n.title + more, { body: n.body, icon: cfg.assetsUrl + 'icon-192.png', tag: 'mqp-' + n.id });
        pop.onclick = () => { window.focus(); markRead({ ids: [n.id] }); openLink(n.link); pop.close(); };
      } catch (e) { /* some phones only allow pop-ups from an installed app */ }
    } else if (!document.hidden) {
      toast(n.title + more, 'info');
    }
  }

  function start() {
    clearInterval(timer);
    timer = setInterval(() => { if (!document.hidden) load(); else if (device.popups) load(); }, POLL_MS);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
  window.addEventListener('online', load);
  // Changing the page title (route()) resets the count prefix; put it back.
  window.addEventListener('hashchange', () => setTimeout(paintCount, 0));

  paintCount();
  load();
  start();
  return wrap;
}
