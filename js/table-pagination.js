window.createTablePagination = ({ rows, pager, label = 'records', perPage = 15 }) => {
  const pagers = (Array.isArray(pager) ? pager : [pager]).filter(Boolean);
  if (!rows || !pagers.length) return { refresh() {} };
  let currentPage = 1;

  const button = (labelText, disabled, onClick, active = false, ariaLabel = '') => {
    const item = document.createElement('button');
    item.type = 'button';
    item.textContent = labelText;
    item.disabled = disabled;
    item.setAttribute('aria-label', ariaLabel || `Page ${labelText}`);
    if (active) item.setAttribute('aria-current', 'page');
    item.className = 'grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-bold transition ' + (active
      ? 'bg-blue-600 text-white shadow-sm'
      : 'text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300');
    item.onclick = onClick;
    return item;
  };

  function controls(totalPages, scrollToTable) {
    const changePage = page => {
      currentPage = page;
      render();
      if (scrollToTable) rows.closest('table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    const group = document.createElement('div');
    group.className = 'flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm';
    group.append(button('‹', currentPage === 1, () => changePage(currentPage - 1), false, 'Previous page'));
    const pageNumbers = [];
    for (let page = 1; page <= totalPages; page++) {
      if (page === 1 || page === totalPages || Math.abs(page - currentPage) <= 1) pageNumbers.push(page);
    }
    let previous = 0;
    pageNumbers.forEach(page => {
      if (page - previous > 1) {
        const ellipsis = document.createElement('span');
        ellipsis.className = 'grid h-9 min-w-6 place-items-center text-sm font-bold text-slate-400';
        ellipsis.textContent = '…';
        group.append(ellipsis);
      }
      group.append(button(String(page), false, () => changePage(page), page === currentPage));
      previous = page;
    });
    group.append(button('›', currentPage === totalPages, () => changePage(currentPage + 1), false, 'Next page'));
    return group;
  }

  function render(reset = false) {
    if (reset) currentPage = 1;
    const records = Array.from(rows.querySelectorAll(':scope > tr')).filter(row => !row.querySelector('[colspan]'));
    const totalPages = Math.max(1, Math.ceil(records.length / perPage));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * perPage;
    const last = Math.min(first + perPage, records.length);
    const visible = new Set(records.slice(first, first + perPage));
    records.forEach(row => { row.hidden = !visible.has(row); });
    pagers.forEach((container, index) => {
      container.replaceChildren();
      container.className = 'my-5 flex flex-wrap items-center justify-between gap-3';
      container.hidden = !records.length;
      if (records.length) {
        const count = document.createElement('p');
        count.className = 'text-sm font-semibold text-slate-500';
        count.setAttribute('aria-live', 'polite');
        count.textContent = `Showing ${first + 1}–${last} of ${records.length} ${label}`;
        container.append(count, controls(totalPages, index > 0));
      }
    });
  }

  render();
  return { refresh: render };
};
