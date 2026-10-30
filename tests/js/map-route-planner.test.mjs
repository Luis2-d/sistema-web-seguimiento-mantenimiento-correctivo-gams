import assert from 'node:assert/strict';
import test from 'node:test';
import { initializeRoutePlanner } from '../../resources/js/map-route-planner.js';

const firstSchool = { id: 1, name: 'Colegio Carlos Peredo', latitude: -17.397, longitude: -66.037 };
const secondSchool = { id: 2, name: 'Segunda unidad', latitude: -17.41, longitude: -66.05 };
const origin = { latitude: -17.403, longitude: -66.04 };
const firstPoints = [[-17.403, -66.04], [-17.4, -66.038], [-17.397, -66.037]];
const secondPoints = [[-17.403, -66.04], [-17.407, -66.045], [-17.41, -66.05]];

function routeResponse(points = firstPoints) {
    return Response.json({
        routes: [{
            legs: [{ points: points.map(([latitude, longitude]) => ({ latitude, longitude })) }],
            summary: { lengthInMeters: 1500, travelTimeInSeconds: 240 },
        }],
    });
}

function element() {
    const classes = new Set();
    const attributes = new Map();
    const events = new Map();
    return {
        textContent: '', hidden: false, disabled: false, style: {},
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, enabled = !classes.has(name)) => enabled ? classes.add(name) : classes.delete(name),
        },
        setAttribute: (name, value) => attributes.set(name, String(value)),
        getAttribute: (name) => attributes.get(name),
        removeAttribute: (name) => attributes.delete(name),
        addEventListener: (name, listener) => events.set(name, listener),
        click: () => events.get('click')?.({ preventDefault() {} }),
        scrollIntoView() {}, focus() {}, append() {},
    };
}

function setup(t, { secureContext = true, hasGeolocation = true } = {}) {
    const controls = Object.fromEntries(['destination', 'status', 'summary', 'pick', 'current', 'clear']
        .map((name) => [name, element()]));
    const panel = element();
    panel.hidden = true;
    panel.querySelector = (selector) => controls[selector.slice('[data-route-'.length, -1)];
    panel.ownerDocument = { createElement: element };
    const mapElement = element();
    const events = new Map();
    const map = {
        layers: [],
        on: (event, handler) => events.set(event, handler),
        click: (latlng) => events.get('click')?.({ latlng }),
        fitBounds() {}, closePopup() {}, setView() {},
    };
    const groups = [];
    const drawn = [];
    const L = {
        layerGroup: () => {
            const group = {
                layers: [],
                addTo() { groups.push(this); return this; },
                clearLayers() { this.layers = []; return this; },
                addLayer(layer) { this.layers.push(layer); return this; },
            };
            return group;
        },
        circleMarker: (point) => ({
            point,
            addTo(group) { group.addLayer(this); return this; },
            bindPopup() { return this; },
            bindTooltip() { return this; },
        }),
        polyline: (points) => {
            const line = {
                points,
                addTo(group) { group.addLayer(this); drawn.push(this); return this; },
                getBounds() { return { extend() { return this; } }; },
                bringToFront() { return this; },
            };
            return line;
        },
    };
    const positionRequests = [];
    const geolocation = {
        getCurrentPosition(success, failure) { positionRequests.push({ success, failure }); },
    };
    const fetchMock = t.mock.method(globalThis, 'fetch', async () => routeResponse());
    const planner = initializeRoutePlanner({ L, map, mapElement, panel, apiKey: 'clave-de-prueba', geolocation: hasGeolocation ? geolocation : null, secureContext });
    return { planner, panel, controls, map, mapElement, groups, drawn, positionRequests, fetchMock };
}

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('usa la ubicación actual para dibujar la ruta y mostrar distancia y tiempo', async (t) => {
    const view = setup(t);
    view.planner.start(firstSchool);
    assert.equal(view.panel.hidden, false);
    assert.equal(view.controls.destination.textContent, firstSchool.name);
    assert.equal(view.fetchMock.mock.callCount(), 0);

    view.positionRequests[0].success({ coords: origin });
    await settle();

    assert.equal(view.fetchMock.mock.callCount(), 1);
    assert.deepEqual(view.drawn.at(-1).points, firstPoints);
    assert.equal(view.controls.summary.hidden, false);
    assert.match(view.controls.summary.textContent, /1[.,]5\s*km/);
    assert.match(view.controls.summary.textContent, /4\s*min/);
});

