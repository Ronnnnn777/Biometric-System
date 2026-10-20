<?php
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/attendance-schema.php";
require_once "../includes/teacher-profile-schema.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/csrf-token.php";
require "../includes/school-year-schema.php";
require "../includes/student-promotion-schema.php";
ensureAttendanceTimeOutColumn($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
require "components.php";
$id = (int) ($_GET["id"] ?? 0);
$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
$assignments = teacherAssignments($conn, $teacherId);
if (!$assignments) {
    http_response_code(403);
    exit("Your teacher account does not have an assigned grade and section.");
}
$default = $assignments[0];
$grade = (int) ($_GET["grade"] ?? $default["GRADE"]);
$section = trim($_GET["section"] ?? $default["SECTION"]);
$applicableAssignments = [];
foreach ($assignments as $assignment) {
    if (
        (int) $assignment["GRADE"] === $grade &&
        $assignment["SECTION"] === $section
    ) {
        $applicableAssignments[] = $assignment;
    }
}
if (!$applicableAssignments) {
    $_SESSION['toast'] = [
        'message' => 'That class is not assigned to your account. Showing your assigned class instead.',
        'type' => 'error',
    ];
    header('Location: students.php?' . http_build_query([
        'grade' => (int) $default['GRADE'],
        'section' => $default['SECTION'],
    ]));
    exit;
}
$stmt = $conn->prepare(
    "SELECT * FROM students WHERE ID=? AND SECTION=? AND GRADE=? LIMIT 1",
);
$stmt->bind_param("isi", $id, $section, $grade);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) {
    http_response_code(404);
    exit("Student not found.");
}
$matchingAssignments = array_values(array_filter(
    $applicableAssignments,
    fn($assignment) => (string) ($assignment['STRAND'] ?? '') === (string) ($student['STRAND'] ?? ''),
));
if (!$matchingAssignments) {
    http_response_code(403);
    exit('That student is not in your assigned strand.');
}
$assignedSubject = trim((string) ($matchingAssignments[0]['SUBJECT'] ?? ''));
$setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$schoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');
$resultQuery = $conn->prepare('SELECT SUBJECT,RESULT FROM student_subject_results WHERE STUDENT_ID=? AND SCHOOL_YEAR=? ORDER BY SUBJECT');
$resultQuery->bind_param('ss', $student['STUDENT_ID'], $schoolYear);
$resultQuery->execute();
$academicResults = [];
$failedSubjects = 0;
$resultRows = $resultQuery->get_result();
while ($resultRows && ($result = $resultRows->fetch_assoc())) {
    $academicResults[] = $result;
    if ($result['RESULT'] === 'Failed') $failedSubjects++;
}
$promotionDecision = studentPromotionDecision($failedSubjects);
$log = $conn->prepare(
    "SELECT TIME_IN,LUNCH_OUT,AFTERNOON_TIME_IN,TIME_OUT,ATTENDANCE_REMARK,ATTENDANCE_NOTE FROM attendance WHERE TRIM(STUDENT_ID)=TRIM(?) AND IS_AUTO_ABSENT=0 ORDER BY TIME_IN DESC",
);
$log->bind_param("s", $student["STUDENT_ID"]);
$log->execute();
$records = $log->get_result();
teacherHead("Student profile");
teacherShell("Student profile");
?>
<script src="../js/table-pagination.js?v=5"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.querySelector('table tbody');
        if (!rows) return;
        const scroll = rows.closest('.overflow-x-auto'),
            top = document.createElement('div'),
            bottom = document.createElement('div');
        top.className = 'mx-6';
        bottom.className = 'mx-6 mb-6';
        scroll?.insertAdjacentElement('beforebegin', top);
        scroll?.insertAdjacentElement('afterend', bottom);
        createTablePagination({
            rows,
            pager: [top, bottom],
            label: 'attendance records'
        });
        rows.querySelectorAll('tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 4 && cells[3].textContent.trim() === 'Absent') {
                cells[1].innerHTML = '&mdash;';
                cells[2].innerHTML = '&mdash;';
            }
        });
    });
