<?php
session_start();
require "page-guard.php";
require "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/student-placement-options.php";
require "../includes/section-catalog-schema.php";
require "../includes/sectioning.php";
require "../includes/teacher-assignment-schema.php";
require "../includes/staff-biometric.php";
require "../includes/attendance-schema.php";
require "../includes/mobile-scanner-settings.php";
require "components.php";
ensureSectionCatalogColumns($conn);
seedDefaultTeacherSections($conn);
ensureTeacherAssignmentSchema($conn);
ensureStaffBiometricSchema($conn);
ensureAttendanceTimeOutColumn($conn);
ensureMobileScannerSettingsSchema($conn);
$conn->query(
    "CREATE TABLE IF NOT EXISTS admin_security (ADMIN_USER VARCHAR(50) PRIMARY KEY, SECURITY_QUESTION VARCHAR(255) NOT NULL, SECURITY_ANSWER VARCHAR(255) NOT NULL, UPDATED_AT TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
);
$securityConfigured = false;
$securityQuestion = "";
$security = $conn->prepare(
    "SELECT SECURITY_QUESTION FROM admin_security WHERE ADMIN_USER=?",
);
$security->bind_param("s", $_SESSION["admin-user"]);
$security->execute();
if ($row = $security->get_result()->fetch_assoc()) {
    $securityConfigured = true;
    $securityQuestion = $row["SECURITY_QUESTION"];
}
$defaultCapacity = defaultSectionCapacity($conn);
$wholeDayAttendance = attendanceUsesWholeDay($conn);
$mobileScannerConfiguration = mobileScannerConfiguration($conn);
$mobileScannerServerUrl = $mobileScannerConfiguration["server_url"];
// Public URL of this installation (for example "/biometric-system/"), used in
// the scanner instructions so they match the real folder on this computer.
$appBasePath = rtrim(str_replace("\\", "/", dirname(dirname($_SERVER["SCRIPT_NAME"] ?? "/admin/settings.php"))), "/") . "/";
$mobileScannerName = $mobileScannerConfiguration["scanner_name"];
$mobileScannerId = $mobileScannerConfiguration["scanner_id"];
$authorizedMobileScanners = authorizedMobileScanners($conn);
$emergencyDismissalResult = $conn->query(
    "SELECT COUNT(*) total FROM attendance WHERE DATE(TIME_IN)=CURDATE() AND TIME_OUT IS NULL AND IS_AUTO_ABSENT=0 AND COALESCE(ATTENDANCE_REMARK,'')<>'Absent'",
);
$emergencyDismissalCount =
    (int) (($emergencyDismissalResult
        ? $emergencyDismissalResult->fetch_assoc()
        : [])["total"] ?? 0);
