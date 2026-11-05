// Sales tab: Invoice Manager (quotations, billing statements, invoices), customers, customer uploads, job orders, commissions.

import {
  cfg, can, h, add, clear, api, run, toast, modal, confirmBox, promptBox, field, select, formData, table,
  money, fmtDate, fileSize, fileUrl, statusBadge, stageBadge, brandName, branchName, uuid, DOC_TYPES, empty,
} from '../core.js';
import { enqueue, outbox, cachedGet } from '../offline.js';
import { printDocument } from '../print.js';
import { openJob, jobList } from '../jobs.js';
import { onlineOrders } from './orders.js';

// Prefill for the next "new document" screen (set when converting a customer upload).
let nextPrefill = null;

export async function render(view, params) {
  const [sub = 'docs', a, b] = params;
  // Each page is in the side menu (Sales → Invoice Manager, Customers…).
  const body = h('div');
  view.append(body);

  if (sub === 'docs' && a === 'new') {
    const prefill = nextPrefill || {};
    nextPrefill = null;
    return docEditor(body, { type: b || 'quotation', prefill });
  }
  if (sub === 'docs' && a && b === 'edit') return docEditor(body, { id: Number(a) });
  if (sub === 'docs' && a) return docView(body, Number(a));
  if (sub === 'uploads') return uploads(body);
  if (sub === 'customers') return a ? customerView(body, Number(a)) : customerList(body);
  if (sub === 'orders') return onlineOrders(body, a);
  if (sub === 'jobs') {
    jobList(body, { title: 'Job orders' });
    if (a) openJob(Number(a));
    return;
  }
  if (sub === 'commissions') return commissions(body);
  return docList(body);
}

/* ---------- Document list ---------- */

async function docList(body) {
  const state = { type: '', search: '' };
  const listEl = h('div');
  const pendingEl = h('div');

  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    try {
      const { data, cached } = await cachedGet('documents', { type: state.type, search: state.search });
      let page = 1;
      const older = () => !cached && data.length === page * 300 ? h('div.actions', h('button.btn', { type: 'button', onclick: async e => {
        const next = await run(() => api('documents', { query: { type: state.type, search: state.search, page: page + 1 } }), e.currentTarget);
        if (!next) return;
        page += 1;
        data.push(...next);
        draw();
      } }, 'Show older')) : null;
      const draw = () => clear(listEl,
        cached ? h('p.notice', 'Offline — showing the last saved list.') : null,
        table(data, [
          { label: 'No.', render: d => h('div', h('strong', d.number), h('div.muted', DOC_TYPES[d.type])) },
          { label: 'Customer', render: d => h('div', d.customer_name, d.customer_company ? h('div.muted', d.customer_company) : null) },
          { label: 'Brand', render: d => brandName(d.brand_id) },
          { label: 'Date', render: d => fmtDate(d.created_at) },
          { label: 'Total', render: d => money(d.total), class: 'num' },
          { label: 'Status', render: d => h('div', statusBadge(d.status), d.job_stage ? h('div', stageBadge(d.job_stage)) : null) },
        ], { onRow: d => { location.hash = `#/sales/docs/${d.id}`; }, emptyText: 'No documents yet. Create a quotation to get started.' }),
        older());
      draw();
    } catch (e) {
      clear(listEl, empty(e.message));
    }
    const pending = (await outbox()).filter(o => o.kind === 'document');
    clear(pendingEl, pending.length ? h('section.card.pending',
      h('h3', `Saved on this device — waiting to sync (${pending.length})`),
      table(pending, [
        { label: 'Type', render: o => DOC_TYPES[o.payload.type] },
        { label: 'Customer', render: o => o.payload.customer.name },
        { label: 'Total', render: o => money(docTotals(o.payload).total), class: 'num' },
        { label: 'Status', render: o => o.error ? h('span.text-bad', o.error) : 'Waiting' },
        { label: '', render: o => h('button.btn.btn-sm', { type: 'button', onclick: e => { e.stopPropagation(); printDocument(offlineDoc(o.payload)); } }, 'Print') },
      ])) : null);
  };

  const newBtn = (type, label) => h('a.btn', { href: `#/sales/docs/new/${type}`, class: type === 'invoice' ? 'btn-primary' : '' }, label);
  let t;
  body.append(
    h('div.toolbar',
      h('h2', 'Invoice Manager'),
      h('div.toolbar-right', newBtn('quotation', '+ Quotation'), newBtn('billing', '+ Billing statement'), newBtn('invoice', '+ Invoice'))),
    h('div.toolbar',
      select('type', [{ value: '', label: 'All documents' }, ...Object.entries(DOC_TYPES).map(([v, l]) => ({ value: v, label: l }))], '', { onchange: e => { state.type = e.target.value; load(); } }),
      h('input', { type: 'search', placeholder: 'Search number, customer, project…', oninput: e => { clearTimeout(t); t = setTimeout(() => { state.search = e.target.value; load(); }, 300); } })),
    pendingEl, listEl);
  const onSync = () => load();
  window.addEventListener('mqp-sync', onSync);
  load();
  return () => window.removeEventListener('mqp-sync', onSync);
}

