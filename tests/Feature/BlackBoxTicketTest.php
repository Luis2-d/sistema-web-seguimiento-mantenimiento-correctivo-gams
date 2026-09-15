<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caja negra: entradas HTTP y resultados observables por el cliente.
 * Los modelos solo preparan datos aislados; no se inspecciona la persistencia.
 */
class BlackBoxTicketTest extends TestCase
{
    use RefreshDatabase;

    // CN-01: un visitante necesita autenticarse.
    public function test_guest_is_redirected_to_login_when_listing_tickets(): void
    {
        $this->get('/solicitudes')->assertRedirect('/login');
    }

    // CN-02: el director no tiene acceso al mantenimiento de usuarios.
    public function test_director_receives_forbidden_when_opening_users(): void
    {
        $director = User::factory()->create(['role' => 'director']);

        $this->actingAs($director)->get('/usuarios')->assertForbidden();
    }

    // CN-03: clase de equivalencia invalida, asunto vacio.
    public function test_empty_subject_returns_validation_error(): void
    {
        [$director, $data] = $this->submission();
        $data['subject'] = '';

        $this->actingAs($director)->from('/solicitudes/crear')
            ->post('/solicitudes', $data)
            ->assertRedirect('/solicitudes/crear')
            ->assertSessionHasErrors(['subject']);
    }

    // CN-04: valor limite superior permitido, 255 caracteres ASCII.
    public function test_subject_with_255_characters_is_accepted(): void
    {
        [$director, $data] = $this->submission();
        $data['subject'] = str_repeat('A', 255);

        $this->actingAs($director)->from('/solicitudes/crear')
            ->post('/solicitudes', $data)
            ->assertRedirectContains('/solicitudes/')
            ->assertSessionHasNoErrors();
    }

    // CN-05: primer valor fuera del limite, 256 caracteres ASCII.
    public function test_subject_with_256_characters_returns_validation_error(): void
    {
        [$director, $data] = $this->submission();
        $data['subject'] = str_repeat('A', 256);

        $this->actingAs($director)->from('/solicitudes/crear')
            ->post('/solicitudes', $data)
            ->assertRedirect('/solicitudes/crear')
            ->assertSessionHasErrors(['subject']);
    }

    // CN-06: categoria fuera del conjunto permitido.
    public function test_unknown_category_returns_validation_error(): void
    {
        [$director, $data] = $this->submission();
        $data['category'] = 'electricidad';

        $this->actingAs($director)->from('/solicitudes/crear')
            ->post('/solicitudes', $data)
            ->assertRedirect('/solicitudes/crear')
            ->assertSessionHasErrors(['category']);
    }

    // CN-07: un director no puede consultar solicitudes de otra unidad.
    public function test_director_receives_forbidden_for_another_school_ticket(): void
    {
        [$owner, $data] = $this->submission();
        $ticket = $this->ticket($owner, $data);
        $otherSchool = School::create(['name' => 'Unidad ajena de prueba']);
        $otherDirector = User::factory()->create([
            'role' => 'director',
            'school_id' => $otherSchool->id,
        ]);

        $this->actingAs($otherDirector)
            ->get('/solicitudes/'.$ticket->id)->assertForbidden();
    }

    // CN-08: misma restriccion de acceso en consulta y cambio de estado.
    public function test_technician_cannot_change_status_of_another_technician_ticket(): void
    {
        [$director, $data] = $this->submission();
        $assignedTechnician = User::factory()->create(['role' => 'technician']);
        $otherTechnician = User::factory()->create(['role' => 'technician']);
        $ticket = $this->ticket($director, $data, $assignedTechnician->id);

        $this->actingAs($otherTechnician)
            ->get('/solicitudes/'.$ticket->id)->assertForbidden();

        $this->actingAs($otherTechnician)
            ->patch('/solicitudes/'.$ticket->id.'/estado', ['status' => 'resolved'])
            ->assertForbidden();
    }

    // CN-09: un tecnico no puede apropiarse de una solicitud ajena.
    public function test_technician_cannot_reassign_another_technician_ticket(): void
    {
        [$director, $data] = $this->submission();
        $assignedTechnician = User::factory()->create(['role' => 'technician']);
        $otherTechnician = User::factory()->create(['role' => 'technician']);
        $ticket = $this->ticket($director, $data, $assignedTechnician->id);

        $this->actingAs($otherTechnician)
            ->patch('/solicitudes/'.$ticket->id.'/asignar', [
                'assigned_to' => $otherTechnician->id,
            ])->assertForbidden();
    }

    // CN-10: el tecnico propietario conserva permiso para cambiar el estado.
    public function test_assigned_technician_can_change_ticket_status(): void
    {
        [$director, $data] = $this->submission();
        $technician = User::factory()->create(['role' => 'technician']);
        $ticket = $this->ticket($director, $data, $technician->id);

        $this->actingAs($technician)->from('/solicitudes/'.$ticket->id)
            ->patch('/solicitudes/'.$ticket->id.'/estado', ['status' => 'resolved'])
            ->assertRedirect('/solicitudes/'.$ticket->id)
            ->assertSessionHasNoErrors();
    }

    // CN-11: el administrador conserva permiso para reasignar solicitudes.
    public function test_admin_can_reassign_ticket_to_another_technician(): void
    {
        [$director, $data] = $this->submission();
        $admin = User::factory()->create(['role' => 'admin']);
        $originalTechnician = User::factory()->create(['role' => 'technician']);
        $newTechnician = User::factory()->create(['role' => 'technician']);
        $ticket = $this->ticket($director, $data, $originalTechnician->id);

        $this->actingAs($admin)->from('/solicitudes/'.$ticket->id)
            ->patch('/solicitudes/'.$ticket->id.'/asignar', [
                'assigned_to' => $newTechnician->id,
            ])
            ->assertRedirect('/solicitudes/'.$ticket->id)
            ->assertSessionHasNoErrors();
    }

    private function submission(): array
    {
        $school = School::create(['name' => 'Unidad de prueba caja negra']);
        $director = User::factory()->create([
            'role' => 'director',
            'school_id' => $school->id,
        ]);

        return [$director, [
            'school_id' => $school->id,
            'category' => 'hardware',
            'priority' => 'medium',
            'subject' => 'Equipo sin encender',
            'description' => 'El equipo no responde al boton de encendido.',
        ]];
    }

    private function ticket(User $director, array $data, ?int $assignedTo = null): Ticket
    {
        return Ticket::create(array_merge($data, [
            'code' => 'SOL-CN-001',
            'requester_id' => $director->id,
            'assigned_to' => $assignedTo,
            'status' => 'pending',
        ]));
    }
}
