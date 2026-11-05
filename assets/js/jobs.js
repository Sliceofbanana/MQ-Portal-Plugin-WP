// Job order detail (shared by Sales, Creatives, Production & QC, Admin) and job lists.

import {
  cfg, can, h, clear, api, run, toast, modal, promptBox, confirmBox, field, select, fileUrl,
  money, fmtDate, fileSize, stageBadge, badge, brandName, table, STAGES,
} from './core.js';

const ACTION_LABELS = {
  created: 'Job order created', save_specs: 'Specifications', assign: 'Sales person assigned', to_creatives: 'Sent to Creatives',
  needs_editing: 'Needs editing', back_to_sales: 'Returned to Sales', approve_files: 'Approved for printing', approve_print: 'Approved for printing by Sales (no Creatives)',
  printed: 'Printed / produced', qc_approve: 'QC approved — ready for delivery', qc_reject: 'QC rejected',
  notify: 'Customer notified', email_ready: 'Ready email sent', delivered: 'Delivered', priority: 'Priority', note: 'Note', payment: 'Payment',
  cancelled: 'Cancelled', file_design: 'Design attached', file_proof: 'Proof attached', file_reference: 'File attached', file_customer: 'Customer file attached', online_order: 'Online order received',
};

const gateItem = (ok, text) => h('li', { class: ok ? 'ok' : 'missing' }, ok ? '✓ ' : '✗ ', text);
function paidText() {
  const rule = cfg.settings.gate_payment;
  return rule === 'full' ? 'Fully paid'
    : rule === 'downpayment' ? `Downpayment paid (${cfg.settings.downpayment_percent}%)`
    : rule === 'none' ? 'Payment (not needed)' : 'Payment received';
}

export function gateList(job) {
  const g = job.gate;
  return h('ul.gate',
    gateItem(g.paid, paidText()),
    gateItem(g.specs, 'Specifications complete & satisfactory'),
    gateItem(g.sales_person, 'Sales person assigned'));
}

/**
 * The two checklists in Sales: "Before this job can go to Creatives" and "Before this job is
 * approved for printing". Payment, specifications and sales person are checked by the portal;
 * the other items are ticked by Sales. Each button opens when its whole list is ✓.
 */
function salesChecklists(job, onChange) {
  const g = job.gate;
  const ticks = { needs_review: false, basic_document: false, no_creatives: false };
  const tick = (key, text) => {
    const li = h('li', { class: 'missing' },
      h('label.gate-tick', h('input', { type: 'checkbox', onchange: e => {
        ticks[key] = e.target.checked;
        // Requiring review and skipping Creatives exclude each other.
        if (key === 'needs_review' && ticks.needs_review) { ticks.basic_document = ticks.no_creatives = false; }
        if ((key === 'basic_document' || key === 'no_creatives') && e.target.checked) ticks.needs_review = false;
        draw();
      } }), ' ', text));
    li.dataset.key = key;
    return li;
  };
  const creativesList = h('ul.gate',
    gateItem(g.paid, paidText()),
    tick('needs_review', 'The file requires review/checking/editing'),
    gateItem(g.specs, 'Specifications complete & satisfactory'),
    gateItem(g.sales_person, 'Sales person assigned'));
  const printList = h('ul.gate',
    gateItem(g.paid, paidText()),
    tick('basic_document', 'Basic document printing only'),
    tick('no_creatives', 'No need for Creatives to review/check/edit'),
    gateItem(g.specs, 'Specifications complete & satisfactory'),
    gateItem(g.sales_person, 'Sales person assigned'));
  const base = g.paid && g.specs && g.sales_person;
  const ready = {
    creatives: () => base && ticks.needs_review,
    print: () => base && ticks.basic_document && ticks.no_creatives,
  };
  function draw() {
    [creativesList, printList].forEach(list => list.querySelectorAll('li[data-key]').forEach(li => {
      const on = ticks[li.dataset.key];
      li.className = on ? 'ok' : 'missing';
      li.querySelector('input').checked = on;
    }));
    onChange(ready, ticks);
  }
  const node = h('div.gate-cols',
    h('div.gate-col', h('h3', 'Before this job can go to Creatives'), creativesList),
    h('div.gate-col', h('h3', 'Before this job is approved for printing'), printList));
  return { node, ready, ticks, draw };
}

