<?php
http_response_code(410);
exit;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Camera Portal | Biometric</title>
    <link rel="stylesheet" href="../css/tailwind.css">
    <style>
        body { background: radial-gradient(circle at center, #1e3a5f 0%, #0f172a 48%, #020617 100%); }
        #camera-portal-card { background: radial-gradient(circle at center, #1e3a5f 0%, #0f172a 48%, #020617 100%); }
        #camera-portal-card .text-slate-900, #camera-portal-card .text-slate-700 { color: #fff !important; }
        #camera-portal-card .text-slate-500 { color: #cbd5e1 !important; }
        #camera-portal-card select { background: rgba(15, 23, 42, .85) !important; border-color: rgba(255, 255, 255, .25) !important; color: #fff !important; }
        #camera-portal-card #refresh-cameras { background: rgba(255, 255, 255, .12) !important; border-color: rgba(255, 255, 255, .28) !important; color: #fff !important; }
        #camera-portal-card #refresh-cameras:hover { background: rgba(255, 255, 255, .22) !important; }
    </style>
</head>
<body class="min-h-screen text-white">
    <main class="mx-auto flex min-h-screen max-w-3xl items-center p-5">
        <section id="camera-portal-card" class="relative w-full overflow-hidden rounded-2xl border border-white/10 bg-black/55 p-6 text-white shadow-2xl backdrop-blur sm:p-9">
            <div class="pointer-events-none absolute inset-0 grid place-items-center">
                <img src="../image/logo.png" alt="" class="w-80 max-w-[72%] opacity-[.10] saturate-75">
            </div>

            <div class="relative z-10 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-bold uppercase tracking-widest text-blue-600">Guard house access</p>
                    <h1 class="mt-2 text-3xl font-black text-slate-900">Camera Portal</h1>
                    <p class="mt-2 text-slate-500">Choose the attendance camera, then open the scanner.</p>
                </div>
                <a href="logout.php" class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-rose-950/30 hover:bg-rose-700">Sign out</a>
            </div>

            <div class="relative z-10 mt-8 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
                <label class="block text-sm font-bold text-slate-700">
                    Attendance camera
                    <select id="camera-picker" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900" aria-label="Attendance camera">
                        <option>Detecting cameras&hellip;</option>
                    </select>
                </label>
                <button id="refresh-cameras" type="button" class="self-end rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Refresh cameras</button>
                <a id="open-scanner" href="../admin/scan-attendance.php" class="self-end rounded-xl bg-blue-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-blue-700">Open camera scanner</a>
            </div>

            <p id="camera-help" class="relative z-10 mt-4 text-sm text-slate-500">Detecting the connected camera&hellip;</p>
        </section>
    </main>

    <script>
        const picker = document.getElementById('camera-picker');
        const refresh = document.getElementById('refresh-cameras');
        const scanner = document.getElementById('open-scanner');
        const help = document.getElementById('camera-help');

        function selectPreferredCamera(devices, savedDeviceId) {
            return devices.find(device => device.deviceId === savedDeviceId)
                || devices.find(device => /chicony|emeet|c60/i.test(device.label || ''))
                || devices[0]
                || null;
        }

        function updateScannerLink() {
            const cameraId = picker.value;
            scanner.href = '../admin/scan-attendance.php' + (cameraId ? '?camera=' + encodeURIComponent(cameraId) : '');
            if (cameraId) localStorage.setItem('attendance-camera', cameraId);
        }

        async function listCameras(requestPermission = true) {
            picker.disabled = true;
            refresh.disabled = true;
            help.textContent = 'Detecting connected cameras...';

            try {
                if (requestPermission) {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                    stream.getTracks().forEach(track => track.stop());
                }

                const cameras = (await navigator.mediaDevices.enumerateDevices()).filter(device => device.kind === 'videoinput');
                const selected = selectPreferredCamera(cameras, localStorage.getItem('attendance-camera') || '');
                picker.replaceChildren();

                cameras.forEach((camera, index) => {
                    const option = document.createElement('option');
                    option.value = camera.deviceId;
                    option.textContent = camera.label || 'Camera ' + (index + 1);
                    picker.appendChild(option);
                });

                if (selected) picker.value = selected.deviceId;
                picker.disabled = !selected;

                if (selected) {
                    updateScannerLink();
                    help.textContent = 'Selected: ' + (selected.label || 'Camera') + '. You can change it if needed.';
                } else {
                    scanner.href = '../admin/scan-attendance.php';
                    help.textContent = 'No camera was found. Connect the Chicony or eMeet camera, then select Refresh cameras.';
                }
            } catch (error) {
                picker.replaceChildren();
                const option = document.createElement('option');
                option.textContent = 'Camera permission is required';
                picker.appendChild(option);
                picker.disabled = true;
                scanner.href = '../admin/scan-attendance.php';
                help.textContent = 'Allow camera access when prompted, then select Refresh cameras.';
            } finally {
                refresh.disabled = false;
            }
        }

        picker.addEventListener('change', updateScannerLink);
        refresh.addEventListener('click', () => listCameras(true));
        listCameras();
    </script>
</body>
</html>