test('si se deniega la ubicación, permite elegir el punto de partida en el mapa', async (t) => {
    const view = setup(t);
    view.planner.start(firstSchool);
    view.positionRequests[0].failure({ code: 1 });
    await settle();
    assert.equal(view.fetchMock.mock.callCount(), 0);
    assert.equal(view.controls.destination.textContent, firstSchool.name);

    view.controls.pick.click();
    assert.equal(view.mapElement.classList.contains('map-pick-origin'), true);
    view.map.click({ lat: origin.latitude, lng: origin.longitude });
    await settle();

    assert.equal(view.fetchMock.mock.callCount(), 1);
    assert.deepEqual(view.drawn.at(-1).points, firstPoints);
    assert.equal(view.mapElement.classList.contains('map-pick-origin'), false);
});

test('cambiar el destino o cerrar la ruta descarta ubicaciones recibidas tarde', async (t) => {
    const view = setup(t);
    view.planner.start(firstSchool);
    view.planner.start(secondSchool);
    view.positionRequests[0].success({ coords: origin });
    await settle();
    assert.equal(view.fetchMock.mock.callCount(), 0);
    assert.equal(view.controls.destination.textContent, secondSchool.name);

    view.planner.close();
    view.positionRequests[1].success({ coords: origin });
    await settle();
    assert.equal(view.panel.hidden, true);
    assert.equal(view.fetchMock.mock.callCount(), 0);
    assert.equal(view.drawn.length, 0);
});

test('una respuesta anterior de TomTom no sustituye la ruta del nuevo destino', async (t) => {
    const view = setup(t);
    const pending = [];
    view.fetchMock.mock.mockImplementation((url, options) => new Promise((resolve) => {
        pending.push({ url, signal: options.signal, resolve });
    }));

    view.planner.start(firstSchool);
    view.positionRequests[0].success({ coords: origin });
    await settle();
    view.planner.start(secondSchool);
    await settle();
    assert.equal(pending.length, 2);
    assert.equal(pending[0].signal.aborted, true);

    pending[1].resolve(routeResponse(secondPoints));
    await settle();
    assert.deepEqual(view.drawn.at(-1).points, secondPoints);
    const drawingCount = view.drawn.length;
    const currentStatus = view.controls.status.textContent;

    pending[0].resolve(routeResponse(firstPoints));
    await settle();
    assert.equal(view.drawn.length, drawingCount);
    assert.equal(view.controls.destination.textContent, secondSchool.name);
    assert.equal(view.controls.status.textContent, currentStatus);
});

test('cerrar la ruta cancela la consulta y evita dibujar una respuesta tardía', async (t) => {
    const view = setup(t);
    let complete;
    let signal;
    view.fetchMock.mock.mockImplementation((url, options) => {
        signal = options.signal;
        return new Promise((resolve) => { complete = resolve; });
    });
    view.planner.start(firstSchool);
    view.positionRequests[0].success({ coords: origin });
    await settle();
    view.controls.clear.click();
    assert.equal(signal.aborted, true);
    complete(routeResponse());
    await settle();

    assert.equal(view.panel.hidden, true);
    assert.equal(view.drawn.length, 0);
    assert.ok(view.groups.every((group) => group.layers.length === 0));
});

// Por HTTP se debe explicar el bloqueo y conservar la selección manual.
test('por HTTP explica el bloqueo y calcula la ruta al tocar el mapa', async (t) => {
    const view = setup(t, { secureContext: false });
    view.planner.start(firstSchool);
    assert.equal(view.positionRequests.length, 0);
    assert.match(view.controls.status.textContent, /HTTPS/);
    assert.equal(view.mapElement.classList.contains('map-pick-origin'), true);
    view.map.click({ lat: origin.latitude, lng: origin.longitude });
    await settle();
    assert.deepEqual(view.drawn.at(-1).points, firstPoints);
});

test('sin soporte de ubicación mantiene disponible la selección manual', (t) => {
    const view = setup(t, { hasGeolocation: false });
    view.planner.start(firstSchool);
    assert.match(view.controls.status.textContent, /Este navegador/);
    assert.equal(view.controls.pick.getAttribute('aria-pressed'), 'true');
});

for (const [code, message] of [[1, /bloqueado/], [2, /activada/], [3, /tiempo de espera/]]) {
    test('explica el fallo de ubicación ' + code + ' y permite reintentar', (t) => {
        const view = setup(t);
        view.planner.start(firstSchool);
        view.positionRequests[0].failure({ code });
        assert.match(view.controls.status.textContent, message);
        assert.equal(view.controls.pick.getAttribute('aria-pressed'), 'true');
        view.controls.current.click();
        assert.equal(view.positionRequests.length, 2);
        assert.equal(view.controls.pick.getAttribute('aria-pressed'), 'false');
    });
}
