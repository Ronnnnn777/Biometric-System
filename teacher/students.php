<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/student-profile-schema.php";
require "../includes/attendance-schema.php";
require_once "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/csrf-token.php";
ensureStudentProfileColumns($conn);
ensureAttendanceTimeOutColumn($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
require "components.php";

$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
$assignments = teacherAssignments($conn, $teacherId);
if (!$assignments) {
    http_response_code(403);
    exit("Your teacher account does not have an assigned grade and section.");
}

$grades = [];
foreach ($assignments as $assignment) {
    $assignedGrade = (int) $assignment["GRADE"];
    if (!in_array($assignedGrade, $grades, true)) {
        $grades[] = $assignedGrade;
    }
}

$default = $assignments[0];
$grade = (int) ($_GET["grade"] ?? $default["GRADE"]);
if (!in_array($grade, $grades, true)) {
    $_SESSION['toast'] = [
        'message' => 'That grade is not assigned to your account. Showing your assigned class instead.',
        'type' => 'error',
    ];
    header('Location: students.php?' . http_build_query([
        'grade' => (int) $default['GRADE'],
        'section' => $default['SECTION'],
    ]));
    exit;
}

$sectionResult = $conn->prepare(
    "SELECT ta.GRADE, ta.SECTION, COUNT(DISTINCT s.ID) student_count, MAX(ta.STRAND) STRAND, MAX(ta.SUBJECT) SUBJECT FROM teacher_assignments ta LEFT JOIN students s ON s.GRADE = ta.GRADE AND s.SECTION = ta.SECTION AND s.ENROLLMENT_STATUS='Enrolled' WHERE ta.TEACHER_ID = ? AND ta.GRADE = ? GROUP BY ta.GRADE, ta.SECTION ORDER BY ta.SECTION",
);
$sectionResult->bind_param("ii", $teacherId, $grade);
$sectionResult->execute();
$sections = [];
$sectionRows = $sectionResult->get_result();
while ($item = $sectionRows->fetch_assoc()) {
    $sections[] = $item;
}

$section = trim($_GET["section"] ?? "");
$sectionExists = false;
foreach ($sections as $item) {
    if ($item["SECTION"] === $section) {
        $sectionExists = true;
        break;
    }
}
if (!$sectionExists) {
    $section = $sections[0]["SECTION"] ?? "";
}

$today = date("Y-m-d");
$stmt = $conn->prepare(
    "SELECT s.*, (SELECT COUNT(*) FROM attendance a WHERE TRIM(a.STUDENT_ID) = TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN) = ?) attended_today, (SELECT a.ATTENDANCE_REMARK FROM attendance a WHERE TRIM(a.STUDENT_ID) = TRIM(s.STUDENT_ID) AND DATE(a.TIME_IN) = ? ORDER BY a.ID DESC LIMIT 1) attendance_remark FROM students s WHERE s.SECTION = ? AND s.GRADE = ? AND s.ENROLLMENT_STATUS='Enrolled' ORDER BY s.LAST_NAME, s.FIRST_NAME, s.STUDENT_NAME",
);
$stmt->bind_param("sssi", $today, $today, $section, $grade);
$stmt->execute();
$students = $stmt->get_result();
teacherHead("Students");
teacherShell("Students");
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h2 class="text-2xl font-black text-slate-900">Students</h2>
        <p class="mt-1 text-slate-500">Choose an assigned grade and section to view its student list.</p>
    </div>
    <a href="home.php"
        class="rounded-xl border border-slate-300 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Attendance
        logs</a>
</div>

<section class="mb-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Current sections</p>
    <div class="mt-3 flex flex-wrap gap-2" aria-label="Assigned grades">
        <?php foreach ($grades as $assignedGrade):
        $isActiveGrade = $assignedGrade === $grade; ?>
        <a href="students.php?grade=<?= $assignedGrade ?>" class="rounded-xl px-4 py-2 text-sm font-bold transition <?= $isActiveGrade
    ? "bg-blue-600 text-white shadow-lg shadow-blue-200"
    : "bg-slate-100 text-slate-700 hover:bg-blue-50 hover:text-blue-700" ?>">Grade <?= $assignedGrade ?></a>
        <?php
    endforeach; ?>
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <?php foreach ($sections as $item):

        $isActive = $item["SECTION"] === $section;
        $detail = in_array((int) $item["GRADE"], [11, 12], true)
            ? $item["STRAND"]
            : $item["SUBJECT"];
        ?>
        <a href="students.php?grade=<?= $grade ?>&section=<?= urlencode(
    $item["SECTION"],
) ?>" class="rounded-xl border p-4 transition <?= $isActive
    ? "border-blue-600 bg-blue-600 text-white shadow-lg shadow-blue-200"
    : "border-slate-200 bg-white text-slate-900 hover:border-blue-300 hover:bg-blue-50" ?>">
            <span class="block text-lg font-black"><?= htmlspecialchars(
            $item["SECTION"],
        ) ?></span>
            <?php if (
            $detail
        ): ?><span class="mt-1 block text-sm font-bold <?= $isActive
    ? "text-blue-100"
    : "text-slate-600" ?>"><?= htmlspecialchars(
    $detail,
) ?></span><?php endif; ?>
            <span class="mt-1 block text-sm <?= $isActive
            ? "text-blue-100"
            : "text-slate-500" ?>"><?= $item[
    "student_count"
] ?> student<?= $item["student_count"] == 1 ? "" : "s" ?></span>
        </a>
        <?php
    endforeach; ?>
    </div>
