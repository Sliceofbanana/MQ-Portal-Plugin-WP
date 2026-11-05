// Admin, Finance & Ops: department reports, owner dashboard, invoice tracker, commissions, attendance, settings, staff & permissions.

import {
  cfg, can, h, clear, api, run, toast, modal, confirmBox, promptBox, field, select, formData, table,
  money, fmtDate, fmtTime, today, duration, stageBadge, statusBadge, badge, brandName, branchName, empty, STAGES, DOC_TYPES, avatar, photoPicker,
} from '../core.js';
import { openJob, jobColumns } from '../jobs.js';
import { printReceipt } from '../print.js';
import { icon } from '../icons.js';
import { dailyCheck as daily } from './daily.js';

export async function render(view, params) {
  const tabs = [];
  if (can('mq_view_dashboard') || can('mq_manage')) tabs.push({ key: 'daily', label: 'Daily check' });
  if (can('mq_view_dashboard')) tabs.push({ key: 'reports', label: 'Department reports' }, { key: 'dashboard', label: 'Sales by brand' }, { key: 'invoices', label: 'All invoices' });
  if (can('mq_finance')) tabs.push({ key: 'commissions', label: 'Commissions' }, { key: 'attendance', label: 'Attendance' });
  // Staff (accounts and their access rights) and Account requests (new staff asking to join) are separate.
  if (can('mq_reviews')) tabs.push({ key: 'reviews', label: 'Reviews', count: cfg.pendingReviews || 0 });
  if (can('mq_manage')) tabs.push({ key: 'staff', label: 'Staff' }, { key: 'requests', label: 'Account requests', count: cfg.pendingSignups || 0 }, { key: 'settings', label: 'Settings' });
  if (params[0] === 'staff' && params[1] === 'requests') { location.replace('#/admin/requests'); return; } // old link
  const sub = tabs.some(t => t.key === params[0]) ? params[0] : tabs[0].key;
  // Each page is in the side menu (Admin → Department reports…); Settings is at the bottom of the menu.
  const body = h('div');
  view.append(body);
  const fn = { daily, reports, dashboard, invoices, commissions, attendance, reviews, staff, requests, settings }[sub];
  return fn(body, params.slice(1));
}

/* ---------- Department reports ---------- */

const pct = v => (v === null || v === undefined ? '—' : v + '%');
const kpi = (label, value, tone = '') => h('div.kpi', { class: tone ? 'tone-' + tone : '' }, h('span', label), h('strong', value));

function deptCard(title, ic, link, kpis, ...extra) {
  return h('section.card.dept-card',
    h('div.section-head', h('h3', icon(ic, 18), title), link ? h('a.section-link', { href: link }, 'Open →') : null),
    h('div.kpis', kpis), extra);
}

function bars(rows, labelKey, valueKey, fmt = money) {
  if (!rows.length) return empty('No data for this period.');
  const max = Math.max(1, ...rows.map(r => r[valueKey]));
  return h('div.hbars', rows.map(r => h('div.hbar', h('span.hbar-label', r[labelKey]),
    h('span.hbar-track', h('span.hbar-fill', { style: { width: Math.round(r[valueKey] / max * 100) + '%' } })), h('strong', fmt(r[valueKey])))));
}

async function reports(body) {
  const state = { from: today(), to: today() };
  const out = h('div');
  const load = async () => {
    const d = await run(() => api('dashboard/departments', { query: state }));
    if (!d) return;
    const label = d.from === d.to ? fmtDate(d.from) : `${fmtDate(d.from)} – ${fmtDate(d.to)}`;
    const s = d.sales; const c = d.creatives; const p = d.production; const pos = d.pos; const a = d.attendance; const f = d.finance;
    clear(out,
      h('p.muted', 'Period: ', h('strong', label), '. Counts marked "now" are the current situation regardless of the period.'),
      h('div.dept-grid',
        deptCard('Sales', 'sales', '#/admin/invoices', [
          kpi('Invoiced', money(s.invoiced), 'navy'), kpi('Invoices', s.invoices), kpi('Quotations', s.quotations), kpi('Billing statements', s.billing),
          kpi('Quotation → sale', pct(s.conversion_rate)), kpi('Uploads received', s.uploads_received), kpi('Uploads converted', s.uploads_converted),
          kpi('Uploads waiting (now)', s.uploads_waiting, s.uploads_waiting ? 'warn' : ''), kpi('Jobs stuck in Sales (now)', s.stuck_in_sales, s.stuck_in_sales ? 'warn' : ''),
        ], h('h4', 'By sales person'), table(s.by_person, [
          { label: 'Sales person', render: r => r.name },
          { label: 'Invoices', render: r => r.invoices, class: 'num' },
          { label: 'Invoiced', render: r => money(r.total), class: 'num' },
          { label: 'Collected', render: r => money(r.paid), class: 'num' },
          { label: 'Delivered', render: r => r.delivered, class: 'num' },
        ], { emptyText: 'No invoices in this period.' })),

        deptCard('Creatives', 'creatives', '#/creatives', [
          kpi('Received', c.received), kpi('Approved for printing', c.approved, 'navy'), kpi('Edits requested', c.edits_requested),
          kpi('Edited designs attached', c.designs_attached), kpi('Returned to Sales', c.returned_to_sales),
          kpi('Avg. review time', c.avg_turnaround_hours === null ? '—' : c.avg_turnaround_hours + ' h'),
          kpi('Waiting (now)', c.waiting_now, c.waiting_now ? 'warn' : ''), kpi('Needs editing (now)', c.needs_editing_now),
        ]),

        deptCard('Production & QC', 'production', '#/production', [
          kpi('Printed', p.printed, 'navy'), kpi('QC passed', p.qc_approved), kpi('QC rejected', p.qc_rejected, p.qc_rejected ? 'warn' : ''),
          kpi('QC reject rate', pct(p.qc_reject_rate)), kpi('Delivered', p.delivered), kpi('On time', pct(p.on_time_rate)),
          kpi('Avg. days to deliver', p.avg_days_to_deliver === null ? '—' : p.avg_days_to_deliver),
          kpi('Printing (now)', p.in_production), kpi('In QC (now)', p.in_qc), kpi('Ready (now)', p.ready),
          kpi('Overdue (now)', p.overdue, p.overdue ? 'bad' : ''), kpi('Rush open (now)', p.rush_open),
        ]),

        deptCard('POS (EasyPrint shops)', 'pos', '#/admin/dashboard', [
          kpi('Sales', money(pos.total), 'navy'), kpi('Receipts', pos.receipts), kpi('Average sale', money(pos.avg_ticket)),
          kpi('Voided', `${pos.voided} · ${money(pos.voided_total)}`, pos.voided ? 'warn' : ''),
        ],
        h('div.grid-2',
          h('div', h('h4', 'By branch'), bars(pos.by_branch, 'name', 'total')),
          h('div', h('h4', 'By payment method'), bars(pos.by_method.map(m => ({ ...m, method: m.method.toUpperCase() })), 'method', 'total'))),
        h('h4', 'Top items'), bars(pos.top_items, 'name', 'amount')),

        deptCard('Attendance', 'clock', '#/admin/attendance', [
          kpi('On duty (now)', a.on_duty.filter(x => x.state === 'working').length, 'navy'),
          kpi('On break (now)', a.on_duty.filter(x => x.state === 'break').length),
          kpi('Shifts', a.shifts), kpi('Hours worked', duration(a.worked_minutes)),
          kpi('Blocked clock-ins', a.blocked_clock_ins, a.blocked_clock_ins ? 'warn' : ''),
        ], h('h4', 'Hours by person'), table(a.people, [
          { label: 'Staff', render: r => r.name },
          { label: 'Shifts', render: r => r.shifts, class: 'num' },
          { label: 'Worked', render: r => duration(r.worked_minutes), class: 'num' },
          { label: 'Breaks', render: r => duration(r.break_minutes), class: 'num' },
          { label: 'Auto clock-outs', render: r => r.auto_closed || '—', class: 'num' },
        ], { emptyText: 'No shifts in this period.' })),

        deptCard('Finance', 'wallet', '#/admin/commissions', [
          kpi('Invoiced', money(f.invoiced), 'navy'), kpi('Collected on invoices', money(f.collected)), kpi('POS sales', money(f.pos_cash_in)),
          kpi('Unpaid balances (now)', money(f.receivables), f.receivables ? 'warn' : ''),
          kpi('Commission pending (now)', money(f.commission_pending)), kpi('Commission to pay (now)', money(f.commission_to_pay)),
          kpi('Commission paid', money(f.commission_paid)),
        ], h('h4', 'Collected by method'), bars(f.collected_by_method.map(m => ({ ...m, method: m.method.toUpperCase() })), 'method', 'total'))));
  };
  body.append(h('div.toolbar', h('h2', 'Department reports'),
    h('div.toolbar-right', rangePicker(state, load), h('button.btn', { type: 'button', onclick: () => window.print() }, 'Print'))), out);
  load();
}

/* ---------- Dashboard ---------- */

function rangePicker(state, onChange) {
  const from = h('input', { type: 'date', value: state.from, onchange: () => { state.from = from.value; onChange(); } });
  const to = h('input', { type: 'date', value: state.to, onchange: () => { state.to = to.value; onChange(); } });
  const preset = (label, f, t) => h('button.btn.btn-sm', { type: 'button', onclick: () => { state.from = from.value = f; state.to = to.value = t; onChange(); } }, label);
  const d = new Date();
  const ymd = x => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
  const yest = new Date(d); yest.setDate(d.getDate() - 1);
  const weekStart = new Date(d); weekStart.setDate(d.getDate() - 6);
  const monthStart = new Date(d.getFullYear(), d.getMonth(), 1);
  return h('div.range', from, h('span', 'to'), to,
    preset('Today', today(), today()), preset('Yesterday', ymd(yest), ymd(yest)), preset('Last 7 days', ymd(weekStart), today()), preset('This month', ymd(monthStart), today()));
}

async function dashboard(body) {
  const state = { from: today(), to: today() };
  const out = h('div');
  const load = async () => {
    const d = await run(() => api('dashboard', { query: state }));
    if (!d) return;
    const label = d.from === d.to ? fmtDate(d.from) : `${fmtDate(d.from)} – ${fmtDate(d.to)}`;
    clear(out,
      h('section.hero-stat',
        h('span', `Total sales of MQ Prints · ${label}`),
        h('strong', money(d.total)),
        h('small', `Payments collected on invoices: ${money(d.collected)}`)),
      h('div.pipeline', Object.entries(d.pipeline).map(([stage, n]) => h('a.pipe', { href: '#/admin/invoices', class: 'stage-' + stage }, h('strong', n), h('span', STAGES[stage])))),
      h('div.brand-list', d.brands.map(b => brandRow(b, state))));
  };
  body.append(h('div.toolbar', h('h2', 'Sales dashboard'), rangePicker(state, load)), out);
  load();
  const timer = setInterval(load, 60000);
  return () => clearInterval(timer);
}

function brandRow(b, state) {
  const detail = h('div.brand-detail');
  let open = false;
  const toggle = h('button.brand-row', {
    type: 'button', 'aria-expanded': 'false',
    onclick: () => {
      open = !open;
      toggle.setAttribute('aria-expanded', String(open));
      if (!open) return clear(detail);
      if (b.has_branches) {
        clear(detail, b.branches.length ? b.branches.map(br => branchRow(br, state)) : empty('No branches.'));
      } else {
        brandDetail(detail, b, state);
      }
    },
  },
  h('span.chev', '›'),
  h('span.brand-name', b.name),
  h('span.brand-meta', b.has_branches ? `${b.pos_count} receipts · ${b.invoice_count} invoices` : `${b.invoice_count} invoices · ${money(b.collected)} collected`),
  h('strong.brand-total', money(b.total)));
  return h('div.dash-brand', toggle, detail);
}

