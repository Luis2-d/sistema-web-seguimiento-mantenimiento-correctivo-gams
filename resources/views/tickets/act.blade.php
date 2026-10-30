<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acta {{ $ticket->code }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 35px; color: #172033; font: 13px Arial, sans-serif; }
        .header { text-align: center; border-bottom: 3px solid #123b68; padding-bottom: 14px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header p { margin: 5px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 25px; margin: 22px 0; }
        .box { margin: 12px 0; padding: 12px; border: 1px solid #aab5c2; border-radius: 6px; }
        .box h2 { margin: 0 0 8px; color: #123b68; font-size: 14px; }
        .intervention { margin: 15px 0; padding-left: 12px; border-left: 4px solid #218c74; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 70px; margin-top: 75px; text-align: center; }
        .line { padding-top: 6px; border-top: 1px solid #333; }
        .no-print { position: fixed; top: 20px; right: 25px; padding: 9px 16px; cursor: pointer; }
        @media print { .no-print { display: none; } body { margin: 12mm; } }
    </style>
</head>
<body>
    <button class="no-print" onclick="window.print()">Imprimir acta</button>

    <header class="header">
        <h1>JEFATURA DE EDUCACIÓN DEL G.A.M.S.</h1>
        <p>ACTA DIGITAL DE MANTENIMIENTO CORRECTIVO</p>
        <strong>{{ $ticket->code }}</strong>
    </header>

    <section class="meta">
        <div><b>Unidad educativa:</b> {{ $ticket->school->name }}</div>
        <div><b>Fecha de solicitud:</b> {{ $ticket->created_at->format('d/m/Y H:i') }}</div>
        <div><b>Solicitante:</b> {{ $ticket->requester->name }}</div>
        <div><b>Técnico:</b> {{ $ticket->technician?->name ?? 'Sin asignar' }}</div>
        <div><b>Categoría:</b> {{ ucfirst($ticket->category) }}</div>
        <div><b>Estado:</b> {{ strtoupper(str_replace('_', ' ', $ticket->status)) }}</div>
    </section>

    @if($ticket->equipment)
        <section class="box">
            <h2>IDENTIFICACIÓN DEL EQUIPO</h2>
            <b>Código:</b> {{ $ticket->equipment->asset_code }} &nbsp;
            <b>Tipo:</b> {{ ucfirst($ticket->equipment->type) }} &nbsp;
            <b>Marca/modelo:</b> {{ $ticket->equipment->brand }} {{ $ticket->equipment->model }}<br>
            <b>Serie:</b> {{ $ticket->equipment->serial_number }} &nbsp;
            <b>Ubicación:</b> {{ $ticket->equipment->location }}
        </section>
    @elseif($ticket->equipment_brand)
        <section class="box">
            <h2>IDENTIFICACIÓN DEL EQUIPO</h2>
            <b>Marca:</b> {{ $ticket->equipment_brand }}
        </section>
    @endif

    <section class="box">
        <h2>FALLA REPORTADA</h2>
        <b>{{ $ticket->subject }}</b>
        <p>{{ $ticket->description }}</p>
    </section>

    <section class="box">
        <h2>TRABAJO TÉCNICO REALIZADO</h2>
        @forelse($ticket->interventions->sortBy('performed_at') as $intervention)
            <article class="intervention">
                <b>{{ $intervention->performed_at->format('d/m/Y H:i') }} · {{ ucfirst($intervention->service_type) }}</b>
                <p><b>Diagnóstico:</b> {{ $intervention->diagnosis }}</p>
                <p><b>Solución:</b> {{ $intervention->solution }}</p>

                @if($intervention->spareParts->isNotEmpty())
                    <p><b>Repuestos:</b>
                        @foreach($intervention->spareParts as $part)
                            {{ $part->name }} x{{ $part->pivot->cantidad }}@unless($loop->last), @endunless
                        @endforeach
                    </p>
                @endif

                @if($intervention->conformity_notes)
                    <p><b>Observaciones:</b> {{ $intervention->conformity_notes }}</p>
                @endif
            </article>
        @empty
            <p>No se registraron intervenciones técnicas.</p>
        @endforelse
    </section>

    <section class="signatures">
        <div class="line">Firma del técnico responsable</div>
        <div class="line">Firma del director / conformidad</div>
    </section>
</body>
</html>
