(() => {
    const school = document.getElementById('school_id');
    const equipment = document.getElementById('equipment_id');
    const help = document.getElementById('equipment-help');

    if (!school || !equipment || !help) return;

    const emptyOption = equipment.options[0];
    const options = Array.from(equipment.options).slice(1);

    function updateEquipment() {
        const selectedId = equipment.value;
        const matching = options.filter(option => option.dataset.schoolId === school.value);

        equipment.replaceChildren(emptyOption, ...matching);
        equipment.value = matching.some(option => option.value === selectedId) ? selectedId : '';
        help.textContent = !school.value
            ? 'Seleccione una unidad educativa para ver sus equipos registrados.'
            : matching.length
                ? 'Equipos registrados en la unidad educativa seleccionada.'
                : 'Esta unidad educativa no tiene equipos registrados. Puede enviar la solicitud sin equipo específico.';
    }

    school.addEventListener('change', updateEquipment);
    updateEquipment();
})();
