(() => {
  const rowsContainer = document.getElementById('student-rows');
  const search = document.getElementById('student-search');
  if (!rowsContainer || !search) return;

  const rows = Array.from(rowsContainer.querySelectorAll('tr'));
  const grade = document.getElementById('grade-filter');
  const section = document.getElementById('section-filter');
  const status = document.getElementById('status-filter');
  const perPage = 15;
  let currentPage = 1;

  const pager = document.createElement('nav');
  pager.className = 'mt-5 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:flex-row sm:items-center sm:justify-between';
  pager.setAttribute('aria-label', 'Student list pagination');
  rowsContainer.closest('.overflow-hidden')?.insertAdjacentElement('afterend', pager);

  const matches = row => {
    const query = search.value.trim().toLowerCase();
    return (!query || row.textContent.toLowerCase().includes(query))
      && (!grade || !grade.value || row.dataset.grade === grade.value)
      && (!section || !section.value || row.dataset.section === section.value)
      && (!status || !status.value || row.dataset.status === status.value);
  };

  function makeButton(label, disabled, onClick, primary = false) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.disabled = disabled;
    button.className = 'rounded-lg px-3 py-2 text-sm font-bold transition ' + (primary
      ? 'bg-blue-600 text-white hover:bg-blue-700 disabled:bg-slate-200 disabled:text-slate-400'
      : 'border border-slate-300 text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400');
    button.onclick = onClick;
    return button;
  }

  function render() {
    const matchingRows = rows.filter(matches);
    const totalPages = Math.max(1, Math.ceil(matchingRows.length / perPage));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * perPage;
    const visibleRows = new Set(matchingRows.slice(first, first + perPage));
    rows.forEach(row => { row.hidden = !visibleRows.has(row); });

    pager.replaceChildren();
    const summary = document.createElement('p');
    summary.className = 'text-sm font-semibold text-slate-600';
    summary.textContent = matchingRows.length
      ? `Showing ${first + 1}–${Math.min(first + perPage, matchingRows.length)} of ${matchingRows.length} students`
      : 'No students match your filters.';

    const controls = document.createElement('div');
    controls.className = 'flex items-center gap-2';
    const page = document.createElement('span');
    page.className = 'px-2 text-sm font-bold text-slate-600';
    page.textContent = `Page ${currentPage} of ${totalPages}`;
    controls.append(
      makeButton('Previous', currentPage === 1, () => { currentPage--; render(); }),
      page,
      makeButton('Next', currentPage === totalPages, () => { currentPage++; render(); }, true)
    );
    pager.append(summary, controls);
  }

  const resetPage = () => { currentPage = 1; render(); };
  search.oninput = resetPage;
  if (grade) grade.onchange = resetPage;
  if (section) section.onchange = resetPage;
  if (status) status.onchange = resetPage;
  render();
})();
