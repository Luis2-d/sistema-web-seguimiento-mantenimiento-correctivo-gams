<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;

class SchoolMapController extends Controller
{
    public function __invoke(Request $request)
    {
        $openStatuses = ['pendiente', 'diagnostico', 'espera_repuestos', 'en_progreso'];
        $user = $request->user();

        // Los puntos del mapa vienen de las unidades guardadas con coordenadas.
        $query = School::query()
            ->where('activo', true)
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->withCount([
                'tickets as open_tickets_count' => fn ($tickets) => $tickets->whereIn('estado', $openStatuses),
            ]);

        if ($user->isDirector()) {
            $query->whereKey($user->school_id);
        }

        $schools = $query->orderBy('nombre')->get();

        // Prepara los datos que JavaScript mostrará sobre el mapa de TomTom.
        $locations = $schools->map(fn (School $school) => [
            'id' => $school->id,
            'name' => $school->name,
            'district' => $school->district,
            'address' => $school->address,
            'latitude' => (float) $school->latitude,
            'longitude' => (float) $school->longitude,
            'openTickets' => $school->open_tickets_count,
            // Abre la ruta dentro del panel con esta unidad como destino.
            'plannerUrl' => route('map.index', ['school' => $school->id, 'route' => 1]),
        ])->values();

        $selectedSchoolId = $locations->contains('id', $request->integer('school'))
            ? $request->integer('school')
            : null;
        $startRoute = $request->boolean('route') && $selectedSchoolId !== null;
        $routeUnavailable = $request->boolean('route') && $selectedSchoolId === null;

        return view('maps.index', compact('schools', 'locations', 'selectedSchoolId', 'startRoute', 'routeUnavailable'));
    }
}
