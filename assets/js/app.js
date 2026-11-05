// MQ Portal entry: login, app shell (header, nav, account menu), routing, offline sync indicator.

import { cfg, can, h, clear, api, toast, run, confirmBox, modal, table, fmtDate, fmtTime, duration, avatar } from './core.js';
import { bell } from './notifications.js';
import { startSync, outbox, syncNow, discard, retry, forgetUser, stillSignedIn } from './offline.js';
import { icon } from './icons.js';

// Pages in the nav. Home and Time Clock are for everyone; departments follow permissions.
const TABS = [
  { key: 'home', label: 'Home', icon: 'home', caps: null, load: () => import('./views/home.js') },
  { key: 'clock', label: 'Time Clock', icon: 'clock', caps: null, load: () => import('./views/clock.js') },
  {
    key: 'sales', label: 'Sales', icon: 'sales', caps: ['mq_sales'], load: () => import('./views/sales.js'),
    children: [
      { key: 'docs', label: 'Invoice Manager' },
      { key: 'customers', label: 'Customers' },
      { key: 'orders', label: 'Online orders' },
      { key: 'uploads', label: 'Customer uploads' },
      { key: 'jobs', label: 'Job orders' },
      { key: 'commissions', label: 'My commissions' },
    ],
  },
  { key: 'creatives', label: 'Creatives', icon: 'creatives', caps: ['mq_creatives'], load: () => import('./views/creatives.js') },
  { key: 'production', label: 'Production & QC', icon: 'production', caps: ['mq_production', 'mq_qc', 'mq_view_board'], load: () => import('./views/production.js') },
  { key: 'pos', label: 'POS', icon: 'pos', caps: ['mq_pos'], load: () => import('./views/pos.js') },
  {
    key: 'admin', label: 'Admin', icon: 'admin', caps: ['mq_view_dashboard', 'mq_finance', 'mq_reviews', 'mq_manage'], load: () => import('./views/admin.js'),
    children: [
      { key: 'daily', label: 'Daily check', caps: ['mq_view_dashboard', 'mq_manage'] },
      { key: 'reports', label: 'Department reports', caps: ['mq_view_dashboard'] },
      { key: 'dashboard', label: 'Sales by brand', caps: ['mq_view_dashboard'] },
      { key: 'invoices', label: 'All invoices', caps: ['mq_view_dashboard'] },
      { key: 'commissions', label: 'Commissions', caps: ['mq_finance'] },
      { key: 'attendance', label: 'Attendance', caps: ['mq_finance'] },
      { key: 'reviews', label: 'Reviews', caps: ['mq_reviews'], count: () => cfg.pendingReviews },
      { key: 'staff', label: 'Staff', caps: ['mq_manage'] },
      { key: 'requests', label: 'Account requests', caps: ['mq_manage'], count: () => cfg.pendingSignups },
    ],
  },
];
// Portal settings: at the bottom of the menu, for admins only (Admin → Settings in the code).
const SETTINGS = { tab: 'admin', sub: 'settings', label: 'Settings', caps: ['mq_manage'] };

/** The pages of a menu group this user may open. */
function kids(t) {
  return (t.children || []).filter(c => !c.caps || c.caps.some(can));
}
// Pages reached from the account menu, not shown in the nav.
const PAGES = [
  { key: 'profile', label: 'My profile', load: () => import('./views/account.js') },
  { key: 'settings', label: 'My settings', load: () => import('./views/account.js') },
];

const app = document.getElementById('app');

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register(cfg.portalUrl + 'sw.js?v=' + encodeURIComponent(cfg.version), { scope: cfg.portalUrl }).catch(() => {});
}

/* ---------- Login ---------- */

// The MQ cube: the white logo used as a mask, painted blue on top and orange below.
const loginMark = () => h('img.login-mark', { src: cfg.assetsUrl + 'logo-color.webp', alt: 'MQ Prints', width: 72, height: 82 });

// Input with an icon on the left; password inputs get a show/hide button on the right.
function iconInput(ic, attrs) {
  const input = h('input', attrs);
  const wrap = h('div.input-icon', icon(ic, 20), input);
  if (attrs.type === 'password') {
    const eye = h('button.input-eye', { type: 'button', 'aria-label': 'Show password', title: 'Show password' }, icon('eye', 20));
    eye.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      eye.title = eye.getAttribute('aria-label');
      eye.replaceChildren(icon(show ? 'eyeOff' : 'eye', 20));
      input.focus();
    });
    wrap.append(eye);
    wrap.classList.add('has-eye');
  }
  return wrap;
}

