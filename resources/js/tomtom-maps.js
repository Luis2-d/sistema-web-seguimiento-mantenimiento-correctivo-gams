// Leaflet permite mover el mapa y mostrar los marcadores sobre las imágenes de TomTom.
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import { initializeRoutePlanner } from './map-route-planner.js';

L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

// La vista entrega la clave de TomTom, las ubicaciones y el tipo de mapa.
const config = window.tomtomMapConfig || {};
const statusElement = document.querySelector('[data-map-status]');

function setStatus(message, isError = false) {
    if (!statusElement) {
        return;
    }

    statusElement.textContent = message;
    statusElement.className = 'map-status small mt-2 mb-0 ' + (isError ? 'text-danger' : 'text-muted');
}

function normalize(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es');
}

// Crea el mapa que se muestra en el panel o en el formulario de una unidad.
function createMap(element, center, zoom) {
    const map = L.map(element, {
        center,
        zoom,
        zoomControl: true,
    });
    const key = encodeURIComponent(config.apiKey);
    // TomTom envía el mapa por imágenes: z indica el zoom; x e y, la pieza solicitada.
    const streetLayer = L.tileLayer(
        `https://api.tomtom.com/maps/orbis/display/raster/tile/{z}/{x}/{y}?apiVersion=2&style=street-light&key=${key}`,
        {
            maxZoom: 22,
            tileSize: 256,
            attribution: '&copy; <a href="https://www.tomtom.com/">TomTom</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
        },
    );

    const layers = [streetLayer];

    if (config.mode === 'overview') {
        const satelliteLayer = L.tileLayer(
            `https://api.tomtom.com/maps/orbis/display/satellite/tile/{z}/{x}/{y}?apiVersion=2&key=${key}`,
            {
                maxNativeZoom: 19,
                maxZoom: 22,
                tileSize: 256,
                attribution: '&copy; <a href="https://www.tomtom.com/">TomTom</a> &copy; <a href="https://www.maxar.com/">Maxar</a>',
            },
        );
        layers.push(satelliteLayer);
        L.control.layers({ 'Satélite': satelliteLayer, 'Calles': streetLayer }, null, {
            collapsed: false,
            position: 'topright',
        }).addTo(map);
    }

    layers.forEach((layer) => {
        let tileErrorShown = false;
        layer.on('tileerror', () => {
            if (map.hasLayer(layer) && !tileErrorShown) {
                setStatus(layer === streetLayer
                    ? 'No se pudo cargar el mapa de calles. Inténtalo nuevamente más tarde.'
                    : 'No se pudo cargar la vista satelital. Puedes seleccionar Calles para continuar.', true);
                tileErrorShown = true;
            }
        });
    });
    layers[layers.length - 1].addTo(map);

    return map;
}

// Consulta a TomTom para obtener las coordenadas de un lugar o una dirección.
async function searchTomTom(query, center = null) {
    const url = new URL(`https://api.tomtom.com/search/2/search/${encodeURIComponent(query)}.json`);
    // Usa la clave de la API y solicita un resultado de Bolivia en español.
    const parameters = {
        key: config.apiKey,
        countrySet: 'BO',
        limit: '1',
        language: 'es-ES',
    };

    if (center && Number.isFinite(center.lat) && Number.isFinite(center.lng)) {
        parameters.lat = String(center.lat);
        parameters.lon = String(center.lng);
    }

    url.search = new URLSearchParams(parameters);
    // El navegador envía la búsqueda a TomTom y espera su respuesta.
    const response = await fetch(url, { credentials: 'omit' });

    if (!response.ok) {
        throw new Error(`TomTom respondió con ${response.status}`);
    }

    // Convierte la respuesta en datos y devuelve el primer lugar encontrado.
    const data = await response.json();
    return data.results?.[0] || null;
}

function createPopup(location, onRoute) {
    const wrapper = document.createElement('div');
    const title = document.createElement('strong');
    const address = document.createElement('p');
    const tickets = document.createElement('p');
    const coordinates = document.createElement('small');
    const planner = document.createElement('a');

    title.textContent = location.name;
    address.textContent = location.address || 'Sin dirección escrita';
    address.className = 'mb-1 mt-2';
    tickets.textContent = `${location.openTickets} solicitudes abiertas`;
    tickets.className = 'mb-1 text-muted';
    coordinates.textContent = `${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)}`;
    coordinates.className = 'd-block mb-2 text-muted';
    planner.textContent = 'Cómo llegar';
    planner.href = location.plannerUrl;
    planner.addEventListener('click', (event) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        onRoute(location);
    });

    wrapper.append(title, address, tickets, coordinates, planner);
    return wrapper;
}