</section>

<section class="table-filter-bar mb-6">
    <div class="mb-3 flex items-center justify-between gap-3">
        <p class="text-sm font-black text-slate-800">Filters</p><button id="clear-student-filters" type="button"
            class="table-filter-reset">Clear filters</button>
    </div>
    <label class="table-filter-control block max-w-md text-sm font-bold text-slate-700">Search students<input
            id="student-search" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-sm"
            placeholder="Name or LRN"></label>
</section>

<div class="mb-4">
    <h3 class="text-xl font-black text-slate-900">Grade <?= $grade ?> | <?= htmlspecialchars(
     $section,
 ) ?> students</h3>
    <p class="mt-1 text-slate-500"><?= $students->num_rows ?> student<?= $students->num_rows ===
 1
     ? ""
     : "s" ?> in this section</p>
</div>
<div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-5 py-4">Student</th>
                    <th class="px-5 py-4">LRN</th>
                    <th class="px-5 py-4">Grade</th>
                    <th class="px-5 py-4">Section</th>
                    <th class="px-5 py-4">Today</th>
                    <th class="px-5 py-4"></th>
                </tr>
            </thead>
            <tbody id="student-rows" class="divide-y divide-slate-100"
                data-empty-title="No students are in this section yet."
                data-empty-detail="Choose another assigned section to view its students.">
                <?php while ($student = $students->fetch_assoc()): ?>
                <tr class="hover:bg-slate-50">
                    <?php $displayName =
              trim(
                  ($student["LAST_NAME"] ?? "") .
                      ", " .
                      ($student["FIRST_NAME"] ?? "") .
                      (!empty($student["MIDDLE_NAME"])
                          ? ", " . $student["MIDDLE_NAME"]
                          : ""),
                  ", ",
              ) ?:
              $student["STUDENT_NAME"]; ?>
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3"><img src="../<?= htmlspecialchars(
              $student["IMAGE_PATH"],
          ) ?>" alt="" class="h-10 w-10 rounded-full object-cover"><span class="font-bold text-slate-900"><?= htmlspecialchars(
    $displayName,
) ?></span></div>
                    </td>
                    <td class="px-5 py-4 text-slate-600"><?= htmlspecialchars(
              $student["STUDENT_ID"],
          ) ?></td>
                    <td class="px-5 py-4"><span
                            class="rounded-full bg-violet-50 px-3 py-1 font-bold text-violet-700">Grade <?= htmlspecialchars(
              $student["GRADE"] ?? "—",
          ) ?></span></td>
                    <td class="px-5 py-4"><span class="rounded-full bg-blue-50 px-3 py-1 font-bold text-blue-700"><?= htmlspecialchars(
              $student["SECTION"],
          ) ?></span></td>
                    <?php
          $todayRemark = $student["attendance_remark"] ?? "";
          $todayLabel = $student["attended_today"]
              ? ($todayRemark === "Absent"
                  ? "Absent"
                  : "Present")
              : "Not recorded";
          $todayClass =
              $todayLabel === "Absent"
                  ? "bg-rose-50 text-rose-700"
                  : ($todayLabel === "Present"
                      ? "bg-emerald-50 text-emerald-700"
                      : "bg-amber-50 text-amber-700");
          ?>
                    <td class="px-5 py-4"><span
                            class="rounded-full px-3 py-1 font-bold <?= $todayClass ?>"><?= $todayLabel ?></span></td>
                    <td class="px-5 py-4 text-right"><?php if (
              !$student["attended_today"]
          ): ?><form class="mark-absent-form inline-block align-middle" action="mark-absent.php" method="post"><input
                                type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="student_id" value="<?= (int) $student[
    "ID"
] ?>"><input type="hidden" name="grade" value="<?= $grade ?>"><input type="hidden" name="section" value="<?= htmlspecialchars(
    $section,
) ?>"><input type="hidden" name="student_name" value="<?= htmlspecialchars(
    $displayName,
) ?>"><button type="button" class="open-mark-absent text-center text-xs font-bold leading-tight text-rose-600 hover:text-rose-800"><span
                                    class="block">Mark</span>absent</button></form><?php else: ?><span
                            class="inline-block align-middle text-center text-xs font-semibold leading-tight text-slate-400"><span
                                class="block"><?= $todayLabel ===
