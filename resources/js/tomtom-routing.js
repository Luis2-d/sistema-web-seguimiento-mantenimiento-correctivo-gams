function validPosition(position) {
    return position && Number.isFinite(position.lat) && Math.abs(position.lat) <= 90
        && Number.isFinite(position.lng) && Math.abs(position.lng) <= 180;
}

export async function calculateTomTomRoute({ apiKey, origin, destination, signal }) {
    if (typeof apiKey !== 'string' || !apiKey.trim()) {
        throw new Error('Falta configurar la clave de TomTom para calcular rutas.');
    }

    if (!validPosition(origin) || !validPosition(destination)) {
        throw new Error('Selecciona un origen y un destino con ubicaciones válidas.');
    }

    // TomTom recibe primero la latitud y después la longitud de cada ubicación.
    const locations = `${origin.lat},${origin.lng}:${destination.lat},${destination.lng}`;
    const url = new URL(`https://api.tomtom.com/maps/orbis/routing/calculateRoute/${locations}/json`);
    url.search = new URLSearchParams({
        apiVersion: '2',
        key: apiKey.trim(),
        travelMode: 'car',
        routeRepresentation: 'polyline',
    }).toString();

    let response;
    try {
        // La API calcula el recorrido por calles; no necesita las cookies del sistema.
        response = await fetch(url, { credentials: 'omit', signal });
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error('No se pudo conectar con TomTom. Revisa tu conexión e inténtalo de nuevo.');
    }

    if (response.status === 401 || response.status === 403) {
        throw new Error('El servicio de rutas no está disponible con la clave actual de TomTom.');
    }
    if (response.status === 429) {
        throw new Error('TomTom recibió demasiadas consultas. Espera un momento e inténtalo de nuevo.');
    }
    if (response.status === 400) {
        throw new Error('TomTom no pudo calcular la ruta. Cambia el origen e inténtalo de nuevo.');
    }
    if (!response.ok) {
        throw new Error('El servicio de rutas de TomTom no está disponible. Inténtalo de nuevo.');
    }

    const invalidRoute = 'TomTom no devolvió una ruta válida. Cambia el origen e inténtalo de nuevo.';
    let data;
    try {
        data = await response.json();
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new Error(invalidRoute);
    }

    const route = data?.routes?.[0];
    if (!Array.isArray(route?.legs) || !route.legs.length) throw new Error(invalidRoute);

    // Los puntos de todos los tramos forman la línea que se dibuja en el mapa.
    const points = [];
    for (const leg of route.legs) {
        if (!Array.isArray(leg?.points) || !leg.points.length) throw new Error(invalidRoute);
        for (const point of leg.points) {
            if (!validPosition({ lat: point?.latitude, lng: point?.longitude })) {
                throw new Error(invalidRoute);
            }
            points.push([point.latitude, point.longitude]);
        }
    }

    const { lengthInMeters, travelTimeInSeconds } = route.summary || {};
    if (points.length < 2 || !Number.isFinite(lengthInMeters) || lengthInMeters < 0
        || !Number.isFinite(travelTimeInSeconds) || travelTimeInSeconds < 0) {
        throw new Error(invalidRoute);
    }

    return { points, lengthInMeters, travelTimeInSeconds };
}
