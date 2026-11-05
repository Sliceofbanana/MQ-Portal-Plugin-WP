// Production & QC: one board (production queue, quality check, ready for delivery) and the TV monitor.

import { cfg, can, h, clear, api, run, fmtDate, fmtTime, badge, brandName, STAGES } from '../core.js';
import { openJob } from '../jobs.js';
import { kanban } from './creatives.js';

export async function render(view, params) {
  const cols = [];
  if (can('mq_production')) cols.push({ stage: 'production', title: 'Production queue', empty: 'Nothing to print right now.' });
  if (can('mq_qc')) cols.push({ stage: 'qc', title: 'Quality check', empty: 'Nothing waiting for QC.' });
  if (can('mq_production') || can('mq_qc') || can('mq_deliver')) cols.push({ stage: 'ready', title: 'Ready for delivery', empty: 'Nothing ready yet.' });
  // The production screen (and anyone who can only watch the board) gets the TV monitor.
  if (params[0] === 'monitor' || cfg.user.role === 'mq_display' || !cols.length) return monitor(view, cols.length > 0);

  const board = h('div.kanban');
  view.append(
    h('div.toolbar', h('p.muted', 'Open a job order to mark it as printed, approve it in QC or send it back.'),
      h('div.toolbar-right', h('a.btn', { href: '#/production/monitor' }, 'Production monitor (TV)'))),
    board);
  const load = async () => {
    const lists = await run(() => Promise.all(cols.map(c => api('jobs', { query: { stage: c.stage } }))));
    if (!lists) return;
    clear(board, kanban(cols.map((c, i) => ({ ...c, jobs: lists[i] })), x => openJob(x.id, load)));
  };
  load();
  const timer = setInterval(load, 30000);
  return () => clearInterval(timer);
}

/** Big-screen board for the production area TV. Refreshes every 15 seconds. */
function monitor(view, back) {
  const board = h('div.board');
  const clock = h('span.board-clock');
  const wrap = h('div.board-wrap',
    h('div.board-head',
      h('h2', 'Production board'), clock,
      h('button.btn', { type: 'button', onclick: () => (document.fullscreenElement ? document.exitFullscreen() : wrap.requestFullscreen()).catch(() => {}) }, 'Full screen')),
    board);
  if (back && cfg.user.role !== 'mq_display') view.append(h('div.toolbar', h('a.btn', { href: '#/production' }, '← Back to the board')));
  view.append(wrap);

  const cols = ['creatives', 'production', 'qc', 'ready'];
  const load = async () => {
    try {
      const data = await api('board');
      clock.textContent = 'Updated ' + fmtTime(data.generated_at);
      clear(board, cols.map(stage => {
        const jobs = data.jobs.filter(j => j.stage === stage);
        return h('section.board-col', { class: 'col-' + stage },
          h('h3', STAGES[stage], h('span.count', jobs.length)),
          jobs.length ? jobs.map(j => h('div.board-job', { class: j.priority ? 'is-rush' : '' },
            h('div.board-job-top', h('strong', j.number), j.priority ? badge('RUSH', 'bad') : null),
            h('div.board-job-title', j.title),
            h('div.board-job-meta', j.customer_name),
            h('div.board-job-meta', brandName(j.brand_id), j.due_date ? ' · Due ' + fmtDate(j.due_date) : ''))) : h('div.board-empty', '—'));
      }));
    } catch (e) {
      clock.textContent = e.offline ? 'Offline — retrying…' : e.message;
    }
  };
  load();
  const timer = setInterval(load, 15000);
  return () => clearInterval(timer);
}