export const jobColumns = [
  { label: 'Job order', render: j => h('div', h('strong', j.number), ' ', j.priority ? badge('RUSH', 'bad') : null, h('div.muted', j.invoice_number)) },
  { label: 'Customer / job', render: j => h('div', h('div', j.customer_name), h('div.muted', j.title)) },
  { label: 'Brand', render: j => brandName(j.brand_id) },
  { label: 'Stage', render: j => h('div', stageBadge(j.stage), j.needs_editing ? h('div', badge('Needs editing', 'warn')) : null) },
  { label: 'Due', render: j => j.due_date ? fmtDate(j.due_date) : '—' },
  { label: 'Sales', render: j => j.sales_person ? j.sales_person.name : h('span.text-bad', 'Unassigned') },
];

/** Opens the job detail modal. Resolves when closed; calls onChange after any update. */
export async function openJob(id, onChange = () => {}) {
  let job = await run(() => api(`jobs/${id}`));
  if (!job) return;
  return modal(`Job order ${job.number}`, close => {
    const root = h('div.job');
    const refresh = async updated => {
      job = updated || await api(`jobs/${id}`);
      clear(root, ...renderJob(job, act, refresh, close));
      onChange(job);
    };
    const act = async (action, body = {}, btn) => {
      const res = await run(() => api(`jobs/${id}/action`, { method: 'POST', body: { action, ...body } }), btn);
      if (res) { await refresh(res); toast('Saved', 'ok'); }
      return res;
    };
    clear(root, ...renderJob(job, act, refresh, close));
    return root;
  }, { wide: true });
}

