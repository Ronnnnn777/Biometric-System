// Run with: node --test tests/quarterly-grades.test.cjs
// Execute the actual inline controller with a small DOM harness, without a DB.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../teacher/students.php'), 'utf8');
const controller = source.slice(source.lastIndexOf('<script>') + 8, source.lastIndexOf('</script>'));
new vm.Script(controller); // Syntax check the real JavaScript, independently of PHP.

function setup() {
    const listeners = {};
    const requests = [];
    let observer;
    const document = { activeElement: null };
    const status = { hidden: true, textContent: '' };
    const buttons = [{ disabled: false }, { disabled: false }];
    const rows = Array.from({ length: 17 }, (_, index) => {
        const row = {
            dataset: { studentId: String(index + 1) }, hidden: false, dirty: false,
            final: {}, remark: {},
            toggleAttribute(name, value) { this.dirty = value; },
            querySelectorAll() { return this.inputs; },
            querySelector(selector) { return selector === '[data-final]' ? this.final : this.remark; },
        };
        row.cells = Array.from({ length: 4 }, (_, quarter) => {
            const cell = { dataset: { quarter: String(quarter + 1) }, classList: { toggle() {} } };
            const input = {
                value: quarter === 0 ? '80' : '', name: `q${quarter + 1}[${index + 1}]`,
                readOnly: false, disabled: false, tabIndex: 0, label: `Q${quarter + 1} grade for Student ${index + 1}`,
                closest(selector) { return selector === '[data-grade-row]' ? row : this; },
                matches() { return true; },
                focus() { document.activeElement = this; },
                getAttribute() { return this.label; },
                setAttribute(name, value) { this.label = value; },
            };
            cell.querySelector = () => input;
            return { cell, input };
        });
        row.inputs = row.cells.map(entry => entry.input);
        return row;
    });
    const inputs = rows.flatMap(row => row.inputs);
    const rosterRows = rows.map((row, index) => ({ dataset: { rosterStudentId: row.dataset.studentId }, hidden: index >= 15 }));
    const roster = { querySelectorAll: () => rosterRows };
    const form = {
        action: 'save-subject-result.php',
        addEventListener(type, callback) { listeners[type] = callback; },
        querySelectorAll: () => buttons,
    };
    const grades = {
        dataset: {},
        addEventListener(type, callback) { listeners[type] = callback; },
        querySelector(selector) { return selector === 'form' ? form : status; },
        querySelectorAll(selector) {
            if (selector === '[data-grade-row]') return rows;
            if (selector === '[data-quarter-input]') return inputs;
            if (selector === '[data-quarter]') return rows.flatMap(row => row.cells.map(entry => entry.cell));
            return [];
        },
    };
    document.getElementById = id => id === 'quarterly-grades' ? grades : roster;
    document.querySelector = () => ({ dataset: { capsuleValue: '1' } });
    class FormData {
        constructor() { this.values = new Map(inputs.map(input => [input.name, input.value])); }
        append(name, value) { this.values.set(name, value); }
    }
    vm.runInNewContext(controller, {
        document, FormData,
        MutationObserver: class { constructor(callback) { observer = callback; } observe() {} },
        fetch(url, options) { return new Promise(resolve => requests.push({ url, options, resolve })); },
    });
    const dispatch = (type, target, extra = {}) => {
        const event = { target, prevented: false, preventDefault() { this.prevented = true; }, ...extra };
        const pending = listeners[type](event);
        return { event, pending };
    };
    return {
        rows, inputs, rosterRows, requests, status, buttons, document, dispatch,
        page(indices) { rosterRows.forEach((row, index) => { row.hidden = !indices.includes(index); }); observer(); },
        quarter(value) { dispatch('capsule:change', null, { detail: { name: 'quarter_period', value: String(value) } }); },
        edit(row, quarter, value) { rows[row].inputs[quarter - 1].value = value; dispatch('input', rows[row].inputs[quarter - 1]); },
    };
}

for (const quarter of [1, 2, 3, 4]) {
    test(`Q${quarter}: vertical Tab, reverse Tab, read-only fields and focus escape`, () => {
        const s = setup();
        s.quarter(quarter);
        const field = row => s.rows[row].inputs[quarter - 1];
        assert.equal(s.document.activeElement, field(0));
        for (const input of s.inputs) {
            const active = input.name.startsWith(`q${quarter}[`);
            assert.equal(input.readOnly, !active);
            assert.equal(input.tabIndex, active ? 0 : -1);
        }
        assert.equal(s.dispatch('keydown', field(0), { key: 'Tab' }).event.prevented, true);
        assert.equal(s.document.activeElement, field(1));
        s.dispatch('keydown', field(1), { key: 'Tab', shiftKey: true });
        assert.equal(s.document.activeElement, field(0));
        assert.equal(s.dispatch('keydown', field(0), { key: 'Tab', shiftKey: true }).event.prevented, false);
        assert.equal(s.dispatch('keydown', field(14), { key: 'Tab' }).event.prevented, false);
        assert.equal(s.rows[15].hidden, true);
        assert.equal(s.requests.length, 0);
    });
}