function loginScreen() {
  const msg = h('p.login-msg', { role: 'alert' });
  const btn = h('button.btn.btn-primary.btn-block.login-submit', { type: 'submit' }, 'Sign In');
  const form = h('form.login-card', {
    onsubmit: async e => {
      e.preventDefault();
      btn.disabled = true;
      msg.textContent = '';
      try {
        await api('auth/login', { method: 'POST', body: { username: form.username.value, password: form.password.value } });
        location.reload();
      } catch (err) {
        msg.textContent = err.offline ? 'You are offline. Connect to the internet to log in.' : err.message;
        btn.disabled = false;
      }
    },
  },
  loginMark(),
  cfg.maintenance ? h('p.login-notice', icon('alert', 16), cfg.maintenance) : null,
  h('h1.login-title', 'Sign in to Your Account'),
  h('p.login-sub', 'Access your dashboard to manage and track orders.'),
  // Staff can sign in with their email or their username.
  h('label.field', h('span.field-label', 'Email'), iconInput('user', { name: 'username', autocomplete: 'username', required: true, placeholder: 'Enter your email', autocapitalize: 'none', spellcheck: 'false' })),
  h('label.field', h('span.field-label', 'Password'), iconInput('lock', { name: 'password', type: 'password', autocomplete: 'current-password', required: true, placeholder: 'Enter your password' })),
  msg, btn,
  cfg.signup && cfg.signup.enabled ? h('p.login-foot', "Don't have an account? ", h('button.link-btn', { type: 'button', onclick: signupScreen }, 'Sign up')) : null);
  loginLayout(form);
}

/*
 * Sign-in page layout: the photo (with the logo and a welcome line) beside the form on computers,
 * a photo banner on top on phones, and "Back to website" in the corner.
 */
function loginLayout(card) {
  const photo = h('section.login-photo', { style: `--photo: url("${cfg.loginPhoto || cfg.assetsUrl + 'login-photo.jpg'}")` },
    h('img.login-photo-logo', { src: cfg.assetsUrl + 'logo-white.webp', alt: '' }),
    h('div.login-photo-text',
      h('strong', 'Welcome back, team!'),
      h('span', 'Job orders, sales, production and your time clock — all in one place.')));
  const back = cfg.siteUrl ? h('a.login-back', { href: cfg.siteUrl }, icon('back', 16), 'Back to website') : null;
  clear(app, h('main.login', back, photo, h('div.login-side', card)));
}

