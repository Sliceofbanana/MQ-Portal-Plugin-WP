// Time Clock: clock in → breaks → clock out, today's timeline, this week and history.

import { cfg, h, clear, api, toast, confirmBox, table, fmtDate, fmtTime, duration, empty } from '../core.js';
import { icon } from '../icons.js';

function getPosition() {
  return new Promise(resolve => {
    if (!navigator.geolocation) return resolve(null);
    navigator.geolocation.getCurrentPosition(
      p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
  });
}

const t12 = hm => new Date('2000-01-01T' + hm).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
const parse = s => new Date(String(s).replace(' ', 'T'));

export async function render(view) {
  let status = await api('me/clock');
  let loadedAt = Date.now();

  const clockTime = h('div.clock-time');
  const clockDate = h('div.clock-date');
  const stateEl = h('div.clock-state');
  const counters = h('div.clock-counters');
  const actions = h('div.clock-actions');
  const msg = h('p.clock-msg', { role: 'alert' });
  const rulesEl = h('div.clock-rules');
  const todayEl = h('div');
  const weekEl = h('div');
  const historyEl = h('div');

  const tick = () => {
    const now = new Date();
    clockTime.textContent = now.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    clockDate.textContent = now.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    const elapsed = (Date.now() - loadedAt) / 60000;
    const worked = status.state === 'working' ? status.worked_minutes + elapsed : status.worked_minutes;
    const brk = status.state === 'break' ? status.break_minutes + elapsed : status.break_minutes;
    clear(counters,
      h('div.counter', h('span', 'Worked today'), h('strong', duration(worked))),
      h('div.counter', h('span', 'Break'), h('strong', duration(brk))),
      h('div.counter', h('span', 'Clocked in'), h('strong', status.shift ? fmtTime(status.shift.time_in) : '—')));
  };

  const act = async (action, btn) => {
    if (action === 'out' && !(await confirmBox('Clock out', 'End your shift now?', 'Clock out'))) return;
    msg.textContent = '';
    actions.querySelectorAll('button').forEach(b => { b.disabled = true; });
    const label = btn.textContent;
    let geo = {};
    if (action === 'in' && status.rules.needs_gps) {
      btn.textContent = 'Checking you are at the shop…';
      geo = (await getPosition()) || {};
    }
    btn.textContent = 'Saving…';
    try {
      status = await api('me/clock', { method: 'POST', body: { action, ...geo } });
      loadedAt = Date.now();
      window.dispatchEvent(new CustomEvent('mqp-clock', { detail: status }));
      toast({ in: 'Clocked in. Have a good shift!', break_start: 'Break started.', break_end: 'Welcome back — break ended.', out: 'Clocked out. See you next time!' }[action], 'ok');
      draw();
      loadHistory();
    } catch (e) {
      msg.textContent = e.offline ? 'You are offline. Clocking in and out needs an internet connection.' : e.message;
      btn.textContent = label;
      actions.querySelectorAll('button').forEach(b => { b.disabled = false; });
    }
  };

  const big = (label, ic, action, cls) => {
    const b = h('button.btn.btn-clock', { type: 'button', class: cls, onclick: () => act(action, b) }, icon(ic, 22), label);
    return b;
  };

  function draw() {
    const s = status;
    const states = {
      off: ['Off duty', 'You are not clocked in.'],
      working: ['On duty', `Since ${fmtTime(s.since)}`],
      break: ['On break', `Since ${fmtTime(s.since)}`],
    };
    clear(stateEl, h('span.state-badge', { class: 'is-' + s.state }, h('span.dot'), states[s.state][0]), h('span.muted', states[s.state][1]));
    clear(actions,
      s.state === 'off' ? big('Clock in', 'play', 'in', 'is-in') : null,
      s.state === 'working' ? big('Start break', 'coffee', 'break_start', 'is-break') : null,
      s.state === 'break' ? big('End break', 'play', 'break_end', 'is-in') : null,
      s.state !== 'off' ? big('Clock out', 'stop', 'out', 'is-out') : null);

    const r = s.rules;
    const notes = [];
    if (r.window) notes.push(['clock', `Clock-in is allowed from ${t12(r.window[0])} to ${t12(r.window[1])}.`]);
    if (r.shop_only) {
      notes.push(r.at_shop ? ['check', `You are on the ${r.at_shop} Wi-Fi.`]
        : r.needs_gps ? ['alert', 'You need to be at the shop to clock in. Connect to the shop Wi-Fi, or allow location access when asked.']
          : ['alert', 'You need to be at the shop to clock in. Connect to the shop Wi-Fi.']);
    }
    clear(rulesEl, notes.map(([ic, n]) => h('p', icon(ic, 16), n)));

    // Today's timeline
    const items = [];
    if (s.shift) {
      items.push({ t: s.shift.time_in, label: 'Clocked in', kind: 'in' });
      s.breaks.forEach(b => {
        items.push({ t: b.break_start, label: 'Break started', kind: 'break' });
        if (b.break_end) items.push({ t: b.break_end, label: `Break ended (${duration((parse(b.break_end) - parse(b.break_start)) / 60000)})`, kind: 'in' });
      });
    }
    clear(todayEl, h('h3', 'Current shift'),
      items.length ? h('ol.clock-timeline', items.map(i => h('li', { class: 'is-' + i.kind }, h('strong', fmtTime(i.t)), h('span', i.label))))
        : empty('No open shift. Press "Clock in" when you start work.'));

    // This week
    const max = Math.max(60, ...s.week.map(d => d.worked_minutes));
    const total = s.week.reduce((a, d) => a + d.worked_minutes, 0);
    clear(weekEl,
      h('div.toolbar', h('h3', 'Last 7 days'), h('strong', duration(total) + ' worked')),
      h('div.week-bars', s.week.map(d => {
        const date = parse(d.date + ' 00:00:00');
        const isToday = d.date === s.week[s.week.length - 1].date;
        return h('div.week-bar', { class: isToday ? 'is-today' : '', title: `${fmtDate(d.date)} — ${duration(d.worked_minutes)}` },
          h('span.bar-value', d.worked_minutes ? duration(d.worked_minutes) : ''),
          h('span.bar', h('span.fill', { style: { height: Math.round(d.worked_minutes / max * 100) + '%' } })),
          h('span.bar-label', date.toLocaleDateString('en-PH', { weekday: 'short' })));
      })));
    tick();
  }

  async function loadHistory() {
    const from = new Date(); from.setDate(from.getDate() - 30);
    const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    try {
      const rows = await api('finance/attendance', { query: { user_id: cfg.user.id, from: ymd(from) } });
      clear(historyEl, h('h3', 'Attendance history (30 days)'), table(rows, [
        { label: 'Date', render: r => fmtDate(r.time_in) },
        { label: 'Clock in', render: r => fmtTime(r.time_in) },
        { label: 'Clock out', render: r => r.time_out ? fmtTime(r.time_out) + (r.auto_closed ? ' (auto)' : '') : h('span.badge.badge-ok', 'Open') },
        { label: 'Breaks', render: r => r.breaks.length ? `${r.breaks.length} · ${duration(r.break_minutes)}` : '—' },
        { label: 'Worked', render: r => duration(r.worked_minutes), class: 'num' },
      ], { emptyText: 'No attendance yet.' }));
    } catch (e) { clear(historyEl, empty(e.message)); }
  }

  view.append(
    h('div.page-head', h('div', h('h2', 'Time Clock'), h('p.muted', 'Clock in when you start, take breaks, and clock out when you finish.'))),
    h('div.clock-grid',
      h('section.card.clock-card', clockTime, clockDate, stateEl, actions, msg, counters, rulesEl),
      h('section.card', todayEl)),
    h('section.card', weekEl),
    h('section.card', historyEl));
  draw();
  loadHistory();
  const timer = setInterval(tick, 1000);
  return () => clearInterval(timer);
}