</script>
<a href="students.php?grade=<?= $grade ?>&section=<?= urlencode(
    $section,
) ?>" class="inline-flex items-center gap-1 text-sm font-bold text-blue-600"><?= icon('arrow-left') ?>Back to students</a>
<section class="mt-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-xl font-black text-slate-900">Academic results</h2><p class="mt-1 text-sm text-slate-500">School year <?= htmlspecialchars($schoolYear) ?>. Record this student's result for your subject.</p></div>
        <span class="rounded-full px-3 py-1 text-sm font-bold <?= $promotionDecision === 'Promoted' ? 'bg-emerald-50 text-emerald-700' : ($promotionDecision === 'Summer class required' ? 'bg-amber-50 text-amber-700' : 'bg-rose-50 text-rose-700') ?>"><?= htmlspecialchars($promotionDecision) ?></span>
    </div>
    <?php if ($academicResults): ?><div class="mt-4 flex flex-wrap gap-2"><?php foreach ($academicResults as $academicResult): ?><span class="rounded-lg px-3 py-2 text-sm font-bold <?= $academicResult['RESULT'] === 'Passed' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>"><?= htmlspecialchars($academicResult['SUBJECT']) ?>: <?= htmlspecialchars($academicResult['RESULT']) ?></span><?php endforeach; ?></div><?php endif; ?>
    <p class="mt-4 text-sm text-slate-600">Promotion rule: 0–1 failed subject = promoted; 2 = summer class; 3 or more = retained in the current grade.</p>
    <form action="save-subject-result.php" method="post" class="mt-5 grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px_auto]"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="student_record_id" value="<?= (int) $student['ID'] ?>"><input type="hidden" name="grade" value="<?= (int) $grade ?>"><input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>"><?php if ($assignedSubject !== ''): ?><input type="hidden" name="subject" value="<?= htmlspecialchars($assignedSubject) ?>"><div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Assigned subject</span><span class="font-bold text-slate-900"><?= htmlspecialchars($assignedSubject) ?></span></div><?php else: ?><label class="text-sm font-bold text-slate-700">Subject<input name="subject" maxlength="80" required placeholder="Subject taught" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label><?php endif; ?><label class="text-sm font-bold text-slate-700">Result<select name="result" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" required><option value="">Choose result</option><option>Passed</option><option>Failed</option></select></label><button class="self-end rounded-xl bg-blue-600 px-5 py-3 font-bold text-white hover:bg-blue-700">Save result</button></form>
</section>
<div class="mt-5 grid gap-7 lg:grid-cols-[320px_minmax(0,1fr)]">
    <aside class="h-fit rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"><img src="../<?= htmlspecialchars(
    $student["IMAGE_PATH"],
) ?>" alt="<?= htmlspecialchars(
    $student["STUDENT_NAME"],
) ?>" class="aspect-square w-full rounded-2xl object-cover">
        <h2 class="mt-5 text-2xl font-black text-slate-900"><?= htmlspecialchars(
    $student["STUDENT_NAME"],
) ?></h2>
        <p class="mt-1 text-slate-500"><?= htmlspecialchars(
    $student["STUDENT_ID"],
) ?></p><span class="mt-4 inline-block rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700">Grade
            <?= $grade ?> | <?= htmlspecialchars(
     $student["SECTION"],
 ) ?></span>
    </aside>
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-6">
            <div>
                <h3 class="text-xl font-black text-slate-900">Attendance log</h3>
                <p class="mt-1 text-sm text-slate-500">All recorded attendance for this student.</p>
            </div><a href="export-attendance.php?student=<?= urlencode(
    $student["STUDENT_ID"],
) ?>&all_dates=1" class="rounded-xl bg-emerald-600 px-4 py-3 font-bold text-white">Export all dates</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-5 py-4">Date</th>
                        <th class="px-5 py-4">Time in</th>
                        <th class="px-5 py-4">Time out</th>
                        <th class="px-5 py-4">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100"><?php if (
    $records->num_rows
):
    while ($record = $records->fetch_assoc()):

        $timeIn = new DateTime($record["TIME_IN"]);
        $timeOut = empty($record["TIME_OUT"])
            ? null
            : new DateTime($record["TIME_OUT"]);
        $status = attendanceStatusLabel(
            $record["TIME_IN"],
            $record["TIME_OUT"] ?? null,
        );
        ?><tr>
                        <td class="px-5 py-4 text-slate-700"><?= $timeIn->format(
    "F j, Y",
) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $timeIn->format(
    "g:i A",
) ?></td>
                        <td class="px-5 py-4 text-slate-700"><?= $timeOut
    ? $timeOut->format("g:i A")
    : "—" ?></td>
                        <td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold <?= attendanceStatusClass(
    $record["TIME_IN"],
    $record["TIME_OUT"] ?? null,
) ?>"><?= htmlspecialchars($status) ?></span></td>
                    </tr><?php
    endwhile;
else:
     ?><tr>
                        <td colspan="4" class="px-5 py-10 text-center text-slate-500">No attendance records found.</td>
                    </tr><?php
endif; ?></tbody>
            </table>
        </div>
    </section>
</div><?php teacherFoot(); ?>
