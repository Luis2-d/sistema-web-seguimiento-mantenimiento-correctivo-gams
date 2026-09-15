<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportSacabaSchools extends Command
{
    // Overpass permite consultar las escuelas registradas en OpenStreetMap.
    private const OVERPASS_ENDPOINT = 'https://overpass-api.de/api/interpreter';

    private const SACABA_AREA_ID = 3604505643;

    // Se ejecuta desde la consola con: php artisan schools:import-sacaba.
    protected $signature = 'schools:import-sacaba {--dry-run : Consulta la fuente sin modificar la base de datos}';

    protected $description = 'Importa las unidades educativas cartografiadas dentro del municipio de Sacaba';

    public function handle(): int
    {
        $this->info('Consultando unidades educativas del municipio de Sacaba…');

        // Pide nombres, datos y coordenadas de escuelas dentro del municipio de Sacaba.
        $query = sprintf(
            '[out:json][timeout:90];area(%d)->.sacaba;nwr["amenity"="school"](area.sacaba);out center tags;',
            self::SACABA_AREA_ID,
        );

        try {
            // Envía la consulta a la API y espera la respuesta de OpenStreetMap.
            $response = Http::withHeaders([
                'User-Agent' => 'GestionSeguimiento/1.0',
            ])->timeout(100)->retry(2, 1000)->get(self::OVERPASS_ENDPOINT, [
                'data' => $query,
            ])->throw();
        } catch (\Throwable $exception) {
            $this->error('No se pudo consultar OpenStreetMap: '.$exception->getMessage());

            return self::FAILURE;
        }

        // Convierte la respuesta en unidades con nombre y coordenadas válidas.
        $schools = collect($response->json('elements', []))
            ->map(fn (array $element) => $this->mapElement($element))
            ->filter()
            ->unique(fn (array $school) => $school['source_id'])
            ->values();

        if ($schools->isEmpty()) {
            $this->warn('La fuente no devolvió unidades educativas con nombre y coordenadas.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Nombre', 'Dirección', 'Latitud', 'Longitud'],
                $schools->map(fn (array $school) => [
                    $school['name'],
                    $school['address'],
                    $school['latitude'],
                    $school['longitude'],
                ]),
            );
            $this->info("Se encontraron {$schools->count()} unidades. No se modificó la base de datos.");

            return self::SUCCESS;
        }

        $created = 0;
        $located = 0;
        $unchanged = 0;

        // Guarda las unidades importadas para que luego aparezcan en el mapa del panel.
        DB::transaction(function () use ($schools, &$created, &$located, &$unchanged): void {
            foreach ($schools as $data) {
                $sameLocation = School::query()
                    ->where('nombre', $data['name'])
                    ->where('latitud', $data['latitude'])
                    ->where('longitud', $data['longitude'])
                    ->first();

                if ($sameLocation) {
                    $unchanged++;

                    continue;
                }

                $unlocated = School::query()
                    ->where('nombre', $data['name'])
                    ->where(function ($query): void {
                        $query->whereNull('latitud')->orWhereNull('longitud');
                    })
                    ->first();

                if ($unlocated) {
                    $unlocated->latitude = $data['latitude'];
                    $unlocated->longitude = $data['longitude'];
                    $unlocated->district = $unlocated->district ?: 'Sacaba';
                    $unlocated->address = $unlocated->address ?: $data['address'];
                    $unlocated->save();
                    $located++;

                    continue;
                }

                School::create([
                    'name' => $data['name'],
                    'district' => 'Sacaba',
                    'address' => $data['address'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'active' => true,
                ]);
                $created++;
            }
        });

        $this->newLine();
        $this->info("Importación terminada: {$created} creadas, {$located} completadas y {$unchanged} sin cambios.");
        $this->line('Fuente: OpenStreetMap contributors (ODbL), límite administrativo de Sacaba.');

        return self::SUCCESS;
    }

    private function mapElement(array $element): ?array
    {
        $tags = $element['tags'] ?? [];
        $name = trim((string) ($tags['name'] ?? ''));
        $latitude = $element['lat'] ?? data_get($element, 'center.lat');
        $longitude = $element['lon'] ?? data_get($element, 'center.lon');

        if ($name === '' || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $address = collect([
            $tags['addr:street'] ?? null,
            $tags['addr:housenumber'] ?? null,
            $tags['addr:suburb'] ?? null,
            $tags['addr:city'] ?? null,
        ])->filter()->implode(', ');

        return [
            'source_id' => ($element['type'] ?? 'element').':'.($element['id'] ?? ''),
            'name' => $name,
            'address' => $address ?: 'Municipio de Sacaba',
            'latitude' => number_format((float) $latitude, 7, '.', ''),
            'longitude' => number_format((float) $longitude, 7, '.', ''),
        ];
    }
}
