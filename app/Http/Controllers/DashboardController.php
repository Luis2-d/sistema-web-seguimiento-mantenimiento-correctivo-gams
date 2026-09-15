<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\School;
use App\Models\SparePart;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $tickets = Ticket::query();

        if ($user->isDirector()) {
            $tickets->where('unidad_educativa_id', $user->school_id);
        } elseif ($user->isTechnician()) {
            $tickets->where(fn ($query) => $query
                ->where('tecnico_asignado_id', $user->id)
                ->orWhereNull('tecnico_asignado_id'));
        }

        $rawCounts = (clone $tickets)
            ->selectRaw('estado, count(*) total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $counts = collect([
            'pending' => $rawCounts['pendiente'] ?? 0,
            'diagnosis' => $rawCounts['diagnostico'] ?? 0,
            'waiting_parts' => $rawCounts['espera_repuestos'] ?? 0,
            'in_progress' => $rawCounts['en_progreso'] ?? 0,
            'resolved' => $rawCounts['solucionado'] ?? 0,
            'closed' => $rawCounts['cerrado'] ?? 0,
            'cancelled' => $rawCounts['cancelado'] ?? 0,
        ]);

        $total = (clone $tickets)->count();
        $completedTotal = $counts->only(['resolved', 'closed'])->sum();
        $resolutionRate = $total > 0 ? (int) round(($completedTotal / $total) * 100) : 0;

        $equipment = Equipment::query();
        if ($user->isDirector()) {
            $equipment->where('unidad_educativa_id', $user->school_id);
        }

        $openTickets = (clone $tickets)->whereIn('estado', ['pendiente', 'diagnostico', 'espera_repuestos', 'en_progreso']);
        $urgentTickets = (clone $openTickets)->whereIn('prioridad', ['alta', 'critica']);
        $months = collect(range(5, 0))->map(fn ($offset) => now()->startOfMonth()->subMonths($offset));
        $periodStart = $months->first();
        $createdByMonth = (clone $tickets)->where('created_at', '>=', $periodStart)
            ->get(['created_at'])->countBy(fn ($ticket) => $ticket->created_at->format('Y-m'));
        $resolvedByMonth = (clone $tickets)->whereIn('estado', ['solucionado', 'cerrado'])
            ->where('resuelto_en', '>=', $periodStart)->get(['resuelto_en'])
            ->countBy(fn ($ticket) => $ticket->resolved_at->format('Y-m'));
        $monthlyActivity = $months->map(fn ($month) => [
            'label' => ucfirst($month->locale('es')->translatedFormat('M')),
            'fullLabel' => ucfirst($month->locale('es')->translatedFormat('F Y')),
            'created' => $createdByMonth[$month->format('Y-m')] ?? 0,
            'resolved' => $resolvedByMonth[$month->format('Y-m')] ?? 0,
        ]);
        $schools = School::where('activo', true);
        if ($user->isDirector()) {
            $schools->whereKey($user->school_id);
        } elseif ($user->isTechnician()) {
            $schools->whereIn('id', (clone $openTickets)->select('unidad_educativa_id'));
        }

        return view('dashboard', [
            'monthlyActivity' => $monthlyActivity,
            'openTotal' => (clone $openTickets)->count(),
            'unassignedTotal' => (clone $openTickets)->whereNull('tecnico_asignado_id')->count(),
            'priorityTotal' => (clone $urgentTickets)->count(),
            'schoolTotal' => (clone $schools)->count(),
            'locatedSchoolTotal' => (clone $schools)->whereNotNull('latitud')->whereNotNull('longitud')->count(),
            'counts' => $counts,
            'total' => $total,
            'completedTotal' => $completedTotal,
            'resolutionRate' => $resolutionRate,
            'recent' => (clone $tickets)->with(['school', 'technician'])->latest()->limit(6)->get(),
            'priorityTickets' => (clone $urgentTickets)
                ->with('school')
                ->orderByRaw("CASE prioridad WHEN 'critica' THEN 0 ELSE 1 END")
                ->oldest()
                ->limit(4)
                ->get(),
            'equipment' => $equipment->count(),
            'technicians' => User::where('role', 'technician')->where('active', true)->count(),
            'lowStock' => SparePart::where('stock', '<=', 2)->count(),
        ]);
    }
}
