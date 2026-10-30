<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\TechnicalVisit;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_activity_uses_creation_and_resolution_dates_and_counts_all_priorities(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 6)->startOfDay());
        $admin = User::factory()->create(['role' => 'admin']);
        $school = School::create(['name' => 'Unidad de prueba']);
        for ($i = 0; $i < 6; $i++) {
            $this->ticket($school, $admin, ['code' => 'URG-'.$i]);
        }
        $resolved = $this->ticket($school, $admin, [
            'code' => 'RESUELTA', 'status' => 'resolved',
            'resolved_at' => '2026-08-10 10:00:00',
        ]);
        $resolved->created_at = '2026-03-15 10:00:00';
        $resolved->save();
        $this->ticket($school, $admin, ['code' => 'CANCELADA', 'status' => 'cancelled']);

        $response = $this->actingAs($admin)->get('/panel')->assertOk()
            ->assertViewHas('total', 8)
            ->assertViewHas('priorityTotal', 6)
            ->assertViewHas('openTotal', 6)
            ->assertViewHas('unassignedTotal', 6)
            ->assertViewHas('completedTotal', 1)
            ->assertViewHas('priorityTickets', fn ($items) => $items->count() === 4);
        $months = $response->viewData('monthlyActivity');
        $this->assertCount(6, $months);
        $this->assertSame(7, $months->sum('created'));
        $this->assertSame(1, $months[4]['resolved']);
        $this->assertSame(0, $months[5]['resolved']);
    }

    public function test_director_dashboard_only_counts_own_school_and_hides_staff_shortcuts(): void
    {
        $ownSchool = School::create(['name' => 'Mi unidad', 'latitude' => -17.4, 'longitude' => -66.04]);
        $otherSchool = School::create(['name' => 'Unidad ajena']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $ownSchool->id]);
        $this->ticket($ownSchool, $director, ['code' => 'CASO-PROPIO']);
        $this->ticket($otherSchool, $director, ['code' => 'CASO-AJENO']);

        $this->actingAs($director)->get('/panel')->assertOk()
            ->assertViewHas('total', 1)
            ->assertViewHas('priorityTotal', 1)
            ->assertViewHas('schoolTotal', 1)
            ->assertViewHas('locatedSchoolTotal', 1)
            ->assertViewHas('monthlyActivity', fn ($months) => $months->sum('created') === 1)
            ->assertSee('CASO-PROPIO')
            ->assertDontSee('CASO-AJENO')
            ->assertDontSee('Equipo de soporte')
            ->assertDontSee('con stock bajo');
    }

    public function test_technician_dashboard_counts_assigned_and_unassigned_cases_only(): void
    {
        $school = School::create(['name' => 'Unidad compartida']);
        $otherSchool = School::create(['name' => 'Otra unidad']);
        $technician = User::factory()->create(['role' => 'technician']);
        $otherTechnician = User::factory()->create(['role' => 'technician']);
        $this->ticket($school, $technician, ['code' => 'ASIGNADA', 'assigned_to' => $technician->id]);
        $this->ticket($school, $technician, ['code' => 'SIN-ASIGNAR']);
        $this->ticket($otherSchool, $technician, ['code' => 'AJENA', 'assigned_to' => $otherTechnician->id]);

        $this->actingAs($technician)->get('/panel')->assertOk()
            ->assertViewHas('total', 2)
            ->assertViewHas('unassignedTotal', 1)
            ->assertViewHas('schoolTotal', 1)
            ->assertViewHas('monthlyActivity', fn ($months) => $months->sum('created') === 2)
            ->assertDontSee('AJENA')
            ->assertDontSee('Equipo de soporte');
    }

    public function test_empty_dashboard_renders_without_invalid_chart_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/panel')->assertOk()
            ->assertViewHas('resolutionRate', 0)
            ->assertViewHas('monthlyActivity', fn ($months) => $months->count() === 6 && $months->sum('created') === 0)
            ->assertSee('El seguimiento comienza aquí')
            ->assertSee('Sin casos prioritarios')
            ->assertSee('Tu agenda está libre')
            ->assertDontSee('NaN');
    }

    public function test_agenda_shows_the_next_four_active_visits_in_local_time_including_visits_in_progress(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-02 14:00:00', 'UTC'));
        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'technician']);
        $school = School::create(['name' => 'Unidad con visitas']);
        $ticket = $this->ticket($school, $admin, ['code' => 'AGENDA']);
        $makeVisit = fn ($start, $end, $state = 'programada') => TechnicalVisit::create([
            'solicitud_id' => $ticket->id, 'tecnico_id' => $technician->id,
            'inicio' => $start, 'fin' => $end, 'estado' => $state,
        ]);
        $makeVisit('2026-10-02 12:00:00', '2026-10-02 13:00:00');
        $makeVisit('2026-10-02 14:00:00', '2026-10-02 15:00:00', 'cancelada');
        $later = $makeVisit('2026-10-04 15:00:00', '2026-10-04 16:00:00');
        $current = $makeVisit('2026-10-02 13:30:00', '2026-10-02 14:30:00');
        $next = $makeVisit('2026-10-02 15:00:00', '2026-10-02 16:00:00');
        $tomorrow = $makeVisit('2026-10-03 15:00:00', '2026-10-03 16:00:00');
        $makeVisit('2026-10-05 15:00:00', '2026-10-05 16:00:00');

        $this->actingAs($admin)->get('/inicio')->assertOk()
            ->assertViewHas('upcomingVisits', fn ($visits) => $visits->modelKeys() === [$current->id, $next->id, $tomorrow->id, $later->id])
            ->assertSee('09:30')->assertSee('11:00')->assertSee('En curso')->assertSee('Hoy')
            ->assertSeeInOrder(['Solicitudes prioritarias', 'Próximas visitas', 'Últimas solicitudes', 'Evolución de solicitudes']);
    }

    public function test_agenda_respects_school_and_technician_visibility(): void
    {
        $school = School::create(['name' => 'Unidad propia']);
        $otherSchool = School::create(['name' => 'Otra unidad']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $technician = User::factory()->create(['role' => 'technician']);
        $otherTechnician = User::factory()->create(['role' => 'technician']);
        $ownTicket = $this->ticket($school, $director, ['code' => 'VISITA-PROPIA', 'assigned_to' => $technician->id]);
        $otherTicket = $this->ticket($otherSchool, $director, ['code' => 'VISITA-AJENA', 'assigned_to' => $otherTechnician->id]);
        $unassigned = $this->ticket($school, $director, ['code' => 'VISITA-SIN-ASIGNAR']);
        $makeVisit = fn ($ticket, $owner) => TechnicalVisit::create([
            'solicitud_id' => $ticket->id, 'tecnico_id' => $owner->id,
            'inicio' => now('UTC')->addDay(), 'fin' => now('UTC')->addDay()->addHour(),
        ]);
        $ownVisit = $makeVisit($ownTicket, $technician);
        $otherVisit = $makeVisit($otherTicket, $otherTechnician);
        $unassignedVisit = $makeVisit($unassigned, $otherTechnician);

        $this->actingAs($director)->get('/inicio')->assertOk()
            ->assertViewHas('upcomingVisits', fn ($visits) => $visits->modelKeys() === [$ownVisit->id, $unassignedVisit->id]);
        $this->actingAs($technician)->get('/inicio')->assertOk()
            ->assertViewHas('upcomingVisits', fn ($visits) => $visits->modelKeys() === [$ownVisit->id]);
        $this->actingAs($otherTechnician)->get('/inicio')->assertOk()
            ->assertViewHas('upcomingVisits', fn ($visits) => $visits->modelKeys() === [$otherVisit->id, $unassignedVisit->id]);
    }

    private function ticket(School $school, User $requester, array $overrides): Ticket
    {
        return Ticket::create(array_merge([
            'school_id' => $school->id, 'requester_id' => $requester->id,
            'category' => 'hardware', 'priority' => 'high', 'status' => 'pending',
            'subject' => 'Equipo necesita revisión', 'description' => 'Caso de verificación.',
        ], $overrides));
    }
}