$groups = [];
$rows = $conn->query(
    "SELECT c.SECTION_ID,c.SECTION,c.GRADE,c.STRAND,c.IS_ACTIVE,c.CAPACITY,c.SORT_ORDER,COUNT(DISTINCT s.ID) students,COUNT(DISTINCT t.TEACHER_ID) teachers FROM sections c LEFT JOIN students s ON s.SECTION=c.SECTION AND s.GRADE=c.GRADE AND s.STRAND=c.STRAND AND COALESCE(s.ENROLLMENT_STATUS,'Enrolled')='Enrolled' LEFT JOIN teacher_assignments ta ON ta.SECTION=c.SECTION AND ta.GRADE=c.GRADE AND ta.STRAND=c.STRAND LEFT JOIN teachers t ON t.TEACHER_ID=ta.TEACHER_ID AND COALESCE(t.TEACHER_STATUS,'Active')='Active' WHERE c.IS_ACTIVE=1 AND c.GRADE BETWEEN 7 AND 12 GROUP BY c.SECTION_ID,c.SECTION,c.GRADE,c.STRAND,c.IS_ACTIVE,c.CAPACITY,c.SORT_ORDER ORDER BY c.GRADE,c.STRAND,c.SORT_ORDER,c.SECTION_ID",
);
while ($row = $rows->fetch_assoc()) {
    $groups[(int) $row["GRADE"]][$row["STRAND"]][] = $row;
}
adminHead("Settings");
adminSidebar(
    "settings",
    "Settings",
    "Manage administrator security and grade-based teacher sections",
);
?>
<style>
    button[value="delete_section"] {
        font-size: 0;
        line-height: 1
    }

    button[value="delete_section"]::after {
        content: "×";
        font-size: 1.35rem;
        line-height: 1
    }

    .settings-group {
        margin: 0 auto 1rem;
        max-width: 64rem;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: .5rem;
        background: #fff;
        box-shadow: 0 1px 2px rgb(15 23 42 / .05)
    }

    .settings-group>summary {
        display: flex;
        cursor: pointer;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        list-style: none
    }

    .settings-group>summary::-webkit-details-marker {
        display: none
    }

    .settings-group>summary:hover {
        background: #f8fafc
    }

    .settings-group[open] {
        border-color: #93c5fd
    }

    .settings-group[open]>summary {
        background: #eff6ff
    }

    .settings-group[open]>summary .text-slate-900,
    .settings-group[open]>summary .text-slate-500 {
        color: #2563eb
    }

    .settings-group .settings-chevron {
        display: inline-flex;
        align-items: center;
        font-size: 1.25rem;
        transition: transform .2s ease
    }

    .settings-group[open] .settings-chevron {
        transform: rotate(180deg);
        color: #2563eb
    }

    .attendance-mode-switch {
        position: relative;
        width: 3.25rem;
        height: 1.8rem;
        cursor: pointer;
        appearance: none;
        border: 0;
        border-radius: 9999px;
        background: #cbd5e1;
        transition: background .2s
    }

    .attendance-mode-switch::after {
        position: absolute;
        top: .25rem;
        left: .25rem;
        width: 1.3rem;
        height: 1.3rem;
        content: '';
        border-radius: 9999px;
        background: #fff;
        box-shadow: 0 1px 2px rgb(15 23 42 / .25);
        transition: transform .2s
    }

    .attendance-mode-switch:checked {
        background: #f59e0b
    }

    .attendance-mode-switch:checked::after {
        transform: translateX(1.45rem)
    }

    .attendance-mode-switch:focus-visible {
        outline: 3px solid #94a3b8;
        outline-offset: 3px
    }

    html.settings-building main>div>section.mx-auto.mb-7.max-w-2xl,
    html.settings-building main>div>div.grid.gap-7.lg\:grid-cols-2 {
        visibility: hidden
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        [...document.querySelectorAll('.settings-group')]
            .filter(group => /Authorized mobile scanners|Mobile scanner identity/.test(group.querySelector('summary')
                ?.textContent || ''))
            .forEach(group => group.remove());
        setTimeout(() => {
        const configuration = [...document.querySelectorAll('.settings-group')].find(group => group
            .querySelector('summary')?.textContent.includes('Mobile scanner configuration'));
        if (!configuration) return;
        const form = configuration.querySelector('form');
        form?.querySelectorAll('input[name="mobile_scanner_name"],input[name="mobile_scanner_id"]').forEach(
            input => input.closest('label')?.remove());
        configuration.querySelector('h2').textContent = 'Mobile scanner IP address';
        const summary = configuration.querySelector('summary .text-sm');
        if (summary) summary.textContent = 'Set the IP address used by the attendance scanner phones.';
        const helper = form?.querySelector('p');
        if (helper) helper.innerHTML =
            'Use this computer\'s LAN IP and include the <span class="font-semibold"><?= htmlspecialchars($appBasePath) ?></span> folder. Scanner phones register automatically and appear on the Dashboard.';
        form?.querySelector('#save-mobile-scanner-configuration') && (form.querySelector(
            '#save-mobile-scanner-configuration').textContent = 'Save mobile scanner configuration');
        }, 0);
    });
</script>
<details class="settings-group" open>
    <summary><span><span class="block text-base font-black text-slate-900">Appearance</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Choose a light or dark dashboard, or follow this
                device.</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <p class="text-sm text-slate-500">Your choice is saved in this browser and applies to the admin and teacher
            portals on this device.</p>
        <div class="theme-options" role="group" aria-label="Colour theme">
            <button type="button" class="theme-option" data-theme-option="light" aria-pressed="false"><?= icon('sun') ?><span>Light</span></button>
            <button type="button" class="theme-option" data-theme-option="dark" aria-pressed="false"><?= icon('moon') ?><span>Dark</span></button>
            <button type="button" class="theme-option" data-theme-option="system" aria-pressed="true"><?= icon('monitor') ?><span>System</span></button>
        </div>
    </div>
</details>
<details class="settings-group">
    <summary><span><span class="block text-base font-black text-slate-900">Authorized mobile scanners</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Add each phone that is allowed to connect to MNHS
                Attendance.</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-900">Authorized phones</h2>
                <p class="mt-1 text-sm text-slate-500">Each phone needs the same scanner name and ID when it opens the
                    mobile app.</p>
            </div><span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-700"><?= count(
    $authorizedMobileScanners,
) ?> added</span>
        </div>
        <form action="settings-save.php" method="post" class="mt-5 grid max-w-2xl gap-4 sm:grid-cols-2"><input
                type="hidden" name="csrf_token" value="<?= htmlspecialchars(
     $_SESSION["csrf_token"],
 ) ?>"><input type="hidden" name="action" value="add_mobile_scanner"><label
                class="block text-sm font-bold text-slate-700">Phone / scanner name<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-medium text-slate-900"
                    name="mobile_scanner_name" placeholder="Main entrance phone" maxlength="80" required></label><label
                class="block text-sm font-bold text-slate-700">Scanner ID<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-medium text-slate-900"
                    name="mobile_scanner_id" placeholder="MNH-MOBILE-001" maxlength="60" required></label>
            <div class="sm:col-span-2"><button
                    class="btn btn-primary">Add authorized
                    phone</button></div>
        </form>
        <div class="mt-6 w-full overflow-x-auto">
            <table class="w-full min-w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3">Scanner ID</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100"><?php if (
    $authorizedMobileScanners
):
    foreach (
        $authorizedMobileScanners
        as $authorizedScanner
    ): ?><tr>
                        <td class="px-4 py-4 font-bold text-slate-900"><?= htmlspecialchars(
    $authorizedScanner["SCANNER_NAME"],
) ?></td>
                        <td class="px-4 py-4 text-slate-600"><?= htmlspecialchars(
    $authorizedScanner["SCANNER_ID"],
) ?></td>
                        <td class="px-4 py-4 text-right">
                            <form action="settings-save.php" method="post" class="inline"><input type="hidden"
                                    name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="remove_mobile_scanner"><input type="hidden" name="mobile_scanner_id"
                                    value="<?= htmlspecialchars(
    $authorizedScanner["SCANNER_ID"],
) ?>"><button class="font-bold text-red-500 hover:text-red-600">Remove</button></form>
                        </td>
                    </tr><?php endforeach;
