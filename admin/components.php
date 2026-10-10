<?php
function adminHead(string $title): void { ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> | Biometric</title>
    <link rel="stylesheet" href="../css/tailwind.css">
    <style>
        select { padding-right: 3rem !important; }
        a[href="add-student.php"]::before { content: '+'; margin-right: .4rem; font-size: 1.1em; }
        main > header h1 { font-size: 1.5rem !important; font-weight: 900 !important; line-height: 1.2 !important; }
        main :is(a, button).bg-blue-600 { box-shadow: 0 1px 2px rgb(15 23 42 / .12); transition: background-color .15s, box-shadow .15s, transform .15s; }
        main :is(a, button).bg-blue-600:hover { box-shadow: 0 8px 18px rgb(37 99 235 / .18); transform: translateY(-1px); }
        main :is(a, button).bg-blue-600:focus-visible { outline: 0; box-shadow: 0 0 0 4px rgb(191 219 254), 0 8px 18px rgb(37 99 235 / .18); }
        main #open-add-section, main input[name="action"][value="security_question"] ~ button { background: #2563eb !important; }
        main #open-add-section:hover, main input[name="action"][value="security_question"] ~ button:hover { background: #1d4ed8 !important; }
        .table-filter-bar { border: 1px solid #e2e8f0; border-radius: 1rem; background: #f8fafc; padding: 1rem; }
        .table-filter-control { min-width: 0; }
        .table-filter-control :is(input, select) { box-shadow: 0 1px 2px rgb(15 23 42 / .04); }
        .table-filter-reset { align-self: end; border: 1px solid #93c5fd; border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; font-weight: 700; color: #1d4ed8; background: #eff6ff; box-shadow: 0 1px 2px rgb(37 99 235 / .08); transition: background-color .15s, box-shadow .15s; }
        .table-filter-reset:hover { background: #dbeafe; box-shadow: 0 4px 10px rgb(37 99 235 / .12); }
        .table-filter-reset:focus-visible { outline: 0; box-shadow: 0 0 0 4px #bfdbfe; }
        .portal-menu-button { transition: background-color .15s, border-color .15s, color .15s; }
        .portal-menu-button:hover { border-color: #93c5fd; background: #eff6ff; color: #1d4ed8; }
        @media (min-width: 1024px) {
            [data-admin-nav] { transition: transform .2s ease, width .2s ease, padding .2s ease; }
            [data-admin-nav].desktop-collapsed { width: 0 !important; padding-left: 0 !important; padding-right: 0 !important; overflow: hidden !important; }
            [data-admin-nav].desktop-collapsed > * { opacity: 0; pointer-events: none; }
        }
        @media (max-width: 639px) {
            main > header { padding: 1rem; }
            main > header h1 { font-size: 1.25rem !important; }
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

function adminSidebar(string $active, string $title, string $subtitle = ''): void {
    $items = [
        ['home.php', 'Dashboard', 'dashboard', '&#8962;'],
        ['student-list.php', 'Students', 'students', '&#9817;'],
        ['teacher-list.php', 'Teachers', 'teachers', '&#9831;'],
        ['attendance-log.php', 'Attendance logs', 'attendance', '&#9719;'],
        ['settings.php', 'Settings', 'settings', '&#9881;'],
        ['school-year-rollover.php', 'School year', 'rollover', 'SY'],
    ];
    $section = $active === 'teachers' ? 'Teachers' : ($active === 'students' ? 'Students' : ($active === 'attendance' ? 'Attendance' : ($active === 'rollover' ? 'School year' : ($active === 'settings' ? 'Settings' : 'Dashboard'))));
    $toast = $_SESSION['toast']['message'] ?? null;
    $toastType = $_SESSION['toast']['type'] ?? 'success';
    $toastClass = $toastType === 'error' ? 'bg-rose-600' : 'bg-emerald-600';
    unset($_SESSION['toast']);
?>
<div class="lg:flex">
    <aside data-admin-nav class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-y-auto bg-slate-950 p-5 text-slate-200 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <a href="home.php" class="mb-9 flex items-center gap-3 px-2 text-lg font-bold text-white">
            <img src="../image/logo.png" alt="Maruhat National High School logo" class="h-10 w-10 rounded-xl bg-white object-contain p-0.5 shadow-sm">
            <span>Biometric<span class="text-blue-400">.</span><span class="block text-xs font-normal text-slate-400">Attendance system</span></span>
        </a>
        <nav class="space-y-1" aria-label="Admin navigation">
            <?php foreach ($items as [$url, $label, $key, $icon]): ?>
                <a href="<?= $url ?>" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition <?= $active === $key ? 'bg-blue-600 text-white shadow-lg shadow-blue-900/30' : 'text-slate-300 hover:bg-slate-800 hover:text-white' ?>">
                    <span class="grid h-6 w-6 place-items-center text-lg" aria-hidden="true"><?= $icon ?></span>
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <a href="../common/logout.php" class="mt-auto flex items-center gap-3 rounded-xl border border-rose-400/40 bg-rose-500/10 px-4 py-3 text-sm font-bold text-rose-200 transition hover:bg-rose-500 hover:text-white">
            <span aria-hidden="true">&#8618;</span>Sign out
        </a>
    </aside>

    <div data-admin-overlay class="fixed inset-0 z-30 hidden bg-slate-950/50 lg:hidden"></div>

    <main class="min-w-0 flex-1">
        <header class="border-b border-slate-200 bg-white px-5 py-4 lg:px-8">
            <div class="flex items-center gap-4">
                <button data-admin-menu type="button" class="portal-menu-button rounded-lg border border-slate-200 p-2 font-bold" aria-label="Toggle navigation" aria-expanded="true" title="Hide navigation">&#9776;</button>
                <div class="min-w-0 flex-1">
                    <?php if ($active !== 'dashboard'): ?>
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-400">
                            <a href="home.php" class="hover:text-blue-600">Dashboard</a>
                            <span>/</span>
                            <span class="text-slate-600"><?= htmlspecialchars($section) ?></span>
                            <?php if ($title !== $section): ?>
                                <span>/</span>
                                <span class="text-slate-600"><?= htmlspecialchars($title) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <h1 class="mt-1 truncate text-xl font-bold text-slate-900"><?= htmlspecialchars($title) ?></h1>
                    <?php if ($subtitle): ?><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
                </div>
                <div class="hidden text-right text-sm text-slate-500 sm:block"><span id="clock"></span></div>
            </div>
            <?php if ($active !== 'dashboard'): ?><a href="javascript:history.back()" class="mt-3 inline-flex items-center gap-1 text-sm font-bold text-blue-600 hover:text-blue-700">&larr; Back</a><?php endif; ?>
        </header>

        <div class="mx-auto max-w-7xl p-5 lg:p-8">
            <?php if ($toast): ?>
                <div data-auto-toast class="fixed bottom-5 right-5 z-50 rounded-xl <?= $toastClass ?> px-5 py-4 font-bold text-white shadow-xl transition-all duration-300" role="status"><?= htmlspecialchars($toast) ?></div>
            <?php endif; ?>
<?php }

function adminFoot(): void { ?>
        </div>
    </main>
</div>
<script>
const nav = document.querySelector('[data-admin-nav]');
const overlay = document.querySelector('[data-admin-overlay]');
const menuButton = document.querySelector('[data-admin-menu]');
const adminDesktop = window.matchMedia('(min-width: 1024px)');
const adminMenuStorageKey = 'biometric-admin-navigation-collapsed';

function setMenuButtonState(open) {
    menuButton?.setAttribute('aria-expanded', String(open));
    menuButton?.setAttribute('title', open ? 'Hide navigation' : 'Show navigation');
    menuButton?.setAttribute('aria-label', open ? 'Hide navigation' : 'Show navigation');
}

function savedAdminNavigationIsCollapsed() {
    try { return localStorage.getItem(adminMenuStorageKey) === 'true'; } catch (_) { return false; }
}

function saveAdminNavigation(collapsed) {
    try { localStorage.setItem(adminMenuStorageKey, String(collapsed)); } catch (_) { /* Browser storage is optional. */ }
}

function toggleNavigation(open) {
    if (adminDesktop.matches) {
        nav?.classList.toggle('desktop-collapsed', !open);
        overlay?.classList.add('hidden');
        saveAdminNavigation(!open);
        setMenuButtonState(open);
        return;
    }
    nav?.classList.remove('desktop-collapsed');
    nav?.classList.toggle('-translate-x-full', !open);
    overlay?.classList.toggle('hidden', !open);
    setMenuButtonState(open);
}

function syncNavigationForViewport() {
    if (adminDesktop.matches) {
        const open = !savedAdminNavigationIsCollapsed();
        nav?.classList.toggle('desktop-collapsed', !open);
        overlay?.classList.add('hidden');
        setMenuButtonState(open);
        return;
    }
    nav?.classList.remove('desktop-collapsed');
    nav?.classList.add('-translate-x-full');
    overlay?.classList.add('hidden');
    setMenuButtonState(false);
}

syncNavigationForViewport();
menuButton?.addEventListener('click', () => {
    const open = adminDesktop.matches
        ? nav?.classList.contains('desktop-collapsed')
        : nav?.classList.contains('-translate-x-full');
    toggleNavigation(Boolean(open));
});
overlay?.addEventListener('click', () => toggleNavigation(false));
adminDesktop.addEventListener('change', syncNavigationForViewport);
document.addEventListener('keydown', event => { if (event.key === 'Escape') toggleNavigation(false); });

const clock = document.getElementById('clock');
if (clock) {
    const tick = () => { clock.textContent = new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); };
    tick();
    setInterval(tick, 60000);
}

document.querySelectorAll('[data-auto-toast]').forEach(toast => {
    setTimeout(() => {
        toast.classList.add('translate-y-3', 'opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
});

const studentArchiveButton = document.getElementById('archive-button');
const studentArchiveForm = document.getElementById('archive-form');
if (studentArchiveButton && studentArchiveForm) {
    const modal = document.createElement('div');
    modal.id = 'archive-student-modal';
    modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5';
    modal.innerHTML = '<div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"><h3 class="text-xl font-black text-slate-900">Archive student profile?</h3><p class="mt-2 text-sm text-slate-500">The profile, image, and attendance history will be retained. The student will no longer be able to record attendance.</p><div class="mt-6 flex gap-3"><button type="button" id="cancel-archive-student" class="flex-1 rounded-xl border border-slate-300 px-4 py-3 font-bold">Cancel</button><button type="button" id="confirm-archive-student" class="flex-1 rounded-xl bg-amber-600 px-4 py-3 font-bold text-white">Archive</button></div></div>';
    document.body.appendChild(modal);
    studentArchiveButton.onclick = () => modal.classList.replace('hidden', 'flex');
    document.getElementById('cancel-archive-student').onclick = () => modal.classList.replace('flex', 'hidden');
    document.getElementById('confirm-archive-student').onclick = () => studentArchiveForm.submit();
}
</script>
</body>
</html>
<?php }
