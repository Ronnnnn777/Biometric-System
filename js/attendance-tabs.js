/**
 * Attendance Logs controller.
 *
 * Owns the three tabs (Live scans, Daily attendance, Scan history), the shared
 * filters, the summary cards, server-side pagination, and the export links.
 * All data is fetched from the matching *.php endpoints so the displayed rows,
 * the pager totals, and the exports always agree.
 */
window.initAttendanceTabs = function (config) {
  'use strict';
  const perPage = Number(config.perPage) || 15;
  const today = config.today || '';
  const byId = id => document.getElementById(id);

  const controls = {
    date: byId('date-filter'),
    grade: byId('grade-filter'),
    section: byId('section-filter'),
    student: byId('student-filter'),
    status: byId('status-filter'),
    source: byId('source-filter'),
    result: byId('result-filter'),
    from: byId('date-from-filter'),
    to: byId('date-to-filter'),
  };
  const bodies = { live: byId('live-rows'), daily: byId('attendance-rows'), history: byId('history-rows') };
  const pagers = { live: byId('live-pagination'), daily: byId('attendance-pagination'), history: byId('history-pagination') };
  const panels = { live: byId('panel-live'), daily: byId('panel-daily'), history: byId('panel-history') };
  const tabs = Array.from(document.querySelectorAll('[data-tab]'));
  const exportLink = byId('export-link');
  const historyExport = byId('history-export');
  const historyNotice = byId('history-notice');

  const state = {
    tab: 'live',
    page: { live: 1, daily: 1, history: 1 },
    controllers: {},
    liveKeys: new Set(),
    liveSeen: false,
  };

  function liveParams() {
    return new URLSearchParams({
      date: controls.date.value || today,
      grade: controls.grade.value,
      section: controls.section.value,
      student: controls.student.value.trim(),
    });
  }

  function paramsFor(tab) {
    const grade = controls.grade.value;
    const section = controls.section.value;
    const student = controls.student.value.trim();
    if (tab === 'history') {
      const from = controls.from.value || controls.date.value || today;
      const to = controls.to.value || controls.date.value || today;
      return new URLSearchParams({
        date_from: from,
        date_to: to,
        grade,
        section,
        student,
        source: controls.source.value,
        result: controls.result.value,
        page: String(state.page.history),
      });
    }
    const params = new URLSearchParams({
      date: controls.date.value || today,
      grade,
      section,
      student,
      page: String(state.page[tab]),
    });
    if (tab === 'daily') params.set('status', controls.status.value);
    return params;
  }

  function endpointFor(tab) {
    if (tab === 'live') return 'get-live-scans.php';
    if (tab === 'daily') return 'get-latest-attendance.php';
    return 'get-scan-history.php';
  }

  async function fetchJson(tab) {
    state.controllers[tab]?.abort();
    const controller = new AbortController();
    state.controllers[tab] = controller;
    const response = await fetch(`${endpointFor(tab)}?${paramsFor(tab).toString()}`, {
      signal: controller.signal,
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error(`Request failed (${response.status}).`);
    return response.json();
  }

  function applyRows(tab, html, highlight) {
    const body = bodies[tab];
    if (!body) return;
    const holder = document.createElement('tbody');
    holder.innerHTML = html;
    const rows = Array.from(holder.children);
    if (tab === 'live') {
      const nextKeys = new Set();
      rows.forEach(row => {
        const key = row.getAttribute('data-key');
        if (key) {
          nextKeys.add(key);
          if (highlight && state.liveSeen && !state.liveKeys.has(key)) row.classList.add('scan-row-new');
        }
      });
      state.liveKeys = nextKeys;
      state.liveSeen = true;
    }
    body.replaceChildren(...rows);
  }

  function renderPager(tab, data) {
    const pager = pagers[tab];
    if (!pager) return;
    pager.replaceChildren();
    pager.className = 'my-5 flex flex-wrap items-center justify-between gap-3';
    if (!data.total) {
      pager.hidden = true;
      return;
    }
    pager.hidden = false;
    const first = (data.page - 1) * data.page_size + 1;
    const last = Math.min(data.page * data.page_size, data.total);
    const summary = document.createElement('p');
    summary.className = 'text-sm font-semibold text-slate-500';
    summary.setAttribute('aria-live', 'polite');
    summary.textContent = `${first}–${last} of ${data.total}`;
    const group = document.createElement('div');
    group.className = 'flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm';
    const makeButton = (label, page, disabled, active, aria) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.disabled = disabled;
      button.setAttribute('aria-label', aria || `Page ${label}`);
      if (active) button.setAttribute('aria-current', 'page');
      button.className = 'grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-bold transition ' +
        (active ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300');
      button.onclick = () => goTo(tab, page);
      return button;
    };
    group.append(makeButton('‹', data.page - 1, data.page <= 1, false, 'Previous page'));
    const numbers = [];
    for (let page = 1; page <= data.page_count; page++) {
      if (page === 1 || page === data.page_count || Math.abs(page - data.page) <= 1) numbers.push(page);
    }
    let previous = 0;
    numbers.forEach(page => {
      if (page - previous > 1) {
        const span = document.createElement('span');
        span.className = 'grid h-9 min-w-6 place-items-center text-sm font-bold text-slate-400';
        span.textContent = '…';
        group.append(span);
      }
      group.append(makeButton(String(page), page, false, page === data.page));
      previous = page;
    });
    group.append(makeButton('›', data.page + 1, data.page >= data.page_count, false, 'Next page'));
    pager.append(summary, group);
  }

  async function load(tab, scroll) {
    const isActive = tab === state.tab;
    try {
      const data = await fetchJson(tab);
      applyRows(tab, data.rows, tab === 'live');
      if (isActive) renderPager(tab, data);
      if (tab === 'history' && historyNotice) {
        if (data.notice) {
          historyNotice.textContent = data.notice;
          historyNotice.hidden = false;
        } else {
          historyNotice.hidden = true;
        }
      }
      if (isActive && scroll) {
        bodies[tab]?.closest('table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      console.warn('Attendance load failed.', error);
      const body = bodies[tab];
      if (body) {
        body.innerHTML = '<tr><td colspan="' + (tab === 'daily' ? 11 : 9) + '" class="px-4 py-8 text-center text-rose-600">Could not load records. Check your connection and try again.</td></tr>';
      }
      if (isActive && pagers[tab]) pagers[tab].hidden = true;
    }
  }

  function goTo(tab, page) {
    state.page[tab] = Math.max(1, page);
    load(tab, true);
  }

  function updateExports() {
    const grade = controls.grade.value;
    const section = controls.section.value;
    const student = controls.student.value.trim();
    if (exportLink) {
      const params = new URLSearchParams({ date: controls.date.value || today, grade, section });
      if (student) params.set('student', student);
      exportLink.href = `export-attendance.php?${params.toString()}`;
    }
    if (historyExport) {
      const params = new URLSearchParams({
        date_from: controls.from.value || controls.date.value || today,
        date_to: controls.to.value || controls.date.value || today,
        grade,
        section,
        source: controls.source.value,
        result: controls.result.value,
      });
      if (student) params.set('student', student);
      historyExport.href = `export-scan-events.php?${params.toString()}`;
    }
  }

  async function loadSummary() {
    try {
      const params = new URLSearchParams({
        date: controls.date.value || today,
        grade: controls.grade.value,
        section: controls.section.value,
      });
      const response = await fetch(`get-attendance-summary.php?${params.toString()}`, {
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      if (!response.ok) return;
      const data = await response.json();
      const scanned = byId('summary-scanned');
      const notScanned = byId('summary-not-scanned');
      const notScannedNote = byId('summary-not-scanned-note');
      const review = byId('summary-needs-review');
      const reviewNote = byId('summary-needs-review-note');
      if (scanned) scanned.textContent = data.scanned_today;
      if (notScanned) {
        if (data.is_school_day) {
          notScanned.textContent = data.not_scanned;
          if (notScannedNote) notScannedNote.textContent = 'Enrolled students without a scan yet';
        } else {
          notScanned.textContent = 'No classes';
          if (notScannedNote) notScannedNote.textContent = data.day_label || 'Non-school day';
        }
      }
      if (review) review.textContent = data.needs_review;
      if (reviewNote) {
        const breakdown = data.needs_review_breakdown || {};
        reviewNote.textContent = data.needs_review
          ? `${breakdown.incomplete || 0} incomplete · ${breakdown.rejected || 0} rejected scans`
          : 'Incomplete records and rejected scans';
      }
    } catch (_) {
      /* keep the last known summary on failure */
    }
  }

  function onFilterChange() {
    state.page[state.tab] = 1;
    updateExports();
    load(state.tab, false);
    loadSummary();
  }

  function setTab(tab) {
    state.tab = tab;
    tabs.forEach(item => item.setAttribute('aria-selected', String(item.dataset.tab === tab)));
    Object.keys(panels).forEach(key => {
      if (panels[key]) panels[key].hidden = key !== tab;
    });
    if (exportLink) exportLink.hidden = tab === 'history';
    if (historyExport) historyExport.hidden = tab !== 'history';
    updateExports();
    load(tab, false);
    if (tab === 'live') window.attendanceLiveRefresh?.prime?.();
  }

  tabs.forEach(item => item.addEventListener('click', () => setTab(item.dataset.tab)));

  ['grade', 'section', 'status', 'source', 'result', 'from', 'to'].forEach(key => {
    controls[key]?.addEventListener('change', onFilterChange);
  });

  controls.date?.addEventListener('change', () => {
    // A single-day history range follows the main date; a custom range is kept.
    if (controls.from && controls.to && controls.from.value === controls.to.value) {
      controls.from.value = controls.date.value;
      controls.to.value = controls.date.value;
    }
    onFilterChange();
  });

  let searchTimer;
  controls.student?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(onFilterChange, 250);
  });

  byId('today-button')?.addEventListener('click', () => {
    controls.date.value = today;
    if (controls.from && controls.to && controls.from.value === controls.to.value) {
      controls.from.value = today;
      controls.to.value = today;
    }
    onFilterChange();
  });

  const previousButton = byId('previous-day-button');
  previousButton?.addEventListener('click', () => {
    if (!previousButton.dataset.date) return;
    controls.date.value = previousButton.dataset.date;
    if (controls.from && controls.to && controls.from.value === controls.to.value) {
      controls.from.value = previousButton.dataset.date;
      controls.to.value = previousButton.dataset.date;
    }
    onFilterChange();
  });

  byId('clear-attendance-filters')?.addEventListener('click', () => {
    controls.grade.value = 'all';
    controls.grade.dispatchEvent(new Event('change'));
    controls.student.value = '';
    controls.date.value = today;
    if (controls.from) controls.from.value = today;
    if (controls.to) controls.to.value = today;
    controls.status.value = 'all';
    controls.source.value = 'all';
    controls.result.value = 'all';
    onFilterChange();
  });

  byId('toggle-advanced')?.addEventListener('click', () => {
    byId('advanced-filters')?.classList.toggle('hidden');
  });

  setTab('live');
  loadSummary();
  setInterval(loadSummary, 20000);

  window.attendanceTabs = {
    get tab() { return state.tab; },
    liveParams,
    refreshLive() {
      if (state.tab !== 'live') return;
      load('live', false);
      loadSummary();
    },
  };
};
