// Creatives: one board of job orders waiting for file review / design editing.

import { h, clear, api, run, fmtDate, badge, brandName } from '../core.js';
import { openJob } from '../jobs.js';

export function jobCard(j, onOpen) {
  return h('button.job-card', { type: 'button', onclick: () => onOpen(j), class: j.priority ? 'is-rush' : '' },
    h('div.job-card-top', h('strong', j.number), j.priority ? badge('RUSH', 'bad') : null, j.needs_editing ? badge('Needs editing', 'warn') : null),
    h('div.job-card-title', j.title || '(no title)'),
    h('div.muted', j.customer_name),
    h('div.job-card-meta', h('span', brandName(j.brand_id)), h('span', j.due_date ? 'Due ' + fmtDate(j.due_date) : 'No due date'), h('span', `${j.file_count} file${j.file_count === 1 ? '' : 's'}`)));
}

/** Board columns: [{ title, jobs, empty }] → one column each, cards open the job order. */
export function kanban(cols, onOpen) {
  return cols.map(c => h('section.kanban-col', { 'aria-label': c.title },
    h('header.kanban-head', h('h3', c.title), h('span.count', String(c.jobs.length))),
    c.hint ? h('p.kanban-hint', c.hint) : null,
    c.jobs.length ? h('div.kanban-cards', c.jobs.map(j => jobCard(j, onOpen))) : h('div.kanban-empty', c.empty || 'Nothing here.')));
}

export async function render(view) {
  const board = h('div.kanban');
  view.append(h('p.muted.kanban-intro', 'Approve files that are ready to print, or edit the design and attach it before approving. Rush jobs come first.'), board);

  const load = async () => {
    const rows = await run(() => api('jobs', { query: { stage: 'creatives' } }));
    if (!rows) return;
    clear(board, kanban([
      { title: 'Not yet reviewed', jobs: rows.filter(j => !j.needs_editing), empty: 'No new files to check.' },
      { title: 'Needs editing', jobs: rows.filter(j => j.needs_editing), empty: 'Nothing to edit.' },
    ], x => openJob(x.id, load)));
  };
  load();
  const timer = setInterval(load, 30000);
  return () => clearInterval(timer);
}
