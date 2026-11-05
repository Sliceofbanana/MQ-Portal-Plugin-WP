// POS for walk-in sales at EasyPrint branches. Works offline: sales are saved on
// the device with their own receipt number and synced when back online.

import { cfg, can, h, add, clear, api, run, toast, modal, promptBox, field, select, formData, table, subnav, money, fmtDate, fmtTime, today, uuid, empty, statusBadge } from '../core.js';
import { cachedGet, enqueue, outbox, kvGet, kvSet, nextReceiptNo, deviceId } from '../offline.js';
import { printReceipt } from '../print.js';
import { printerCounter } from './daily.js';

const posBranches = () => cfg.branches.filter(b => b.active && cfg.brands.some(br => br.id === b.brand_id && br.has_branches));

async function currentBranch() {
  if (cfg.user.branch_id) return cfg.user.branch_id;
  const saved = await kvGet('pos_branch');
  const list = posBranches();
  return list.some(b => b.id === saved) ? saved : (list[0] && list[0].id);
}

export async function render(view, params) {
  const sub = params[0] || 'register';
  const branchId = await currentBranch();
  if (!branchId) return view.append(empty('No branch set up yet. Add one in Admin → Settings → Branches.'));

  const branchCtl = cfg.user.branch_id
    ? h('strong', posBranches().find(b => b.id === branchId)?.name || '')
    : select('branch', posBranches().map(b => ({ value: b.id, label: b.name })), branchId, {
      onchange: async e => { await kvSet('pos_branch', Number(e.target.value)); location.reload(); },
    });

  view.append(h('div.toolbar',
    subnav([{ key: 'register', label: 'Register' }, { key: 'sales', label: "Today's receipts" }, { key: 'summary', label: 'End of day' }, { key: 'printer', label: 'Printer counter' }], sub, k => { location.hash = '#/pos/' + k; }),
    h('div.toolbar-right', h('span.muted', 'Branch:'), branchCtl)));
  const body = h('div');
  view.append(body);

  if (sub === 'sales') return receipts(body, branchId);
  if (sub === 'summary') return summary(body, branchId);
  if (sub === 'printer') return printerCounter(body, branchId);
  return register(body, branchId);
}

/* ---------- Register ---------- */