else:
     ?><tr>
                        <td colspan="3" class="px-4 py-8 text-center text-slate-500">No authorized phones yet.</td>
                    </tr><?php
endif; ?></tbody>
            </table>
        </div>
    </div>
</details>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const authorized = [...document.querySelectorAll('.settings-group')].find(group => group.querySelector(
            'summary')?.textContent.includes('Authorized mobile scanners'));
        if (!authorized) return;
        authorized.querySelector('table')?.closest('.mt-6.overflow-x-auto')?.remove();
        const badge = authorized.querySelector('.rounded-full');
        if (badge) {
            badge.textContent = 'Add a phone';
            badge.className = 'rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700'
        }
    });
</script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const groups = [...document.querySelectorAll('.settings-group')],
            identity = groups.find(group => group.querySelector('summary')?.textContent.includes(
                'Mobile scanner identity')),
            configuration = groups.find(group => group.querySelector('summary')?.textContent.includes(
                'Mobile scanner configuration'));
        if (!identity || !configuration) return;
        const identityForm = identity.querySelector('form'),
            configurationForm = configuration.querySelector('form'),
            ipLabel = configurationForm.querySelector('label'),
            nameLabels = [...identityForm.querySelectorAll('label')];
        nameLabels.forEach(label => configurationForm.insertBefore(label, ipLabel));
        configurationForm.classList.add('grid', 'gap-4', 'sm:grid-cols-2');
        ipLabel.classList.add('sm:col-span-2');
        ipLabel.childNodes[0].nodeValue = 'IP address';
        configurationForm.querySelector('p')?.classList.add('sm:col-span-2');
        configurationForm.querySelector('button')?.classList.add('sm:col-span-2');
        configuration.querySelector('h2').textContent = 'Mobile scanner configuration';
        const summaryText = configuration.querySelector('summary .text-sm');
        if (summaryText) summaryText.textContent =
            'Set the scanner name, scanner ID, and IP address used by Maruhat Attendance.';
        const helper = configurationForm.querySelector('p');
        if (helper) helper.innerHTML =
            'Use this computer\'s LAN IP and include the <span class="font-semibold"><?= htmlspecialchars($appBasePath) ?></span> folder. Enter the same IP address in the mobile app; the phone and computer must use the same Wi-Fi network.';
        configurationForm.querySelector('#save-mobile-scanner-configuration').textContent =
            'Save mobile scanner configuration';
        identity.remove()
    });
</script>
<details class="settings-group">
    <summary><span><span class="block text-base font-black text-slate-900">Mobile scanner identity</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Register the name and ID shown by the Maruhat
                Attendance mobile scanner.</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-900">Scanner name and ID</h2>
                <p class="mt-1 text-sm text-slate-500">These identify the scanner when it connects to the attendance
                    system.</p>
            </div><span class="rounded-full px-3 py-1 text-sm font-bold <?= $mobileScannerName !==
    "" && $mobileScannerId !== ""
    ? "bg-emerald-50 text-emerald-700"
    : "bg-amber-50 text-amber-700" ?>"><?= $mobileScannerName !== "" &&
$mobileScannerId !== ""
    ? "Configured"
    : "Not configured" ?></span>
        </div>
        <form action="settings-save.php" method="post" class="mt-5 grid max-w-2xl gap-4 sm:grid-cols-2"><input
                type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="mobile_scanner_identity"><label
                class="block text-sm font-bold text-slate-700">Scanner name<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-medium text-slate-900"
                    name="mobile_scanner_name" value="<?= htmlspecialchars(
    $mobileScannerName,
) ?>" placeholder="Main entrance scanner" maxlength="80" required></label><label
                class="block text-sm font-bold text-slate-700">Scanner ID<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-medium text-slate-900"
                    name="mobile_scanner_id" value="<?= htmlspecialchars(
    $mobileScannerId,
) ?>" placeholder="MNH-MOBILE-001" maxlength="60" required></label>
            <div class="sm:col-span-2"><button
                    class="btn btn-primary">Save scanner
                    identity</button></div>
        </form>
    </div>
