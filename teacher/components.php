<?php
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/ui.php';

function teacherHead(string $title): void { ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> | Teacher portal</title>
    <script src="../js/theme.js?v=<?= (int) @filemtime(__DIR__ . '/../js/theme.js') ?>"></script>
    <link rel="stylesheet" href="../css/tailwind.css?v=<?= (int) @filemtime(__DIR__ . '/../css/tailwind.css') ?>">
    <link rel="stylesheet" href="../css/theme.css?v=<?= (int) @filemtime(__DIR__ . '/../css/theme.css') ?>">
    <style>
        :root { --maruhat-blue: #1f4f91; --maruhat-blue-deep: #102e5e; --maruhat-green: #27794a; --maruhat-gold: #d8a52d; }
        body { background: radial-gradient(circle at 92% -8%, rgb(216 165 45 / .14), transparent 28rem), radial-gradient(circle at 42% 0%, rgb(31 79 145 / .09), transparent 36rem), #f5f8f5 !important; }
        select { padding-right: 3rem !important; }
        main :is(a, button).bg-blue-600 { background-color: var(--maruhat-blue) !important; box-shadow: 0 1px 2px rgb(15 23 42 / .12); transition: background-color .15s, box-shadow .15s, transform .15s; }
        main :is(a, button).bg-blue-600:hover { background-color: var(--maruhat-blue-deep) !important; box-shadow: 0 8px 18px rgb(31 79 145 / .22); transform: translateY(-1px); }
        main :is(a, button).bg-blue-600:focus-visible { outline: 0; box-shadow: 0 0 0 4px rgb(191 219 254), 0 8px 18px rgb(37 99 235 / .18); }
        .table-filter-bar { border: 1px solid #d7e5da; border-radius: .5rem; background: linear-gradient(135deg, #fbfdfb, #f1f7f2); padding: 1rem; }
        .table-filter-control { min-width: 0; }
        .table-filter-control :is(input, select) { box-shadow: 0 1px 2px rgb(15 23 42 / .04); }
        .table-filter-reset { align-self: end; border: 1px solid #9fc7ad; border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; font-weight: 700; color: #21623e; background: #edf8f0; box-shadow: 0 1px 2px rgb(39 121 74 / .08); transition: background-color .15s, box-shadow .15s; }
        .table-filter-reset:hover { background: #dff2e4; box-shadow: 0 4px 10px rgb(39 121 74 / .14); }
        .table-filter-reset:focus-visible { outline: 0; box-shadow: 0 0 0 4px #bfdbfe; }
        [data-teacher-nav] { background: linear-gradient(165deg, var(--maruhat-blue-deep), var(--maruhat-blue) 58%, #17396e) !important; border-right: 3px solid var(--maruhat-gold); }
        [data-teacher-nav] a.bg-blue-600 { background: linear-gradient(100deg, #2d6fbd, var(--maruhat-green)) !important; box-shadow: inset 4px 0 var(--maruhat-gold), 0 10px 20px rgb(7 28 63 / .3) !important; }
        [data-teacher-nav] a:not(.bg-blue-600):hover { background: rgb(255 255 255 / .11) !important; }
        [data-teacher-nav] img { box-shadow: 0 0 0 2px rgb(216 165 45 / .72), 0 8px 18px rgb(0 0 0 / .25); }
        main { min-height: 100vh; background: radial-gradient(circle at 100% 14%, rgb(216 165 45 / .22), transparent 22rem), radial-gradient(circle at 8% 88%, rgb(39 121 74 / .18), transparent 28rem), linear-gradient(135deg, #e8f1fd 0%, #eef8f0 50%, #fff8e7 100%); }
        main > header { position: relative; border-bottom-color: #c8dceb !important; box-shadow: 0 3px 14px rgb(31 79 145 / .06); }
        main > header::after { position: absolute; right: 0; bottom: -2px; left: 0; height: 2px; content: ''; background: linear-gradient(90deg, var(--maruhat-blue), var(--maruhat-green), var(--maruhat-gold)); }
        @media (min-width: 1024px) {
            [data-teacher-nav] { transition: transform .2s ease, width .2s ease, padding .2s ease; }
            [data-teacher-nav].desktop-collapsed { width: 0 !important; padding-left: 0 !important; padding-right: 0 !important; overflow: hidden !important; }
            [data-teacher-nav].desktop-collapsed > * { opacity: 0; pointer-events: none; }
        }
        @media (max-width: 639px) {
            main > header p { line-height: 1.35; }
            .table-filter-bar { padding: .875rem; }
            .table-filter-bar > div:first-child { align-items: stretch; flex-direction: column; }
            .table-filter-reset { width: 100%; }
            main .overflow-x-auto { -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
            main .overflow-x-auto > table { min-width: 760px; }
        }
    </style>
</head>
<body class="overflow-x-hidden bg-slate-50 text-slate-800">
<?php }

function teacherShell(string $title, string $actions = ''): void {
    $page = basename($_SERVER['PHP_SELF']);
    $assignmentLabels = [];
    $toast = $_SESSION['toast'] ?? null;
    unset($_SESSION['toast']);
    global $conn;

    if (isset($conn) && $conn instanceof mysqli && function_exists('teacherAssignments')) {
        foreach (teacherAssignments($conn, (int) ($_SESSION['teacher-id'] ?? 0)) as $assignment) {
            $detail = in_array((int) $assignment['GRADE'], [11, 12], true) ? $assignment['STRAND'] : $assignment['SUBJECT'];
            $assignmentLabels[] = 'Grade ' . $assignment['GRADE'] . ' | ' . $assignment['SECTION'] . ($detail ? ' · ' . $detail : '');
        }
    }

    if (!$assignmentLabels && ($_SESSION['teacher-section'] ?? '') !== '') {
        $assignmentLabels[] = $_SESSION['teacher-section'];
    }

    $pageDescription = $page === 'home.php'
        ? 'Review recorded attendance and approved attendance exceptions.'
        : ($page === 'students.php'
            ? 'View students in your assigned grades, sections, and subjects.'
            : ($page === 'settings.php'
                ? 'Manage your password and biometric sign-in.'
                : 'Review the selected student profile and attendance history.'));

    if ($page === 'home.php') {
        $crumbs = [['label' => 'Students', 'href' => 'students.php'], ['label' => 'Attendance logs']];
    } elseif ($page === 'settings.php') {
        $crumbs = [['label' => 'Students', 'href' => 'students.php'], ['label' => 'Settings']];
    } elseif ($page === 'student-profile.php') {
        $crumbs = [['label' => 'Students', 'href' => 'students.php'], ['label' => $title]];
    } else {
        $crumbs = [['label' => 'Students']];
    }
?>
<div class="lg:flex">
    <aside data-teacher-nav class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col overflow-y-auto bg-slate-950 p-4 text-slate-200 transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <a href="students.php" class="mb-6 flex items-center gap-3 px-2 text-base font-bold text-white">
            <img src="../image/logo.png" alt="Maruhat National High School logo" class="h-9 w-9 rounded-lg bg-white object-contain p-0.5 shadow-sm">
            <span>Biometric<span class="text-blue-400">.</span><span class="block text-[11px] font-normal leading-tight text-slate-400">Teacher portal</span></span>
        </a>

        <nav class="space-y-1" aria-label="Teacher navigation">
            <a href="students.php" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold <?= in_array($page, ['students.php', 'student-profile.php'], true) ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
                <?= icon('users') ?>Students
            </a>
            <a href="home.php" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold <?= $page === 'home.php' ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
                <?= icon('clipboard-list') ?>Attendance logs
            </a>
            <a href="settings.php" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold <?= $page === 'settings.php' ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
                <?= icon('settings') ?>Settings
            </a>
        </nav>

        <div class="mt-auto rounded-lg border border-slate-800 bg-slate-800/60 p-4">
            <p class="text-sm font-semibold text-white"><?= htmlspecialchars($_SESSION['teacher-name']) ?></p>
            <p class="mt-2 text-[11px] font-semibold uppercase tracking-widest text-slate-400">Assigned sections and subjects</p>
            <div class="mt-2 space-y-1">
                <?php foreach ($assignmentLabels as $assignmentLabel): ?><p class="text-xs text-slate-300"><?= htmlspecialchars($assignmentLabel) ?></p><?php endforeach; ?>
            </div>
        </div>

        <a href="../common/logout.php" class="mt-3 flex items-center gap-3 rounded-lg border-t border-slate-800/80 px-3 pb-2 pt-4 text-sm font-medium text-slate-400 transition hover:bg-slate-800 hover:text-white">
            <?= icon('log-out') ?><span>Sign out</span>
        </a>
    </aside>

    <div data-teacher-overlay class="fixed inset-0 z-30 hidden bg-slate-950/50 lg:hidden"></div>

    <main class="min-w-0 flex-1">
        <?php ui_topbar($crumbs, $pageDescription, $actions, 'data-teacher-menu'); ?>

        <div class="mx-auto max-w-6xl p-5 lg:p-7">
            <?php if ($toast): ?>
            <div data-auto-toast class="fixed bottom-5 right-5 z-50 rounded-lg px-5 py-4 font-bold text-white shadow-xl <?= ($toast['type'] ?? 'success') === 'error' ? 'bg-rose-600' : 'bg-emerald-600' ?>" role="status"><?= htmlspecialchars($toast['message'] ?? '') ?></div>
            <?php endif; ?>
<?php }

function teacherFoot(): void { ?>
        </div>
    </main>
</div>
<script>
const teacherNav = document.querySelector('[data-teacher-nav]');
const teacherOverlay = document.querySelector('[data-teacher-overlay]');
const teacherMenu = document.querySelector('[data-teacher-menu]');
const teacherDesktop = window.matchMedia('(min-width: 1024px)');
const teacherMenuStorageKey = 'biometric-teacher-navigation-collapsed';

function setTeacherMenuButtonState(open) {
    teacherMenu?.setAttribute('aria-expanded', String(open));
    teacherMenu?.setAttribute('title', open ? 'Hide navigation' : 'Show navigation');
    teacherMenu?.setAttribute('aria-label', open ? 'Hide navigation' : 'Show navigation');
}

function savedTeacherNavigationIsCollapsed() {
    try { return localStorage.getItem(teacherMenuStorageKey) === 'true'; } catch (_) { return false; }
}

function saveTeacherNavigation(collapsed) {
    try { localStorage.setItem(teacherMenuStorageKey, String(collapsed)); } catch (_) { /* Browser storage is optional. */ }
}

function setTeacherMenu(open) {
    if (teacherDesktop.matches) {
        teacherNav?.classList.toggle('desktop-collapsed', !open);
        teacherOverlay?.classList.add('hidden');
        saveTeacherNavigation(!open);
        setTeacherMenuButtonState(open);
        return;
    }
    teacherNav?.classList.remove('desktop-collapsed');
    teacherNav?.classList.toggle('-translate-x-full', !open);
    teacherOverlay?.classList.toggle('hidden', !open);
    setTeacherMenuButtonState(open);
}

function syncTeacherNavigationForViewport() {
    if (teacherDesktop.matches) {
        const open = !savedTeacherNavigationIsCollapsed();
        teacherNav?.classList.toggle('desktop-collapsed', !open);
        teacherOverlay?.classList.add('hidden');
        setTeacherMenuButtonState(open);
        return;
    }
    teacherNav?.classList.remove('desktop-collapsed');
    teacherNav?.classList.add('-translate-x-full');
    teacherOverlay?.classList.add('hidden');
    setTeacherMenuButtonState(false);
}

syncTeacherNavigationForViewport();
teacherMenu?.addEventListener('click', () => {
    const open = teacherDesktop.matches
        ? teacherNav?.classList.contains('desktop-collapsed')
        : teacherNav?.classList.contains('-translate-x-full');
    setTeacherMenu(Boolean(open));
});
teacherOverlay?.addEventListener('click', () => setTeacherMenu(false));
teacherDesktop.addEventListener('change', syncTeacherNavigationForViewport);
document.addEventListener('keydown', event => { if (event.key === 'Escape') setTeacherMenu(false); });
const teacherClock = document.getElementById('clock');
if (teacherClock) {
    const tick = () => {
        teacherClock.textContent = new Date().toLocaleString([], {
            dateStyle: 'medium',
            timeStyle: 'short'
        });
    };
    tick();
    setInterval(tick, 60000);
}
document.querySelectorAll('[data-auto-toast]').forEach(toast => {
    setTimeout(() => toast.remove(), 4500);
});
</script>
</body>
</html>
<?php }
