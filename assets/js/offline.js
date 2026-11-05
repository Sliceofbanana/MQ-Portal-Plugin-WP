// Offline support: IndexedDB key-value cache + outbox of writes that sync when online.

import { api, uuid } from './core.js';

const DB_NAME = 'mq-portal';
let dbPromise;

function db() {
  if (!dbPromise) {
    dbPromise = new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => {
        const d = req.result;
        d.createObjectStore('kv');
        d.createObjectStore('outbox', { keyPath: 'id' });
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }
  return dbPromise;
}

async function tx(store, mode, fn) {
  const d = await db();
  return new Promise((resolve, reject) => {
    const t = d.transaction(store, mode);
    const s = t.objectStore(store);
    const out = fn(s);
    t.oncomplete = () => resolve(out && 'result' in out ? out.result : out);
    t.onerror = () => reject(t.error);
  });
}

export const kvGet = key => tx('kv', 'readonly', s => s.get(key)).catch(() => undefined);

/** Removes a person's cached lists from this device (sign-out). Unsynced records are kept. */
export async function forgetUser(userId) {
  const prefix = `u${userId}:`;
  await tx('kv', 'readwrite', s => {
    const req = s.openCursor();
    req.onsuccess = () => {
      const c = req.result;
      if (!c) return;
      if (String(c.key).startsWith(prefix)) c.delete();
      c.continue();
    };
  }).catch(() => undefined);
}

/** Whether this browser still has the portal sign-in of this person (the cookie ends when the browser closes). */
export function stillSignedIn(userId) {
  return document.cookie.split(/;\s*/).includes(`mqp_portal_on=${userId}`);
}
export const kvSet = (key, value) => tx('kv', 'readwrite', s => s.put(value, key)).catch(() => undefined);

/** Per-user cache key so two staff sharing a device never see each other's cache. */
const userKey = key => `u${window.MQP.user ? window.MQP.user.id : 0}:${key}`;

/**
 * GET with offline fallback: returns { data, cached }.
 * Successful responses are stored; when offline the last stored copy is returned.
 */
export async function cachedGet(path, query) {
  const key = userKey('GET:' + path + '?' + new URLSearchParams(query || {}).toString());
  try {
    const data = await api(path, { query });
    kvSet(key, data);
    return { data, cached: false };
  } catch (e) {
    if (!e.offline) throw e;
    const data = await kvGet(key);
    if (data === undefined) throw e;
    return { data, cached: true };
  }
}

/* ---------- Device identity & POS receipt numbers ---------- */

export async function deviceId() {
  let id = await kvGet('device_id');
  if (!id) {
    const r = new Uint32Array(1);
    crypto.getRandomValues(r);
    id = r[0].toString(36).toUpperCase().padStart(6, '0').slice(-6);
    await kvSet('device_id', id);
  }
  return id;
}

/** Receipt numbers are made on the device so receipts can print while offline: DEVICE-000123. */
export async function nextReceiptNo() {
  const dev = await deviceId();
  const seq = ((await kvGet('pos_seq')) || 0) + 1;
  await kvSet('pos_seq', seq);
  return `${dev}-${String(seq).padStart(6, '0')}`;
}

/* ---------- Outbox ---------- */

export async function enqueue(kind, payload) {
  const op = { id: uuid(), kind, payload, created_at: new Date().toISOString(), user_id: window.MQP.user.id, error: null };
  await tx('outbox', 'readwrite', s => s.put(op));
  notify();
  syncNow();
  return op;
}

export async function outbox() {
  const all = (await tx('outbox', 'readonly', s => s.getAll())) || [];
  return all.filter(op => op.user_id === window.MQP.user.id).sort((a, b) => a.created_at.localeCompare(b.created_at));
}

export async function discard(id) {
  await tx('outbox', 'readwrite', s => s.delete(id));
  notify();
}

let syncing = false;
let lastSync = null;

export function syncState() {
  return { syncing, lastSync, online: navigator.onLine };
}

function notify(extra = {}) {
  window.dispatchEvent(new CustomEvent('mqp-sync', { detail: { ...syncState(), ...extra } }));
}

/** Sends pending ops in order. Failed ops (validation errors) stay with an error for review. */
export async function syncNow() {
  if (syncing || !navigator.onLine) return;
  const ops = (await outbox()).filter(op => !op.error);
  if (!ops.length) return;
  syncing = true;
  notify();
  try {
    const res = await api('sync', { method: 'POST', body: { ops: ops.map(({ id, kind, payload }) => ({ id, kind, payload })) } });
    for (const r of res.results) {
      if (r.ok) {
        await tx('outbox', 'readwrite', s => s.delete(r.id));
      } else if (!r.retry) {
        const op = ops.find(o => o.id === r.id);
        if (op) await tx('outbox', 'readwrite', s => s.put({ ...op, error: r.error }));
      }
    }
    lastSync = new Date();
    notify({ synced: res.results.filter(r => r.ok).length });
  } catch (e) {
    // Offline or server unreachable: keep everything for the next attempt.
  } finally {
    syncing = false;
    notify();
  }
}

/** Clears the error on an op so it is retried. */
export async function retry(id) {
  const op = await tx('outbox', 'readonly', s => s.get(id));
  if (op) await tx('outbox', 'readwrite', s => s.put({ ...op, error: null }));
  syncNow();
}

export function startSync() {
  window.addEventListener('online', () => { notify(); syncNow(); });
  window.addEventListener('offline', () => notify());
  setInterval(syncNow, 30000);
  syncNow();
}