// Coloca las unidades guardadas en la base de datos sobre el mapa de TomTom.
function initializeOverview() {
    const mapElement = document.getElementById('school-overview-map');
    const locations = Array.isArray(config.locations) ? config.locations : [];
    const filterInput = document.querySelector('[data-map-filter]');
    const searchButton = document.querySelector('[data-map-search-overview]');

    if (!mapElement) {
        return;
    }

    const defaultLatitude = Number(config.defaultLatitude) || -17.3975;
    const defaultLongitude = Number(config.defaultLongitude) || -66.0387;
    const first = locations[0] || { latitude: defaultLatitude, longitude: defaultLongitude };
    const map = createMap(mapElement, [first.latitude, first.longitude], 13);
    const routePlanner = initializeRoutePlanner({
        L, map, mapElement, apiKey: config.apiKey,
        panel: document.getElementById('map-route-panel'),
    });
    const markers = new Map();
    const bounds = L.latLngBounds();
    let searchMarker = null;

    function focusLocation(entry) {
        if (!map.hasLayer(entry.marker)) {
            entry.marker.addTo(map);
        }
        map.setView(entry.marker.getLatLng(), 17);
        entry.marker.openPopup();
        setStatus(`Mostrando ${entry.location.name}.`);
    }

    locations.forEach((location) => {
        const point = [location.latitude, location.longitude];
        const marker = L.marker(point, { title: location.name })
            .bindPopup(createPopup(location, (destination) => routePlanner?.start(destination)))
            .addTo(map);

        markers.set(String(location.id), { marker, location });
        bounds.extend(point);
    });

    if (locations.length > 1) {
        map.fitBounds(bounds, { padding: [45, 45], maxZoom: 17 });
    }

    document.querySelectorAll('[data-map-route]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const entry = markers.get(link.dataset.mapRoute);
            if (!entry || !routePlanner) return;
            event.preventDefault();
            routePlanner.start(entry.location);
        });
    });

    document.querySelectorAll('[data-map-focus]').forEach((button) => {
        button.addEventListener('click', () => {
            const entry = markers.get(button.dataset.mapFocus);
            if (entry) {
                focusLocation(entry);
            }
        });
    });

    function filterLocations(searchTerm) {
        const query = normalize(searchTerm);
        let visible = 0;
        const matches = [];

        document.querySelectorAll('[data-location-id]').forEach((item) => {
            const doesMatch = !query || normalize(item.dataset.searchText).includes(query);
            const entry = markers.get(item.dataset.locationId);

            item.classList.toggle('d-none', !doesMatch);
            if (entry && doesMatch && !map.hasLayer(entry.marker)) {
                entry.marker.addTo(map);
            } else if (entry && !doesMatch && map.hasLayer(entry.marker)) {
                map.removeLayer(entry.marker);
            }
            if (entry && doesMatch) {
                visible += 1;
                matches.push(entry);
            }
        });

        map.closePopup();
        return { matches, visible };
    }

    filterInput?.addEventListener('input', (event) => {
        const { visible } = filterLocations(event.target.value.trim());
        setStatus(`${visible} unidades coinciden. Pulsa Buscar para consultar también lugares en TomTom.`);
    });

    async function searchOverview() {
        const query = filterInput?.value.trim();

        if (!query) {
            setStatus('Escribe una unidad, distrito o dirección para buscar.', true);
            filterInput?.focus();
            return;
        }

        // Primero busca entre las unidades que el panel ya tiene cargadas.
        const { matches } = filterLocations(query);

        if (matches.length === 1) {
            focusLocation(matches[0]);
            return;
        }

        if (matches.length > 1) {
            const matchingBounds = L.latLngBounds(matches.map((entry) => entry.marker.getLatLng()));
            map.fitBounds(matchingBounds, { padding: [45, 45], maxZoom: 17 });
            setStatus(`${matches.length} unidades coinciden con la búsqueda.`);
            return;
        }

        searchButton?.setAttribute('disabled', 'disabled');
        setStatus('Buscando el lugar con TomTom…');

        try {
            // Consulta la API cuando ninguna unidad cargada coincide con la búsqueda.
            const result = await searchTomTom(query, map.getCenter());

            if (!result?.position) {
                setStatus('No se encontró el lugar. Prueba agregando el municipio o departamento.', true);
                return;
            }

            // El resultado se muestra como referencia temporal; no registra una unidad.
            const point = { lat: result.position.lat, lng: result.position.lon };
            const label = result.poi?.name || result.address?.freeformAddress || query;

            if (searchMarker) {
                searchMarker.setLatLng(point);
            } else {
                searchMarker = L.marker(point).addTo(map);
            }

            const popup = document.createElement('div');
            const title = document.createElement('strong');
            const address = document.createElement('p');
            title.textContent = label;
            address.textContent = result.address?.freeformAddress || 'Ubicación encontrada por TomTom';
            address.className = 'mb-0 mt-2';
            popup.append(title, address);

            searchMarker.bindPopup(popup).openPopup();
            map.setView(point, 17);
            setStatus('Lugar encontrado con TomTom. Este marcador es una referencia y no modifica unidades registradas.');
        } catch (error) {
            setStatus('No se pudo completar la búsqueda en TomTom. Recarga la página e inténtalo nuevamente.', true);
        } finally {
            searchButton?.removeAttribute('disabled');
        }
    }

    searchButton?.addEventListener('click', searchOverview);
    filterInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchOverview();
        }
    });

    const selected = markers.get(String(config.selectedSchoolId || ''));
    if (selected) {
        focusLocation(selected);
        if (config.startRoute) routePlanner?.start(selected.location);
    }
}