</details>
<details class="settings-group">
    <summary><span><span class="block text-base font-black text-slate-900">Mobile scanner configuration</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Set the server address the Maruhat Attendance
                mobile app uses.</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-slate-900">School server address</h2>
                <p class="mt-1 text-sm text-slate-500">Save the XAMPP computer's LAN address, then enter this same
                    address in Maruhat Attendance.</p>
            </div><span class="rounded-full px-3 py-1 text-sm font-bold <?= $mobileScannerServerUrl !==
""
    ? "bg-emerald-50 text-emerald-700"
    : "bg-amber-50 text-amber-700" ?>"><?= $mobileScannerServerUrl !== ""
    ? "Configured"
    : "Not configured" ?></span>
        </div>
        <form action="settings-save.php" method="post" class="mt-5 max-w-2xl"><input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="mobile_scanner_configuration"><label
                class="block text-sm font-bold text-slate-700">Server address<input
                    id="mobile-scanner-server-url"
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 font-medium text-slate-900"
                    name="mobile_scanner_server_url" value="<?= htmlspecialchars(
    $mobileScannerServerUrl,
) ?>" placeholder="http://192.168.1.10<?= htmlspecialchars($appBasePath) ?>" inputmode="url" required></label>
            <div class="mt-3 flex flex-wrap items-center gap-3"><button id="detect-mobile-scanner-ip" type="button"
                    class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-bold text-blue-700 hover:bg-blue-100">Detect
                    IP Address</button><span id="mobile-scanner-ip-status" class="text-sm font-medium text-slate-500"
                    aria-live="polite"></span></div>
            <p class="mt-3 text-sm text-slate-500">Detection fills the current LAN address only. Review it, then save.
                Include the <span class="font-semibold"><?= htmlspecialchars($appBasePath) ?></span> folder. The phone and this computer must use
                the same Wi-Fi network.</p><button
                id="save-mobile-scanner-configuration"
                class="btn btn-primary mt-5">Save mobile scanner
                configuration</button>
        </form>
    </div>
</details>
<script>
    (() => {
        const button = document.getElementById('detect-mobile-scanner-ip'),
            address = document.getElementById('mobile-scanner-server-url'),
            status = document.getElementById('mobile-scanner-ip-status');
        if (!button || !address || !status) return;

        button.addEventListener('click', async () => {
            button.disabled = true;
            status.textContent = 'Detecting server address…';
            try {
                const response = await fetch('detect-mobile-scanner-server.php', {
                    headers: {
                        Accept: 'application/json'
                    },
                    cache: 'no-store'
                });
                const result = await response.json();
                if (!response.ok || !result.ok || !result.server_url) throw new Error(result.message ||
                    'The server address could not be detected.');
                address.value = result.server_url;
                status.textContent = `Detected ${result.server_ip}. Review it, then save.`;
                status.className = 'text-sm font-medium text-emerald-700';
            } catch (error) {
                status.textContent = error.message || 'Could not detect the server address.';
                status.className = 'text-sm font-medium text-rose-700';
            } finally {
                button.disabled = false;
            }
        });
    })();
</script>
<script>
    document.documentElement.classList.add('settings-building');
    window.setTimeout(() => document.documentElement.classList.remove('settings-building'), 1200);
</script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const groups = [];
        const makeGroup = (title, description, anchor, content, keepContainer = false) => {
            const details = document.createElement('details'),
                summary = document.createElement('summary'),
                body = document.createElement('div');
            details.className = 'settings-group';
            summary.innerHTML =
                `<span><span class="block text-base font-black text-slate-900">${title}</span><span class="mt-1 block text-sm font-medium text-slate-500">${description}</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span>`;
            body.className = 'border-t border-slate-100 p-5 sm:p-6';
            anchor.before(details);
            details.append(summary, body);
            if (keepContainer) body.append(content);
            else {
                while (content.firstChild) body.append(content.firstChild);
                content.remove()
            }
            groups.push(details);
            return details
        };
        const topCards = [...document.querySelectorAll('section.mx-auto.mb-7.max-w-2xl')],
            biometricCard = topCards.find(card => card.querySelector('h2')?.textContent.trim() ===
                'Biometric sign-in');
        if (biometricCard) makeGroup('Biometric sign-in',
            'Enroll the administrator face used with password sign-in.', biometricCard, biometricCard);
        const settingsGrid = document.querySelector('div.grid.gap-7.lg\\:grid-cols-2');
        if (settingsGrid) {
            const teacherCard = settingsGrid.querySelector('#teacher-sections-card');
            if (teacherCard) teacherCard.remove();
            const accountGroup = makeGroup('Account security',
                'Change the administrator password or configure password recovery.', settingsGrid,
                settingsGrid, true);
            if (teacherCard) {
                const teacherGroup = makeGroup('Sections',
                    'Create, rename, and organize grade-level sections.', accountGroup, teacherCard);
                accountGroup.after(teacherGroup)
            }
        }
        groups.forEach(group => group.addEventListener('toggle', () => {
            if (group.open) groups.forEach(other => {
                if (other !== group) other.open = false
            })
        }))
    });
