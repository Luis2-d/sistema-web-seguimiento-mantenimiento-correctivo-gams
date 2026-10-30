<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\School;
use App\Models\SparePart;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $q = Ticket::with(['school', 'equipment', 'requester', 'technician'])->latest();
        $this->scope($q, $request);
        if ($request->filled('status')) {
            $q->where('estado', Ticket::databaseValue('status', $request->status));
        }
        if ($request->filled('search')) {
            $q->where(fn ($x) => $x->where('codigo', 'like', '%'.$request->search.'%')->orWhere('asunto', 'like', '%'.$request->search.'%'));
        }

        return view('tickets.index', ['tickets' => $q->paginate(12)->withQueryString()]);
    }

    public function create(Request $request)
    {
        $schools = School::where('activo', true)->orderBy('nombre')->get();

        return view('tickets.create', [
            'schools' => $schools,
            'equipment' => Equipment::whereIn('unidad_educativa_id', $schools->modelKeys())
                ->orderBy('codigo_patrimonial')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'school_id' => ['required', 'integer', Rule::exists('unidades_educativas', 'id')->where('activo', true)],
            'equipment_id' => ['nullable', 'integer', Rule::exists('equipos', 'id')
                ->where('unidad_educativa_id', $request->integer('school_id'))],
            'category' => 'required|in:hardware,software,connectivity',
            'priority' => 'required|in:low,medium,high,critical',
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
        ], ['equipment_id.exists' => 'El equipo debe pertenecer a la unidad educativa de la solicitud.']);
        $data['requester_id'] = $request->user()->id;
        $data['status'] = 'pending';
        $data['code'] = 'SOL-'.now()->format('Ym').'-'.strtoupper(Str::random(6));
        $ticket = DB::transaction(function () use ($data, $request) {
            $t = Ticket::create($data);
            TicketStatusHistory::create(['ticket_id' => $t->id, 'changed_by' => $request->user()->id, 'to_status' => 'pending', 'comment' => 'Solicitud registrada']);
            if ($t->equipment) {
                $t->equipment->update(['status' => 'under_repair']);
            }

            return $t;
        });
        User::whereIn('role', ['admin', 'technician'])->where('active', true)->get()->each->notify(new TicketEventNotification($ticket, 'new'));

        return redirect()->route('tickets.show', $ticket)->with('success', 'Solicitud registrada correctamente.');
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->authorizeView($request, $ticket);
        $ticket->load(['school', 'equipment', 'requester', 'technician', 'histories.user', 'interventions.technician', 'interventions.spareParts']);

        return view('tickets.show', ['ticket' => $ticket, 'technicians' => User::where('role', 'technician')->where('active', true)->orderBy('name')->get(), 'parts' => SparePart::where('stock', '>', 0)->orderBy('nombre')->get(), 'visits' => \App\Models\TechnicalVisit::visibleTo($request->user())->where('solicitud_id', $ticket->id)->with('technician')->orderByDesc('inicio')->get()]);
    }

    public function act(Request $request, Ticket $ticket)
    {
        $this->authorizeView($request, $ticket);
        $ticket->load(['school', 'equipment', 'requester', 'technician', 'interventions.technician', 'interventions.spareParts']);

        return view('tickets.act', compact('ticket'));
    }

    public function assign(Request $request, Ticket $ticket)
    {
        abort_if($request->user()->isDirector(), 403);
        $this->authorizeView($request, $ticket);
        $data = $request->validate(['assigned_to' => ['required', 'integer',
            Rule::exists('users', 'id')->where('role', 'technician')->where('active', true)]]);
        $ticket->update($data);

        return back()->with('success', 'Técnico asignado.');
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
        abort_if($request->user()->isDirector(), 403);
        $this->authorizeView($request, $ticket);
        $data = $request->validate(['status' => 'required|in:pending,diagnosis,waiting_parts,in_progress,resolved,closed,cancelled', 'comment' => 'nullable|string|max:1000']);
        $from = $ticket->status;
        DB::transaction(function () use ($ticket, $data, $from, $request) {
            $completed = in_array($data['status'], ['resolved', 'closed'], true);
            $ticket->update([
                'status' => $data['status'],
                'resolved_at' => $completed ? ($ticket->resolved_at ?? now()) : null,
            ]);
            TicketStatusHistory::create(['ticket_id' => $ticket->id, 'changed_by' => $request->user()->id, 'from_status' => $from, 'to_status' => $data['status'], 'comment' => $data['comment'] ?? null]);
            $ticket->equipment?->syncMaintenanceStatus($completed);
        });
        $ticket->requester->notify(new TicketEventNotification($ticket, 'status'));

        return back()->with('success', 'Estado actualizado.');
    }

    private function scope($q, Request $r)
    {
        if ($r->user()->isDirector()) {
            $q->where(fn ($query) => $query->where('unidad_educativa_id', $r->user()->school_id)
                ->orWhere('solicitante_id', $r->user()->id));
        } elseif ($r->user()->isTechnician()) {
            $q->where(fn ($x) => $x->where('tecnico_asignado_id', $r->user()->id)->orWhereNull('tecnico_asignado_id'));
        }
    }

    private function authorizeView(Request $r, Ticket $t)
    {
        if ($r->user()->isDirector()) {
            abort_unless($t->school_id === $r->user()->school_id || $t->requester_id === $r->user()->id, 403);
        }
        if ($r->user()->isTechnician()) {
            abort_unless($t->assigned_to === $r->user()->id || is_null($t->assigned_to), 403);
        }
    }
}
