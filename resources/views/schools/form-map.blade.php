@extends('adminlte::page')

@section('title', $item->exists ? 'Editar unidad' : 'Nueva unidad')

@section('adminlte_css')
    <link rel="stylesheet" href="{{ asset('css/maps.css') }}">
@stop

@section('content_header')
<div>
    <span class="text-uppercase text-primary small font-weight-bold">Administración</span>
    <h1 class="mb-0">{{ $item->exists ? 'Editar unidad educativa' : 'Nueva unidad educativa' }}</h1>
</div>
@stop

@section('content')
@php
    // Usa la clave de TomTom para mostrar el mapa y buscar direcciones.
    $tomtomApiKey = config('services.tomtom.api_key');
    $latitude = old('latitude', $item->latitude);
    $longitude = old('longitude', $item->longitude);
@endphp

@include('partials.flash')

<form method="POST" action="{{ $item->exists ? route('schools.update', $item) : route('schools.store') }}">
    @csrf
    @if($item->exists)
        @method('PUT')
    @endif

    <div class="row">
        <div class="col-lg-5">
            <div class="card card-outline card-primary">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-school mr-2"></i>Datos de la unidad</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="name">Nombre *</label>
                        <input id="name" name="name" value="{{ old('name', $item->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                        @error('name')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="district">Distrito</label>
                        <input id="district" name="district" value="{{ old('district', $item->district) }}" class="form-control @error('district') is-invalid @enderror">
                        @error('district')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="address">Dirección</label>
                        <input id="address" name="address" value="{{ old('address', $item->address) }}" class="form-control @error('address') is-invalid @enderror" placeholder="Ej.: Av. principal, Sacaba">
                        @error('address')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label for="phone">Teléfono</label>
                        <input id="phone" name="phone" value="{{ old('phone', $item->phone) }}" class="form-control @error('phone') is-invalid @enderror">
                        @error('phone')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group mb-0">
                        <label for="active">Estado</label>
                        <select id="active" name="active" class="form-control">
                            <option value="1" @selected(old('active', $item->active ?? 1) == 1)>Activa</option>
                            <option value="0" @selected(old('active', $item->active ?? 1) == 0)>Inactiva</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Coordenadas</h3></div>
                <div class="card-body">
                    {{-- El mapa rellena estas coordenadas; Guardar unidad las envía al servidor. --}}
                    <div class="coordinates-panel">
                        <div class="row">
                            <div class="col-sm-6 form-group mb-sm-0">
                                <label for="latitude">Latitud</label>
                                <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ $latitude }}" class="form-control @error('latitude') is-invalid @enderror" placeholder="-17.3975000">
                                @error('latitude')<span class="invalid-feedback">{{ $message }}</span>@enderror
                            </div>
                            <div class="col-sm-6 form-group mb-0">
                                <label for="longitude">Longitud</label>
                                <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ $longitude }}" class="form-control @error('longitude') is-invalid @enderror" placeholder="-66.0387000">
                                @error('longitude')<span class="invalid-feedback">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </div>
                    <small class="text-muted">Puedes escribirlas o seleccionarlas directamente en el mapa.</small>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card card-outline card-info">
                <div class="card-header"><h3 class="card-title"><i class="fas fa-map-marker-alt mr-2"></i>Ubicación en el mapa</h3></div>
                <div class="card-body">
                    @if($tomtomApiKey)
                        {{-- Este botón consulta a TomTom usando la dirección escrita. --}}
                        <div class="map-toolbar mb-2">
                            <button type="button" class="btn btn-primary" data-map-search><i class="fas fa-search-location mr-1"></i>Buscar dirección</button>
                        </div>
                        <div
                            id="school-location-map"
                            class="school-map"
                            data-title="{{ old('name', $item->name ?: 'Unidad educativa') }}"
                            aria-label="Selector de ubicación de la unidad educativa"
                        ></div>
                        <p class="map-status small text-muted mt-2" data-map-status aria-live="polite">Busca la dirección, haz clic en el mapa o arrastra el marcador.</p>
                    @else
                        <div class="map-placeholder">
                            <i class="fas fa-key fa-3x mb-3"></i>
                            <strong>Falta configurar TomTom.</strong>
                            <span class="small mt-1">Agrega <code>TOMTOM_API_KEY</code> en <code>.env</code>. Mientras tanto puedes escribir las coordenadas manualmente.</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card"><div class="card-body d-flex justify-content-end">
        <a href="{{ route('schools.index') }}" class="btn btn-light mr-2">Cancelar</a>
        <button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Guardar unidad</button>
    </div></div>
</form>
@stop

@section('js')
@if($tomtomApiKey)
    {{-- Activa el mapa para elegir o ajustar la ubicación de la unidad. --}}
    <script>
        window.tomtomMapConfig = {{ Illuminate\Support\Js::from([
            'mode' => 'editor',
            'apiKey' => $tomtomApiKey,
            'defaultLatitude' => config('services.tomtom.default_latitude'),
            'defaultLongitude' => config('services.tomtom.default_longitude'),
        ]) }};
    </script>
    @vite('resources/js/tomtom-maps.js')
@endif
@stop