</script>
<section class="card card-body mx-auto mb-7 max-w-2xl">
    <h2 class="text-xl font-black text-slate-900">Biometric sign-in</h2>
    <p class="mt-1 text-sm text-slate-500">Enroll a clear face from this device. Password and face verification will
        both be required next time.</p><button id="admin-enable-camera" type="button"
        class="btn btn-info mt-5 w-full">Enable
        camera</button>
</section>
<div id="admin-biometric-camera-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5"
    role="dialog" aria-modal="true" aria-labelledby="admin-biometric-camera-title">
    <form id="admin-biometric-form" action="settings-save.php" method="post"
        class="card relative w-full max-w-2xl p-5 shadow-2xl sm:p-6"><button
            id="admin-biometric-camera-close" type="button"
            class="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-full text-2xl leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900"
            aria-label="Close camera">×</button><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="biometric"><input id="admin-biometric-frame" type="hidden"
            name="biometric_frame">
        <h3 id="admin-biometric-camera-title" class="pr-10 text-xl font-black text-slate-900">Biometric camera</h3>
        <p class="mt-1 text-sm text-slate-500">Keep one clear face centered in the camera.</p><label
            class="mt-5 block text-sm font-bold text-slate-700">Current password<input
                class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-950" type="password"
                name="current_password" autocomplete="current-password" required></label><video
            id="admin-biometric-video" class="mt-5 aspect-video w-full rounded-xl bg-slate-950 object-cover" autoplay
            muted playsinline></video>
        <p id="admin-biometric-camera-status" class="mt-3 text-sm font-semibold text-slate-600">Opening camera…</p>
        <button class="btn btn-info mt-5 w-full">Save
            biometric sign-in</button>
    </form>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('admin-biometric-form'),
            video = document.getElementById('admin-biometric-video'),
            frame = document.getElementById('admin-biometric-frame'),
            button = document.getElementById('admin-enable-camera'),
            modal = document.getElementById('admin-biometric-camera-modal'),
            close = document.getElementById('admin-biometric-camera-close'),
            status = document.getElementById('admin-biometric-camera-status');
        let stream;
        const closeCamera = () => {
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
            video.srcObject = null;
            modal.classList.replace('flex', 'hidden');
            button.textContent = 'Enable camera';
            button.disabled = false
        };
        button.onclick = async () => {
            modal.classList.replace('hidden', 'flex');
            status.textContent = 'Opening camera…';
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'user',
                        width: {
                            ideal: 640
                        }
                    },
                    audio: false
                });
                video.srcObject = stream;
                await video.play();
                status.textContent = 'Camera ready. Keep your face clearly visible, then enroll.';
                button.textContent = 'Camera ready';
                button.disabled = true
            } catch (error) {
                status.textContent = 'Camera access was not granted. Close this window and try again.';
                button.textContent = 'Camera access was not granted'
            }
        };
        close.onclick = closeCamera;
        form.onsubmit = event => {
            if (!stream || !video.videoWidth) {
                event.preventDefault();
                alert('Enable the camera and keep one clear face in view before enrolling.');
                return
            }
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            frame.value = canvas.toDataURL('image/jpeg', .9)
        };
        window.addEventListener('pagehide', () => stream?.getTracks().forEach(track => track.stop()))
    });
</script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const gradePanels = [...document.querySelectorAll('details:not(.settings-group)')];
        gradePanels.forEach(panel => panel.addEventListener('toggle', () => {
            if (panel.open) gradePanels.forEach(other => {
                if (other !== panel) other.open = false
            })
        }))
    });