async function register(body, branchId) {
  let products = [];
  let cached = false;
  try {
    ({ data: products, cached } = await cachedGet('pos/products'));
  } catch (e) {
    body.append(empty('Products could not be loaded: ' + e.message));
    return;
  }
  const cart = [];
  let discount = 0;
  let category = '';
  let search = '';

  const catsEl = h('div.pos-cats');
  const gridEl = h('div.pos-grid');
  const cartEl = h('div.pos-cart-lines');
  const totalsEl = h('div.pos-totals');
  const payBtn = h('button.btn.btn-primary.btn-xl', { type: 'button', onclick: pay }, 'Charge');

  const categories = [...new Set(products.map(p => p.category).filter(Boolean))];

  const renderProducts = () => {
    clear(catsEl, ['', ...categories].map(c => h('button', { type: 'button', class: c === category ? 'active' : '', onclick: () => { category = c; renderProducts(); } }, c || 'All')));
    const shown = products.filter(p => (!category || p.category === category) && (!search || p.name.toLowerCase().includes(search)));
    clear(gridEl, shown.map(p => h('button.pos-item', { type: 'button', onclick: () => addToCart(p) }, h('span.pos-item-name', p.name), h('span.pos-item-price', money(p.price) + ' / ' + p.unit))),
      // Custom prices are for admins (others are flagged for the admin when they appear).
      can('mq_price_override') ? h('button.pos-item.pos-custom', { type: 'button', onclick: customItem }, h('span.pos-item-name', '+ Custom item'), h('span.pos-item-price', 'Any amount')) : null);
  };

  const addToCart = (p, qty = 1) => {
    const line = cart.find(l => l.product_id === p.id && l.price === p.price && p.id);
    if (line) line.qty += qty; else cart.push({ product_id: p.id || null, name: p.name, price: Number(p.price), qty });
    renderCart();
  };

  async function customItem() {
    modal('Custom item', close => {
      const form = h('form', {
        onsubmit: e => {
          e.preventDefault();
          const f = formData(form);
          if (!f.name.trim() || !(Number(f.price) >= 0)) return;
          addToCart({ id: null, name: f.name.trim(), price: Number(f.price) }, Number(f.qty) || 1);
          close();
        },
      },
      field('Description', h('input', { name: 'name', required: true })),
      h('div.grid-2', field('Price (₱)', h('input', { name: 'price', type: 'number', step: '0.01', min: 0, required: true })), field('Qty', h('input', { name: 'qty', type: 'number', step: 'any', min: 0, value: 1 }))),
      h('div.actions', h('button.btn.btn-primary', { type: 'submit' }, 'Add')));
      return form;
    });
  }

  const totals = () => {
    const subtotal = cart.reduce((s, l) => s + l.qty * l.price, 0);
    const d = Math.min(subtotal, discount);
    return { subtotal, discount: d, total: Math.round((subtotal - d) * 100) / 100 };
  };

  const renderCart = () => {
    clear(cartEl, cart.length ? cart.map((l, i) => h('div.pos-line',
      h('div.pos-line-name', l.name, h('small', money(l.price))),
      h('div.qty',
        h('button', { type: 'button', 'aria-label': 'Less', onclick: () => { l.qty -= 1; if (l.qty <= 0) cart.splice(i, 1); renderCart(); } }, '−'),
        h('input', { type: 'number', min: 0, step: 'any', value: l.qty, 'aria-label': 'Quantity', onchange: e => { l.qty = Number(e.target.value) || 0; if (l.qty <= 0) cart.splice(i, 1); renderCart(); } }),
        h('button', { type: 'button', 'aria-label': 'More', onclick: () => { l.qty += 1; renderCart(); } }, '+')),
      h('div.pos-line-amt', money(l.qty * l.price)))) : h('div.empty', 'Tap items to add them.'));
    const t = totals();
    clear(totalsEl,
      h('div', h('span', 'Subtotal'), h('span', money(t.subtotal))),
      can('mq_pos_discount') ? h('div', h('span', 'Discount'), h('input.discount', { type: 'number', min: 0, step: '0.01', value: discount || '', placeholder: '0.00', onchange: e => { discount = Number(e.target.value) || 0; renderCart(); } })) : null,
      h('div.grand', h('span', 'Total'), h('span', money(t.total))));
    payBtn.disabled = !cart.length;
    payBtn.textContent = cart.length ? 'Charge ' + money(t.total) : 'Charge';
  };

  async function pay() {
    const t = totals();
    const sale = await modal('Payment — ' + money(t.total), close => {
      let method = 'cash';
      const tendered = h('input', { type: 'number', name: 'tendered', step: '0.01', min: t.total, value: t.total.toFixed(2), inputmode: 'decimal' });
      const change = h('strong');
      const ref = h('input', { name: 'reference', placeholder: 'Reference no.' });
      const refWrap = field('Reference', ref);
      refWrap.hidden = true;
      const upd = () => { change.textContent = money(Math.max(0, (Number(tendered.value) || 0) - t.total)); };
      tendered.addEventListener('input', upd);
      upd();
      const quick = [...new Set([t.total, Math.ceil(t.total / 50) * 50, Math.ceil(t.total / 100) * 100, Math.ceil(t.total / 500) * 500, 1000])].filter(v => v >= t.total).slice(0, 5);
      const methods = h('div.seg', ['cash', 'gcash', 'maya', 'card'].map(m => h('button', {
        type: 'button', class: m === 'cash' ? 'active' : '',
        onclick: e => {
          method = m;
          methods.querySelectorAll('button').forEach(b => b.classList.toggle('active', b === e.currentTarget));
          cashWrap.hidden = m !== 'cash';
          refWrap.hidden = m === 'cash';
          if (m !== 'cash') tendered.value = t.total.toFixed(2);
          upd();
        },
      }, m.toUpperCase())));
      const cashWrap = h('div', field('Cash received', tendered),
        h('div.quick', quick.map(v => h('button.btn.btn-sm', { type: 'button', onclick: () => { tendered.value = v.toFixed(2); upd(); } }, money(v)))),
        h('p', 'Change: ', change));
      const form = h('form', {
        onsubmit: e => {
          e.preventDefault();
          if ((Number(tendered.value) || 0) + 0.001 < t.total) { toast('Cash received is less than the total.', 'bad'); return; }
          close({ method, tendered: Number(tendered.value) || t.total, reference: ref.value, print: form.print.checked });
        },
      }, methods, cashWrap, refWrap,
      h('label.check', h('input', { type: 'checkbox', name: 'print', checked: !cfg.user.prefs || cfg.user.prefs.auto_print }), ' Print receipt'),
      h('div.actions', h('button.btn.btn-primary.btn-xl', { type: 'submit' }, 'Complete sale')));
      return form;
    });
    if (!sale) return;

    const payload = {
      uuid: uuid(),
      receipt_no: await nextReceiptNo(),
      device_id: await deviceId(),
      branch_id: branchId,
      items: cart.map(l => ({ product_id: l.product_id, name: l.name, qty: l.qty, price: l.price })),
      discount: t.discount,
      tendered: sale.tendered,
      method: sale.method,
      reference: sale.reference,
      sold_at: new Date().toISOString(),
    };
    let saved;
    try {
      saved = await api('pos/sales', { method: 'POST', body: payload });
      toast('Sale saved · ' + (saved.official_no || saved.receipt_no), 'ok');
    } catch (e) {
      if (!e.offline) return toast(e.message, 'bad');
      await enqueue('pos_sale', payload);
      toast('Offline — sale saved on this device and will sync.', 'warn');
      const branch = cfg.branches.find(b => b.id === branchId);
      saved = {
        ...payload, receipt_no: (branch ? branch.code + '-' : '') + payload.receipt_no, sold_at: new Date().toISOString(),
        items: payload.items.map(i => ({ ...i, amount: i.qty * i.price })), subtotal: t.subtotal, total: t.total,
        change_due: Math.max(0, sale.tendered - t.total), status: 'completed', cashier: { name: cfg.user.name },
      };
    }
    // Printing a receipt also opens the cash drawer when the thermal printer driver has "open drawer" enabled.
    if (sale.print) printReceipt(saved);
    cart.length = 0;
    discount = 0;
    renderCart();
  }

  let st;
  add(body,
    cached ? h('p.notice', 'Offline — using saved product list.') : null,
    h('div.pos',
      h('section.pos-products',
        h('input.pos-search', { type: 'search', placeholder: 'Search products…', oninput: e => { clearTimeout(st); st = setTimeout(() => { search = e.target.value.toLowerCase(); renderProducts(); }, 150); } }),
        catsEl, gridEl),
      h('aside.pos-cart',
        h('div.pos-cart-head', h('h3', 'Current sale'), h('button.btn.btn-sm', { type: 'button', onclick: () => { cart.length = 0; discount = 0; renderCart(); } }, 'Clear')),
        cartEl, totalsEl, payBtn)));
  renderProducts();
  renderCart();
}

