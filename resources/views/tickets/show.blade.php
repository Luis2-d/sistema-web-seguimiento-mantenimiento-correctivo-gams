@extends('adminlte::page')

@section('title', $ticket->code)

@section('content_header')
<div class="d-flex justify-content-between align-items-center flex-wrap">
    <div>
        <span class="section-kicker">Detalle de solicitud</span>
        <h1 class="mb-1">{{ $ticket->code }}</h1>
        <small class="text-muted">Registrada el {{ $ticket->created_at->format('d/m/Y H:i') }}</small>
    </div>
    <div>
        <a href="{{ route('tickets.act', $ticket) }}" target="_blank" class="btn btn-outline-primary"><i class="fas fa-print mr-1"></i>Acta técnica</a>
        <a href="{{ route('tickets.index') }}" class="btn btn-light"><i class="fas fa-arrow-left mr-1"></i>Volver</a>
    </div>
</div>
@stop

@section('content')
@include('partials.flash')
@include('calendar.ticket-visits')

@php
    $statusLabels = ['pending'=>'Pendiente','diagnosis'=>'En diagnóstico','waiting_parts'=>'Espera de repuestos','in_progress'=>'En progreso','resolved'=>'Solucionado','closed'=>'Cerrado','cancelled'=>'Cancelado'];
@endphp