test('pagination/search hides grade rows and quarter switching preserves all entries', () => {
    const s = setup();
    s.edit(0, 2, '91.25');
    s.page([15, 16]);
    s.quarter(2);
    assert.equal(s.document.activeElement, s.rows[15].inputs[1]);
    s.dispatch('keydown', s.rows[15].inputs[1], { key: 'Tab' });
    assert.equal(s.document.activeElement, s.rows[16].inputs[1]);
    assert.equal(s.dispatch('keydown', s.rows[16].inputs[1], { key: 'Tab' }).event.prevented, false);
    s.page([0, 2]); // Non-adjacent matches retain their table order.
    s.quarter(4);
    s.dispatch('keydown', s.rows[0].inputs[3], { key: 'Tab' });
    assert.equal(s.document.activeElement, s.rows[2].inputs[3]);
    s.quarter(2);
    assert.equal(s.rows[0].inputs[1].value, '91.25');
    assert.equal(s.rows[0].inputs[0].value, '80');
    assert.equal(s.rows[0].dirty, true);
});

test('numeric bounds, running final, pass/fail, and accidental input protection', () => {
    const s = setup();
    s.quarter(2);
    s.edit(0, 2, '70');
    assert.equal(s.rows[0].final.textContent, '75.00');
    assert.match(s.rows[0].remark.innerHTML, /Passed/);
    s.edit(0, 2, '69');
    assert.match(s.rows[0].remark.innerHTML, /Failed/);
    s.edit(0, 2, '150');
    s.dispatch('change', s.rows[0].inputs[1]);
    assert.equal(s.rows[0].inputs[1].value, '100');
    s.edit(0, 2, '-2');
    s.dispatch('change', s.rows[0].inputs[1]);
    assert.equal(s.rows[0].inputs[1].value, '0');
    s.edit(0, 2, '81.25');
    s.dispatch('change', s.rows[0].inputs[1]);
    assert.equal(s.rows[0].inputs[1].value, '81.25');
    for (const key of ['ArrowUp', 'ArrowDown', 'Enter']) {
        assert.equal(s.dispatch('keydown', s.rows[0].inputs[1], { key }).event.prevented, true);
    }
    s.rows[0].inputs[1].focus();
    assert.equal(s.dispatch('wheel', s.rows[0].inputs[1]).event.prevented, true);
});

test('row save preserves other edits, blocks duplicates and retains edits made during save', async () => {
    const s = setup();
    s.edit(0, 2, '88');
    s.edit(1, 3, '91');
    const first = s.dispatch('submit', null, { submitter: { name: 'save_student', value: '1' } });
    assert.equal(s.requests[0].options.body.values.get('save_student'), '1');
    assert.equal(s.requests[0].options.body.values.get('q2[1]'), '88');
    assert.equal(s.buttons.every(button => button.disabled), true);
    await s.dispatch('submit', null).pending;
    assert.equal(s.requests.length, 1);
    s.edit(0, 2, '89');
    s.requests[0].resolve({ ok: true, json: async () => ({ message: '1 student saved.', saved_ids: [1] }) });
    await first.pending;
    assert.equal(s.rows[0].dirty, true);
    assert.equal(s.rows[1].dirty, true);
    assert.equal(s.rows[1].inputs[2].value, '91');
    assert.equal(s.status.textContent, '1 student saved.');
    assert.equal(s.buttons.some(button => button.disabled), false);
    const all = s.dispatch('submit', null);
    assert.equal(s.requests[1].options.body.values.has('save_student'), false);
    s.requests[1].resolve({ ok: true, json: async () => ({ message: 'Saved.', saved_ids: [1] }) });
    await all.pending;
    assert.equal(s.rows[0].dirty, false);
    assert.equal(s.rows[1].dirty, true); // Skipped rows are never marked saved.
});

test('save errors retain entries, display feedback and allow retry', async () => {
    const s = setup();
    s.edit(0, 1, '95');
    const save = s.dispatch('submit', null);
    s.requests[0].resolve({ ok: false });
    await save.pending;
    assert.match(s.status.textContent, /Could not confirm/);
    assert.equal(s.rows[0].inputs[0].value, '95');
    assert.equal(s.rows[0].dirty, true);
    assert.equal(s.buttons.some(button => button.disabled), false);
});
