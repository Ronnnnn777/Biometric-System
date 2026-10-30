<?php
require "page-guard.php";
require_once "../config/db_conn.php";
require_once "../includes/attendance-schema.php";
require_once "../includes/teacher-profile-schema.php";
require_once "../includes/teacher-assignment-schema.php";
require "../includes/csrf-token.php";
require_once "../includes/school-year-schema.php";
require_once "../includes/promotion-planner.php";
ensureAttendanceTimeOutColumn($conn);
ensureTeacherProfileColumns($conn);
ensureTeacherAssignmentSchema($conn);
ensureSchoolYearRolloverSchema($conn);
ensureStudentPromotionSchema($conn);
require "components.php";
$id = (int) ($_GET["id"] ?? 0);
$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
$assignments = teacherAdvisoryAssignments($conn, $teacherId);
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
    "SELECT * FROM students WHERE ID=? AND SECTION=? AND GRADE=? AND ENROLLMENT_STATUS='Enrolled' LIMIT 1",
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
$subjectAssignments = teacherAssignments($conn, $teacherId);
$assignedSubject = '';
foreach ($subjectAssignments as $subjectAssignment) {
    if ((int)$subjectAssignment['GRADE'] === (int)$student['GRADE'] && $subjectAssignment['STRAND'] === $student['STRAND'] && $subjectAssignment['SECTION'] === $student['SECTION']) $assignedSubject = trim($subjectAssignment['SUBJECT']);
}
$setting = $conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc();
$schoolYear = (string) ($setting['ACTIVE_SCHOOL_YEAR'] ?? '');
$resultQuery = $conn->prepare('SELECT * FROM student_subject_results WHERE STUDENT_ID=? AND SCHOOL_YEAR=? ORDER BY SUBJECT');
$resultQuery->bind_param('ss', $student['STUDENT_ID'], $schoolYear);
$resultQuery->execute();
$academicResults = [];
$failedSubjects = 0;
$resultRows = $resultQuery->get_result();
while ($resultRows && ($result = $resultRows->fetch_assoc())) {
    $academicResults[] = $result;
    if ($result['RESULT'] === 'Failed') $failedSubjects++;
}
$requiredQuery = $conn->prepare('SELECT SUBJECT FROM promotion_required_subjects WHERE SCHOOL_YEAR=? AND GRADE=? AND STRAND=? ORDER BY SUBJECT');
$requiredQuery->bind_param('sis', $schoolYear, $student['GRADE'], $student['STRAND']);
$requiredQuery->execute();
$requiredSubjects = array_column($requiredQuery->get_result()->fetch_all(MYSQLI_ASSOC), 'SUBJECT');
$promotionDecision = promotionAcademicStanding($student, $academicResults, $requiredSubjects)['decision'];
$log = $conn->prepare(
    "SELECT TIME_IN,LUNCH_OUT,AFTERNOON_TIME_IN,TIME_OUT,ATTENDANCE_REMARK,ATTENDANCE_NOTE FROM attendance WHERE TRIM(STUDENT_ID)=TRIM(?) AND IS_AUTO_ABSENT=0 ORDER BY TIME_IN DESC",
);
$log->bind_param("s", $student["STUDENT_ID"]);
$log->execute();
$records = $log->get_result();
teacherHead("Student profile");
teacherShell("Student profile");
?>
<style>
    /* Override the portal mobile minimum for this compact four-column log. */
    .student-profile-layout #student-attendance { min-width: 32rem; }