"Absent"
    ? "Marked"
    : "Attendance" ?></span><?= $todayLabel === "Absent"
    ? "absent"
    : "recorded" ?></span><?php endif; ?><a
                            class="ml-3 inline-block align-middle text-center text-xs font-bold leading-tight text-blue-600 hover:text-blue-800"
                            href="student-profile.php?id=<?= $student[
    "ID"
] ?>&grade=<?= $grade ?>&section=<?= urlencode(
    $section,
) ?>"><span class="block">Open</span>profile</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="mark-absent-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5"
    role="dialog" aria-modal="true" aria-labelledby="mark-absent-title">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
        <h3 id="mark-absent-title" class="text-xl font-black text-slate-900">Mark student absent?</h3>
        <p id="mark-absent-message" class="mt-2 text-sm text-slate-600">This will create an absence record for today.
        </p>
        <div class="mt-6 flex gap-3"><button id="cancel-mark-absent" type="button"
                class="flex-1 rounded-xl border border-slate-300 px-4 py-3 font-bold text-slate-700">Cancel</button><button
                id="confirm-mark-absent" type="button"
                class="flex-1 rounded-xl bg-rose-600 px-4 py-3 font-bold text-white hover:bg-rose-700">Mark
                absent</button></div>
    </div>
</div>
<script src="../js/student-pagination.js?v=9"></script>
<script>
    document.getElementById('clear-student-filters').onclick = () => {
        const search = document.getElementById('student-search');
        search.value = '';
        search.dispatchEvent(new Event('input'));
    };
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('mark-absent-modal'),
            message = document.getElementById('mark-absent-message'),
            cancel = document.getElementById('cancel-mark-absent'),
            confirm = document.getElementById('confirm-mark-absent');
        let pendingForm = null;
        document.querySelectorAll('.open-mark-absent').forEach(button => button.addEventListener('click',
    () => {
            pendingForm = button.form;
            message.textContent =
                `Mark ${pendingForm.querySelector('[name="student_name"]').value} absent for today?`;
            modal.classList.replace('hidden', 'flex')
        }));
        cancel.onclick = () => {
            pendingForm = null;
            modal.classList.replace('flex', 'hidden')
        };
        confirm.onclick = () => {
            if (pendingForm) pendingForm.submit()
        };
        modal.addEventListener('click', event => {
            if (event.target === modal) {
                pendingForm = null;
                modal.classList.replace('flex', 'hidden')
            }
        })
    });
</script>
<?php teacherFoot(); ?>