</script>
<details class="settings-group">
    <summary><span><span class="block text-base font-black text-slate-900">Attendance day mode</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Choose between whole-day and half-day
                attendance.</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="attendance-mode-title" class="text-xl font-black <?= $wholeDayAttendance
    ? "text-green-700"
    : "text-amber-700" ?>"><?= $wholeDayAttendance
    ? "Whole-day mode"
    : "Half-day mode" ?></h2>
                <p id="attendance-mode-description" class="mt-1 text-sm text-slate-500"><?= $wholeDayAttendance
    ? "Whole day is active: lunch out at 11:40 AM and afternoon time in at 12:20 PM."
    : "Half day is active: students use the standard time-in/time-out flow." ?></p>
            </div><span id="attendance-mode-badge" class="rounded-full px-3 py-1 text-sm font-bold <?= $wholeDayAttendance
    ? "bg-emerald-50 text-emerald-700"
    : "bg-amber-50 text-amber-700" ?>"><?= $wholeDayAttendance
    ? "Whole day active"
    : "Half day active" ?></span>
        </div>
        <form id="attendance-mode-form" action="settings-save.php" method="post"
            class="mt-5 flex items-center justify-between gap-4 rounded-xl bg-slate-50 p-4"><input type="hidden"
                name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="attendance_mode">
            <div>
                <p id="attendance-mode-control-label" class="font-bold <?= $wholeDayAttendance
    ? "text-green-700"
    : "text-amber-700" ?>"><?= $wholeDayAttendance
    ? "Switch on half-day mode"
    : "Half-day mode is on" ?></p>
                <p id="attendance-mode-save-status" class="mt-1 text-sm text-slate-500">Switch off for whole day.
                    Changes save automatically.</p>
            </div><label class="inline-flex cursor-pointer items-center gap-3"><input id="half-day-mode-toggle"
                    class="attendance-mode-switch" type="checkbox" role="switch" name="half_day_enabled" value="1" <?= !$wholeDayAttendance
    ? "checked"
    : "" ?>><span id="attendance-mode-state" class="text-sm font-bold <?= $wholeDayAttendance
    ? "text-green-700"
    : "text-amber-700" ?>"><?= $wholeDayAttendance
    ? "Off · Whole day"
    : "On · Half day" ?></span></label>
        </form>
    </div>
</details>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('attendance-mode-form'),
            toggle = document.getElementById('half-day-mode-toggle'),
            title = document.getElementById('attendance-mode-title'),
            description = document.getElementById('attendance-mode-description'),
            badge = document.getElementById('attendance-mode-badge'),
            label = document.getElementById('attendance-mode-control-label'),
            state = document.getElementById('attendance-mode-state'),
            status = document.getElementById('attendance-mode-save-status');
        if (!form || !toggle) return;
        const render = halfDay => {
            const wholeDay = !halfDay;
            title.textContent = wholeDay ? 'Whole-day mode' : 'Half-day mode';
            title.className = 'text-xl font-black ' + (wholeDay ? 'text-green-700' : 'text-amber-700');
            description.textContent = wholeDay ?
                'Whole day is active: lunch out at 11:40 AM and afternoon time in at 12:20 PM.' :
                'Half day is active: students use the standard time-in/time-out flow.';
            badge.textContent = wholeDay ? 'Whole day active' : 'Half day active';
            badge.className = 'rounded-full px-3 py-1 text-sm font-bold ' + (wholeDay ?
                'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700');
            label.textContent = wholeDay ? 'Switch on half-day mode' : 'Half-day mode is on';
            label.className = 'font-bold ' + (wholeDay ? 'text-green-700' : 'text-amber-700');
            state.textContent = wholeDay ? 'Off · Whole day' : 'On · Half day';
            state.className = 'text-sm font-bold ' + (wholeDay ? 'text-green-700' : 'text-amber-700')
        };
        toggle.addEventListener('change', async () => {
            const previous = !toggle.checked;
            render(toggle.checked);
            status.textContent = 'Saving attendance mode…';
            toggle.disabled = true;
            try {
                const data = new FormData(form);
                const response = await fetch('save-attendance-mode.php', {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json'
                    }
                });
                const result = await response.json();
                if (!response.ok || !result.saved) throw new Error(result.message || 'Save failed');
                status.textContent = 'Attendance mode saved.'
            } catch (error) {
                toggle.checked = previous;
                render(previous);
                status.textContent = 'Could not save the mode. Please try again.'
            } finally {
                toggle.disabled = false
            }
        })
    });
</script>
<details class="settings-group">
    <summary><span><span class="block text-base font-black text-slate-900">Emergency dismissal</span><span
                class="mt-1 block text-sm font-medium text-slate-500">End attendance for all students already checked in
                today without scanning again.</span></span><span class="settings-chevron text-slate-500"><?= icon('chevron-down') ?></span>
    </summary>
    <div class="border-t border-slate-100 p-5 sm:p-6">
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
            <p class="font-black text-rose-800"><?= $emergencyDismissalCount ?> checked-in student<?= $emergencyDismissalCount ===
 1
     ? ""
     : "s" ?> ready</p>
            <p class="mt-1 text-sm text-rose-700">This records the current time as final time out and marks each as
                Emergency dismissal. Students with no time in are not changed.</p><button id="open-emergency-dismissal"
                type="button"
                class="btn btn-danger mt-4 w-full" <?= $emergencyDismissalCount ===
 0
     ? "disabled"
     : "" ?>><?= $emergencyDismissalCount > 0
    ? "Mark all as emergency dismissal"
    : "No checked-in students" ?></button>
        </div>
    </div>
