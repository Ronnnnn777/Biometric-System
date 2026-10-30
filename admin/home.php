<?php
require 'page-guard.php';
require '../config/db_conn.php';
require '../includes/attendance-schema.php';
require '../includes/mobile-scanner-settings.php';
require 'components.php';

ensureAttendanceTimeOutColumn($conn);
$authorizedMobileScanners = authorizedMobileScanners($conn);
$activeAuthorizedMobileScanners = array_values(array_filter($authorizedMobileScanners, fn($scanner) => (int) ($scanner['IS_ENABLED'] ?? 1) === 1));

$today = date('Y-m-d');
$totalStudents = (int) ($conn->query("SELECT COUNT(*) AS total FROM students WHERE COALESCE(NULLIF(ENROLLMENT_STATUS,''),'Enrolled')<>'Archived'")->fetch_assoc()['total'] ?? 0);

$timeInStatement = $conn->prepare("SELECT COUNT(DISTINCT STUDENT_ID) AS total FROM attendance WHERE DATE(TIME_IN)=? AND COALESCE(ATTENDANCE_REMARK,'')<>'Absent'");
$timeInStatement->bind_param('s', $today);
$timeInStatement->execute();
$todayCount = (int) ($timeInStatement->get_result()->fetch_assoc()['total'] ?? 0);

$timeOutStatement = $conn->prepare("SELECT COUNT(DISTINCT STUDENT_ID) AS total FROM attendance WHERE DATE(TIME_IN)=? AND TIME_OUT IS NOT NULL AND COALESCE(ATTENDANCE_REMARK,'')<>'Absent'");
$timeOutStatement->bind_param('s', $today);
$timeOutStatement->execute();
$timeOutCount = (int) ($timeOutStatement->get_result()->fetch_assoc()['total'] ?? 0);

$stillCheckedIn = max(0, $todayCount - $timeOutCount);
$attendanceRate = $totalStudents ? round($todayCount / $totalStudents * 100) : 0;
$completionRate = $todayCount ? round($timeOutCount / $todayCount * 100) : 0;

adminHead('Dashboard');
adminSidebar('dashboard', 'Dashboard', 'Your attendance overview for today');
?>
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

<section class="grid gap-5 md:grid-cols-3">
    <article class="maruhat-stat card card-body">
        <p class="text-sm font-bold text-slate-500">Active students</p>
        <p id="stat-total" class="mt-3 text-4xl font-black text-slate-900"><?= $totalStudents ?></p>
        <p class="card-muted mt-2">Non-archived student profiles</p>
    </article>
    <article class="maruhat-stat card card-body">
        <p class="text-sm font-bold text-emerald-700">Today's time ins</p>
        <p id="stat-today" class="mt-3 text-4xl font-black text-slate-900"><?= $todayCount ?></p>
        <p class="card-muted mt-2">Unique students who arrived today</p>
    </article>
    <article class="maruhat-stat card card-body">
        <p class="text-sm font-bold text-amber-700">Today's time outs</p>
        <p id="stat-time-out" class="mt-3 text-4xl font-black text-slate-900"><?= $timeOutCount ?></p>
        <p class="card-muted mt-2">Students with completed attendance</p>
    </article>
</section>

<section id="mobile-scanner-card" class="card mt-7 p-6 shadow-sm ring-2 ring-[#102e5e] sm:p-8"></section>

