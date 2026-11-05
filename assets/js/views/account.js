// My profile and My settings, reached from the account menu and the bottom of the side menu.
// Left: your profile card (photo, position, shop) and a section menu. Right: the section.

import { cfg, can, h, api, run, toast, field, select, formData, fmtDate, empty, photoPicker } from '../core.js';
import { icon } from '../icons.js';
import { device, askPopups, beep } from '../notifications.js';

const SECTIONS = [
  ['profile', 'Personal details', 'user', '#/profile'],
  ['access', 'What I can do', 'shield', '#/profile/access'],
  ['preferences', 'Preferences', 'settings', '#/settings'],
  ['notifications', 'Notifications', 'bell', '#/settings/notifications'],
  ['security', 'Password', 'lock', '#/settings/security'],
];

export async function render(view, params, page) {
  const p = await api('me/profile');
  const sub = page === 'settings'
    ? (params[0] === 'security' ? 'security' : params[0] === 'notifications' ? 'notifications' : 'preferences')
    : (params[0] === 'access' ? 'access' : 'profile');

  const fact = (label, value) => [h('dt', label), h('dd', value)];
  const side = h('aside.account-side',
    h('section.card.account-card',
      photoPicker(p, {
        endpoint: 'me/avatar',
        onChange: v => { cfg.user.avatar = v; window.dispatchEvent(new CustomEvent('mqp-avatar')); },
      }),
      h('h2.account-name', p.name),
      h('p.account-role', p.role_name || '—'),
      p.branch || p.brand ? h('div.account-chips',
        p.branch ? h('span.chip', icon('store', 14), p.branch) : null,
        p.brand ? h('span.chip', icon('tag', 14), p.brand) : null) : null,
      h('dl.account-meta',
        fact('Username', p.username),
        fact('Email', p.email || '—'),
        fact('Member since', fmtDate(p.member_since)))),
    h('nav.account-nav', { 'aria-label': 'My account' },
      SECTIONS.map(([k, label, ic, href]) => h('a', { href, class: k === sub ? 'active' : '', 'aria-current': k === sub ? 'page' : null }, icon(ic, 16), label))));

  const content = sub === 'notifications' ? await notifications() : { profile, access, preferences, security }[sub](p);
  view.append(h('div.account-layout', side, h('div.account-main', content)));
}

// A titled card with a short description.
const panel = (title, desc, ...body) => h('section.card.account-panel',
  h('div.panel-head', h('h3', title), desc ? h('p.muted', desc) : null), body);

// One labelled row: title + help on the left, control on the right.
const row = (title, help, control) => h('div.pref-row',
  h('div', h('div.pref-title', title), help ? h('div.pref-help', help) : null), h('div.pref-control', control));

function profile(p) {
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      const res = await run(() => api('me/profile', { method: 'POST', body: formData(form) }), form.querySelector('[type=submit]'));
      if (!res) return;
      toast('Profile saved', 'ok');
      cfg.user.name = res.name;
      cfg.user.email = res.email;
      document.querySelectorAll('.user-name, .dropdown-head strong, .account-name').forEach(el => { el.textContent = res.name; });
    },
  },
  h('div.grid-2',
    field('First name', h('input', { name: 'first_name', value: p.first_name, autocomplete: 'given-name' })),
    field('Last name', h('input', { name: 'last_name', value: p.last_name, autocomplete: 'family-name' })),
    field('Email', h('input', { name: 'email', type: 'email', value: p.email, autocomplete: 'email' }), 'Used for account emails.'),
    field('Mobile number', h('input', { name: 'phone', type: 'tel', value: p.phone, autocomplete: 'tel' }))),
  h('div.actions', h('button.btn.btn-primary', { type: 'submit' }, 'Save changes')));
  return [
    panel('Personal details', 'Your name and contact details. Your position, shop and access are set by an admin.', form),
  ];
}

function access(p) {
  const groups = {};
  p.permissions.forEach(c => { (groups[c.group] = groups[c.group] || []).push(c.label); });
  return panel('What I can do', 'Your access in the portal, from your position. Ask an admin if something is missing.',
    Object.keys(groups).length
      ? h('div.perm-groups', Object.entries(groups).map(([g, labels]) => h('div.perm-group',
        h('h4', g), h('ul.check-list', labels.map(l => h('li', icon('check', 16), l))))))
      : empty('No access yet.'));
}

