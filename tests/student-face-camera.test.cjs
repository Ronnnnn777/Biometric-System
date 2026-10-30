const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createHarness } = require('./helpers/student-face-camera.cjs');

test('front, left, and right captures attach independent files to the correct form fields', async () => {
    const app = createHarness();
    for (const pose of ['front', 'left', 'right']) {
        await app.open(pose);
        assert.match(app.ids['face-camera-title'].textContent, new RegExp(pose, 'i'));
        await app.capture();
        assert.equal(app.file(pose).name, `${pose}-camera.jpg`);
        assert.equal(app.active.size, 0);
    }
    const data = app.formData();
    assert.equal(await data.get('student_image').text(), 'camera-frame-1');
    assert.equal(await data.get('face_left').text(), 'camera-frame-2');
    assert.equal(await data.get('face_right').text(), 'camera-frame-3');
    const front = app.file('front'), right = app.file('right');
    await app.retake('left'); await app.capture();
    assert.equal(await app.file('left').text(), 'camera-frame-4');
    assert.equal(app.file('front'), front); assert.equal(app.file('right'), right);
    await app.remove('right');
    assert.equal(app.file('right'), undefined); assert.equal(app.file('front'), front);
    assert.equal(app.formData().get('remove_face_right'), '1');
    await app.upload('right');
    assert.equal(app.formData().get('remove_face_right'), null);
    assert.equal(await app.file('right').text(), 'uploaded-right');
    assert.equal(app.maxActive(), 1);
});

test('uploads and captures replace each other without changing another slot', async () => {
    const app = createHarness();
    await app.upload('front'); const front = app.file('front');
    await app.upload('left'); await app.open('left'); await app.capture();
    assert.equal(await app.file('left').text(), 'camera-frame-1');
    assert.equal(app.slots.left.elements.retake.classList.contains('hidden'), false);
    await app.upload('left', 'replacement');
    assert.equal(await app.file('left').text(), 'replacement');
    assert.equal(app.slots.left.elements.retake.classList.contains('hidden'), false);
    assert.equal(app.slots.left.elements.use.classList.contains('hidden'), true);
    assert.equal(app.file('front'), front);
});

test('switching target reuses one live stream and switching devices stops the previous stream', async () => {
    const app = createHarness({ savedDevice: 'camera-b' });
    await app.open('front');
    assert.equal(app.requests[0].video.deviceId.exact, 'camera-b');
    await app.open('left');
    assert.equal(app.requests.length, 1);
    const old = [...app.active][0];
    await app.select('camera-a');
    assert.equal(old.stopped, true); assert.equal(app.maxActive(), 1);
    await app.capture();
    await app.open('right');
    assert.equal(app.requests.at(-1).video.deviceId.exact, 'camera-a');
    await app.cancel(); assert.equal(app.active.size, 0);
});

test('permission denial and unsupported camera access show useful errors', async () => {
    const app = createHarness({ media: async () => { throw Object.assign(new Error(), { name: 'NotAllowedError' }); } });
    await app.open('left');
    assert.match(app.ids['camera-status'].textContent, /permission was denied/);
    assert.equal(app.ids['capture-photo'].disabled, true);
    assert.equal(app.file('left'), undefined);
    const unsupported = createHarness({ noMedia: true });
    await unsupported.open('right');
    assert.match(unsupported.ids['camera-status'].textContent, /HTTPS or localhost/);
});

test('canceled permission request is stopped when it eventually resolves', async () => {
    let resolve, factory;
    const app = createHarness({ media: (constraints, makeStream) => { factory = makeStream; return new Promise(done => { resolve = done; }); } });
    await app.open('front'); await app.cancel();
    const submit = new Event('submit', { cancelable: true });
    app.form.dispatchEvent(submit);
    assert.equal(submit.defaultPrevented, false, 'Cancel permits ordinary uploads while a permission request is pending');
    resolve(factory()); await app.settle();
    assert.equal(app.active.size, 0); assert.equal(app.file('front'), undefined);
});

test('cancel and reopen while permission is pending serializes camera acquisition', async () => {
    let resolve, factory, calls = 0;
    const app = createHarness({ media: (constraints, makeStream) => {
        if (++calls > 1) return Promise.resolve(makeStream());
        factory = makeStream; return new Promise(done => { resolve = done; });
    } });
    await app.open('front'); await app.cancel(); await app.open('right');
    assert.equal(calls, 1);
    resolve(factory()); await app.settle();
    assert.equal(calls, 2); assert.equal(app.maxActive(), 1);
    await app.capture(); assert.equal(app.file('front'), undefined); assert.ok(app.file('right'));
});

