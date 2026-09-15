<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaintenanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/panel')->assertRedirect('/login');
    }

    public function test_director_can_create_ticket_and_staff_is_notified(): void
    {
        $school = School::create(['name' => 'Unidad Educativa Test']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $technician = User::factory()->create(['role' => 'technician']);

        $response = $this->actingAs($director)->post('/solicitudes', [
            'school_id' => $school->id,
            'category' => 'hardware',
            'priority' => 'high',
            'subject' => 'Equipo sin energía',
            'description' => 'El equipo no enciende desde esta mañana.',
        ]);

        $ticket = Ticket::firstOrFail();
        $response->assertRedirect(route('tickets.show', $ticket));
        $this->assertSame('pending', $ticket->status);
        $this->assertDatabaseHas('historial_estados_solicitud', ['solicitud_id' => $ticket->id, 'estado_nuevo' => 'pendiente']);
        $this->assertCount(1, $technician->fresh()->notifications);
    }

    public function test_director_cannot_access_administration(): void
    {
        $director = User::factory()->create(['role' => 'director']);
        $this->actingAs($director)->get('/usuarios')->assertForbidden();
        $this->actingAs($director)->get('/repuestos')->assertForbidden();
    }

    public function test_technician_can_resolve_ticket_and_director_is_notified(): void
    {
        $school = School::create(['name' => 'Unidad Educativa Test']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $technician = User::factory()->create(['role' => 'technician']);
        $ticket = Ticket::create(['code' => 'TCK-TEST', 'school_id' => $school->id, 'requester_id' => $director->id, 'assigned_to' => $technician->id, 'category' => 'software', 'priority' => 'medium', 'status' => 'in_progress', 'subject' => 'Prueba', 'description' => 'Descripción de prueba']);

        $this->actingAs($technician)->patch(route('tickets.status', $ticket), ['status' => 'resolved', 'comment' => 'Trabajo concluido'])->assertRedirect();

        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertCount(1, $director->fresh()->notifications);
    }

    public function test_admin_dashboard_renders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $school = School::create(['name' => 'Unidad Educativa Panel']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        Ticket::create(['code' => 'TCK-PANEL', 'school_id' => $school->id, 'requester_id' => $director->id, 'category' => 'hardware', 'priority' => 'medium', 'status' => 'pending', 'subject' => 'Equipo registrado en el panel principal', 'description' => 'Caso utilizado para verificar el panel.']);

        $this->actingAs($admin)->get('/panel')
            ->assertOk()
            ->assertSee('Panel de seguimiento')
            ->assertSee('TCK-PANEL')
            ->assertSee('Equipo registrado en el panel principal');
    }

    public function test_domain_database_uses_spanish_names_and_values(): void
    {
        $this->assertTrue(Schema::hasTable('unidades_educativas'));
        $this->assertTrue(Schema::hasTable('equipos'));
        $this->assertTrue(Schema::hasTable('solicitudes'));
        $this->assertTrue(Schema::hasTable('historial_estados_solicitud'));
        $this->assertTrue(Schema::hasTable('intervenciones'));
        $this->assertTrue(Schema::hasTable('repuestos'));
        $this->assertTrue(Schema::hasColumns('unidades_educativas', ['latitud', 'longitud']));
        $this->assertTrue(Schema::hasColumns('solicitudes', ['codigo', 'unidad_educativa_id', 'equipo_id', 'solicitante_id', 'tecnico_asignado_id', 'categoria', 'prioridad', 'estado', 'asunto', 'descripcion', 'resuelto_en']));

        $school = School::create(['name' => 'Unidad Educativa Español']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $ticket = Ticket::create(['code' => 'SOL-ES-001', 'school_id' => $school->id, 'requester_id' => $director->id, 'category' => 'connectivity', 'priority' => 'critical', 'status' => 'in_progress', 'subject' => 'Prueba en español', 'description' => 'Verificación de valores físicos.']);

        $this->assertDatabaseHas('solicitudes', ['codigo' => 'SOL-ES-001', 'categoria' => 'conectividad', 'prioridad' => 'critica', 'estado' => 'en_progreso']);
        $this->assertFalse(Schema::hasTable('tickets'));
        $this->assertFalse(Schema::hasTable('equipment'));
        $this->assertSame('in_progress', $ticket->status);
    }

    public function test_admin_can_register_school_coordinates_and_open_map_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('schools.store'), [
            'name' => 'Unidad Educativa Geolocalizada',
            'district' => 'Distrito 1',
            'address' => 'Sacaba, Cochabamba',
            'latitude' => -17.3975000,
            'longitude' => -66.0387000,
            'active' => 1,
        ])->assertRedirect(route('schools.index'));

        $school = School::where('nombre', 'Unidad Educativa Geolocalizada')->firstOrFail();
        $this->assertSame('-17.3975000', $school->latitude);
        $this->assertSame('-66.0387000', $school->longitude);

        $this->actingAs($admin)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('Mapa de unidades educativas')
            ->assertSee('Unidad Educativa Geolocalizada');
    }

    public function test_map_visibility_is_limited_for_director_role(): void
    {
        Http::preventStrayRequests();

        $ownSchool = School::create([
            'name' => 'Unidad del Director',
            'latitude' => -17.3975,
            'longitude' => -66.0387,
        ]);
        $otherSchool = School::create([
            'name' => 'Unidad de Otro Director',
            'latitude' => -17.4050,
            'longitude' => -66.0400,
        ]);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $ownSchool->id]);

        $this->actingAs($director)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('Unidad del Director')
            ->assertDontSee('Unidad de Otro Director')
            ->assertViewHas('startRoute', false);

        $this->actingAs($director)
            ->get(route('map.index', ['school' => $otherSchool->id, 'route' => 1]))
            ->assertOk()
            ->assertViewHas('selectedSchoolId', null)
            ->assertViewHas('startRoute', false)
            ->assertSee('El destino solicitado no está disponible en tu mapa.')
            ->assertDontSee('Unidad de Otro Director');

        Http::assertNothingSent();
    }

    public function test_directions_keep_the_selected_school_in_the_map_and_ticket(): void
    {
        Http::preventStrayRequests();
        config()->set('services.tomtom.api_key', 'tomtom-test-key');

        $admin = User::factory()->create(['role' => 'admin']);
        $firstSchool = School::create([
            'name' => 'Colegio Carlos Peredo',
            'latitude' => -17.3975,
            'longitude' => -66.0387,
        ]);
        $secondSchool = School::create([
            'name' => 'Otra unidad educativa',
            'latitude' => -17.405,
            'longitude' => -66.04,
        ]);
        $firstUrl = route('map.index', ['school' => $firstSchool->id, 'route' => 1]);
        $secondUrl = route('map.index', ['school' => $secondSchool->id, 'route' => 1]);

        $this->actingAs($admin)->get($firstUrl)
            ->assertOk()
            ->assertSee('href="'.e($firstUrl).'" data-map-route="'.$firstSchool->id.'"', false)
            ->assertSee('href="'.e($secondUrl).'" data-map-route="'.$secondSchool->id.'"', false)
            ->assertViewHas('selectedSchoolId', $firstSchool->id)
            ->assertViewHas('startRoute', true)
            ->assertViewHas('locations', fn ($locations) =>
                $locations->firstWhere('id', $firstSchool->id)['plannerUrl'] === $firstUrl
                && $locations->firstWhere('id', $secondSchool->id)['plannerUrl'] === $secondUrl
            )
            ->assertSee('data-route-current', false)
            ->assertSee('data-route-pick', false)
            ->assertDontSee('https://plan.tomtom.com/');

        $ticket = Ticket::create([
            'code' => 'SOL-RUTA-001',
            'school_id' => $firstSchool->id,
            'requester_id' => $admin->id,
            'category' => 'hardware',
            'priority' => 'medium',
            'status' => 'pending',
            'subject' => 'Visita a la primera unidad',
            'description' => 'Verificar el destino de la ruta de la solicitud.',
        ]);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('href="'.e($firstUrl).'" class="btn btn-primary btn-sm"', false)
            ->assertDontSee('href="'.e($secondUrl).'"', false)
            ->assertDontSee('https://plan.tomtom.com/');

        $firstSchool->update(['latitude' => null, 'longitude' => null]);

        $this->actingAs($admin)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertDontSee('Cómo llegar')
            ->assertSee('Registrar ubicación');

        Http::assertNothingSent();
    }

    public function test_directions_button_is_disabled_without_tomtom_configuration(): void
    {
        Http::preventStrayRequests();
        config()->set('services.tomtom.api_key', null);
        $admin = User::factory()->create(['role' => 'admin']);
        School::create([
            'name' => 'Unidad con ubicacion',
            'latitude' => -17.3975,
            'longitude' => -66.0387,
        ]);

        $this->actingAs($admin)->get(route('map.index'))
            ->assertOk()
            ->assertSee('Falta configurar TomTom.')
            ->assertSee('disabled title="Falta configurar TomTom"', false)
            ->assertDontSee('data-map-route=', false);

        Http::assertNothingSent();
    }

    public function test_map_search_is_available_without_registered_coordinates(): void
    {
        config()->set('services.tomtom.api_key', 'tomtom-test-key');

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('map.index'))
            ->assertOk()
            ->assertSee('school-overview-map', false)
            ->assertSee('data-map-search-overview', false)
            ->assertSee('El buscador todavía puede consultar lugares en TomTom.');
    }

    public function test_sacaba_school_import_creates_locations_without_duplicates(): void
    {
        Http::fake([
            'overpass-api.de/*' => Http::response([
                'elements' => [
                    [
                        'type' => 'node',
                        'id' => 123,
                        'lat' => -17.4008698,
                        'lon' => -66.0387011,
                        'tags' => [
                            'amenity' => 'school',
                            'name' => 'Unidad Educativa 29 de Junio',
                        ],
                    ],
                    [
                        'type' => 'way',
                        'id' => 456,
                        'center' => ['lat' => -17.4017161, 'lon' => -66.0458397],
                        'tags' => [
                            'amenity' => 'school',
                            'name' => 'U.E. Natalio Arauco',
                            'addr:city' => 'Sacaba',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('schools:import-sacaba')->assertSuccessful();
        $this->artisan('schools:import-sacaba')->assertSuccessful();

        $this->assertDatabaseCount('unidades_educativas', 2);
        $this->assertDatabaseHas('unidades_educativas', [
            'nombre' => 'Unidad Educativa 29 de Junio',
            'distrito' => 'Sacaba',
            'latitud' => -17.4008698,
            'longitud' => -66.0387011,
        ]);
    }

    public function test_technical_report_renders_without_blade_errors(): void
    {
        $school = School::create(['name' => 'Unidad Educativa Acta']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $ticket = Ticket::create(['code' => 'SOL-ACTA-001', 'school_id' => $school->id, 'requester_id' => $director->id, 'category' => 'hardware', 'priority' => 'medium', 'status' => 'pending', 'subject' => 'Falla de prueba', 'description' => 'Descripción para verificar el acta técnica.']);

        $this->actingAs($director)
            ->get(route('tickets.act', $ticket))
            ->assertOk()
            ->assertSee('ACTA DIGITAL DE MANTENIMIENTO CORRECTIVO')
            ->assertSee('SOL-ACTA-001');
    }

    public function test_saving_solution_assigns_technician_resolves_ticket_and_notifies_director(): void
    {
        $school = School::create(['name' => 'Unidad Educativa Flujo']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $technician = User::factory()->create(['role' => 'technician']);
        $ticket = Ticket::create(['code' => 'SOL-FLUJO-001', 'school_id' => $school->id, 'requester_id' => $director->id, 'category' => 'hardware', 'priority' => 'high', 'status' => 'pending', 'subject' => 'Falla de periféricos', 'description' => 'Teclado y ratón sin respuesta.']);

        $this->actingAs($technician)->post(route('interventions.store', $ticket), [
            'service_type' => 'hardware',
            'diagnosis' => 'Puerto con conexión defectuosa.',
            'solution' => 'Se corrigió la conexión y se probaron los periféricos.',
            'performed_at' => now()->format('Y-m-d H:i:s'),
            'quantity' => 1,
            'mark_resolved' => 1,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame('resolved', $ticket->status);
        $this->assertSame($technician->id, $ticket->assigned_to);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertDatabaseHas('historial_estados_solicitud', ['solicitud_id' => $ticket->id, 'estado_anterior' => 'pendiente', 'estado_nuevo' => 'solucionado']);
        $this->assertCount(1, $director->fresh()->notifications);
    }
}
