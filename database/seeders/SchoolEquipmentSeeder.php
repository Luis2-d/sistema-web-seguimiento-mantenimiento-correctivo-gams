<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SchoolEquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $schools = School::where('activo', true)
            ->orderByRaw('CASE WHEN nombre = ? THEN 0 ELSE 1 END', ['U.E. Simón Bolívar'])
            ->orderBy('nombre')->limit(5)->get();

        DB::transaction(function () use ($schools) {
            $nextCode = 1;

            foreach ($schools as $school) {
                foreach (['Lenovo', 'ASUS', 'Dell', 'HP', 'Acer'] as $brand) {
                    if ($school->equipment()->where('marca', $brand)->where('tipo', 'computadora')->exists()) {
                        continue;
                    }

                    do {
                        $assetCode = 'EQ-'.str_pad((string) $nextCode++, 4, '0', STR_PAD_LEFT);
                    } while (Equipment::where('codigo_patrimonial', $assetCode)->exists());

                    Equipment::create([
                        'school_id' => $school->id,
                        'asset_code' => $assetCode,
                        'type' => 'computer',
                        'brand' => $brand,
                        'status' => 'operational',
                        'notes' => 'Registro inicial. Modelo y número de serie pendientes de completar.',
                    ]);
                }
            }
        });
    }
}
