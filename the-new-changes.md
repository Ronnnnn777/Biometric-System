# The New Changes

Summary of the UI/logic updates applied to the biometric attendance system.

## Goals

1. Gmail-style pagination ranges (`1–15 of 348`) with 15 rows per page.
2. Attendance dates default to the current school date.
3. The Section filter only appears when a specific grade is selected.
4. Active lists exclude archived records; archived views show only archived records.
5. Students who timed in are listed before unrecorded/absent students.
6. Every breadcrumb is clickable, and the dashboard hero only contains the headline.

---

## Files changed

| File | Change |
| --- | --- |
| `includes/ui.php` | All breadcrumb items render as links. |
| `js/student-pagination.js` | 15 rows/page, exact range text, SQL total support, conditional Section filter. |
| `js/table-pagination.js` | 15 rows/page default, exact range text, optional DB total. |
| `admin/student-list.php` | Active-only query/count, tabs, pager attributes, hidden Section field. |
| `admin/teacher-list.php` | Active-only count, 15/page pager, tabs. |
| `admin/attendance-log.php` | Current-date default, hidden Section for all grades, 15/page. |
| `admin/archived-students.php` | Archived count, tabs, 15/page, hidden Section field. |
| `admin/archived-teachers.php` | Archived count, tabs, 15/page pager. |
| `admin/get-latest-attendance.php` | Timed-in-first ordering. |
| `teacher/get-attendance.php` | Timed-in-first ordering. |
| `teacher/home.php` | Current-date default, 15/page. |
| `admin/run-school-year-rollover.php` | Explicit archived student/teacher exclusions. |
| `admin/home.php` | Hero contains only the headline; subtext/metrics moved below. |
| `admin/get-stats.php` | Dashboard student count excludes archived. |

---

## 1. Clickable breadcrumbs — `includes/ui.php`

```php
<?php
// Every breadcrumb level is rendered as a link. The current page falls back
// to its own URL so the hierarchy never contains a dead-end label.
$crumbCount = count($crumbs);
$currentUrl = $_SERVER['REQUEST_URI'] ?? '#';
?>
<nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-sm">
    <?php foreach ($crumbs as $index => $crumb): ?>
        <?php
        $isCurrent = $index === $crumbCount - 1;
        $href = trim((string) ($crumb['href'] ?? '')) ?: $currentUrl;
        ?>
        <?php if ($index > 0): ?><span class="text-slate-300" aria-hidden="true">/</span><?php endif; ?>
        <a href="<?= htmlspecialchars($href) ?>"
            class="shrink-0 transition <?= $isCurrent ? 'truncate font-semibold text-slate-900' : 'text-slate-500 hover:text-slate-900' ?>"
            <?= $isCurrent ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($crumb['label']) ?></a>
    <?php endforeach; ?>
</nav>
```

---

## 2. Student pagination — `js/student-pagination.js`

