<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
require 'components.php';

ensureAttendanceTimeOutColumn($conn);
syncAutomaticAbsences($conn);

$today = date('Y-m-d');
$totalStudents = (int) ($conn->query('SELECT COUNT(*) AS total FROM students')->fetch_assoc()['total'] ?? 0);

$timeInStatement = $conn->prepare('SELECT COUNT(DISTINCT STUDENT_ID) AS total FROM attendance WHERE DATE(TIME_IN)=?');
$timeInStatement->bind_param('s', $today);
$timeInStatement->execute();
$todayCount = (int) ($timeInStatement->get_result()->fetch_assoc()['total'] ?? 0);

$timeOutStatement = $conn->prepare('SELECT COUNT(DISTINCT STUDENT_ID) AS total FROM attendance WHERE DATE(TIME_IN)=? AND TIME_OUT IS NOT NULL');
$timeOutStatement->bind_param('s', $today);
$timeOutStatement->execute();
$timeOutCount = (int) ($timeOutStatement->get_result()->fetch_assoc()['total'] ?? 0);

$stillCheckedIn = max(0, $todayCount - $timeOutCount);
$attendanceRate = $totalStudents ? round($todayCount / $totalStudents * 100) : 0;
$completionRate = $todayCount ? round($timeOutCount / $todayCount * 100) : 0;

adminHead('Dashboard');
adminSidebar('dashboard', 'Dashboard', 'Your attendance overview for today');
?>
<section class="maruhat-dashboard-hero mb-7">
    <p class="maruhat-eyebrow text-xs font-black uppercase">Maruhat National High School</p>
    <h2 class="mt-3 text-3xl font-black sm:text-4xl">Good day, administrator.</h2>
    <p class="mt-3 max-w-xl text-sm leading-6 text-blue-50">Your attendance overview for <?= htmlspecialchars(date('l, F j, Y')) ?>. Keep every school day organized, accurate, and ready for review.</p>
</section>

<section class="grid gap-5 md:grid-cols-3">
    <article class="maruhat-stat rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm font-bold text-slate-500">Registered students</p>
        <p id="stat-total" class="mt-3 text-4xl font-black text-slate-900"><?= $totalStudents ?></p>
        <p class="mt-2 text-sm text-slate-500">Student profiles in the system</p>
    </article>
    <article class="maruhat-stat rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm font-bold text-emerald-700">Today's time ins</p>
        <p id="stat-today" class="mt-3 text-4xl font-black text-slate-900"><?= $todayCount ?></p>
        <p class="mt-2 text-sm text-slate-500">Unique students who arrived today</p>
    </article>
    <article class="maruhat-stat rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <p class="text-sm font-bold text-amber-700">Today's time outs</p>
        <p id="stat-time-out" class="mt-3 text-4xl font-black text-slate-900"><?= $timeOutCount ?></p>
        <p class="mt-2 text-sm text-slate-500">Students with completed attendance</p>
    </article>
</section>

<section class="mt-7 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-black text-slate-900">Today's attendance statistics</h2>
            <p class="mt-1 text-sm text-slate-500">A quick summary for <?= htmlspecialchars(date('F j, Y')) ?>.</p>
        </div>
        <a href="attendance-log.php" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">View attendance logs</a>
    </div>

    <div class="mt-6 grid gap-5 md:grid-cols-3">
        <article class="rounded-xl border border-blue-100 bg-blue-50 p-5">
            <p class="text-sm font-bold text-blue-700">Attendance rate</p>
            <p class="mt-2 text-3xl font-black text-blue-950"><?= $attendanceRate ?>%</p>
            <p class="mt-2 text-sm text-blue-800"><?= $todayCount ?> of <?= $totalStudents ?> students checked in</p>
        </article>
        <article class="rounded-xl border border-amber-100 bg-amber-50 p-5">
            <p class="text-sm font-bold text-amber-700">Still checked in</p>
            <p class="mt-2 text-3xl font-black text-amber-950"><?= $stillCheckedIn ?></p>
            <p class="mt-2 text-sm text-amber-800">Time in recorded, no time out yet</p>
        </article>
        <article class="rounded-xl border border-emerald-100 bg-emerald-50 p-5">
            <p class="text-sm font-bold text-emerald-700">Attendance completed</p>
            <p class="mt-2 text-3xl font-black text-emerald-950"><?= $completionRate ?>%</p>
            <p class="mt-2 text-sm text-emerald-800"><?= $timeOutCount ?> checked out after time in</p>
        </article>
    </div>
</section>

<script>
    function refreshDashboardStatistics() {
        fetch('get-stats.php', { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                document.getElementById('stat-total').textContent = data.totalStudents;
                document.getElementById('stat-today').textContent = data.attendanceToday;
                document.getElementById('stat-time-out').textContent = data.timeOutToday;
            })
            .catch(() => {});
    }

    setInterval(refreshDashboardStatistics, 5000);
</script>
<?php adminFoot(); ?>
