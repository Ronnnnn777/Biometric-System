<?php
require "page-guard.php";
require_once "../config/db_conn.php";
require "../includes/csrf-token.php";
require "../includes/staff-biometric.php";
ensureStaffBiometricSchema($conn);
require "components.php";
$teacherId = (int) ($_SESSION["teacher-id"] ?? 0);
$enrolled = staffHasBiometric($conn, "teacher", $teacherId);
$toast = $_SESSION["toast"] ?? null;
unset($_SESSION["toast"]);
teacherHead("Settings");
teacherShell("Settings");
?>
<style>
    .teacher-settings-group {
        margin: 0 auto 1rem;
        max-width: 42rem;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: .5rem;
        background: #fff;
        box-shadow: 0 1px 2px rgb(15 23 42 / .05)
    }

    .teacher-settings-group>summary {
        display: flex;
        cursor: pointer;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        list-style: none
    }

    .teacher-settings-group>summary::-webkit-details-marker {
        display: none
    }

    .teacher-settings-group>summary:hover {
        background: #f8fafc
    }

    .teacher-settings-group[open] {
        border-color: #93c5fd
    }

    .teacher-settings-group[open]>summary {
        background: #eff6ff
    }

    .teacher-settings-group[open]>summary .text-slate-900,
    .teacher-settings-group[open]>summary .text-slate-500 {
        color: #2563eb
    }

    .teacher-settings-chevron {
        display: inline-flex;
        align-items: center;
        font-size: 1.25rem;
        transition: transform .2s ease
    }

    .teacher-settings-group[open] .teacher-settings-chevron {
        transform: rotate(180deg);
        color: #2563eb
    }
</style>
<?php if (!empty($portalTeacher['MUST_CHANGE_PASSWORD'])): ?><p class="mb-4 font-bold text-amber-600">Change your temporary password to continue. Use at least 12 characters.</p><?php endif; ?>
<details class="teacher-settings-group" open>
    <summary><span><span class="block text-base font-black text-slate-900">Appearance</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Choose a light or dark dashboard, or follow this
                device.</span></span><span class="teacher-settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <section class="border-t border-slate-100 p-5 sm:p-6">
        <p class="text-sm text-slate-500">Your choice is saved in this browser and applies to the admin and teacher
            portals on this device.</p>
        <div class="theme-options" role="group" aria-label="Colour theme">
            <button type="button" class="theme-option" data-theme-option="light" aria-pressed="false"><?= icon('sun') ?><span>Light</span></button>
            <button type="button" class="theme-option" data-theme-option="dark" aria-pressed="false"><?= icon('moon') ?><span>Dark</span></button>
            <button type="button" class="theme-option" data-theme-option="system" aria-pressed="true"><?= icon('monitor') ?><span>System</span></button>
        </div>
    </section>
</details>
<details class="teacher-settings-group">
    <summary><span><span class="block text-base font-black text-slate-900">Biometric sign-in</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Enroll the face used with your password for
                biometric sign-in.</span></span><span class="teacher-settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <section class="border-t border-slate-100 p-5 sm:p-6">
        <p class="text-sm text-slate-500">Enroll a clear face from this device. Your password and face verification will
            both be required when using biometric sign-in.</p>
        <?php if (
      $enrolled
  ): ?><p class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">Biometric sign-in is
            enrolled. You can save a new face anytime.</p><?php endif; ?>
        <button id="teacher-enable-camera" type="button"
            class="btn btn-info mt-5 w-full">Enable
            camera</button>
    </section>
</details>
<details class="teacher-settings-group" <?= !empty($portalTeacher['MUST_CHANGE_PASSWORD']) ? 'open' : '' ?>>
    <summary><span><span class="block text-base font-black text-slate-900">Change password</span><span
                class="mt-1 block text-sm font-medium text-slate-500">Update your own teacher account
                password.</span></span><span class="teacher-settings-chevron text-slate-500"><?= icon('chevron-down') ?></span></summary>
    <section class="border-t border-slate-100 p-5 sm:p-6">
        <form action="settings-save.php" method="post" class="space-y-4"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
    $_SESSION["csrf_token"],
) ?>"><input type="hidden" name="action" value="password"><label class="block text-sm font-bold text-slate-700">Current
                password<input class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password"
                    name="current_password" autocomplete="current-password" required></label><label
                class="block text-sm font-bold text-slate-700">New password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="new_password"
                    minlength="12" autocomplete="new-password" required></label><label
                class="block text-sm font-bold text-slate-700">Confirm new password<input
                    class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password"
                    name="confirm_password" minlength="12" autocomplete="new-password" required></label><button
                class="btn btn-primary w-full">Update
                password</button></form>
    </section>