<script>
    const authorizedPhoneScanners = <?= json_encode(array_map(fn($scanner) => ['name' => $scanner['SCANNER_NAME'], 'id' => $scanner['SCANNER_ID'], 'enabled' => (int) ($scanner['IS_ENABLED'] ?? 1) === 1], $authorizedMobileScanners), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const dashboardCsrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
    const renderedActiveScannerCount = authorizedPhoneScanners.filter(scanner => scanner.enabled).length;
    const mobileScannerCard = document.getElementById('mobile-scanner-card');
    const smartphoneIcon = <?= json_encode(icon('smartphone')) ?>;
    if (mobileScannerCard) {
        mobileScannerCard.innerHTML = `
            <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-widest text-[#1f4f91]">Mobile scanners</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-900">Registered scanner phones</h2>
                    <p id="mobile-scanner-copy" class="mt-2 max-w-xl text-sm leading-6 text-slate-500">A phone appears here automatically the first time it connects to MNHS Attendance.</p>
                </div>
                <div class="flex items-center gap-3 rounded-2xl bg-blue-50 px-5 py-4 ring-1 ring-blue-100">
                    <div class="grid h-12 w-12 place-items-center rounded-xl bg-emerald-100 text-emerald-700">${smartphoneIcon}</div>
                    <p id="mobile-scanner-status" class="whitespace-nowrap text-base font-black text-emerald-700"><?= count($activeAuthorizedMobileScanners) === 1 ? '1 active scanner' : count($activeAuthorizedMobileScanners) . ' active scanners' ?></p>
                </div>
            </div><div id="authorized-phone-list" class="mt-6 grid gap-3 md:grid-cols-2"></div><div id="authorized-phone-pagination" class="mt-5 flex items-center justify-center gap-3"></div>`;
        const phoneList = document.getElementById('authorized-phone-list');
        const phonePagination = document.getElementById('authorized-phone-pagination');
        if (phoneList) {
            const scannersPerPage = 4;
            let scannerPage = 1;
            const renderAuthorizedPhones = () => {
                phoneList.replaceChildren();
                phonePagination?.replaceChildren();
                if (!authorizedPhoneScanners.length) {
                    phoneList.textContent = 'No authorized phones added yet.';
                    return;
                }

                const totalPages = Math.ceil(authorizedPhoneScanners.length / scannersPerPage);
                scannerPage = Math.min(Math.max(scannerPage, 1), totalPages);
                const start = (scannerPage - 1) * scannersPerPage;
                authorizedPhoneScanners.slice(start, start + scannersPerPage).forEach(scanner => {
                const phone = document.createElement('div');
                phone.className = 'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4';

                const details = document.createElement('div');
                details.className = 'flex min-w-0 items-center gap-3';
                const phoneIcon = document.createElement('div');
                phoneIcon.className = 'grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-blue-700';
                phoneIcon.setAttribute('aria-hidden', 'true');
                phoneIcon.innerHTML = smartphoneIcon;
                const copy = document.createElement('div');
                copy.className = 'min-w-0';
                const name = document.createElement('p');
                name.className = 'font-black text-slate-900';
                name.textContent = scanner.name;
                const id = document.createElement('p');
                id.className = 'mt-1 text-xs font-bold text-slate-500';
                id.textContent = scanner.id;
                copy.append(name, id);
                details.append(phoneIcon, copy);

                const controls = document.createElement('div');
                controls.className = 'flex items-center gap-3';
                const status = document.createElement('span');
                status.className = scanner.enabled
                    ? 'rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700'
                    : 'rounded-full bg-slate-200 px-3 py-1 text-xs font-bold text-slate-600';
                status.textContent = scanner.enabled ? 'Active' : 'Disabled';
                controls.appendChild(status);

                const form = document.createElement('form');
                form.method = 'post';
                form.action = scanner.enabled ? 'disable-mobile-scanner.php' : 'activate-mobile-scanner.php';
                if (scanner.enabled) {
                    form.dataset.confirmDisable = 'true';
                    form.dataset.scannerName = scanner.name;
                }
                const token = document.createElement('input');
                token.type = 'hidden';
                token.name = 'csrf_token';
                token.value = dashboardCsrfToken;
                const scannerId = document.createElement('input');
                scannerId.type = 'hidden';
                scannerId.name = 'scanner_id';
                scannerId.value = scanner.id;
                const button = document.createElement('button');
                button.type = 'submit';
                button.className = scanner.enabled
                    ? 'font-bold text-red-500 transition hover:text-red-600'
                    : 'font-bold text-emerald-700 transition hover:text-emerald-800';
                button.textContent = scanner.enabled ? 'Disable' : 'Activate';
                form.append(token, scannerId, button);
                controls.appendChild(form);

                const removeForm = document.createElement('form');
                removeForm.method = 'post';
                removeForm.action = 'remove-mobile-scanner.php';
                const removeToken = document.createElement('input');
                removeToken.type = 'hidden';
                removeToken.name = 'csrf_token';
                removeToken.value = dashboardCsrfToken;
                const removeScannerId = document.createElement('input');
                removeScannerId.type = 'hidden';
                removeScannerId.name = 'scanner_id';
                removeScannerId.value = scanner.id;
                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'font-bold text-red-500 transition hover:text-red-600';
                removeButton.textContent = 'Remove';
                removeButton.addEventListener('click', () => {
                    pendingScannerRemoveForm = removeForm;
                    document.getElementById('remove-scanner-name').textContent = scanner.name || 'This mobile scanner';
                    scannerRemoveModal.classList.replace('hidden', 'flex');
                });
                removeForm.append(removeToken, removeScannerId, removeButton);
                controls.appendChild(removeForm);

                phone.append(details, controls);
                phoneList.appendChild(phone);
                });

                if (totalPages > 1 && phonePagination) {
                    const previous = document.createElement('button');
                    previous.type = 'button';
                    previous.className = 'rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40';
                    previous.textContent = 'Previous';
                    previous.disabled = scannerPage === 1;
                    previous.addEventListener('click', () => { scannerPage--; renderAuthorizedPhones(); });

                    const page = document.createElement('p');
                    page.className = 'text-sm font-bold text-slate-600';
                    page.textContent = `Page ${scannerPage} of ${totalPages}`;

                    const next = document.createElement('button');
                    next.type = 'button';
                    next.className = 'rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40';
                    next.textContent = 'Next';
                    next.disabled = scannerPage === totalPages;
                    next.addEventListener('click', () => { scannerPage++; renderAuthorizedPhones(); });
                    phonePagination.append(previous, page, next);
                }
            };
            renderAuthorizedPhones();
        }
    }

    const scannerDisableModal = document.createElement('div');
    scannerDisableModal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5';
    scannerDisableModal.innerHTML = `
        <div class="card w-full max-w-md p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="disable-scanner-title">
            <h2 id="disable-scanner-title" class="text-xl font-black text-slate-900">Disable mobile scanner?</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">This phone will no longer be able to sign in or record attendance until you activate it again.</p>
            <p id="disable-scanner-name" class="mt-4 rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-800"></p>
            <div class="mt-6 flex gap-3">
                <button id="cancel-scanner-disable" type="button" class="btn btn-secondary flex-1">Cancel</button>
                <button id="confirm-scanner-disable" type="button" class="btn btn-danger flex-1">Disable scanner</button>
            </div>
        </div>`;
    document.body.appendChild(scannerDisableModal);

    let pendingScannerDisableForm = null;
    const closeScannerDisableModal = () => {
        pendingScannerDisableForm = null;
        scannerDisableModal.classList.replace('flex', 'hidden');
    };
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.confirmDisable !== 'true') return;
        event.preventDefault();
        pendingScannerDisableForm = form;
        document.getElementById('disable-scanner-name').textContent = form.dataset.scannerName || 'This mobile scanner';
        scannerDisableModal.classList.replace('hidden', 'flex');
    });
    document.getElementById('cancel-scanner-disable').addEventListener('click', closeScannerDisableModal);
    document.getElementById('confirm-scanner-disable').addEventListener('click', () => {
        if (!pendingScannerDisableForm) return;
        const form = pendingScannerDisableForm;
        closeScannerDisableModal();
        form.dataset.confirmDisable = 'confirmed';
        form.submit();
    });
    scannerDisableModal.addEventListener('click', event => {
        if (event.target === scannerDisableModal) closeScannerDisableModal();
    });

    const scannerRemoveModal = document.createElement('div');
    scannerRemoveModal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5';
    scannerRemoveModal.innerHTML = `
        <div class="card w-full max-w-md p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="remove-scanner-title">
            <h2 id="remove-scanner-title" class="text-xl font-black text-slate-900">Remove mobile scanner?</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">This removes the phone from the Dashboard and blocks it from registering again.</p>
            <p id="remove-scanner-name" class="mt-4 rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-800"></p>
            <div class="mt-6 flex gap-3">
                <button id="cancel-scanner-remove" type="button" class="btn btn-secondary flex-1">Cancel</button>
                <button id="confirm-scanner-remove" type="button" class="btn btn-danger flex-1">Remove scanner</button>
            </div>
        </div>`;
    document.body.appendChild(scannerRemoveModal);

    let pendingScannerRemoveForm = null;
    const closeScannerRemoveModal = () => {
        pendingScannerRemoveForm = null;
        scannerRemoveModal.classList.replace('flex', 'hidden');
    };
    document.getElementById('cancel-scanner-remove').addEventListener('click', closeScannerRemoveModal);
    document.getElementById('confirm-scanner-remove').addEventListener('click', () => {
        if (!pendingScannerRemoveForm) return;
        const form = pendingScannerRemoveForm;
        closeScannerRemoveModal();
        form.submit();
    });
    scannerRemoveModal.addEventListener('click', event => {
        if (event.target === scannerRemoveModal) closeScannerRemoveModal();
    });

    function refreshDashboardStatistics() {
        fetch('get-stats.php', { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                document.getElementById('stat-total').textContent = data.totalStudents;
                document.getElementById('stat-today').textContent = data.attendanceToday;
                document.getElementById('stat-time-out').textContent = data.timeOutToday;
                const activePhones = Number(data.authorizedMobileScanners || 0);
                const activePhoneStatus = document.getElementById('mobile-scanner-status');
                if (activePhoneStatus) activePhoneStatus.textContent = activePhones === 1 ? '1 active scanner' : activePhones + ' active scanners';
                if (activePhones !== renderedActiveScannerCount) window.location.reload();
            })
            .catch(() => {});
    }

    refreshDashboardStatistics();
    setInterval(refreshDashboardStatistics, 5000);

</script>
<?php adminFoot(); ?>
