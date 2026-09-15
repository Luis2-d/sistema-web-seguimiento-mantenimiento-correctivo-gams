import { calculateTomTomRoute } from './tomtom-routing.js';

export function initializeRoutePlanner({ L, map, mapElement, panel, apiKey, geolocation = globalThis.navigator?.geolocation }) {
    if (!panel) return null;

    const destinationLabel = panel.querySelector('[data-route-destination]');
    const status = panel.querySelector('[data-route-status]');
    const summary = panel.querySelector('[data-route-summary]');
    const pickButton = panel.querySelector('[data-route-pick]');
    const layers = L.layerGroup().addTo(map);
    let destination = null;
    let origin = null;
    let choosingOrigin = false;
    let pending = null;
    let version = 0;

    function cancelPending() {
        version += 1;
        pending?.abort();
        pending = null;
    }

    function setStatus(message, isError = false) {
        status.textContent = message;
        status.classList.toggle('text-danger', isError);
    }

    function setChoosingOrigin(active) {
        choosingOrigin = active;
        pickButton.setAttribute('aria-pressed', String(active));
        mapElement.classList.toggle('map-pick-origin', active);
    }

    function clearDrawing() {
        layers.clearLayers();
        summary.hidden = true;
        summary.textContent = '';
    }

    function markDestination() {
        const label = panel.ownerDocument.createElement('span');
        label.textContent = `Destino: ${destination.name}`;
        L.circleMarker([destination.latitude, destination.longitude], {
            radius: 9, color: '#166534', fillColor: '#fff', fillOpacity: 1, weight: 3,
        }).bindTooltip(label).addTo(layers);
    }

    function chooseOnMap(message = 'Haz clic en el mapa para elegir desde dónde sales.') {
        if (!destination) return;
        cancelPending();
        origin = null;
        clearDrawing();
        markDestination();
        setChoosingOrigin(true);
        setStatus(message);
    }

    async function calculate(point) {
        if (!destination) return;
        cancelPending();
        origin = point;
        setChoosingOrigin(false);
        clearDrawing();
        markDestination();
        L.circleMarker([point.lat, point.lng], {
            radius: 7, color: '#fff', fillColor: '#166534', fillOpacity: 1, weight: 3,
        }).bindTooltip('Punto de partida').addTo(layers);

        const requestVersion = version;
        const controller = new AbortController();
        pending = controller;
        const timer = setTimeout(() => controller.abort(), 15000);
        setStatus('Calculando la ruta con TomTom…');

        try {
            // Envía el origen y el destino a TomTom para obtener el recorrido por calles.
            const route = await calculateTomTomRoute({
                apiKey,
                origin: point,
                destination: { lat: destination.latitude, lng: destination.longitude },
                signal: controller.signal,
            });
            if (requestVersion !== version) return;

            // Dibuja los puntos que devuelve la API y encuadra toda la ruta.
            L.polyline(route.points, { color: '#fff', weight: 9, opacity: 0.9 }).addTo(layers);
            const line = L.polyline(route.points, { color: '#166534', weight: 5, opacity: 1 }).addTo(layers);
            map.fitBounds(line.getBounds()
                .extend([point.lat, point.lng])
                .extend([destination.latitude, destination.longitude]), { padding: [35, 35], maxZoom: 17 });

            const distance = route.lengthInMeters < 1000
                ? `${Math.round(route.lengthInMeters)} m`
                : `${(route.lengthInMeters / 1000).toLocaleString('es-BO', { maximumFractionDigits: 1 })} km`;
            summary.textContent = `${distance} · ${Math.max(1, Math.ceil(route.travelTimeInSeconds / 60))} min aprox. en vehículo`;
            summary.hidden = false;
            setStatus('La línea verde muestra el recorrido hasta la unidad educativa.');
        } catch (error) {
            if (requestVersion !== version) return;
            setStatus(error.name === 'AbortError'
                ? 'TomTom tardó demasiado. Vuelve a intentarlo o elige otro punto de partida.'
                : error.message, true);
        } finally {
            clearTimeout(timer);
            if (pending === controller) pending = null;
        }
    }

    function useCurrentLocation() {
        if (!destination) return;
        cancelPending();
        origin = null;
        setChoosingOrigin(false);
        clearDrawing();
        markDestination();
        if (!geolocation) {
            chooseOnMap('Tu ubicación no está disponible. Elige el punto de partida haciendo clic en el mapa.');
            return;
        }

        const requestVersion = version;
        setStatus('Buscando tu ubicación. Permite el acceso si el navegador lo solicita.');
        const unavailable = () => {
            if (requestVersion !== version) return;
            chooseOnMap('No se pudo obtener tu ubicación. Haz clic en el mapa para indicar desde dónde sales.');
        };

        try {
            // El navegador pide permiso y entrega la ubicación que se usará como origen.
            geolocation.getCurrentPosition((position) => {
                if (requestVersion !== version) return;
                calculate({ lat: position.coords.latitude, lng: position.coords.longitude });
            }, unavailable, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
        } catch {
            unavailable();
        }
    }

    function start(location) {
        cancelPending();
        destination = location;
        destinationLabel.textContent = location.name;
        panel.hidden = false;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        map.closePopup();
        map.setView([location.latitude, location.longitude], 14);
        if (origin) calculate(origin);
        else useCurrentLocation();
    }

    function close() {
        cancelPending();
        destination = null;
        origin = null;
        setChoosingOrigin(false);
        clearDrawing();
        panel.hidden = true;
    }

    map.on('click', (event) => {
        if (choosingOrigin) calculate(event.latlng);
    });
    panel.querySelector('[data-route-current]').addEventListener('click', useCurrentLocation);
    pickButton.addEventListener('click', () => chooseOnMap());
    panel.querySelector('[data-route-clear]').addEventListener('click', close);

    return { start, close };
}