</details>
<div id="emergency-dismissal-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5"
    role="dialog" aria-modal="true" aria-labelledby="emergency-dismissal-title">
    <form action="settings-save.php" method="post" class="card w-full max-w-md p-6 shadow-2xl"><input
            type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="bulk_emergency_dismissal">
        <h2 id="emergency-dismissal-title" class="text-xl font-black text-slate-900">Mark all checked-in students?</h2>
        <p class="mt-2 text-sm text-slate-600">This will set the current time as final time out and mark
            <?= $emergencyDismissalCount ?> student<?= $emergencyDismissalCount ===
 1
     ? ""
     : "s" ?> as Emergency dismissal. No face scan is required.</p>
        <div class="mt-6 flex gap-3"><button id="cancel-emergency-dismissal" type="button"
                class="btn btn-secondary flex-1">Cancel</button><button
                class="btn btn-danger flex-1">Confirm
                dismissal</button></div>
    </form>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const open = document.getElementById('open-emergency-dismissal'),
            modal = document.getElementById('emergency-dismissal-modal'),
            cancel = document.getElementById('cancel-emergency-dismissal');
        if (!open || !modal || !cancel) return;
        open.onclick = () => modal.classList.replace('hidden', 'flex');
        cancel.onclick = () => modal.classList.replace('flex', 'hidden');
        modal.addEventListener('click', event => {
            if (event.target === modal) modal.classList.replace('flex', 'hidden')
        })
    });
</script>
<div id="section-delete-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5">
    <div class="card w-full max-w-sm p-6 shadow-2xl">
        <h3 class="text-xl font-black text-slate-900">Delete section?</h3>
        <p id="section-delete-message" class="mt-2 text-sm text-slate-500">This section will be removed if it has no
            linked students or teachers.</p>
        <div class="mt-6 flex gap-3"><button type="button" id="cancel-section-delete"
                class="btn btn-secondary flex-1">Cancel</button><button
                type="button" id="confirm-section-delete"
                class="btn btn-danger flex-1">Delete</button></div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('section-delete-modal'),
            message = document.getElementById('section-delete-message');
        let pendingForm = null;
        document.querySelectorAll('button[value="delete_section"]').forEach(button => {
            button.onclick = event => {
                event.preventDefault();
                pendingForm = button.form;
                message.textContent =
                    `Delete “${pendingForm.querySelector('[name="delete_target"]').value}”? This cannot be undone.`;
                modal.classList.replace('hidden', 'flex')
            }
        });
        document.getElementById('cancel-section-delete').onclick = () => {
            pendingForm = null;
            modal.classList.replace('flex', 'hidden')
        };
        document.getElementById('confirm-section-delete').onclick = () => {
            if (!pendingForm) return;
            pendingForm.querySelector('input[name="action"]').value = 'delete_section';
            pendingForm.submit()
        }
    });
</script>
<div class="grid gap-7 lg:grid-cols-2">
    <section class="card card-body">
        <h2 class="text-xl font-black text-slate-900">Change admin password</h2>
        <form action="settings-save.php" method="post" class="mt-6 space-y-4"><input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="password"><label class="block text-sm font-bold">Current password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password"
                    name="current_password" required></label><label class="block text-sm font-bold">New password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="new_password"
                    minlength="8" required></label><label class="block text-sm font-bold">Confirm new password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password"
                    name="confirm_password" minlength="8" required></label><button
                class="btn btn-primary w-full">Update password</button></form>
    </section>
    <section class="card card-body">
        <h2 class="text-xl font-black text-slate-900">Password recovery</h2>
        <p class="mt-1 text-sm text-slate-500">Choose a security question used to reset the administrator password.</p><?php if (
    $securityConfigured
): ?><p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">Configured: <?= htmlspecialchars(
    $securityQuestion,
) ?></p><?php endif; ?><form action="settings-save.php" method="post" class="mt-5 space-y-4"><input type="hidden"
                name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="security_question"><label class="block text-sm font-bold">Current
                password<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password"
                    name="current_password" required></label><label class="block text-sm font-bold">Security
                question<select class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"
                    name="security_question" required>
                    <option value="What was the name of your first pet?">What was the name of your first pet?</option>
                    <option value="What city were you born in?">What city were you born in?</option>
                    <option value="What is your favorite teacher's surname?">What is your favorite teacher's surname?
                    </option>
                </select></label><label class="block text-sm font-bold">Answer<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" name="security_answer"
                    autocomplete="off" required></label><button
                class="btn btn-primary w-full">Save security question</button>
        </form>
    </section>
    <section id="teacher-sections-card" class="card card-body lg:col-span-2">
        <h2 class="text-xl font-black text-slate-900">Sections</h2>
        <p class="mt-1 text-sm text-slate-500">Open a grade to manage its sections. Grade 11 and Grade 12 are separated
            by strand. Capacity controls how many promoted students fit before the rollover spills into the next
            section.</p>
        <form action="settings-save.php" method="post"
            class="mt-4 flex flex-wrap items-end gap-3 rounded-xl border border-blue-100 bg-blue-50 p-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">
            <input type="hidden" name="action" value="set_default_capacity">
            <label class="text-sm font-bold text-slate-700">Default capacity
                <input class="mt-1 block w-28 rounded-lg border border-slate-300 px-3 py-2 text-slate-900"
                    type="number" name="default_capacity" min="1" max="200"
                    value="<?= (int) $defaultCapacity ?>" required>
            </label>
            <button class="btn btn-primary btn-sm">Save default</button>
            <p class="basis-full text-xs text-slate-500">Used for sections without their own limit and for new overflow
                sections created during a rollover.</p>
        </form>
        <div class="mt-5 flex gap-2"><input id="draft-section"
                class="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-3"
                placeholder="New section name"><button type="button" id="open-add-section"
                class="btn btn-primary">Add</button></div>
        <div class="mt-5 space-y-3"><?php foreach (
    [7, 8, 9, 10, 11, 12]
    as $grade
): ?><details class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <summary class="cursor-pointer list-none px-4 py-4 font-bold text-slate-800 hover:bg-slate-50"><span
                        class="flex items-center justify-between"><span>Grade <?= $grade ?></span><span
                            class="text-sm font-normal text-slate-500">Click to manage sections</span></span></summary>
                <div class="space-y-4 border-t border-slate-100 p-4"><?php
