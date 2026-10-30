const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element extends EventTarget {
    constructor() {
        super(); this.textContent = ''; this.src = ''; this.files = []; this.children = [];
        this.disabled = false; this.checked = false; this.open = false; this._value = '';
        const classes = new Set();
        this.classList = { toggle(name, force) { const active = force ?? !classes.has(name); active ? classes.add(name) : classes.delete(name); }, contains: name => classes.has(name) };
    }
    set value(value) { this._value = value; if (value === '') this.files = []; }
    get value() { return this._value; }
    setCustomValidity(message) { this.validationMessage = message; }
    checkValidity() { return !this.validationMessage; }
    reportValidity() { return this.checkValidity(); }
    appendChild(element) { this.children.push(element); if (this.children.length === 1) this._value = element.value; }
    replaceChildren() { this.children = []; this._value = ''; }
    showModal() { this.open = true; }
    close() { this.open = false; queueMicrotask(() => this.dispatchEvent(new Event('close'))); }
}

function createHarness(options = {}) {
    const ids = Object.fromEntries(['face-camera-dialog', 'webcam', 'photo-canvas', 'camera-select', 'capture-photo', 'camera-status', 'face-camera-title', 'face-camera-instructions', 'cancel-face-camera'].map(id => [id, new Element()]));
    const slots = {};
    const form = new Element();
    const root = new Element();
    root.closest = () => form;
    const fields = { front: 'student_image', left: 'face_left', right: 'face_right' };
    for (const pose of Object.keys(fields)) {
        const slot = new Element();
        slot.dataset = { registered: (options.registered || []).includes(pose) ? 'true' : 'false' };
        const elements = Object.fromEntries(['input', 'status', 'retake', 'use', ...(pose === 'front' ? [] : ['remove'])].map(key => [key, new Element()]));
        elements.input.name = fields[pose];
        elements.status.textContent = 'Registered';
        const selectors = { '[data-face-input]': 'input', '[data-face-status]': 'status', '[data-retake-face]': 'retake', '[data-remove-face]': 'remove', '[data-use-camera]': 'use' };
        slot.querySelector = selector => elements[selectors[selector]] || null;
        slot.elements = elements;
        slots[pose] = slot;
        ids[`face-preview-${pose}`] = new Element();
        ids[`face-preview-${pose}`].src = `original-${pose}.jpg`;
    }
    root.querySelector = selector => slots[selector.match(/"(\w+)"/)[1]];
    const video = ids.webcam;
    video.videoWidth = 1280; video.videoHeight = 720; video.play = async () => {};
    let captureNumber = 0;
    ids['photo-canvas'].getContext = () => ({ drawImage() {} });
    ids['photo-canvas'].toBlob = callback => {
        const blob = new Blob([`camera-frame-${++captureNumber}`], { type: 'image/jpeg' });
        if (options.encode) options.encode(callback, blob);
        else queueMicrotask(() => callback(blob));
    };
    const active = new Set();
    const requests = [];
    let maxActive = 0;
    function makeStream(deviceId = 'camera-a') {
        const stream = { stopped: false };
        const track = { stop() { stream.stopped = true; active.delete(stream); }, getSettings: () => ({ deviceId }) };
        stream.getTracks = stream.getVideoTracks = () => [track];
        active.add(stream); maxActive = Math.max(maxActive, active.size);
        return stream;
    }
    const mediaDevices = {
        enumerateDevices: async () => [{ kind: 'videoinput', deviceId: 'camera-a', label: 'First' }, { kind: 'videoinput', deviceId: 'camera-b', label: 'Second' }],
        getUserMedia: async constraints => {
            requests.push(constraints);
            return options.media ? options.media(constraints, makeStream) : makeStream(constraints.video.deviceId?.exact);
        }
    };
    const window = new Element();
    const storage = new Map(options.savedDevice ? [['attendance-camera', options.savedDevice]] : []);
    const urls = new Map();
    const context = {
        document: { querySelector: () => root, getElementById: id => ids[id], createElement: () => new Element() },
        navigator: { mediaDevices: options.noMedia ? undefined : mediaDevices }, window,
        localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
        URL: { createObjectURL: file => { const url = `blob:${urls.size + 1}`; urls.set(url, file); return url; }, revokeObjectURL() {} },
        File, Blob, Event, DataTransfer: class { constructor() { this.files = []; this.items = { add: file => this.files.push(file) }; } }
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js/student-face-references.js'), 'utf8'), context);
    const settle = async () => { for (let index = 0; index < 5; index++) await new Promise(resolve => setImmediate(resolve)); };
    async function click(element) { element.dispatchEvent(new Event('click')); await settle(); }
    return {
        ids, slots, active, requests, storage, window, form, makeStream, settle,
        maxActive: () => maxActive,
        open: pose => click(slots[pose].elements.use),
        capture: () => click(ids['capture-photo']),
        retake: pose => click(slots[pose].elements.retake),
        cancel: () => click(ids['cancel-face-camera']),
        file: pose => slots[pose].elements.input.files[0],
        async upload(pose, text = `uploaded-${pose}`) {
            slots[pose].elements.input.files = [new File([text], `${pose}.png`, { type: 'image/png' })];
            slots[pose].elements.input.dispatchEvent(new Event('change')); await settle();
        },
        async remove(pose, checked = true) {
            slots[pose].elements.remove.checked = checked;
            slots[pose].elements.remove.dispatchEvent(new Event('change')); await settle();
        },
        async select(deviceId) {
            ids['camera-select'].value = deviceId;
            ids['camera-select'].dispatchEvent(new Event('change')); await settle();
        },
        formData() {
            const data = new FormData();
            for (const pose of Object.keys(fields)) {
                const file = slots[pose].elements.input.files[0];
                if (file) data.append(fields[pose], file, file.name);
                if (slots[pose].elements.remove?.checked) data.append(`remove_face_${pose}`, '1');
            }
            return data;
        }
    };
}
module.exports = { createHarness };
