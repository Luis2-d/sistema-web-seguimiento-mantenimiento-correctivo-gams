import assert from 'node:assert/strict';
import test from 'node:test';
import { calculateTomTomRoute } from '../../resources/js/tomtom-routing.js';

const input = {
    apiKey: 'clave-de-prueba',
    origin: { lat: -17.403, lng: -66.04 },
    destination: { lat: -17.397, lng: -66.037 },
};

function validRoute() {
    return {
        routes: [{
            legs: [
                { points: [{ latitude: -17.403, longitude: -66.04 }] },
                { points: [{ latitude: -17.4, longitude: -66.038 }, { latitude: -17.397, longitude: -66.037 }] },
            ],
            summary: { lengthInMeters: 1500, travelTimeInSeconds: 240 },
        }],
    };
}

test('consulta TomTom Orbis v2 con coordenadas y reúne los tramos de la ruta', async (t) => {
    const controller = new AbortController();
    const fetchMock = t.mock.method(globalThis, 'fetch', async (address, options) => {
        const url = new URL(address);
        assert.equal(url.origin, 'https://api.tomtom.com');
        assert.equal(url.pathname, '/maps/orbis/routing/calculateRoute/-17.403,-66.04:-17.397,-66.037/json');
        assert.deepEqual(Object.fromEntries(url.searchParams), {
            apiVersion: '2', key: input.apiKey, travelMode: 'car', routeRepresentation: 'polyline',
        });
        assert.equal(options.credentials, 'omit');
        assert.equal(options.signal, controller.signal);
        return Response.json(validRoute());
    });

    assert.deepEqual(await calculateTomTomRoute({ ...input, signal: controller.signal }), {
        points: [[-17.403, -66.04], [-17.4, -66.038], [-17.397, -66.037]],
        lengthInMeters: 1500,
        travelTimeInSeconds: 240,
    });
    assert.equal(fetchMock.mock.callCount(), 1);
});

test('rechaza la clave o coordenadas inválidas antes de consultar la API', async (t) => {
    const fetchMock = t.mock.method(globalThis, 'fetch', async () => assert.fail('No debe consultar la API'));
    for (const apiKey of ['', '   ', undefined]) {
        await assert.rejects(calculateTomTomRoute({ ...input, apiKey }), /Falta configurar la clave/);
    }
    for (const position of [null, {}, { lat: NaN, lng: 0 }, { lat: 0, lng: Infinity },
        { lat: -91, lng: 0 }, { lat: 0, lng: 181 }, { lat: '0', lng: 0 }]) {
        await assert.rejects(calculateTomTomRoute({ ...input, origin: position }), /ubicaciones válidas/);
        await assert.rejects(calculateTomTomRoute({ ...input, destination: position }), /ubicaciones válidas/);
    }
    assert.equal(fetchMock.mock.callCount(), 0);
});

test('rechaza respuestas sin ruta, puntos suficientes o resumen válido', async (t) => {
    const malformed = [null, {}, { routes: [] }, { routes: [{ legs: null }] }];
    const variants = [
        (route) => { route.legs = []; },
        (route) => { route.legs = [null]; },
        (route) => { route.legs[0].points = []; },
        (route) => { route.legs = [route.legs[0]]; },
        (route) => { route.legs[0].points[0] = null; },
        (route) => { route.legs[0].points[0].latitude = 91; },
        (route) => { route.summary = null; },
        (route) => { route.summary.lengthInMeters = -1; },
        (route) => { route.summary.travelTimeInSeconds = '240'; },
    ];
    for (const mutate of variants) {
        const data = validRoute();
        mutate(data.routes[0]);
        malformed.push(data);
    }

    let responseData;
    t.mock.method(globalThis, 'fetch', async () => Response.json(responseData));
    for (const data of malformed) {
        responseData = data;
        await assert.rejects(calculateTomTomRoute(input), /no devolvió una ruta válida/);
    }
});

test('rechaza una respuesta que no contiene JSON', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => new Response('<html>Error</html>'));
    await assert.rejects(calculateTomTomRoute(input), /no devolvió una ruta válida/);
});

test('explica los errores de API sin exponer su respuesta ni la clave', async (t) => {
    let status;
    t.mock.method(globalThis, 'fetch', async () => new Response(input.apiKey, { status }));
    for (const [code, message] of [
        [401, /clave actual/], [403, /clave actual/], [429, /demasiadas consultas/],
        [400, /Cambia el origen/], [500, /servicio de rutas/],
    ]) {
        status = code;
        await assert.rejects(calculateTomTomRoute(input), (error) => {
            assert.match(error.message, message);
            assert.ok(!error.message.includes(input.apiKey));
            return true;
        });
    }
});

test('explica fallos de red sin exponer datos de la petición', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => { throw new TypeError(input.apiKey); });
    await assert.rejects(calculateTomTomRoute(input), (error) => {
        assert.match(error.message, /Revisa tu conexión/);
        assert.ok(!error.message.includes(input.apiKey));
        return true;
    });
});

test('conserva AbortError al cancelar la consulta o la lectura de la respuesta', async (t) => {
    const aborted = new DOMException('Consulta cancelada', 'AbortError');
    const fetchMock = t.mock.method(globalThis, 'fetch', async () => { throw aborted; });
    await assert.rejects(calculateTomTomRoute(input), (error) => error === aborted);
    fetchMock.mock.mockImplementation(async () => ({
        ok: true, status: 200, json: async () => { throw aborted; },
    }));
    await assert.rejects(calculateTomTomRoute(input), (error) => error === aborted);
});