/* New staff ask for an account; an admin approves it before it exists. */
function signupScreen() {
  const opts = cfg.signup;
  const msg = h('p.login-msg', { role: 'alert' });
  const btn = h('button.btn.btn-primary.btn-block.login-submit', { type: 'submit' }, 'Send request');
  const input = (name, label, { icon: ic, ...attrs } = {}, hint) => h('label.field', h('span.field-label', label),
    ic ? iconInput(ic, { name, ...attrs }) : h('input', { name, ...attrs }), hint ? h('small.hint', hint) : null);
  const form = h('form.login-card.signup-card', {
    onsubmit: async e => {
      e.preventDefault();
      msg.textContent = '';
      const f = Object.fromEntries(new FormData(form));
      if (f.password !== f.confirm) { msg.textContent = 'The passwords do not match.'; return; }
      btn.disabled = true;
      try {
        await api('public/signup', { method: 'POST', body: { name: f.name, username: f.username, email: f.email, phone: f.phone, role: f.role, branch_id: Number(f.branch_id), note: f.note, password: f.password, website: f.website } });
        clear(form,
          loginMark(),
          h('h1.login-title', 'Request sent'),
          h('p.login-sub', `Thanks, ${f.name.split(' ')[0]}. An admin will review your request. We'll email ${f.email} when your account is approved — then log in with the username and password you chose.`),
          h('button.btn.btn-primary.btn-block.login-submit', { type: 'button', onclick: loginScreen }, 'Back to sign in'));
      } catch (err) {
        msg.textContent = err.offline ? 'You are offline. Connect to the internet and try again.' : err.message;
        btn.disabled = false;
      }
    },
  },
  loginMark(),
  h('h1.login-title', 'Create an Account'),
  h('p.login-sub', 'An admin checks every request. Your account is only created once it is approved.'),
  input('name', 'Full name', { icon: 'user', required: true, autocomplete: 'name', placeholder: 'Enter your full name' }),
  h('div.login-row',
    input('username', 'Username', { required: true, autocomplete: 'username', minlength: 3, pattern: '[A-Za-z0-9._\\-@]+', title: 'Letters, numbers, dots, dashes or underscores — no spaces' }),
    input('phone', 'Mobile number', { type: 'tel', autocomplete: 'tel' })),
  input('email', 'Email', { icon: 'mail', type: 'email', required: true, autocomplete: 'email', placeholder: 'Enter your email' }, 'We email you when your account is approved.'),
  h('div.login-row',
    h('label.field', h('span.field-label', 'Position'), h('select', { name: 'role', required: true },
      h('option', { value: '' }, 'Choose…'), opts.positions.map(p => h('option', { value: p.value }, p.label)))),
    h('label.field', h('span.field-label', 'Shop / branch'), h('select', { name: 'branch_id' },
      h('option', { value: 0 }, '— Not at a shop —'), opts.branches.map(b => h('option', { value: b.value }, b.label))))),
  h('div.login-row',
    h('label.field', h('span.field-label', 'Password'), iconInput('lock', { name: 'password', type: 'password', required: true, minlength: 8, autocomplete: 'new-password', placeholder: 'At least 8 characters' })),
    h('label.field', h('span.field-label', 'Confirm password'), iconInput('lock', { name: 'confirm', type: 'password', required: true, minlength: 8, autocomplete: 'new-password', placeholder: 'Type it again' }))),
  h('label.field', h('span.field-label', 'Note for the admin (optional)'), h('textarea', { name: 'note', rows: 2, placeholder: 'e.g. Started Monday at EasyPrint Main' })),
  // Honeypot for bots; hidden from people and screen readers.
  h('input.hp', { name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
  msg, btn,
  h('p.login-foot', 'Already have an account? ', h('button.link-btn', { type: 'button', onclick: loginScreen }, 'Sign in')));
  loginLayout(form);
  form.querySelector('input[name=name]').focus();
}

/* ---------- Shell ---------- */

let current = { tab: null, cleanup: null };

function availableTabs() {
  return TABS.filter(t => !t.caps || t.caps.some(can));
}

function defaultTab() {
  if (cfg.user.role === 'mq_display') return 'production';
  const pref = cfg.user.prefs && cfg.user.prefs.landing;
  if (pref && availableTabs().some(t => t.key === pref)) return pref;
  return 'home';
}

function shell() {
  const tabs = availableTabs();

  const syncEl = h('button.sync', { type: 'button', onclick: showOutbox, title: 'Connection & offline queue' });
  const clockEl = h('a.clock-pill', { href: '#/clock', title: 'Time clock' });
  const menuBtn = h('button.icon-btn.menu-toggle', { type: 'button', 'aria-label': 'Open menu', onclick: () => sidebarOpen(true) }, icon('menu', 22));

  // Account menu (avatar dropdown)
  const dropdown = h('div.dropdown', { role: 'menu', hidden: true },
    h('div.dropdown-head',
      avatar(cfg.user, 'lg'),
      h('div', h('strong', cfg.user.name), h('small', cfg.user.role_name || ''), h('small', cfg.user.email || ''))),
    h('a.dropdown-item', { href: '#/profile', role: 'menuitem' }, icon('user'), 'My profile'),
    h('a.dropdown-item', { href: '#/settings', role: 'menuitem' }, icon('settings'), 'My settings'),
    h('a.dropdown-item', { href: '#/clock', role: 'menuitem' }, icon('clock'), 'Time clock'),
    can('mq_manage') ? h('a.dropdown-item', { href: '#/admin/settings', role: 'menuitem' }, icon('shield'), 'Portal settings') : null,
    h('div.dropdown-sep'),
    h('button.dropdown-item.danger', { type: 'button', role: 'menuitem', onclick: logout }, icon('logout'), 'Log out'));
  const userBtn = h('button.user', {
    type: 'button', 'aria-haspopup': 'menu', 'aria-expanded': 'false',
    onclick: e => { e.stopPropagation(); toggleMenu(dropdown.hidden); },
  },
  avatar(cfg.user),
  h('span.user-text', h('span.user-name', cfg.user.name), h('span.user-role', cfg.user.role_name || '')),
  icon('chevron', 16));
  const toggleMenu = open => {
    dropdown.hidden = !open;
    userBtn.setAttribute('aria-expanded', String(open));
  };
  document.addEventListener('click', e => { if (!dropdown.hidden && !dropdown.contains(e.target)) toggleMenu(false); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { toggleMenu(false); sidebarOpen(false); } });
  dropdown.addEventListener('click', e => { if (e.target.closest('a')) toggleMenu(false); });

  // Side navigation. On phones and small tablets it slides in from the left.
  const sidebar = h('aside.sidebar', { 'aria-label': 'Main menu', onclick: e => { if (e.target.closest('a')) sidebarOpen(false); } },
    h('div.side-head',
      h('a.brand', { href: '#/home', 'aria-label': 'MQ Prints Staff Portal — home' },
        h('img', { src: cfg.assetsUrl + 'logo-white.webp', alt: 'MQ Prints' }), h('span', 'Staff Portal')),
      h('button.icon-btn.side-close', { type: 'button', 'aria-label': 'Close menu', onclick: () => sidebarOpen(false) }, icon('close', 20))),
    h('nav.side-nav', tabs.map(t => (kids(t).length ? sideGroup(t) : h('a', { href: '#/' + t.key, dataset: { tab: t.key } }, icon(t.icon), h('span', t.label))))),
    // My settings and Log out are in the account menu (top right).
    h('div.side-foot',
      h('a', { href: '#/profile', dataset: { tab: 'profile' } }, icon('user'), h('span', 'My profile')),
      SETTINGS.caps.some(can) ? h('a', { href: '#/admin/settings', dataset: { tab: SETTINGS.tab, sub: SETTINGS.sub } }, icon('settings'), h('span', SETTINGS.label)) : null));
  const backdrop = h('div.side-backdrop', { onclick: () => sidebarOpen(false) });
  function sidebarOpen(open) {
    document.body.classList.toggle('side-open', open);
  }

  // Maintenance (set in wp-admin): administrators keep working but get a reminder.
  const maintBar = cfg.maintenanceOn ? h('div.maint-bar', icon('alert', 16), 'Maintenance mode is on: staff cannot use the portal. ',
    cfg.systemUrl ? h('a', { href: cfg.systemUrl }, 'Turn it off') : null) : null;

  const header = h('header.topbar',
    menuBtn,
    h('div.page-title', h('strong#page-title'), h('small', new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }))),
    h('div.top-right', clockEl, syncEl, bell(), h('div.user-wrap', userBtn, dropdown)));

  const main = h('main.content', { id: 'view' });
  clear(app, h('div.layout', sidebar, backdrop, h('div.main-col', maintBar, header, main)));
  document.body.classList.toggle('is-compact', !!cfg.user.prefs && cfg.user.prefs.density === 'compact');

  window.addEventListener('hashchange', route);
  window.addEventListener('mqp-sync', () => updateSync(syncEl));
  // Account requests or reviews handled: update the counts in the menu.
  window.addEventListener('mqp-signups', sideCounts);
  // Profile photo changed: swap the pictures in the top bar and account menu.
  window.addEventListener('mqp-avatar', () => {
    userBtn.querySelector('.avatar').replaceWith(avatar(cfg.user));
    dropdown.querySelector('.avatar').replaceWith(avatar(cfg.user, 'lg'));
  });
  window.addEventListener('mqp-clock', e => { cfg.user.clock = e.detail; updateClock(clockEl); });
  updateSync(syncEl);
  updateClock(clockEl);
  setInterval(() => updateClock(clockEl), 30000);
  startSync();
  route();
}

