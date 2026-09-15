<div class="card card-outline card-primary" id="visitas">
    <div class="card-header d-flex align-items-center flex-wrap">
        <h3 class="card-title"><i class="far fa-calendar-alt mr-2"></i>Visitas técnicas</h3>
        <a class="btn btn-sm btn-outline-primary ml-auto" href="{{ route('calendar.index') }}">Ver calendario</a>
    </div>
    <div class="card-body">
        @forelse($visits as $scheduledVisit)
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between flex-wrap">
                    <strong>Inicio: {{ $scheduledVisit->inicio->copy()->timezone('America/La_Paz')->format('d/m/Y H:i') }}</strong>
                    <span class="badge badge-{{ $scheduledVisit->estado === 'cancelada' ? 'secondary' : 'primary' }}">{{ ucfirst($scheduledVisit->estado) }}</span>
                </div>
                <p class="mb-1">Técnico: {{ $scheduledVisit->technician->name }}</p>
                @if($scheduledVisit->observaciones)<p class="mb-1">{{ $scheduledVisit->observaciones }}</p>@endif
                @unless(auth()->user()->isDirector())
                    <p class="small text-muted mb-2">{{ $scheduledVisit->sincronizado_en ? 'Sincronizada con Google Calendar' : 'Pendiente de sincronizar con Google' }}</p>
                    @if($scheduledVisit->error_sincronizacion)<p class="small text-warning">{{ $scheduledVisit->error_sincronizacion }}</p>@endif
                    @if(auth()->user()->isAdmin() || $scheduledVisit->tecnico_id === auth()->id())
                        {{-- Este botón pide al servidor que vuelva a enviar la visita a Google. --}}
                        <form method="POST" action="{{ route('visits.sync', $scheduledVisit) }}" class="d-inline-block">@csrf<button class="btn btn-sm btn-outline-primary">Sincronizar con Google</button></form>
                        @if($scheduledVisit->estado === 'programada')
                            <form method="POST" action="{{ route('visits.cancel', $scheduledVisit) }}" class="d-inline-block">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Cancelar visita</button></form>
                            @unless(in_array($ticket->status, ['resolved', 'closed', 'cancelled']))
                                <details class="mt-3" @if(old('formulario_visita') === 'visita-'.$scheduledVisit->id) open @endif>
                                    <summary class="text-primary">Reprogramar visita</summary>
                                    <form method="POST" action="{{ route('visits.update', $scheduledVisit) }}" class="mt-3">@csrf @method('PUT')
                                        @include('calendar.visit-fields', ['visit' => $scheduledVisit])
                                        <button class="btn btn-primary btn-sm">Guardar cambios</button>
                                    </form>
                                </details>
                            @endunless
                        @endif
                    @endif
                @endunless
            </div>
        @empty
            <p class="text-muted">Todavía no hay visitas programadas para esta solicitud.</p>
        @endforelse
        @if(!auth()->user()->isDirector() && !in_array($ticket->status, ['resolved', 'closed', 'cancelled']))
            <details @if(old('formulario_visita') === 'nueva-visita') open @endif>
                <summary class="text-primary font-weight-bold">Programar visita</summary>
                {{-- Al guardar, el servidor registra la visita y luego intenta crear su evento en Google. --}}
                <form method="POST" action="{{ route('visits.store', $ticket) }}" class="mt-3">@csrf
                    @include('calendar.visit-fields', ['visit' => null])
                    <button class="btn btn-primary"><i class="far fa-calendar-plus mr-1"></i>Guardar visita</button>
                </form>
            </details>
        @endif
    </div>
</div>