function docTotals(p) {
  const subtotal = (p.items || []).reduce((s, i) => s + (Number(i.qty) || 0) * (Number(i.unit_price) || 0), 0);
  const discount = Math.min(subtotal, Number(p.discount) || 0);
  return { subtotal, discount, total: subtotal - discount };
}

/** Shapes an unsynced payload like a server document so it can be printed. */
function offlineDoc(p) {
  const t = docTotals(p);
  return {
    ...p, number: null, created_at: p.created_at, customer_name: p.customer.name, customer_company: p.customer.company,
    customer_contact: p.customer.contact, customer_address: p.customer.address,
    items: p.items.map(i => ({ ...i, amount: (Number(i.qty) || 0) * (Number(i.unit_price) || 0) })),
    subtotal: t.subtotal, discount: t.discount, total: t.total, amount_paid: 0, balance: t.total, payments: [],
    created_by: { name: cfg.user.name },
  };
}

/* ---------- Document editor ---------- */

async function docEditor(body, { id, type, prefill = {} }) {
  let doc = null;
  if (id) {
    doc = await run(() => api(`documents/${id}`));
    if (!doc) return;
    type = doc.type;
  }
  const s = cfg.settings;
  const defaultTerms = { quotation: s.quotation_terms, billing: s.billing_terms, invoice: s.invoice_terms }[type];
  const brandId = doc ? doc.brand_id : (prefill.brand_id || cfg.user.brand_id || cfg.brands[0].id);
  const items = doc ? doc.items.map(i => ({ ...i })) : (prefill.items || [{ description: '', specs: '', qty: 1, unit: 'pc', unit_price: 0, is_estimate: type === 'quotation' }]);

  const itemsBody = h('tbody');
  const totalsEl = h('div.totals');
  const form = h('form.doc-form', { onsubmit: e => { e.preventDefault(); save(e.submitter); } });

  const brandSel = select('brand_id', cfg.brands.filter(b => b.active).map(b => ({ value: b.id, label: b.name })), brandId, { onchange: () => syncBranch() });
  const branchWrap = h('div');
  const syncBranch = () => {
    const brand = cfg.brands.find(b => b.id === Number(brandSel.value));
    const branches = cfg.branches.filter(b => b.brand_id === Number(brandSel.value) && b.active);
    clear(branchWrap, brand && brand.has_branches && branches.length
      ? field('Branch', select('branch_id', branches.map(b => ({ value: b.id, label: b.name })), doc ? doc.branch_id : (prefill.branch_id || cfg.user.branch_id)))
      : null);
  };
  syncBranch();

  const c = doc ? { name: doc.customer_name, company: doc.customer_company, contact: doc.customer_contact, address: doc.customer_address } : (prefill.customer || {});
  const custName = h('input', { name: 'c_name', required: true, value: c.name || '', list: 'mqp-customers', autocomplete: 'off' });
  const datalist = h('datalist#mqp-customers');
  let found = [];
  let ct;
  custName.addEventListener('input', () => {
    clearTimeout(ct);
    const match = found.find(x => x.name === custName.value);
    if (match) {
      form.c_company.value = match.company; form.c_contact.value = match.contact; form.c_email.value = match.email; form.c_address.value = match.address;
      return;
    }
    ct = setTimeout(async () => {
      if (custName.value.length < 2 || !navigator.onLine) return;
      try {
        found = await api('customers', { query: { search: custName.value } });
        clear(datalist, found.map(x => h('option', { value: x.name }, [x.company, x.contact].filter(Boolean).join(' · '))));
      } catch (e) { /* suggestions are optional */ }
    }, 250);
  });

  const renderItems = () => {
    clear(itemsBody, items.map((it, i) => h('tr',
      h('td', { 'data-label': 'Description' },
        h('input', { value: it.description, placeholder: 'e.g. Tarpaulin 3×6 ft', required: true, oninput: e => { it.description = e.target.value; } }),
        h('textarea', { rows: 2, value: it.specs || '', placeholder: 'Specifications: size, material, color, finishing…', oninput: e => { it.specs = e.target.value; } })),
      h('td.num', { 'data-label': 'Qty' }, h('input', { type: 'number', min: 0, step: 'any', value: it.qty, oninput: e => { it.qty = e.target.value; updateTotals(); } }),
        h('input.unit', { value: it.unit, oninput: e => { it.unit = e.target.value; }, 'aria-label': 'Unit' })),
      h('td.num', { 'data-label': 'Unit price' }, h('input', { type: 'number', min: 0, step: '0.01', value: it.unit_price, oninput: e => { it.unit_price = e.target.value; updateTotals(); } }),
        h('label.check.small', h('input', { type: 'checkbox', checked: !!it.is_estimate, onchange: e => { it.is_estimate = e.target.checked; } }), ' estimate')),
      h('td.num', { 'data-label': 'Amount', class: 'amount' }, money((Number(it.qty) || 0) * (Number(it.unit_price) || 0))),
      h('td', h('button.icon-btn', { type: 'button', 'aria-label': 'Remove item', onclick: () => { if (items.length > 1) { items.splice(i, 1); renderItems(); } } }, '×')))));
    updateTotals();
  };
  const updateTotals = () => {
    itemsBody.querySelectorAll('tr').forEach((tr, i) => {
      const it = items[i];
      if (it) tr.querySelector('.amount').textContent = money((Number(it.qty) || 0) * (Number(it.unit_price) || 0));
    });
    const t = docTotals({ items, discount: form.discount ? form.discount.value : 0 });
    clear(totalsEl,
      h('div', h('span', 'Subtotal'), h('span', money(t.subtotal))),
      h('div', h('span', 'Discount'), h('span', '−' + money(t.discount))),
      h('div.grand', h('span', 'Total'), h('span', money(t.total))),
      type === 'billing' && Number(s.downpayment_percent) ? h('div.muted', h('span', `${s.downpayment_percent}% downpayment`), h('span', money(t.total * s.downpayment_percent / 100))) : null);
  };

  const salesSel = can('mq_assign_sales')
    ? field('Sales person', select('sales_person_id', [{ value: '', label: '— Assign later —' }, ...cfg.salesStaff.map(u => ({ value: u.id, label: u.name }))], doc ? doc.sales_person_id : ''))
    : null;

  add(form,
    h('div.toolbar', h('h2', (doc ? 'Edit ' : 'New ') + DOC_TYPES[type] + (doc ? ' ' + doc.number : '')),
      h('div.toolbar-right', h('a.btn', { href: doc ? `#/sales/docs/${doc.id}` : '#/sales/docs' }, 'Cancel'), h('button.btn.btn-primary', { type: 'submit' }, 'Save'))),
    type === 'quotation' ? h('p.notice', 'An estimate can be saved even without final quantity or design. Tick "estimate" on items whose price is not final yet.') : null,
    h('section.card',
      h('h3', 'Customer'),
      h('div.grid-2',
        field('Name *', custName),
        field('Company', h('input', { name: 'c_company', value: c.company || '' })),
        field('Contact no.', h('input', { name: 'c_contact', value: c.contact || '' })),
        field('Email', h('input', { name: 'c_email', type: 'email', value: c.email || '' })),
        field('Address', h('input', { name: 'c_address', value: c.address || '' }))),
      datalist),
    h('section.card',
      h('h3', 'Details'),
      h('div.grid-2',
        field('Brand', brandSel), branchWrap,
        field('Project / job title', h('input', { name: 'title', value: doc ? doc.title : (prefill.title || ''), placeholder: 'e.g. Company anniversary giveaways' })),
        type === 'quotation' ? field('Valid until', h('input', { type: 'date', name: 'valid_until', value: doc ? doc.valid_until || '' : '' })) : null,
        field('Due / delivery date', h('input', { type: 'date', name: 'due_date', value: doc ? doc.due_date || '' : '' })),
        salesSel)),
    h('section.card',
      h('h3', 'Items'),
      h('div.table-wrap', h('table.table.items-table',
        h('thead', h('tr', h('th', 'Description & specifications'), h('th.num', 'Qty / unit'), h('th.num', 'Unit price'), h('th.num', 'Amount'), h('th', ''))),
        itemsBody)),
      h('button.btn', { type: 'button', onclick: () => { items.push({ description: '', specs: '', qty: 1, unit: 'pc', unit_price: 0, is_estimate: type === 'quotation' }); renderItems(); } }, '+ Add item'),
      h('div.totals-row',
        can('mq_price_override')
          ? field('Discount (₱)', h('input', { type: 'number', name: 'discount', min: 0, step: '0.01', value: doc ? doc.discount : 0, oninput: updateTotals }))
          : h('input', { type: 'hidden', name: 'discount', value: doc ? doc.discount : 0 }),
        totalsEl)),
    h('section.card',
      h('div.grid-2',
        field('Terms', h('textarea', { name: 'terms', rows: 4, value: doc ? doc.terms : defaultTerms })),
        field('Notes', h('textarea', { name: 'notes', rows: 4, value: doc ? doc.notes : (prefill.notes || '') })))));
  body.append(form);
  renderItems();

  async function save(btn) {
    const f = formData(form);
    const payload = {
      type, brand_id: Number(f.brand_id), branch_id: f.branch_id ? Number(f.branch_id) : null,
      customer: { name: f.c_name.trim(), company: f.c_company, contact: f.c_contact, email: f.c_email, address: f.c_address },
      title: f.title, valid_until: f.valid_until || null, due_date: f.due_date || null,
      items: items.filter(i => String(i.description).trim()).map(i => ({ description: i.description, specs: i.specs, qty: Number(i.qty) || 0, unit: i.unit || 'pc', unit_price: Number(i.unit_price) || 0, is_estimate: !!i.is_estimate })),
      discount: Number(f.discount) || 0, terms: f.terms, notes: f.notes,
      sales_person_id: f.sales_person_id ? Number(f.sales_person_id) : null,
      upload_id: prefill.upload_id || null,
    };
    if (!payload.items.length) return toast('Add at least one item.', 'bad');

    if (doc) {
      payload.version = doc.version; // The server refuses the save if someone else changed it meanwhile.
      const res = await run(() => api(`documents/${doc.id}`, { method: 'PUT', body: payload }), btn);
      if (res) { toast('Saved', 'ok'); location.hash = `#/sales/docs/${res.id}`; }
      return;
    }
    payload.uuid = uuid();
    payload.created_at = new Date().toISOString();
    if (btn) btn.disabled = true;
    try {
      const res = await api('documents', { method: 'POST', body: payload });
      toast(`${DOC_TYPES[type]} ${res.number} saved`, 'ok');
      location.hash = `#/sales/docs/${res.id}`;
    } catch (e) {
      if (e.offline) {
        await enqueue('document', payload);
        toast('Offline — saved on this device. It will sync automatically.', 'warn');
        if (await confirmBox('Saved offline', 'Print it now? The document number is assigned once it syncs; the printout will say "PENDING SYNC".', 'Print')) {
          printDocument(offlineDoc(payload));
        }
        location.hash = '#/sales/docs';
      } else {
        toast(e.message, 'bad');
      }
    } finally {
      if (btn) btn.disabled = false;
    }
  }
}