function branchRow(br, state) {
  const detail = h('div.branch-detail');
  let open = false;
  const btn = h('button.branch-row', {
    type: 'button', 'aria-expanded': 'false',
    onclick: async () => {
      open = !open;
      btn.setAttribute('aria-expanded', String(open));
      if (!open) return clear(detail);
      clear(detail, h('div.loading', 'Loading…'));
      const d = await run(() => api(`dashboard/branch/${br.id}`, { query: state }));
      if (!d) return clear(detail);
      const receiptCols = [
        { label: 'Receipt', render: s => h('div', h('strong', s.official_no || s.receipt_no), s.official_no ? h('div.muted', s.receipt_no) : null) },
        { label: 'Time', render: s => fmtDate(s.sold_at, true) },
        { label: 'Cashier', render: s => s.cashier ? s.cashier.name : '—' },
        { label: 'Items', render: s => s.items.map(i => `${Number(i.qty)}× ${i.name}`).join(', ') },
        { label: 'Method', render: s => s.method.toUpperCase() },
        { label: 'Total', render: s => money(s.total), class: 'num' },
      ];
      clear(detail,
        h('h4', `Receipts (${d.receipts.length})`),
        table(d.receipts, receiptCols, { onRow: printReceipt, emptyText: 'No receipts.' }),
        h('h4', `Voided receipts (${d.voided.length})`),
        table(d.voided, [...receiptCols, { label: 'Reason', render: s => `${s.void_reason} (${s.voided_by ? s.voided_by.name : ''})` }], { emptyText: 'No voided receipts.' }),
        d.invoices.length ? [h('h4', `Invoices (${d.invoices.length})`), invoiceTable(d.invoices)] : null);
    },
  },
  h('span.chev', '›'), h('span.brand-name', br.name),
  h('span.brand-meta', `${br.pos_count} receipts · ${br.void_count} voided (${money(br.void_total)})`),
  h('strong.brand-total', money(br.total)));
  return h('div.dash-branch', btn, detail);
}

async function brandDetail(el, b, state) {
  clear(el, h('div.loading', 'Loading…'));
  const d = await run(() => api(`dashboard/brand/${b.id}`, { query: state }));
  if (!d) return clear(el);
  const reload = () => brandDetail(el, b, state);
  clear(el,
    h('h4', `Completed orders (${d.completed.length})`),
    table(d.completed, [...jobColumns.slice(0, 2), { label: 'Delivered', render: j => fmtDate(j.delivered_at, true) }, { label: 'Total', render: j => money(j.total), class: 'num' }],
      { onRow: j => openJob(j.id, reload), emptyText: 'No orders delivered in this period.' }),
    h('h4', `Upcoming orders (${d.upcoming.length})`),
    table(d.upcoming, [...jobColumns.slice(0, 2), jobColumns[3], jobColumns[4], { label: 'Balance', render: j => money(j.balance), class: 'num' }],
      { onRow: j => openJob(j.id, reload), emptyText: 'No open orders.' }),
    h('h4', `Invoices in this period (${d.invoices.length})`),
    invoiceTable(d.invoices));
}

function invoiceTable(rows) {
  return table(rows, [
    { label: 'Invoice', render: d => h('strong', d.number) },
    { label: 'Customer', render: d => d.customer_name },
    { label: 'Date', render: d => fmtDate(d.created_at) },
    { label: 'Total', render: d => money(d.total), class: 'num' },
    { label: 'Balance', render: d => money(d.balance), class: 'num' },
    { label: 'Status', render: d => statusBadge(d.status) },
  ], { onRow: d => { location.hash = `#/sales/docs/${d.id}`; }, emptyText: 'No invoices.' });
}

/* ---------- All invoices with job progress ---------- */

const FLOW = ['sales', 'creatives', 'production', 'qc', 'ready', 'delivered'];

function progress(stage) {
  if (!stage) return h('span.muted', 'No job order');
  if (stage === 'cancelled') return stageBadge(stage);
  const idx = FLOW.indexOf(stage);
  return h('div.progress', { title: STAGES[stage] },
    FLOW.map((s, i) => h('span.step', { class: i < idx ? 'done' : i === idx ? 'current' : '' })),
    h('small', STAGES[stage]));
}

async function invoices(body) {
  const state = { brand_id: '', search: '', status: '' };
  const listEl = h('div');
  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    const rows = await run(() => api('documents', { query: { type: 'invoice', ...state } }));
    if (!rows) return clear(listEl);
    clear(listEl, table(rows, [
      { label: 'Invoice', render: d => h('div', h('strong', d.number), h('div.muted', d.job_number || '')) },
      { label: 'Customer', render: d => h('div', d.customer_name, h('div.muted', d.title)) },
      { label: 'Brand', render: d => brandName(d.brand_id) },
      { label: 'Sales', render: d => d.sales_person ? d.sales_person.name : h('span.text-bad', 'Unassigned') },
      { label: 'Total / balance', render: d => h('div', money(d.total), h('div.muted', 'Bal ' + money(d.balance))), class: 'num' },
      { label: 'Payment', render: d => statusBadge(d.status) },
      { label: 'Progress', render: d => progress(d.job_stage) },
    ], { onRow: d => (d.job_id ? openJob(d.job_id, load) : (location.hash = `#/sales/docs/${d.id}`)), emptyText: 'No invoices.' }));
  };
  let t;
  body.append(h('div.toolbar', h('h2', 'All invoices / job orders'),
    h('div.toolbar-right',
      h('input', { type: 'search', placeholder: 'Search…', oninput: e => { clearTimeout(t); t = setTimeout(() => { state.search = e.target.value; load(); }, 300); } }),
      select('brand', [{ value: '', label: 'All brands' }, ...cfg.brands.map(b => ({ value: b.id, label: b.name }))], '', { onchange: e => { state.brand_id = e.target.value; load(); } }),
      select('status', [{ value: '', label: 'Any payment' }, { value: 'unpaid', label: 'Unpaid' }, { value: 'partial', label: 'Partial' }, { value: 'paid', label: 'Paid' }, { value: 'void', label: 'Void' }], '', { onchange: e => { state.status = e.target.value; load(); } }))),
  listEl);
  load();
}

/* ---------- Commissions ---------- */

async function commissions(body) {
  const state = { status: 'earned', user_id: '' };
  const selected = new Set();
  const listEl = h('div');
  const totalEl = h('strong');
  const payBtn = h('button.btn.btn-primary', { type: 'button', disabled: true, onclick: async e => {
    if (!(await confirmBox('Mark as paid', `Mark ${selected.size} commission(s) as paid?`, 'Mark paid'))) return;
    if (await run(() => api('finance/commissions/pay', { method: 'POST', body: { ids: [...selected] } }), e.currentTarget)) { toast('Marked as paid', 'ok'); load(); }
  } }, 'Mark selected as paid');
  const load = async () => {
    selected.clear();
    payBtn.disabled = true;
    const rows = await run(() => api('finance/commissions', { query: state }));
    if (!rows) return;
    totalEl.textContent = money(rows.reduce((s, r) => s + r.amount, 0));
    clear(listEl, table(rows, [
      { label: '', render: r => r.status === 'earned' ? h('input', { type: 'checkbox', 'aria-label': 'Select', onclick: e => e.stopPropagation(), onchange: e => { e.target.checked ? selected.add(r.id) : selected.delete(r.id); payBtn.disabled = !selected.size; } }) : '' },
      { label: 'Sales person', render: r => r.sales_person ? r.sales_person.name : '—' },
      { label: 'Job', render: r => h('div', h('strong', r.job_number), h('div.muted', `${r.brand_name} · ${r.invoice_number}`)) },
      { label: 'Customer', render: r => r.customer_name },
      { label: 'Base', render: r => money(r.base_amount), class: 'num' },
      { label: 'Rate', render: r => r.rate + '%', class: 'num' },
      { label: 'Commission', render: r => money(r.amount), class: 'num' },
      { label: 'Status', render: r => h('div', statusBadge(r.status), r.earned_at ? h('div.muted', 'Delivered ' + fmtDate(r.earned_at)) : null) },
    ], { emptyText: 'No commissions match.' }),
    h('button.btn', { type: 'button', onclick: () => csv('commissions.csv', rows.map(r => ({ sales_person: r.sales_person && r.sales_person.name, job: r.job_number, invoice: r.invoice_number, brand: r.brand_name, customer: r.customer_name, base: r.base_amount, rate: r.rate, amount: r.amount, status: r.status, earned_at: r.earned_at, paid_at: r.paid_at }))) }, 'Export CSV'));
  };
  body.append(h('div.toolbar', h('h2', 'Commissions'),
    h('div.toolbar-right',
      select('status', [{ value: 'earned', label: 'Earned (to pay)' }, { value: 'pending', label: 'Pending (not delivered or not fully paid)' }, { value: 'paid', label: 'Paid' }, { value: '', label: 'All' }], state.status, { onchange: e => { state.status = e.target.value; load(); } }),
      select('user', [{ value: '', label: 'All sales staff' }, ...cfg.salesStaff.map(u => ({ value: u.id, label: u.name }))], '', { onchange: e => { state.user_id = e.target.value; load(); } }))),
  h('p', 'Total shown: ', totalEl, '. Commission is earned when the job order is delivered.'),
  h('div.actions.actions-left', payBtn), listEl);
  load();
}

function csv(name, rows) {
  if (!rows.length) return toast('Nothing to export.', 'warn');
  const cols = Object.keys(rows[0]);
  const esc = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const text = [cols.join(','), ...rows.map(r => cols.map(c => esc(r[c])).join(','))].join('\r\n');
  const a = h('a', { href: URL.createObjectURL(new Blob([text], { type: 'text/csv' })), download: name });
  document.body.append(a);
  a.click();
  a.remove();
}

/* ---------- Attendance ---------- */

