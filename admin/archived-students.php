<?php
require "../config/db_conn.php";
require "../includes/student-profile-schema.php";
require "../includes/section-catalog-schema.php";
require "../includes/csrf-token.php";
ensureStudentProfileColumns($conn);
ensureSectionCatalogColumns($conn);
require "page-guard.php";
require "components.php";

$stmt = $conn->prepare(
    "SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID)=TRIM(s.STUDENT_ID)) attendance_records FROM students s WHERE s.ENROLLMENT_STATUS='Archived' ORDER BY s.LAST_NAME,s.FIRST_NAME,s.GRADE,s.SECTION",
);
$stmt->execute();
$result = $stmt->get_result();
$sections = [];
$sectionMap = [];
$sectionResult = $conn->query(
    "SELECT GRADE, SECTION FROM sections WHERE IS_ACTIVE=1 AND GRADE BETWEEN 7 AND 12 UNION SELECT DISTINCT GRADE, SECTION FROM students WHERE GRADE BETWEEN 7 AND 12 AND SECTION IS NOT NULL AND SECTION<>'' ORDER BY GRADE, SECTION",
);
while ($item = $sectionResult->fetch_assoc()) {
    $sections[] = $item["SECTION"];
    $sectionMap[(string) (int) $item["GRADE"]][] = $item["SECTION"];
}

adminHead("Archived students");
adminSidebar(
    "archived_students",
    "Archived students",
    "Retained student profiles and attendance history.",
);
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <p class="text-sm font-black uppercase tracking-widest text-amber-700">Records archive</p>
        <p class="mt-1 text-slate-500"><?= $result->num_rows ?> archived student<?= $result->num_rows ===
 1
     ? ""
     : "s" ?>. These profiles are retained and cannot record new attendance.</p>
    </div><a href="student-list.php"
        class="btn btn-secondary text-center">View
        active students</a>
</div>
<div class="table-filter-bar mb-6">
    <div class="flex flex-wrap items-end gap-3">
        <label class="table-filter-control min-w-[12rem] flex-1 text-sm font-bold text-slate-700">Search<input
                id="student-search" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm"
                placeholder="Name or LRN"></label>
        <label class="table-filter-control min-w-[10rem] flex-1 text-sm font-bold text-slate-700">Grade<select
                id="grade-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm">
                <option value="">All grades</option><?php foreach (
    [7, 8, 9, 10, 11, 12]
    as $grade
): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?>
            </select></label>
        <label class="table-filter-control min-w-[10rem] flex-1 text-sm font-bold text-slate-700">Section<select
                id="section-filter" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm">
                <option value="">All sections</option><?php foreach (
    $sections
    as $section
): ?><option><?= htmlspecialchars(
    $section,
) ?></option><?php endforeach; ?>
            </select></label>
        <button id="clear-student-filters" type="button" class="table-filter-reset shrink-0">Clear filters</button>
    </div>
</div>
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Student</th>
                    <th class="px-5 py-4">LRN</th>
                    <th class="px-5 py-4">Grade</th>
                    <th class="px-5 py-4">Section</th>
                    <th class="px-5 py-4">Attendance records</th>
                    <th class="px-5 py-4">Status</th>
                    <th class="px-5 py-4"></th>
                </tr>
            </thead>
            <tbody id="student-rows" class="divide-y divide-slate-100" data-page-size="10"
                data-empty-title="No archived students."
                data-empty-detail="Student profiles that are archived will appear here."><?php while (
    $row = $result->fetch_assoc()
):
    $displayName =
        trim(
            ($row["LAST_NAME"] ?? "") .
                ", " .
                ($row["FIRST_NAME"] ?? "") .
                (!empty($row["MIDDLE_NAME"]) ? ", " . $row["MIDDLE_NAME"] : ""),
            ", ",
        ) ?:
        $row[
            "STUDENT_NAME"
        ]; ?><tr class="hover:bg-amber-50/40" data-grade="<?= (int) $row[
    "GRADE"
] ?>" data-section="<?= htmlspecialchars(
    $row["SECTION"] ?? "",
) ?>" data-status="Archived">
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3"><img src="../<?= htmlspecialchars(
    $row["IMAGE_PATH"],
) ?>" alt="" class="h-10 w-10 rounded-full object-cover"><span class="font-bold text-slate-900"><?= htmlspecialchars(
    $displayName,
) ?></span></div>
                    </td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars(
    $row["STUDENT_ID"],
) ?></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700">Grade <?= htmlspecialchars(
    $row["GRADE"] ?? "—",
) ?></span></td>
                    <td class="px-5 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 font-bold text-blue-700"><?= htmlspecialchars(
    $row["SECTION"] ?? "N/A",
) ?></span></td>
                    <td class="px-5 py-4 font-bold text-slate-700"><?= (int) $row[
    "attendance_records"
] ?></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-amber-50 px-3 py-1 font-bold text-amber-700"><?= htmlspecialchars($row["ARCHIVE_REASON"] ?: "Archived") ?></span></td>
                    <td class="px-5 py-4 text-right"><div class="flex justify-end gap-2"><a class="btn btn-secondary btn-sm" href="archived-student-record.php?id=<?= (int) $row[
    "ID"
] ?>">Open record</a><form action="restore-archived-student.php" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"><input type="hidden" name="id" value="<?= (int) $row["ID"] ?>"><button class="btn btn-secondary btn-sm">Restore</button></form></div></td>
                </tr><?php
endwhile; ?></tbody>
        </table>
    </div>
</div>
<script>
    window.studentSectionMap = <?= json_encode(
        $sectionMap,
    ) ?> ;
</script>
<script src="../js/student-pagination.js?v=9"></script>
<script>
    document.getElementById('clear-student-filters').onclick = () => {
        const search = document.getElementById('student-search'),
            grade = document.getElementById('grade-filter'),
            section = document.getElementById('section-filter');
        search.value = '';
        grade.value = '';
        grade.dispatchEvent(new Event('change'));
        section.value = '';
        section.dispatchEvent(new Event('change'));
        search.dispatchEvent(new Event('input'))
    };
</script>
<?php adminFoot(); ?>
