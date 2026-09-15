@extends('adminlte::page')
@section('title', 'Calendario de visitas')
@section('content_header')
<div class="d-flex justify-content-between align-items-center flex-wrap">
    <div><span class="section-kicker">Planificación de atención</span><h1>Calendario de visitas</h1><p class="text-muted mb-0">Organiza las visitas a las unidades educativas. Horario de Bolivia.</p></div>
    <a href="{{ route('tickets.index') }}" class="btn btn-primary mt-2">Ir a solicitudes</a>
</div>
@stop
@section('content')
@include('partials.flash')
{{-- Solo el administrador ve los botones para conectar o desconectar Google Calendar. --}}
@if(auth()->user()->isAdmin())
    <div class="card card-outline card-primary">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div><strong><i class="far fa-calendar-check mr-2"></i>Google Calendar institucional</strong><p class="text-muted mb-2">{{ $connection?->refresh_token ? 'Cuenta conectada. Las visitas se sincronizan al guardarlas.' : 'Conecta la cuenta institucional para consultar las visitas también en Google.' }}</p></div>
                <div>
                    <form method="POST" action="{{ route('calendar.connect') }}" class="d-inline-block">@csrf<button class="btn btn-outline-primary" @disabled(!$configured)>{{ $connection?->refresh_token ? 'Volver a conectar' : 'Conectar con Google Calendar' }}</button></form>
                    @if($connection?->refresh_token)
                        <form method="POST" action="{{ route('calendar.disconnect') }}" class="d-inline-block">@csrf @method('DELETE')<button class="btn btn-outline-secondary">Desconectar</button></form>
                    @endif
                </div>
            </div>
            @unless($configured)
                <div class="alert alert-light border mb-0">Falta configurar Google Calendar. Puedes programar visitas mientras completas la conexión.</div>
            @endunless

        </div>
    </div>
@endif
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h2 class="card-title font-weight-bold">{{ $month->locale('es')->translatedFormat('F Y') }}</h2>
        <div class="ml-auto">
            <a class="btn btn-sm btn-light" aria-label="Mes anterior" href="{{ route('calendar.index', ['mes' => $month->subMonth()->format('Y-m')]) }}">← Anterior</a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('calendar.index') }}">Hoy</a>
            <a class="btn btn-sm btn-light" aria-label="Mes siguiente" href="{{ route('calendar.index', ['mes' => $month->addMonth()->format('Y-m')]) }}">Siguiente →</a>
        </div>
    </div>
    <div class="card-body p-0 calendar-scroll">
        {{-- Este calendario muestra visitas guardadas en el sistema, aunque Google no esté conectado. --}}
        <div class="visits-calendar">
            @foreach(['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'] as $dayName)<div class="calendar-heading">{{ $dayName }}</div>@endforeach
            @for($day = $start; $day->lte($end); $day = $day->addDay())
                <div class="calendar-day {{ $day->month !== $month->month ? 'outside-month' : '' }} {{ $day->isToday() ? 'calendar-today' : '' }}">
                    <span class="calendar-number">{{ $day->day }}</span>
                    @foreach($visits->filter(fn($v) => $v->estado !== 'cancelada' && $v->inicio->copy()->timezone($timezone)->startOfDay()->lte($day) && $v->fin->copy()->timezone($timezone)->subSecond()->startOfDay()->gte($day)) as $entry)
                        <a class="calendar-event" href="{{ route('tickets.show', $entry->ticket) }}#visitas">
                            <strong>{{ $entry->inicio->copy()->timezone($timezone)->format('H:i') }} · {{ $entry->ticket->code }}</strong>
                            <span>{{ $entry->ticket->school->name }}</span><small>{{ $entry->technician->name }}</small>
                        </a>
                    @endforeach
                </div>
            @endfor
        </div>
    </div>
</div>
<div class="card"><div class="card-header"><h3 class="card-title">Visitas del período mostrado</h3></div><div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0"><thead><tr><th>Fecha y hora</th><th>Solicitud / unidad educativa</th><th>Técnico</th><th>Estado</th>@unless(auth()->user()->isDirector())<th>Google Calendar</th>@endunless</tr></thead><tbody>
        @forelse($visits as $entry)
            <tr><td>{{ $entry->inicio->copy()->timezone($timezone)->format('d/m/Y H:i') }}<br><small>Hasta {{ $entry->fin->copy()->timezone($timezone)->format('d/m/Y H:i') }}</small></td><td><a href="{{ route('tickets.show', $entry->ticket) }}#visitas">{{ $entry->ticket->code }}</a><br>{{ $entry->ticket->school->name }}</td><td>{{ $entry->technician->name }}</td><td>{{ ucfirst($entry->estado) }}</td>
                @unless(auth()->user()->isDirector())<td><span class="badge badge-{{ $entry->sincronizado_en ? 'success' : 'warning' }}">{{ $entry->sincronizado_en ? 'Sincronizada' : 'Pendiente' }}</span>
                    @if(!$entry->sincronizado_en)<form class="mt-1" method="POST" action="{{ route('visits.sync', $entry) }}">@csrf<button class="btn btn-sm btn-outline-primary">Sincronizar</button></form>@endif
                </td>@endunless
            </tr>
        @empty<tr><td colspan="{{ auth()->user()->isDirector() ? 4 : 5 }}" class="text-center text-muted py-4">No hay visitas en este período. Se programan desde el detalle de una solicitud.</td></tr>@endforelse
    </tbody></table>
</div></div>
@stop
@section('css')
<style>
.calendar-scroll{overflow-x:auto}.visits-calendar{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));min-width:840px}.calendar-heading{padding:12px;background:#edf2fb;color:#274b86;text-align:center;font-weight:600}.calendar-day{min-height:125px;padding:8px;border-right:1px solid #e5eaf2;border-bottom:1px solid #e5eaf2}.calendar-number{display:inline-block;font-weight:600;margin-bottom:6px}.outside-month{background:#f8f9fc;color:#7b879b}.calendar-today{box-shadow:inset 0 0 0 2px #4361b3}.calendar-event{display:block;background:#edf1ff;border-left:3px solid #5368b9;border-radius:4px;padding:6px;margin-bottom:5px;font-size:12px;color:#253f75;overflow-wrap:anywhere}.calendar-event span,.calendar-event small{display:block}.calendar-event:hover{background:#dfe7ff}
</style>
@stop
