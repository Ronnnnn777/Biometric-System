(() => {
  const rowsContainer = document.getElementById('student-rows');
  const search = document.getElementById('student-search');
  if (!rowsContainer || !search) return;

  const rows = Array.from(rowsContainer.querySelectorAll('tr'));
  const grade = document.getElementById('grade-filter');
  const section = document.getElementById('section-filter');
  const status = document.getElementById('status-filter');
  const strand = document.getElementById('strand-filter');
  const catalog = JSON.parse(rowsContainer.dataset.sectionCatalog || 'null');
  const honorSections = JSON.parse(rowsContainer.dataset.honorSections || '{}');
  const sectionChoices = new Map();
  // Capsule pills mirror their active value into the hidden selects so the
  // existing filter/pagination logic remains the single source of truth.
  const syncCapsuleSelect = (name, select) => {
    if (!select) return;
    document.querySelector(`[data-capsule-group="${name}"]`)?.addEventListener('capsule:change', event => {
      select.value = event.detail.value;
      select.dispatchEvent(new Event('change'));
    });
  };
  syncCapsuleSelect('grade_filter', grade);
  syncCapsuleSelect('status_filter', status);
  const sectionField = section?.closest('.table-filter-control') || section?.parentElement || null;
  const sectionOptions = section ? Array.from(section.options).map(option => ({ value: option.value, label: option.textContent })) : [];
  let sectionMap = window.studentSectionMap || {};
  if (!Object.keys(sectionMap).length) {
    try { sectionMap = JSON.parse(rowsContainer.dataset.sectionMap || '{}'); } catch (_) {}
  }
  const perPage = Number(rowsContainer.dataset.pageSize) || 15;
  const sqlTotal = Number(rowsContainer.dataset.total) || 0;
  let currentPage = 1;
  const tableCard = rowsContainer.closest('.overflow-hidden');
  const bottomPager = document.createElement('nav');
  bottomPager.setAttribute('aria-label', 'Student list pagination');
  tableCard?.insertAdjacentElement('afterend', bottomPager);
  const pagers = [bottomPager];
  const emptyState = document.createElement('tr');
  emptyState.hidden = true;
  emptyState.innerHTML = '<td colspan="99" class="px-5 py-14 text-center"><p class="text-base font-bold text-slate-800 dark:text-slate-200"></p><p class="mt-1 text-xs text-slate-500"></p><button type="button" class="mt-4 rounded-xl bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-300 dark:hover:bg-blue-500/20"></button></td>';
  rowsContainer.append(emptyState);

  // Cache searchable text before the "#" column is populated. Otherwise the
  // row number itself would match numeric searches (e.g. searching "16").
  const searchText = new Map(rows.map(row => [row, (row.dataset.search || row.textContent).toLowerCase()]));
  const matches = row => {
    const query = search.value.trim().toLowerCase();
    const choice = sectionChoices.get(section?.value);
    const sectionMatches = !section?.value || (catalog
      ? choice && row.dataset.grade === choice.grade && row.dataset.section === choice.name
        && (Number(choice.grade) < 11 || row.dataset.strand === choice.strand)
      : row.dataset.section === section.value);
    return (!query || (searchText.get(row) || '').includes(query))
      && (!grade || !grade.value || row.dataset.grade === grade.value)
      && sectionMatches
      && (!status || !status.value || row.dataset.status === status.value)
      && (!strand?.value || row.dataset.strand === strand.value);
  };

  function updateSectionOptions() {
    if (!section) return;
    const selectedGrade = grade?.value || '';
    const previousSection = section.value;
    sectionChoices.clear();
    // Rebuild from the complete catalog, never from student rows or the
    // currently filtered options: empty and overflow sections must survive.
    section.replaceChildren(new Option('All Sections', ''));
    if (catalog) {
      section.disabled = !selectedGrade;
      const entry = catalog[selectedGrade];
      const groups = Number(selectedGrade) >= 11 ? (entry || {}) : { '': entry || [] };
      Object.entries(groups).forEach(([sectionStrand, names]) => {
        if (strand?.value && strand.value !== sectionStrand) return;
        names.forEach(name => {
          // A tuple preserves placement identity even if two strands share a name.
          const value = JSON.stringify([selectedGrade, sectionStrand, name]);
          if (sectionChoices.has(value)) return;
          const honorLabel = honorSections[selectedGrade]?.[sectionStrand] === name ? ' (Honor Section)' : '';
          const strandLabel = sectionStrand && !strand?.value ? ` — ${sectionStrand}` : '';
          section.add(new Option(`${name}${honorLabel}${strandLabel}`, value));
          sectionChoices.set(value, { grade: selectedGrade, strand: sectionStrand, name });
        });
      });
      section.value = sectionChoices.has(previousSection) ? previousSection : '';
    } else {
      // Preserve the shared controller's behavior for teacher rosters.
      const names = selectedGrade ? (sectionMap[selectedGrade] || []) : sectionOptions.map(option => option.value).filter(Boolean);
      const availableSections = new Set(names);
      availableSections.forEach(value => section.add(new Option(value, value)));
      section.value = availableSections.has(previousSection) ? previousSection : '';
    }
  }

  // The admin roster opts into an always-visible catalog. Other callers keep
  // the grade-dependent section control and clear it when all grades is chosen.
  function syncSectionVisibility() {
    if (!section) return;
    const visible = section.dataset.alwaysVisible === 'true' || Boolean(grade?.value);
    if (sectionField) {
      sectionField.hidden = !visible;
      sectionField.classList.toggle('hidden', !visible);
    }
    if (!visible) section.value = '';
  }

  function makeButton(label, disabled, onClick, active = false, ariaLabel = '') {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = label;
    button.disabled = disabled;
    button.setAttribute('aria-label', ariaLabel || `Page ${label}`);
    if (active) button.setAttribute('aria-current', 'page');
    button.className = 'grid h-9 min-w-9 place-items-center rounded-lg px-2 text-sm font-bold transition ' + (active ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300 dark:text-slate-300 dark:hover:bg-slate-800 dark:disabled:text-slate-600');
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
    group.className = 'flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900';
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
    const hasFilters = Boolean(search.value.trim() || grade?.value || section?.value || status?.value || strand?.value);
    // Admin renders the complete roster, so count the same rows we paginate.
    // Legacy callers retain their unfiltered SQL total.
    const totalRecords = catalog || hasFilters || !sqlTotal ? matchingRows.length : sqlTotal;
    const totalPages = Math.max(1, Math.ceil(totalRecords / perPage));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * perPage;
    const last = Math.min(first + perPage, totalRecords);
    const pageRows = matchingRows.slice(first, first + perPage);
    const visibleRows = new Set(pageRows);
    rows.forEach(row => { row.hidden = !visibleRows.has(row); });
    // Number by absolute position in the filtered set, so page 2 starts at 16
    // (not 1) and filtered results stay sequential.
    pageRows.forEach((row, index) => {
      const indexCell = row.querySelector('.student-index');
      if (indexCell) indexCell.textContent = first + index + 1;
    });
    emptyState.hidden = matchingRows.length > 0;
    if (!matchingRows.length) {
      const title = emptyState.querySelector('p:first-child');
      const detail = emptyState.querySelector('p:nth-child(2)');
      const clear = emptyState.querySelector('button');
      title.textContent = (rows.length || hasFilters) ? 'No students match the current filters.' : (rowsContainer.dataset.emptyTitle || 'No students have been added yet.');
      detail.textContent = (rows.length || hasFilters) ? 'Try changing the search or filter options.' : (rowsContainer.dataset.emptyDetail || 'Add a student or import a class list to begin.');
      const addUrl = rowsContainer.dataset.emptyActionUrl || '';
      clear.hidden = (rows.length || hasFilters) ? !hasFilters : !addUrl;
      clear.textContent = (rows.length || hasFilters) ? 'Clear filters' : 'Add student';
      clear.onclick = () => (rows.length || hasFilters) ? document.getElementById('clear-student-filters')?.click() : window.location.assign(addUrl);
    }
    pagers.forEach(pager => {
      pager.replaceChildren();
      pager.className = 'my-5 flex flex-wrap items-center justify-between gap-3';
      pager.hidden = !catalog && !matchingRows.length;
      const count = document.createElement('p');
      count.className = 'text-sm font-semibold text-slate-500 dark:text-slate-400';
      count.setAttribute('aria-live', 'polite');
      const start = totalRecords ? first + 1 : 0;
      count.textContent = `${start}–${last} of ${totalRecords}`;
      count.setAttribute('aria-label', `Showing ${start} to ${last} of ${totalRecords} students`);
      pager.append(count);
      if (matchingRows.length) pager.append(controls(totalPages, true));
    });
  }

  const resetPage = () => { currentPage = 1; render(); };
  search.oninput = resetPage;
  const syncStrandVisibility = () => {
    if (!strand) return;
    const visible = Number(grade?.value) >= 11;
    document.getElementById('strand-filter-container').hidden = !visible;
    strand.disabled = !visible;
    if (!visible) strand.value = '';
  };
  if (grade) grade.onchange = () => {
    if (catalog) {
      if (strand) strand.value = '';
      if (section) section.value = '';
    }
    syncStrandVisibility();
    updateSectionOptions(); syncSectionVisibility(); resetPage();
  };
  if (strand) strand.onchange = () => {
    updateSectionOptions(); resetPage();
  };
  if (section) section.onchange = resetPage;
  if (status) status.onchange = resetPage;
  if (catalog) document.getElementById('clear-student-filters')?.addEventListener('click', () => {
    search.value = '';
    if (grade) grade.value = '';
    if (strand) strand.value = '';
    if (section) section.value = '';
    if (status) status.value = '';
    window.CapsuleControl?.setValue('grade_filter', '', { silent: true });
    syncStrandVisibility();
    updateSectionOptions();
    syncSectionVisibility();
    resetPage();
  });
  syncStrandVisibility();
  updateSectionOptions();
  syncSectionVisibility();
  render();
})();