/** Header pill: Off duty / On duty 2h 05m / On break 12m. */
function updateClock(el) {
  const c = cfg.user.clock || { state: 'off' };
  const sinceMins = c.since ? (Date.now() - new Date(String(c.since).replace(' ', 'T')).getTime()) / 60000 : 0;
  let label;
  if (c.state === 'working') {
    // worked_minutes was correct at page load; add the time since then.
    const loaded = c.loadedAt || (c.loadedAt = Date.now());
    label = 'On duty · ' + duration(c.worked_minutes + (Date.now() - loaded) / 60000);
  } else if (c.state === 'break') {
    label = 'On break · ' + duration(sinceMins);
  } else {
    label = 'Off duty — clock in';
  }
  el.className = 'clock-pill is-' + c.state;
  clear(el, h('span.dot'), h('span', label));
}

async function updateSync(el) {
  const ops = await outbox();
  const failed = ops.filter(o => o.error).length;
  const online = navigator.onLine;
  el.className = 'sync ' + (!online ? 'is-offline' : failed ? 'is-error' : ops.length ? 'is-pending' : 'is-ok');
  el.textContent = !online
    ? `Offline${ops.length ? ' · ' + ops.length : ''}`
    : failed ? `${failed} failed` : ops.length ? `Syncing ${ops.length}` : 'Online';
}

