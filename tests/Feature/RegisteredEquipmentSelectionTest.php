<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\School;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SchoolEquipmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisteredEquipmentSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_equipment_is_registered_and_available_for_a_school_ticket(): void
    {
        $school = School::create(['name' => 'Unidad con inventario']);
        $admin = User::factory()->create(['role' => 'admin']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);

        foreach (['Lenovo', 'ASUS', 'Dell', 'HP', 'Acer'] as $index => $brand) {
            $this->actingAs($admin)->post(route('equipment.store'), [
                'school_id' => $school->id, 'asset_code' => 'REG-'.$index,
                'type' => 'computer', 'brand' => $brand, 'status' => 'operational',
            ])->assertRedirect(route('equipment.index'))->assertSessionHasNoErrors();
        }

        $response = $this->actingAs($director)->get(route('tickets.create'))->assertOk();
        foreach (Equipment::all() as $equipment) {
            $response->assertSee($equipment->asset_code)->assertSee($equipment->brand)
                ->assertSee('value="'.$equipment->id.'" data-school-id="'.$school->id.'"', false);
        }
        $response->assertDontSee('value="brand:', false);

        $asus = Equipment::where('marca', 'ASUS')->firstOrFail();
        $this->post(route('tickets.store'), $this->submission($school, (string) $asus->id))
            ->assertRedirect()->assertSessionHasNoErrors();
        $ticket = Ticket::firstOrFail();
        $this->assertSame($asus->id, $ticket->equipment_id);
        $this->assertSame('under_repair', $asus->fresh()->status);
        $this->get(route('tickets.show', $ticket))->assertOk()->assertSee('ASUS')->assertSee($asus->asset_code);
        $this->get(route('tickets.act', $ticket))->assertOk()->assertSee('ASUS')->assertSee($asus->asset_code);
    }

    public function test_a_brand_without_an_equipment_record_cannot_be_submitted(): void
    {
        $school = School::create(['name' => 'Unidad sin inventario']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        foreach (['brand:Lenovo', 'brand:ASUS', 'brand:Dell', '99999'] as $selection) {
            $this->actingAs($director)->postJson(route('tickets.store'), $this->submission($school, $selection))
                ->assertUnprocessable()->assertJsonValidationErrors('equipment_id');
        }
        $this->assertDatabaseCount('solicitudes', 0);
        $this->assertDatabaseCount('equipos', 0);
    }

    public function test_registered_equipment_selection_survives_validation_errors(): void
    {
        $school = School::create(['name' => 'Unidad de prueba']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $equipment = Equipment::create(['school_id' => $school->id, 'asset_code' => 'REG-ASUS',
            'brand' => 'ASUS', 'type' => 'computer', 'status' => 'operational']);
        $data = $this->submission($school, (string) $equipment->id);
        $data['subject'] = '';
        $this->actingAs($director)->from(route('tickets.create'))->post(route('tickets.store'), $data)
            ->assertRedirect(route('tickets.create'))->assertSessionHasErrors('subject');
        $response = $this->get(route('tickets.create'))->assertOk();
        $this->assertMatchesRegularExpression('/<option[^>]+value="'.$equipment->id.'"[^>]+selected[^>]*>/', $response->getContent());
    }

    public function test_initial_inventory_populates_five_active_schools_without_duplicates_or_overwriting_existing_equipment(): void
    {
        $mainSchool = School::create(['name' => 'U.E. Simón Bolívar']);
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            School::create(['name' => 'Unidad '.$name]);
        }
        $inactive = School::create(['name' => 'Unidad inactiva', 'active' => false]);
        $existing = Equipment::create(['school_id' => $mainSchool->id, 'asset_code' => 'EQ-0001',
            'brand' => 'Lenovo', 'model' => 'ThinkCentre', 'type' => 'computer', 'status' => 'under_repair']);

        $this->seed(SchoolEquipmentSeeder::class);
        $this->seed(SchoolEquipmentSeeder::class);

        $this->assertDatabaseCount('equipos', 25);
        $this->assertSame(5, Equipment::distinct()->count('unidad_educativa_id'));
        $this->assertSame(25, Equipment::distinct()->count('codigo_patrimonial'));
        $this->assertSame(0, $inactive->equipment()->count());
        $this->assertSame(5, $mainSchool->equipment()->count());
        $this->assertSame('under_repair', $existing->fresh()->status);
        $this->assertSame('ThinkCentre', $existing->fresh()->model);
        foreach (Equipment::whereKeyNot($existing->id)->get() as $equipment) {
            $this->assertNull($equipment->model);
            $this->assertNull($equipment->serial_number);
        }
    }

    private function submission(School $school, string $equipment): array
    {
        return ['school_id' => $school->id, 'equipment_id' => $equipment,
            'category' => 'hardware', 'priority' => 'medium', 'subject' => 'Falla de equipo',
            'description' => 'El equipo no enciende.'];
    }
}
