<?php
session_start();
require "../config/db_conn.php";
require "../includes/student-profile-schema.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
require "../includes/school-year-schema.php";
require "../includes/student-promotion-schema.php";
ensureStudentProfileColumns($conn);
ensureSectionCatalogColumns($conn);
ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
seedDefaultTeacherSections($conn);
require "page-guard.php";
require "../includes/csrf-token.php";
require "components.php";
require_once "../includes/student-face-references.php";
ensureStudentFaceReferences($conn);
$id = (int) ($_GET["id"] ?? 0);
$stmt = $conn->prepare("SELECT * FROM students WHERE ID=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    http_response_code(404);
    exit("Student not found.");
}
$placement = studentSectionPlacementOptions($conn);

/* Academic records are retained for every school year, so the tab below reads
 * them independently of the student's current grade and section. */
$academicRows = [];
$academicQuery = $conn->prepare(
    "SELECT SCHOOL_YEAR,GRADE,STRAND,SECTION,SUBJECT,Q1_GRADE,Q2_GRADE,Q3_GRADE,Q4_GRADE,FINAL_GRADE,RESULT
       FROM student_subject_results
      WHERE TRIM(STUDENT_ID)=TRIM(?)
      ORDER BY SCHOOL_YEAR DESC,GRADE,SUBJECT",
);
$academicQuery->bind_param("s", $row["STUDENT_ID"]);
$academicQuery->execute();
$academicResult = $academicQuery->get_result();
while ($academicResult && ($academicRow = $academicResult->fetch_assoc())) {
    $academicRows[] = $academicRow;
}

$yearSetting = $conn->query("SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1")->fetch_assoc();
$activeSchoolYear = (string) ($yearSetting["ACTIVE_SCHOOL_YEAR"] ?? "");
$academicYears = [];
if ($activeSchoolYear !== "") {
    $academicYears[] = $activeSchoolYear;
}
foreach ($academicRows as $academicRow) {
    $year = (string) $academicRow["SCHOOL_YEAR"];
    if ($year !== "" && !in_array($year, $academicYears, true)) {
        $academicYears[] = $year;
    }
}
if (!$academicYears) {
    $academicYears[] = date("Y") . "-" . ((int) date("Y") + 1);
}
usort($academicYears, fn($a, $b) => strcmp($b, $a));

$academicYear = (string) ($_GET["year"] ?? $academicYears[0]);
if (!in_array($academicYear, $academicYears, true)) {
    $academicYear = $academicYears[0];
}
$currentGrade = (int) ($row["GRADE"] ?? 0);
$academicGrade = (int) ($_GET["academic_grade"] ?? $currentGrade);
if ($academicGrade < 7 || $academicGrade > 12) {
    $academicGrade = $currentGrade >= 7 && $currentGrade <= 12 ? $currentGrade : 7;
}
$activeTab = ($_GET["tab"] ?? "") === "academic" ? "academic" : "profile";
$academicGradeItems = [];
foreach ([7, 8, 9, 10, 11, 12] as $academicGradeOption) {
    $academicGradeItems[] = ["value" => (string) $academicGradeOption, "label" => "Grade " . $academicGradeOption];
}
$academicYearItems = [];
foreach ($academicYears as $academicYearOption) {
    $academicYearItems[] = ["value" => $academicYearOption, "label" => $academicYearOption];
}
$displayAcademicGrade = function ($value): string {
    if ($value === null || $value === "") {
        return "—";
    }
    return rtrim(rtrim(number_format((float) $value, 2, ".", ""), "0"), ".");
};
$matchingAcademicRows = array_values(array_filter(
    $academicRows,
    fn($academicRow) => (int) $academicRow["GRADE"] === $academicGrade
        && (string) $academicRow["SCHOOL_YEAR"] === $academicYear,
));

adminHead("Edit student");
adminSidebar(
    "students",
    "Edit student",
    "Update the student profile or registered image",
);
?>
<a href="student-list.php"
    class="mb-4 inline-flex items-center gap-1.5 text-sm font-bold text-slate-600 transition hover:text-blue-700">
    <?= icon('arrow-left', 'h-4 w-4') ?> Back to Students
</a>