$strands = $grade >= 11 ? ["Academic", "Techpro"] : [""];
foreach ($strands as $strand):
    $items = $groups[$grade][$strand] ?? []; ?><div><?php if (
    $strand
): ?><h3 class="mb-2 text-sm font-black text-blue-700"><?= htmlspecialchars(
    $strand,
) ?></h3><?php endif; ?><div class="space-y-2"><?php foreach (
    $items
    as $item
): ?><form action="settings-save.php" method="post"
                                class="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-2"><input
                                    type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="rename_section"><input type="hidden" name="old_section" value="<?= htmlspecialchars(
    $item["SECTION"],
) ?>"><input type="hidden" name="delete_target" value="<?= htmlspecialchars(
    $item["SECTION"],
) ?>"><input class="min-w-36 flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold" name="section"
                                    value="<?= htmlspecialchars(
    $item["SECTION"],
) ?>"><label class="flex items-center gap-1 text-xs font-bold text-slate-600">Cap
                                <input class="w-16 rounded-lg border border-slate-200 px-2 py-2 text-sm text-slate-900"
                                    type="number" name="capacity" min="1" max="200"
                                    value="<?= (int) $item["CAPACITY"] ?>"></label><span
                                class="text-xs text-slate-500"><?= $item[
    "students"
] ?> students · <?= $item[
     "teachers"
 ] ?> teachers</span><button class="btn btn-primary btn-sm">Save</button><button
                                    type="submit" name="action" value="delete_section"
                                    class="rounded-lg px-3 py-2 text-sm font-bold text-red-500 transition hover:bg-red-500/10"
                                    onclick="return confirm('Delete this section?')">Delete</button></form>
                            <?php endforeach; ?></div>
                    </div><?php
endforeach;
?></div>
            </details><?php endforeach; ?></div>
    </section>
</div>
<div id="add-section-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5">
    <form action="settings-save.php" method="post" class="card w-full max-w-sm p-6 shadow-2xl"><input
            type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="add_section"><input id="new-section-value" type="hidden"
            name="section">
        <h3 class="text-xl font-black text-slate-900">Place new section</h3>
        <p id="new-section-label" class="mt-2 text-sm text-slate-500"></p><label
            class="mt-5 block text-sm font-bold">Grade<select id="new-section-grade"
                class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3" name="grade" required>
                <option value="">Choose a grade</option><?php foreach (
    [7, 8, 9, 10, 11, 12]
    as $grade
): ?><option value="<?= $grade ?>">Grade <?= $grade ?></option><?php endforeach; ?>
            </select></label><label id="new-section-strand-field" class="mt-4 hidden text-sm font-bold">Strand<select
                id="new-section-strand" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3"
                name="strand">
                <option value="">Choose a strand</option>
                <option>Techpro</option>
                <option>Academic</option>
            </select></label>
        <div class="mt-6 flex gap-3"><button type="button" id="cancel-add-section"
                class="btn btn-secondary flex-1">Cancel</button><button
                class="btn btn-primary flex-1">Save section</button></div>
    </form>
</div>
<script>
    const draft = document.getElementById('draft-section'),
        modal = document.getElementById('add-section-modal'),
        value = document.getElementById('new-section-value'),
        label = document.getElementById('new-section-label'),
        grade = document.getElementById('new-section-grade'),
        strandField = document.getElementById('new-section-strand-field'),
        strand = document.getElementById('new-section-strand');
    document.getElementById('open-add-section').onclick = () => {
        const name = draft.value.trim();
        if (!name) {
            draft.focus();
            return
        }
        value.value = name;
        label.textContent = `Assign “${name}” to a grade.`;
        modal.classList.replace('hidden', 'flex');
        grade.focus()
    };
    document.getElementById('cancel-add-section').onclick = () => modal.classList.replace('flex', 'hidden');
    grade.onchange = () => {
        const senior = grade.value === '11' || grade.value === '12';
        strandField.classList.toggle('hidden', !senior);
        strand.required = senior;
        if (!senior) strand.value = ''
    };
</script><?php adminFoot(); ?>
