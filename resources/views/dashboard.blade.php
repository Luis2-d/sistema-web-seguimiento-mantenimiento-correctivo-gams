@extends('adminlte::page')

@section('title', 'Centro de trabajo')

@section('adminlte_css')
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ filemtime(public_path('css/dashboard.css')) }}">
@stop

@section('content_header')
    <div class="dash-heading">
        <div>
            <div class="dash-eyebrow">Jefatura de Educación <span>/</span> Sacaba</div>
            <h1>Hola, {{ str(auth()->user()->name)->before(' ') }}.</h1>
            <p>Tu centro de trabajo: prioridades, visitas y seguimiento en un solo lugar.</p>
        </div>
        <div class="dash-heading-actions">
            <a href="{{ route('calendar.index') }}" class="dash-button dash-button-secondary"><i class="far fa-calendar-alt" aria-hidden="true"></i> Ver calendario</a>
            <a href="{{ route('tickets.create') }}" class="dash-button dash-button-primary"><i class="fas fa-plus" aria-hidden="true"></i> Nueva solicitud</a>
        </div>
    </div>
@stop

@section('content')
@php
    $user = auth()->user();
    $inProcess = $counts['diagnosis'] + $counts['in_progress'] + $counts['waiting_parts'];
    $statusLabels = ['pending' => 'Pendiente', 'diagnosis' => 'Diagnóstico', 'waiting_parts' => 'Espera de repuestos', 'in_progress' => 'En proceso', 'resolved' => 'Solucionado', 'closed' => 'Cerrado', 'cancelled' => 'Cancelado'];
    $metrics = [
        ['pending', 'clock', $counts['pending'], 'Pendientes de atención', 'Esperan una primera revisión'],
        ['progress', 'tools', $inProcess, 'En proceso', 'Diagnóstico, reparación y repuestos'],
        ['resolved', 'check-double', $completedTotal, 'Solucionadas y cerradas', $resolutionRate.'% de las solicitudes registradas'],
    ];
    $chartMax = max(4, $monthlyActivity->max('created'), $monthlyActivity->max('resolved'));
    $chartMax = (int) (ceil($chartMax / 4) * 4);
@endphp

