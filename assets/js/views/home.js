// Home: a calm personal dashboard. A short header, a few quick actions and key numbers, then the main
// work on the left and a small side column (to do, this week, recent activity) on the right.
// The owner/finance view shows the company picture only, not every department stacked up.

import { cfg, can, h, api, money, fmtDate, duration, stageBadge, badge, empty, STAGES } from '../core.js';
import { icon } from '../icons.js';
import { openJob } from '../jobs.js';

const ACTIONS_LABEL = {
  created: 'created', to_creatives: 'sent to Creatives', approve_files: 'approved for printing', approve_print: 'approved for printing (no Creatives)', needs_editing: 'needs editing',
  back_to_sales: 'returned to Sales', printed: 'printed', qc_approve: 'passed QC', qc_reject: 'failed QC', notify: 'customer notified',
  delivered: 'delivered', payment: 'payment received', assign: 'sales person assigned', save_specs: 'specs updated', note: 'note added',
  priority: 'priority changed', cancelled: 'cancelled', file_design: 'design attached', file_proof: 'proof attached', file_reference: 'file attached',
};

function greeting() {
  const hr = new Date().getHours();
  return hr < 12 ? 'Good morning' : hr < 18 ? 'Good afternoon' : 'Good evening';
}

// A key number. tone: '' | 'warn' | 'bad'.
const stat = (label, value, href, tone = '', sub = '') => h(href ? 'a.home-stat' : 'div.home-stat', { href, class: tone ? 'tone-' + tone : '' },
  h('span.home-stat-label', label), h('strong', value), sub ? h('small', sub) : null);

function jobMini(jobs, emptyText, limit = 6) {
  if (!jobs || !jobs.length) return empty(emptyText);
  const today = new Date().toISOString().slice(0, 10);
  return h('ul.job-mini', jobs.slice(0, limit).map(j => h('li', {
    tabindex: 0, role: 'button',
    onclick: () => openJob(j.id),
    onkeydown: e => { if (e.key === 'Enter') openJob(j.id); },
  },
  h('div', h('strong', j.number), ' ', j.priority ? badge('RUSH', 'bad') : null, j.needs_editing ? badge('Needs editing', 'warn') : null,
    h('div.muted', [j.title, j.customer_name].filter(Boolean).join(' · '))),
  h('div.job-mini-right', stageBadge(j.stage),
    j.due_date ? h('small', { class: j.due_date < today ? 'text-bad' : 'muted' }, 'Due ' + fmtDate(j.due_date)) : null))));
}

function card(title, link, ...body) {
  return h('section.card.home-card',
    h('div.section-head', h('h3', title), link ? h('a.section-link', { href: link[1] }, link[0], ' →') : null),
    body);
}

// Small counters inside a card (e.g. Printing 3 · In QC 1 · Ready 2).
const counts = items => h('div.home-counts', items.map(([label, value, tone]) => h('div', { class: tone && value ? 'is-' + tone : '' }, h('strong', value), h('span', label))));

