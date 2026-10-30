<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SchoolAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_sees_all_active_located_schools_and_can_select_a_route(): void
    {
        Http::preventStrayRequests();
        $technician = User::factory()->create(['role' => 'technician']);
        $other = User::factory()->create(['role' => 'technician']);
        $empty = School::create(['name' => 'Colegio sin solicitudes', 'latitude' => -17.4, 'longitude' => -66.04]);
        $assigned = School::create(['name' => 'Colegio de otro técnico', 'latitude' => -17.41, 'longitude' => -66.05]);
        School::create(['name' => 'Colegio inactivo', 'active' => false, 'latitude' => -17.42, 'longitude' => -66.06]);
        School::create(['name' => 'Colegio sin coordenadas']);
        Ticket::create([
            'code' => 'SOL-MAPA-TECH', 'school_id' => $assigned->id,
            'requester_id' => $other->id, 'assigned_to' => $other->id,
            'category' => 'hardware', 'priority' => 'medium', 'status' => 'pending',
            'subject' => 'Equipo con falla', 'description' => 'Solicitud de otro técnico.',
        ]);

        $this->actingAs($technician)->get(route('map.index', ['school' => $empty->id, 'route' => 1]))
            ->assertOk()
            ->assertViewHas('locations', fn ($locations) => $locations->pluck('id')->sort()->values()->all() === [$empty->id, $assigned->id])
            ->assertViewHas('selectedSchoolId', $empty->id)
            ->assertViewHas('startRoute', true)
            ->assertSee('Colegio sin solicitudes')
            ->assertSee('Colegio de otro técnico')
            ->assertDontSee('Colegio inactivo')
            ->assertDontSee('Colegio sin coordenadas')
            ->assertSee('Ver unidades educativas');
        Http::assertNothingSent();
    }

    public function test_technician_can_read_school_list_but_cannot_manage_schools(): void
    {
        $technician = User::factory()->create(['role' => 'technician']);
        $school = School::create(['name' => 'Colegio para consulta']);
        $this->actingAs($technician)->get(route('schools.index'))
            ->assertOk()->assertSee($school->name)
            ->assertDontSee(route('schools.create'), false)
            ->assertDontSee(route('schools.edit', $school), false);
        $this->get(route('schools.create'))->assertForbidden();
        $this->get(route('schools.edit', $school))->assertForbidden();
        $this->post(route('schools.store'), ['name' => 'No permitido'])->assertForbidden();
        $this->put(route('schools.update', $school), ['name' => 'No permitido'])->assertForbidden();
        $this->assertSame('Colegio para consulta', $school->fresh()->name);
        $this->assertDatabaseCount('unidades_educativas', 1);
    }

    public function test_admin_keeps_management_links_and_director_cannot_access_general_list(): void
    {
        $school = School::create(['name' => 'Colegio de administración']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('schools.index'))->assertOk()
            ->assertSee(route('schools.create'), false)
            ->assertSee(route('schools.edit', $school), false);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $this->actingAs($director)->get(route('schools.index'))->assertForbidden();
    }
}