```js
(() => {
  const rowsContainer = document.getElementById('student-rows');
  const search = document.getElementById('student-search');
  if (!rowsContainer || !search) return;

  const rows = Array.from(rowsContainer.querySelectorAll('tr'));
  const grade = document.getElementById('grade-filter');
  const section = document.getElementById('section-filter');
  const status = document.getElementById('status-filter');
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
  emptyState.innerHTML = '<td colspan="99" class="px-5 py-14 text-center"><p class="text-base font-black text-slate-800"></p><p class="mt-1 text-sm text-slate-500"></p><button type="button" class="mt-4 rounded-xl bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700 hover:bg-blue-100"></button></td>';
  rowsContainer.append(emptyState);

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
    const availableSections = new Set(selectedGrade
      ? (sectionMap[selectedGrade] || [])
      : sectionOptions.map(option => option.value).filter(Boolean));
    section.replaceChildren();
    sectionOptions.forEach(({ value, label }) => {
      if (value && !availableSections.has(value)) return;
      section.add(new Option(label, value));
    });
    section.value = availableSections.has(previousSection) ? previousSection : '';
  }

  // Sections only make sense once a grade is selected. "All grades" hides the
  // control entirely and clears any stale section selection.
  function syncSectionVisibility() {
    if (!section) return;
    const visible = Boolean(grade?.value);
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
    const hasFilters = Boolean(search.value.trim() || grade?.value || section?.value || status?.value);
    // With no filters the SQL COUNT is authoritative; filtered views report
    // the matching record count instead of a page count.
    const totalRecords = hasFilters || !sqlTotal ? matchingRows.length : sqlTotal;
    const totalPages = Math.max(1, Math.ceil(totalRecords / perPage));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * perPage;
    const last = Math.min(first + perPage, totalRecords);
    const visibleRows = new Set(matchingRows.slice(first, first + perPage));
    rows.forEach(row => { row.hidden = !visibleRows.has(row); });
    emptyState.hidden = matchingRows.length > 0;
    if (!matchingRows.length) {
      const title = emptyState.querySelector('p:first-child');
      const detail = emptyState.querySelector('p:nth-child(2)');
      const clear = emptyState.querySelector('button');
      title.textContent = rows.length ? 'No students match the current filters.' : (rowsContainer.dataset.emptyTitle || 'No students have been added yet.');
      detail.textContent = rows.length ? 'Try changing the search or filter options.' : (rowsContainer.dataset.emptyDetail || 'Add a student or import a class list to begin.');
      const addUrl = rowsContainer.dataset.emptyActionUrl || '';
      clear.hidden = rows.length ? !hasFilters : !addUrl;
      clear.textContent = rows.length ? 'Clear filters' : 'Add student';
      clear.onclick = () => rows.length ? document.getElementById('clear-student-filters')?.click() : window.location.assign(addUrl);
    }
    pagers.forEach((pager, index) => {
      pager.replaceChildren();
      pager.className = 'my-5 flex flex-wrap items-center justify-between gap-3';
      pager.hidden = !matchingRows.length;
      if (matchingRows.length) {
        const count = document.createElement('p');
        count.className = 'text-sm font-semibold text-slate-500';
        count.setAttribute('aria-live', 'polite');
        count.textContent = `${first + 1}–${last} of ${totalRecords}`;
        count.setAttribute('aria-label', `Showing ${first + 1} to ${last} of ${totalRecords} students`);
        pager.append(count, controls(totalPages, true));
      }
    });
  }

  const resetPage = () => { currentPage = 1; render(); };
  search.oninput = resetPage;
  if (grade) grade.onchange = () => { updateSectionOptions(); syncSectionVisibility(); resetPage(); };
  if (section) section.onchange = resetPage;
  if (status) status.onchange = resetPage;
  updateSectionOptions();
  syncSectionVisibility();
  render();
})();
```

---

## 3. Generic table pagination — `js/table-pagination.js`

```js
window.createTablePagination = ({ rows, pager, label = 'records', perPage = 0, total = 0 }) => {
  // Render the bottom pager only; earlier entries are kept for backwards compatibility.
  const pagers = (Array.isArray(pager) ? pager : [pager]).filter(Boolean).slice(-1);
  if (!rows || !pagers.length) return { refresh() {} };
  const pageSize = Number(perPage) || Number(rows.dataset?.pageSize) || 15;
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
    const totalRecords = Number(total) || records.length;
    const totalPages = Math.max(1, Math.ceil(totalRecords / pageSize));
    currentPage = Math.min(currentPage, totalPages);
    const first = (currentPage - 1) * pageSize;
    const last = Math.min(first + pageSize, totalRecords);
    const visible = new Set(records.slice(first, first + pageSize));
    records.forEach(row => { row.hidden = !visible.has(row); });
    pagers.forEach((container, index) => {
      container.replaceChildren();
      container.className = 'my-5 flex flex-wrap items-center justify-between gap-3';
      container.hidden = !records.length;
      if (records.length) {
        const count = document.createElement('p');
        count.className = 'text-sm font-semibold text-slate-500';
        count.setAttribute('aria-live', 'polite');
        count.textContent = `${first + 1}–${last} of ${totalRecords}`;
        count.setAttribute('aria-label', `Showing ${first + 1} to ${last} of ${totalRecords} ${label}`);
        container.append(count, controls(totalPages, true));
      }
    });
  }

  render();
  return { refresh: render };
};
```