/* ---------- Receipts of the day ---------- */

async function receipts(body, branchId) {
  let date = today();
  const listEl = h('div');
  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    const pending = (await outbox()).filter(o => o.kind === 'pos_sale' && o.payload.branch_id === branchId);
    let rows = [];
    try {
      rows = await api('pos/sales', { query: { branch_id: branchId, date } });
    } catch (e) {
      if (!e.offline) return clear(listEl, empty(e.message));
      clear(listEl, h('p.notice', 'Offline — only receipts saved on this device are shown.'));
    }
    clear(listEl,
      pending.length ? h('section.card.pending', h('h3', `Waiting to sync (${pending.length})`), table(pending, [
        { label: 'Receipt', render: o => o.payload.receipt_no },
        { label: 'Time', render: o => fmtTime(o.payload.sold_at) },
        { label: 'Total', render: o => money(o.payload.items.reduce((s, i) => s + i.qty * i.price, 0) - o.payload.discount), class: 'num' },
        { label: 'Status', render: o => o.error ? h('span.text-bad', o.error) : 'Waiting' },
      ])) : null,
      table(rows, [
        { label: 'Receipt', render: s => h('div', h('strong', s.official_no || s.receipt_no), s.official_no ? h('div.muted', s.receipt_no) : null) },
        { label: 'Time', render: s => fmtTime(s.sold_at) },
        { label: 'Items', render: s => s.items.map(i => `${Number(i.qty)}× ${i.name}`).join(', ') },
        { label: 'Method', render: s => s.method.toUpperCase() },
        { label: 'Total', render: s => money(s.total), class: 'num' },
        { label: 'Status', render: s => statusBadge(s.status) },
        { label: '', render: s => h('span.row-actions',
          h('button.btn.btn-sm', { type: 'button', onclick: () => printReceipt(s) }, 'Reprint'),
          s.status === 'completed' && can('mq_pos_void') ? h('button.btn.btn-sm.btn-danger', {
            type: 'button',
            onclick: async e => {
              const reason = await promptBox('Void ' + (s.official_no || s.receipt_no), 'Reason for voiding', { danger: true, okLabel: 'Void receipt' });
              if (reason && await run(() => api(`pos/sales/${s.id}/void`, { method: 'POST', body: { reason } }), e.currentTarget)) load();
            },
          }, 'Void') : null) },
      ], { emptyText: 'No receipts for this day.' }));
  };
  body.append(h('div.toolbar', h('h2', 'Receipts'), h('input', { type: 'date', value: date, onchange: e => { date = e.target.value; load(); } })), listEl);
  load();
}