function renderJob(job, act, refresh, close) {
  const out = [];
  out.push(h('div.job-head',
    h('div',
      h('div.job-title', job.title || '(no title)'),
      h('div.muted', job.customer_name, job.customer_contact ? ' · ' + job.customer_contact : ''),
      h('div.muted', brandName(job.brand_id), ' · Invoice ', job.invoice_number)),
    h('div.job-badges', stageBadge(job.stage), job.priority ? badge('RUSH', 'bad') : null, job.needs_editing ? badge('Needs editing', 'warn') : null)));

  const facts = [
    ['Due date', job.due_date ? fmtDate(job.due_date) : '—'],
    ['Sales person', job.sales_person ? job.sales_person.name : 'Not assigned'],
  ];
  if (job.total !== undefined) {
    facts.push(['Invoice total', money(job.total)], ['Paid', money(job.amount_paid)], ['Balance', money(job.balance)]);
  }
  if (job.commission) facts.push(['Commission', `${money(job.commission.amount)} (${job.commission.rate}% · ${job.commission.status})`]);
  if (job.notified_at) facts.push(['Customer notified', `${fmtDate(job.notified_at, true)} via ${job.notified_via}`]);
  if (job.delivered_at) facts.push(['Delivered', fmtDate(job.delivered_at, true)]);
  out.push(h('dl.facts', facts.map(([k, v]) => [h('dt', k), h('dd', v)])));

  out.push(stageActions(job, act, refresh));

  // Specifications
  const editableSpecs = job.stage === 'sales' && can('mq_sales');
  if (editableSpecs) {
    const form = h('form.card', {
      onsubmit: e => {
        e.preventDefault();
        act('save_specs', { specs: form.specs.value, specs_complete: form.specs_complete.checked, due_date: form.due_date.value }, form.querySelector('button'));
      },
    },
    h('h3', 'Specifications'),
    h('textarea', { name: 'specs', rows: 6, value: job.specs || '' }),
    h('div.row',
      field('Due date', h('input', { type: 'date', name: 'due_date', value: job.due_date || '' })),
      h('label.check', h('input', { type: 'checkbox', name: 'specs_complete', checked: !!job.specs_complete }), ' Specifications are complete & satisfactory')),
    h('div.actions', h('button.btn', { type: 'submit' }, 'Save specifications')));
    out.push(form);
  } else {
    out.push(h('section.card', h('h3', 'Specifications'), h('pre.specs', job.specs || '—')));
  }

  // Items
  out.push(h('section.card', h('h3', 'Items'), table(job.items, [
    { label: 'Description', render: i => h('div', i.description, i.specs ? h('div.muted.pre', i.specs) : null) },
    { label: 'Qty', render: i => `${Number(i.qty)} ${i.unit}`, class: 'num' },
    ...(job.total !== undefined ? [{ label: 'Amount', render: i => money(i.amount), class: 'num' }] : []),
  ])));

  // Files
  const fileInput = h('input', { type: 'file', multiple: true, name: 'files[]' });
  const kindSel = select('kind', [
    { value: can('mq_creatives') ? 'design' : 'reference', label: can('mq_creatives') ? 'Edited design' : 'Reference file' },
    { value: 'proof', label: 'Proof / mock-up' },
    { value: 'reference', label: 'Reference' },
  ], can('mq_creatives') ? 'design' : 'reference');
  const upForm = h('form.inline-form', {
    onsubmit: async e => {
      e.preventDefault();
      if (!fileInput.files.length) return;
      const fd = new FormData();
      [...fileInput.files].forEach(f => fd.append('files[]', f));
      fd.append('kind', kindSel.value);
      const res = await run(() => api(`jobs/${job.id}/files`, { method: 'POST', body: fd }), upForm.querySelector('button'));
      if (res) { toast('File attached', 'ok'); refresh(res); }
    },
  }, kindSel, fileInput, h('button.btn', { type: 'submit' }, 'Attach'));
  out.push(h('section.card', h('h3', 'Files'),
    job.files.length ? h('ul.files', job.files.map(f => h('li',
      badge({ customer: 'Customer', design: 'Design', proof: 'Proof', reference: 'Ref', payment: 'Payment proof' }[f.kind] || f.kind, f.kind === 'design' ? 'ok' : f.kind === 'payment' ? 'info' : ''),
      ' ', f.archived ? h('span', f.name) : h('a', { href: fileUrl(f, true), target: '_blank', rel: 'noopener' }, f.name),
      h('span.muted', ` · ${fileSize(f.size)} · ${fmtDate(f.created_at, true)}${f.uploaded_by ? ' · ' + f.uploaded_by.name : ''}`),
      ' ', f.archived ? badge('Archived ' + fmtDate(f.archived), '') : h('a.muted', { href: fileUrl(f) }, 'Download')))) : h('p.muted', 'No files yet.'),
    ['delivered', 'cancelled'].includes(job.stage) ? null : upForm));

  // Timeline
  out.push(h('section.card', h('h3', 'History'),
    h('form.inline-form', {
      onsubmit: async e => {
        e.preventDefault();
        const input = e.currentTarget.note;
        if (!input.value.trim()) return;
        if (await act('note', { note: input.value }, e.currentTarget.querySelector('button'))) input.value = '';
      },
    }, h('input', { name: 'note', placeholder: 'Add a note for the team…' }), h('button.btn', { type: 'submit' }, 'Add note')),
    h('ol.timeline', job.events.map(ev => h('li',
      h('div', h('strong', ACTION_LABELS[ev.action] || ev.action), ev.to_stage ? ` → ${STAGES[ev.to_stage]}` : ''),
      ev.note ? h('div.pre', ev.note) : null,
      h('div.muted', fmtDate(ev.created_at, true), ev.user ? ' · ' + ev.user.name : ''))))));

  if (can('mq_sales')) {
    out.push(h('div.actions', h('a.btn', { href: `#/sales/docs/${job.document_id}`, onclick: () => close() }, 'Open invoice')));
  }
  return out;
}