/* ---------- Menu groups (Sales, Admin) ---------- */

// Groups the user opened or closed (kept on this device only).
const openGroups = new Set((() => { try { return JSON.parse(localStorage.getItem('mqp_side_open') || '[]'); } catch (e) { return []; } })());
const saveGroups = () => { try { localStorage.setItem('mqp_side_open', JSON.stringify([...openGroups])); } catch (e) { /* private window */ } };

function sideGroup(t) {
  const id = 'side-sub-' + t.key;
  const wrap = h('div.side-group', { dataset: { group: t.key } });
  const btn = h('button.side-group-btn', {
    type: 'button', 'aria-expanded': 'false', 'aria-controls': id, dataset: { tab: t.key },
    onclick: () => { setGroup(wrap, !wrap.classList.contains('is-open')); },
  }, icon(t.icon), h('span', t.label), h('span.side-badge', { hidden: true }), h('span.side-caret', icon('chevron', 14)));
  const sub = h('div.side-sub', { id, role: 'group', 'aria-label': t.label },
    kids(t).map(c => h('a', { href: '#/' + t.key + '/' + c.key, dataset: { tab: t.key, sub: c.key } }, h('span', c.label), c.count ? h('span.side-badge', { hidden: true }) : null)));
  wrap.append(btn, sub);
  if (openGroups.has(t.key)) setGroup(wrap, true, false);
  return wrap;
}

function setGroup(wrap, open, remember = true) {
  wrap.classList.toggle('is-open', open);
  wrap.querySelector('.side-group-btn').setAttribute('aria-expanded', String(open));
  if (!remember) return;
  if (open) openGroups.add(wrap.dataset.group); else openGroups.delete(wrap.dataset.group);
  saveGroups();
}

// Waiting counts: on each page in a group, and their total on the group (useful while it is closed).
function sideCounts() {
  TABS.forEach(t => {
    const wrap = document.querySelector('.side-group[data-group="' + t.key + '"]');
    if (!wrap) return;
    let total = 0;
    kids(t).forEach(c => {
      if (!c.count) return;
      const n = c.count() || 0;
      total += n;
      const b = wrap.querySelector('a[data-sub="' + c.key + '"] .side-badge');
      if (b) { b.hidden = !n; b.textContent = String(n); }
    });
    const gb = wrap.querySelector('.side-group-btn .side-badge');
    gb.hidden = !total;
    gb.textContent = String(total);
    gb.title = total + ' waiting';
  });
}

async function route() {
  const parts = location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
  let key = parts[0];
  const page = availableTabs().find(t => t.key === key) || PAGES.find(p => p.key === key);
  if (!page) {
    key = defaultTab();
    location.replace('#/' + key);
    return;
  }
  // Sales and Admin: each page has its own address (#/sales/customers…); the group alone opens the first.
  const pages = page.children ? kids(page) : [];
  if (pages.length && !parts[1]) {
    location.replace('#/' + key + '/' + pages[0].key);
    return;
  }
  const subKey = pages.length ? parts[1] : '';
  const child = pages.find(c => c.key === subKey) || (key === SETTINGS.tab && subKey === SETTINGS.sub ? SETTINGS : null);
  document.querySelectorAll('.sidebar a[data-tab]').forEach(a => a.classList.toggle('active', a.dataset.tab === key && (a.dataset.sub || '') === (a.dataset.sub ? subKey : '')));
  document.querySelectorAll('.sidebar .side-group').forEach(g => {
    const here = g.dataset.group === key && !!child && child !== SETTINGS;
    g.classList.toggle('has-active', here);
    if (here && !g.classList.contains('is-open')) setGroup(g, true);
  });
  sideCounts();
  const title = child ? child.label : page.label;
  document.getElementById('page-title').textContent = title;
  document.title = title + ' · MQ Portal';
  const view = document.getElementById('view');
  if (current.cleanup) { try { current.cleanup(); } catch (e) { /* ignore */ } }
  const token = {};
  current = { tab: key, cleanup: null, token };
  // Each visit renders into its own pane, so a slow view that finishes after the user
  // has moved on only writes into a detached element.
  const pane = h('div.pane');
  clear(view, pane);
  window.scrollTo(0, 0);
  // While the view loads its data, a CSS ::after "Loading…" stays below whatever it has drawn.
  view.classList.add('is-loading');
  try {
    const mod = await page.load();
    if (current.token !== token) return;
    const cleanup = await mod.render(pane, parts.slice(1), key) || null;
    if (current.token === token) current.cleanup = cleanup;
    else if (cleanup) cleanup(); // The user already moved on; stop this view's timers.
  } catch (e) {
    if (current.token === token) clear(pane, h('div.empty', 'Could not load this section: ' + e.message));
  } finally {
    if (current.token === token) view.classList.remove('is-loading');
  }
}