function preferences(p) {
  const pages = [{ value: 'home', label: 'Home' }, { value: 'clock', label: 'Time Clock' }];
  if (can('mq_sales')) pages.push({ value: 'sales', label: 'Sales' });
  if (can('mq_creatives')) pages.push({ value: 'creatives', label: 'Creatives' });
  if (can('mq_production') || can('mq_qc') || can('mq_view_board')) pages.push({ value: 'production', label: 'Production & QC' });
  if (can('mq_pos')) pages.push({ value: 'pos', label: 'POS' });
  if (can('mq_view_dashboard') || can('mq_finance') || can('mq_manage')) pages.push({ value: 'admin', label: 'Admin' });

  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      const f = formData(form);
      const res = await run(() => api('me/preferences', { method: 'POST', body: { landing: f.landing, auto_print: f.auto_print, density: f.density } }), form.querySelector('[type=submit]'));
      if (!res) return;
      cfg.user.prefs = res;
      document.body.classList.toggle('is-compact', res.density === 'compact');
      toast('Preferences saved', 'ok');
    },
  },
  row('Start page', 'The page that opens after you log in.', select('landing', pages, p.preferences.landing)),
  row('Table spacing', 'Compact fits more rows on the screen.', select('density', [{ value: 'comfortable', label: 'Comfortable' }, { value: 'compact', label: 'Compact' }], p.preferences.density)),
  can('mq_pos') ? row('Print POS receipts automatically', 'Prints the receipt right after each sale.', h('input', { type: 'checkbox', name: 'auto_print', checked: p.preferences.auto_print, 'aria-label': 'Print POS receipts automatically' })) : null,
  h('div.actions', h('button.btn.btn-primary', { type: 'submit' }, 'Save preferences')));

  const installed = window.matchMedia('(display-mode: standalone)').matches;
  return [
    panel('Preferences', 'How the portal works for you on any device.', form),
    panel('This device', 'Sales and documents made while offline stay on this device until they sync.',
      row('Connection', null, h('span', { class: navigator.onLine ? 'text-ok' : 'text-bad' }, navigator.onLine ? 'Online' : 'Offline')),
      row('Installed as an app', installed ? null : 'Use your browser menu → "Install" or "Add to Home screen".', h('span', installed ? 'Yes' : 'No'))),
    can('mq_manage') ? panel('Portal settings', 'Company details, shops, prices, staff and permissions are in Admin.',
      h('a.btn', { href: '#/admin/settings' }, icon('shield', 16), 'Open Admin → Settings')) : null,
  ];
}

async function notifications() {
  const types = await api('me/notifications/settings');
  const groups = {};
  types.forEach(t => { (groups[t.group] = groups[t.group] || []).push(t); });
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      const body = {};
      types.forEach(t => { body[t.type] = form.elements[t.type].checked; });
      const res = await run(() => api('me/notifications/settings', { method: 'POST', body }), form.querySelector('[type=submit]'));
      if (res) toast('Notification settings saved', 'ok');
    },
  },
  Object.entries(groups).map(([g, list]) => h('div.notif-group',
    h('h4', g),
    list.map(t => h('label.check', h('input', { type: 'checkbox', name: t.type, checked: t.on }), ' ', t.label)))),
  types.length ? h('div.actions', h('button.btn.btn-primary', { type: 'submit' }, 'Save')) : empty('Nothing to choose here for your position.'));

  // Pop-ups and sound: per device (a shop computer can beep while a phone stays quiet).
  const supported = 'Notification' in window;
  const pop = h('input', {
    type: 'checkbox', checked: device.popups, disabled: !supported, 'aria-label': 'Pop-up notifications on this device',
    onchange: async e => {
      if (e.target.checked && !(await askPopups())) { e.target.checked = false; return; }
      device.popups = e.target.checked;
      toast(e.target.checked ? 'Pop-ups turned on for this device' : 'Pop-ups turned off', 'ok');
    },
  });
  const snd = h('input', {
    type: 'checkbox', checked: device.sound, 'aria-label': 'Play a sound',
    onchange: e => { device.sound = e.target.checked; if (e.target.checked) beep(); },
  });
  return [
    panel('Notifications', 'What shows under the bell at the top. You only get what your position can work on, and never your own actions.', form),
    panel('This device', 'Saved on this computer or phone only.',
      row('Pop-up notifications', supported ? 'Shows new notifications on the screen while the portal is open in the background (another tab or app).' : 'This browser cannot show pop-ups.', pop),
      row('Play a sound', 'A short chime when something new comes in. Handy at the shop counter.', snd)),
  ];
}

function security() {
  const form = h('form', {
    onsubmit: async e => {
      e.preventDefault();
      const f = formData(form);
      if (f.new !== f.confirm) return toast('The new passwords do not match.', 'bad');
      const res = await run(() => api('me/password', { method: 'POST', body: { current: f.current, new: f.new } }), form.querySelector('[type=submit]'));
      if (res) {
        toast('Password changed', 'ok');
        // The login cookie changed with the password, so reload to pick up a fresh session.
        setTimeout(() => location.reload(), 800);
      }
    },
  },
  field('Current password', h('input', { name: 'current', type: 'password', required: true, autocomplete: 'current-password' })),
  h('div.grid-2',
    field('New password', h('input', { name: 'new', type: 'password', required: true, minlength: 8, autocomplete: 'new-password' }), 'At least 8 characters.'),
    field('Confirm new password', h('input', { name: 'confirm', type: 'password', required: true, minlength: 8, autocomplete: 'new-password' }))),
  h('div.actions', h('button.btn.btn-primary', { type: 'submit' }, 'Update password')));
  return panel('Password', 'Use a password you don\'t use anywhere else. Never share it — each person logs in with their own account.', form);
}