function stageActions(job, act) {
  const box = h('section.card.stage-actions');
  const btn = (label, onclick, cls = '') => h('button.btn', { type: 'button', class: cls, onclick }, label);

  switch (job.stage) {
    case 'sales': {
      // Not every order goes through Creatives: only files that need checking or editing.
      const printBtn = h('button.btn.btn-ok', { type: 'button', disabled: true }, 'Approve for Printing');
      const creativesBtn = h('button.btn.btn-primary', { type: 'button', disabled: true }, 'Send to Creatives →');
      const lists = salesChecklists(job, ready => {
        printBtn.disabled = !ready.print();
        creativesBtn.disabled = !ready.creatives();
      });
      box.append(lists.node);
      if (can('mq_assign_sales')) {
        const sel = select('sales_person_id', [{ value: '', label: '— Choose sales person —' }, ...cfg.salesStaff.map(u => ({ value: u.id, label: u.name }))], job.sales_person_id || '');
        box.append(h('div.inline-form', sel, btn('Assign', e => sel.value && act('assign', { sales_person_id: sel.value }, e.currentTarget))));
      }
      if (can('mq_sales')) {
        printBtn.onclick = e => act('approve_print', { basic_document: lists.ticks.basic_document, no_creatives: lists.ticks.no_creatives }, e.currentTarget);
        creativesBtn.onclick = e => act('to_creatives', { needs_review: lists.ticks.needs_review }, e.currentTarget);
        box.append(h('div.actions',
          btn(job.priority ? 'Remove RUSH' : 'Mark as RUSH', e => act('priority', {}, e.currentTarget)),
          printBtn, creativesBtn));
      }
      lists.draw();
      break;
    }
    case 'creatives':
      if (can('mq_creatives')) {
        box.append(h('h3', 'Creatives'), h('p.muted', 'Check the customer files. If they need editing, attach the edited design below, then approve.'),
          h('div.actions',
            btn('← Back to Sales', async e => { const n = await promptBox('Return to Sales', 'What is missing or unclear?'); if (n) act('back_to_sales', { note: n }, e.currentTarget); }),
            btn('Needs editing', async e => { const n = await promptBox('Needs editing', 'What needs to be edited?'); if (n) act('needs_editing', { note: n }, e.currentTarget); }, 'btn-warn'),
            btn('Approve for printing →', async e => {
              if (job.needs_editing && !job.files.some(f => f.kind === 'design') && !(await confirmBox('No edited design attached', 'This job was marked as needing edits but no edited design is attached. Approve anyway?', 'Approve'))) return;
              act('approve_files', {}, e.currentTarget);
            }, 'btn-primary')));
      }
      break;
    case 'production':
      if (can('mq_production')) {
        box.append(h('h3', 'Production'), h('div.actions', btn('Printed / produced → send to QC', e => act('printed', {}, e.currentTarget), 'btn-primary')));
      }
      break;
    case 'qc':
      if (can('mq_qc')) {
        box.append(h('h3', 'Quality check'), h('div.actions',
          btn('Reject → back to Production', async e => { const n = await promptBox('QC reject', 'What is wrong?', { danger: true, okLabel: 'Reject' }); if (n) act('qc_reject', { note: n }, e.currentTarget); }, 'btn-danger'),
          btn('APPROVED / READY FOR DELIVERY', e => act('qc_approve', {}, e.currentTarget), 'btn-primary')));
      }
      break;
    case 'ready':
      if (can('mq_deliver')) {
        const via = select('via', ['SMS', 'Call', 'Messenger', 'Email', 'Viber'].map(v => ({ value: v, label: v })), 'SMS');
        // Ready-made message: staff copy it and send it themselves on any app.
        const msg = job.ready_message ? h('textarea.ready-msg', { rows: 3, 'aria-label': 'Message for the customer', value: job.ready_message }) : null;
        const copyBtn = msg ? btn('Copy message', async e => {
          const b = e.currentTarget;
          try { await navigator.clipboard.writeText(msg.value); } catch (_) { msg.select(); document.execCommand('copy'); }
          b.textContent = 'Copied ✓';
          toast('Message copied. Paste it in SMS, Messenger or Viber.');
          setTimeout(() => { b.textContent = 'Copy message'; }, 2000);
        }) : null;
        // Send the message by email from the portal; it is recorded as "Customer notified via Email".
        const c = job.contact || {};
        const emailBtn = msg ? h('button.btn', {
          type: 'button', disabled: !c.email, title: c.email ? 'Send to ' + c.email : 'No email on file',
          onclick: async e => {
            const b = e.currentTarget;
            if (job.notified_at && !(await confirmBox('Already notified', `The customer was notified ${fmtDate(job.notified_at, true)} via ${job.notified_via}. Email them again?`, 'Send email'))) return;
            if (await act('email_ready', { message: msg.value }, b)) toast('Email sent to ' + c.email + '.', 'ok');
          },
        }, 'Send email') : null;
        // Where to reach the customer: tap to call, or email.
        const tel = (c.phone || '').replace(/[^\d+]/g, '');
        const reach = h('div.reach',
          h('strong', c.name || job.customer_name),
          tel ? h('a', { href: 'tel:' + tel }, '📞 ' + c.phone) : h('span.muted', c.phone ? c.phone : 'No phone on file'),
          c.email ? h('a', { href: 'mailto:' + c.email }, '✉ ' + c.email) : h('span.muted', 'No email on file'),
          c.delivery ? badge(c.delivery, 'info') : null);
        box.append(h('h3', 'Ready for delivery'),
          job.contact ? reach : null,
          c.online ? h('p.muted', 'Online order: the customer already got an automatic "Your order is ready" email when QC approved it.') : null,
          msg ? h('div.ready-msg-wrap', h('span.muted', 'Message for the customer (you can edit it before sending)'), msg, h('div.actions', copyBtn, emailBtn)) : null,
          h('div.inline-form', h('span', 'Notify customer via'), via, btn(job.notified_at ? 'Notified again' : 'Customer notified', e => act('notify', { via: via.value }, e.currentTarget))),
          h('div.actions', btn('Mark as delivered ✓', async e => {
            if (job.balance > 0 && !(await confirmBox('Balance unpaid', `This job still has a balance of ${money(job.balance)}. Mark as delivered anyway?`, 'Mark delivered'))) return;
            act('delivered', {}, e.currentTarget);
          }, 'btn-primary')));
      }
      break;
    default:
      return h('span');
  }
  return box.childNodes.length ? box : h('span');
}