<div class="alert alert-light border d-flex align-items-center">
    <i class="fas fa-user-shield fa-lg text-primary mr-3"></i>
    <div><strong>Sesión activa: {{ auth()->user()->roleLabel() }}</strong><br><small class="text-muted">{{ auth()->user()->roleDescription() }}</small></div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card card-outline card-primary">
            <div class="card-header"><h3 class="card-title">{{ $ticket->subject }}</h3><div class="card-tools"><span class="badge badge-{{ in_array($ticket->status,['resolved','closed'])?'success':($ticket->status==='pending'?'warning':'info') }}">{{ $statusLabels[$ticket->status] ?? $ticket->status }}</span></div></div>
            <div class="card-body">
                <p>{{ $ticket->description }}</p><hr>
                <div class="row text-sm">
                    <div class="col-md-4"><strong>Unidad educativa</strong><br>{{ $ticket->school->name }}</div>
                    <div class="col-md-4"><strong>Categoría</strong><br>{{ $ticket->category === 'connectivity' ? 'Conectividad' : ucfirst($ticket->category) }}</div>
                    <div class="col-md-4"><strong>Prioridad</strong><br>{{ ['low'=>'Baja','medium'=>'Media','high'=>'Alta','critical'=>'Crítica'][$ticket->priority] ?? $ticket->priority }}</div>
                </div>
                @if($ticket->equipment)
                    <hr><strong>Equipo:</strong> {{ $ticket->equipment->asset_code }} · {{ $ticket->equipment->brand }} {{ $ticket->equipment->model }}
                @elseif($ticket->equipment_brand)
                    <hr><strong>Marca del equipo:</strong> {{ $ticket->equipment_brand }}
                @endif
            </div>
        </div>

        <div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-history mr-2"></i>Historial del caso</h3></div><div class="card-body"><div class="timeline">
            @foreach($ticket->histories->sortByDesc('created_at') as $history)
                <div><i class="fas fa-exchange-alt bg-blue"></i><div class="timeline-item"><span class="time"><i class="far fa-clock"></i> {{ $history->created_at->format('d/m/Y H:i') }}</span><h3 class="timeline-header"><strong>{{ $history->user->name }}</strong> cambió a {{ $statusLabels[$history->to_status] ?? $history->to_status }}</h3>@if($history->comment)<div class="timeline-body">{{ $history->comment }}</div>@endif</div></div>
            @endforeach
            <div><i class="far fa-clock bg-gray"></i></div>
        </div></div></div>

        <div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-check mr-2"></i>Intervenciones técnicas</h3></div><div class="card-body">
            @forelse($ticket->interventions as $intervention)
                <div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between"><strong>{{ ucfirst($intervention->service_type) }} · {{ $intervention->technician->name }}</strong><span class="text-muted">{{ $intervention->performed_at->format('d/m/Y H:i') }}</span></div><p class="mt-2 mb-1"><b>Diagnóstico:</b> {{ $intervention->diagnosis }}</p><p class="mb-1"><b>Solución:</b> {{ $intervention->solution }}</p>@if($intervention->spareParts->isNotEmpty())<p class="mb-0"><b>Repuestos:</b> @foreach($intervention->spareParts as $part){{ $part->name }} ({{ $part->pivot->cantidad }})@unless($loop->last), @endunless @endforeach</p>@endif</div>
            @empty <p class="text-muted">Todavía no se registraron intervenciones.</p> @endforelse
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card card-outline card-primary">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-map-marked-alt mr-2"></i>Ubicación de atención</h3></div>
            <div class="card-body">
                <strong>{{ $ticket->school->name }}</strong>
                <p class="text-muted small mt-1 mb-3">{{ $ticket->school->address ?: 'Dirección no registrada' }}</p>
                @if($ticket->school->latitude !== null && $ticket->school->longitude !== null)
                    <a href="{{ route('map.index', ['school' => $ticket->school->id]) }}" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-map mr-1"></i>Ver en el mapa
                    </a>
                    <a href="{{ route('map.index', ['school' => $ticket->school->id, 'route' => 1]) }}" class="btn btn-primary btn-sm">
                        <i class="fas fa-route mr-1"></i>Cómo llegar
                    </a>
                @else
                    <p class="text-muted small mb-0">Esta unidad todavía no tiene coordenadas registradas.</p>
                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('schools.edit', $ticket->school) }}" class="btn btn-outline-secondary btn-sm mt-2">Registrar ubicación</a>
                    @endif
                @endif
            </div>
        </div>

        @unless(auth()->user()->isDirector())
            <div class="card card-outline card-info"><div class="card-header"><h3 class="card-title">Control de la solicitud</h3></div><div class="card-body">
                <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="mb-4">@csrf @method('PATCH')<label>Técnico responsable</label><div class="input-group"><select name="assigned_to" class="form-control">@foreach($technicians as $user)<option value="{{ $user->id }}" @selected($ticket->assigned_to === $user->id)>{{ $user->name }}</option>@endforeach</select><div class="input-group-append"><button class="btn btn-outline-primary">Asignar</button></div></div></form>
                <form method="POST" action="{{ route('tickets.status', $ticket) }}">@csrf @method('PATCH')<label>Estado de la solicitud</label><select name="status" class="form-control mb-2">@foreach($statusLabels as $value=>$label)<option value="{{ $value }}" @selected($ticket->status === $value)>{{ $label }}</option>@endforeach</select><textarea name="comment" class="form-control mb-2" rows="2" placeholder="Observación del cambio"></textarea><button class="btn btn-info btn-block">Actualizar estado</button></form>
            </div></div>

            <div class="card card-outline card-success"><div class="card-header"><h3 class="card-title">Registrar solución técnica</h3></div><div class="card-body">
                <form method="POST" action="{{ route('interventions.store', $ticket) }}">@csrf
                    <div class="form-group"><label>Tipo de servicio</label><select name="service_type" class="form-control"><option value="hardware">Hardware</option><option value="software">Software</option><option value="connectivity">Conectividad</option></select></div>
                    <div class="form-group"><label>Diagnóstico *</label><textarea name="diagnosis" class="form-control" rows="3" required></textarea></div>
                    <div class="form-group"><label>Solución aplicada *</label><textarea name="solution" class="form-control" rows="3" required></textarea></div>
                    <div class="form-group"><label>Fecha y hora</label><input type="datetime-local" name="performed_at" value="{{ now()->format('Y-m-d\TH:i') }}" class="form-control" required></div>
                    <div class="form-group"><label>Repuesto utilizado</label><select name="spare_part_id" class="form-control"><option value="">Ninguno</option>@foreach($parts as $part)<option value="{{ $part->id }}">{{ $part->name }} (stock: {{ $part->stock }})</option>@endforeach</select></div>
                    <div class="form-group"><label>Cantidad</label><input type="number" name="quantity" min="1" value="1" class="form-control"></div>
                    <input type="hidden" name="mark_resolved" value="0">
                    <div class="custom-control custom-switch mb-3"><input type="checkbox" class="custom-control-input" id="mark_resolved" name="mark_resolved" value="1" checked><label class="custom-control-label" for="mark_resolved">Marcar como solucionada</label><small class="d-block text-muted mt-1">Notificará al director y actualizará el panel administrativo.</small></div>
                    <button class="btn btn-success btn-block"><i class="fas fa-check-circle mr-1"></i>Guardar solución técnica</button>
                </form>
            </div></div>
        @else
            <div class="card card-outline card-primary"><div class="card-body"><strong>Seguimiento del director</strong><p class="text-muted small mb-0 mt-2">Aquí puede verificar el estado, técnico asignado, historial y solución aplicada. Recibirá una notificación cuando el caso sea solucionado.</p></div></div>
        @endunless
    </div>
</div>
@stop