async function attendance(body) {
  const week = new Date(); week.setDate(week.getDate() - 6);
  const state = { from: `${week.getFullYear()}-${String(week.getMonth() + 1).padStart(2, '0')}-${String(week.getDate()).padStart(2, '0')}`, to: today(), user_id: '' };
  const listEl = h('div');
  const auditEl = h('div');
  let staffList = [];
  try { staffList = can('mq_manage') ? await api('admin/users') : []; } catch (e) { /* optional filter */ }
  const load = async () => {
    const rows = await run(() => api('finance/attendance', { query: state }));
    if (!rows) return;
    const totals = {};
    rows.forEach(r => { const n = r.user ? r.user.name : '?'; totals[n] = (totals[n] || 0) + (r.worked_minutes || 0) / 60; });
    clear(listEl,
      h('div.stats', Object.entries(totals).map(([n, hrs]) => h('div.stat', h('span', n), h('strong', hrs.toFixed(1) + ' h')))),
      table(rows, [
        { label: 'Staff', render: r => r.user ? r.user.name : '—' },
        { label: 'Branch', render: r => r.branch_name || '—' },
        { label: 'Clock in', render: r => fmtDate(r.time_in, true) },
        { label: 'Clock out', render: r => r.time_out ? fmtDate(r.time_out, true) + (r.auto_closed ? ' (auto)' : '') : h('span.badge.badge-ok', 'On duty') },
        { label: 'Breaks', render: r => r.breaks.length ? `${r.breaks.length} · ${duration(r.break_minutes)}` : '—' },
        { label: 'Worked', render: r => duration(r.worked_minutes), class: 'num' },
        { label: 'Login from', render: r => h('div.muted', r.in_ip, r.in_lat ? h('div', h('a', { href: `https://maps.google.com/?q=${r.in_lat},${r.in_lng}`, target: '_blank', rel: 'noopener' }, `GPS ±${r.in_accuracy || '?'} m`)) : null) },
      ], { emptyText: 'No attendance records.' }),
      h('button.btn', { type: 'button', onclick: () => csv('attendance.csv', rows.map(r => ({ staff: r.user && r.user.name, branch: r.branch_name, clock_in: r.time_in, clock_out: r.time_out, break_minutes: r.break_minutes, worked_hours: (r.worked_minutes / 60).toFixed(2), auto_closed: r.auto_closed ? 'yes' : '' }))) }, 'Export CSV'));
  };
  const loadAudit = async () => {
    const rows = await run(() => api('finance/audit'));
    if (!rows) return;
    clear(auditEl, table(rows, [
      { label: 'When', render: r => fmtDate(r.created_at, true) },
      { label: 'Event', render: r => r.event },
      { label: 'User', render: r => r.username || (r.user_id ? '#' + r.user_id : '—') },
      { label: 'Result', render: r => h('span', { class: r.result === 'ok' ? '' : 'text-bad' }, r.result) },
      { label: 'Reason', render: r => r.reason },
      { label: 'IP', render: r => r.ip },
    ], { emptyText: 'No login activity.' }));
  };
  body.append(
    h('div.toolbar', h('h2', 'Attendance'),
      h('div.toolbar-right',
        h('input', { type: 'date', value: state.from, onchange: e => { state.from = e.target.value; load(); } }),
        h('input', { type: 'date', value: state.to, onchange: e => { state.to = e.target.value; load(); } }),
        staffList.length ? select('user', [{ value: '', label: 'All staff' }, ...staffList.map(u => ({ value: u.id, label: u.name }))], '', { onchange: e => { state.user_id = e.target.value; load(); } }) : null)),
    listEl,
    h('details.card', { ontoggle: e => { if (e.target.open) loadAudit(); } }, h('summary', h('strong', 'Login log (including blocked attempts)')), auditEl));
  load();
}

/* ---------- Odoo-style building blocks ---------- */

// Control panel: breadcrumbs on the left, search and buttons on the right. Sticks under the top bar.
function controlPanel(crumbs, ...right) {
  return h('div.ctrl-panel',
    h('nav.crumbs', { 'aria-label': 'Breadcrumb' }, crumbs.map((c, i) => (i < crumbs.length - 1
      ? [h('a', { href: c[1] }, c[0]), h('span.crumb-sep', '/')]
      : h('strong', c[0])))),
    h('div.ctrl-right', right));
}

// One setting: optional checkbox on the left, then a bold title, a short help text and its fields.
function setting({ title, help, check, body, wide }) {
  return h('div.set-box', { class: wide ? 'is-wide' : '' },
    h('div.set-check', check || null),
    // With a checkbox, the title is its label (clicking the title ticks the box).
    h('div.set-main', check && check.id ? h('label.set-label', { for: check.id }, title) : h('div.set-label', title), help ? h('div.set-help', help) : null, body ? h('div.set-body', body) : null));
}

const inputSuffix = (input, suffix) => h('div.input-suffix', input, h('span', suffix));

function switchInput(attrs) {
  return h('span.switch', h('input', { type: 'checkbox', ...attrs }), h('span.switch-ui', { 'aria-hidden': 'true' }));
}

/* ---------- Website reviews ---------- */

const RV_STATUS = { pending: ['Waiting', 'warn'], shown: ['On the website', 'ok'], hidden: ['Hidden', ''] };
const RV_SOURCE = { website: 'Website form', manual: 'Added by you', google: 'Google' };
const stars = n => h('span.rv-stars', { 'aria-label': n + ' out of 5 stars', title: n + ' out of 5 stars' }, '★'.repeat(n), h('span.rv-stars-off', '★'.repeat(5 - n)));
const pendingReviews = n => { cfg.pendingReviews = n; window.dispatchEvent(new CustomEvent('mqp-signups')); };

async function reviews(body, params) {
  const st = { status: ['pending', 'shown', 'hidden', 'all'].includes(params[0]) ? params[0] : (cfg.pendingReviews ? 'pending' : 'all'), q: '', source: '', stars: '', page: 1 };
  const picked = new Set();
  const tiles = h('div.kpis.rv-tiles');
  const pills = h('div.rv-pills', { role: 'group', 'aria-label': 'Show' });
  const bar = h('div.rv-bulk');
  const listEl = h('div.rv-list');
  const pager = h('div.rv-pager');
  let data = null;

  const load = async () => {
    const d = await run(() => api('reviews', { query: { status: st.status === 'all' ? '' : st.status, q: st.q, source: st.source, stars: st.stars, page: st.page } }));
    if (!d) return;
    data = d;
    picked.clear();
    if (cfg.pendingReviews !== d.counts.pending) pendingReviews(d.counts.pending);
    const all = d.counts.pending + d.counts.shown + d.counts.hidden;
    clear(tiles,
      kpi('Waiting for approval', d.counts.pending, d.counts.pending ? 'warn' : ''),
      kpi('On the website', d.counts.shown, 'navy'),
      kpi('Average on the website', d.average === null ? '—' : d.average + ' ★'),
      kpi('Google rating', d.google.rating ? d.google.rating + ' ★ (' + d.google.count + ')' : '—'),
      kpi('Private feedback (below 4 ★)', d.private));
    clear(pills, [['pending', 'Waiting', d.counts.pending], ['shown', 'On the website', d.counts.shown], ['hidden', 'Hidden', d.counts.hidden], ['all', 'All', all]].map(([k, label, n]) =>
      h('button', { type: 'button', class: st.status === k ? 'active' : '', 'aria-pressed': String(st.status === k), onclick: () => { st.status = k; st.page = 1; history.replaceState(null, '', '#/admin/reviews/' + k); load(); } }, label, h('span.count', String(n)))));
    drawList();
  };

  const drawBar = () => clear(bar, picked.size
    ? [h('strong', picked.size + ' selected'),
      h('button.btn.btn-sm.btn-primary', { type: 'button', onclick: e => bulk('show', e.currentTarget) }, icon('check', 14), 'Approve / show'),
      h('button.btn.btn-sm', { type: 'button', onclick: e => bulk('hide', e.currentTarget) }, 'Hide'),
      h('button.btn.btn-sm.btn-danger', { type: 'button', onclick: e => bulk('delete', e.currentTarget) }, 'Delete'),
      h('button.btn.btn-sm', { type: 'button', onclick: () => { picked.clear(); drawList(); } }, 'Clear')]
    : data && data.rows.length ? h('label.rv-all', h('input', { type: 'checkbox', onchange: e => { data.rows.forEach(r => (e.target.checked ? picked.add(r.id) : picked.delete(r.id))); drawList(); } }), 'Select all on this page') : null);

  const drawList = () => {
    drawBar();
    if (!data.rows.length) {
      clear(listEl, empty(st.q || st.source || st.stars ? 'No reviews match these filters.' : st.status === 'pending' ? 'No reviews waiting for approval. 🎉' : 'No reviews here yet.'));
      return clear(pager);
    }
    clear(listEl, data.rows.map(row));
    clear(pager, data.pages > 1 ? [
      h('button.btn.btn-sm', { type: 'button', disabled: st.page <= 1, onclick: () => { st.page--; load(); } }, '← Newer'),
      h('span.muted', 'Page ' + data.page + ' of ' + data.pages + ' · ' + data.total + ' reviews'),
      h('button.btn.btn-sm', { type: 'button', disabled: st.page >= data.pages, onclick: () => { st.page++; load(); } }, 'Older →')] : null);
  };

  const row = r => {
    const low = r.rating < 4;
    const [label, tone] = RV_STATUS[r.status] || [r.status, ''];
    const pic = r.photo ? h('img.avatar', { src: r.photo, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }) : avatar({ name: r.name });
    const set = (body, btn, msg) => run(async () => {
      const res = await api('reviews/' + r.id, { method: 'POST', body });
      toast(msg, 'ok');
      load();
      return res;
    }, btn);
    return h('article.card.rv-card', { class: 'is-' + r.status },
      h('input.rv-pick', { type: 'checkbox', checked: picked.has(r.id), 'aria-label': 'Select the review of ' + r.name, onchange: e => { e.target.checked ? picked.add(r.id) : picked.delete(r.id); drawBar(); } }),
      pic,
      h('div.rv-main',
        h('div.rv-head', h('strong', r.name), stars(r.rating), badge(label, tone), badge(RV_SOURCE[r.source] || r.source, r.source === 'google' ? 'info' : ''),
          h('span.muted', fmtDate(r.reviewed_at)),
          low ? badge('Below 4 ★ · private', 'bad') : null,
          data.featured === r.id ? badge('★ Featured this week', 'ok') : null,
          r.in_lineup ? badge('In featured lineup', 'info') : null),
        r.tagline ? h('div.muted', r.tagline) : null,
        h('p.rv-body', r.body),
        r.email ? h('div.muted.rv-email', 'Email (never shown): ', h('a', { href: 'mailto:' + r.email }, r.email)) : null,
        h('div.rv-actions',
          r.status !== 'shown' && !low ? h('button.btn.btn-sm.btn-primary', { type: 'button', onclick: e => set({ status: 'shown' }, e.currentTarget, 'Shown on the website.') }, icon('check', 14), r.status === 'pending' ? 'Approve & show' : 'Show on website') : null,
          r.status !== 'hidden' ? h('button.btn.btn-sm', { type: 'button', onclick: e => set({ status: 'hidden' }, e.currentTarget, 'Hidden from the website.') }, r.status === 'pending' ? 'Deny (hide)' : 'Hide') : null,
          r.status === 'shown' && !low ? h('button.btn.btn-sm', { type: 'button', onclick: e => set({ lineup: !r.in_lineup }, e.currentTarget, r.in_lineup ? 'Removed from the featured lineup.' : 'Added to the featured lineup.') }, r.in_lineup ? 'Remove from featured' : 'Add to featured') : null,
          h('button.btn.btn-sm', { type: 'button', onclick: () => edit(r) }, 'Edit'))));
  };

  const bulk = async (op, btn) => {
    const ids = [...picked];
    if (op === 'delete' && !(await confirmBox('Delete reviews', `Delete ${ids.length} review${ids.length === 1 ? '' : 's'} for good? This can't be undone. To only take them off the website, use Hide instead.`, 'Delete', true))) return;
    const res = await run(() => api('reviews/bulk', { method: 'POST', body: { op, ids } }), btn);
    if (!res) return;
    toast(`${{ show: 'Shown', hide: 'Hidden', delete: 'Deleted' }[op]}: ${res.done}${res.skipped ? ` · ${res.skipped} below 4 stars stay private` : ''}`, 'ok');
    load();
  };

  const edit = r => modal(r ? 'Edit review' : 'Add a review', close => {
    const google = r && r.source === 'google';
    const lock = google ? { disabled: true } : {};
    const photo = h('input', { type: 'file', name: 'photo', accept: 'image/jpeg,image/png,image/webp', ...lock });
    const form = h('form', {
      onsubmit: async e => {
        e.preventDefault();
        const f = formData(form);
        if (!google && (!f.name.trim() || !f.body.trim())) return toast('Please enter the name and the review text.', 'bad');
        const fd = new FormData();
        ['name', 'tagline', 'rating', 'body', 'status', 'reviewed_at'].forEach(k => f[k] !== undefined && fd.append(k, f[k]));
        if (f.remove_photo) fd.append('remove_photo', '1');
        if (photo.files[0]) {
          if (photo.files[0].size > 3 * 1024 * 1024) return toast('The picture is too large (3 MB at most).', 'bad');
          fd.append('photo', photo.files[0]);
        }
        const res = await run(() => api(r ? 'reviews/' + r.id : 'reviews', { method: 'POST', body: fd }), form.querySelector('[type=submit]'));
        if (!res) return;
        close();
        toast(res.kept_private ? 'Saved. Below 4 stars, so it is kept private (not on the website).' : r ? 'Review saved.' : 'Review added.', 'ok');
        load();
      },
    },
    google ? h('p.notice', 'This review is from Google: it is the reviewer\'s own words, so only the line under the name and whether it is shown can be changed.') : null,
    h('div.grid-2',
      field('Name', h('input', { name: 'name', value: r ? r.name : '', maxlength: 150, required: !google, ...lock })),
      field('Line under the name', h('input', { name: 'tagline', value: r ? r.tagline : '', maxlength: 100, placeholder: 'e.g. Wedding client, Cebu City' }), 'Optional.'),
      field('Stars', select('rating', [5, 4, 3, 2, 1].map(n => ({ value: n, label: '★'.repeat(n) + ' (' + n + ')' })), r ? r.rating : 5, lock), 'Below 4 stars is always kept private.'),
      field('Date of the review', h('input', { type: 'date', name: 'reviewed_at', value: r ? String(r.reviewed_at).slice(0, 10) : today(), max: today(), ...lock })),
      field('Status', select('status', [{ value: 'shown', label: 'Shown on the website' }, { value: 'pending', label: 'Waiting for approval' }, { value: 'hidden', label: 'Hidden' }], r ? r.status : 'shown'))),
    field('Review', h('textarea', { name: 'body', rows: 5, maxlength: 5000, required: !google, ...lock }, r ? r.body : '')),
    google ? null : h('div.grid-2',
      field(r && r.photo ? 'Change the picture' : 'Picture (optional)', photo, 'JPG, PNG or WebP, up to 3 MB. Without one, the initials are shown.'),
      r && r.photo ? h('label.check', h('input', { type: 'checkbox', name: 'remove_photo' }), 'Remove the current picture') : null),
    h('div.actions',
      // Delete is here, away from the everyday buttons, and asks first.
      r ? h('button.btn.btn-danger.rv-del', {
        type: 'button',
        onclick: async e => {
          const btn = e.currentTarget;
          if (!(await confirmBox('Delete review', `Delete the review of ${r.name} for good? This can't be undone. To only take it off the website, use Hide instead.`, 'Delete for good', true))) return;
          if (await run(() => api('reviews/' + r.id, { method: 'DELETE' }), btn)) { close(); toast('Review deleted.', 'ok'); load(); }
        },
      }, 'Delete review') : null,
      h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'), h('button.btn.btn-primary', { type: 'submit' }, r ? 'Save' : 'Add review')));
    return form;
  }, { wide: true });

  let timer;
  body.append(
    controlPanel([['Reviews']], h('button.btn.btn-primary', { type: 'button', onclick: () => edit(null) }, '+ Add a review')),
    h('p.muted', 'Reviews customers send from the website wait here for your approval (unless they are set to appear right away). Only reviews with 4 or 5 stars can be shown; lower ratings stay private as feedback. Google reviews come in by themselves once Google is connected.'),
    tiles,
    h('div.rv-tools', pills,
      h('div.rv-filters',
        h('input', { type: 'search', placeholder: 'Search name, text or email', 'aria-label': 'Search reviews', oninput: e => { clearTimeout(timer); timer = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; load(); }, 300); } }),
        select('source', [{ value: '', label: 'All sources' }, ...Object.entries(RV_SOURCE).map(([value, label]) => ({ value, label }))], '', { 'aria-label': 'Source', onchange: e => { st.source = e.target.value; st.page = 1; load(); } }),
        select('stars', [{ value: '', label: 'All stars' }, ...[5, 4, 3, 2, 1].map(n => ({ value: n, label: n + ' ★' }))], '', { 'aria-label': 'Stars', onchange: e => { st.stars = e.target.value; st.page = 1; load(); } }))),
    bar, listEl, pager);
  load();
}

