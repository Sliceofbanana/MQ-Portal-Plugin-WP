// Sales → Online orders: orders placed on /order/. Newer orders have no price yet: staff check the
// files, set the price on the invoice and message the customer, who pays later (QR on the order page).
// Orders from before 1.24 were paid up front and wait here for the payment to be verified.

import { cfg, can, h, clear, api, run, toast, modal, promptBox, confirmBox, table, select, money, fmtDate, fileSize, fileUrl, badge, stageBadge } from '../core.js';
import { openJob } from '../jobs.js';

const PAY = { pending: ['New', 'info'], submitted: ['To verify', 'warn'], verified: ['Verified', 'ok'], rejected: ['Rejected', 'bad'] };
const DELIVERY = { pickup: 'Pick up', campus: 'Campus delivery', courier: 'Courier (customer books)' };
const INVOICE = { unpaid: ['Not paid', 'warn'], partial: ['Partly paid', 'warn'], paid: ['Paid', 'ok'], void: ['Void', 'bad'] };

/** Price and payment as the invoice shows them (newer orders), else the old payment status. */
function payBadge(o) {
  if (o.payment_status !== 'pending') return badge(PAY[o.payment_status][0], PAY[o.payment_status][1]);
  const inv = o.invoice;
  if (!inv || !(inv.total > 0)) return badge('Price not set', 'info');
  const [label, kind] = INVOICE[inv.status] || [inv.status, ''];
  return badge(label, kind);
}

export async function onlineOrders(body, openId) {
  let status = '';
  let search = '';
  const listEl = h('div');
  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    const rows = await run(() => api('orders', { query: { payment_status: status, search } }));
    if (!rows) return clear(listEl);
    clear(listEl, table(rows, [
      { label: 'Order no.', render: o => h('div', h('strong', o.reference), h('div.muted', fmtDate(o.created_at, true))) },
      { label: 'Customer', render: o => h('div', o.customer_name, h('div.muted', o.customer_phone)) },
      { label: 'Order', render: o => h('div', o.service === 'rush_id' ? 'Rush ID' : 'Printing', h('div.muted', o.details && o.details.summary)) },
      { label: 'Get it by', render: o => h('div', DELIVERY[o.delivery_type], h('div.muted', o.delivery_location)) },
      { label: 'Price', render: o => o.payment_status === 'pending' ? (o.invoice && o.invoice.total > 0 ? money(o.invoice.total) : '—') : money(o.total), class: 'num' },
      { label: 'Payment', render: o => h('div', payBadge(o), o.payment_status === 'pending' ? null : h('div.muted', `${o.payment_method.toUpperCase()} · ${o.payment_reference}`)) },
      { label: 'Job', render: o => o.job ? h('div', stageBadge(o.job.stage)) : '—' },
    ], { onRow: o => openOrder(o.id, load), emptyText: 'No online orders here.' }));
  };
  let t;
  body.append(
    h('div.toolbar', h('h2', 'Online orders'),
      h('div.toolbar-right',
        h('input', { type: 'search', placeholder: 'Order no., name, email, phone…', oninput: e => { clearTimeout(t); t = setTimeout(() => { search = e.target.value; load(); }, 300); } }),
        select('status', [{ value: '', label: 'All' }, { value: 'pending', label: 'New (pay later)' }, { value: 'submitted', label: 'To verify (paid online)' }, { value: 'verified', label: 'Verified' }, { value: 'rejected', label: 'Rejected' }], status,
          { onchange: e => { status = e.target.value; load(); } }),
        h('a.btn', { href: cfg.orderUrl, target: '_blank', rel: 'noopener' }, 'Open order page ↗'))),
    h('p.muted', 'Orders customers placed on the online order page. Open the job order to check the files and each file\'s notes, set the price on the invoice, then message the customer on Facebook with the order number. Record their payment on the invoice when it comes in.'),
    listEl);
  load();
  if (openId) openOrder(Number(openId), load);
}