<div class="mb-5 flex w-full flex-wrap items-center justify-between gap-3">
    <nav class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 text-sm font-bold shadow-sm"
        role="tablist" aria-label="Student record view">
        <button type="button" role="tab" data-student-tab="profile" aria-controls="student-profile-panel"
            aria-selected="<?= $activeTab === 'profile' ? 'true' : 'false' ?>"
            class="rounded-lg px-4 py-2 transition <?= $activeTab === 'profile' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">Profile</button>
        <button type="button" role="tab" data-student-tab="academic" aria-controls="student-academic-panel"
            aria-selected="<?= $activeTab === 'academic' ? 'true' : 'false' ?>"
            class="rounded-lg px-4 py-2 transition <?= $activeTab === 'academic' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">Academic
            Records</button>
    </nav>
    <p class="text-sm font-semibold text-slate-500">Grade <?= $currentGrade ?: "—" ?> &middot; SY
        <?= htmlspecialchars($academicYear) ?></p>
</div>

<div id="student-profile-panel" data-student-panel="profile" role="tabpanel"
    class="<?= $activeTab === 'profile' ? '' : 'hidden' ?>">
    <div class="w-full">
    <section class="card card-body">
        <h2 class="text-2xl font-black text-slate-900">Student details</h2>
        <form action="update-student.php" method="post" enctype="multipart/form-data" class="mt-7 space-y-5">
            <input type="hidden" name="id" value="<?= $row[
          "ID"
      ] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm font-bold text-slate-700">LRN<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="student_id" value="<?= htmlspecialchars(
            $row["STUDENT_ID"],
        ) ?>" required></label>
                <label class="block text-sm font-bold text-slate-700">Grade<select id="student-grade"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" name="grade" required>
                        <option value="">Choose a grade</option><?php foreach (
            [7, 8, 9, 10, 11, 12]
            as $grade
        ): ?><option value="<?= $grade ?>" <?= (int) $row["GRADE"] === $grade
    ? "selected"
    : "" ?>>Grade <?= $grade ?></option><?php endforeach; ?>
                    </select></label>
            </div>
            <label class="block text-sm font-bold text-slate-700">Enrollment status<select
                    class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" name="enrollment_status"
                    required><?php foreach (
          studentEnrollmentStatuses()
          as $status
      ): ?><option value="<?= htmlspecialchars($status) ?>" <?= ($row[
    "ENROLLMENT_STATUS"
] ??
    "Enrolled") ===
$status
    ? "selected"
    : "" ?>><?= htmlspecialchars(
    $status,
) ?></option><?php endforeach; ?></select><span class="mt-2 block text-xs font-normal text-slate-500">Only enrolled
                    students can use attendance scanning. Archived and inactive profiles remain in the system with their
                    records.</span></label>
            <div class="grid gap-5 sm:grid-cols-2">
                <label id="strand-field" class="text-sm font-bold text-slate-700">Strand<select id="student-strand"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"
                        name="strand"></select></label>
                <label id="section-field" class="block text-sm font-bold text-slate-700">Section<select
                        id="student-section" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"
                        name="section" required></select></label>
            </div>
            <div class="grid gap-5 sm:grid-cols-3"><label class="block text-sm font-bold text-slate-700">First
                    name<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="first_name" value="<?= htmlspecialchars(
          $row["FIRST_NAME"],
      ) ?>" required></label><label class="block text-sm font-bold text-slate-700">Middle name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="middle_name" value="<?= htmlspecialchars(
    $row["MIDDLE_NAME"] ?? "",
) ?>"></label><label class="block text-sm font-bold text-slate-700">Last name<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="last_name" value="<?= htmlspecialchars(
    $row["LAST_NAME"],
) ?>" required></label></div>
            <div class="grid gap-5 sm:grid-cols-[140px_minmax(0,1fr)]"><label
                    class="block text-sm font-bold text-slate-700">Age<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="number" name="age"
                        min="3" max="120" inputmode="numeric" value="<?= htmlspecialchars(
          $row["AGE"] ?? "",
      ) ?>" required></label><label class="block text-sm font-bold text-slate-700">Home address<input
                        class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="address"
                        autocomplete="street-address" maxlength="255" value="<?= htmlspecialchars(
    $row["ADDRESS"] ?? "",
) ?>" required></label></div>
            <?php require "../includes/student-face-fields.php"; ?>
            <button class="btn btn-primary">Save
                changes</button>
        </form>
        <form id="archive-form" action="archive-student.php" method="post" class="hidden"><input name="id" value="<?= $row[
        "ID"
    ] ?>"><input name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"></form>
        <section class="mt-5 rounded-2xl bg-blue-950 p-6">
            <h3 class="font-bold text-white">Archive profile</h3>
            <p class="mt-2 text-sm text-blue-200">Keep this student and all attendance records, but prevent future
                attendance scans.</p><button type="button" id="archive-button"
                class="mt-5 w-full rounded-xl border border-red-500/40 px-4 py-3 font-bold text-red-400 transition-colors hover:bg-red-600 hover:text-white">Archive
                student</button>
        </section>
    </section>
    </div>