/** Job list with search + stage filter. Used by Sales and Admin. */
export function jobList(container, { stages = '', mine = false, title = 'Job orders' } = {}) {
  const state = { stage: stages, search: '' };
  const listEl = h('div');
  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    const query = { stage: state.stage, search: state.search, mine: mine ? 1 : '' };
    const rows = await run(() => api('jobs', { query }));
    if (!rows) return clear(listEl);
    let page = 1;
    const draw = (more) => clear(listEl, table(rows, jobColumns, { onRow: j => openJob(j.id, load), emptyText: 'No job orders found.' }),
      more ? h('div.actions', h('button.btn', { type: 'button', onclick: async e => {
        const next = await run(() => api('jobs', { query: { ...query, page: page + 1 } }), e.currentTarget);
        if (!next) return;
        page += 1;
        rows.push(...next);
        draw(next.length === 300);
      } }, 'Show older')) : null);
    draw(rows.length === 300);
  };
  const stageSel = select('stage', [
    { value: 'sales,creatives,production,qc,ready', label: 'All active' },
    ...Object.entries(STAGES).map(([v, l]) => ({ value: v, label: l })),
    { value: '', label: 'All' },
  ], state.stage, { onchange: e => { state.stage = e.target.value; load(); } });
  let t;
  const search = h('input', { type: 'search', placeholder: 'Search job, invoice, customer…', oninput: e => { clearTimeout(t); t = setTimeout(() => { state.search = e.target.value; load(); }, 300); } });
  container.append(h('div.toolbar', h('h2', title), h('div.toolbar-right', search, stageSel)), listEl);
  load();
  return load;
}
