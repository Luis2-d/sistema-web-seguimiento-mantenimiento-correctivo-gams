<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\School;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07 14:00:00', 'UTC'));
    }

    public function test_inactive_user_cannot_log_in_with_correct_password(): void
    {
        $user = User::factory()->create(['active' => false, 'password' => 'Password123!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivating_a_logged_in_user_revokes_access(): void
    {
        $user = User::factory()->create(['active' => true, 'role' => 'admin']);
        $this->actingAs($user);
        $user->update(['active' => false]);
        $this->get('/inicio')->assertRedirect('/login');
        $this->assertGuest();
    }

    public static function invalidAssignees(): array
    {
        return [['director', true], ['admin', true], ['technician', false]];
    }

    #[DataProvider('invalidAssignees')]
    public function test_assignment_requires_an_active_technician(string $role, bool $active): void
    {
        [$ticket, $admin] = $this->scenario();
        $target = User::factory()->create(compact('role', 'active'));
        $this->actingAs($admin)->patchJson(route('tickets.assign', $ticket), ['assigned_to' => $target->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    #[DataProvider('roles')]
    public function test_creation_form_includes_all_active_schools_and_their_equipment(string $role): void
    {
        [$ticket, , $equipment] = $this->scenario();
        $user = User::factory()->create(['role' => $role, 'school_id' => $ticket->school_id]);
        $other = Equipment::create(['school_id' => School::create(['name' => 'Otra unidad'])->id,
            'asset_code' => 'AJENO-PRIVADO', 'type' => 'computer', 'status' => 'operational']);
        $inactiveSchool = School::create(['name' => 'Unidad inactiva', 'active' => false]);
        $inactiveEquipment = Equipment::create(['school_id' => $inactiveSchool->id,
            'asset_code' => 'INACTIVO-01', 'type' => 'computer', 'status' => 'operational']);
        $this->actingAs($user)->get('/solicitudes/crear')->assertOk()
            ->assertSee($ticket->school->name)->assertSee('Otra unidad')
            ->assertSee($equipment->asset_code)->assertSee($other->asset_code)
            ->assertDontSee($inactiveSchool->name)->assertDontSee($inactiveEquipment->asset_code)
            ->assertSee('data-school-id="'.$other->school_id.'"', false)
            ->assertSee('js/ticket-form.js');
    }

    public static function roles(): array
    {
        return [['admin'], ['technician'], ['director']];
    }

    #[DataProvider('roles')]
    public function test_ticket_rejects_equipment_from_another_school(string $role): void
    {
        [$ticket, , $equipment] = $this->scenario();
        $ownSchool = School::create(['name' => 'Unidad solicitante']);
        $user = User::factory()->create(['role' => $role, 'school_id' => $ownSchool->id]);
        $this->actingAs($user)->postJson('/solicitudes', [
            'school_id' => $ownSchool->id, 'equipment_id' => $equipment->id,
            'category' => 'hardware', 'priority' => 'high', 'subject' => 'Falla', 'description' => 'No enciende.',
        ])->assertUnprocessable()->assertJsonValidationErrors('equipment_id');
        $this->assertDatabaseCount('solicitudes', 1);
        $this->assertSame('under_repair', $equipment->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_director_without_assigned_school_can_select_a_school_and_create_a_ticket(): void
    {
        [$ticket] = $this->scenario();
        $director = User::factory()->create(['role' => 'director', 'school_id' => null]);
        $this->actingAs($director)->get('/solicitudes/crear')->assertOk();
        $this->post('/solicitudes', [
            'school_id' => $ticket->school_id, 'category' => 'hardware', 'priority' => 'high',
            'subject' => 'Falla', 'description' => 'No enciende.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('solicitudes', 2);
        $created = Ticket::where('solicitante_id', $director->id)->firstOrFail();
        $this->get(route('tickets.show', $created))->assertOk();
    }

    public function test_director_can_follow_own_submission_for_another_school_without_accessing_other_submissions(): void
    {
        [$otherTicket] = $this->scenario();
        $school = School::create(['name' => 'Unidad del director']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $this->actingAs($director)->post('/solicitudes', [
            'school_id' => $otherTicket->school_id, 'category' => 'hardware', 'priority' => 'high',
            'subject' => 'Solicitud creada para otra unidad', 'description' => 'No enciende.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $created = Ticket::where('solicitante_id', $director->id)->firstOrFail();
        $this->get(route('tickets.show', $created))->assertOk();
        $this->get(route('tickets.act', $created))->assertOk();
        $this->get(route('tickets.index', ['search' => $created->code]))->assertOk()
            ->assertSee($created->code)->assertDontSee($otherTicket->code);
        $this->get(route('tickets.show', $otherTicket))->assertForbidden();
    }

    public function test_submission_requires_an_active_school(): void
    {
        $school = School::create(['name' => 'Unidad inactiva', 'active' => false]);
        $user = User::factory()->create(['role' => 'director']);
        foreach (['', $school->id, 99999] as $schoolId) {
            $this->actingAs($user)->postJson('/solicitudes', [
                'school_id' => $schoolId, 'category' => 'hardware', 'priority' => 'high',
                'subject' => 'Falla', 'description' => 'No enciende.',
            ])->assertUnprocessable()->assertJsonValidationErrors('school_id');
        }
        $this->assertDatabaseCount('solicitudes', 0);
    }

    public static function updateMethods(): array
    {
        return [['status'], ['intervention']];
    }

    #[DataProvider('updateMethods')]
    public function test_reopening_clears_resolution_date_and_marks_equipment_under_repair(string $method): void
    {
        [$ticket, $admin, $equipment] = $this->scenario();
        $ticket->update(['status' => 'resolved', 'resolved_at' => now()->subDay()]);
        $equipment->update(['status' => 'operational']);
        $this->actingAs($admin);
        $response = $method === 'status'
            ? $this->patch(route('tickets.status', $ticket), ['status' => 'in_progress'])
            : $this->post(route('interventions.store', $ticket), $this->intervention(false));
        $response->assertRedirect();
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertSame('under_repair', $equipment->fresh()->status);
    }

    #[DataProvider('updateMethods')]
    public function test_resolving_one_ticket_does_not_mark_equipment_operational_with_another_open_ticket(string $method): void
    {
        [$ticket, $admin, $equipment] = $this->scenario();
        Ticket::create(['code' => 'OTRO-ABIERTO', 'school_id' => $ticket->school_id, 'equipment_id' => $equipment->id,
            'requester_id' => $admin->id, 'category' => 'hardware', 'priority' => 'medium', 'status' => 'pending',
            'subject' => 'Segunda falla', 'description' => 'Pendiente de revisar.']);
        $this->actingAs($admin);
        $response = $method === 'status'
            ? $this->patch(route('tickets.status', $ticket), ['status' => 'resolved'])
            : $this->post(route('interventions.store', $ticket), $this->intervention(true));
        $response->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertSame('under_repair', $equipment->fresh()->status);
    }

    public function test_direct_closure_records_a_completion_date_for_dashboard_statistics(): void
    {
        [$ticket, $admin] = $this->scenario();
        $this->actingAs($admin)->patch(route('tickets.status', $ticket), ['status' => 'closed'])->assertRedirect();
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->get('/inicio')->assertOk()
            ->assertViewHas('monthlyActivity', fn ($months) => $months->sum('resolved') === 1);
    }

    public static function textFields(): array
    {
        return [['diagnosis'], ['solution'], ['conformity_notes']];
    }

    #[DataProvider('textFields')]
    public function test_intervention_rejects_arrays_in_text_fields_without_partial_changes(string $field): void
    {
        [$ticket, $admin] = $this->scenario();
        $data = $this->intervention(true);
        $data[$field] = ['texto no escalar'];
        $this->actingAs($admin)->postJson(route('interventions.store', $ticket), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('intervenciones', 0);
        $this->assertSame('pending', $ticket->fresh()->status);
    }

    public static function invalidReportFilters(): array
    {
        return [
            [['from' => 'fecha-invalida'], 'from'],
            [['from' => ['2026-10-01']], 'from'],
            [['from' => '2026-10-08', 'to' => '2026-10-01'], 'to'],
            [['category' => 'otra'], 'category'],
            [['status' => 'otro'], 'status'],
        ];
    }

    #[DataProvider('invalidReportFilters')]
    public function test_reports_and_exports_validate_filters(array $filters, string $field): void
    {
        [, $admin] = $this->scenario();
        foreach (['reports.index', 'reports.export'] as $route) {
            $this->actingAs($admin)->getJson(route($route, $filters))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_csv_exports_user_text_as_text_instead_of_a_spreadsheet_formula(): void
    {
        [$ticket, $admin] = $this->scenario();
        $ticket->update(['subject' => '=1+1']);
        $response = $this->actingAs($admin)->get(route('reports.export'))->assertOk();
        $lines = explode("\n", trim($response->streamedContent()));
        $row = str_getcsv($lines[1]);
        $this->assertSame("'=1+1", $row[3]);
    }

    private function intervention(bool $resolved): array
    {
        return ['service_type' => 'hardware', 'diagnosis' => 'Diagnóstico de prueba', 'solution' => 'Trabajo de prueba',
            'performed_at' => '2026-10-07 10:00:00', 'mark_resolved' => $resolved];
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Unidad de auditoría']);
        $admin = User::factory()->create(['role' => 'admin']);
        $equipment = Equipment::create(['school_id' => $school->id, 'asset_code' => 'AUD-001',
            'type' => 'computer', 'status' => 'under_repair']);
        $ticket = Ticket::create(['code' => 'AUD-TICKET', 'school_id' => $school->id, 'equipment_id' => $equipment->id,
            'requester_id' => $admin->id, 'category' => 'hardware', 'priority' => 'high', 'status' => 'pending',
            'subject' => 'Falla de prueba', 'description' => 'Escenario aislado.']);

        return [$ticket, $admin, $equipment];
    }
}