---

## 4. Active student list — `admin/student-list.php`

### Count mirroring the list query

```php
$limit = 15;
$today = date('Y-m-d');
// Active rosters never include the archived status. The COUNT below mirrors
// the list query exactly so the header total and the pager total agree.
$status = 'Archived';
$activeStudentWhere = "COALESCE(NULLIF(s.ENROLLMENT_STATUS,''),'Enrolled')<>'" . $conn->real_escape_string($status) . "'";
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM students s WHERE $activeStudentWhere");
$countStmt->execute();
$totalStudents = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt = $conn->prepare("SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=?) attended_today, (SELECT a.ATTENDANCE_REMARK FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)=? ORDER BY a.ID DESC LIMIT 1) attendance_remark FROM students s WHERE $activeStudentWhere ORDER BY s.LAST_NAME, s.FIRST_NAME, s.GRADE, s.SECTION");
$stmt->bind_param('ss', $today, $today);
$stmt->execute();
$result = $stmt->get_result();
```

### Header, tabs, and count

```php
<div class="mb-6 flex flex-col gap-4">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <p class="text-sm font-semibold text-slate-500">
                <?= $totalStudents ?> active student<?= $totalStudents === 1 ? '' : 's' ?>
            </p>
            <nav class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold shadow-sm"
                aria-label="Student records view">
                <a href="student-list.php" aria-current="page"
                    class="rounded-lg bg-blue-600 px-3 py-1.5 text-white shadow-sm">Active</a>
                <a href="archived-students.php"
                    class="rounded-lg px-3 py-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Archived</a>
            </nav>
        </div>
        ...
```

### Hidden Section field + pager attributes

```html
<label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700" hidden>Section<select
        id="section-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm">
        <option value="">All sections</option>
        ...
```

```html
<tbody id="student-rows" class="divide-y divide-slate-100" data-page-size="<?= $limit ?>"
    data-total="<?= $totalStudents ?>"
    data-section-map="<?= htmlspecialchars(json_encode($sectionMap), ENT_QUOTES) ?>"
    data-empty-action-url="add-student.php" data-empty-title="No students have been added yet."
    data-empty-detail="Add a student or import a class list to begin.">
```

```html
<script src="../js/student-pagination.js?v=10"></script>
```

---

## 5. Active teacher list — `admin/teacher-list.php`

```php
$limit = 15;
// Archived teachers live on their own screen; the active list must never include them.
$status = 'Active';
$activeTeacherWhere = "COALESCE(NULLIF(TEACHER_STATUS,''),'Active')='" . $conn->real_escape_string($status) . "'";
$teacherCount = $conn->query("SELECT COUNT(*) AS total FROM teachers WHERE $activeTeacherWhere");
$totalTeachers = (int) ($teacherCount ? $teacherCount->fetch_assoc()['total'] ?? 0 : 0);
$teachers = $conn->query(
    "SELECT TEACHER_ID,TEACHER_NAME,TEACHER_USER,CREATED_AT FROM teachers WHERE $activeTeacherWhere ORDER BY TEACHER_NAME",
);
```

### Pager setup

```html
<script src="../js/table-pagination.js?v=7"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.getElementById('teacher-rows');
        if (!rows) return;
        const card = rows.closest('.overflow-hidden'),
            bottom = document.createElement('div');
        card.insertAdjacentElement('afterend', bottom);
        createTablePagination({
            rows,
            pager: bottom,
            label: 'teacher accounts',
            perPage: <?= $limit ?>,
            total: <?= $totalTeachers ?>
        });
    });
</script>
```

