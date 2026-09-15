<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    public function index()
    {
        return view('schools.index', [
            'schools' => School::withCount(['users', 'equipment'])->orderBy('nombre')->paginate(15),
        ]);
    }

    public function create()
    {
        return view('schools.form-map', ['item' => new School]);
    }

    public function store(Request $request)
    {
        // Guarda también las coordenadas elegidas con TomTom o escritas en el formulario.
        School::create($this->data($request));

        return redirect()->route('schools.index')->with('success', 'Unidad educativa registrada.');
    }

    public function edit(School $school)
    {
        return view('schools.form-map', ['item' => $school]);
    }

    public function update(Request $request, School $school)
    {
        // Conserva la nueva ubicación cuando el usuario pulsa Guardar unidad.
        $school->update($this->data($request));

        return redirect()->route('schools.index')->with('success', 'Unidad educativa actualizada.');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'district' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            // Comprueba que la ubicación tenga ambas coordenadas y valores válidos.
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:30'],
            'active' => ['required', 'boolean'],
        ]);
    }
}