</details>

<div id="teacher-biometric-camera-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-5" role="dialog" aria-modal="true"
    aria-labelledby="teacher-biometric-camera-title">
    <form id="teacher-biometric-form" action="settings-save.php" method="post"
        class="card relative w-full max-w-2xl p-5 shadow-2xl sm:p-6">
        <button id="teacher-biometric-camera-close" type="button"
            class="absolute right-4 top-4 grid h-9 w-9 place-items-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-900"
            aria-label="Close camera"><?= icon('x') ?></button>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(
        $_SESSION["csrf_token"],
    ) ?>">
        <input type="hidden" name="action" value="biometric">
        <input id="teacher-biometric-frame" type="hidden" name="biometric_frame">
        <h3 id="teacher-biometric-camera-title" class="pr-10 text-xl font-black text-slate-900">Biometric camera</h3>
        <p class="mt-1 text-sm text-slate-500">Keep your face centered in the camera.</p>
        <label class="mt-5 block text-sm font-bold text-slate-700">Current password<input
                class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-950" type="password"
                name="current_password" autocomplete="current-password" required></label>
        <video id="teacher-biometric-video" class="mt-5 aspect-video w-full rounded-xl bg-slate-950 object-cover"
            autoplay muted playsinline></video>
        <p id="teacher-biometric-camera-status" class="mt-3 text-sm font-semibold text-slate-600">Opening camera&hellip;
        </p>
        <button class="btn btn-info mt-5 w-full">Save
            biometric sign-in</button>
    </form>
</div>

<?php if (
    $toast
): ?><div class="fixed bottom-5 right-5 z-50 rounded-xl bg-<?= ($toast[
    "type"
] ??
    "") ===
"success"
    ? "emerald"
    : "rose" ?>-600 px-5 py-4 font-bold text-white shadow-xl" role="status"><?= htmlspecialchars(
    $toast["message"] ?? "",
) ?></div><?php endif; ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('teacher-biometric-form'),
            video = document.getElementById('teacher-biometric-video'),
            frame = document.getElementById('teacher-biometric-frame'),
            button = document.getElementById('teacher-enable-camera'),
            modal = document.getElementById('teacher-biometric-camera-modal'),
            close = document.getElementById('teacher-biometric-camera-close'),
            status = document.getElementById('teacher-biometric-camera-status');
        let stream = null;
        const closeCamera = () => {
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
            video.srcObject = null;
            modal.classList.replace('flex', 'hidden');
            button.textContent = 'Enable camera';
            button.disabled = false;
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
                status.textContent = 'Camera ready. Keep your face clearly visible, then save.';
                button.textContent = 'Camera ready';
                button.disabled = true;
            } catch (error) {
                status.textContent = 'Camera access was not granted. Close this window and try again.';
                button.textContent = 'Camera access was not granted';
            }
        };
        close.onclick = closeCamera;
        modal.addEventListener('click', event => {
            if (event.target === modal) closeCamera();
        });
        form.onsubmit = event => {
            if (!stream || !video.videoWidth) {
                event.preventDefault();
                alert('Enable the camera and keep one clear face in view before saving.');
                return;
            }
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            frame.value = canvas.toDataURL('image/jpeg', .9);
        };
        window.addEventListener('pagehide', () => stream?.getTracks().forEach(track => track.stop()));
        document.querySelectorAll('.teacher-settings-group').forEach(group => group.addEventListener('toggle',
        () => {
            if (group.open) document.querySelectorAll('.teacher-settings-group').forEach(other => {
                if (other !== group) other.open = false;
            });
        }));
    });
</script>
<?php teacherFoot(); ?>