test('late canvas result cannot overwrite a canceled or different pose', async () => {
    let callback, blob;
    const app = createHarness({ encode: (done, value) => { callback = done; blob = value; } });
    await app.open('left'); await app.capture(); await app.cancel();
    callback(blob); await app.settle();
    assert.equal(app.file('left'), undefined);
    await app.open('left'); await app.capture(); await app.open('right');
    callback(blob); await app.settle();
    assert.equal(app.file('left'), undefined); assert.equal(app.file('right'), undefined);
    await app.cancel();
});

test('disconnected remembered device falls back, and unloading releases tracks', async () => {
    let calls = 0;
    const app = createHarness({ savedDevice: 'disconnected', media: async (constraints, makeStream) => {
        if (++calls === 1) throw Object.assign(new Error(), { name: 'OverconstrainedError' });
        return makeStream('camera-a');
    } });
    await app.open('front'); assert.equal(calls, 2);
    app.window.dispatchEvent(new Event('pagehide')); await app.settle();
    assert.equal(app.active.size, 0);
});

test('low-resolution capture is rejected without mutating form fields', async () => {
    const app = createHarness(); await app.open('left');
    app.ids.webcam.videoWidth = 320; app.ids.webcam.videoHeight = 240;
    await app.capture(); assert.equal(app.file('left'), undefined);
    assert.match(app.ids['camera-status'].textContent, /480/);
    const submit = new Event('submit', { cancelable: true });
    app.form.dispatchEvent(submit); assert.equal(submit.defaultPrevented, true);
    await app.cancel();
});

function checkControls(app, pose, hasPhoto) {
    assert.equal(app.slots[pose].elements.use.classList.contains('hidden'), hasPhoto, `${pose}: Use Camera matches photo state`);
    assert.equal(app.slots[pose].elements.retake.classList.contains('hidden'), !hasPhoto, `${pose}: Retake Photo matches photo state`);
}

test('empty slots, captures, uploads, and removals switch controls independently', async () => {
    const app = createHarness();
    for (const pose of ['front', 'left', 'right']) checkControls(app, pose, false);
    await app.open('front'); await app.capture();
    checkControls(app, 'front', true); checkControls(app, 'left', false); checkControls(app, 'right', false);
    await app.open('left'); await app.capture();
    checkControls(app, 'left', true); checkControls(app, 'right', false);
    await app.upload('right');
    for (const pose of ['front', 'left', 'right']) checkControls(app, pose, true);
    const front = app.file('front'), left = app.file('left');
    await app.remove('right'); checkControls(app, 'right', false);
    assert.equal(app.file('front'), front); assert.equal(app.file('left'), left);
    await app.open('right'); await app.capture(); checkControls(app, 'right', true);
    assert.equal(app.formData().get('remove_face_right'), null);
});

test('saved references initialize Retake controls and survive canceled retakes', async () => {
    const app = createHarness({ registered: ['front', 'left'] });
    checkControls(app, 'front', true); checkControls(app, 'left', true); checkControls(app, 'right', false);
    const original = app.ids['face-preview-left'].src;
    await app.retake('left');
    assert.equal(app.ids['face-camera-title'].textContent, 'Capturing Slight Left View');
    await app.cancel();
    assert.equal(app.ids['face-preview-left'].src, original);
    assert.equal(app.file('left'), undefined); checkControls(app, 'left', true);
    assert.equal(app.formData().get('face_left'), null, 'Cancel does not submit a replacement for the saved image');
    await app.remove('left'); checkControls(app, 'left', false);
    assert.equal(app.ids['face-preview-left'].src, '../image/student-preview.png');
    await app.remove('left', false); checkControls(app, 'left', true);
    assert.equal(app.ids['face-preview-left'].src, original);
    const reloaded = createHarness({ registered: ['front', 'left', 'right'] });
    for (const pose of ['front', 'left', 'right']) checkControls(reloaded, pose, true);
});

test('canceling a retake preserves an unsaved captured or uploaded file and its controls', async () => {
    const app = createHarness();
    await app.open('front'); await app.capture(); await app.upload('left');
    for (const pose of ['front', 'left']) {
        const file = app.file(pose), preview = app.ids[`face-preview-${pose}`].src;
        await app.retake(pose); await app.cancel();
        assert.equal(app.file(pose), file); assert.equal(app.ids[`face-preview-${pose}`].src, preview);
        checkControls(app, pose, true);
    }
});
