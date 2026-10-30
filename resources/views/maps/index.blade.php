@extends('adminlte::page')

@section('title', 'Mapa de unidades')

@section('adminlte_css')
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}?v={{ filemtime(public_path('css/maps.css')) }}">
@stop

@section('content_header')
<div class="d-flex justify-content-between align-items-center flex-wrap">
    <div>
        <span class="text-uppercase text-primary small font-weight-bold">Ubicación y rutas</span>
        <h1 class="mb-1">Mapa de unidades educativas</h1>
        <p class="text-muted mb-0">Explora las unidades en vista satelital y consulta la ruta para llegar.</p>
    </div>
    @can('staff')
        <a href="{{ route('schools.index') }}" class="btn btn-outline-primary mt-2 mt-md-0">
            <i class="fas fa-school mr-1"></i> Ver unidades educativas
        </a>
    @endcan
</div>
@stop

@section('content')
@php
    // Lee la clave configurada para consultar la API de TomTom desde el navegador.
    $tomtomApiKey = config('services.tomtom.api_key');
@endphp

@unless($tomtomApiKey)
    <div class="alert alert-warning">
        <i class="fas fa-key mr-2"></i>
        <strong>Falta configurar TomTom.</strong>
        Agrega <code>TOMTOM_API_KEY</code> en el archivo <code>.env</code> para visualizar el mapa.
    </div>
@endunless

@if($routeUnavailable)
    <div class="alert alert-warning" role="status">
        El destino solicitado no está disponible en tu mapa. Selecciona una de las unidades visibles.
    </div>
@endif

<div class="row">
    <div class="col-lg-8">
        <div class="card card-outline card-primary">
            <div class="card-body p-2">
                @if($tomtomApiKey)
                    <section id="map-route-panel" class="map-route-panel" hidden aria-label="Ruta de visita">
                        <h2 class="h6 mb-1"><i class="fas fa-route mr-1"></i>Cómo llegar</h2>
                        <strong class="d-block" data-route-destination></strong>
                        <p class="small text-muted mt-1 mb-2">Ruta en vehículo con TomTom. Usa tu ubicación o elige el punto de partida en el mapa.</p>
                        <p class="small mb-2" data-route-status role="status" aria-live="polite"></p>
                        <p class="small font-weight-bold mb-2" data-route-summary hidden></p>
                        <div class="map-route-actions">
                            <button type="button" class="btn btn-primary btn-sm" data-route-current>
                                <i class="fas fa-location-arrow mr-1"></i>Mi ubicación
                            </button>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-route-pick aria-pressed="false">
                                <i class="fas fa-map-pin mr-1"></i>Elegir en el mapa
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-route-clear>Cerrar ruta</button>
                        </div>
                    </section>
                    {{-- JavaScript dibuja aquí el mapa y los puntos de las unidades. --}}
                    <div
                        id="school-overview-map"
                        class="school-map school-map--overview"
                        aria-label="Mapa de unidades educativas con selector de vista satelital o calles"
                    ></div>
                    <p class="map-status small text-muted mt-2 mb-0" data-map-status aria-live="polite">
                        @if($locations->isNotEmpty())
                            Selecciona un marcador o busca una unidad, distrito o dirección.
                        @else
                            Aún no hay unidades ubicadas. Puedes buscar una dirección como referencia.
                        @endif
                    </p>
                    <small class="d-block text-muted mt-1">
                        Centros educativos importados desde
                        <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>.
                    </small>
                @else
                    <div class="map-placeholder">
                        <i class="fas fa-map fa-3x mb-3"></i>
                        <strong>El mapa aparecerá cuando configures la clave de la API.</strong>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header border-0">
                <h3 class="card-title"><i class="fas fa-list-ul mr-2"></i>Unidades visibles</h3>
                <div class="card-tools"><span class="badge badge-primary">{{ $locations->count() }}</span></div>
            </div>
            <div class="card-body pt-0">
                <div class="input-group mb-3">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                    <input type="search" class="form-control" placeholder="Unidad, distrito o dirección" data-map-filter aria-label="Buscar unidad, distrito o dirección">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-primary" data-map-search-overview>Buscar</button>
                    </div>
                </div>

                <div class="map-location-list" data-map-location-list>
                    @forelse($locations as $location)
                        <article
                            class="map-location-item"
                            data-location-id="{{ $location['id'] }}"
                            data-search-text="{{ Illuminate\Support\Str::lower($location['name'].' '.$location['district'].' '.$location['address']) }}"
                        >
                            <button type="button" class="map-location-focus" data-map-focus="{{ $location['id'] }}">
                                <span class="map-location-icon"><i class="fas fa-school"></i></span>
                                <span class="text-left">
                                    <strong class="d-block">{{ $location['name'] }}</strong>
                                    <small class="text-muted">{{ $location['address'] ?: 'Sin dirección escrita' }}</small>
                                </span>
                            </button>
                            <div class="map-location-actions">
                                <span class="badge badge-{{ $location['openTickets'] ? 'warning' : 'success' }}">
                                    {{ $location['openTickets'] }} solicitudes abiertas
                                </span>
                                @if($tomtomApiKey)
                                    <a href="{{ $location['plannerUrl'] }}" data-map-route="{{ $location['id'] }}" class="btn btn-xs btn-outline-primary">
                                        <i class="fas fa-route mr-1"></i>Cómo llegar
                                    </a>
                                @else
                                    <button type="button" class="btn btn-xs btn-outline-primary" disabled title="Falta configurar TomTom">
                                        <i class="fas fa-route mr-1"></i>Cómo llegar
                                    </button>
                                @endif
                            </div>
                        </article>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">
                            No hay unidades ubicadas para tu rol. El buscador todavía puede consultar lugares en TomTom.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@stop

@section('js')
@if($tomtomApiKey)
    {{-- Entrega a JavaScript la clave de TomTom y las ubicaciones guardadas. --}}
    <script>
        window.tomtomMapConfig = {{ Illuminate\Support\Js::from([
            'mode' => 'overview',
            'apiKey' => $tomtomApiKey,
            'locations' => $locations,
            'selectedSchoolId' => $selectedSchoolId,
            'startRoute' => $startRoute,
            'defaultLatitude' => config('services.tomtom.default_latitude'),
            'defaultLongitude' => config('services.tomtom.default_longitude'),
        ]) }};
    </script>
    {{-- Carga el código que consulta TomTom y controla el mapa. --}}
    @vite('resources/js/tomtom-maps.js')
@endif
@stop