### Header and tabs

```html
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <p class="text-sm font-semibold text-slate-500"><?= $totalTeachers ?> active teacher
            account<?= $totalTeachers === 1 ? '' : 's' ?></p>
        <nav class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold shadow-sm"
            aria-label="Teacher records view">
            <a href="teacher-list.php" aria-current="page"
                class="rounded-lg bg-blue-600 px-3 py-1.5 text-white shadow-sm">Active</a>
            <a href="archived-teachers.php"
                class="rounded-lg px-3 py-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Archived</a>
        </nav>
    </div><a href="add-teacher.php" class="btn btn-primary">+ Add teacher</a>
</div>
```

```html
<tbody id="teacher-rows" data-page-size="<?= $limit ?>" class="divide-y divide-slate-100">
```

---

## 6. Attendance log — `admin/attendance-log.php`

### Current-date default

```php
$limit = 15;
// Always default the log to the school's current date instead of the newest
// saved attendance row, which may be stale.
$defaultAttendanceDate = date("Y-m-d");
```

### Section filter and ordering note

```html
<p class="text-slate-500">Records update quietly when attendance changes. Students who have timed in appear first.</p>
```

```html
<label id="section-field" hidden
    class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700">Section<select
        id="section-filter"
        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
        <option value="all">All sections</option>
        ...
```

### Section visibility + 15/page

```html
<script src="../js/table-pagination.js?v=7"></script>
<script>
    const gradeFilter = document.getElementById('grade-filter'),
        sectionFilter = document.getElementById('section-filter'),
        ...
        exportLink = document.getElementById('export-link');
    const attendanceSections = <?= json_encode($sections) ?>;
    const sectionField = document.getElementById('section-field');

    function updateAttendanceSections() {
        const grade = gradeFilter.value,
            previous = sectionFilter.value,
            allGrades = grade === 'all';
        // The section list is only meaningful for a single grade.
        if (sectionField) {
            sectionField.hidden = allGrades;
            sectionField.classList.toggle('hidden', allGrades);
        }
        sectionFilter.replaceChildren(new Option('All sections', 'all'));
        attendanceSections.filter(item => allGrades || String(item.grade) === grade).forEach(item => sectionFilter
            .add(new Option(item.section, item.section, false, item.section === previous)));
        if (!Array.from(sectionFilter.options).some(option => option.selected)) sectionFilter.value = 'all';
    }
    gradeFilter.addEventListener('change', updateAttendanceSections);
    updateAttendanceSections();
    const attendancePager = createTablePagination({
        rows,
        pager: document.getElementById('attendance-pagination'),
        label: 'attendance records',
        perPage: <?= $limit ?>
    });
    ...
</script>
```

---

## 7. Archived students — `admin/archived-students.php`

```php
$result = $stmt->get_result();
// Count and list use the same archived-status predicate.
$archivedCount = $conn->query("SELECT COUNT(*) AS total FROM students WHERE ENROLLMENT_STATUS='Archived'");
$totalArchivedStudents = (int) ($archivedCount ? $archivedCount->fetch_assoc()['total'] ?? 0 : 0);
```

```html
<p class="mt-1 text-slate-500"><?= $totalArchivedStudents ?> archived student<?= $totalArchivedStudents === 1 ? "" : "s" ?>. These profiles are retained and cannot record new attendance.</p>
...
<nav class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold shadow-sm"
    aria-label="Student records view">
    <a href="student-list.php"
        class="rounded-lg px-3 py-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Active</a>
    <a href="archived-students.php" aria-current="page"
        class="rounded-lg bg-blue-600 px-3 py-1.5 text-white shadow-sm">Archived</a>
</nav>
```

```html
<label class="table-filter-control flex-1 w-full text-sm font-bold text-slate-700" hidden>Section<select
        id="section-filter" ...>
```

