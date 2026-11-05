// Shared helpers: config, API client, DOM builder, formatting, modals and toasts.

import { icon } from './icons.js';

export const cfg = window.MQP;

export function can(cap) {
  return !!cfg.user && cfg.user.caps.includes(cap);
}

/* ---------- API ---------- */

export class ApiError extends Error {
  constructor(message, status, offline = false) {
    super(message);
    this.status = status;
    this.offline = offline;
  }
}

async function refreshNonce() {
  const r = await fetch(cfg.ajaxUrl + '?action=mqp_nonce', { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.loggedIn) {
    location.reload();
    throw new ApiError('Your session ended. Please log in again.', 401);
  }
  cfg.nonce = j.nonce;
}

/**
 * Calls the portal REST API. `body` may be a plain object (sent as JSON) or FormData.
 */
export async function api(path, { method = 'GET', body, query, retried = false } = {}) {
  let url = cfg.restUrl + path.replace(/^\//, '');
  if (query) {
    const qs = new URLSearchParams();
    Object.entries(query).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && qs.append(k, v));
    const s = qs.toString();
    if (s) url += (url.includes('?') ? '&' : '?') + s;
  }
  // The portal session's check code, needed for changes (see MQP_Session).
  const opts = { method, credentials: 'same-origin', headers: cfg.nonce ? { 'X-MQP-Token': cfg.nonce } : {} };
  if (body instanceof FormData) {
    opts.body = body;
  } else if (body !== undefined) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(url, opts);
  } catch (e) {
    throw new ApiError('You are offline.', 0, true);
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* empty body */ }
  if (!res.ok) {
    if (res.status === 403 && data && data.code === 'rest_cookie_invalid_nonce' && !retried) {
      await refreshNonce();
      return api(path, { method, body, query, retried: true });
    }
    if (res.status === 401 && !retried) {
      await refreshNonce();
      return api(path, { method, body, query, retried: true });
    }
    throw new ApiError((data && data.message) || `Request failed (${res.status})`, res.status);
  }
  return data;
}

export function fileUrl(file, inline = false) {
  const u = new URL(file.url);
  u.searchParams.set('_wpnonce', cfg.nonce);
  if (inline) u.searchParams.set('inline', '1');
  return u.toString();
}

/**
 * A person's picture: their photo when they have one (user.avatar = version), else their initials.
 * size: '' | 'sm' | 'lg' | 'xl'.
 */
export function avatar(user, size = '') {
  const cls = 'avatar' + (size ? ' avatar-' + size : '');
  const name = (user && user.name) || '?';
  if (user && user.avatar && user.id) {
    const u = new URL(cfg.restUrl + 'avatar/' + user.id);
    u.searchParams.set('v', user.avatar);
    u.searchParams.set('_wpnonce', cfg.nonce);
    const img = h('img', { class: cls, src: u.toString(), alt: '', loading: 'lazy', decoding: 'async' });
    // If the photo can't load (e.g. removed meanwhile), fall back to initials.
    img.addEventListener('error', () => img.replaceWith(h('span', { class: cls }, initialsOf(name))));
    return img;
  }
  return h('span', { class: cls }, initialsOf(name));
}

/**
 * Large picture with "Add / Change photo" and "Remove". `endpoint` is the REST path that takes
 * POST (multipart "file") and DELETE. onChange(version) runs after each change.
 */
export function photoPicker(user, { endpoint, onChange }) {
  const wrap = h('div.photo-picker');
  const changed = v => { user.avatar = v; draw(); if (onChange) onChange(v); };
  const input = h('input', {
    type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true,
    onchange: async e => {
      const file = e.target.files[0];
      e.target.value = '';
      if (!file) return;
      if (file.size > 8 * 1024 * 1024) return toast('That photo is too large. Please use one under 8 MB.', 'bad');
      const fd = new FormData();
      fd.append('file', file);
      wrap.classList.add('is-busy');
      const res = await run(() => api(endpoint, { method: 'POST', body: fd }));
      wrap.classList.remove('is-busy');
      if (res) { changed(res.avatar); toast('Photo updated', 'ok'); }
    },
  });
  const remove = async () => {
    if (!(await confirmBox('Remove photo', 'Show initials instead of the photo?', 'Remove', true))) return;
    const res = await run(() => api(endpoint, { method: 'DELETE' }));
    if (res) { changed(null); toast('Photo removed', 'ok'); }
  };
  const draw = () => clear(wrap,
    h('button.photo-btn', { type: 'button', title: user.avatar ? 'Change photo' : 'Add a photo', 'aria-label': user.avatar ? 'Change photo' : 'Add a photo', onclick: () => input.click() },
      avatar(user, 'xl'), h('span.photo-cam', icon('camera', 16))),
    h('div.photo-actions',
      h('button.link-btn', { type: 'button', onclick: () => input.click() }, user.avatar ? 'Change photo' : 'Add photo'),
      user.avatar ? h('button.link-btn.is-danger', { type: 'button', onclick: remove }, 'Remove') : null),
    input);
  draw();
  return wrap;
}

