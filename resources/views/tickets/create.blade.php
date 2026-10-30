@extends('adminlte::page')
@section('title', 'Nueva solicitud')
@section('content_header')<h1>Nueva solicitud de soporte</h1>@stop

@section('content')
@include('partials.flash')
@php
    $defaultSchoolId = $schools->contains('id', auth()->user()->school_id) ? auth()->user()->school_id : '';
    $selectedSchoolId = old('school_id', $defaultSchoolId);
@endphp
<form method="POST" action="{{ route('tickets.store') }}">
    @csrf
    <div class="row">
        <div class="col-lg-8">
            <div class="card card-primary card-outline">
                <div class="card-header"><h3 class="card-title">Descripción del problema</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="subject">Asunto *</label>
                        <input id="subject" name="subject" value="{{ old('subject') }}" class="form-control" required maxlength="255" placeholder="Ej.: La computadora no enciende">
                    </div>
                    <div class="form-group">
                        <label for="description">Descripción detallada *</label>
                        <textarea id="description" name="description" rows="7" class="form-control" required maxlength="5000" placeholder="Explique qué ocurre, desde cuándo y cualquier mensaje de error">{{ old('description') }}</textarea>
                    </div>
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label for="category">Categoría *</label>
                            <select id="category" name="category" class="form-control" required>
                                <option value="hardware" @selected(old('category', 'hardware') === 'hardware')>Hardware</option>
                                <option value="software" @selected(old('category') === 'software')>Software</option>
                                <option value="connectivity" @selected(old('category') === 'connectivity')>Conectividad</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="priority">Prioridad *</label>
                            <select id="priority" name="priority" class="form-control" required>
                                <option value="low" @selected(old('priority', 'medium') === 'low')>Baja</option>
                                <option value="medium" @selected(old('priority', 'medium') === 'medium')>Media</option>
                                <option value="high" @selected(old('priority') === 'high')>Alta</option>
                                <option value="critical" @selected(old('priority') === 'critical')>Crítica</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Ubicación y equipo</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="school_id">Unidad educativa *</label>
                        <select id="school_id" name="school_id" class="form-control" required>
                            <option value="">Seleccione una unidad educativa</option>
                            @foreach($schools as $school)
                                <option value="{{ $school->id }}" @selected((string) $selectedSchoolId === (string) $school->id)>{{ $school->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="equipment_id">Equipo (opcional)</label>
                        <select id="equipment_id" name="equipment_id" class="form-control" aria-describedby="equipment-help">
                            <option value="">Sin equipo específico</option>
                            @foreach($equipment as $item)
                                <option value="{{ $item->id }}" data-school-id="{{ $item->school_id }}" @selected((string) old('equipment_id') === (string) $item->id)>{{ $item->asset_code }} · {{ $item->brand }} {{ $item->model }}</option>
                            @endforeach
                        </select>
                        <small id="equipment-help" class="form-text text-muted" aria-live="polite">Seleccione un equipo registrado en la unidad educativa.</small>
                        @if(auth()->user()->isAdmin() || auth()->user()->isTechnician())
                            <a href="{{ route('equipment.create') }}" class="d-inline-block small mt-1">Registrar equipo</a>
                        @endif
                    </div>
                    <button class="btn btn-primary btn-block"><i class="fas fa-paper-plane mr-1"></i>Enviar solicitud</button>
                    <a href="{{ route('tickets.index') }}" class="btn btn-light btn-block">Cancelar</a>
                </div>
            </div>
        </div>
    </div>
</form>
@stop

@section('js')
    <script src="{{ asset('js/ticket-form.js') }}"></script>
@stop