/* ---------- Log out ---------- */

async function logout() {
  const pending = await outbox();
  if (pending.length && !(await confirmBox('Unsynced records', `${pending.length} record(s) on this device have not synced yet. They will sync the next time you log in on this device. Log out anyway?`, 'Log out'))) return;

  let clockOut = false;
  if (cfg.user.clock && cfg.user.clock.state !== 'off') {
    const choice = await modal('You are still clocked in', close => [
      h('p', `You clocked in at ${fmtTime(cfg.user.clock.shift.time_in)}. Do you want to clock out too?`),
      h('div.actions',
        h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'),
        h('button.btn', { type: 'button', onclick: () => close('stay') }, 'Log out only'),
        h('button.btn.btn-primary', { type: 'button', onclick: () => close('out') }, 'Clock out & log out')),
    ]);
    if (!choice) return;
    clockOut = choice === 'out';
  }
  await run(async () => {
    await api('auth/logout', { method: 'POST', body: { clock_out: clockOut } });
    await forgetUser(cfg.user.id);
    location.reload();
  });
}

/* ---------- Offline queue ---------- */

async function showOutbox() {
  const ops = await outbox();
  const labels = { pos_sale: 'POS sale', document: 'Document', payment: 'Payment' };
  modal('Connection & offline queue', close => {
    const body = h('div');
    if (!ops.length) {
      body.append(h('p', navigator.onLine ? 'You are online and everything is synced.' : 'You are offline. Sales and documents you make now are saved on this device and sync automatically when you are back online.'));
    } else {
      body.append(h('p.hint', 'These records are saved on this device and will be sent automatically when online.'),
        table(ops, [
          { label: 'Type', render: o => labels[o.kind] || o.kind },
          { label: 'Created', render: o => fmtDate(o.created_at, true) },
          { label: 'Status', render: o => o.error ? h('span.text-bad', o.error) : 'Waiting' },
          { label: '', render: o => o.error ? h('span.row-actions',
            h('button.btn.btn-sm', { type: 'button', onclick: async () => { await retry(o.id); close(); } }, 'Retry'),
            h('button.btn.btn-sm.btn-danger', { type: 'button', onclick: async () => { if (await confirmBox('Discard record', 'This record will be deleted from this device and never synced.', 'Discard', true)) { await discard(o.id); close(); } } }, 'Discard')) : '' },
        ]));
    }
    body.append(h('div.actions', h('button.btn.btn-primary', { type: 'button', onclick: () => { syncNow(); toast('Syncing…'); close(); } }, 'Sync now')));
    return body;
  }, { wide: true });
}

/* ---------- Boot (last, after all module-level state is initialised) ---------- */

/* Maintenance (wp-admin → MQ Portal → System → Settings): staff see this instead of the portal. */
function closedScreen() {
  loginLayout(h('div.login-card',
    loginMark(),
    h('h1.login-title', 'Back soon'),
    h('p.login-sub', cfg.maintenance),
    h('button.btn.btn-primary.btn-block.login-submit', { type: 'button', onclick: () => location.reload() }, 'Try again'),
    h('p.login-foot', 'Need to switch accounts? ', h('button.link-btn', { type: 'button', onclick: async () => {
      try { await api('auth/logout', { method: 'POST' }); } catch (e) { /* already out */ }
      location.reload();
    } }, 'Sign out'))));
}

/* Opened from the offline copy after this person signed out or closed the browser: don't show their data. */
function signedOutOffline() {
  loginLayout(h('div.login-card',
    loginMark(),
    h('h1.login-title', 'You are offline'),
    h('p.login-sub', 'Connect to the internet to sign in.'),
    h('button.btn.btn-primary.btn-block.login-submit', { type: 'button', onclick: () => location.reload() }, 'Try again')));
}

if (cfg.loggedIn && !navigator.onLine && !stillSignedIn(cfg.user.id)) signedOutOffline();
else if (cfg.loggedIn && cfg.maintenance) closedScreen(); else if (cfg.loggedIn) shell(); else loginScreen();
