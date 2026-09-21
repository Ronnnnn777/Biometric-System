window.createTablePagination = ({ rows, pager, label = 'records', perPage = 15 }) => {
  if (!rows || !pager) return { refresh() {} };
  let currentPage = 1;

  const button = (labelText, disabled, onClick, primary = false) => {
    const item = document.createElement('button');
    item.type = 'button';
    item.textContent = labelText;
    item.disabled = disabled;
    item.className = 'rounded-lg px-3 py-2 text-sm font-bold transition ' + (primary
      ? 'bg-blue-600 text-white hover:bg-blue-700 disabled:bg-slate-200 disabled:text-slate-400'
      : 'border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400');
    item.onclick = onClick;
    return item;
  };

  function render(reset = false) {
    if (reset) currentPage = 1;
    const records = Array.from(rows.querySelectorAll(':scope > tr')).filter(row => !row.querySelector('[colspan]'));
    const totalPages = Math.max(1, Math.ceil(records.length / perPage));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * perPage;
    const visible = new Set(records.slice(first, first + perPage));
    records.forEach(row => { row.hidden = !visible.has(row); });
    pager.replaceChildren();
    pager.className = 'mt-5 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:flex-row sm:items-center sm:justify-between';
    if (!records.length) { pager.hidden = true; return; }
    pager.hidden = false;
    const summary = document.createElement('p');
    summary.className = 'text-sm font-semibold text-slate-600';
    summary.textContent = `Showing ${first + 1}–${Math.min(first + perPage, records.length)} of ${records.length} ${label}`;
    const controls = document.createElement('div');
    controls.className = 'flex items-center gap-2';
    const page = document.createElement('span');
    page.className = 'px-2 text-sm font-bold text-slate-600';
    page.textContent = `Page ${currentPage} of ${totalPages}`;
    controls.append(button('Previous', currentPage === 1, () => { currentPage--; render(); }), page, button('Next', currentPage === totalPages, () => { currentPage++; render(); }, true));
    pager.append(summary, controls);
  }

  render();
  return { refresh: render };
};
