<?php
require 'page-guard.php';
require_once '../config/db_conn.php';
require_once '../includes/csrf-token.php';
require_once '../includes/school-calendar-schema.php';
require_once '../includes/student-promotion-schema.php';
require_once '../includes/promotion-planner.php';
require_once '../includes/promotion-review.php';
require 'components.php';

ensureStudentPromotionSchema($conn);
ensureSchoolCalendarSchema($conn);

$activeSchoolYear=(string)$conn->query('SELECT ACTIVE_SCHOOL_YEAR FROM school_year_settings WHERE ID=1')->fetch_assoc()['ACTIVE_SCHOOL_YEAR'];
$plan=null; $context=null; $controls=[]; $choices=['outcomes'=>[],'strand_choices'=>[]]; $error='';
try {
    $context=promotionContext($conn,$activeSchoolYear);
    $controls=promotionDefaultTargets($context['students']);
    foreach ($context['students'] as $student) if ((int)$student['GRADE'] === 10) {
        $choices['strand_choices'][(string)$student['STUDENT_ID']] = 'Academic';
    }
    if ((string) ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('The preview request expired. Refresh this page.');
        if (!is_array($_POST['plans'] ?? null)) throw new RuntimeException('Choose valid promotion targets.');
        promotionValidatedTargets($_POST['plans']);
        $controls=$_POST['plans'];
        if (is_array($_POST['outcomes'] ?? null)) $choices['outcomes']=$_POST['outcomes'];
        if (is_array($_POST['strand_choices'] ?? null)) $choices['strand_choices']=$_POST['strand_choices'];
    } elseif (isset($_GET['plans'])) {
        if (!is_array($_GET['plans'])) throw new RuntimeException('Choose valid promotion targets.');
        promotionValidatedTargets($_GET['plans']);
        $controls=$_GET['plans'];
    }
    $choices['require_grade10_strands']=true;
    $plan=buildPromotionPlan($context,promotionValidatedTargets($controls),$choices);
} catch (mysqli_sql_exception $exception) { $error='Promotion setup is unavailable. Run database/migrate_promotion_policy.php before using rollover.'; }
catch (RuntimeException $exception) { $error=$exception->getMessage(); }
$pastRolloverYears=$conn->query('SELECT DISTINCT SCHOOL_YEAR FROM student_school_history ORDER BY SCHOOL_YEAR DESC')->fetch_all(MYSQLI_ASSOC);
$summary=$plan['summary'] ?? [];
$scheduleOpen = (string) ($_GET['view'] ?? '') === 'schedule';
$calendarRangeStart = date('Y-m-d', strtotime('-2 months'));
$calendarRangeEnd = date('Y-m-d', strtotime('+10 months'));
$calendarEntries = schoolCalendarEntries($conn, $calendarRangeStart, $calendarRangeEnd);
$calendarToday = date('Y-m-d');
$calendarTodayInfo = schoolDayInfo($conn, $calendarToday);
adminHead('School year rollover');
adminSidebar('rollover','End-of-school-year rollover','Review completed grades, honor qualification and balanced section assignments before confirming.');
$escape=static fn($value): string=>htmlspecialchars((string)$value,ENT_QUOTES);
?>
<style>
    .step-nav-btn {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
        border-radius: 0.75rem;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #64748b;
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
        font-weight: 700;
        text-align: center;
        transition: background-color .15s, border-color .15s, color .15s;
        cursor: pointer;
    }
    .step-nav-btn:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
    }
    .step-nav-btn[aria-current="step"] {
        background: var(--maruhat-blue, #1f4f91);
        border-color: var(--maruhat-blue, #1f4f91);
        color: #fff;
        box-shadow: 0 1px 2px rgb(15 23 42 / .12);
    }
    .step-nav-btn[aria-current="step"] .step-nav-label {
        color: #fff;
    }
    .step-nav-btn[aria-current="step"] .step-nav-description {
        color: rgba(255,255,255,.85);
    }
    .no-scrollbar {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }
    .no-scrollbar::-webkit-scrollbar {
        display: none;
    }
    .section-chevron {
        transition: transform .25s ease;
    }
    .section-accordion-trigger[aria-expanded="true"] .section-chevron {
        transform: rotate(180deg);
    }
    .section-accordion-panel:not(.hidden) {
        animation: section-accordion-in .2s ease-out;
    }
    @keyframes section-accordion-in {
        from { opacity: 0; transform: translateY(-4px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (prefers-reduced-motion: reduce) {
        .section-chevron { transition: none; }
        .section-accordion-panel:not(.hidden) { animation: none; }
    }
</style>
<?php

// Step 1 is an advisory-class roster: every enrolled student appears under
// their current Grade -> Section, whether or not a final grade exists.
$advisoryGroups = [];
$promoted = []; $honor = []; $regular = []; $retained = []; $remedial = []; $graduated = [];
if ($plan) {
    foreach ($plan['students'] as $id => $record) {
        $student = $record['student']; $standing = $record['standing'];
        $grade = (int) $student['GRADE'];
        $strand = (string) $student['STRAND'];
        $section = trim((string) $student['SECTION']) ?: 'Unassigned section';
        $sectionLabel = $section . ($strand !== '' ? ' | ' . $strand : '');
        $advisoryGroups[$grade][$sectionLabel][(string) $id] = $record;

        if ($standing['decision'] === 'Retained') {
            $retained[$id] = $record;
            continue;
        }
        if ($grade === 12) {
            $graduated[$id] = $record;
            continue;
        }
        $promoted[$id] = $record;
        if ($standing['decision'] === 'Summer Class / Remedial') $remedial[$id] = $record;
        if ($standing['average'] !== null && (float) $standing['average'] >= 90.00) { $honor[$id] = $record; }
        else { $regular[$id] = $record; }
    }
    ksort($advisoryGroups, SORT_NUMERIC);
    foreach ($advisoryGroups as &$gradeSections) {
        ksort($gradeSections, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($gradeSections as &$roster) ksort($roster, SORT_NATURAL | SORT_FLAG_CASE);
        unset($roster);
    }
    unset($gradeSections);
}

// The dedicated advisory mapping is authoritative for rollover display.
$sectionAdvisers = [];
if ($plan) {
    $adviserRows = $conn->prepare(
        "SELECT s.GRADE,s.STRAND,s.SECTION,t.TEACHER_NAME
           FROM teacher_advisory_assignments aa
           JOIN sections s ON s.SECTION_ID=aa.SECTION_ID
           JOIN teachers t ON t.TEACHER_ID=aa.TEACHER_ID AND t.TEACHER_STATUS='Active'
          WHERE aa.SCHOOL_YEAR=? AND aa.RELEASED_AT IS NULL",
    );
    $adviserRows->bind_param('s', $activeSchoolYear);
    $adviserRows->execute();
    foreach ($adviserRows->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $key = (int) $row['GRADE'].'|'.$row['STRAND'].'|'.$row['SECTION'];
        $sectionAdvisers[$key] = (string) $row['TEACHER_NAME'];
    }
}
?>
<section class="card card-body w-full flex-1 px-4 sm:px-6 lg:px-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-black text-slate-900 dark:text-white">End-of-School-Year Rollover</h2>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-blue-500/20 bg-blue-500/10 px-3 py-1 text-xs font-bold text-blue-700 dark:text-blue-300">Active school year: <?= $escape($activeSchoolYear) ?></span>
                <p class="text-sm text-slate-500">Review the plan in four steps, then confirm. Nothing is changed until you enter your administrator password and submit Step 4.</p>
            </div>
        </div>
        <button type="button" id="toggle-schedule-btn" aria-controls="schedule-panel" aria-expanded="<?= $scheduleOpen ? 'true' : 'false' ?>" class="btn btn-secondary inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
            <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 002-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span>Schedule &amp; Holidays</span>
        </button>
    </div>

    <section id="schedule-panel" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-5 dark:border-slate-800 dark:bg-slate-900/60" <?= $scheduleOpen ? '' : 'hidden' ?> aria-label="School calendar and holiday schedule">
        <div class="flex flex-col gap-3 border-b border-slate-200 pb-5 dark:border-slate-800 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="text-xl font-black text-slate-900 dark:text-white">School Calendar &amp; Holiday Schedule</h3>
                <p class="mt-1 text-sm text-slate-500">Attendance follows this calendar. Unset dates follow the Monday–Friday school-day default; weekends are non-school days.</p>
            </div>
            <p class="shrink-0 text-sm font-bold text-slate-700 dark:text-slate-200">Today, <?= $escape(date('F j, Y')) ?>:
                <span class="ml-1 rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><?= $escape(schoolCalendarTypeLabel($calendarTodayInfo['type'])) ?></span>
            </p>
        </div>

        <form action="school-calendar-save.php" method="post" class="mt-5 flex flex-wrap items-end gap-3">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700 dark:text-slate-200">Date
                <input type="date" name="calendar_date" value="<?= $escape($calendarToday) ?>" required class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
            </label>
            <label class="table-filter-control flex-1 text-sm font-bold text-slate-700 dark:text-slate-200">Day type
                <select name="day_type" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <?php foreach (SCHOOL_CALENDAR_TYPES as $calendarType): ?>
                    <option value="<?= $escape($calendarType) ?>"><?= $escape(schoolCalendarTypeLabel($calendarType)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="table-filter-control flex-[2] text-sm font-bold text-slate-700 dark:text-slate-200">Label (optional)
                <input type="text" name="label" maxlength="120" placeholder="e.g. Araw ng Kagitingan, typhoon signal #2" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
            </label>
            <button class="btn btn-primary">Save date</button>
        </form>

        <div class="mt-6 w-full overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Day</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Label</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (!$calendarEntries): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center">
                            <p class="text-base font-black text-slate-800 dark:text-white">No calendar exceptions recorded.</p>
                            <p class="mt-1 text-sm text-slate-500">Every Monday–Friday is treated as a school day until you add a holiday, suspension, or special schedule.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($calendarEntries as $calendarEntry):
                        $calendarType = (string) $calendarEntry['DAY_TYPE'];
                        $calendarChip = match ($calendarType) {
                            'school', 'special' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                            'holiday' => 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                            'suspension' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                            default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                        };
                    ?>
                    <tr>
                        <td class="px-4 py-3 font-bold text-slate-900 dark:text-white"><?= $escape($calendarEntry['CALENDAR_DATE']) ?></td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= $escape(date('l', strtotime($calendarEntry['CALENDAR_DATE']))) ?></td>
                        <td class="px-4 py-3"><span class="rounded-full px-3 py-1 text-xs font-bold <?= $calendarChip ?>"><?= $escape(schoolCalendarTypeLabel($calendarType)) ?></span></td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= $escape($calendarEntry['LABEL'] ?: '—') ?></td>
                        <td class="px-4 py-3">
                            <form action="school-calendar-save.php" method="post" onsubmit="return confirm('Remove this calendar entry?')">
                                <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="calendar_date" value="<?= $escape($calendarEntry['CALENDAR_DATE']) ?>">
                                <button class="btn btn-secondary btn-sm">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php if ($error): ?><p class="mt-4 rounded-xl bg-rose-50 p-4 font-bold text-rose-700" role="alert"><?= $escape($error) ?></p><?php endif; ?>

    <?php if ($plan): ?>
    <!-- --------------------------------------------------------------------- -->
    <!-- Stepper                                                               -->
    <!-- --------------------------------------------------------------------- -->
    <div id="sticky-sentinel" class="h-px" aria-hidden="true"></div>
    <div id="sticky-step-nav" class="sticky top-16 z-40 -mx-4 border-b border-slate-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur-md transition-shadow duration-200 dark:border-slate-800 dark:bg-slate-900/95 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
    <nav aria-label="Rollover steps">
        <div class="no-scrollbar flex w-full max-w-full items-center justify-between gap-2 overflow-x-auto sm:gap-4">
            <button type="button" class="step-nav-btn min-w-[10rem] flex-1 text-left sm:min-w-0 sm:text-center" data-target="#step-1"
                aria-current="step">
                <span class="step-nav-label font-bold text-slate-900">Step 1</span>
                <span class="step-nav-description ml-1.5 text-xs text-slate-500">Advisory classes</span>
            </button>
            <button type="button" class="step-nav-btn min-w-[10rem] flex-1 text-left sm:min-w-0 sm:text-center" data-target="#step-2"
                aria-current="false">
                <span class="step-nav-label font-bold text-slate-900">Step 2</span>
                <span class="step-nav-description ml-1.5 text-xs text-slate-500">Promotion review</span>
            </button>
            <button type="button" class="step-nav-btn min-w-[10rem] flex-1 text-left sm:min-w-0 sm:text-center" data-target="#step-3"
                aria-current="false">
                <span class="step-nav-label font-bold text-slate-900">Step 3</span>
                <span class="step-nav-description ml-1.5 text-xs text-slate-500">Section preview</span>
            </button>
            <button type="button" class="step-nav-btn min-w-[10rem] flex-1 text-left sm:min-w-0 sm:text-center" data-target="#step-4"
                aria-current="false">
                <span class="step-nav-label font-bold text-slate-900">Step 4</span>
                <span class="step-nav-description ml-1.5 text-xs text-slate-500">Confirm rollover</span>
            </button>
        </div>
    </nav>
    </div>

    <!-- --------------------------------------------------------------------- -->
    <!-- Step 1 — Advisory-class roster                                        -->
    <!-- --------------------------------------------------------------------- -->
    <div id="step-1" class="step-section scroll-mt-24 mt-10 mb-6 rounded-r-2xl border border-l-4 border-l-blue-600 border-slate-200 bg-blue-50/50 p-5 dark:border-slate-800 dark:bg-blue-900/20">
        <h2 class="text-2xl font-black text-blue-900 dark:text-blue-100">Step 1 · Advisory classes</h2>
        <p class="text-sm text-slate-500 mb-3">Review every enrolled student by Grade and advisory section. Final grades
            are summarized only as an overall GWA; a student without grades is promoted administratively.</p>

        <?php foreach ($advisoryGroups as $grade => $gradeSections): ?>
        <?php
            $sectionCount = count($gradeSections);
            $totalGradeStudents = array_sum(array_map(
                static fn(array $students): int => count($students),
                $gradeSections,
            ));
            // Include the strand in senior-high grade headers so "Grade 11
            // Academic" is distinguishable from a mixed Academic/Techpro group.
            $gradeStrands = [];
            foreach ($gradeSections as $students) {
                foreach ($students as $entry) {
                    $strand = trim((string) ($entry['student']['STRAND'] ?? ''));
                    if ($strand !== '') $gradeStrands[$strand] = true;
                }
            }
            $gradeName = 'Grade ' . (int) $grade;
            if ($gradeStrands) {
                $strandNames = array_keys($gradeStrands);
                sort($strandNames, SORT_NATURAL | SORT_FLAG_CASE);
                $gradeName .= ' ' . implode(' / ', $strandNames);
            }
        ?>
        <details class="group mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <summary class="flex cursor-pointer list-none select-none flex-wrap items-center justify-between gap-3 px-6 py-4 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 dark:hover:bg-slate-800/50 dark:focus-visible:ring-blue-900">
                <div class="flex items-center gap-3">
                    <svg class="h-6 w-6 text-slate-700 transition-transform group-open:rotate-90 dark:text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100"><?= $escape($gradeName) ?></h3>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                        <?= $sectionCount ?> section<?= $sectionCount === 1 ? '' : 's' ?>
                    </span>
                    <span class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                        <?= $totalGradeStudents ?> student<?= $totalGradeStudents === 1 ? '' : 's' ?>
                    </span>
                </div>
            </summary>

            <div class="p-5 border-t border-slate-100 dark:border-slate-800 space-y-4">
            <?php $sectionIndex = 0; foreach ($gradeSections as $sectionName => $students): $sectionIndex++;
                $studentCount = count($students);
                $firstEntry = $students ? reset($students) : [];
                $sectionStudent = $firstEntry['student'] ?? [];
                $sectionStrand = (string) ($sectionStudent['STRAND'] ?? '');
                $sectionValue = (string) ($sectionStudent['SECTION'] ?? $sectionName);
                $adviserKey = (int) $grade . '|' . $sectionStrand . '|' . $sectionValue;
                $sectionAdviser = $sectionAdvisers[$adviserKey] ?? 'Unassigned';
                $panelId = 'academic-section-' . (int) $grade . '-' . $sectionIndex;
            ?>
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <button type="button"
                    class="section-accordion-trigger flex w-full cursor-pointer items-center justify-between rounded-xl bg-slate-50 px-6 py-4 text-left text-lg font-bold text-slate-900 transition-all hover:bg-slate-100 dark:bg-slate-800/60 dark:text-slate-100"
                    onclick="toggleSectionDropdown(this)" aria-expanded="false" aria-controls="<?= $escape($panelId) ?>">
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-lg bg-blue-500/10 text-blue-700 dark:text-blue-300">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-lg"><?= $escape($sectionName) ?></span>
                            <?php if ($sectionStrand !== ''): ?>
                            <span class="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400"><?= $escape($sectionStrand) ?></span>
                            <?php endif; ?>
                            <span class="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">Section adviser: <?= $escape($sectionAdviser) ?></span>
                        </span>
                    </span>
                    <span class="flex flex-none items-center gap-3">
                        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-bold text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                            <?= number_format($studentCount) ?> student<?= $studentCount !== 1 ? 's' : '' ?>
                        </span>
                        <svg class="section-chevron h-6 w-6 flex-none text-slate-700 transition-transform duration-300 dark:text-slate-200" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M5 7.5 10 12.5 15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                </button>
                <div id="<?= $escape($panelId) ?>" class="section-accordion-panel hidden p-4 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 mt-2 overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:text-slate-400">
                                <th scope="col" class="px-3 py-3 font-black">Student</th>
                                <th scope="col" class="px-3 py-3 font-black">LRN</th>
                                 <th scope="col" class="px-3 py-3 font-black">Section Adviser</th>
                                 <th scope="col" class="px-3 py-3 font-black">Overall GWA</th>
                                 <th scope="col" class="px-3 py-3 font-black">Promotion Eligibility</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php foreach ($students as $studentId => $entry):
                            $student = $entry['student'];
                            $standing = $entry['standing'];
                            $isGraduate = (int) $student['GRADE'] === 12;
                            $isHonor = $standing['average'] !== null && (float) $standing['average'] >= 90.00;
                            $eligibility = $isGraduate ? 'Graduate / Archive'
                                : ($isHonor ? 'Promoted · Honor Section 1' : 'Promoted · Regular Section');
                            $displayName = trim(
                                ($student['LAST_NAME'] ?? '') . ', ' . ($student['FIRST_NAME'] ?? '') .
                                (!empty($student['MIDDLE_NAME']) ? ', ' . $student['MIDDLE_NAME'] : ''),
                                ', ',
                            ) ?: (string) ($student['STUDENT_NAME'] ?? '');
                        ?>
                        <tr class="align-top hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                            <td class="px-3 py-3">
                                <p class="font-bold text-slate-900 dark:text-white"><?= $escape($displayName) ?></p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Grade <?= (int) ($student['GRADE'] ?? $grade) ?><?= ($student['STRAND'] ?? '') !== '' ? ' · ' . $escape($student['STRAND']) : '' ?>
                                </p>
                            </td>
                            <td class="px-3 py-3 font-mono text-xs font-semibold text-slate-600 dark:text-slate-300"><?= $escape($studentId) ?></td>
                            <td class="px-3 py-3 font-semibold text-slate-700 dark:text-slate-200"><?= $escape($sectionAdviser) ?></td>
                            <td class="px-3 py-3 font-semibold text-slate-900 dark:text-white">
                                <?= $standing['average'] === null ? 'Not recorded' : $escape(number_format((float) $standing['average'], 2)) ?>
                            </td>
                            <td class="px-3 py-3"><span class="inline-flex items-center rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"><?= $escape($eligibility) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>

    </div>

    <!-- --------------------------------------------------------------------- -->
    <!-- Step 2 — Student Promotion Review                                     -->
    <!-- --------------------------------------------------------------------- -->
    <div id="step-2" class="step-section scroll-mt-24 mt-12 mb-6 rounded-r-2xl border border-l-4 border-l-emerald-600 border-slate-200 bg-emerald-50/50 p-5 dark:border-slate-800 dark:bg-emerald-900/20">
        <h2 class="text-2xl font-black text-emerald-900 dark:text-emerald-100">Step 2 · Student promotion review</h2>
        <p class="text-sm text-slate-500 mb-3">Promoted students are split into an honor section and evenly balanced
            regular sections. Grade 12 (Academic and Techpro) graduates are archived instead of sectioned.</p>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <?php
            $step2Cards = [
                'promoted' => ['Promoted', count($promoted)],
                'honor' => ['Honor Qualified', count($honor)],
                'regular' => ['Regular Students', count($regular)],
                'retained' => ['Retained', count($retained)],
                'remedial' => ['Remedial', count($remedial)],
                'graduated' => ['Graduated', count($graduated)],
            ];
            foreach ($step2Cards as $key => $card): ?>
            <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                <p class="text-xs text-slate-500"><?= $escape($card[0]) ?></p>
                <p class="mt-1 text-xl font-black text-slate-900 dark:text-white" data-summary="<?= $escape($key) ?>"><?= (int)$card[1] ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <form id="rollover-choices-form" method="post" action="school-year-rollover.php" class="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
            <?php foreach ($controls as $index => $source): ?>
            <input type="hidden" name="plans[<?= $index ?>][from_grade]" value="<?= (int)$source['from_grade'] ?>">
            <input type="hidden" name="plans[<?= $index ?>][from_strand]" value="<?= $escape($source['from_strand']) ?>">
            <?php foreach ($source['targets'] as $targetIndex => $target): ?>
            <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= $escape($target['strand']) ?>">
            <?php endforeach; endforeach; ?>
            <p class="text-sm text-blue-900">Choose Retained only for students who must repeat their current grade. Grade 10 students require an Academic or Techpro destination.</p>
        </form>

        <?php $step2Groups = [
            'promoted' => ['Promoted', 'Grades 7–11 advancing to the next grade.', $promoted],
            'honor' => ['Honor Qualified (GWA ≥ 90.00)', 'Assigned to Section 1 of the destination grade.', $honor],
            'regular' => ['Regular Students', 'Balanced evenly across Sections 2 and above.', $regular],
            'retained' => ['Retained', 'Students repeating their current grade by an approved retention decision.', $retained],
            'remedial' => ['Remedial', 'Students advancing with a Summer Class / Remedial obligation.', $remedial],
            'graduated' => ['Graduated & Archived', 'All Grade 12 Academic and Techpro students; excluded from next year sectioning.', $graduated],
        ]; ?>
        <?php foreach ($step2Groups as $key => $grp): [$title, $blurb, $items] = $grp; ?>
        <div class="mt-4 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="bg-slate-50 px-4 py-2.5 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700/50 flex items-center justify-between">
                <div>
                    <span class="font-bold text-slate-900 dark:text-white"><?= $escape($title) ?></span>
                    <p class="text-xs text-slate-500 mt-0.5"><?= $escape($blurb) ?></p>
                </div>
                <span class="font-bold text-sm"><?= number_format(count($items)) ?></span>
            </div>
            <div class="p-3">
                <?php if (count($items) === 0): ?>
                <p class="text-sm text-slate-500">None.</p>
                <?php else: ?>
                <?php $studentsByGrade = []; foreach ($items as $sid => $record) {
                    $studentsByGrade[(int) $record['student']['GRADE']][$sid] = $record;
                } ksort($studentsByGrade, SORT_NUMERIC); ?>
                <div class="space-y-3">
                <?php foreach ($studentsByGrade as $sourceGrade => $gradeStudents):
                    $gradePanelId = 'promotion-' . $key . '-grade-' . $sourceGrade;
                ?>
                <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                    <summary class="flex cursor-pointer list-none select-none items-center justify-between gap-3 px-6 py-4 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 dark:hover:bg-slate-800/50 dark:focus-visible:ring-blue-900" aria-controls="<?= $escape($gradePanelId) ?>">
                        <span class="flex items-center gap-3 text-lg font-bold text-slate-900 dark:text-slate-100">
                            <svg class="h-6 w-6 flex-none text-slate-700 transition-transform group-open:rotate-90 dark:text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                            Grade <?= (int) $sourceGrade ?>
                        </span>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            <?= number_format(count($gradeStudents)) ?> student<?= count($gradeStudents) === 1 ? '' : 's' ?>
                        </span>
                    </summary>
                    <div id="<?= $escape($gradePanelId) ?>" class="border-t border-slate-200 p-1 dark:border-slate-700">
                <div class="overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-700">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b border-slate-100 text-slate-500 dark:border-slate-800">
                            <th class="px-3 py-3">Student / LRN</th>
                            <th class="px-3 py-3">Grade / section</th>
                            <th class="px-3 py-3">Overall GWA</th>
                            <th class="px-3 py-3">Outcome</th>
                            <th class="px-3 py-3">Grade 11 strand</th>
                            <th class="px-3 py-3">Placement next year</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php foreach ($gradeStudents as $sid => $record): $s = $record['student']; $st = $record['standing']; ?>
                        <tr class="align-top hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                            <td class="px-3 py-3"><strong><?= htmlspecialchars($s['STUDENT_NAME']) ?></strong>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($sid) ?></div></td>
                            <td class="px-3 py-3">Grade <?= (int)$s['GRADE'] ?>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars(trim($s['STRAND'].' '.$s['SECTION'])) ?></div></td>
                            <td class="px-3 py-3">
                                <?= $st['average'] === null
                                    ? '<span class="text-slate-500">Not recorded</span>'
                                    : htmlspecialchars(number_format((float) $st['average'], 2, '.', '')) ?>
                            </td>
                            <td class="px-3 py-3">
                                <?php if (!empty($st['teacher_retained'])): ?>
                                <span class="rounded-full bg-rose-500/10 px-2 py-1 text-xs font-bold text-rose-700">Teacher flagged Retained</span>
                                <?php elseif (in_array($key, ['promoted','retained'], true) && (int) $s['GRADE'] < 12): ?>
                                <select form="rollover-choices-form" name="outcomes[<?= $escape($sid) ?>]" class="rounded border border-slate-300 bg-white px-2 py-1 text-xs">
                                    <option value="" <?= empty($choices['outcomes'][$sid]) ? 'selected' : '' ?>>Automatic outcome</option>
                                    <option value="Retained" <?= ($choices['outcomes'][$sid] ?? '') === 'Retained' ? 'selected' : '' ?>>Retained</option>
                                </select>
                                <?php else: ?><span class="text-slate-500"><?= $escape($st['decision']) ?></span><?php endif; ?>
                            </td>
                            <td class="px-3 py-3">
                                <?php if (in_array($key, ['promoted','retained'], true) && (int) $s['GRADE'] === 10): ?>
                                <select form="rollover-choices-form" name="strand_choices[<?= $escape($sid) ?>]" required class="rounded border border-slate-300 bg-white px-2 py-1 text-xs">
                                    <?php foreach (['Academic','Techpro'] as $strandChoice): ?><option value="<?= $strandChoice ?>" <?= ($choices['strand_choices'][$sid] ?? '') === $strandChoice ? 'selected' : '' ?>><?= $strandChoice ?></option><?php endforeach; ?>
                                </select>
                                <?php else: ?><span class="text-slate-500">—</span><?php endif; ?>
                            </td>
                            <td class="px-3 py-3">
                                <?php if ((int) $s['GRADE'] === 12): ?>
                                <span class="font-bold text-violet-700">Graduated / Archived</span>
                                <?php else: ?>
                                <?= $record['placement'] ? htmlspecialchars($record['placement']['SECTION'].' '.$record['placement']['STRAND']) : '<span class="text-slate-500">No section change</span>' ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                    </div>
                </details>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="mt-4 flex justify-end"><button form="rollover-choices-form" class="btn btn-secondary">Refresh outcomes and section preview</button></div>

        <?php if ($plan['reviews']): ?>
        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm">
            <p class="font-bold text-amber-800">Advisory notes</p>
            <p class="text-amber-700 mt-1"><?= count($plan['reviews']) ?> note<?= count($plan['reviews']) !== 1 ? 's' : '' ?> for administrator awareness. None of these notes block confirmation.</p>
            <ul class="mt-2 text-amber-700 list-disc pl-5 text-sm">
                <?php foreach ($plan['reviews'] as $review): ?>
                <li><?= htmlspecialchars(($review['name'] ?: 'Placement').': '.$review['reason']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- --------------------------------------------------------------------- -->
    <!-- Step 3 — Section Assignment Preview                                   -->
    <!-- --------------------------------------------------------------------- -->
    <div id="step-3" class="step-section scroll-mt-24 mt-12 mb-6 rounded-r-2xl border border-l-4 border-l-purple-600 border-slate-200 bg-purple-50/50 p-5 dark:border-slate-800 dark:bg-purple-900/20">
        <h2 class="text-2xl font-black text-purple-900 dark:text-purple-100">Step 3 · Section assignment preview</h2>
        <p class="text-sm text-slate-500 mb-3">Destination grades, sections, capacities and adviser assignments.
            Every student with a GWA of at least 90.00 is placed in Section 1. All remaining students are split
            <strong>evenly</strong> across Sections 2 and above using <code>chunkEvenly()</code>; no section is filled to
            capacity before the next one is populated. Academic and Techpro strands remain separate.</p>

        <?php
        $destinationGroups = [];
        foreach ($plan['destinations'] as $destination) {
            $destinationKey = (int) $destination['grade'] . '|' . (string) $destination['strand'];
            if (!isset($destinationGroups[$destinationKey])) {
                $destinationGroups[$destinationKey] = [
                    'grade' => (int) $destination['grade'],
                    'strand' => (string) $destination['strand'],
                    'assignments' => [],
                ];
            }
            foreach ($destination['groups'] as $group) {
                $destinationGroups[$destinationKey]['assignments'][] = $group;
            }
        }
        $destinationGroups = array_filter(
            $destinationGroups,
            static fn(array $destinationGroup): bool => count($destinationGroup['assignments']) > 0,
        );
        uasort($destinationGroups, static function (array $left, array $right): int {
            return $left['grade'] <=> $right['grade'] ?: strnatcasecmp($left['strand'], $right['strand']);
        });
        ?>
        <?php if (!$destinationGroups): ?>
        <p class="mt-4 rounded-xl border border-slate-200 px-4 py-3 text-center text-slate-500 dark:border-slate-700">No section assignments in this preview.</p>
        <?php else: ?>
        <div class="mt-4 space-y-4">
        <?php $destinationIndex = 0; foreach ($destinationGroups as $destinationGroup): $destinationIndex++;
            $destinationPanelId = 'destination-grade-' . $destinationIndex;
            $sectionCount = count($destinationGroup['assignments']);
        ?>
        <details class="group overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
            <summary class="flex cursor-pointer list-none select-none items-center justify-between gap-3 px-6 py-4 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 dark:hover:bg-slate-800/50 dark:focus-visible:ring-blue-900">
                <span class="flex items-center gap-3 text-lg font-bold text-slate-900 dark:text-slate-100">
                    <svg class="h-6 w-6 flex-none text-slate-700 transition-transform group-open:rotate-90 dark:text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                    Target: Grade <?= (int) $destinationGroup['grade'] ?><?= $destinationGroup['strand'] !== '' ? ' ' . $escape($destinationGroup['strand']) : '' ?>
                </span>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    <?= number_format($sectionCount) ?> Section<?= $sectionCount === 1 ? '' : 's' ?> Assigned
                </span>
            </summary>
            <div id="<?= $escape($destinationPanelId) ?>" class="overflow-x-auto border-t border-slate-200 dark:border-slate-700">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead>
                    <tr class="border-b border-slate-200 text-slate-500 dark:border-slate-800">
                        <th class="px-4 py-3">Section</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Capacity</th>
                        <th class="px-4 py-3">Staying</th>
                        <th class="px-4 py-3">Incoming</th>
                        <th class="px-4 py-3">Total / capacity</th>
                        <th class="px-4 py-3">Active teacher / adviser</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($destinationGroup['assignments'] as $group):
                        $section = $group['section'];
                        $incoming = count($group['students']);
                        $staying = (int) ($section['EXISTING_COUNT'] ?? 0);
                        $teacherKey = (int) $destinationGroup['grade'] . '|' . $destinationGroup['strand'] . '|' . $section['SECTION'];
                        $teacherLabel = $sectionAdvisers[$teacherKey] ?? '';
                    ?>
                    <tr class="align-top hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                        <td class="px-4 py-3 font-bold">
                            <?= htmlspecialchars($section['SECTION']) ?>
                            <?php if (!empty($section['VIRTUAL'])): ?><span class="ml-1 text-xs font-bold text-amber-700">New regular overflow</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><?= !empty($section['IS_HONOR']) ? '<span class="font-bold text-violet-700">Honor (90+)</span>' : '<span class="text-slate-600 dark:text-slate-300">Regular</span>' ?></td>
                        <td class="px-4 py-3"><?= sectionCapacity($section, $context['default'] ?? 40) ?></td>
                        <td class="px-4 py-3"><?= $staying ?></td>
                        <td class="px-4 py-3"><?= $incoming ?></td>
                        <td class="px-4 py-3 font-bold"><?= $incoming + $staying ?> / <?= sectionCapacity($section, $context['default'] ?? 40) ?></td>
                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?= $teacherLabel !== '' ? $escape($teacherLabel) : '<span class="text-slate-500">No section adviser assigned</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- --------------------------------------------------------------------- -->
    <!-- Step 4 — Final Rollover Confirmation                                  -->
    <!-- --------------------------------------------------------------------- -->
    <div id="step-4" class="step-section scroll-mt-24 mt-12 mb-6 rounded-r-2xl border border-l-4 border-l-rose-600 border-slate-200 bg-rose-50/50 p-5 dark:border-slate-800 dark:bg-rose-900/20">
        <h2 class="text-2xl font-black text-rose-900 dark:text-rose-100">Step 4 · Confirm rollover</h2>
        <p class="text-sm text-slate-500 mb-3">Review the summary below. Confirming promotes students, creates new
            sections, archives graduates and advances the school year. This action can be undoned from the rollover page.</p>

        <div class="mt-4 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
            <h4 class="font-bold text-slate-900 dark:text-white mb-2">Summary of changes</h4>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <?php
                $step4Cards = [
                    'promoted' => ['Promoted (Grades 7–11)', count($promoted)],
                    'honor' => ['Honor Qualified (Section 1)', count($honor)],
                    'regular' => ['Regular Students (Sections 2+)', count($regular)],
                    'graduated' => ['Graduated (Grade 12)', count($graduated)],
                ];
                foreach ($step4Cards as $key => $card): ?>
                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                    <p class="text-xs text-slate-500"><?= $escape($card[0]) ?></p>
                    <p class="mt-1 text-xl font-black text-slate-900 dark:text-white" data-summary="<?= $escape($key) ?>"><?= (int)$card[1] ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 text-xs text-slate-500">All promotions, honor/regular sectioning, Grade 12 archiving and the
                school-year advance are executed atomically in one SQL transaction. Historical grade, strand and
                section snapshots remain in <code>student_school_history</code> and the run audit tables for undo.</p>
        </div>

        <form id="rollover-form" action="run-school-year-rollover.php" method="post" class="mt-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>">
            <input type="hidden" name="preview_fingerprint" value="<?= $escape($plan['fingerprint'] ?? '') ?>">
            <?php foreach ($controls as $index => $source): ?>
            <input type="hidden" name="plans[<?= $index ?>][from_grade]" value="<?= (int)$source['from_grade'] ?>">
            <input type="hidden" name="plans[<?= $index ?>][from_strand]" value="<?= $escape($source['from_strand']) ?>">
            <?php foreach ($source['targets'] as $targetIndex => $target): ?>
            <input type="hidden" name="plans[<?= $index ?>][targets][<?= $targetIndex ?>][strand]" value="<?= $escape($target['strand']) ?>">
            <?php endforeach; endforeach; ?>
            <?php foreach ($plan['students'] as $studentId => $record): ?>
            <?php if (($choices['outcomes'][$studentId] ?? '') === 'Retained'): ?>
            <input type="hidden" name="outcomes[<?= $escape($studentId) ?>]" value="Retained">
            <?php endif; ?>
            <?php if ((int)$record['student']['GRADE'] === 10): ?>
                <input type="hidden" name="strand_choices[<?= $escape($studentId) ?>]" value="<?= $escape($choices['strand_choices'][$studentId] ?? '') ?>">
            <?php endif; ?>
            <?php endforeach; ?>

            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="font-bold text-rose-800">Confirmation safeguards</p>
                <label class="mt-3 flex items-center gap-2 text-sm font-bold">
                    <input type="checkbox" name="end_school_year_confirmation" value="1" required>
                    The school year has ended and I have reviewed all four steps.
                </label>
                <label class="mt-4 block text-sm font-bold">Administrator password
                    <input type="password" name="current_password" autocomplete="current-password" required class="mt-2 block w-full max-w-sm rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                </label>
            </div>

            <div class="flex items-center gap-3">
                <a href="school-year-rollover.php" class="btn btn-secondary">Back to preview</a>
                <button type="submit" data-run-rollover
                    <?= !$plan ? 'disabled' : '' ?>
                    class="btn btn-danger mt-0 disabled:cursor-not-allowed disabled:opacity-50">
                    Promote &amp; Auto-Section Students
                </button>
                <span class="text-xs text-slate-500">Reads the preview fingerprint and commits one transaction.</span>
            </div>
        </form>
    </div>
    <?php endif; ?>
</section>

<?php if ($context): ?>
<section class="card card-body mt-6 w-full flex-1 px-4 sm:px-6 lg:px-8">
    <h3 class="text-xl font-black text-slate-900 dark:text-white">Advisory section settings</h3>
    <p class="mt-3 text-sm text-slate-500">Choose the destination Section 1 for each grade and strand. Manage section advisers through Teachers.</p>
    <details class="card card-body mt-3 w-full">
        <summary class="cursor-pointer px-6 py-4 text-lg font-bold text-slate-900 dark:text-slate-100">Section 1 designation</summary>
        <form action="save-promotion-policy.php" method="post" class="mt-6 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>">
            <input type="hidden" name="action" value="honor">
            <p class="text-sm text-slate-500">One explicitly designated honor section per grade/strand. Junior-high
                designations: Amethyst, Peace, Aristotle. Senior high has no honor designation unless an administrator chooses one.</p>
            <label class="block text-sm font-bold">Honor section
                <select name="honor_section_id" class="mt-2 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900">
                    <?php foreach ($context['sections'] as $section): if (!(int)$section['IS_ACTIVE']) continue; ?>
                    <option value="<?= (int)$section['SECTION_ID'] ?>">Grade <?= (int)$section['GRADE'] ?> <?= htmlspecialchars($section['STRAND'].' | '.$section['SECTION']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-secondary">Confirm honor designation</button>
        </form>
    </details>
</section>
<?php endif; ?>

<?php if ($pastRolloverYears): ?>
<section class="card card-body mt-6 w-full flex-1 px-4 sm:px-6 lg:px-8">
    <details class="card card-body mt-3 w-full">
        <summary class="cursor-pointer px-6 py-4 text-lg font-bold text-slate-900 dark:text-slate-100">Undo the latest rollover</summary>
        <p class="mt-3 text-sm text-slate-500">Restores the previous year's saved placements. Grades and audit history
            remain preserved.</p>
        <form action="undo-school-year-rollover.php" method="post" class="mt-4 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="expected_school_year" value="<?= $escape($activeSchoolYear) ?>">
            <label class="block text-sm font-bold">Administrator password
                <input type="password" name="current_password" required autocomplete="current-password" class="mt-2 block w-full max-w-sm rounded-xl border border-slate-300 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
            </label>
            <button class="btn btn-secondary">Undo rollover</button>
        </form>
    </details>
</section>
<?php endif; ?>

<script>
    const scheduleToggleButton = document.getElementById('toggle-schedule-btn');
    const schedulePanel = document.getElementById('schedule-panel');

    if (scheduleToggleButton && schedulePanel) {
        const setSchedulePanel = (open, updateUrl = true) => {
            schedulePanel.hidden = !open;
            scheduleToggleButton.setAttribute('aria-expanded', String(open));
            if (!updateUrl) return;
            const url = new URL(window.location.href);
            if (open) url.searchParams.set('view', 'schedule');
            else url.searchParams.delete('view');
            history.replaceState(null, '', url);
        };

        scheduleToggleButton.addEventListener('click', () => {
            setSchedulePanel(schedulePanel.hidden);
        });
    }

    const stickyNav = document.getElementById('sticky-step-nav');
    const stickySentinel = document.getElementById('sticky-sentinel');
    const stepNavButtons = Array.from(document.querySelectorAll('.step-nav-btn'));
    const stepSections = Array.from(document.querySelectorAll('.step-section'));
    const topbar = document.querySelector('.app-topbar');

    if (stickyNav && stickySentinel && stepNavButtons.length && stepSections.length) {
        const fixedHeaderHeight = () => topbar ? topbar.getBoundingClientRect().height : 0;
        const stickyOffset = () => fixedHeaderHeight() + stickyNav.getBoundingClientRect().height + 16;

        const setActiveStep = (stepId) => {
            stepNavButtons.forEach((button) => {
                const isActive = button.dataset.target === '#' + stepId;
                button.setAttribute('aria-current', isActive ? 'step' : 'false');
                button.classList.toggle('bg-blue-600', isActive);
                button.classList.toggle('text-white', isActive);
            });
        };

        const stickyObserver = new IntersectionObserver(([entry]) => {
            const isPinned = !entry.isIntersecting;
            stickyNav.classList.toggle('shadow-md', isPinned);
        }, {
            rootMargin: `-${fixedHeaderHeight()}px 0px 0px 0px`,
            threshold: 0,
        });
        stickyObserver.observe(stickySentinel);

        stepNavButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.querySelector(button.dataset.target);
                if (!target) return;

                const destination = window.scrollY + target.getBoundingClientRect().top - stickyOffset();
                setActiveStep(target.id);
                window.scrollTo({ top: Math.max(0, destination), behavior: 'smooth' });
                history.replaceState(null, '', button.dataset.target);
            });
        });

        let stepObserver;
        const observeSteps = () => {
            stepObserver?.disconnect();
            const activationLine = Math.min(stickyOffset(), Math.max(0, window.innerHeight - 1));
            const lowerMargin = Math.max(0, window.innerHeight - activationLine - 1);
            stepObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) setActiveStep(entry.target.id);
                });
            }, {
                rootMargin: `-${activationLine}px 0px -${lowerMargin}px 0px`,
                threshold: 0,
            });
            stepSections.forEach((section) => stepObserver.observe(section));
        };

        observeSteps();
        window.addEventListener('resize', observeSteps);
        setActiveStep(window.location.hash.replace('#', '') || 'step-1');
    }

    // Step 1 section accordion. The chevron rotation is driven by aria-expanded
    // so the visual state and accessibility state never drift apart.
    function toggleSectionDropdown(button) {
        const panelId = button.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        if (!panel) return;
        const willOpen = panel.classList.contains('hidden');
        panel.classList.toggle('hidden', !willOpen);
        panel.hidden = !willOpen;
        button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    // A valid advisory-class plan remains available for confirmation.
    const runBtn = document.querySelector('[data-run-rollover]');
    if (runBtn) runBtn.disabled = false;
</script>
<?php adminFoot(); ?>