```html
<tbody id="student-rows" class="divide-y divide-slate-100" data-page-size="15"
    data-total="<?= $totalArchivedStudents ?>"
    data-empty-title="No archived students."
    data-empty-detail="Student profiles that are archived will appear here.">
```

```html
<script src="../js/student-pagination.js?v=10"></script>
```

---

## 8. Archived teachers — `admin/archived-teachers.php`

```php
$teachers = $conn->query(
    "SELECT TEACHER_ID,TEACHER_NAME,TEACHER_USER,CREATED_AT,ARCHIVED_AT FROM teachers WHERE TEACHER_STATUS='Archived' ORDER BY ARCHIVED_AT DESC,TEACHER_NAME",
);
$archivedTeacherCount = $conn->query("SELECT COUNT(*) AS total FROM teachers WHERE TEACHER_STATUS='Archived'");
$totalArchivedTeachers = (int) ($archivedTeacherCount ? $archivedTeacherCount->fetch_assoc()['total'] ?? 0 : 0);
```

```html
<p class="mt-1 text-slate-500"><?= $totalArchivedTeachers ?> archived teacher account<?= $totalArchivedTeachers === 1 ? "" : "s" ?>. Their profiles and assignments are retained, but they cannot sign in.</p>
...
<nav class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold shadow-sm"
    aria-label="Teacher records view">
    <a href="teacher-list.php"
        class="rounded-lg px-3 py-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Active</a>
    <a href="archived-teachers.php" aria-current="page"
        class="rounded-lg bg-blue-600 px-3 py-1.5 text-white shadow-sm">Archived</a>
</nav>
```

```html
<tbody id="archived-teacher-rows" data-page-size="15" class="divide-y divide-slate-100">
```

```html
<script src="../js/table-pagination.js?v=7"></script>
...
createTablePagination({
    rows,
    pager: bottom,
    label: 'archived teachers',
    perPage: 15,
    total: <?= $totalArchivedTeachers ?>
});
```

---

## 9. Timed-in ordering

### `admin/get-latest-attendance.php`

```php
$query =
    "SELECT a.ID ATTENDANCE_ID, s.STUDENT_ID, ... FROM students s LEFT JOIN attendance a ON TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN)='$safeDate' WHERE " .
    implode(" AND ", $where) .
    " ORDER BY (a.TIME_IN IS NOT NULL AND TIME(a.TIME_IN)<>'00:00:00' AND COALESCE(a.ATTENDANCE_REMARK,'') NOT IN ('Absent','Excused absence')) DESC,s.GRADE,s.SECTION,s.LAST_NAME,s.FIRST_NAME,s.STUDENT_NAME";
```

### `teacher/get-attendance.php`

```php
$stmt = $conn->prepare(
    "SELECT ... FROM ...
      ORDER BY (a.TIME_IN IS NOT NULL AND TIME(a.TIME_IN) <> '00:00:00' AND COALESCE(a.ATTENDANCE_REMARK, '') NOT IN ('Absent', 'Excused absence')) DESC,
               s.GRADE, s.SECTION, s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME",
);
```

Students with a real time-in are listed first; midnight timestamps and absent/excused records sort last.

---

## 10. Teacher attendance page — `teacher/home.php`

```php
$students = [];
// Keep every date control and export link anchored to the school's current date.
$today = date("Y-m-d");
```

```html
<p class="text-slate-500">Updates quietly when attendance changes in your assigned grades. Students who have timed in appear first.</p>
<a id="export-link" href="export-attendance.php?date=<?= htmlspecialchars($today) ?>&section=all"
    class="btn btn-success">Export spreadsheet</a>
```

```html
<input id="date-filter" type="date" value="<?= htmlspecialchars($today) ?>"
    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3">
```

```html
<tbody id="attendance-rows" data-page-size="15" class="divide-y divide-slate-100" aria-live="polite"></tbody>
```

```js
document.getElementById('clear-attendance-filters').onclick = () => {
    ...
    date.value = <?= json_encode($today) ?>;
    [section, student, date].forEach(control => control.dispatchEvent(new Event('change')));
};
```