</div>

<div id="student-academic-panel" data-student-panel="academic" role="tabpanel"
    class="w-full <?= $activeTab === 'academic' ? '' : 'hidden' ?>">
    <section class="card w-full flex-1 p-5">
        <div class="flex w-full flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-2xl font-black text-slate-900">Academic records</h2>
                <p class="mt-1 text-sm text-slate-500">Read-only subject grades for
                    <?= htmlspecialchars($row["STUDENT_NAME"]) ?>. Filter by grade level and school year.
                </p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold text-slate-600"
                data-academic-count><?= count($matchingAcademicRows) ?>
                subject<?= count($matchingAcademicRows) === 1 ? "" : "s" ?></span>
        </div>

        <div class="mt-5 grid w-full gap-4 xl:grid-cols-2">
            <div class="flex flex-col gap-2 rounded-xl border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Grade level</p>
                <?= ui_capsule_group("academic_grade", $academicGradeItems, (string) $academicGrade, [
                    "aria_label" => "Academic grade level",
                ]) ?>
            </div>
            <div class="flex flex-col gap-2 rounded-xl border border-slate-200 p-4">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-500">School year</p>
                <?= ui_capsule_group("academic_year", $academicYearItems, $academicYear, [
                    "aria_label" => "School year",
                ]) ?>
            </div>
        </div>

        <div class="mt-5 w-full overflow-x-auto" data-academic-table-wrap
            <?= $matchingAcademicRows ? "" : "hidden" ?>>
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-5 py-4">Subject</th>
                        <th class="px-5 py-4">Section</th>
                        <th class="px-3 py-4 text-center">Q1</th>
                        <th class="px-3 py-4 text-center">Q2</th>
                        <th class="px-3 py-4 text-center">Q3</th>
                        <th class="px-3 py-4 text-center">Q4</th>
                        <th class="px-3 py-4 text-center">Final</th>
                        <th class="px-3 py-4 text-center">Remark</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($academicRows as $academicRow):
                        $rowVisible = (int) $academicRow["GRADE"] === $academicGrade
                            && (string) $academicRow["SCHOOL_YEAR"] === $academicYear;
                        $remark = trim((string) ($academicRow["RESULT"] ?? ""));
                        if ($remark === "" && $academicRow["FINAL_GRADE"] !== null) {
                            $remark = studentResultRemark((float) $academicRow["FINAL_GRADE"]);
                        }
                        $sectionLabel = (string) ($academicRow["SECTION"] ?? "");
                        if ((string) ($academicRow["STRAND"] ?? "") !== "") {
                            $sectionLabel .= " · " . $academicRow["STRAND"];
                        }
                    ?>
                    <tr data-academic-row data-grade="<?= (int) $academicRow["GRADE"] ?>"
                        data-year="<?= htmlspecialchars((string) $academicRow["SCHOOL_YEAR"]) ?>"
                        class="<?= $rowVisible ? "hover:bg-slate-50" : "hidden" ?>">
                        <td class="px-5 py-4 font-bold text-slate-900"><?= htmlspecialchars(
    (string) $academicRow["SUBJECT"],
) ?></td>
                        <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars($sectionLabel) ?></td>
                        <?php foreach (["Q1_GRADE", "Q2_GRADE", "Q3_GRADE", "Q4_GRADE"] as $quarterColumn): ?>
                        <td class="px-3 py-4 text-center text-slate-700">
                            <?= htmlspecialchars($displayAcademicGrade($academicRow[$quarterColumn] ?? null)) ?></td>
                        <?php endforeach; ?>
                        <td class="px-3 py-4 text-center text-base font-black text-slate-900">
                            <?= htmlspecialchars($displayAcademicGrade($academicRow["FINAL_GRADE"] ?? null)) ?></td>
                        <td class="px-3 py-4 text-center">
                            <span
                                class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= $remark === "Passed"
    ? "bg-emerald-50 text-emerald-700"
    : ($remark === "Failed"
        ? "bg-rose-50 text-rose-700"
        : "bg-slate-100 text-slate-500") ?>"><?= $remark !== "" ? htmlspecialchars($remark) : "Not graded" ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div data-academic-empty
            class="flex w-full flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 px-6 py-14 text-center <?= $matchingAcademicRows ? "hidden" : "" ?>">
            <p class="text-lg font-black text-slate-800">No academic records for this selection.</p>
            <p class="mt-1 max-w-md text-sm text-slate-500">Choose another grade level or school year to review saved
                subject grades.</p>
        </div>
    </section>