export async function render(view) {
  const d = await api('me/home');
  const company = d.company;
  const s = d.sales;

  /* Header: just the greeting. Clock status is already in the top bar. */
  view.append(h('div.home-head',
    h('div',
      h('h2', `${greeting()}, ${cfg.user.name.split(' ')[0]}`),
      h('p.muted', [cfg.user.role_name, new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' })].filter(Boolean).join(' · ')))));

  /* Quick actions: the few things this person does most (max 5). */
  const quick = [];
  if (company) {
    if (can('mq_sales')) quick.push(['New invoice', 'sales', '#/sales/docs/new/invoice'], ['Online orders', 'pos', '#/sales/orders']);
    if (can('mq_view_dashboard')) quick.push(['Department reports', 'admin', '#/admin/reports']);
    if (can('mq_view_board')) quick.push(['Production monitor', 'production', '#/production/monitor']);
    if (can('mq_manage')) quick.push(['Staff', 'users', '#/admin/staff']);
  } else {
    if (can('mq_sales')) quick.push(['New quotation', 'sales', '#/sales/docs/new/quotation'], ['New invoice', 'sales', '#/sales/docs/new/invoice'], ['Online orders', 'pos', '#/sales/orders']);
    if (can('mq_creatives')) quick.push(['Creatives queue', 'creatives', '#/creatives']);
    if (can('mq_production') || can('mq_qc')) quick.push(['Production queue', 'production', '#/production']);
    if (can('mq_pos')) quick.push(['Open POS', 'pos', '#/pos']);
  }
  if (quick.length) view.append(h('nav.quick', { 'aria-label': 'Quick actions' }, quick.slice(0, 5).map(([l, ic, href]) => h('a.quick-btn', { href }, icon(ic, 16), l))));

  /* Key numbers (max 4). */
  const stats = [];
  if (company) {
    const pipe = company.pipeline || {};
    const open = ['sales', 'creatives', 'production', 'qc', 'ready'].reduce((a, k) => a + (pipe[k] ? Number(pipe[k].count) : 0), 0);
    if (company.sales_today !== undefined) stats.push(stat('Sales today', money(company.sales_today), '#/admin/dashboard', '', `${money(company.collected_today)} collected`));
    if (company.receivables !== undefined) stats.push(stat('Unpaid balances', money(company.receivables), '#/admin/invoices', company.receivables ? 'warn' : ''));
    stats.push(stat('Job orders open', String(open), '#/admin/invoices'));
    stats.push(stat('Overdue', String(company.overdue.length), '#/admin/invoices', company.overdue.length ? 'bad' : ''));
  } else if (s) {
    stats.push(stat('My sales today', money(s.sales_today), '#/sales/docs'));
    stats.push(stat('This month', money(s.sales_month), '#/sales/docs'));
    stats.push(stat('Unpaid balances', money(s.unpaid_balance), '#/sales/docs', s.unpaid_balance ? 'warn' : ''));
    stats.push(stat('Commission', money(s.commission_pending + s.commission_earned), '#/sales/commissions', '', `${money(s.commission_earned)} earned`));
  } else if (d.pos) {
    stats.push(stat('Branch sales today', money(d.pos.total_today), '#/pos/summary', '', d.pos.branch));
    stats.push(stat('Receipts', String(d.pos.receipts_today), '#/pos/sales'));
    stats.push(stat('My sales', money(d.pos.my_total), '#/pos/sales'));
    stats.push(stat('Voided', String(d.pos.voided_today), '#/pos/sales', d.pos.voided_today ? 'warn' : ''));
  }
  if (stats.length) view.append(h('div.home-stats', stats.slice(0, 4)));

  /* Main column and side column. */
  const main = h('div.home-main');
  const side = h('aside.home-side');
  view.append(h('div.home-layout', main, side));

  // Website quotation requests still waiting for prices: first, so they get answered within 24 hours.
  const q = d.quote_requests;
  if (q && q.count) {
    const waited = at => {
      const hrs = Math.max(0, (Date.now() - new Date(at.replace(' ', 'T')).getTime()) / 3600000);
      return hrs < 1 ? 'just now' : hrs < 48 ? Math.floor(hrs) + 'h ago' : Math.floor(hrs / 24) + ' days ago';
    };
    const openDoc = id => { location.hash = '#/sales/docs/' + id; };
    main.append(h('section.card.home-card.home-urgent', { class: q.late ? 'is-late' : '' },
      h('div.section-head', h('h3', `Needs quotation (${q.count})`), h('a.section-link', { href: '#/sales/docs' }, 'All quotations →')),
      h('p.home-hint', icon('alert', 16), q.late
        ? `${q.late} past 20 hours. The website promises a reply within 24 hours: price ${q.late === 1 ? 'it' : 'them'} now.`
        : 'Customers were promised a reply within 24 hours. Open one, add the prices, save, then Email to customer.'),
      h('ul.job-mini', q.items.map(r => h('li', {
        tabindex: 0, role: 'button',
        onclick: () => openDoc(r.id),
        onkeydown: e => { if (e.key === 'Enter') openDoc(r.id); },
      },
      h('div', h('strong', r.number), ' ', r.is_late ? badge('Late', 'bad') : null,
        h('div.muted', [r.customer_name, r.customer_company, r.title].filter(Boolean).join(' · '))),
      h('div.job-mini-right', badge('Needs pricing', 'warn'),
        h('small', { class: r.is_late ? 'text-bad' : 'muted', title: fmtDate(r.created_at, true) }, 'Asked ' + waited(r.created_at)))))),
      q.count > q.items.length ? h('p.muted', `+ ${q.count - q.items.length} more in Sales → Invoice Manager.`) : null));
  }

  if (company) {
    if (company.brands) {
      const max = Math.max(1, ...company.brands.map(b => b.total));
      main.append(card('Sales today by brand', ['Full dashboard', '#/admin/dashboard'],
        h('div.hbars', company.brands.map(b => h('div.hbar', h('span.hbar-label', b.name),
          h('span.hbar-track', h('span.hbar-fill', { style: { width: Math.round(b.total / max * 100) + '%' } })),
          h('strong', money(b.total)))))));
    }
    const pipe = company.pipeline || {};
    main.append(card('Job orders in progress', ['All job orders', '#/admin/invoices'],
      counts(['sales', 'creatives', 'production', 'qc', 'ready'].map(k => [STAGES[k], pipe[k] ? Number(pipe[k].count) : 0]))));
    if (company.overdue.length) main.append(card('Overdue job orders', null, jobMini(company.overdue, '', 5)));
  } else {
    if (s) {
      main.append(card('My job orders', ['All', '#/sales/jobs'],
        s.blocked_jobs ? h('p.home-hint', icon('alert', 16), `${s.blocked_jobs} still in Sales — check payment, specs and sales person.`) : null,
        jobMini(s.jobs, 'No open job orders.')));
    }
    if (d.creatives) {
      const cr = d.creatives;
      main.append(card('Creatives queue', ['Open Creatives', '#/creatives'],
        counts([['Waiting', cr.waiting], ['Needs editing', cr.needs_editing, 'warn'], ['Approved today', cr.approved_today]]),
        jobMini(cr.jobs, 'Nothing waiting for Creatives.')));
    }
    if (d.production) {
      const p = d.production;
      main.append(card('Production & QC', ['Open queue', '#/production'],
        counts([['Printing', p.production], ['In QC', p.qc], ['Ready', p.ready], ['Rush', p.rush, 'bad'], ['Overdue', p.overdue, 'bad']]),
        jobMini(p.jobs, 'Nothing in production or QC.')));
    }
    if (d.pos && !s) {
      main.append(card(`POS · ${d.pos.branch}`, null,
        h('p.muted', 'Ring up walk-in sales, print receipts and close the day.'),
        h('div.actions.actions-left', h('a.btn.btn-primary', { href: '#/pos' }, 'Open the register'), h('a.btn', { href: '#/pos/summary' }, 'End of day'))));
    }
    if (d.pos && s) {
      main.append(card(`POS today · ${d.pos.branch}`, ['Open POS', '#/pos'],
        counts([['Branch sales', money(d.pos.total_today)], ['Receipts', d.pos.receipts_today], ['My sales', money(d.pos.my_total)]])));
    }
  }
  if (!main.children.length) main.append(card('Welcome', null, h('p.muted', 'Your work will show here. Use the menu on the left to get started.')));

  /* Side: to do (only things that need someone), this week, recent activity. */
  const todo = [];
  if (q && q.count) todo.push(['Quotations to price', q.count, '#/sales/docs']);
  if (s && s.orders_to_verify) todo.push(['Online orders to verify', s.orders_to_verify, '#/sales/orders']);
  if (s && s.orders_to_price) todo.push(['New online orders to price', s.orders_to_price, '#/sales/orders']);
  if (s && s.new_uploads) todo.push(['New customer uploads', s.new_uploads, '#/sales/uploads']);
  if (s && s.open_quotations && !company) todo.push(['Open quotations', s.open_quotations, '#/sales/docs']);
  if (cfg.pendingSignups) todo.push(['Account requests', cfg.pendingSignups, '#/admin/requests']);
  if (cfg.pendingReviews) todo.push(['Reviews to approve', cfg.pendingReviews, '#/admin/reviews/pending']);
  if (company && company.commissions_to_pay) todo.push(['Commissions to pay', money(company.commissions_to_pay), '#/admin/commissions']);
  side.append(card('To do', null,
    todo.length ? h('ul.todo', todo.map(([label, n, href]) => h('li', h('a', { href }, h('span', label), h('strong', String(n)), icon('next', 14)))))
      : h('p.muted.home-done', icon('check', 16), "You're all caught up.")));

  const max = Math.max(60, ...d.week.map(w => w.worked_minutes));
  const total = d.week.reduce((a, w) => a + w.worked_minutes, 0);
  side.append(card('My week', ['Time clock', '#/clock'],
    h('p.home-week-total', h('strong', duration(total)), h('span.muted', ' worked in the last 7 days')),
    h('div.week-bars.week-bars-sm', d.week.map((w, i) => h('div.week-bar', { class: i === d.week.length - 1 ? 'is-today' : '', title: `${fmtDate(w.date)} — ${duration(w.worked_minutes)}` },
      h('span.bar', h('span.fill', { style: { height: Math.round(w.worked_minutes / max * 100) + '%' } })),
      h('span.bar-label', new Date(w.date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'narrow' })))))));

  if (d.activity && d.activity.length) {
    side.append(card('Recent activity', null,
      h('ul.activity', d.activity.slice(0, 6).map(a => h('li', {
        tabindex: 0, role: 'button', onclick: () => openJob(a.job_id),
        onkeydown: e => { if (e.key === 'Enter') openJob(a.job_id); },
      },
      h('div', h('strong', a.number), ' ', ACTIONS_LABEL[a.action] || a.action),
      h('small.muted', `${a.user ? a.user.name + ' · ' : ''}${fmtDate(a.created_at, true)}`))))));
  }
}
