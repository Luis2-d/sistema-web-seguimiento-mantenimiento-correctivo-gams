@php
    $visit = $visit ?? null;
    $zone = config('services.google_calendar.timezone');
    $prefix = $visit ? 'visita-'.$visit->id : 'nueva-visita';
    $keepOld = old('formulario_visita') === $prefix;
@endphp
<input type="hidden" name="formulario_visita" value="{{ $prefix }}">
<div class="row">
    <div class="col-md-6 form-group">
        <label for="{{ $prefix }}-tecnico">Técnico de la visita</label>
        <select id="{{ $prefix }}-tecnico" name="tecnico_id" class="form-control" required>
            <option value="">Selecciona un técnico</option>
            @foreach($technicians as $technician)
                @if((auth()->user()->isAdmin() || $technician->id === auth()->id()) && ($ticket->assigned_to === null || $technician->id === $ticket->assigned_to))
                    <option value="{{ $technician->id }}" @selected((int) ($keepOld ? old('tecnico_id') : ($visit?->tecnico_id ?? (auth()->user()->isTechnician() ? auth()->id() : $ticket->assigned_to))) === $technician->id)>{{ $technician->name }}</option>
                @endif
            @endforeach
        </select>
    </div>
    <div class="col-md-6 form-group">
        <label for="{{ $prefix }}-inicio">Inicio (hora de Bolivia)</label>
        <input id="{{ $prefix }}-inicio" type="datetime-local" name="inicio" class="form-control" value="{{ $keepOld ? old('inicio') : $visit?->inicio->copy()->timezone($zone)->format('Y-m-d\TH:i') }}" required>
    </div>
</div>
<div class="form-group">
    <label for="{{ $prefix }}-notas">Indicaciones para la visita</label>
    <textarea id="{{ $prefix }}-notas" name="observaciones" class="form-control" rows="2" maxlength="1000" placeholder="Ejemplo: llevar herramientas para revisar la fuente de alimentación.">{{ $keepOld ? old('observaciones') : $visit?->observaciones }}</textarea>
</div>