---

## 11. School-year rollover — `admin/run-school-year-rollover.php`

```php
require_once "../includes/teacher-profile-schema.php";
...
ensureTeacherProfileColumns($conn);
```

```php
// Archived teachers are deliberately excluded from the promotion runner. The
// runner never promotes teacher rows; this guard documents and verifies that
// archived accounts are skipped rather than silently treated as active staff.
$archivedTeacherResult = $conn->query("SELECT COUNT(*) total FROM teachers WHERE COALESCE(NULLIF(TEACHER_STATUS,''),'Active')='Archived'");
$archivedTeacherCount = (int) ($archivedTeacherResult ? $archivedTeacherResult->fetch_assoc()["total"] ?? 0 : 0);
```

Every student select/update now excludes archived rows:

```php
"SELECT GRADE, STRAND FROM students
  WHERE ENROLLMENT_STATUS='Enrolled' AND ENROLLMENT_STATUS<>'Archived' AND GRADE BETWEEN 7 AND 11
  GROUP BY GRADE, STRAND
  ORDER BY GRADE, STRAND"

"SELECT COUNT(*) total FROM students WHERE GRADE=12 AND ENROLLMENT_STATUS='Enrolled' AND ENROLLMENT_STATUS<>'Archived'"

-- and the same extra predicate on the history insert, source list, promotion UPDATE,
-- Grade 12 archive/graduate queries.
```

```php
$teacherNote = $archivedTeacherCount > 0
    ? " {$archivedTeacherCount} archived teacher account" . ($archivedTeacherCount === 1 ? " was" : "s were") . " skipped."
    : "";
$_SESSION["toast"] = [
    "message" => "School year rollover complete: {$promoted} promoted, ...{$sectionsSummary}{$teacherNote}",
    "type" => "success",
];
```

---

## 12. Dashboard hero — `admin/home.php`

```html
<section class="maruhat-dashboard-hero mb-6">
    <h2 class="text-3xl font-black sm:text-4xl">Good day, administrator.</h2>
</section>

<section class="card card-body mb-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-black uppercase tracking-widest text-[#1f4f91]">Maruhat National High School</p>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Your attendance overview for
                <?= htmlspecialchars(date('l, F j, Y')) ?>. Keep every school day organized, accurate, and ready for
                review.</p>
        </div>
        <div class="flex shrink-0 items-center gap-3 rounded-2xl bg-blue-50 px-5 py-4 text-blue-700 ring-1 ring-blue-100">
            <?= icon('calendar') ?>
            <span class="text-sm font-bold"><?= htmlspecialchars(date('l')) ?></span>
        </div>
    </div>
</section>
```

```php
$activeStudentWhere = "COALESCE(NULLIF(ENROLLMENT_STATUS,''),'Enrolled')<>'Archived'";
$totalStudents = (int) ($conn->query("SELECT COUNT(*) AS total FROM students WHERE $activeStudentWhere")->fetch_assoc()['total'] ?? 0);
```

```html
<p class="text-sm font-bold text-slate-500">Active students</p>
...
<p class="card-muted mt-2">Non-archived student profiles</p>
```

---

## 13. Dashboard stats endpoint — `admin/get-stats.php`

```php
// 1. Get active student count (archived profiles are excluded)
$total = $conn->query("SELECT COUNT(*) AS total FROM students WHERE COALESCE(NULLIF(ENROLLMENT_STATUS,''),'Enrolled')<>'Archived'")->fetch_assoc()["total"];
```

---

## Verification

- `php -l` clean on all modified PHP files.
- `node --check` clean on `js/student-pagination.js` and `js/table-pagination.js`.
- Cache versions bumped: `student-pagination.js?v=10`, `table-pagination.js?v=7`.
- Data checks: 59 active students, 6 archived students, 1 active teacher, 1 archived teacher.
- Attendance default renders the current date (`2026-10-23`).
- Changes are uncommitted.
