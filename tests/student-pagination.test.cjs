// Run with: node --test tests/student-pagination.test.cjs
// A small DOM harness exercises the real controller without browser dependencies.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const controller = fs.readFileSync(path.join(__dirname, '../js/student-pagination.js'), 'utf8');

const catalog = {
  7: ['Camia', 'Orchids', 'Rose'],
  8: ['Amethyst', 'Emerald', 'Jade'],
  9: ['Peace', 'Faith', 'Hope'],
  10: ['Aristotle', 'Newton', 'Einsten'],
  11: { Academic: ['Kepler', 'Smith'], Techpro: ['Carver', 'Prescott'] },
  12: { Academic: ['Pythagoras'], Techpro: ['Hilton', 'Burbank'] },
};

function setup({ placements = catalog, records = [], archived = false } = {}) {
  let pager;
  class Element {
    constructor() {
      this.dataset = {}; this.children = []; this.parts = {}; this.listeners = {};
      this.value = ''; this.textContent = ''; this.hidden = false;
      this.classList = { toggle() {} };
    }
    append(...items) { this.children.push(...items); }
    add(item) { this.append(item); }
    replaceChildren(...items) { this.children = items; }
    get options() { return this.children; }
    setAttribute(name, value) { this[name] = value; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    dispatchEvent(event) {
      this['on' + event.type]?.(event);
      for (const fn of this.listeners[event.type] || []) fn(event);
    }
    click() { this.dispatchEvent({ type: 'click' }); }
    querySelectorAll() { return this.children; }
    querySelector(selector) { return this.parts[selector] ||= new Element(); }
    closest(selector) {
      return selector === '.overflow-hidden' ? card : selector === 'table' ? { scrollIntoView() {} } : field;
    }
    insertAdjacentElement(_, element) { pager = element; }
  }
  const card = new Element(), field = new Element(), gradePills = new Element();
  const ids = {};
  for (const id of ['student-rows', 'student-search', 'grade-filter', 'section-filter', 'status-filter', 'clear-student-filters']) ids[id] = new Element();
  const rows = ids['student-rows'];
  rows.dataset = { pageSize: '15', total: String(records.length), emptyTitle: archived ? 'No archived students.' : 'No students have been added yet.' };
  if (placements) {
    ids['strand-filter'] = new Element(); ids['strand-filter-container'] = new Element();
    ids['section-filter'].dataset.alwaysVisible = 'true';
    rows.dataset.sectionCatalog = JSON.stringify(placements);
    const honors = {};
    for (const [grade, entry] of Object.entries(placements)) {
      honors[grade] = {};
      for (const [strand, names] of Object.entries(Number(grade) >= 11 ? entry : { '': entry })) honors[grade][strand] = names[0];
    }
    rows.dataset.honorSections = JSON.stringify(honors);
  } else {
    rows.dataset.sectionMap = JSON.stringify({ 8: ['Jade', 'Emerald'] });
  }
  const studentRows = records.map(record => {
    const row = new Element();
    row.dataset = { grade: '8', section: 'Jade', strand: '', status: 'Enrolled', search: 'Jane Santos 123456789012', ...record };
    row.textContent = row.dataset.search;
    rows.append(row); return row;
  });
  const document = {
    getElementById: id => ids[id], createElement: () => new Element(),
    querySelector: selector => selector.includes('grade_filter') ? gradePills : null,
  };
  const pillResets = [];
  vm.runInNewContext(controller, {
    document, window: { CapsuleControl: { setValue: (...args) => pillResets.push(args) } },
    Option: function (label, value) { this.textContent = label; this.value = value; },
    Event: function (type) { this.type = type; },
  });
  return {
    ids, rows, gradePills, field, pillResets,
    get pager() { return pager; },
    change(id, value) { ids[id].value = value; ids[id].dispatchEvent({ type: 'change' }); },
    select(name, strand = '') {
      const option = ids['section-filter'].options.find(option => {
        if (!option.value) return false;
        const [, optionStrand, optionName] = JSON.parse(option.value);
        return optionName === name && optionStrand === strand;
      });
      assert.ok(option, `Missing section ${name} / ${strand}`);
      this.change('section-filter', option.value);
    },
    visible() { return studentRows.filter(row => !row.hidden); },
    labels() { return ids['section-filter'].options.map(option => option.textContent); },
  };
}

test('every registered section remains selectable with an empty roster', () => {
  const app = setup();
  assert.equal(app.ids['section-filter'].disabled, true);
  assert.deepEqual(app.labels(), ['All Sections']);
  for (const [grade, entry] of Object.entries(catalog)) {
    app.change('grade-filter', grade);
    const groups = Number(grade) >= 11 ? entry : { '': entry };
    assert.equal(app.ids['strand-filter-container'].hidden, Number(grade) < 11);
    for (const [strand, names] of Object.entries(groups)) {
      if (strand) app.change('strand-filter', strand);
      assert.deepEqual(app.labels(), ['All Sections', ...names.map((name, i) => name + (i === 0 ? ' (Honor Section)' : ''))]);
      for (const name of names) {
        app.select(name, strand);
        assert.equal(app.rows.children.at(-1).hidden, false);
        assert.equal(app.pager.children[0].textContent, '0–0 of 0');
      }
    }
  }
});

test('All Strands disambiguates same-name sections and keeps overflow sections', () => {
  const placements = structuredClone(catalog);
  placements[11].Academic.push('Shared', 'Grade 11 - Academic - Section 4');
  placements[11].Techpro.push('Shared');
  const app = setup({ placements, records: [
    { grade: '11', strand: 'Academic', section: 'Shared' },
    { grade: '11', strand: 'Techpro', section: 'Shared' },
  ] });
  app.change('grade-filter', '11');
  assert(app.labels().includes('Shared — Academic'));
  assert(app.labels().includes('Shared — Techpro'));
  assert(app.labels().includes('Grade 11 - Academic - Section 4 — Academic'));
  app.select('Shared', 'Academic'); assert.equal(app.visible().length, 1);
  assert.equal(app.visible()[0].dataset.strand, 'Academic');
  app.change('strand-filter', 'Techpro'); assert.equal(app.ids['section-filter'].value, '');
  app.select('Shared', 'Techpro'); assert.equal(app.visible().length, 1);
  app.change('grade-filter', '12');
  assert.equal(app.ids['strand-filter'].value, '');
  assert.equal(app.ids['section-filter'].value, '');
});

test('combined search/status/section filters reset paging and report actual results', () => {
  const app = setup({ records: [...Array.from({ length: 18 }, () => ({})), { status: 'Dropped' }, { section: 'Amethyst' }] });
  app.gradePills.dispatchEvent({ type: 'capsule:change', detail: { value: '8' } });
  app.select('Jade'); app.change('status-filter', 'Enrolled');
  assert.equal(app.visible().length, 15);
  assert.equal(app.pager.children[0].textContent, '1–15 of 18');
  app.pager.children[1].children.at(-1).onclick();
  assert.equal(app.visible().length, 3);
  assert.equal(app.pager.children[0].textContent, '16–18 of 18');
  assert.equal(app.visible()[0].querySelector('.student-index').textContent, 16);
  app.ids['student-search'].value = '123456789012'; app.ids['student-search'].dispatchEvent({ type: 'input' });
  assert.equal(app.pager.children[0].textContent, '1–15 of 18');
  app.ids['student-search'].value = 'missing'; app.ids['student-search'].dispatchEvent({ type: 'input' });
  assert.equal(app.visible().length, 0);
  assert.equal(app.pager.children[0].textContent, '0–0 of 0');
  app.ids['clear-student-filters'].click();
  for (const id of ['grade-filter', 'strand-filter', 'section-filter', 'status-filter', 'student-search']) assert.equal(app.ids[id].value, '');
  assert.equal(app.pager.children[0].textContent, '1–15 of 20');
  assert.equal(app.pillResets.at(-1)[0], 'grade_filter');
  assert.equal(app.ids['strand-filter-container'].hidden, true);
  assert.equal(app.ids['section-filter'].disabled, true);
});

test('valid section survives strand changes and All Grades clears the section', () => {
  const app = setup(); app.change('grade-filter', '11'); app.select('Smith', 'Academic');
  const selected = app.ids['section-filter'].value;
  app.change('strand-filter', 'Academic'); assert.equal(app.ids['section-filter'].value, selected);
  app.change('strand-filter', ''); assert.equal(app.ids['section-filter'].value, selected);
  app.change('grade-filter', ''); assert.equal(app.ids['section-filter'].value, '');
  assert.deepEqual(app.labels(), ['All Sections']);
});

test('archived empty state and legacy teacher filters remain supported', () => {
  const archived = setup({ archived: true });
  assert.equal(archived.rows.children.at(-1).querySelector('p:first-child').textContent, 'No archived students.');
  archived.change('grade-filter', '7'); archived.select('Rose');
  assert.equal(archived.rows.children.at(-1).querySelector('p:first-child').textContent, 'No students match the current filters.');
  const teacher = setup({ placements: null, records: [{}] });
  assert.equal(teacher.field.hidden, true);
  teacher.change('grade-filter', '8'); assert.equal(teacher.field.hidden, false);
  assert.deepEqual(teacher.labels(), ['All Sections', 'Jade', 'Emerald']);
  teacher.change('section-filter', 'Emerald'); assert.equal(teacher.visible().length, 0);
  teacher.change('section-filter', 'Jade'); assert.equal(teacher.visible().length, 1);
  teacher.change('status-filter', 'Dropped'); assert.equal(teacher.visible().length, 0);
  teacher.change('grade-filter', ''); assert.equal(teacher.field.hidden, true);
});