/* ---------- Staff & permissions ---------- */

async function staff(body, params) {
  const [users, meta] = await Promise.all([run(() => api('admin/users')), run(() => api('admin/roles'))]);
  if (!users || !meta) return;
  if (params[0] && params[0] !== 'deleted') {
    const u = params[0] === 'new' ? null : users.find(x => String(x.id) === params[0]);
    if (params[0] !== 'new' && !u) return body.append(empty('Staff member not found.'));
    return staffForm(body, u, meta, params[1] === 'access' ? 'access' : 'details');
  }

  const roleName = slug => (meta.roles.find(r => r.slug === slug) || {}).name || (slug === 'administrator' ? 'WP Administrator' : slug || '—');
  const roleCaps = slug => (slug === 'administrator' ? meta.capabilities.map(c => c.cap) : (meta.roles.find(r => r.slug === slug) || { caps: [] }).caps);
  const has = (u, cap) => (u.role === 'administrator' ? true : cap in u.overrides ? u.overrides[cap] : roleCaps(u.role).includes(cap));
  let q = '';
  let role = '';
  let show = params[0] === 'deleted' ? 'deleted' : 'staff';
  const listEl = h('div');
  const pills = h('div.rv-pills', { role: 'group', 'aria-label': 'Show' });
  const isWpAdmin = cfg.user.role === 'administrator';
  const reload = async () => { const fresh = await run(() => api('admin/users')); if (fresh) { users.length = 0; users.push(...fresh); draw(); } };
  const draw = () => {
    const deleted = users.filter(u => u.deleted_at);
    clear(pills, [['staff', 'Staff', users.length - deleted.length], ['deleted', 'Deleted', deleted.length]].map(([k, label, n]) =>
      h('button', { type: 'button', class: show === k ? 'active' : '', 'aria-pressed': String(show === k), onclick: () => { show = k; draw(); } }, label, h('span.count', String(n)))));
    if (show === 'deleted') {
      clear(listEl,
        h('p.muted', `Deleted accounts can't sign in. They can be restored for ${30} days, then they are removed for good by themselves. Their past work always keeps their name.`),
        table(deleted, [
          { label: 'Name', render: u => h('div.person', avatar(u, 'sm'), h('div', h('strong', u.name), h('div.muted', u.username))) },
          { label: 'Position', render: u => roleName(u.role) },
          { label: 'Deleted', render: u => fmtDate(u.deleted_at, true) },
          { label: 'Removed for good on', render: u => h('strong', fmtDate(u.purge_at)) },
          {
            label: '', class: 'num', render: u => h('div.row-actions',
              h('button.btn.btn-sm.btn-primary', { type: 'button', onclick: async e => { e.stopPropagation(); if (await run(() => api(`admin/users/${u.id}/restore`, { method: 'POST' }), e.currentTarget)) { toast(u.name + ' was restored', 'ok'); reload(); } } }, 'Restore'),
              isWpAdmin ? h('button.btn.btn-sm.btn-danger-outline', { type: 'button', onclick: e => { e.stopPropagation(); safeDelete(u, { purge: true, onDone: reload }); } }, 'Delete now') : null),
          },
        ], { emptyText: 'No deleted accounts.' }));
      return;
    }
    const rows = users.filter(u => !u.deleted_at && (!role || u.role === role) && (!q || `${u.name} ${u.username} ${u.email || ''}`.toLowerCase().includes(q)));
    clear(listEl, table(rows, [
      { label: 'Name', render: u => h('div.person', avatar(u, 'sm'), h('div', h('strong', u.name), h('div.muted', u.username))) },
      { label: 'Position', render: u => roleName(u.role) },
      { label: 'Home shop', render: u => branchName(u.branch_id) || '—' },
      { label: 'Clock in', render: u => (has(u, 'mq_clock_anywhere') ? 'Anywhere' : 'At the shop') },
      { label: 'Access rights', render: u => (Object.keys(u.overrides).length ? `${Object.keys(u.overrides).length} changed` : h('span.muted', 'Role defaults')) },
      { label: 'Status', render: u => (u.disabled ? h('span.badge', 'Disabled') : h('span.badge.badge-ok', 'Active')) },
      {
        label: '', class: 'num', render: u => (u.is_wp_admin || u.id === cfg.user.id ? '' : h('button.btn.btn-sm.btn-danger-outline', {
          type: 'button', 'aria-label': 'Delete ' + u.name,
          onclick: e => { e.stopPropagation(); safeDelete(u, { onDone: reload }); },
        }, 'Delete')),
      },
    ], { onRow: u => { location.hash = '#/admin/staff/' + u.id; }, emptyText: 'No staff match your search.' }));
  };
  body.append(
    controlPanel([['Staff']],
      h('input.ctrl-search', { type: 'search', placeholder: 'Search staff…', 'aria-label': 'Search staff', oninput: e => { q = e.target.value.trim().toLowerCase(); draw(); } }),
      select('role', [{ value: '', label: 'All positions' }, ...meta.roles.map(r => ({ value: r.slug, label: r.name }))], '', { 'aria-label': 'Filter by position', onchange: e => { role = e.target.value; draw(); } }),
      h('a.btn.btn-primary', { href: '#/admin/staff/new' }, 'New')),
    pills,
    cfg.pendingSignups ? h('p.notice.notice-action', `${cfg.pendingSignups} account request${cfg.pendingSignups === 1 ? ' is' : 's are'} waiting for approval. `,
      h('a', { href: '#/admin/requests' }, 'Review now →')) : '',
    listEl);
  draw();
}

/**
 * Deleting staff, made hard to do by mistake: first what they still have open (with a hand-over),
 * then their username typed and your own password. The account goes to Staff → Deleted for 30 days
 * (restorable); "Delete now" (WordPress administrators, from Deleted) removes it for good.
 */