function initializeEditor() {
    const mapElement = document.getElementById('school-location-map');
    const latitudeInput = document.getElementById('latitude');
    const longitudeInput = document.getElementById('longitude');
    const addressInput = document.getElementById('address');
    const searchButton = document.querySelector('[data-map-search]');

    if (!mapElement || !latitudeInput || !longitudeInput) {
        return;
    }

    const latitude = Number.parseFloat(latitudeInput.value);
    const longitude = Number.parseFloat(longitudeInput.value);
    const hasSavedPoint = Number.isFinite(latitude) && Number.isFinite(longitude);
    const defaultLatitude = Number(config.defaultLatitude) || -17.3975;
    const defaultLongitude = Number(config.defaultLongitude) || -66.0387;
    const map = createMap(
        mapElement,
        hasSavedPoint ? [latitude, longitude] : [defaultLatitude, defaultLongitude],
        hasSavedPoint ? 17 : 13,
    );
    let marker = null;

    // Rellena las coordenadas; se guardan en la base de datos al enviar el formulario.
    function savePosition(point, shouldPan = true) {
        latitudeInput.value = Number(point.lat).toFixed(7);
        longitudeInput.value = Number(point.lng).toFixed(7);

        if (marker) {
            marker.setLatLng(point);
        } else {
            marker = L.marker(point, {
                draggable: true,
                title: mapElement.dataset.title || 'Unidad educativa',
            }).addTo(map);
            marker.on('dragend', () => {
                savePosition(marker.getLatLng(), false);
                setStatus('Marcador ajustado. Guarda el formulario para conservar el cambio.');
            });
        }

        if (shouldPan) {
            map.setView(point, 17);
        }
    }

    if (hasSavedPoint) {
        savePosition({ lat: latitude, lng: longitude }, false);
    }

    map.on('click', (event) => {
        savePosition(event.latlng);
        setStatus('Punto seleccionado. Guarda el formulario para conservarlo.');
    });

    [latitudeInput, longitudeInput].forEach((input) => {
        input.addEventListener('change', () => {
            const manualLatitude = Number.parseFloat(latitudeInput.value);
            const manualLongitude = Number.parseFloat(longitudeInput.value);

            if (Number.isFinite(manualLatitude) && Number.isFinite(manualLongitude)) {
                savePosition({ lat: manualLatitude, lng: manualLongitude });
            }
        });
    });

    // El botón Buscar dirección consulta a TomTom y coloca el punto en el formulario.
    async function searchAddress() {
        const address = addressInput?.value.trim();

        if (!address) {
            setStatus('Escribe primero una dirección para buscarla.', true);
            addressInput?.focus();
            return;
        }

        searchButton?.setAttribute('disabled', 'disabled');
        setStatus('Buscando la dirección con TomTom…');

        try {
            const result = await searchTomTom(address, map.getCenter());

            if (!result?.position) {
                setStatus('No se encontró la dirección. Selecciona el punto directamente en el mapa.', true);
                return;
            }

            savePosition({ lat: result.position.lat, lng: result.position.lon });
            setStatus('Dirección encontrada. Ajusta el marcador si es necesario.');
        } catch (error) {
            setStatus('No se pudo buscar la dirección. Verifica la clave o selecciona el punto en el mapa.', true);
        } finally {
            searchButton?.removeAttribute('disabled');
        }
    }

    searchButton?.addEventListener('click', searchAddress);
    addressInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchAddress();
        }
    });
}

// La vista elige entre consultar unidades o seleccionar su ubicación al editarlas.
if (config.mode === 'overview') {
    initializeOverview();
} else if (config.mode === 'editor') {
    initializeEditor();
}
