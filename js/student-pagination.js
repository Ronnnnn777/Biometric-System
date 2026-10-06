(() => {
  const rowsContainer = document.getElementById('student-rows');
  const search = document.getElementById('student-search');
  if (!rowsContainer || !search) return;

  const rows = Array.from(rowsContainer.querySelectorAll('tr'));
  const grade = document.getElementById('grade-filter');
  const section = document.getElementById('section-filter');
  const status = document.getElementById('status-filter');
  const sectionOptions = section ? Array.from(section.options).map(option => ({ value: option.value, label: option.textContent })) : [];
  const perPage = 15;
  let currentPage = 1;
  const tableCard = rowsContainer.closest('.overflow-hidden');
  const topPager = document.createElement('nav');
  const bottomPager = document.createElement('nav');
  topPager.setAttribute('aria-label', 'Student list pagination');
  bottomPager.setAttribute('aria-label', 'Student list pagination');
  tableCard?.insertAdjacentElement('beforebegin', topPager);
  tableCard?.insertAdjacentElement('afterend', bottomPager);
  const pagers = [topPager, bottomPager];

  const matches = row => {
    const query = search.value.trim().toLowerCase();
    return (!query || row.textContent.toLowerCase().includes(query))
      && (!grade || !grade.value || row.dataset.grade === grade.value)
      && (!section || !section.value || row.dataset.section === section.value)
      && (!status || !status.value || row.dataset.status === status.value);
  };

  function updateSectionOptions() {
    if (!section) return;
    const selectedGrade = grade?.value || '';
    const previousSection = section.value;
    const availableSections = new Set(rows.filter(row => !selectedGrade || row.dataset.grade === selectedGrade).map(row => row.dataset.section).filter(Boolean));
    section.replaceChildren();
    sectionOptions.forEach(({ value, label }) => {
      if (value && !availableSections.has(value)) return;
      section.add(new Option(label, value));
    });
    section.value = availableSections.has(previousSection) ? previousSection : '';
  }

  function makeButton(label, disabled, onClick, active = false, ariaLabel = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.disabled = disabled;
    button.setAttribute('aria-label', ariaLabel || `Page ${label}`);
    if (active) button.setAttribute('aria-current', 'page');
    button.className = 'grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-bold transition ' + (active ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300');
    button.onclick = onClick;
    return button;
  }

  function controls(totalPages, scrollToTable) {
    const changePage = page => {
      currentPage = page;
      render();
      if (scrollToTable) rowsContainer.closest('table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    const group = document.createElement('div');
    group.className = 'flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm';
    group.append(makeButton('‹', currentPage === 1, () => changePage(currentPage - 1), false, 'Previous page'));
    const pageNumbers = [];
    for (let page = 1; page <= totalPages; page++) if (page === 1 || page === totalPages || Math.abs(page - currentPage) <= 1) pageNumbers.push(page);
    let previous = 0;
    pageNumbers.forEach(page => {
      if (page - previous > 1) {
        const ellipsis = document.createElement('span');
        ellipsis.className = 'grid h-9 min-w-6 place-items-center text-sm font-bold text-slate-400';
        ellipsis.textContent = '…';
        group.append(ellipsis);
      }
      group.append(makeButton(String(page), false, () => changePage(page), page === currentPage));
      previous = page;
    });
    group.append(makeButton('›', currentPage === totalPages, () => changePage(currentPage + 1), false, 'Next page'));
    return group;
  }

  function render() {
    const matchingRows = rows.filter(matches);
    const totalPages = Math.max(1, Math.ceil(matchingRows.length / perPage));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * perPage;
    const visibleRows = new Set(matchingRows.slice(first, first + perPage));
    rows.forEach(row => { row.hidden = !visibleRows.has(row); });
    pagers.forEach((pager, index) => {
      pager.replaceChildren();
      pager.className = 'my-5 flex justify-center';
      pager.hidden = !matchingRows.length;
      if (matchingRows.length) pager.append(controls(totalPages, index > 0));
    });
  }

  const resetPage = () => { currentPage = 1; render(); };
  search.oninput = resetPage;
  if (grade) grade.onchange = () => { updateSectionOptions(); resetPage(); };
  if (section) section.onchange = resetPage;
  if (status) status.onchange = resetPage;
  updateSectionOptions();
  render();
})();