async function safeDelete(u, { purge = false, onDone } = {}) {
  const im = await run(() => api(`admin/users/${u.id}/impact`));
  if (!im) return;
  if (!purge && !im.can_delete) {
    return modal('Can\'t delete ' + u.name, close => [h('p', im.reason), h('div.actions', h('button.btn.btn-primary', { type: 'button', onclick: () => close() }, 'OK'))]);
  }
  return modal(purge ? 'Delete ' + u.name + ' for good?' : 'Delete ' + u.name + '?', close => {
    const typed = h('input', { type: 'text', autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', 'aria-label': 'Type their username' });
    const pass = h('input', { type: 'password', autocomplete: 'current-password', 'aria-label': 'Your password' });
    const open = im.open_jobs + im.open_docs;
    const hand = !purge && open && im.takers.length ? select('handover', [{ value: 0, label: 'Nobody (leave them as they are)' }, ...im.takers.map(t => ({ value: t.id, label: t.name }))], im.takers[0].id) : null;
    const go = h('button.btn.btn-danger', { type: 'submit', disabled: true }, purge ? 'Delete for good' : 'Delete account');
    const check = () => { go.disabled = typed.value.trim().toLowerCase() !== im.username.toLowerCase() || !pass.value || im.locked; };
    typed.addEventListener('input', check);
    pass.addEventListener('input', check);
    const facts = [
      im.open_jobs ? ['warn', `${im.open_jobs} open job order${im.open_jobs === 1 ? '' : 's'} assigned to them`] : null,
      im.open_docs ? ['warn', `${im.open_docs} open quotation${im.open_docs === 1 ? '' : 's'} / billing statement${im.open_docs === 1 ? '' : 's'}`] : null,
      im.commissions.count ? ['warn', `${money(im.commissions.total)} in unpaid commissions (${im.commissions.count}) — they stay in Admin → Commissions`] : null,
      im.open_shift ? ['info', 'They are clocked in now: the shift ends at the moment of deleting'] : null,
      im.sessions ? ['info', `Signed in on ${im.sessions} device${im.sessions === 1 ? '' : 's'}: signed out everywhere`] : null,
    ].filter(Boolean);
    const form = h('form.safe-delete', {
      onsubmit: async e => {
        e.preventDefault();
        const body = { confirm: typed.value.trim(), password: pass.value, handover: hand ? Number(hand.value) : 0 };
        const res = await run(() => api(`admin/users/${u.id}/${purge ? 'purge' : ''}`.replace(/\/$/, ''), { method: purge ? 'POST' : 'DELETE', body }), go);
        if (!res) { pass.value = ''; check(); return; }
        close(true);
        toast(purge ? u.name + ' was deleted for good' : `${u.name} was deleted. You can restore them until ${fmtDate(res.purge_at)} (Staff → Deleted).`, 'ok');
        if (onDone) onDone();
      },
    },
    h('div.safe-delete-who', avatar(u, 'lg'), h('div', h('strong', u.name), h('div.muted', u.username + (u.email ? ' · ' + u.email : '')))),
    purge
      ? h('p.notice.notice-bad', 'This removes the account for good. It cannot be restored. Their past work keeps showing their name.')
      : h('p.notice', `They are signed out at once and can no longer sign in. The account stays in Staff → Deleted for ${im.keep_days} days, where you can restore it. Their past work (job orders, sales, attendance and the activity log) keeps their name. If they may come back, switch off "Active" instead.`),
    facts.length ? h('ul.safe-delete-facts', facts.map(([tone, t]) => h('li', { class: 'is-' + tone }, icon(tone === 'warn' ? 'alert' : 'check', 15), t))) : (purge ? null : h('p.muted', 'Nothing open: no job orders, quotations or unpaid commissions.')),
    hand ? field('Hand their open work to', hand, 'Their open job orders and quotations move to this sales person.') : (!purge && open ? h('p.muted', 'There is no other active sales person to hand their open work to; it stays as it is.') : null),
    field(h('span', 'Type ', h('code', im.username), ' to confirm'), typed),
    field('Your password', pass, im.locked ? 'Too many wrong passwords. Try again in 15 minutes.' : 'For safety, enter the password you use to sign in.'),
    h('div.actions', h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'), go));
    setTimeout(() => typed.focus(), 50);
    return form;
  });
}

/* Account requests from the login screen. Approving creates the account; denying creates nothing. */
async function requests(body) {
  const meta = await run(() => api('admin/roles'));
  if (!meta) return;
  const roleName = slug => (meta.roles.find(r => r.slug === slug) || {}).name || slug;
  const isWpAdmin = cfg.user.role === 'administrator';
  let status = 'pending';
  const lists = { pending: [], approved: [], denied: [] };
  const pills = h('div.rv-pills', { role: 'group', 'aria-label': 'Show' });
  const listEl = h('div.req-list');
  const ago = d => {
    const m = Math.max(0, Math.round((Date.now() - new Date(String(d).replace(' ', 'T')).getTime()) / 60000));
    return m < 60 ? (m <= 1 ? 'just now' : m + ' minutes ago') : m < 1440 ? Math.round(m / 60) + ' hours ago' : Math.round(m / 1440) + ' days ago';
  };
  const setCount = n => { if (cfg.pendingSignups !== n) { cfg.pendingSignups = n; window.dispatchEvent(new CustomEvent('mqp-signups')); } };

  const load = async () => {
    clear(listEl, h('div.loading', 'Loading…'));
    const got = await run(() => Promise.all(['pending', 'approved', 'denied'].map(st => api('admin/signups', { query: { status: st } }))));
    if (!got) return clear(listEl);
    [lists.pending, lists.approved, lists.denied] = got;
    setCount(lists.pending.length);
    draw();
  };

  const draw = () => {
    clear(pills, [['pending', 'Waiting'], ['approved', 'Approved'], ['denied', 'Denied']].map(([k, label]) =>
      h('button', { type: 'button', class: status === k ? 'active' : '', 'aria-pressed': String(status === k), onclick: () => { status = k; draw(); } }, label, h('span.count', String(lists[k].length)))));
    const rows = lists[status];
    if (!rows.length) {
      return clear(listEl, h('div.req-empty', icon(status === 'pending' ? 'check' : 'users', 28),
        h('strong', status === 'pending' ? 'No requests waiting' : status === 'approved' ? 'No approved requests yet' : 'No denied requests'),
        status === 'pending' ? h('span.muted', 'New staff ask for an account from the portal login screen ("Request an account"). You will see them here.') : null));
    }
    clear(listEl, rows.map(card));
  };

  const card = r => {
    const pending = r.status === 'pending';
    const warns = pending ? [
      r.email_used ? 'This email is already used by another account.' : null,
      r.username_used ? 'This username is already taken, so approving will fail. Deny it and ask them to request again with another username.' : null,
    ].filter(Boolean) : [];
    return h('article.card.req-card', { class: 'is-' + r.status },
      h('div.req-top',
        avatar({ name: r.name }, 'lg'),
        h('div.req-who',
          h('div.req-name', h('strong', r.name), h('span.muted', '@' + r.username)),
          h('div.req-tags',
            h('span.badge.badge-info', roleName(r.role)),
            r.branch_id ? h('span.badge', branchName(r.branch_id)) : null,
            h('span.muted', (pending ? 'Asked ' : '') + ago(r.created_at)))),
        pending ? h('div.req-actions',
          h('button.btn.btn-primary', { type: 'button', onclick: () => review(r) }, icon('check', 15), 'Approve…'),
          h('button.btn.btn-danger-outline', { type: 'button', onclick: e => deny(r, e.currentTarget) }, 'Deny')) : null),
      h('div.req-contact',
        h('a', { href: 'mailto:' + r.email }, icon('mail', 15), r.email),
        r.phone ? h('a', { href: 'tel:' + r.phone }, icon('user', 15), r.phone) : null),
      r.note ? h('blockquote.req-note', r.note) : null,
      warns.length ? h('ul.req-warn', warns.map(w => h('li', icon('alert', 14), w))) : null,
      !pending ? h('div.req-decided',
        h('span', (r.status === 'approved' ? 'Approved by ' : 'Denied by ') + (r.decided_by ? r.decided_by.name : '—') + ' · ' + fmtDate(r.decided_at, true)),
        r.reason ? h('span.muted', 'Reason: ' + r.reason) : null,
        r.status === 'approved' && r.user_id ? h('a', { href: '#/admin/staff/' + r.user_id }, 'Open their staff page →') : null) : null);
  };

  const deny = async (r, btn) => {
    const reason = await promptBox('Deny ' + r.name + '?', 'Reason (optional — included in the email to them)', { okLabel: 'Deny request', required: false, danger: true });
    if (reason === undefined || reason === null) return;
    const res = await run(() => api(`admin/signups/${r.id}/deny`, { method: 'POST', body: { reason } }), btn);
    if (!res) return;
    toast('Request denied — no account was created.', 'ok');
    load();
  };

  const review = r => modal('Approve ' + r.name, close => {
    const positions = meta.roles.filter(x => x.slug !== 'mq_owner' || isWpAdmin);
    const form = h('form', {
      onsubmit: async e => {
        e.preventDefault();
        const f = formData(form);
        const res = await run(() => api(`admin/signups/${r.id}/approve`, { method: 'POST', body: { role: f.role, branch_id: Number(f.branch_id), brand_id: Number(f.brand_id) } }), form.querySelector('[type=submit]'));
        if (!res) return;
        close();
        setCount(Math.max(0, lists.pending.length - 1));
        toast(`Account created for ${r.name}. They were emailed.`, 'ok');
        location.hash = '#/admin/staff/' + res.new_user_id + '/access'; // Next: check their access rights.
      },
    },
    h('div.safe-delete-who', avatar({ name: r.name }, 'lg'), h('div', h('strong', r.name), h('div.muted', '@' + r.username + ' · ' + r.email + (r.phone ? ' · ' + r.phone : '')))),
    r.note ? h('blockquote.req-note', r.note) : null,
    h('p.muted', 'Choose their position and shop. The account is created only when you approve, and they are emailed that they can sign in.'),
    h('div.grid-2',
      field('Position', select('role', positions.map(x => ({ value: x.slug, label: x.name })), r.role), `They asked for: ${roleName(r.role)}`),
      field('Home shop', select('branch_id', [{ value: 0, label: '— None —' }, ...cfg.branches.map(b => ({ value: b.id, label: b.name }))], r.branch_id || 0)),
      field('Brand', select('brand_id', [{ value: 0, label: '— Any —' }, ...cfg.brands.map(b => ({ value: b.id, label: b.name }))], 0), 'For sales staff: the brand on their documents.')),
    h('div.actions', h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'), h('button.btn.btn-primary', { type: 'submit' }, 'Approve & create account')));
    return form;
  }, { wide: true });

  body.append(
    controlPanel([['Account requests']], h('a.btn', { href: '#/admin/settings' }, 'Request settings')),
    h('p.muted', 'New staff ask for an account from the portal login screen. Nothing is created until you approve, and you choose their position and shop. Requests can be turned off in Settings → Staff accounts.'),
    pills, listEl);
  load();
}

// Departments on the staff record: each has access levels (a dropdown) and the rights it covers (checkboxes).
const DEPARTMENTS = [
  {
    name: 'Sales', help: 'Invoice Manager, customer uploads, online orders and job orders.',
    caps: ['mq_sales', 'mq_record_payments', 'mq_deliver', 'mq_view_all_sales', 'mq_assign_sales', 'mq_void_documents'],
    levels: [
      ['No access', []],
      ['Sales staff — own customers', ['mq_sales', 'mq_record_payments', 'mq_deliver']],
      ['Sales manager — all sales', ['mq_sales', 'mq_record_payments', 'mq_deliver', 'mq_view_all_sales', 'mq_assign_sales', 'mq_void_documents']],
    ],
  },
  {
    name: 'Creatives', help: 'Check customer files and attach edited designs.',
    caps: ['mq_creatives'],
    levels: [['No access', []], ['Designer', ['mq_creatives']]],
  },
  {
    name: 'Production & QC', help: 'Printing queue, quality check and the production monitor.',
    caps: ['mq_production', 'mq_qc', 'mq_view_board'],
    levels: [
      ['No access', []],
      ['Monitor screen only', ['mq_view_board']],
      ['Production', ['mq_production', 'mq_view_board']],
      ['QC officer', ['mq_production', 'mq_qc', 'mq_view_board']],
    ],
  },
  {
    name: 'POS', help: 'Walk-in sales at the shop.',
    caps: ['mq_pos', 'mq_pos_discount', 'mq_pos_void'],
    levels: [['No access', []], ['Cashier', ['mq_pos']], ['Supervisor — discounts and voids', ['mq_pos', 'mq_pos_discount', 'mq_pos_void']]],
  },
  {
    name: 'Admin, Finance & Ops', help: 'Reports, commissions, attendance, website reviews, settings and staff.',
    caps: ['mq_view_dashboard', 'mq_finance', 'mq_price_override', 'mq_reviews', 'mq_manage'],
    levels: [
      ['No access', []],
      ['Finance — commissions and attendance', ['mq_finance']],
      ['Owner reports', ['mq_view_dashboard', 'mq_finance']],
      ['Administrator — settings and staff', ['mq_view_dashboard', 'mq_finance', 'mq_price_override', 'mq_reviews', 'mq_manage']],
    ],
  },
  {
    name: 'Time clock', help: 'Where and when they can clock in.',
    caps: ['mq_clock_anywhere', 'mq_bypass_attendance'],
    levels: [
      ['At the shop, during set hours', []],
      ['Anywhere, during set hours', ['mq_clock_anywhere']],
      ['At the shop, any time', ['mq_bypass_attendance']],
      ['Anywhere, any time', ['mq_clock_anywhere', 'mq_bypass_attendance']],
    ],
  },
];

function staffForm(body, u, meta, tab = 'details') {
  const isNew = !u;
  const isWpAdmin = cfg.user.role === 'administrator';
  const locked = !isNew && (u.is_wp_admin || u.id === cfg.user.id); // Access rights can't be edited here.
  const roleCaps = slug => (meta.roles.find(r => r.slug === slug) || { caps: [] }).caps;
  const roleName = slug => (meta.roles.find(r => r.slug === slug) || {}).name || slug;
  const state = { role: u ? u.role : 'mq_sales_staff' };
  const ov = { ...(u ? u.overrides : {}) }; // cap → true (added) | false (removed) on top of the role

  const saveBtn = h('button.btn.btn-primary', { type: 'submit', form: 'staff-form', disabled: !isNew }, 'Save');
  const discardBtn = h('button.btn', { type: 'button', disabled: true, onclick: () => { if (isNew) location.hash = '#/admin/staff'; else { clear(body); staffForm(body, u, meta, tab); } } }, 'Discard');
  const mark = () => { saveBtn.disabled = false; discardBtn.disabled = false; };

  const branchOpts = [{ value: 0, label: '— None —' }, ...cfg.branches.map(b => ({ value: b.id, label: b.name }))];
  const brandOpts = [{ value: 0, label: '— Any —' }, ...cfg.brands.map(b => ({ value: b.id, label: b.name }))];
  const rightsEl = h('div.sheet-panel', { hidden: tab !== 'access', 'data-panel': 'access' });
  // Details (the account) and Access rights (what they may do) are separate tabs; one Save covers both.
  const showTab = t => {
    tab = t;
    tabBar.querySelectorAll('button').forEach(b => { const on = b.dataset.tab === t; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); });
    form.querySelectorAll('[data-panel]').forEach(p => { p.hidden = p.dataset.panel !== t; });
    if (!isNew) history.replaceState(null, '', '#/admin/staff/' + u.id + (t === 'access' ? '/access' : ''));
  };
  const tabBar = h('div.sheet-tabs', { role: 'tablist' },
    [['details', 'Details'], ['access', 'Access rights']].map(([k, label]) => h('button', {
      type: 'button', role: 'tab', class: k === tab ? 'active' : '', 'aria-selected': String(k === tab), dataset: { tab: k }, onclick: () => showTab(k),
    }, label)));

  // Access rights: per department, a level dropdown plus checkboxes for each right it covers.
  // The position (role) gives the defaults; anything different is saved for this person only.
  const capLabel = cap => (meta.capabilities.find(c => c.cap === cap) || {}).label || cap;
  const has = cap => (cap in ov ? ov[cap] : roleCaps(state.role).includes(cap));
  const setCap = (cap, on) => {
    if (cap === 'mq_manage' && !isWpAdmin) return; // Only WP admins can hand out settings access.
    if (on === roleCaps(state.role).includes(cap)) delete ov[cap]; else ov[cap] = on;
  };

  const drawRights = () => {
    if (locked) {
      clear(rightsEl, h('p.muted', u.is_wp_admin ? 'WordPress administrators have every permission.' : 'You cannot change your own access rights.'));
      return;
    }
    const changed = Object.keys(ov).length > 0;
    clear(rightsEl,
      h('div.rights-head',
        h('p.muted', changed
          ? `Some rights are different from the ${roleName(state.role)} position's defaults.`
          : `Using the defaults of the ${roleName(state.role)} position. Change a level or tick a box to adjust it for this person.`),
        changed ? h('button.btn.btn-sm', { type: 'button', onclick: () => { Object.keys(ov).forEach(k => delete ov[k]); mark(); drawRights(); } }, 'Reset to position defaults') : null),
      h('div.access-list', DEPARTMENTS.map(d => {
        const caps = d.caps.filter(cap => meta.capabilities.some(c => c.cap === cap));
        const on = caps.filter(has);
        const level = d.levels.findIndex(([, lc]) => lc.length === on.length && lc.every(cap => on.includes(cap)));
        const levelSel = h('select', {
          'aria-label': d.name + ' access level',
          onchange: e => {
            if (e.target.value === 'custom') return;
            const lc = d.levels[Number(e.target.value)][1];
            caps.forEach(cap => setCap(cap, lc.includes(cap)));
            mark();
            drawRights();
          },
        },
        d.levels.map(([label], i) => h('option', { value: i, selected: i === level }, label)),
        level === -1 ? h('option', { value: 'custom', selected: true }, 'Custom') : null);
        return h('section.dept-access',
          h('div.dept-access-head',
            h('div', h('div.set-label', d.name), h('div.set-help', d.help)),
            levelSel),
          h('div.dept-checks', caps.map(cap => {
            const locked1 = cap === 'mq_manage' && !isWpAdmin;
            return h('label.check', { class: locked1 ? 'is-disabled' : '', title: locked1 ? 'Only a WordPress administrator can change this.' : null },
              h('input', { type: 'checkbox', checked: has(cap), disabled: locked1, onchange: e => { setCap(cap, e.target.checked); mark(); drawRights(); } }),
              ' ', capLabel(cap),
              cap in ov ? h('small.right-custom', ov[cap] ? ' added' : ' removed') : null);
          })));
      })));
  };

  const form = h('form.sheet#staff-form', {
    oninput: mark,
    onchange: mark,
    onsubmit: async e => {
      e.preventDefault();
      const f = formData(form);
      const payload = { ...f, id: u ? u.id : 0, branch_id: Number(f.branch_id), brand_id: Number(f.brand_id), disabled: 'active' in f ? !f.active : false };
      if (u && u.is_wp_admin) payload.role = 'mq_owner';
      const res = await run(async () => {
        const saved = await api('admin/users', { method: 'POST', body: payload });
        if (locked) return saved;
        const caps = {};
        meta.capabilities.forEach(c => { caps[c.cap] = c.cap in ov ? (ov[c.cap] ? 'grant' : 'deny') : 'default'; });
        return api(`admin/users/${saved.id}/caps`, { method: 'POST', body: caps });
      }, saveBtn);
      if (!res) return;
      toast(isNew ? 'Staff member added' : 'Saved', 'ok');
      if (isNew) location.hash = '#/admin/staff/' + res.id + '/access';
      else { clear(body); staff(body, [String(res.id), tab]); }
    },
  },
  h('div.sheet-head',
    isNew ? h('span.avatar.avatar-xl', '?')
      : photoPicker(u, {
        endpoint: `admin/users/${u.id}/avatar`,
        onChange: v => { if (u.id === cfg.user.id) { cfg.user.avatar = v; window.dispatchEvent(new CustomEvent('mqp-avatar')); } },
      }),
    h('div.sheet-title',
      h('input.title-input', { name: 'name', required: true, placeholder: 'Full name', 'aria-label': 'Full name', value: u ? u.name : '' }),
      isNew ? h('input', { name: 'username', required: true, autocomplete: 'off', placeholder: 'Username (used to log in)', 'aria-label': 'Username' })
        : h('div.muted', 'Username: ' + u.username))),
  tabBar,
  h('div.sheet-fields', { hidden: tab !== 'details', 'data-panel': 'details' },
    field('Email', h('input', { name: 'email', type: 'email', value: u ? u.email : '' })),
    field(isNew ? 'Password' : 'New password', h('input', { name: 'password', type: 'password', autocomplete: 'new-password', minlength: 8, required: isNew }), isNew ? 'At least 8 characters.' : 'Leave blank to keep the current password.'),
    u && u.is_wp_admin ? field('Position', h('input', { value: 'WP Administrator', disabled: true }))
      : field('Position', select('role', meta.roles.map(r => ({ value: r.slug, label: r.name })), state.role, {
        onchange: e => {
          state.role = e.target.value;
          // A new position starts from its own defaults.
          Object.keys(ov).forEach(k => delete ov[k]);
          drawRights();
        },
      }), 'Sets their default access rights (see the Access rights tab).'),
    field('Home shop', select('branch_id', branchOpts, u ? u.branch_id : 0), 'Their POS branch.'),
    field('Brand', select('brand_id', brandOpts, u ? u.brand_id : 0), 'For sales staff: the brand on their documents.'),
    u && u.id !== cfg.user.id ? h('div.field', h('span.field-label', 'Status'),
      h('label.status-switch', switchInput({ name: 'active', checked: !u.disabled }), h('span', 'Active — can log in'))) : null),
  rightsEl);

  // Remove account: at the bottom of Details, with the reason when it isn't allowed.
  const deleteWhy = isNew ? null : u.is_wp_admin ? 'WordPress administrator accounts can only be deleted in wp-admin → Users.'
    : u.id === cfg.user.id ? 'You cannot delete your own account.'
      : u.role === 'mq_owner' && !isWpAdmin ? 'Only a WordPress administrator can delete an Owner account.' : '';
  const dangerZone = isNew || u.deleted_at ? null : h('section.danger-zone', { 'data-panel': 'details', hidden: tab !== 'details' },
    h('div', h('h3', 'Remove account'),
      h('p.muted', deleteWhy || 'Deleting signs them out and blocks sign-in at once. You can restore the account for 30 days. If they may come back soon, switch off "Active" above instead.')),
    deleteWhy ? null : h('button.btn.btn-danger-outline', { type: 'button', onclick: () => safeDelete(u, { onDone: () => { location.hash = '#/admin/staff'; } }) }, 'Delete account…'));
  const deleteBtn = null;
  if (u && u.deleted_at) {
    form.prepend(h('div.notice.notice-bad.deleted-banner',
      h('div', h('strong', 'This account is deleted.'), ` It can't sign in. It will be removed for good on ${fmtDate(u.purge_at)}.`),
      h('button.btn.btn-sm.btn-primary', { type: 'button', onclick: async e => { if (await run(() => api(`admin/users/${u.id}/restore`, { method: 'POST' }), e.currentTarget)) { toast(u.name + ' was restored', 'ok'); location.reload(); } } }, 'Restore')));
    form.querySelectorAll('input, select, textarea, button[type=submit]').forEach(el => { el.disabled = true; });
  }

  // Sign out: ends their portal sessions on every device (e.g. a lost phone).
  const signoutBtn = !isNew && u.id !== cfg.user.id && u.sessions > 0 && (!u.is_wp_admin || isWpAdmin)
    ? h('button.btn', {
      type: 'button',
      title: `Signed in on ${u.sessions} device${u.sessions === 1 ? '' : 's'}`,
      onclick: async () => {
        const ok = await confirmBox('Sign out ' + u.name + '?',
          `They are signed in to the portal on ${u.sessions} device${u.sessions === 1 ? '' : 's'}. They will be signed out everywhere and need to sign in again. An open shift stays open.`, 'Sign out');
        if (!ok) return;
        const res = await run(() => api('admin/users/' + u.id + '/signout', { method: 'POST' }), signoutBtn);
        if (!res) return;
        toast(u.name + ' was signed out', 'ok');
        signoutBtn.remove();
      },
    }, 'Sign out')
    : null;

  drawRights();
  body.append(
    controlPanel([['Staff', '#/admin/staff'], [isNew ? 'New' : u.name]], signoutBtn, deleteBtn, discardBtn, u && u.deleted_at ? null : saveBtn),
    form);
  if (dangerZone) form.append(dangerZone);
}

/* ---------- Settings ---------- */

const SECTIONS = [
  ['company', 'Company & documents', 'file'],
  ['clock', 'Time clock', 'clock'],
  ['accounts', 'Staff accounts', 'users'],
  ['shops', 'Shops & branches', 'store'],
  ['brands', 'Brands', 'tag'],
  ['pos', 'POS', 'pos'],
  ['orders', 'Online orders', 'globe'],
  ['pricing', 'Order page', 'wallet'],
  ['uploads', 'Customer uploads', 'upload'],
];

async function settings(body) {
  const [s, oc] = await Promise.all([run(() => api('admin/settings')), run(() => api('admin/order-config'))]);
  if (!s || !oc) return;

  const saveBtn = h('button.btn.btn-primary', { type: 'submit', form: 'set-form', disabled: true }, 'Save');
  const discardBtn = h('button.btn', { type: 'button', disabled: true, onclick: () => reload() }, 'Discard');
  const mark = () => { saveBtn.disabled = false; discardBtn.disabled = false; };
  const reload = async () => { const y = window.scrollY; clear(body); await settings(body); window.scrollTo(0, y); };

  const chk = name => h('input', { type: 'checkbox', name, id: 'opt-' + name, checked: !!s[name] });
  const inp = (name, attrs = {}) => h('input', { name, value: s[name] ?? '', ...attrs });
  const txt = (name, rows = 3) => h('textarea', { name, rows, value: s[name] ?? '' });

  const branchesEl = h('div.set-block');
  const brandsEl = h('div.set-block');
  const productsEl = h('div.set-block');

  const sections = {
    company: [
      setting({ wide: true, title: 'Company details', help: 'Shown on quotations, billing statements, invoices and receipts.',
        body: h('div.grid-2', field('Company name', inp('company_name')), field('Contact', inp('company_contact')), field('Address', txt('company_address', 2)), field('TIN (optional)', inp('company_tin'))) }),
      setting({ title: 'Quotation terms', body: txt('quotation_terms') }),
      setting({ title: 'Billing statement terms', body: txt('billing_terms') }),
      setting({ title: 'Invoice terms', body: txt('invoice_terms') }),
      setting({ title: 'Downpayment', help: 'Asked on billing statements.', body: inputSuffix(inp('downpayment_percent', { type: 'number', min: 0, max: 100 }), '%') }),
      setting({ title: 'Payment before Creatives', help: 'What must be paid before Sales can send a job order to Creatives.',
        body: select('gate_payment', [
          { value: 'any', label: 'Any payment recorded' },
          { value: 'downpayment', label: 'The downpayment (' + (s.downpayment_percent || 0) + '%)' },
          { value: 'full', label: 'Fully paid' },
          { value: 'none', label: 'No payment needed' },
        ], s.gate_payment) }),
      setting({ check: chk('quote_main_only'), title: 'Get Quotation: MQ Printing Services only', help: 'The website\'s Get Quotation form offers only MQ Printing Services. Untick when the other businesses (Brands) are ready to take quotation requests; MQ Printing Services stays the first choice.' }),
      setting({ title: 'Quotation links expire', help: 'Customers\' quotation links stop working this many days after the order is completed or cancelled, or the quotation is no longer valid. 0 = never.', body: inputSuffix(inp('quote_link_days', { type: 'number', min: 0, max: 3650 }), 'days') }),
      setting({ title: 'Default commission', help: 'Of the invoice total. Each brand can have its own under Brands.', body: inputSuffix(inp('commission_rate', { type: 'number', min: 0, max: 100, step: 1 }), '%') }),
    ],
    clock: [
      setting({
        check: chk('att_require_location'),
        title: 'Clock in at the shop only',
        help: 'Staff must be on a shop\'s Wi-Fi or inside its GPS area to clock in. Set this up for each shop under Shops & branches. People with "Can clock in outside the shop" in their access rights are exempt.',
        body: s.att_require_location && !s.shops_set_up ? h('p.set-warn', icon('alert', 16), 'No shop has Wi-Fi or GPS set up yet, so staff can still clock in anywhere.') : null,
      }),
      setting({
        check: chk('att_window_enabled'),
        title: 'Clock-in hours',
        help: 'Only allow clocking in between these times. People with "Can clock in outside the set hours" are exempt.',
        body: h('div.inline-fields', inp('att_window_start', { type: 'time', 'aria-label': 'From' }), h('span.muted', 'to'), inp('att_window_end', { type: 'time', 'aria-label': 'Until' })),
      }),
      setting({ title: 'Forgotten clock-outs', help: 'Close a shift automatically after this many hours.', body: inputSuffix(inp('att_auto_close_hours', { type: 'number', min: 1 }), 'hours') }),
    ],
    accounts: [
      setting({
        check: chk('signup_enabled'),
        title: 'Let new staff request an account',
        help: ['Shows "Request an account" on the portal login screen. You approve or deny each request under ', h('a', { href: '#/admin/requests' }, 'Admin → Account requests'), '; no account exists until you approve. When this is off, only admins can create accounts.'],
      }),
    ],
    shops: [branchesEl],
    brands: [brandsEl],
    pos: [
      setting({ title: 'Receipt footer', help: 'Printed at the bottom of every POS receipt.', body: txt('receipt_footer') }),
      productsEl,
    ],
    orders: [
      setting({ check: chk('order_enabled'), title: 'Accept online orders', help: ['Customers order from the website button or ', h('a', { href: cfg.orderUrl, target: '_blank', rel: 'noopener' }, cfg.orderUrl), '.'] }),
      setting({ check: chk('daily_report'), title: 'Daily check email', help: ['Every morning the admin emails get the day before\'s daily check: voids, deleted orders, orders still without a price, job orders released unpaid and printer counter gaps. Also in ', h('a', { href: '#/admin/daily' }, 'Admin → Daily check'), '.'] }),
      setting({ wide: true, title: 'Features on the order page', help: '"Coming soon" shows the feature with a Coming soon label so customers know it is on the way, but it cannot be chosen. "Off" hides it.',
        body: h('div.grid-2', [['feature_campus', 'Campus delivery'], ['feature_courier', 'Maxim / Lalamove / Grab Express'], ['feature_supplies', 'Office supplies']].map(([name, label]) =>
          field(label, select(name, [{ value: 'on', label: 'On' }, { value: 'soon', label: 'Coming soon (maintenance)' }, { value: 'off', label: 'Off (hidden)' }], s[name])))) }),
      setting({ title: 'New order emails', help: 'Staff address told about every new order. Blank = the site admin email.', body: inp('order_notify_email', { type: 'email', placeholder: 'orders@mqprintsph.com' }) }),
      setting({ title: 'Assign online orders to', help: 'Gets the commission and handles the order. With nobody set, any sales staff can pick up unassigned orders.',
        body: select('online_sales_person', [{ value: 0, label: '— Assign manually —' }, ...cfg.salesStaff.map(x => ({ value: x.id, label: x.name }))], s.online_sales_person) }),
      setting({ title: 'File size limit', help: `Per file. Your server allows up to ${s.server_max_upload_mb} MB (hPanel → PHP Configuration).`, body: inputSuffix(inp('order_upload_max_mb', { type: 'number', min: 1 }), 'MB') }),
      setting({ title: 'Spam protection', help: 'Cloudflare Turnstile (free) on the order page and the upload form. Leave blank to turn it off.',
        body: h('div.stack', inp('turnstile_site_key', { placeholder: 'Site key', 'aria-label': 'Turnstile site key' }), h('input', { name: 'turnstile_secret', type: 'password', value: s.turnstile_secret, placeholder: 'Secret key', autocomplete: 'off', 'aria-label': 'Turnstile secret key' })) }),
    ],
    pricing: [orderPricing(oc, mark)],
    uploads: [
      setting({ title: 'File size limit', help: `Your server allows up to ${s.server_max_upload_mb} MB.`, body: inputSuffix(inp('upload_max_mb', { type: 'number', min: 1 }), 'MB') }),
      setting({ title: 'Allowed file types', help: 'Comma-separated, e.g. pdf,png,jpg,jpeg', body: inp('upload_types') }),
      setting({ title: 'Uploads per hour', help: 'Per visitor, to stop spam.', body: inp('upload_rate_per_hour', { type: 'number', min: 1 }) }),
      setting({ title: 'Message after upload', body: txt('upload_success_text', 2) }),
    ],
  };

  const content = SECTIONS.map(([k, label]) => h('section.set-section', { id: 'set-' + k, dataset: { sec: k } },
    h('h3.set-title', label), h('div.set-grid', sections[k])));
  const form = h('form#set-form', {
    oninput: mark,
    onchange: mark,
    onsubmit: async e => {
      e.preventDefault();
      const res = await run(async () => {
        const saved = await api('admin/settings', { method: 'POST', body: formData(form) });
        await api('admin/order-config', { method: 'POST', body: oc });
        return saved;
      }, saveBtn);
      if (res) { Object.assign(cfg.settings, res); toast('Settings saved', 'ok'); reload(); }
    },
  }, content);

  const navBtns = SECTIONS.map(([k, label, ic]) => h('button', {
    type: 'button',
    dataset: { sec: k },
    onclick: e => {
      navBtns.forEach(b => b.classList.toggle('active', b === e.currentTarget));
      document.getElementById('set-' + k).scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
  }, icon(ic, 16), label));

  // Search hides settings that don't match (by title and help text).
  const filter = q => {
    q = q.trim().toLowerCase();
    content.forEach(sec => {
      const titleHit = !q || sec.querySelector('.set-title').textContent.toLowerCase().includes(q);
      let any = titleHit;
      sec.querySelectorAll('.set-box, .set-block').forEach(b => {
        const hit = titleHit || b.textContent.toLowerCase().includes(q);
        b.hidden = !hit;
        any = any || hit;
      });
      sec.hidden = !any;
    });
  };

  body.append(
    controlPanel([['Settings']],
      h('input.ctrl-search', { type: 'search', placeholder: 'Search settings…', 'aria-label': 'Search settings', oninput: e => filter(e.target.value) }),
      discardBtn, saveBtn),
    h('div.set-layout', h('nav.set-nav', { 'aria-label': 'Settings sections' }, navBtns), form));

  // Highlight the section in view: the last one whose top has passed the control panel.
  const spy = () => {
    const visible = content.filter(sec => !sec.hidden);
    let cur = visible[0];
    visible.forEach(sec => { if (sec.getBoundingClientRect().top <= 170) cur = sec; });
    // At the bottom of the page, the last section counts as current even if it is short.
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) cur = visible[visible.length - 1];
    navBtns.forEach(b => b.classList.toggle('active', !!cur && b.dataset.sec === cur.dataset.sec));
  };
  window.addEventListener('scroll', spy, { passive: true });
  spy();

  renderBranches(branchesEl, s.your_ip);
  renderBrands(brandsEl);
  renderProducts(productsEl);
  return () => window.removeEventListener('scroll', spy);
}

/* Online order page settings (pick-up place, courier pins, supplies, QR codes), saved with the rest of Settings. */
function orderPricing(c, mark) {
  const bind = (obj, key, attrs = {}) => h('input', { value: obj[key] ?? '', oninput: e => { obj[key] = e.target.value; }, ...attrs });
  const num = (obj, key, attrs = {}) => bind(obj, key, { type: 'number', min: 0, step: 'any', class: 'input-num', ...attrs });

  // Editable rows with add/remove. cols: [[label, row => control]].
  const rows = (arr, cols, blank, addLabel) => {
    const wrap = h('div.edit-list');
    const draw = () => clear(wrap,
      h('div.table-wrap', h('table.table.edit-table',
        h('thead', h('tr', cols.map(([label]) => h('th', label)), h('th'))),
        h('tbody', arr.map((row, i) => h('tr', cols.map(([label, ctl]) => h('td', { 'data-label': label }, ctl(row))),
          h('td', h('button.btn.btn-sm', { type: 'button', title: 'Remove', 'aria-label': 'Remove', onclick: () => { arr.splice(i, 1); mark(); draw(); } }, '✕'))))))),
      h('button.btn.btn-sm', { type: 'button', onclick: () => { arr.push(blank()); mark(); draw(); } }, '+ ' + addLabel));
    draw();
    return wrap;
  };

  c.pickup = c.pickup || { name: '', address: '', map: '' };
  return h('div.set-block.pricing',
    setting({ title: 'Pick-up place', wide: true, help: 'Shown with a Google map when the customer picks "Pick up". Map: the words to search in Google Maps (e.g. MQ Printing Services), or paste a Google Maps link.',
      body: h('div.grid-2',
        field('Name', bind(c.pickup, 'name', { placeholder: 'MQ Printing Services Main Office' })),
        field('Address (optional)', bind(c.pickup, 'address')),
        field('Google Maps', bind(c.pickup, 'map', { placeholder: 'MQ Printing Services' }))) }),
    setting({ title: 'Courier pin addresses', wide: true, help: 'Customers book their own rider when the order is ready, and use this pin as the pick-up point.',
      body: rows(c.couriers, [
        ['Courier', x => bind(x, 'name', { placeholder: 'Maxim' })],
        ['Pin address', x => bind(x, 'pin', { placeholder: 'MQ Printing Services' })],
      ], () => ({ id: '', name: '', pin: '' }), 'Courier') }),
    setting({ title: 'Campus delivery', help: 'The campuses customers can choose (one per line). Switch campus delivery on under Online orders → Features on the order page.',
      body: h('textarea', { rows: 6, value: (c.campuses || []).join('\n'), 'aria-label': 'Campuses', oninput: e => { c.campuses = e.target.value; } }) }),
    setting({ title: 'Courier note', help: 'Shown under the pins.',
      body: h('textarea', { rows: 3, value: c.courier_note, 'aria-label': 'Courier note', oninput: e => { c.courier_note = e.target.value; } }) }),
    setting({ title: 'Guide for file instructions', help: 'Shown in each file\'s instructions box.',
      body: h('textarea', { rows: 2, value: c.note_guide, 'aria-label': 'Guide for file instructions', oninput: e => { c.note_guide = e.target.value; } }) }),
    setting({ title: 'Facebook page chat link', help: 'Customers follow up on their order here with the order number, e.g. https://m.me/yourpage. Blank = no button.',
      body: bind(c, 'messenger_url', { type: 'url', placeholder: 'https://m.me/…' }) }),
    setting({ title: 'Office supplies (add-ons)', wide: true, help: 'Customers pick these on the order page (no price is shown there; the price here is for your reference).',
      body: rows(c.supplies, [
        ['Item', x => bind(x, 'name')],
        ['Unit', x => bind(x, 'unit', { placeholder: '1 pc' })],
        ['Price', x => num(x, 'price')],
      ], () => ({ id: '', name: '', unit: '1 pc', price: 0 }), 'Item') }),
    setting({ title: 'Payment QR codes', wide: true, help: 'Shown large with a Save button after the order is placed, on the tracking page and in the confirmation email. For a QR code, upload the image in WordPress → Media and paste its link.',
      body: rows(c.payment, [
        ['Method', p => bind(p, 'name', { placeholder: 'GCash' })],
        ['Account name', p => bind(p, 'account_name')],
        ['Account no.', p => bind(p, 'account_number')],
        ['QR image link', p => bind(p, 'qr', { type: 'url', placeholder: 'https://…' })],
      ], () => ({ id: '', name: '', account_name: '', account_number: '', qr: '' }), 'Payment method') }),
    setting({ title: 'Notice on the order page', wide: true,
      body: h('textarea', { rows: 2, value: c.notice, 'aria-label': 'Order page notice', oninput: e => { c.notice = e.target.value; } }) }));
}

function crudModal(title, fields, save) {
  modal(title, close => {
    const form = h('form', {
      onsubmit: async e => {
        e.preventDefault();
        if (await save(formData(form), form.querySelector('[type=submit]'))) close();
      },
    }, h('div.grid-2', fields), h('div.actions', h('button.btn', { type: 'button', onclick: () => close() }, 'Cancel'), h('button.btn.btn-primary', { type: 'submit' }, 'Save')));
    return form;
  }, { wide: true });
}

// List header inside a settings section: help on the left, "add" button on the right.
const listHead = (help, addLabel, onAdd) => h('div.set-list-head',
  h('div.set-help', help),
  h('button.btn.btn-sm', { type: 'button', onclick: onAdd }, addLabel));

async function renderBrands(el) {
  const rows = await run(() => api('admin/brands'));
  if (!rows) return;
  const edit = b => crudModal(b ? 'Edit brand' : 'Add brand', [
    field('Name', h('input', { name: 'name', required: true, value: b ? b.name : '' })),
    field('Code (used in document numbers)', h('input', { name: 'code', required: true, maxlength: 10, value: b ? b.code : '' })),
    field('Commission % for this brand', h('input', { name: 'commission_rate', type: 'number', min: 0, max: 100, step: 1, value: b && b.commission_rate !== null ? b.commission_rate : '' }), 'Leave blank to use the default.'),
    field('Sort order', h('input', { name: 'sort', type: 'number', value: b ? b.sort : 0 })),
    h('label.check', h('input', { type: 'checkbox', name: 'has_branches', checked: b ? !!b.has_branches : false }), ' Has physical shops (uses POS)'),
    h('label.check', h('input', { type: 'checkbox', name: 'active', checked: b ? !!b.active : true }), ' Active'),
  ], async (f, btn) => {
    const res = await run(() => api('admin/brands', { method: 'POST', body: { ...f, id: b ? b.id : 0 } }), btn);
    if (res) { cfg.brands = res; renderBrands(el); }
    return !!res;
  });
  clear(el, listHead('The MQ brands. Click a brand to edit it.', '+ Add brand', () => edit(null)),
    table(rows, [
      { label: 'Brand', render: b => h('strong', b.name) },
      { label: 'Code', render: b => b.code },
      { label: 'Shops / POS', render: b => (b.has_branches ? 'Yes' : 'No') },
      { label: 'Commission', render: b => (b.commission_rate !== null ? b.commission_rate + '%' : 'Default') },
      { label: 'Status', render: b => (b.active ? 'Active' : 'Inactive') },
    ], { onRow: edit }));
}

async function renderBranches(el, yourIp) {
  const rows = await run(() => api('admin/branches'));
  if (!rows) return;
  const edit = b => crudModal(b ? 'Edit shop' : 'Add shop', [
    field('Brand', select('brand_id', cfg.brands.filter(x => x.has_branches).map(x => ({ value: x.id, label: x.name })), b ? b.brand_id : '')),
    field('Shop name', h('input', { name: 'name', required: true, value: b ? b.name : '' })),
    field('Code (receipts, upload links)', h('input', { name: 'code', required: true, maxlength: 10, value: b ? b.code : '' })),
    field('Address', h('input', { name: 'address', value: b ? b.address : '' })),
    h('h4.span-2', 'Clock-in location'),
    h('p.muted.span-2', 'Staff clock in when they are on this shop\'s Wi-Fi or within the distance below. Set up at least one.'),
    field('Shop Wi-Fi internet IPs (one per line)', h('textarea', { name: 'allowed_ips', rows: 3, value: b ? b.allowed_ips || '' : '' }), `Easiest: save, then click "Register this network" while on the shop Wi-Fi. Your current IP: ${yourIp}`),
    field('Allowed distance (metres)', h('input', { name: 'radius_m', type: 'number', min: 10, value: b ? b.radius_m : 100 }), 'For GPS. 100 m works for most shops.'),
    field('Latitude', h('input', { name: 'lat', type: 'number', step: 'any', value: b && b.lat !== null ? b.lat : '' }), 'In Google Maps, right-click the shop to copy its coordinates.'),
    field('Longitude', h('input', { name: 'lng', type: 'number', step: 'any', value: b && b.lng !== null ? b.lng : '' })),
    h('label.check', h('input', { type: 'checkbox', name: 'active', checked: b ? !!b.active : true }), ' Active'),
  ], async (f, btn) => {
    const res = await run(() => api('admin/branches', { method: 'POST', body: { ...f, id: b ? b.id : 0 } }), btn);
    if (res) { renderBranches(el, yourIp); toast('Saved — reload the portal to see shop changes everywhere.', 'ok'); }
    return !!res;
  });
  const check = b => {
    const parts = [];
    if (b.allowed_ips) parts.push(`Wi-Fi (${b.allowed_ips.split('\n').filter(Boolean).length})`);
    if (b.lat !== null) parts.push(`GPS ${b.radius_m} m`);
    return parts.length ? parts.join(' · ') : h('span.badge.badge-warn', 'Not set up');
  };
  clear(el, listHead('Each shop\'s Wi-Fi and GPS decide where staff can clock in. Click a shop to edit it.', '+ Add shop', () => edit(null)),
    table(rows, [
      { label: 'Shop', render: b => h('div', h('strong', b.name), h('div.muted', brandName(b.brand_id) + ' · ' + b.code)) },
      { label: 'Clock-in location', render: check },
      { label: 'Status', render: b => (b.active ? 'Active' : 'Inactive') },
      { label: '', render: b => h('button.btn.btn-sm', {
        type: 'button',
        onclick: async e => {
          e.stopPropagation();
          if (!(await confirmBox('Register this network', `Add your current internet IP (${yourIp}) to ${b.name}? Do this while connected to the shop's Wi-Fi.`, 'Add IP'))) return;
          if (await run(() => api(`admin/branches/${b.id}/register-ip`, { method: 'POST' }), e.currentTarget)) { toast('Shop Wi-Fi added', 'ok'); renderBranches(el, yourIp); }
        },
      }, icon('wifi', 14), 'Register this network') },
    ], { onRow: edit }));
}

async function renderProducts(el) {
  const rows = await run(() => api('admin/products'));
  if (!rows) return;
  const edit = p => crudModal(p ? 'Edit product' : 'Add POS product', [
    field('Name', h('input', { name: 'name', required: true, value: p ? p.name : '' })),
    field('Category', h('input', { name: 'category', value: p ? p.category : '', list: 'mqp-cats' })),
    h('datalist#mqp-cats', [...new Set(rows.map(r => r.category))].map(c => h('option', { value: c }))),
    field('Price (₱)', h('input', { name: 'price', type: 'number', step: '0.01', min: 0, required: true, value: p ? p.price : '' })),
    field('Unit', h('input', { name: 'unit', value: p ? p.unit : 'pc' })),
    field('Sort order', h('input', { name: 'sort', type: 'number', value: p ? p.sort : 0 })),
    field('Brand', select('brand_id', cfg.brands.map(b => ({ value: b.id, label: b.name })), p ? p.brand_id : (cfg.brands[0] || {}).id), 'Website quotations from this shop list the product.'),
    h('label.check', h('input', { type: 'checkbox', name: 'active', checked: p ? !!p.active : true }), ' Show in POS'),
    h('label.check', h('input', { type: 'checkbox', name: 'web', checked: p ? !!p.web : true }), ' Show on the website (price list and instant price in "Get Quotation")'),
  ], async (f, btn) => {
    const res = await run(() => api('admin/products', { method: 'POST', body: { ...f, id: p ? p.id : 0 } }), btn);
    if (res) renderProducts(el);
    return !!res;
  });
  clear(el, listHead('Products and prices the POS sells. Products shown on the "Website" appear in the website price list and get an instant price in "Get Quotation". Click a product to edit it.', '+ Add product', () => edit(null)),
    table(rows, [
      { label: 'Product', render: p => h('strong', p.name) },
      { label: 'Category', render: p => p.category },
      { label: 'Price', render: p => money(p.price) + ' / ' + p.unit, class: 'num' },
      { label: 'Status', render: p => (p.active ? 'Shown' : 'Hidden') },
      { label: 'Website', render: p => (p.web && p.active ? 'Shown' : '—') },
    ], { onRow: edit }));
}