async function openOrder(id, onChange) {
  let o = await run(() => api(`orders/${id}`));
  if (!o) return;
  modal(`Online order ${o.reference}`, close => {
    const root = h('div.job');
    const draw = () => {
      const d = o.details || {};
      const inv = o.invoice || {};
      const pending = o.payment_status === 'pending';
      clear(root,
        h('div.job-head',
          h('div', h('div.job-title', o.customer_name), h('div.muted', `${o.customer_phone} · ${o.customer_email}`), h('div.muted', `Placed ${fmtDate(o.created_at, true)}`)),
          h('div.job-badges', payBadge(o), o.job ? h('div', stageBadge(o.job.stage)) : null)),
        pending ? h('section.card.stage-actions',
          h('h3', 'Price & payment'),
          h('dl.facts',
            h('dt', 'Invoice'), h('dd', inv.number || '—'),
            h('dt', 'Price'), h('dd', inv.total > 0 ? h('strong', money(inv.total)) : h('span.muted', 'Not set yet — open the invoice and fill in the prices')),
            inv.total > 0 ? [h('dt', 'Paid'), h('dd', `${money(inv.amount_paid)} of ${money(inv.total)}`)] : null),
          h('p.muted', 'The customer did not pay yet. Tell them the price on Facebook (they use the order number), and record the payment on the invoice when they send it.'))
          : h('section.card.stage-actions',
            h('h3', 'Payment'),
            h('dl.facts',
              h('dt', 'Method'), h('dd', o.payment_method.toUpperCase()),
              h('dt', 'Reference no.'), h('dd', h('strong', o.payment_reference)),
              h('dt', 'Amount due'), h('dd', h('strong', money(o.total))),
              o.verified_at ? [h('dt', 'Verified'), h('dd', `${fmtDate(o.verified_at, true)}${o.verified_by ? ' by ' + o.verified_by.name : ''}`)] : null,
              o.payment_note ? [h('dt', 'Rejected because'), h('dd.text-bad', o.payment_note)] : null),
            o.proof ? h('p', h('a', { href: fileUrl(o.proof, true), target: '_blank', rel: 'noopener' }, 'View payment screenshot ↗'), h('span.muted', ' · ' + fileSize(o.proof.size))) : h('p.muted', 'No screenshot attached — check the reference number in your GCash/UnionBank history.'),
            o.payment_status !== 'verified' && can('mq_record_payments') ? h('div.actions',
              o.payment_status === 'submitted' ? h('button.btn.btn-danger', {
                type: 'button',
                onclick: async e => {
                  const reason = await promptBox('Payment not found', 'Tell the customer what is wrong (they will see this and can send it again)', { danger: true, okLabel: 'Reject payment' });
                  if (!reason) return;
                  const res = await run(() => api(`orders/${o.id}/reject`, { method: 'POST', body: { reason } }), e.currentTarget);
                  if (res) { o = res; draw(); onChange(); toast('Customer emailed to send the payment again', 'ok'); }
                },
              }, 'Reject payment') : null,
              h('button.btn.btn-primary', {
                type: 'button',
                onclick: async e => {
                  if (!(await confirmBox('Verify payment', `Confirm you received ${money(o.total)} via ${o.payment_method.toUpperCase()} (ref ${o.payment_reference})? The invoice will be marked paid and the customer emailed.`, 'Payment received'))) return;
                  const res = await run(() => api(`orders/${o.id}/verify`, { method: 'POST' }), e.currentTarget);
                  if (res) { o = res; draw(); onChange(); toast('Payment verified — customer emailed', 'ok'); }
                },
              }, 'Payment received ✓')) : null),
        h('section.card',
          h('h3', 'Order'),
          h('dl.facts',
            d.summary ? [h('dt', 'Summary'), h('dd', d.summary)] : null,
            h('dt', DELIVERY[o.delivery_type]), h('dd.pre', o.delivery_location),
            o.notes ? [h('dt', 'Customer note'), h('dd.pre', o.notes)] : null),
          d.files ? table(d.files, [
            { label: 'File', render: f => h('div', h('strong', f.name), h('div.muted', f.kind + (f.pages ? ` · ${f.pages} pages` : ''))) },
            { label: 'Notes', render: f => f.note ? h('div.pre', f.note) : h('span.muted', '—') },
          ]) : null,
          d.supplies && d.supplies.length ? table(d.supplies, [
            { label: 'Office supply', render: x => h('div', x.name, h('div.muted', x.unit)) },
            { label: 'Qty', render: x => String(x.qty), class: 'num' },
          ]) : null,
          d.items ? [table(d.items, [
            { label: 'Item', render: i => h('div', i.description, i.specs ? h('div.muted', i.specs) : null) },
            { label: 'Qty', render: i => `${Number(i.qty)} ${i.unit}`, class: 'num' },
            { label: 'Amount', render: i => money(i.amount), class: 'num' },
          ]), h('div.totals.totals-right', h('div.grand', h('span', 'Total'), h('span', money(o.total))))] : null),
        h('div.actions',
          h('button.btn', { type: 'button', onclick: async e => { if (await run(() => api(`orders/${o.id}/resend`, { method: 'POST' }), e.currentTarget)) toast('Confirmation email sent again', 'ok'); } }, 'Resend email'),
          h('a.btn', { href: `#/sales/docs/${o.document_id}`, onclick: () => close() }, pending && !(inv.total > 0) ? 'Set the price (invoice)' : 'Open invoice'),
          o.job_id ? h('button.btn.btn-primary', { type: 'button', onclick: () => { close(); openJob(o.job_id, onChange); } }, 'Open job order (files, RUSH)') : null,
          can('mq_manage') ? h('button.btn.btn-danger', {
            type: 'button',
            onclick: async e => {
              const reason = await promptBox('Delete this order?', `Removes order ${o.reference} with its invoice, job order and files. This cannot be undone; it is kept in the activity log and the admins are emailed. Reason:`, { danger: true, okLabel: 'Delete order' });
              if (!reason) return;
              if (await run(() => api(`orders/${o.id}`, { method: 'DELETE', body: { reason } }), e.currentTarget)) { close(); onChange(); toast('Order deleted', 'ok'); }
            },
          }, 'Delete order') : null));
    };
    draw();
    return root;
  }, { wide: true });
}
