// Printable layouts: A4 documents (quotation / billing statement / invoice) and thermal receipts.

import { cfg, h, clear, money, fmtDate, brandName, branchName } from './core.js';

const TITLES = { quotation: 'QUOTATION / ESTIMATE', billing: 'BILLING STATEMENT', invoice: 'INVOICE' };

function printNow(node, mode) {
  const root = document.getElementById('print-root');
  clear(root, node);
  document.body.dataset.print = mode;
  const done = () => {
    delete document.body.dataset.print;
    clear(root);
    window.removeEventListener('afterprint', done);
  };
  window.addEventListener('afterprint', done);
  // Let layout settle (fonts/images) before opening the print dialog.
  setTimeout(() => window.print(), 50);
}

export function printDocument(doc) {
  const s = cfg.settings;
  const lines = (text) => String(text || '').split('\n').map((l, i) => [i ? h('br') : null, l]);
  const hasEstimate = doc.items.some(i => i.is_estimate);
  const node = h('div.print-doc',
    h('header.pd-head',
      h('div',
        h('img.pd-logo', { src: cfg.logos.color, alt: '' }),
        h('div.pd-company', s.company_name),
        h('div.pd-brand', brandName(doc.brand_id) + (doc.branch_id ? ' · ' + branchName(doc.branch_id) : '')),
        h('div.pd-small', lines(s.company_address)),
        h('div.pd-small', s.company_contact),
        s.company_tin ? h('div.pd-small', 'TIN: ' + s.company_tin) : null),
      h('div.pd-title',
        h('h1', TITLES[doc.type]),
        h('div', h('strong', 'No. '), doc.number || 'PENDING SYNC'),
        h('div', h('strong', 'Date: '), fmtDate(doc.created_at)),
        doc.valid_until ? h('div', h('strong', 'Valid until: '), fmtDate(doc.valid_until)) : null,
        doc.due_date ? h('div', h('strong', 'Due: '), fmtDate(doc.due_date)) : null)),
    h('section.pd-bill',
      h('div.pd-label', 'Bill to'),
      h('div.pd-strong', doc.customer_name),
      doc.customer_company ? h('div', doc.customer_company) : null,
      doc.customer_contact ? h('div', doc.customer_contact) : null,
      doc.customer_address ? h('div', doc.customer_address) : null,
      doc.title ? h('div.pd-project', h('strong', 'Project: '), doc.title) : null),
    h('table.pd-items',
      h('thead', h('tr', h('th', '#'), h('th', 'Description'), h('th.num', 'Qty'), h('th.num', 'Unit price'), h('th.num', 'Amount'))),
      h('tbody', doc.items.map((it, i) => h('tr',
        h('td', i + 1),
        h('td', h('div', it.description + (it.is_estimate ? ' *' : '')), it.specs ? h('div.pd-specs', lines(it.specs)) : null),
        h('td.num', `${Number(it.qty)} ${it.unit}`),
        h('td.num', money(it.unit_price)),
        h('td.num', money(it.amount)))))),
    h('table.pd-totals',
      h('tr', h('td', 'Subtotal'), h('td.num', money(doc.subtotal))),
      Number(doc.discount) ? h('tr', h('td', 'Discount'), h('td.num', '−' + money(doc.discount))) : null,
      h('tr.pd-grand', h('td', 'Total'), h('td.num', money(doc.total))),
      doc.type === 'billing' && Number(s.downpayment_percent)
        ? h('tr', h('td', `Required downpayment (${s.downpayment_percent}%)`), h('td.num', money(doc.total * s.downpayment_percent / 100))) : null,
      doc.type === 'invoice' ? [
        h('tr', h('td', 'Amount paid'), h('td.num', money(doc.amount_paid))),
        h('tr.pd-grand', h('td', 'Balance due'), h('td.num', money(doc.balance))),
      ] : null),
    hasEstimate ? h('p.pd-small', '* Estimated price — final amount will be confirmed once quantity and design are final.') : null,
    doc.type === 'invoice' && doc.payments && doc.payments.length ? h('section.pd-terms',
      h('div.pd-label', 'Payments received'),
      doc.payments.map(p => h('div', `${fmtDate(p.paid_at)} — ${money(p.amount)} (${p.method}${p.reference ? ' ' + p.reference : ''})`))) : null,
    doc.terms ? h('section.pd-terms', h('div.pd-label', 'Terms & conditions'), h('div', lines(doc.terms))) : null,
    doc.notes ? h('section.pd-terms', h('div.pd-label', 'Notes'), h('div', lines(doc.notes))) : null,
    h('footer.pd-sign',
      h('div', h('div.pd-line'), (doc.sales_person && doc.sales_person.name) || (doc.created_by && doc.created_by.name) || '', h('div.pd-small', 'Prepared by')),
      h('div', h('div.pd-line'), h('div.pd-small', 'Received / Conforme'))));
  printNow(node, 'doc');
}

export function printReceipt(sale) {
  const s = cfg.settings;
  const node = h('div.print-receipt',
    h('div.pr-center', h('img.pr-logo', { src: cfg.logos.light, alt: '' })),
    h('div.pr-center', h('strong', s.company_name)),
    h('div.pr-center', branchName(sale.branch_id)),
    h('div.pr-center', s.company_contact),
    h('div.pr-rule'),
    h('div', 'Receipt: ' + (sale.official_no || sale.receipt_no)),
    // Offline sales get their official number when they sync; the device number stays as the reference.
    sale.official_no ? h('div.pr-small', 'Ref: ' + sale.receipt_no) : h('div.pr-small', 'Official no. is given when this sale syncs'),
    h('div', fmtDate(sale.sold_at, true)),
    h('div', 'Cashier: ' + ((sale.cashier && sale.cashier.name) || cfg.user.name)),
    h('div.pr-rule'),
    sale.items.map(it => [
      h('div', it.name),
      h('div.pr-row', h('span', `  ${Number(it.qty)} × ${money(it.price)}`), h('span', money(it.amount))),
    ]),
    h('div.pr-rule'),
    h('div.pr-row', h('span', 'Subtotal'), h('span', money(sale.subtotal))),
    Number(sale.discount) ? h('div.pr-row', h('span', 'Discount'), h('span', '−' + money(sale.discount))) : null,
    h('div.pr-row.pr-big', h('span', 'TOTAL'), h('span', money(sale.total))),
    h('div.pr-row', h('span', sale.method.toUpperCase()), h('span', money(sale.tendered))),
    sale.method === 'cash' ? h('div.pr-row', h('span', 'Change'), h('span', money(sale.change_due))) : null,
    sale.reference ? h('div', 'Ref: ' + sale.reference) : null,
    sale.status === 'void' ? h('div.pr-center.pr-big', '*** VOID ***') : null,
    h('div.pr-rule'),
    String(s.receipt_footer || '').split('\n').map(l => h('div.pr-center', l)));
  printNow(node, 'receipt');
}
