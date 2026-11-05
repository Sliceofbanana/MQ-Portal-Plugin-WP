// Daily check (Admin) and the printer counter (POS): helps the owner spot orders nobody recorded.
// Staff write down each printer's page counter at opening and closing; the daily check compares the
// pages printed with the pages sold, and lists voids, deleted orders and other things to ask about.

import { h, clear, api, run, toast, field, table, money, fmtDate, today, badge } from '../core.js';

/** POS → Printer counter: write down a printer's counter, see the latest ones. */
export async function printerCounter(body, branchId) {
  const listEl = h('div');
  const printerList = h('datalist#printer-names');
  const form = h('form.card.stack', {
    onsubmit: async e => {
      e.preventDefault();
      const res = await run(() => api('printer-readings', { method: 'POST', body: { branch_id: branchId, printer: form.printer.value.trim(), reading: Number(form.reading.value), note: form.note.value.trim() } }), form.querySelector('button'));
      if (res) { toast('Counter saved', 'ok'); form.reading.value = ''; form.note.value = ''; load(); }
    },
  },
  h('h3', 'Write down the printer counter'),
  h('p.muted', 'At opening and at closing, write the total page counter shown on each printer (in its menu or on its counter report). The owner compares the pages printed with the pages sold.'),
  h('div.grid-2',
    field('Printer', h('input', { name: 'printer', required: true, list: 'printer-names', placeholder: 'e.g. Epson L3210 #1' })),
    field('Page counter', h('input', { name: 'reading', type: 'number', min: 1, step: 1, required: true, inputmode: 'numeric' }))),
  field('Note (optional)', h('input', { name: 'note', placeholder: 'e.g. opening, closing, printer was reset' })),
  printerList,
  h('div.actions', h('button.btn.btn-primary', { type: 'submit' }, 'Save counter')));
  const load = async () => {
    const d = await run(() => api('printer-readings', { query: { branch_id: branchId } }));
    if (!d) return;
    clear(printerList, d.printers.map(p => h('option', { value: p })));
    clear(listEl, h('section.card', h('h3', 'Latest counters'), table(d.readings, [
      { label: 'When', render: r => fmtDate(r.created_at, true) },
      { label: 'Printer', render: r => r.printer },
      { label: 'Counter', render: r => Number(r.reading).toLocaleString(), class: 'num' },
      { label: 'By', render: r => r.user ? r.user.name : '—' },
      { label: 'Note', render: r => r.note || '' },
    ], { emptyText: 'No counters written down yet.' })));
  };
  body.append(form, listEl);
  load();
}

/** Admin → Daily check. */
export async function dailyCheck(body, params) {
  let date = /^\d{4}-\d{2}-\d{2}$/.test(params[0] || '') ? params[0] : today();
  const out = h('div');
  const list = (title, rows, cols, emptyText) => h('section.card', h('h3', title, rows.length ? [' ', badge(String(rows.length), 'warn')] : null),
    rows.length ? table(rows, cols) : h('p.muted', emptyText));
  const load = async () => {
    clear(out, h('div.loading', 'Loading…'));
    const r = await run(() => api('admin/daily-check', { query: { date } }));
    if (!r) return clear(out);
    clear(out,
      h('p', r.flags ? badge(`${r.flags} to look at`, 'warn') : badge('Nothing unusual', 'ok')),
      h('div.grid-2',
        h('section.card', h('h3', 'Money in'), table([
          ...r.pos.map(p => ({ what: `POS ${p.shop} (${p.count} sales)`, amount: p.total })),
          ...r.payments.map(p => ({ what: `Payments ${p.method} (${p.count})`, amount: p.total })),
          { what: `Invoices issued (${r.invoices.count})`, amount: r.invoices.total },
        ], [{ label: '', render: x => x.what }, { label: 'Amount', render: x => money(x.amount), class: 'num' }])),
        h('section.card', h('h3', 'Printer counter vs pages sold'),
          r.gaps.length ? table(r.gaps, [
            { label: 'Shop', render: g => g.shop },
            { label: 'Printed', render: g => g.printed.toLocaleString(), class: 'num' },
            { label: 'Sold at POS', render: g => g.sold.toLocaleString(), class: 'num' },
            { label: 'Difference', render: g => g.gap > Math.max(20, g.sold * 0.1) ? badge((g.gap > 0 ? '+' : '') + g.gap.toLocaleString(), 'bad') : (g.gap > 0 ? '+' : '') + g.gap.toLocaleString(), class: 'num' },
          ]) : h('p.muted', 'No counters were written down this day.'),
          r.no_counter.length ? h('p.notice', 'Sold at the POS but no printer counter written down: ' + r.no_counter.join(', ')) : null,
          h('p.muted.small', 'Printed pages include job orders, reprints and test pages, so a small difference is normal. Ask about big ones.'))),
      list('Voided POS receipts', r.pos_voids, [
        { label: 'Receipt', render: v => h('div', v.what, h('div.muted', v.shop)) },
        { label: 'By', render: v => v.by }, { label: 'Reason', render: v => v.reason },
        { label: 'Amount', render: v => money(v.amount), class: 'num' }], 'None.'),
      list('Voided quotations and invoices', r.doc_voids, [
        { label: 'Document', render: v => v.what }, { label: 'Reason', render: v => v.reason },
        { label: 'Amount', render: v => money(v.amount), class: 'num' }], 'None.'),
      list('Deleted online orders', r.deleted, [{ label: 'Order', render: v => v.what }, { label: 'By', render: v => v.by }], 'None.'),
      list('Online orders still without a price (over a day old)', r.unpriced, [
        { label: 'Order', render: v => v.what }, { label: 'Placed', render: v => fmtDate(v.since, true) }], 'None.'),
      list('Job orders released with a balance', r.released, [
        { label: 'Job order', render: v => v.what }, { label: 'Unpaid', render: v => money(v.amount), class: 'num' }], 'None.'),
      r.uploads ? h('p.notice', `${r.uploads} customer upload(s) are waiting for more than a day (Sales → Customer uploads).`) : null);
  };
  body.append(
    h('div.toolbar', h('h2', 'Daily check'), h('div.toolbar-right',
      h('input', { type: 'date', value: date, max: today(), onchange: e => { date = e.target.value; history.replaceState(null, '', '#/admin/daily/' + date); load(); } }))),
    h('p.muted', 'What to ask your team about each day. The admins also get this by email every morning for the day before (Settings → Online orders → Daily check email).'),
    out);
  load();
}