<div class="dash">
    @include('partials.flash')
    <div class="dash-workbar">
        <span class="dash-workbar-role"><i class="fas fa-user-shield" aria-hidden="true"></i> {{ $user->roleLabel() }}</span>
        <span><strong>{{ $openTotal }}</strong> casos abiertos <span class="dash-workbar-divider">/</span> <strong>{{ $unassignedTotal }}</strong> sin asignar</span>
        <time datetime="{{ now($visitTimezone)->toDateString() }}"><i class="far fa-calendar-alt" aria-hidden="true"></i> {{ now($visitTimezone)->locale('es')->translatedFormat('d M, Y') }}</time>
    </div>

    <div class="dash-section-label"><h2>Resumen de atención</h2><span>{{ $total }} solicitudes registradas</span></div>
    <div class="dash-metrics">
        @foreach($metrics as [$tone, $icon, $value, $label, $detail])
            <article class="dash-metric dash-tone-{{ $tone }}">
                <div class="dash-metric-top"><span>{{ $label }}</span><span class="dash-icon"><i class="fas fa-{{ $icon }}" aria-hidden="true"></i></span></div>
                <strong class="dash-metric-value">{{ number_format($value, 0, ',', '.') }}</strong>
                <p>{{ $detail }}</p>
            </article>
        @endforeach
    </div>

    <div class="dash-section-label"><h2>Organiza tu jornada</h2><span>Primero lo que necesita atención</span></div>
    <div class="dash-main-grid dash-operational-grid">
        <section class="dash-panel dash-priority" aria-labelledby="dash-priority-title">
            <div class="dash-panel-heading">
                <div><span class="dash-eyebrow">Primero lo importante</span><h2 id="dash-priority-title">Solicitudes prioritarias</h2></div>
                <span class="dash-count">{{ $priorityTotal }}</span>
            </div>
            <p class="dash-panel-intro">Casos abiertos de prioridad alta o crítica.</p>
            <div class="dash-priority-list">
                @forelse($priorityTickets as $ticket)
                    <a href="{{ route('tickets.show', $ticket) }}" class="dash-priority-item">
                        <span class="dash-priority-icon {{ $ticket->priority === 'critical' ? 'is-critical' : '' }}"><i class="fas fa-exclamation" aria-hidden="true"></i></span>
                        <span class="dash-priority-copy"><small>{{ $ticket->priority === 'critical' ? 'Crítica' : 'Alta' }} <span>· {{ $ticket->code }}</span></small><strong>{{ $ticket->subject }}</strong><span>{{ $ticket->school?->name ?? 'Sin unidad asociada' }}</span></span>
                        <i class="fas fa-chevron-right dash-arrow" aria-hidden="true"></i>
                    </a>
                @empty
                    <div class="dash-empty dash-priority-empty"><span class="dash-empty-icon"><i class="fas fa-check" aria-hidden="true"></i></span><strong>Sin casos prioritarios</strong><p>No hay solicitudes abiertas de prioridad alta o crítica en tu ámbito de atención.</p></div>
                @endforelse
            </div>
            @if($priorityTotal > $priorityTickets->count())
                <div class="dash-panel-foot">Mostrando {{ $priorityTickets->count() }} de {{ $priorityTotal }} casos prioritarios.</div>
            @endif
        </section>

        <section class="dash-panel dash-agenda" aria-labelledby="dash-visits-title">
            <div class="dash-panel-heading">
                <div><span class="dash-eyebrow">Agenda de soporte</span><h2 id="dash-visits-title">Próximas visitas</h2></div>
                <a class="dash-text-link" href="{{ route('calendar.index') }}">Ver agenda <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <p class="dash-panel-intro">Tus próximas citas · Hora de Bolivia</p>
            <div class="dash-visit-list">
                @forelse($upcomingVisits as $visit)
                    @php($visitStart = $visit->inicio->copy()->setTimezone($visitTimezone))
                    <a class="dash-visit" href="{{ route('tickets.show', $visit->ticket) }}#visitas">
                        <time class="dash-visit-day" datetime="{{ $visitStart->toIso8601String() }}"><strong>{{ $visitStart->format('d') }}</strong><span>{{ $visitStart->locale('es')->translatedFormat('M') }}</span></time>
                        <span class="dash-visit-copy">
                            <span class="dash-visit-hour">{{ $visitStart->format('H:i') }}@if($visit->inicio->isPast()) <span class="dash-live">En curso</span>@elseif($visitStart->isToday()) <span class="dash-today">Hoy</span>@endif</span>
                            <strong>{{ $visit->ticket->school?->name ?? 'Sin unidad asociada' }}</strong>
                            <span>{{ $visit->ticket->code }} · {{ $visit->technician?->name ?? 'Sin técnico' }}</span>
                        </span>
                        <i class="fas fa-chevron-right dash-arrow" aria-hidden="true"></i>
                    </a>
                @empty
                    <div class="dash-empty dash-agenda-empty"><span class="dash-empty-icon"><i class="far fa-calendar-check" aria-hidden="true"></i></span><strong>Tu agenda está libre</strong><p>No tienes visitas próximas programadas. Consulta el calendario para organizar la atención.</p><a class="dash-text-link" href="{{ route('calendar.index') }}">Abrir calendario <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
                @endforelse
            </div>
            @if($upcomingVisits->isNotEmpty())
                <div class="dash-panel-foot">Hasta 4 visitas programadas, ordenadas por fecha.</div>
            @endif
        </section>
    </div>

    <div class="dash-section-label"><h2>Seguimiento y resultados</h2><a class="dash-text-link" href="{{ route('reports.index') }}">Consultar reportes <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
    <div class="dash-recent-grid">
        <section class="dash-panel dash-recent" aria-labelledby="dash-recent-title">
            <div class="dash-panel-heading"><div><span class="dash-eyebrow">Seguimiento operativo</span><h2 id="dash-recent-title">Últimas solicitudes</h2></div><a class="dash-text-link" href="{{ route('tickets.index') }}">Ver todas <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
            <div class="dash-table-scroll" tabindex="0" role="region" aria-label="Tabla de últimas solicitudes">
                <table class="dash-table">
                    <thead><tr><th scope="col">Solicitud / unidad educativa</th><th scope="col">Responsable</th><th scope="col">Estado</th><th scope="col">Fecha</th></tr></thead>
                    <tbody>
                        @forelse($recent as $ticket)
                            <tr>
                                <td><a class="dash-ticket-code" href="{{ route('tickets.show', $ticket) }}">{{ $ticket->code }} <i class="fas fa-arrow-up" aria-hidden="true"></i></a><span class="dash-ticket-subject" title="{{ $ticket->subject }}">{{ $ticket->subject }}</span><small class="dash-school-name">{{ $ticket->school?->name ?? 'Sin unidad asociada' }}</small></td>
                                <td><span class="dash-assignee"><span class="dash-avatar" aria-hidden="true">{{ str($ticket->technician?->name ?? '—')->substr(0, 1)->upper() }}</span><span>{{ $ticket->technician?->name ?? 'Sin asignar' }}</span></span></td>
                                <td><span class="dash-status dash-status-{{ $ticket->status }}">{{ $statusLabels[$ticket->status] ?? 'Sin estado' }}</span></td>
                                <td class="dash-ticket-date"><time datetime="{{ $ticket->created_at->toIso8601String() }}">{{ $ticket->created_at->format('d/m/Y') }}</time></td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="dash-empty"><span class="dash-empty-icon"><i class="far fa-folder-open" aria-hidden="true"></i></span><strong>El seguimiento comienza aquí</strong><p>Aún no existen solicitudes registradas.</p><a href="{{ route('tickets.create') }}" class="dash-text-link">Registrar una solicitud <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="dash-panel-foot"><span>{{ $recent->count() }} solicitudes recientes de {{ $total }} registradas</span><a href="{{ route('reports.index') }}">Consultar reportes <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
        </section>

    </div>
    <div class="dash-main-grid dash-results-grid">
        <section class="dash-panel" aria-labelledby="dash-activity-title">
            <div class="dash-panel-heading">
                <div><span class="dash-eyebrow">Ritmo de trabajo</span><h2 id="dash-activity-title">Evolución de solicitudes</h2></div>
                <span class="dash-period">Últimos 6 meses</span>
            </div>
            <div class="dash-chart-summary">
                <div><strong>{{ $monthlyActivity->sum('created') }}</strong><span>registradas en el período</span></div>
                <div class="dash-legend"><span><i class="dash-swatch dash-swatch-created"></i> Registradas</span><span><i class="dash-swatch dash-swatch-resolved"></i> Solucionadas*</span></div>
            </div>
            <figure class="dash-chart">
                <svg viewBox="0 0 640 225" role="img" aria-labelledby="dash-chart-title dash-chart-description" class="dash-chart-svg">
                    <title id="dash-chart-title">Solicitudes por mes</title>
                    <desc id="dash-chart-description">@foreach($monthlyActivity as $month){{ $month['fullLabel'] }}: {{ $month['created'] }} registradas y {{ $month['resolved'] }} solucionadas. @endforeach</desc>
                    @foreach(range(0, 4) as $step)
                        <line x1="38" y1="{{ 180 - $step * 40 }}" x2="625" y2="{{ 180 - $step * 40 }}" class="dash-chart-grid" />
                        <text x="28" y="{{ 184 - $step * 40 }}" text-anchor="end" class="dash-chart-label">{{ $chartMax * $step / 4 }}</text>
                    @endforeach
                    @foreach($monthlyActivity as $month)
                        @php($x = 69 + $loop->index * 96)
                        <rect x="{{ $x }}" y="{{ 180 - $month['created'] / $chartMax * 160 }}" width="23" height="{{ $month['created'] / $chartMax * 160 }}" rx="4" class="dash-chart-created"><title>{{ $month['fullLabel'] }}: {{ $month['created'] }} registradas</title></rect>
                        <rect x="{{ $x + 29 }}" y="{{ 180 - $month['resolved'] / $chartMax * 160 }}" width="23" height="{{ $month['resolved'] / $chartMax * 160 }}" rx="4" class="dash-chart-resolved"><title>{{ $month['fullLabel'] }}: {{ $month['resolved'] }} solucionadas</title></rect>
                        <text x="{{ $x + 26 }}" y="208" text-anchor="middle" class="dash-chart-label">{{ $month['label'] }}</text>
                    @endforeach
                </svg>
                <figcaption>*Casos actualmente solucionados o cerrados, según su fecha de solución.</figcaption>
            </figure>
        </section>

        <section class="dash-panel" aria-labelledby="dash-workflow-title">
            <div class="dash-panel-heading"><div><span class="dash-eyebrow">Estado de la atención</span><h2 id="dash-workflow-title">Avance del servicio</h2></div><i class="fas fa-chart-pie dash-muted" aria-hidden="true"></i></div>
            <div class="dash-resolution"><strong>{{ $total ? $resolutionRate.'%' : '—' }}</strong><div><b>Solicitudes finalizadas</b><span>{{ $completedTotal }} de {{ $total }} registradas</span></div></div>
            <div class="dash-workflow">
                @foreach(['pending' => 'Pendientes', 'diagnosis' => 'En diagnóstico', 'in_progress' => 'En reparación', 'waiting_parts' => 'Esperando repuestos', 'resolved' => 'Solucionadas', 'closed' => 'Cerradas', 'cancelled' => 'Canceladas'] as $state => $label)
                    <div class="dash-workflow-row dash-status-{{ $state }}"><div><label for="dash-progress-{{ $state }}">{{ $label }}</label><span>{{ $counts[$state] }}</span></div><progress id="dash-progress-{{ $state }}" value="{{ $counts[$state] }}" max="{{ max(1, $total) }}">{{ $counts[$state] }} de {{ $total }}</progress></div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="dash-section-label"><h2>Tu espacio de trabajo</h2><span>Herramientas y recursos</span></div>
    <nav class="dash-shortcuts" aria-label="Accesos rápidos">
        <a href="{{ route('map.index') }}" class="dash-shortcut"><span class="dash-shortcut-icon"><i class="fas fa-map-marked-alt" aria-hidden="true"></i></span><span><strong>Mapa educativo</strong><small>{{ $locatedSchoolTotal }} de {{ $schoolTotal }} unidades activas ubicadas</small></span><i class="fas fa-arrow-right dash-arrow" aria-hidden="true"></i></a>
        <a href="{{ route('equipment.index') }}" class="dash-shortcut"><span class="dash-shortcut-icon"><i class="fas fa-laptop" aria-hidden="true"></i></span><span><strong>Inventario de equipos</strong><small>{{ $equipment }} equipos registrados</small></span><i class="fas fa-arrow-right dash-arrow" aria-hidden="true"></i></a>
        @can('staff')
            <a href="{{ route('spare-parts.index') }}" class="dash-shortcut"><span class="dash-shortcut-icon dash-shortcut-amber"><i class="fas fa-box-open" aria-hidden="true"></i></span><span><strong>Repuestos</strong><small>{{ $lowStock }} con stock bajo · 2 unidades o menos</small></span><i class="fas fa-arrow-right dash-arrow" aria-hidden="true"></i></a>
        @else
            <a href="{{ route('notifications.index') }}" class="dash-shortcut"><span class="dash-shortcut-icon"><i class="far fa-bell" aria-hidden="true"></i></span><span><strong>Notificaciones</strong><small>Novedades de tus solicitudes</small></span><i class="fas fa-arrow-right dash-arrow" aria-hidden="true"></i></a>
        @endcan
        @can('admin')
            <a href="{{ route('users.index') }}" class="dash-shortcut"><span class="dash-shortcut-icon"><i class="fas fa-user-cog" aria-hidden="true"></i></span><span><strong>Equipo de soporte</strong><small>{{ $technicians }} técnicos activos</small></span><i class="fas fa-arrow-right dash-arrow" aria-hidden="true"></i></a>
        @else
            <a href="{{ route('reports.index') }}" class="dash-shortcut"><span class="dash-shortcut-icon"><i class="far fa-file-alt" aria-hidden="true"></i></span><span><strong>Reportes</strong><small>Consulta los resultados de atención</small></span><i class="fas fa-arrow-right dash-arrow" aria-hidden="true"></i></a>
        @endcan
    </nav>
    <div class="dash-bottom-note"><span>Soporte Educativo G.A.M.S.</span><span>Datos al cargar la página · {{ now($visitTimezone)->format('H:i') }}</span></div>
</div>
@stop
