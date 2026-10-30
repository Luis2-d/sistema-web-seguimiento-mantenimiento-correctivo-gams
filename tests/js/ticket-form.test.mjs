import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../public/js/ticket-form.js', import.meta.url), 'utf8');
const choices = (...ids) => ['', ...ids];

function form(schoolId = '1', equipmentId = '') {
    const listeners = {};
    const school = {
        value: schoolId,
        addEventListener: (event, handler) => { listeners[event] = handler; },
    };
    const equipment = {
        value: equipmentId,
        options: [
            { value: '', dataset: {} },
            { value: '11', dataset: { schoolId: '1' } },
            { value: '12', dataset: { schoolId: '1' } },
            { value: '21', dataset: { schoolId: '2' } },
        ],
        replaceChildren(...options) { this.options = options; },
    };
    const help = { textContent: '' };
    const elements = { school_id: school, equipment_id: equipment, 'equipment-help': help };
    vm.runInNewContext(script, { document: { getElementById: id => elements[id] } });

    return {
        equipment,
        help,
        changeSchool(id) { school.value = id; listeners.change(); },
    };
}

test('shows all equipment of the selected school and clears incompatible selections on change', () => {
    const page = form('1', '12');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices('11', '12'));
    assert.equal(page.equipment.value, '12');
    page.changeSchool('2');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices('21'));
    assert.equal(page.equipment.value, '');
    page.changeSchool('1');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices('11', '12'));
    assert.equal(page.equipment.value, '');
});

test('restores a previous selection for another school after validation', () => {
    const page = form('2', '21');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices('21'));
    assert.equal(page.equipment.value, '21');
});

test('keeps the optional empty choice when no school or no equipment is available', () => {
    const page = form('', '11');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices());
    assert.equal(page.equipment.value, '');
    assert.match(page.help.textContent, /Seleccione una unidad educativa/);
    page.changeSchool('3');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices());
    assert.match(page.help.textContent, /no tiene equipos registrados/);
    page.changeSchool('2');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices('21'));
});

test('discards a previous unregistered brand choice', () => {
    const page = form('', 'brand:ASUS');
    assert.equal(page.equipment.value, '');
    page.changeSchool('1');
    assert.equal(page.equipment.value, '');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices('11', '12'));
    page.changeSchool('3');
    assert.equal(page.equipment.value, '');
    assert.deepEqual(page.equipment.options.map(option => option.value), choices());
});