/* ---------- Document view ---------- */

async function docView(body, id) {
  const doc = await run(() => api(`documents/${id}`));
  if (!doc) return clear(body, empty('Document not found.'));

  const reload = () => { clear(body); docView(body, id); };
  const actions = [h('button.btn', { type: 'button', onclick: () => printDocument(doc) }, 'Print / PDF')];
  // After a payment, only an admin may change an invoice.
  const editable = !['void', 'converted'].includes(doc.status) && (doc.type !== 'invoice' || !(doc.amount_paid > 0) || can('mq_price_override'));
  if (editable) actions.push(h('a.btn', { href: `#/sales/docs/${doc.id}/edit` }, 'Edit'));
  if (editable && doc.type === 'quotation') {
    actions.push(h('button.btn', { type: 'button', onclick: e => convert('billing', e.currentTarget) }, '→ Billing statement'));
  }
  if (editable && doc.type !== 'invoice') {
    actions.push(h('button.btn', { type: 'button', onclick: e => convert('invoice', e.currentTarget) }, '→ Invoice'));
  }
  if (doc.status !== 'void' && doc.type !== 'quotation' && can('mq_record_payments') && doc.balance > 0) {
    actions.push(h('button.btn.btn-primary', { type: 'button', onclick: recordPayment }, 'Record payment'));
  }
  // Quotations: email the customer a link to view/print it (website requests: after adding the prices).
  // Invoices: email the updated invoice — each price, the total, the amount to pay and the payment QR codes.
  if (doc.status !== 'void' && (doc.type === 'quotation' ? editable : doc.type === 'invoice')) {
    const isInv = doc.type === 'invoice';
    actions.push(h('button.btn' + (doc.status === 'pricing' ? '.btn-primary' : ''), {
      type: 'button',
      onclick: async e => {
        if (isInv && !(doc.total > 0)) return toast('Set the prices first: click Edit, add the price of each line, then save.', 'warn');
        const text = isInv
          ? `Send invoice ${doc.number} to the customer's email? It shows each price and the total${doc.balance > 0 ? ', the amount to pay and the payment QR codes' : ''}.`
          : `Send quotation ${doc.number} to the customer's email? They get a link to view and print it.`;
        if (!(await confirmBox('Email to customer', text, 'Send'))) return;
        const res = await run(() => api(`documents/${doc.id}/send`, { method: 'POST' }), e.currentTarget);
        if (res) { toast((isInv ? 'Invoice' : 'Quotation') + ' emailed to ' + res.sent_to, 'ok'); reload(); }
      },
    }, 'Email to customer'));
  }
  if (doc.status !== 'void' && can('mq_void_documents')) {
    actions.push(h('button.btn.btn-danger', {
      type: 'button',
      onclick: async e => {
        const reason = await promptBox('Void ' + doc.number, 'Reason for voiding', { danger: true, okLabel: 'Void' });
        if (reason && await run(() => api(`documents/${doc.id}/void`, { method: 'POST', body: { reason } }), e.currentTarget)) reload();
      },
    }, 'Void'));
  }

  async function convert(to, btn) {
    const res = await run(() => api(`documents/${doc.id}/convert`, { method: 'POST', body: { to } }), btn);
    if (res) { toast(`${DOC_TYPES[to]} ${res.number} created`, 'ok'); location.hash = `#/sales/docs/${res.id}`; }
  }

  function recordPayment() {
    const suggested = doc.type === 'billing' && !doc.amount_paid
      ? Math.round(doc.total * (cfg.settings.downpayment_percent || 100)) / 100 : doc.balance;
    modal('Record payment', close => {
      const form = h('form', {
        onsubmit: async e => {
          e.preventDefault();
          const f = formData(form);
          const payload = { uuid: uuid(), amount: Number(f.amount), method: f.method, reference: f.reference, notes: f.notes, paid_at: new Date().toISOString() };
          const btn = form.querySelector('[type=submit]');
          btn.disabled = true;
          try {
            const res = await api(`documents/${doc.id}/payments`, { method: 'POST', body: payload });
            close();
            toast('Payment recorded', 'ok');
            if (res.id !== doc.id) location.hash = `#/sales/docs/${res.id}`; else reload();
          } catch (err) {
            if (err.offline) {
              await enqueue('payment', { ...payload, document_id: doc.id });
              close();
              toast('Offline — payment saved on this device and will sync.', 'warn');
            } else {
              toast(err.message, 'bad');
            }
          } finally { btn.disabled = false; }
        },
      },
      doc.type !== 'invoice' ? h('p.notice', 'Recording a payment on a ' + DOC_TYPES[doc.type].toLowerCase() + ' creates the invoice (and its job order) automatically.') : null,
      h('div.grid-2',
        field('Amount (₱)', h('input', { type: 'number', name: 'amount', min: 0.01, step: '0.01', required: true, value: suggested.toFixed(2) })),
        field('Method', select('method', ['cash', 'gcash', 'maya', 'bank', 'card', 'check'].map(m => ({ value: m, label: m.toUpperCase() })), 'cash')),
        field('Reference no.', h('input', { name: 'reference', placeholder: 'GCash / bank ref' })),
        field('Notes', h('input', { name: 'notes' }))),
      h('div.actions', h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'), h('button.btn.btn-primary', { type: 'submit' }, 'Save payment')));
      return form;
    });
  }

  const related = [];
  if (doc.parent) related.push(h('a', { href: `#/sales/docs/${doc.parent.id}` }, `From ${DOC_TYPES[doc.parent.type]} ${doc.parent.number}`));
  doc.children.forEach(ch => related.push(h('a', { href: `#/sales/docs/${ch.id}` }, `${DOC_TYPES[ch.type]} ${ch.number} (${ch.status})`)));
  if (doc.job_id) related.push(h('a', { href: '#', onclick: e => { e.preventDefault(); openJob(doc.job_id, () => {}); } }, `Job order ${doc.job_number} — ${doc.job_stage}`));

  add(body,
    h('div.toolbar', h('div', h('h2', `${DOC_TYPES[doc.type]} ${doc.number}`), h('div', statusBadge(doc.status), ' ', doc.job_stage ? stageBadge(doc.job_stage) : null)),
      h('div.toolbar-right', actions)),
    doc.status === 'void' ? h('p.notice.notice-bad', `Voided ${fmtDate(doc.voided_at, true)}: ${doc.void_reason}`) : null,
    doc.status === 'pricing' ? h('p.notice', 'Quotation request from the website. The customer was told you reply within 24 hours. Click Edit, add prices to the custom items (marked "estimate"), save, then click Email to customer.') : null,
    doc.source === 'website' && doc.public_token && doc.status !== 'pricing' ? h('p.related', h('a', { href: (cfg.siteUrl || '/') + '?mq_quote=' + doc.public_token, target: '_blank', rel: 'noopener' }, 'Customer\'s view of this quotation ↗')) : null,
    related.length ? h('p.related', related.map((r, i) => [i ? ' · ' : '', r])) : null,
    h('div.grid-2',
      h('section.card', h('h3', 'Customer'),
        h('div', h('strong', doc.customer_name)), doc.customer_company ? h('div', doc.customer_company) : null,
        h('div.muted', doc.customer_contact), doc.customer_email ? h('div.muted', doc.customer_email) : null, h('div.muted', doc.customer_address),
        doc.upload_id && doc.source === 'website' ? h('p.muted', 'Files from the customer: Sales → Customer uploads → Converted.') : null),
      h('section.card', h('h3', 'Details'), h('dl.facts',
        h('dt', 'Brand'), h('dd', brandName(doc.brand_id) + (doc.branch_id ? ' · ' + branchName(doc.branch_id) : '')),
        h('dt', 'Project'), h('dd', doc.title || '—'),
        h('dt', 'Date'), h('dd', fmtDate(doc.created_at, true)),
        doc.due_date ? [h('dt', 'Due'), h('dd', fmtDate(doc.due_date))] : null,
        doc.valid_until ? [h('dt', 'Valid until'), h('dd', fmtDate(doc.valid_until))] : null,
        h('dt', 'Prepared by'), h('dd', doc.created_by ? doc.created_by.name : '—'),
        h('dt', 'Sales person'), h('dd', doc.sales_person ? doc.sales_person.name : 'Not assigned')))),
    h('section.card', h('h3', 'Items'), table(doc.items, [
      { label: 'Description', render: i => h('div', i.description, i.is_estimate ? ' (estimate)' : '', i.specs ? h('div.muted.pre', i.specs) : null) },
      { label: 'Qty', render: i => `${Number(i.qty)} ${i.unit}`, class: 'num' },
      { label: 'Unit price', render: i => money(i.unit_price), class: 'num' },
      { label: 'Amount', render: i => money(i.amount), class: 'num' },
    ]),
    h('div.totals.totals-right',
      h('div', h('span', 'Subtotal'), h('span', money(doc.subtotal))),
      Number(doc.discount) ? h('div', h('span', 'Discount'), h('span', '−' + money(doc.discount))) : null,
      h('div.grand', h('span', 'Total'), h('span', money(doc.total))),
      doc.type === 'invoice' ? [h('div', h('span', 'Paid'), h('span', money(doc.amount_paid))), h('div.grand', h('span', 'Balance'), h('span', money(doc.balance)))] : null)),
    doc.payments.length ? h('section.card', h('h3', 'Payments'), table(doc.payments, [
      { label: 'Date', render: p => fmtDate(p.paid_at, true) },
      { label: 'Method', render: p => p.method.toUpperCase() + (p.reference ? ' · ' + p.reference : '') },
      { label: 'Received by', render: p => p.received_by ? p.received_by.name : '—' },
      { label: 'Amount', render: p => money(p.amount), class: 'num' },
    ])) : null,
    doc.terms || doc.notes ? h('section.card.grid-2', h('div', h('h3', 'Terms'), h('div.pre', doc.terms || '—')), h('div', h('h3', 'Notes'), h('div.pre', doc.notes || '—'))) : null);
}

/* ---------- Customer uploads ---------- */

/* ---------- Customers ---------- */

async function customerList(body) {
  const state = { search: '', page: 1, items: [] };
  const listEl = h('div');
  const moreEl = h('div.actions');
  const countEl = h('span.muted');
  const load = async (append = false) => {
    if (!append) { state.page = 1; clear(listEl, h('div.loading', 'Loading…')); }
    const res = await run(() => api('customers/directory', { query: { search: state.search, page: state.page } }));
    if (!res) return clear(listEl);
    state.items = append ? state.items.concat(res.items) : res.items;
    countEl.textContent = res.total ? `${res.total} customer${res.total === 1 ? '' : 's'}` : '';
    clear(listEl, table(state.items, [
      { label: 'Customer', render: c => h('div', h('strong', c.name), c.company ? h('div.muted', c.company) : null) },
      { label: 'Contact', render: c => h('div', c.contact || '—', c.email ? h('div.muted', c.email) : null) },
      { label: 'Documents', render: c => c.docs, class: 'num' },
      { label: 'Invoiced', render: c => (c.invoiced ? money(c.invoiced) : '—'), class: 'num' },
      { label: 'Website', render: c => [c.uploads ? `${c.uploads} upload${c.uploads === 1 ? '' : 's'}` : '', c.orders ? `${c.orders} order${c.orders === 1 ? '' : 's'}` : '', c.website && !c.uploads && !c.orders ? 'Quotation' : ''].filter(Boolean).join(' · ') || '—' },
      { label: 'Last activity', render: c => fmtDate(c.last_at) },
    ], { onRow: c => { location.hash = '#/sales/customers/' + c.id; }, emptyText: state.search ? 'No customer matches your search.' : 'No customers yet. They are added with every quotation, invoice, upload and online order.' }));
    clear(moreEl, res.more ? h('button.btn', { type: 'button', onclick: () => { state.page++; load(true); } }, 'Show more') : null);
  };
  let t;
  body.append(
    h('div.toolbar', h('h2', 'Customers'), countEl),
    h('div.toolbar', h('input', { type: 'search', placeholder: 'Search name, company, mobile, email…', oninput: e => { clearTimeout(t); t = setTimeout(() => { state.search = e.target.value; load(); }, 300); } })),
    h('p.muted', 'Everyone you made a quotation or invoice for, and customers from the website (quotation requests, uploads, online orders). Click one to see their history.'),
    listEl, moreEl);
  load();
}

async function customerView(body, id) {
  clear(body, h('div.loading', 'Loading…'));
  const c = await run(() => api('customers/' + id));
  if (!c) return clear(body, empty('Customer not found.'));
  const s = c.summary;
  const newDoc = type => {
    nextPrefill = { customer: { name: c.name, company: c.company, contact: c.contact, email: c.email, address: c.address } };
    location.hash = `#/sales/docs/new/${type}`;
  };
  const tile = (label, value, tone) => h('div.kpi', { class: tone ? 'tone-' + tone : '' }, h('span', label), h('strong', value));
  clear(body,
    h('div.toolbar',
      h('div', h('a.muted', { href: '#/sales/customers' }, '← Customers'), h('h2', c.name), c.company ? h('div.muted', c.company) : null),
      h('div.toolbar-right',
        h('button.btn', { type: 'button', onclick: () => newDoc('quotation') }, '+ Quotation'),
        h('button.btn.btn-primary', { type: 'button', onclick: () => newDoc('invoice') }, '+ Invoice'))),
    h('section.card',
      h('dl.facts',
        h('dt', 'Mobile'), h('dd', c.contact ? h('a', { href: 'tel:' + c.contact.replace(/[^\d+]/g, '') }, c.contact) : '—'),
        h('dt', 'Email'), h('dd', c.email ? h('a', { href: 'mailto:' + c.email }, c.email) : '—'),
        c.address ? [h('dt', 'Address'), h('dd', c.address)] : null,
        h('dt', 'Customer since'), h('dd', fmtDate(c.created_at))),
      h('div.kpis',
        tile('Invoiced', money(s.invoiced), 'navy'), tile('Paid', money(s.paid)), tile('Balance', money(s.balance), s.balance > 0 ? 'warn' : ''),
        tile('Quotations', s.quotations), tile('Job orders', s.jobs))),
    h('h3', 'Quotations, invoices and job orders'),
    table(c.documents, [
      { label: 'No.', render: d => h('div', h('strong', d.number), h('div.muted', DOC_TYPES[d.type] + (d.source === 'website' ? ' · website' : ''))) },
      { label: 'Date', render: d => fmtDate(d.created_at) },
      { label: 'Title', render: d => d.title || '—' },
      { label: 'Total', render: d => money(d.total), class: 'num' },
      { label: 'Status', render: d => h('div', statusBadge(d.status), d.job_stage ? h('div', stageBadge(d.job_stage)) : null) },
    ], { onRow: d => { location.hash = '#/sales/docs/' + d.id; }, emptyText: 'No documents yet.' }),
    c.orders.length ? [h('h3', 'Online orders'), table(c.orders, [
      { label: 'Ref', render: o => h('strong', o.reference) },
      { label: 'Date', render: o => fmtDate(o.created_at, true) },
      { label: 'Total', render: o => money(o.total), class: 'num' },
      { label: 'Payment', render: o => statusBadge(o.payment_status) },
    ], { onRow: o => { location.hash = '#/sales/orders/' + o.id; } })] : null,
    c.uploads.length ? [h('h3', 'Uploads'), table(c.uploads, [
      { label: 'Ref', render: u => h('strong', u.reference) },
      { label: 'Received', render: u => fmtDate(u.created_at, true) },
      { label: 'Files', render: u => u.file_count, class: 'num' },
      { label: 'Status', render: u => statusBadge(u.status) },
    ], { onRow: u => openUpload(u.id, () => customerView(body, id)) })] : null,
    can('mq_manage') ? h('section.card.privacy-card',
      h('h3', 'Privacy request'),
      h('p.muted', 'If this customer asks you to delete their personal data (Data Privacy Act), this removes their mobile, email and address from every document, deletes their uploaded files, payment screenshots and reviews, and removes them from this list. Invoices keep the name, number and amounts, which the BIR requires you to keep. This can\'t be undone.'),
      h('button.btn.btn-danger', {
        type: 'button',
        onclick: async e => {
          const btn = e.currentTarget;
          const ok = await confirmBox('Remove ' + c.name + '\'s personal data?', 'Their contact details, files and reviews are removed for good. Invoices keep the name and amounts (BIR). This can\'t be undone.', 'Remove personal data', true);
          if (!ok) return;
          const res = await run(() => api('customers/' + c.id + '/personal-data', { method: 'DELETE' }), btn);
          if (!res) return;
          const r = res.removed;
          toast(`Removed: ${r.documents} documents cleaned, ${r.uploads} uploads, ${r.orders} orders, ${r.files} files, ${r.reviews} reviews` + (r.archived && r.archived.length ? `. Some files are in archive ${r.archived.join(', ')} on the admin's PC: delete them there too.` : ''), 'ok');
          location.hash = '#/sales/customers';
        },
      }, 'Remove personal data')) : null);
}

async function uploads(body) {
  let status = 'new';
  const listEl = h('div');
  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    const rows = await run(() => api('uploads', { query: { status } }));
    if (!rows) return clear(listEl);
    clear(listEl, table(rows, [
      { label: 'Ref', render: u => h('strong', u.reference) },
      { label: 'Received', render: u => fmtDate(u.created_at, true) },
      { label: 'Customer', render: u => h('div', u.name, h('div.muted', [u.contact, u.email].filter(Boolean).join(' · '))) },
      { label: 'Brand / branch', render: u => brandName(u.brand_id) + (u.branch_id ? ' · ' + branchName(u.branch_id) : '') },
      { label: 'Files', render: u => u.file_count, class: 'num' },
      { label: 'Status', render: u => statusBadge(u.status) },
    ], { onRow: u => openUpload(u.id, load), emptyText: status === 'new' ? 'No new uploads.' : 'Nothing here.' }));
  };
  body.append(h('div.toolbar', h('h2', 'Customer uploads'),
    select('status', [{ value: 'new', label: 'New' }, { value: 'converted', label: 'Converted to document' }, { value: 'dismissed', label: 'Dismissed' }], status,
      { onchange: e => { status = e.target.value; load(); } })),
  h('p.muted', 'Files customers uploaded on the website, with the branch and time they were received. Turn one into a quotation or invoice to start a job order.'),
  listEl);
  load();
}

async function openUpload(id, onChange) {
  const u = await run(() => api(`uploads/${id}`));
  if (!u) return;
  const start = type => {
    nextPrefill = {
      upload_id: u.id, brand_id: u.brand_id, branch_id: u.branch_id, notes: u.notes,
      customer: { name: u.name, contact: u.contact, email: u.email },
      items: [{ description: '', specs: u.notes || '', qty: 1, unit: 'pc', unit_price: 0, is_estimate: type === 'quotation' }],
    };
    location.hash = `#/sales/docs/new/${type}`;
  };
  modal(`Upload ${u.reference}`, close => [
    h('dl.facts',
      h('dt', 'Received'), h('dd', fmtDate(u.created_at, true)),
      h('dt', 'Brand / branch'), h('dd', brandName(u.brand_id) + (u.branch_id ? ' · ' + branchName(u.branch_id) : '')),
      h('dt', 'Customer'), h('dd', u.name),
      h('dt', 'Contact'), h('dd', [u.contact, u.email].filter(Boolean).join(' · ')),
      h('dt', 'Instructions'), h('dd.pre', u.notes || '—')),
    h('h3', 'Files'),
    h('ul.files', u.files.map(f => h('li', f.archived ? h('span', f.name) : h('a', { href: fileUrl(f, true), target: '_blank', rel: 'noopener' }, f.name), h('span.muted', ' · ' + fileSize(f.size) + (f.archived ? ' · archived ' + fmtDate(f.archived) : '')), ' ', f.archived ? null : h('a.muted', { href: fileUrl(f) }, 'Download')))),
    u.status === 'new' ? h('div.actions',
      h('button.btn', { type: 'button', onclick: async e => { if (await run(() => api(`uploads/${u.id}`, { method: 'POST', body: { status: 'dismissed' } }), e.currentTarget)) { close(); onChange(); } } }, 'Dismiss'),
      h('button.btn', { type: 'button', onclick: () => { close(); start('quotation'); } }, 'Create quotation'),
      h('button.btn.btn-primary', { type: 'button', onclick: () => { close(); start('invoice'); } }, 'Create invoice'))
      : u.status === 'dismissed'
        ? h('div.actions', h('button.btn', { type: 'button', onclick: async e => { if (await run(() => api(`uploads/${u.id}`, { method: 'POST', body: { status: 'new' } }), e.currentTarget)) { close(); onChange(); } } }, 'Restore'))
        : (u.document_id ? h('div.actions', h('a.btn', { href: `#/sales/docs/${u.document_id}`, onclick: () => close() }, 'Open document')) : null),
  ], { wide: true });
}

/* ---------- Commissions ---------- */

async function commissions(body) {
  const rows = await run(() => api('finance/commissions', { query: { user_id: cfg.user.id } }));
  if (!rows) return;
  const sum = st => rows.filter(r => r.status === st).reduce((s, r) => s + r.amount, 0);
  body.append(h('h2', 'My commissions'),
    h('div.stats',
      h('div.stat', h('span', 'Pending (not yet delivered)'), h('strong', money(sum('pending')))),
      h('div.stat', h('span', 'Earned (delivered, unpaid)'), h('strong', money(sum('earned')))),
      h('div.stat', h('span', 'Paid'), h('strong', money(sum('paid'))))),
    h('p.muted', 'Commission is earned once the job order is delivered.'),
    table(rows, [
      { label: 'Job', render: r => h('div', h('strong', r.job_number), h('div.muted', r.invoice_number)) },
      { label: 'Customer', render: r => r.customer_name },
      { label: 'Stage', render: r => stageBadge(r.stage) },
      { label: 'Base', render: r => money(r.base_amount), class: 'num' },
      { label: 'Rate', render: r => r.rate + '%', class: 'num' },
      { label: 'Commission', render: r => money(r.amount), class: 'num' },
      { label: 'Status', render: r => statusBadge(r.status) },
    ], { emptyText: 'No commissions yet.' }));
}