export function initialsOf(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('') || '?';
}

/* ---------- DOM ---------- */

/**
 * h('div.card#main', { onclick, dataset, style, ...attrs }, ...children)
 * Children may be strings, nodes, arrays, null/false (skipped).
 */
export function h(tag, attrs, ...children) {
  const m = tag.match(/^([a-z0-9-]+)?((?:[.#][\w-]+)*)$/i);
  const el = document.createElement((m && m[1]) || 'div');
  if (m && m[2]) {
    m[2].replace(/([.#])([\w-]+)/g, (_, t, v) => {
      if (t === '.') el.classList.add(v); else el.id = v;
    });
  }
  // A non-object second argument (including 0 and '') is a child, not attributes.
  if (attrs !== undefined && attrs !== null && attrs !== false && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) {
    children.unshift(attrs);
    attrs = null;
  }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k === 'class') el.className += ' ' + v;
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k === 'value' || k === 'checked' || k === 'selected' || k === 'disabled' || k === 'hidden') el[k] = v;
    else el.setAttribute(k, v === true ? '' : v);
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children) {
    if (c === null || c === undefined || c === false) continue;
    if (Array.isArray(c)) append(el, c);
    else el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

/** Appends children like h() does (null/false skipped, arrays flattened). */
export function add(el, ...children) {
  append(el, children);
  return el;
}

export function clear(el, ...children) {
  el.replaceChildren();
  append(el, children);
  return el;
}

/** Form field helper: label + control. */
export function field(label, control, hint) {
  return h('label.field', h('span.field-label', label), control, hint ? h('small.hint', hint) : null);
}

export function select(name, options, value, attrs = {}) {
  return h('select', { name, ...attrs },
    options.map(o => h('option', { value: o.value, selected: String(o.value) === String(value ?? '') }, o.label)));
}

/** Reads named controls of a form into an object (checkboxes → booleans). */
export function formData(form) {
  const out = {};
  for (const el of form.elements) {
    if (!el.name) continue;
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
    else out[el.name] = el.value;
  }
  return out;
}

/* ---------- Formatting ---------- */

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
export const money = v => peso.format(Number(v) || 0);

export function fmtDate(s, withTime = false) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return s;
  const opts = { month: 'short', day: 'numeric', year: 'numeric' };
  if (withTime) Object.assign(opts, { hour: 'numeric', minute: '2-digit' });
  return d.toLocaleString('en-PH', opts);
}

export function fmtTime(s) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? s : d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}

/** Today's date (device local) as Y-m-d. */
export function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** "2h 05m" from minutes. */
export function duration(mins) {
  mins = Math.max(0, Math.round(mins || 0));
  return mins >= 60 ? `${Math.floor(mins / 60)}h ${String(mins % 60).padStart(2, '0')}m` : `${mins}m`;
}

export function fileSize(b) {
  if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB';
  return Math.max(1, Math.round(b / 1024)) + ' KB';
}

export function brandName(id) {
  const b = cfg.brands.find(x => x.id === Number(id));
  return b ? b.name : '—';
}

export function branchName(id) {
  const b = cfg.branches.find(x => x.id === Number(id));
  return b ? b.name : '';
}

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c =>
    (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
}

export const STAGES = {
  sales: 'Sales',
  creatives: 'Creatives',
  production: 'Production',
  qc: 'Quality Check',
  ready: 'Ready for Delivery',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
};

export const DOC_TYPES = {
  quotation: 'Quotation / Estimate',
  billing: 'Billing Statement',
  invoice: 'Invoice',
};

export function badge(text, kind = '') {
  return h('span.badge', { class: kind ? 'badge-' + kind : '' }, text);
}

export function stageBadge(stage) {
  return badge(STAGES[stage] || stage, 'stage-' + stage);
}

export function statusBadge(status) {
  if (status === 'pricing') return badge('Needs pricing', 'warn'); // Website quotation request with custom items.
  const kinds = { paid: 'ok', partial: 'warn', unpaid: 'bad', void: 'muted', converted: 'muted', open: 'info', completed: 'ok', pending: 'warn', earned: 'info', cancelled: 'muted', new: 'info', dismissed: 'muted' };
  return badge(status.charAt(0).toUpperCase() + status.slice(1), kinds[status] || '');
}

/* ---------- Feedback ---------- */

export function toast(message, kind = 'info') {
  let wrap = document.querySelector('.toasts');
  if (!wrap) document.body.append(wrap = h('div.toasts', { role: 'status', 'aria-live': 'polite' }));
  const t = h('div.toast', { class: 'toast-' + kind }, message);
  wrap.append(t);
  setTimeout(() => t.classList.add('out'), 3500);
  setTimeout(() => t.remove(), 4000);
}

/** Runs an async action, showing errors as a toast. Disables `button` while running. */
export async function run(fn, button) {
  if (button) button.disabled = true;
  try {
    return await fn();
  } catch (e) {
    toast(e.message || String(e), 'bad');
    return undefined;
  } finally {
    if (button) button.disabled = false;
  }
}

/** Opens a modal. `render(close)` returns the body. Resolves with the value passed to close(). */
export function modal(title, render, { wide = false } = {}) {
  return new Promise(resolve => {
    const prev = document.activeElement;
    const close = value => {
      overlay.remove();
      document.removeEventListener('keydown', onKey);
      if (prev && prev.focus) prev.focus();
      resolve(value);
    };
    const onKey = e => { if (e.key === 'Escape') close(); };
    const box = h('div.modal', { role: 'dialog', 'aria-modal': 'true', 'aria-label': title, class: wide ? 'modal-wide' : '' },
      h('header.modal-head', h('h2', title), h('button.icon-btn', { type: 'button', 'aria-label': 'Close', onclick: () => close() }, '×')),
      h('div.modal-body', render(close)));
    const overlay = h('div.overlay', { onmousedown: e => { if (e.target === overlay) close(); } }, box);
    document.body.append(overlay);
    document.addEventListener('keydown', onKey);
    const first = box.querySelector('input, select, textarea, button:not(.icon-btn)');
    if (first) first.focus();
  });
}

export function confirmBox(title, message, okLabel = 'Confirm', danger = false) {
  return modal(title, close => [
    h('p', message),
    h('div.actions', h('button.btn', { type: 'button', onclick: () => close(false) }, 'Cancel'),
      h('button.btn', { type: 'button', class: danger ? 'btn-danger' : 'btn-primary', onclick: () => close(true) }, okLabel)),
  ]);
}

/** Asks for text (e.g. a void reason). Resolves with the string or undefined. */
export function promptBox(title, label, { okLabel = 'Save', required = true, multiline = true, danger = false } = {}) {
  return modal(title, close => {
    const input = multiline ? h('textarea', { rows: 3, name: 'v' }) : h('input', { type: 'text', name: 'v' });
    const form = h('form', {
      onsubmit: e => {
        e.preventDefault();
        if (required && !input.value.trim()) { input.focus(); return; }
        close(input.value.trim());
      },
    }, field(label, input), h('div.actions',
      h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'),
      h('button.btn', { type: 'submit', class: danger ? 'btn-danger' : 'btn-primary' }, okLabel)));
    return form;
  });
}

export function empty(text) {
  return h('div.empty', text);
}

export function loading() {
  return h('div.loading', 'Loading…');
}

/** Simple table: columns = [{ label, render(row), class }] */
export function table(rows, columns, { onRow, emptyText = 'Nothing here yet.' } = {}) {
  if (!rows.length) return empty(emptyText);
  return h('div.table-wrap', h('table.table',
    h('thead', h('tr', columns.map(c => h('th', { class: c.class }, c.label)))),
    h('tbody', rows.map(r => h('tr', {
      class: onRow ? 'clickable' : '',
      tabindex: onRow ? 0 : null,
      onclick: onRow ? () => onRow(r) : null,
      onkeydown: onRow ? e => { if (e.key === 'Enter') onRow(r); } : null,
    }, columns.map(c => h('td', { class: c.class, 'data-label': c.label }, c.render(r))))))));
}

/** Sub-navigation (pills). */
export function subnav(items, active, onPick) {
  return h('nav.subnav', items.map(it => h('button', {
    type: 'button', class: it.key === active ? 'active' : '', 'aria-pressed': it.key === active ? 'true' : 'false',
    onclick: () => onPick(it.key),
  }, it.label, it.count ? h('span.count', it.count) : null)));
}