</style>
<script src="../js/table-pagination.js?v=7"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const rows = document.querySelector('#student-attendance tbody');
        if (!rows) return;
        const bottom = document.createElement('div');
        document.getElementById('attendance-pagination')?.append(bottom);
        createTablePagination({
            rows,
            pager: bottom,
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
<a href="students.php" class="inline-flex items-center gap-1.5 text-sm font-bold text-slate-600 hover:text-blue-700 mb-6 transition-colors">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
        stroke="currentColor" class="h-4 w-4" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
    </svg>
    Back to Students
</a>

<div class="student-profile-layout grid grid-cols-1 lg:grid-cols-12 items-start gap-5 w-full min-w-0">
    <!-- Profile card stays at its natural height. -->
    <div class="lg:col-span-4 xl:col-span-3 w-full min-w-0">
        <aside
            class="card bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm p-5 flex flex-col items-center text-center">
            <img src="../<?= htmlspecialchars($student["IMAGE_PATH"]) ?>"
                alt="<?= htmlspecialchars($student["STUDENT_NAME"]) ?>"
                class="h-24 w-24 rounded-full object-cover border-2 border-slate-100 dark:border-slate-800">
            <h2 class="mt-3 text-xl font-black break-words max-w-full text-slate-900 dark:text-white">
                <?= htmlspecialchars($student["STUDENT_NAME"]) ?>
            </h2>
            <p class="mt-1 text-sm font-medium text-slate-500 dark:text-slate-400">
                LRN: <?= htmlspecialchars($student["STUDENT_ID"]) ?>
            </p>
            <span
                class="mt-3 inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                Grade <?= $grade ?> · <?= htmlspecialchars($student["SECTION"]) ?>
            </span>
        </aside>
    </div>

    <!-- Academic Results + Attendance Log -->
    <div class="lg:col-span-8 xl:col-span-9 w-full min-w-0 flex flex-col gap-5">
        <!-- Academic Results Card -->
        <section class="card border border-slate-200 dark:border-slate-800 shadow-sm">
            <div class="card-header">
                <div class="flex items-start justify-between gap-3 w-full min-w-0">
                    <div>
                        <h2 class="text-lg font-black text-slate-900 dark:text-white">Academic results</h2>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">School year <?= htmlspecialchars($schoolYear) ?>. Record
                            quarterly grades in Grading to calculate this student's subject result.</p>
                    </div>
                    <span
                        class="shrink-0 max-w-[45%] text-center rounded-full px-3 py-1 text-sm font-bold <?= $promotionDecision === 'Promoted' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : ($promotionDecision === 'Summer class required' ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400') ?>"><?= htmlspecialchars($promotionDecision) ?></span>
                </div>
            </div>
            <div class="card-body">
                <?php if ($academicResults): ?>
                <div class="flex flex-wrap gap-2 mb-4">
                    <?php foreach ($academicResults as $academicResult): ?>
                    <span
                        class="rounded-lg px-3 py-1.5 text-sm font-bold <?= $academicResult['RESULT'] === 'Passed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' ?>"><?= htmlspecialchars($academicResult['SUBJECT']) ?>:
                        <?= htmlspecialchars($academicResult['RESULT']) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Promotion rule: 0–1 failed subject = promoted; 2 = summer class; 3
                    or more = retained in the current grade.</p>

                <?php if ($assignedSubject !== ''): ?>
                <a class="btn btn-primary" href="grading.php?<?= htmlspecialchars(http_build_query(['grade'=>$grade,'section'=>$section]),ENT_QUOTES) ?>">Open quarterly grading: <?= htmlspecialchars($assignedSubject) ?></a>
                <p class="mt-2 text-sm text-slate-500">Subject results are calculated from entered grades. Promotion requires all required subjects and all four quarters.</p>
                <?php else: ?><p class="text-sm text-slate-500">No subject grading permission assigned for this class.</p><?php endif; ?>
            </div>
        </section>

        <!-- Attendance Log Card -->
        <section class="card border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="card-header">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 w-full">
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white">Attendance log</h3>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">All recorded attendance for this student.</p>
                    </div>
                    <a href="export-attendance.php?student=<?= urlencode($student["STUDENT_ID"]) ?>&all_dates=1"
                        class="btn btn-secondary shrink-0">Export all dates</a>
                </div>
            </div>
            <div>
                <div class="w-full overflow-x-auto">
                    <table id="student-attendance" class="w-full text-left text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-5 py-3 whitespace-nowrap">Date</th>
                                <th scope="col" class="px-5 py-3 whitespace-nowrap">Time in</th>
                                <th scope="col" class="px-5 py-3 whitespace-nowrap">Time out</th>
                                <th scope="col" class="px-5 py-3 whitespace-nowrap">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if ($records->num_rows): ?>
                            <?php while ($record = $records->fetch_assoc()): ?>
                            <?php
                            $timeIn = new DateTime($record["TIME_IN"]);
                            $timeOut = empty($record["TIME_OUT"]) ? null : new DateTime($record["TIME_OUT"]);
                            $status = attendanceStatusLabel($record["TIME_IN"], $record["TIME_OUT"] ?? null);
                            ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-300 whitespace-nowrap"><?= $timeIn->format("F j, Y") ?></td>
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-300 whitespace-nowrap"><?= $timeIn->format("g:i A") ?></td>
                                <td class="px-5 py-3 text-slate-700 dark:text-slate-300 whitespace-nowrap"><?= $timeOut ? $timeOut->format("g:i A") : "—" ?></td>
                                <td class="px-5 py-3 whitespace-nowrap"><span
                                        class="rounded-full px-3 py-1 text-xs font-bold <?= attendanceStatusClass($record["TIME_IN"], $record["TIME_OUT"] ?? null) ?>"><?= htmlspecialchars($status) ?></span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="4" class="px-5 py-8 text-center text-slate-500 dark:text-slate-400">No attendance records found.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="attendance-pagination" class="px-5"></div>
            </div>
        </section>
    </div>
</div>
<?php teacherFoot(); ?>
