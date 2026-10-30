(() => {
    const root = document.querySelector('[data-face-enrollment]');
    if (!root) return;
    const dialog = document.getElementById('face-camera-dialog');
    const video = document.getElementById('webcam');
    const canvas = document.getElementById('photo-canvas');
    const cameraSelect = document.getElementById('camera-select');
    const capture = document.getElementById('capture-photo');
    const status = document.getElementById('camera-status');
    const labels = { front: 'Front View', left: 'Slight Left View', right: 'Slight Right View' };
    const instructions = {
        front: 'Look directly at the camera. Keep your face clearly visible.',
        left: 'Turn your head slightly to your left (about 15–30 degrees) while keeping both eyes visible.',
        right: 'Turn your head slightly to your right (about 15–30 degrees) while keeping both eyes visible.'
    };
    const photos = {};
    let activeCapturePose = null;
    let stream = null;
    let opening = null;
    let generation = 0;
    let encoding = false;
    let selectedDevice = '';
    try { selectedDevice = localStorage.getItem('attendance-camera') || ''; } catch { /* Storage may be disabled. */ }

    function stopTracks() {
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        video.srcObject = null;
        capture.disabled = true;
    }

    function stopCamera() {
        generation++;
        stopTracks();
    }

    function renderPhoto(pose, file, source) {
        const photo = photos[pose];
        if (photo.url) URL.revokeObjectURL(photo.url);
        photo.url = null;
        photo.file = null;
        photo.source = null;
        photo.input.setCustomValidity('');
        if (file && (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 5 * 1024 * 1024)) {
            photo.input.setCustomValidity('Choose a JPEG or PNG photo up to 5 MB.');
            photo.input.reportValidity();
            photo.status.textContent = 'Invalid photo. Choose a JPEG or PNG up to 5 MB, or recapture.';
        } else if (file) {
            photo.file = file;
            photo.source = source;
            photo.url = URL.createObjectURL(file);
            photo.removed = false;
            photo.status.textContent = `${source === 'camera' ? 'Camera photo captured' : file.name}. Ready to validate when saving.`;
        } else {
            photo.status.textContent = photo.removed ? 'This optional view will be removed when saving.' : photo.originalStatus;
        }
        if (photo.removal) photo.removal.checked = photo.removed;
        photo.preview.src = photo.url || (photo.removed ? '../image/student-preview.png' : photo.original);
        const hasPhoto = !photo.removed && Boolean(photo.file || photo.registered);
        photo.use.classList.toggle('hidden', hasPhoto);
        photo.retake.classList.toggle('hidden', !hasPhoto);
    }

    for (const pose of Object.keys(labels)) {
        const slot = root.querySelector(`[data-face-slot="${pose}"]`);
        const preview = document.getElementById(`face-preview-${pose}`);
        const photo = photos[pose] = {
            input: slot.querySelector('[data-face-input]'), preview,
            original: preview.src, status: slot.querySelector('[data-face-status]'),
            registered: slot.dataset.registered === 'true', removed: false,
            use: slot.querySelector('[data-use-camera]'),
            retake: slot.querySelector('[data-retake-face]'), removal: slot.querySelector('[data-remove-face]'),
            file: null, source: null, url: null
        };
        photo.originalStatus = photo.status.textContent;
        photo.input.addEventListener('change', () => renderPhoto(pose, photo.input.files[0], 'upload'));
        photo.removal?.addEventListener('change', () => {
            photo.removed = photo.removal.checked;
            if (photo.removed) {
                if (activeCapturePose === pose && dialog.open) closeCamera();
                photo.input.value = '';
            }
            renderPhoto(pose, photo.input.files[0], photo.source);
        });
        photo.use.addEventListener('click', () => openCamera(pose));
        photo.retake.addEventListener('click', () => openCamera(pose));
        renderPhoto(pose, photo.input.files[0], 'upload');
    }

    async function loadCameras() {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        const devices = (await navigator.mediaDevices.enumerateDevices()).filter(device => device.kind === 'videoinput');
        cameraSelect.replaceChildren();
        devices.forEach((device, index) => {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.textContent = device.label || `Camera ${index + 1}`;
            cameraSelect.appendChild(option);
        });
        if (!devices.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Default camera';
            cameraSelect.appendChild(option);
        }
        if (devices.some(device => device.deviceId === selectedDevice)) cameraSelect.value = selectedDevice;
        else selectedDevice = cameraSelect.value || '';
        cameraSelect.disabled = !devices.length;
    }

    function cameraError(error) {
        if (error.name === 'NotAllowedError' || error.name === 'SecurityError') return 'Camera permission was denied. Allow camera access in your browser, then select Use Camera again. You can also choose a photo.';
        if (error.name === 'NotFoundError') return 'No webcam was found. Connect a camera, then try again or choose a photo.';
        if (error.name === 'NotReadableError') return 'The webcam is unavailable or in use by another application. Close that application and try again.';
        return 'The camera could not be opened. Check its connection and browser permissions, or choose a photo.';
    }

    async function startCamera() {
        const request = ++generation;
        stopTracks();
        status.textContent = 'Opening camera…';
        // Serialize pending permission/device requests, including cancel followed by reopen.
        if (opening) await opening;
        if (request !== generation || !dialog.open) return;
        const task = (async () => {
            let obtained;
            try {
                const constraints = { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: { ideal: 'user' } };
                try {
                    obtained = await navigator.mediaDevices.getUserMedia({ video: { ...constraints, ...(selectedDevice ? { deviceId: { exact: selectedDevice } } : {}) }, audio: false });
                } catch (error) {
                    if (!selectedDevice || !['NotFoundError', 'OverconstrainedError'].includes(error.name) || request !== generation || !dialog.open) throw error;
                    // The remembered camera was disconnected. Fall back to an available device.
                    obtained = await navigator.mediaDevices.getUserMedia({ video: constraints, audio: false });
                }
                if (request !== generation || !dialog.open) { obtained.getTracks().forEach(track => track.stop()); return; }
                stream = obtained;
                video.srcObject = stream;
                await video.play();
                if (request !== generation || !dialog.open) return;
                selectedDevice = stream.getVideoTracks()[0]?.getSettings?.().deviceId || selectedDevice;
                try { await loadCameras(); } catch { /* An open stream can still capture if enumeration fails. */ }
                if (request !== generation || !dialog.open) return;
                try { localStorage.setItem('attendance-camera', selectedDevice); } catch { /* Optional preference. */ }
                capture.disabled = false;
                status.textContent = 'Camera ready. Keep the full face in view, then capture.';
            } catch (error) {
                obtained?.getTracks().forEach(track => track.stop());
                if (request !== generation) return;
                stopTracks();
                status.textContent = cameraError(error);
            }
        })();
        opening = task;
        await task;
        if (opening === task) opening = null;
    }

    function openCamera(pose) {
        activeCapturePose = pose;
        document.getElementById('face-camera-title').textContent = `Capturing ${labels[pose]}`;
        document.getElementById('face-camera-instructions').textContent = instructions[pose];
        if (!dialog.open) dialog.showModal();
        if (!navigator.mediaDevices?.getUserMedia) {
            status.textContent = 'Camera access requires HTTPS or localhost and a supported browser. Open this page on localhost or choose a photo.';
            return;
        }
        if (!stream) void startCamera();
    }

    function closeCamera() {
        stopCamera();
        activeCapturePose = null;
        if (dialog.open) dialog.close();
    }

    cameraSelect.addEventListener('change', () => {
        selectedDevice = cameraSelect.value;
        if (dialog.open) void startCamera();
    });
    capture.addEventListener('click', async () => {
        if (!stream || !activeCapturePose || encoding) return;
        if (Math.min(video.videoWidth, video.videoHeight) < 480) {
            status.textContent = 'The camera image must be at least 480 × 480 pixels. Select a higher-resolution camera or upload a larger photo.';
            return;
        }
        const pose = activeCapturePose;
        const request = generation;
        encoding = true;
        capture.disabled = true;
        try {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Photo capture is unavailable in this browser. Choose Photo to upload a reference.');
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
            if (request !== generation || !dialog.open || activeCapturePose !== pose) return;
            if (!blob) throw new Error('The photo could not be captured. Try again.');
            const file = new File([blob], `${pose}-camera.jpg`, { type: 'image/jpeg' });
            const input = photos[pose].input;
            try {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
            } catch {
                throw new Error('This browser could not attach the camera photo. Choose Photo to upload it instead.');
            }
            if (input.files.length !== 1 || input.files[0].size !== file.size) throw new Error('This browser could not attach the camera photo. Choose a photo instead.');
            renderPhoto(pose, input.files[0], 'camera');
            if (input.checkValidity()) closeCamera();
            else status.textContent = input.validationMessage;
        } catch (error) {
            status.textContent = error.message || 'Capture failed. Try again or choose a photo.';
        } finally {
            encoding = false;
            if (request === generation) capture.disabled = !stream;
        }
    });
    document.getElementById('cancel-face-camera').addEventListener('click', closeCamera);
    dialog.addEventListener('cancel', event => { event.preventDefault(); closeCamera(); });
    dialog.addEventListener('close', () => { if (!dialog.open) stopCamera(); });
    root.closest('form').addEventListener('submit', event => {
        if (dialog.open) {
            event.preventDefault();
            status.textContent = 'Capture or cancel the camera before saving the student.';
        }
    });
    window.addEventListener('pagehide', closeCamera);
    window.addEventListener('beforeunload', () => {
        closeCamera();
        Object.values(photos).forEach(photo => { if (photo.url) URL.revokeObjectURL(photo.url); });
    });
})();
