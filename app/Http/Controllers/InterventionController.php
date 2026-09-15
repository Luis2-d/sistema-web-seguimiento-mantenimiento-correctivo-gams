<?php

namespace App\Http\Controllers;

use App\Models\{Intervention, SparePart, Ticket, TicketStatusHistory};
use App\Notifications\TicketEventNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterventionController extends Controller
{
    public function store(Request $request, Ticket $ticket)
    {
        abort_if($request->user()->isDirector(), 403);
        abort_if(
            $request->user()->isTechnician() && $ticket->assigned_to && $ticket->assigned_to !== $request->user()->id,
            403,
            'La solicitud está asignada a otro técnico.'
        );

        $data = $request->validate([
            'service_type' => 'required|in:hardware,software,connectivity',
            'diagnosis' => 'required|max:5000',
            'solution' => 'required|max:5000',
            'conformity_notes' => 'nullable|max:3000',
            'performed_at' => 'required|date',
            'spare_part_id' => 'nullable|exists:repuestos,id',
            'quantity' => 'nullable|integer|min:1',
            'mark_resolved' => 'required|boolean',
        ]);

        $newStatus = $data['mark_resolved'] ? 'resolved' : 'in_progress';
        $previousStatus = $ticket->status;

        DB::transaction(function () use ($data, $request, $ticket, $newStatus, $previousStatus) {
            if ($request->user()->isTechnician() && !$ticket->assigned_to) {
                $ticket->update(['assigned_to' => $request->user()->id]);
            }

            $intervention = Intervention::create([
                ...$data,
                'ticket_id' => $ticket->id,
                'technician_id' => $request->user()->id,
            ]);

            if (!empty($data['spare_part_id'])) {
                $part = SparePart::lockForUpdate()->findOrFail($data['spare_part_id']);
                $quantity = $data['quantity'] ?? 1;
                abort_if($part->stock < $quantity, 422, 'Stock insuficiente.');
                $intervention->spareParts()->attach($part->id, ['cantidad' => $quantity]);
                $part->decrement('stock', $quantity);
            }

            $ticket->update([
                'status' => $newStatus,
                'resolved_at' => $newStatus === 'resolved' ? now() : null,
            ]);

            if ($previousStatus !== $newStatus) {
                TicketStatusHistory::create([
                    'ticket_id' => $ticket->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $previousStatus,
                    'to_status' => $newStatus,
                    'comment' => $newStatus === 'resolved'
                        ? 'Intervención concluida y solicitud solucionada.'
                        : 'Intervención registrada; la solicitud continúa en progreso.',
                ]);
            }

            if ($newStatus === 'resolved') {
                $ticket->equipment?->update(['status' => 'operational']);
            }
        });

        $ticket->refresh();
        $ticket->requester->notify(new TicketEventNotification($ticket, 'status'));

        return back()->with(
            'success',
            $newStatus === 'resolved'
                ? 'Intervención registrada y solicitud marcada como solucionada.'
                : 'Intervención registrada; la solicitud quedó en progreso.'
        );
    }
}
