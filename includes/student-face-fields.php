<?php
$faceReferences = isset($row['ID']) ? studentFaceReferences($conn, (int)$row['ID']) : [];
$faceLabels = ['front' => 'Front View', 'left' => 'Slight Left View', 'right' => 'Slight Right View'];
?>
<fieldset class="rounded-xl border border-slate-200 p-4" data-face-enrollment>
    <legend class="px-2 text-sm font-bold text-slate-700">Facial references</legend>
    <p class="text-sm text-slate-500">Use the same student in every photo. Keep the full face visible, with good lighting and space around the head. Optional views should turn about 15–30 degrees toward the student's own left or right, keeping both eyes visible.</p>
    <div class="mt-4 grid gap-5 lg:grid-cols-3">
        <?php foreach ($faceLabels as $pose => $label):
            $inputId = $pose === 'front' ? 'student_image' : 'face_' . $pose;
            $registered = isset($faceReferences[$pose]);
        ?>
        <section class="min-w-0 text-sm text-slate-700" data-face-slot="<?= $pose ?>" data-registered="<?= $registered ? 'true' : 'false' ?>">
            <h3 class="font-bold"><?= $label ?></h3>
            <p class="text-xs text-slate-500"><?= $pose === 'front' ? (isset($row['ID']) ? 'Primary reference' : 'Primary reference · required') : 'Optional reference' ?></p>
            <img id="face-preview-<?= $pose ?>" alt="<?= $label ?> reference preview" src="../<?= htmlspecialchars($faceReferences[$pose] ?? 'image/student-preview.png', ENT_QUOTES) ?>" class="mt-2 h-40 w-full rounded-xl object-contain">
            <p class="mt-2 min-h-10 text-xs text-slate-500" data-face-status="<?= $pose ?>" aria-live="polite"><?= $registered ? 'Registered. Kept unless you choose a replacement.' : ($pose === 'front' ? 'Required for new enrollment.' : 'No reference registered for this view.') ?></p>
            <input id="<?= $inputId ?>" name="<?= $inputId ?>" type="file" accept="image/jpeg,image/png" class="sr-only" data-face-input="<?= $pose ?>">
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" data-use-camera="<?= $pose ?>" class="<?= $registered ? 'hidden ' : '' ?>inline-flex h-10 items-center justify-center rounded-lg border border-blue-200 px-3 text-sm font-bold text-blue-700 hover:bg-blue-50">Use Camera</button>
                <button type="button" data-retake-face="<?= $pose ?>" class="<?= $registered ? '' : 'hidden ' ?>inline-flex h-10 items-center justify-center rounded-lg border border-slate-300 px-3 text-sm font-bold text-slate-700">Retake Photo</button>
                <label for="<?= $inputId ?>" class="inline-flex h-10 cursor-pointer items-center justify-center rounded-lg bg-blue-50 px-3 text-sm font-bold text-blue-700">Choose Photo</label>
            </div>
            <?php if ($pose !== 'front'): ?>
            <label class="mt-3 block text-xs"><input type="checkbox" name="remove_face_<?= $pose ?>" value="1" data-remove-face="<?= $pose ?>"> Remove photo when saving</label>
            <?php else: ?>
            <p class="mt-3 text-xs text-slate-500">The primary reference cannot be removed.</p>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    </div>
    <p class="mt-3 text-xs text-slate-500">Each photo: JPEG or PNG, up to 5 MB, at least 480 × 480 pixels, exactly one clear, uncropped face. Leave a slot unchanged to keep its current photo. All photos are validated before saving.</p>
    <p class="mt-3 text-xs text-slate-500">Before enrolling photos, follow the school's approved biometric notice and consent process. Explain attendance use, authorized access, retention, and how to request correction or removal.</p>
    <dialog id="face-camera-dialog" class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 text-slate-700 shadow-xl" aria-labelledby="face-camera-title" aria-describedby="face-camera-instructions">
        <h3 id="face-camera-title" class="text-xl font-bold text-slate-900">Capturing Front View</h3>
        <p id="face-camera-instructions" class="mt-2 text-sm text-slate-500"></p>
        <label for="camera-select" class="mt-4 block text-sm font-bold">Camera</label>
        <select id="camera-select" class="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700" disabled><option value="">Default camera</option></select>
        <video id="webcam" class="mt-4 aspect-video w-full rounded-xl bg-slate-900 object-contain" autoplay muted playsinline></video>
        <p id="camera-status" class="mt-3 text-sm text-slate-700" role="status" aria-live="polite"></p>
        <div class="mt-4 flex flex-wrap gap-3">
            <button id="capture-photo" type="button" class="btn btn-primary" disabled>Capture Photo</button>
            <button id="cancel-face-camera" type="button" class="rounded-lg border border-slate-300 px-4 py-2 font-bold text-slate-700">Cancel</button>
        </div>
        <canvas id="photo-canvas" class="hidden"></canvas>
    </dialog>
</fieldset>
<script src="../js/student-face-references.js?v=3" defer></script>