</div>

<script src="../js/capsule-control.js?v=3"></script>
<script>
    const placementOptions = <?php echo json_encode($placement, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        savedSection = <?php echo json_encode($row["SECTION"], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        savedStrand = <?php echo json_encode($row["STRAND"] ?? "", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const gradeSelect = document.getElementById('student-grade'),
        sectionSelect = document.getElementById('student-section'),
        strandSelect = document.getElementById('student-strand'),
        sectionField = document.getElementById('section-field'),
        strandField = document.getElementById('strand-field');

    function setOptions(select, placeholder, items, value = '') {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        items.forEach(item => select.add(new Option(item, item, item === value, item === value)))
    }

    function updatePlacement(restore = false) {
        const grade = gradeSelect.value,
            senior = grade === '11' || grade === '12';
        strandField.classList.toggle('hidden', !senior);
        strandSelect.disabled = !senior;
        strandSelect.required = senior;
        sectionField.classList.toggle('sm:col-span-2', !senior);
        if (!senior) {
            strandSelect.value = '';
            setOptions(sectionSelect, grade ? 'Choose a section' : 'Choose a grade first', placementOptions[grade] ||
            [], restore ? savedSection : '');
            sectionSelect.disabled = !grade;
            return
        }
        setOptions(strandSelect, 'Choose a strand', ['Techpro', 'Academic'], restore ? savedStrand : '');
        updateSeniorSections(restore)
    }

    function updateSeniorSections(restore = false) {
        const sections = placementOptions[gradeSelect.value]?.[strandSelect.value] || [];
        setOptions(sectionSelect, strandSelect.value ? 'Choose a section' : 'Choose a strand first', sections, restore ?
            savedSection : '');
        sectionSelect.disabled = !strandSelect.value
    }
    gradeSelect.onchange = () => updatePlacement();
    strandSelect.onchange = () => updateSeniorSections();
    updatePlacement(true);
</script>
<script>
    (() => {
        const tabs = Array.from(document.querySelectorAll('[data-student-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-student-panel]'));
        const activeTabClass = 'rounded-lg bg-blue-600 px-4 py-2 text-white shadow-sm transition';
        const inactiveTabClass = 'rounded-lg px-4 py-2 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900';

        function setTab(tab, updateUrl = false) {
            tabs.forEach(button => {
                const active = button.dataset.studentTab === tab;
                button.className = active ? activeTabClass : inactiveTabClass;
                button.setAttribute('aria-selected', String(active));
            });
            panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.studentPanel !== tab));
            if (updateUrl) {
                const url = new URL(window.location.href);
                if (tab === 'academic') url.searchParams.set('tab', 'academic');
                else url.searchParams.delete('tab');
                window.history.replaceState({}, '', url);
            }
        }

        tabs.forEach(button => button.addEventListener('click', () => setTab(button.dataset.studentTab, true)));
        setTab(<?= json_encode($activeTab) ?>);

        const academicPanel = document.querySelector('[data-student-panel="academic"]');
        if (!academicPanel) return;
        const rows = Array.from(academicPanel.querySelectorAll('[data-academic-row]'));
        const empty = academicPanel.querySelector('[data-academic-empty]');
        const tableWrap = academicPanel.querySelector('[data-academic-table-wrap]');
        const count = academicPanel.querySelector('[data-academic-count]');

        function filterAcademic() {
            const grade = window.CapsuleControl?.getValue('academic_grade');
            const year = window.CapsuleControl?.getValue('academic_year');
            let visible = 0;
            rows.forEach(row => {
                const show = row.dataset.grade === grade && row.dataset.year === year;
                row.classList.toggle('hidden', !show);
                if (show) visible++;
            });
            empty?.classList.toggle('hidden', visible > 0);
            if (tableWrap) tableWrap.hidden = visible === 0;
            if (count) count.textContent = `${visible} subject${visible === 1 ? '' : 's'}`;
        }

        document.addEventListener('capsule:change', event => {
            if (event.detail.name === 'academic_grade' || event.detail.name === 'academic_year') filterAcademic();
        });
        filterAcademic();
    })();
</script>
<?php adminFoot(); ?>
