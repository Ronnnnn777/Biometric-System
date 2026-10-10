<?php
session_start();

$appBase = '/biometric';
$requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';

if (preg_match('#/admin/login\.php$#i', $requestedPath)) {
    header('Location: ' . $appBase . '/', true, 302);
    exit;
}

if (!empty($_SESSION['is-authenticated'])) {
    $role = $_SESSION['user-role'] ?? '';
    $destination = $role === 'teacher'
        ? '/teacher/home.php'
        : ($role === 'camera' ? '/camera/index.php' : '/admin/home.php');
    header('Location: ' . $appBase . $destination);
    exit;
}

require __DIR__ . '/../config/db_conn.php';
require __DIR__ . '/../includes/default-admin.php';
require __DIR__ . '/../includes/csrf-token.php';
require __DIR__ . '/../includes/staff-biometric.php';

ensureDefaultAdministrator($conn);
ensureStaffBiometricSchema($conn);

$loginError = $_SESSION['login-error'] ?? '';
unset($_SESSION['login-error']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | Biometric</title>
    <base href="<?= htmlspecialchars($appBase) ?>/admin/">
    <link rel="stylesheet" href="../css/tailwind.css">
    <style>
        .login-shell { min-height: 100vh; background: radial-gradient(circle at center, #1e3a5f 0%, #0f172a 48%, #020617 100%); }
        .login-card { position: relative; border: 1px solid rgb(255 255 255 / .1); background: radial-gradient(circle at center, #1e3a5f 0%, #0f172a 48%, #020617 100%); box-shadow: 0 24px 58px rgb(0 0 0 / .38); }
        .login-card :is(.text-slate-900, .text-slate-700) { color: #fff !important; }
        .login-card .text-slate-500 { color: #cbd5e1 !important; }
        .login-card .text-emerald-700 { color: #bfdbfe !important; }
        .login-brand-mark { box-shadow: 0 0 0 2px rgb(216 165 45 / .7); }
        .login-input { transition: border-color .16s, box-shadow .16s, background-color .16s; }
        .login-input { border-color: rgb(255 255 255 / .25) !important; background: rgb(15 23 42 / .82) !important; color: #fff !important; }
        .login-input:focus { border-color: rgb(147 197 253 / .9) !important; box-shadow: 0 0 0 3px rgb(147 197 253 / .16); }
        .login-primary { background: #2563eb; transition: background-color .16s, transform .16s; }
        .login-primary:hover { background: #1d4ed8; transform: translateY(-1px); }
        .login-biometric { border-color: rgb(255 255 255 / .28); background: rgb(255 255 255 / .1); color: #fff; }
        .login-biometric:hover { background: rgb(255 255 255 / .2); }
        .login-card #first-time-passwords { border-color: rgb(147 197 253 / .3); background: rgb(30 58 95 / .7); }
    </style>
</head>
<body class="login-shell text-slate-800">
    <main class="flex min-h-screen items-center justify-center p-5 sm:p-8">
        <section class="w-full max-w-md">
            <div class="login-card overflow-hidden rounded-2xl bg-white">
                <div class="pointer-events-none absolute inset-0 grid place-items-center"><img src="../image/logo.png" alt="" class="w-80 max-w-[72%] opacity-[.10] saturate-75"></div>
                <div class="relative z-10 p-7 sm:p-9">
                <div class="mb-7"><p class="text-sm font-bold uppercase tracking-widest text-emerald-700">Maruhat Integrated School</p>
                <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-900">Sign in</h1>
                <p class="mt-2 leading-6 text-slate-500">Enter your account details to continue.</p></div>

                <?php if ($loginError): ?>
                    <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700"><?= htmlspecialchars($loginError) ?></p>
                <?php endif; ?>

                <form id="password-form" action="auth.php" method="post" class="mt-8 space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div>
                        <label for="username" class="mb-2 block text-sm font-bold text-slate-700">Email address</label>
                        <input id="username" class="login-input w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 outline-none" type="email" name="username" autocomplete="username" required autofocus>
                    </div>
                    <div>
                        <label for="password" class="mb-2 block text-sm font-bold text-slate-700">Password</label>
                        <input id="password" class="login-input w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 outline-none" type="password" name="password" autocomplete="current-password" required>
                    </div>
                    <div id="first-time-passwords" class="hidden space-y-5 rounded-xl border border-blue-100 bg-blue-50 p-4">
                        <p class="text-sm font-semibold text-blue-900">First-time setup: choose a new administrator password.</p>
                        <label class="block text-sm font-bold text-slate-700">
                            New password
                            <input id="new-password" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="new_password" minlength="8" autocomplete="new-password">
                        </label>
                        <label class="block text-sm font-bold text-slate-700">
                            Confirm new password
                            <input id="confirm-password" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3" type="password" name="confirm_password" minlength="8" autocomplete="new-password">
                        </label>
                    </div>
                    <div class="text-right"><a href="forgot-password.php" class="text-sm font-bold text-blue-600 hover:text-blue-700">Forgot password?</a></div>
                    <button class="login-primary w-full rounded-xl px-4 py-3 font-bold text-white">Sign in</button>
                </form>

                <div class="my-6 flex items-center gap-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                    <span class="h-px flex-1 bg-slate-200"></span>or<span class="h-px flex-1 bg-slate-200"></span>
                </div>
                <button id="open-biometric" type="button" class="login-biometric w-full rounded-xl border px-4 py-3 font-bold transition">Sign in with biometric</button>
                </div>
            </div>
            <p class="mt-5 text-center text-xs leading-5 text-slate-400">For authorized school staff only.</p>
        </section>
    </main>

    <div id="biometric-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-5">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl sm:p-8">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-bold uppercase tracking-widest text-violet-700">Staff access</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Biometric sign in</h2>
                    <p id="scanner-status" class="mt-2 text-sm text-slate-500">Starting camera&hellip;</p>
                </div>
                <button id="close-biometric" type="button" class="rounded-lg p-2 text-xl font-bold text-slate-500 hover:bg-slate-100" aria-label="Close">&times;</button>
            </div>
            <div class="relative mt-5 aspect-[4/3] overflow-hidden rounded-2xl bg-slate-950">
                <video id="scanner-video" class="h-full w-full object-cover" autoplay muted playsinline></video>
                <canvas id="face-overlay" class="pointer-events-none absolute inset-0 h-full w-full"></canvas>
            </div>
            <p class="mt-4 text-center text-xs leading-5 text-slate-500">Look directly at the camera. Only enrolled administrator and teacher faces can access the system.</p>
        </div>
    </div>

    <script>
        const passwordForm = document.getElementById('password-form');
        const email = document.getElementById('username');
        const password = document.getElementById('password');
        const setup = document.getElementById('first-time-passwords');
        const newPassword = document.getElementById('new-password');
        const confirmPassword = document.getElementById('confirm-password');

        const usesDefaultCredentials = () => email.value.trim().toLowerCase() === 'admin@biometrics.com' && password.value === 'password';

        function updateFirstTimeSetup() {
            const required = usesDefaultCredentials();
            setup.classList.toggle('hidden', !required);
            newPassword.required = required;
            confirmPassword.required = required;
            if (!required) {
                newPassword.value = '';
                confirmPassword.value = '';
            }
        }

        email.addEventListener('input', updateFirstTimeSetup);
        password.addEventListener('input', updateFirstTimeSetup);
        passwordForm.addEventListener('submit', event => {
            const passwordIsInvalid = newPassword.value !== confirmPassword.value || newPassword.value.length < 8 || newPassword.value === 'password';
            if (usesDefaultCredentials() && passwordIsInvalid) {
                event.preventDefault();
                alert('Choose matching passwords with at least 8 characters and do not reuse the default password.');
                newPassword.focus();
            }
        });

        const modal = document.getElementById('biometric-modal');
        const openButton = document.getElementById('open-biometric');
        const closeButton = document.getElementById('close-biometric');
        const video = document.getElementById('scanner-video');
        const overlay = document.getElementById('face-overlay');
        const status = document.getElementById('scanner-status');
        const csrf = <?= json_encode($_SESSION['csrf_token']) ?>;
        let stream = null;
        let busy = false;
        let scanning = false;

        function drawCorners(faces, frameWidth, frameHeight) {
            const context = overlay.getContext('2d');
            const width = video.videoWidth || 640;
            const height = video.videoHeight || 480;
            overlay.width = width;
            overlay.height = height;
            context.clearRect(0, 0, width, height);
            if (!frameWidth || !frameHeight) return;

            const scaleX = width / frameWidth;
            const scaleY = height / frameHeight;
            context.lineWidth = Math.max(3, width / 180);
            context.strokeStyle = '#34d399';
            faces.forEach(item => {
                const box = item.box || item;
                const x = box.left * scaleX;
                const y = box.top * scaleY;
                const boxWidth = (box.right - box.left) * scaleX;
                const boxHeight = (box.bottom - box.top) * scaleY;
                const corner = Math.min(28, boxWidth * .25, boxHeight * .25);
                context.beginPath();
                context.moveTo(x + corner, y); context.lineTo(x, y); context.lineTo(x, y + corner);
                context.moveTo(x + boxWidth - corner, y); context.lineTo(x + boxWidth, y); context.lineTo(x + boxWidth, y + corner);
                context.moveTo(x, y + boxHeight - corner); context.lineTo(x, y + boxHeight); context.lineTo(x + corner, y + boxHeight);
                context.moveTo(x + boxWidth - corner, y + boxHeight); context.lineTo(x + boxWidth, y + boxHeight); context.lineTo(x + boxWidth, y + boxHeight - corner);
                context.stroke();
            });
        }

        async function scan() {
            if (!scanning || busy || !video.videoWidth) return;
            busy = true;

            try {
                const canvas = document.createElement('canvas');
                const ratio = Math.min(1, 480 / video.videoWidth);
                canvas.width = Math.round(video.videoWidth * ratio);
                canvas.height = Math.round(video.videoHeight * ratio);
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .88));
                if (!blob) return;

                const data = new FormData();
                data.append('csrf_token', csrf);
                data.append('biometric_frame', blob, 'live-frame.jpg');
                const response = await fetch('biometric-login-match.php', { method: 'POST', body: data, cache: 'no-store' });
                const result = await response.json();
                drawCorners(result.faces || [], result.frame_width, result.frame_height);
                status.textContent = result.message || 'Scanning...';

                if (result.ok) {
                    scanning = false;
                    status.textContent = 'Identity confirmed. Opening your portal...';
                    window.location.href = result.redirect;
                }
            } catch (error) {
                status.textContent = 'Scanner connection interrupted. Retrying...';
            } finally {
                busy = false;
                if (scanning) setTimeout(scan, 650);
            }
        }

        async function openBiometric() {
            modal.classList.replace('hidden', 'flex');
            status.textContent = 'Starting camera...';
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
                video.srcObject = stream;
                await video.play();
                scanning = true;
                status.textContent = 'Camera ready. Looking for an enrolled face...';
                scan();
            } catch (error) {
                status.textContent = 'Camera access was not granted. Allow camera access and try again.';
            }
        }

        function closeBiometric() {
            scanning = false;
            busy = false;
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
            video.srcObject = null;
            overlay.getContext('2d').clearRect(0, 0, overlay.width, overlay.height);
            modal.classList.replace('flex', 'hidden');
        }

        openButton.addEventListener('click', openBiometric);
        closeButton.addEventListener('click', closeBiometric);
        modal.addEventListener('click', event => { if (event.target === modal) closeBiometric(); });
        window.addEventListener('pagehide', closeBiometric);
        window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
    </script>
</body>
</html>