/* ---------- End of day ---------- */

async function summary(body, branchId) {
  let date = today();
  const out = h('div');
  const load = async () => {
    const s = await run(() => api('pos/summary', { query: { branch_id: branchId, date } }));
    if (!s) return;
    clear(out,
      h('div.stats',
        h('div.stat', h('span', 'Total sales'), h('strong', money(s.total))),
        h('div.stat', h('span', 'Receipts'), h('strong', s.count)),
        h('div.stat', h('span', 'Voided'), h('strong', `${s.voided.count} · ${money(s.voided.total)}`))),
      h('div.grid-2',
        h('section.card', h('h3', 'By payment method'), table(s.by_method, [
          { label: 'Method', render: m => m.method.toUpperCase() },
          { label: 'Receipts', render: m => m.count, class: 'num' },
          { label: 'Amount', render: m => money(m.total), class: 'num' },
        ], { emptyText: 'No sales.' })),
        h('section.card', h('h3', 'Items sold'), table(s.items, [
          { label: 'Item', render: i => i.name },
          { label: 'Qty', render: i => Number(i.qty), class: 'num' },
          { label: 'Amount', render: i => money(i.amount), class: 'num' },
        ], { emptyText: 'No sales.' }))),
      h('p.muted', 'Count the cash drawer and compare it with the CASH total above. Sales still waiting to sync on a device are not included until they sync.'));
  };
  body.append(h('div.toolbar', h('h2', 'End of day — ' + fmtDate(date)), h('div.toolbar-right',
    h('input', { type: 'date', value: date, onchange: e => { date = e.target.value; load(); } }),
    h('button.btn', { type: 'button', onclick: () => window.print() }, 'Print'))), out);
  load();
}
