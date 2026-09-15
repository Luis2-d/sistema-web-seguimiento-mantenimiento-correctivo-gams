<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\School;
use App\Models\SparePart;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhiteBoxInterventionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unresolved_intervention_assigns_technician_and_creates_status_history(): void
    {
        [$ticket, $technician, $director] = $this->scenario();

        $this->actingAs($technician)
            ->post(route('interventions.store', $ticket), $this->payload())
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame($technician->id, $ticket->assigned_to);
        $this->assertSame('in_progress', $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertDatabaseCount('intervenciones', 1);
        $this->assertDatabaseCount('intervencion_repuesto', 0);
        $this->assertDatabaseHas('historial_estados_solicitud', [
            'solicitud_id' => $ticket->id,
            'estado_anterior' => 'pendiente',
            'estado_nuevo' => 'en_progreso',
        ]);
        $this->assertCount(1, $director->fresh()->notifications);
    }

    public function test_unchanged_status_does_not_create_redundant_history(): void
    {
        [$ticket, $technician] = $this->scenario();
        $ticket->update(['assigned_to' => $technician->id, 'status' => 'in_progress']);

        $this->actingAs($technician)
            ->post(route('interventions.store', $ticket), $this->payload())
            ->assertRedirect();

        $this->assertSame($technician->id, $ticket->fresh()->assigned_to);
        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertDatabaseCount('intervenciones', 1);
        $this->assertDatabaseCount('historial_estados_solicitud', 0);
    }

    public function test_exact_stock_is_consumed_and_resolved_equipment_becomes_operational(): void
    {
        [$ticket, $technician] = $this->scenario();
        $equipment = Equipment::create([
            'school_id' => $ticket->school_id,
            'asset_code' => 'EQ-WHITE-001',
            'type' => 'computer',
            'status' => 'under_repair',
        ]);
        $ticket->update(['equipment_id' => $equipment->id]);
        $part = SparePart::create(['name' => 'Memoria de prueba', 'stock' => 2]);

        $this->actingAs($technician)->post(route('interventions.store', $ticket),
            $this->payload([
                'spare_part_id' => $part->id,
                'quantity' => 2,
                'mark_resolved' => 1,
            ])
        )->assertRedirect();

        $this->assertSame(0, $part->fresh()->stock);
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertSame('operational', $equipment->fresh()->status);
        $this->assertDatabaseHas('intervencion_repuesto', [
            'repuesto_id' => $part->id, 'cantidad' => 2,
        ]);
        $this->assertDatabaseHas('historial_estados_solicitud', [
            'solicitud_id' => $ticket->id, 'estado_nuevo' => 'solucionado',
        ]);
    }

    public function test_insufficient_stock_rolls_back_intervention_and_assignment(): void
    {
        [$ticket, $technician, $director] = $this->scenario();
        $part = SparePart::create(['name' => 'Memoria de prueba', 'stock' => 2]);

        $this->actingAs($technician)->post(route('interventions.store', $ticket),
            $this->payload([
                'spare_part_id' => $part->id,
                'quantity' => 3,
                'mark_resolved' => 1,
            ])
        )->assertStatus(422);

        $this->assertSame(2, $part->fresh()->stock);
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertSame('pending', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('intervencion_repuesto', 0);
        $this->assertDatabaseCount('historial_estados_solicitud', 0);
        $this->assertCount(0, $director->fresh()->notifications);
    }

    public function test_other_technician_is_rejected_before_transaction(): void
    {
        [$ticket, $technician] = $this->scenario();
        $owner = User::factory()->create(['role' => 'technician']);
        $ticket->update(['assigned_to' => $owner->id]);

        $this->actingAs($technician)
            ->post(route('interventions.store', $ticket), $this->payload())
            ->assertForbidden();

        $this->assertSame($owner->id, $ticket->fresh()->assigned_to);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('historial_estados_solicitud', 0);
    }

    public function test_director_is_rejected_before_transaction(): void
    {
        [$ticket, , $director] = $this->scenario();

        $this->actingAs($director)
            ->post(route('interventions.store', $ticket), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertDatabaseCount('historial_estados_solicitud', 0);
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Unidad de pruebas de ramas']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $technician = User::factory()->create(['role' => 'technician']);
        $ticket = Ticket::create([
            'code' => 'SOL-WHITE-001',
            'school_id' => $school->id,
            'requester_id' => $director->id,
            'category' => 'hardware',
            'priority' => 'high',
            'status' => 'pending',
            'subject' => 'Equipo de prueba',
            'description' => 'Verificación de decisiones y transacciones.',
        ]);

        return [$ticket, $technician, $director];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_type' => 'hardware',
            'diagnosis' => 'Se identificó el componente defectuoso.',
            'solution' => 'Se registró la intervención de prueba.',
            'performed_at' => '2026-09-08 09:00:00',
            'mark_resolved' => 0,
        ], $overrides);
    }
}
