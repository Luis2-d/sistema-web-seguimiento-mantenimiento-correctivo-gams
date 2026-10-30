<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\School;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07 14:00:00', 'UTC'));
    }

    public function test_environment_is_isolated_and_schema_contains_calendar_tables(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertTrue(Schema::hasTable('visitas_tecnicas'));
        $this->assertTrue(Schema::hasTable('conexiones_calendario'));
    }

    public function test_real_login_and_logout_with_an_active_account(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'Password123!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertRedirect('/home')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get('/inicio')->assertOk();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/inicio')->assertRedirect('/login');
    }

    public function test_wrong_password_does_not_start_a_session(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_creates_and_updates_a_director_without_changing_a_blank_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $school = School::create(['name' => 'Unidad usuarios']);
        $payload = ['name' => 'Directora de prueba', 'email' => 'directora-auditoria@example.test',
            'role' => 'director', 'school_id' => $school->id, 'active' => true,
            'password' => 'Password123!', 'password_confirmation' => 'Password123!'];
        $this->actingAs($admin)->post('/usuarios', $payload)->assertRedirect('/usuarios')->assertSessionHasNoErrors();
        $director = User::where('email', $payload['email'])->firstOrFail();
        $this->assertTrue(Hash::check($payload['password'], $director->password));
        $this->assertSame($school->id, $director->school_id);
        $hash = $director->password;
        $payload['name'] = 'Directora actualizada';
        $payload['password'] = $payload['password_confirmation'] = '';
        $this->put(route('users.update', $director), $payload)->assertRedirect('/usuarios')->assertSessionHasNoErrors();
        $this->assertSame($hash, $director->fresh()->password);
        $this->assertSame($payload['name'], $director->fresh()->name);
    }

    public function test_user_creation_rejects_duplicate_email_missing_school_and_unconfirmed_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/usuarios', ['name' => 'Inválido', 'email' => $admin->email,
            'role' => 'director', 'active' => true, 'password' => 'short', 'password_confirmation' => 'distinta'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'school_id', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_equipment_creation_unique_code_update_and_director_permissions(): void
    {
        $school = School::create(['name' => 'Unidad inventario']);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['school_id' => $school->id, 'asset_code' => 'INV-001', 'type' => 'computer', 'status' => 'operational'];
        $this->actingAs($admin)->post('/equipos', $payload)->assertRedirect('/equipos')->assertSessionHasNoErrors();
        $equipment = Equipment::firstOrFail();
        $this->postJson('/equipos', $payload)->assertUnprocessable()->assertJsonValidationErrors('asset_code');
        $payload['notes'] = 'Inventario verificado';
        $this->put(route('equipment.update', $equipment), $payload)->assertRedirect('/equipos')->assertSessionHasNoErrors();
        $this->assertSame($payload['notes'], $equipment->fresh()->notes);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $this->actingAs($director)->postJson('/equipos', $payload)->assertForbidden();
        $this->putJson(route('equipment.update', $equipment), $payload)->assertForbidden();
        $this->get('/equipos')->assertOk()->assertSee('INV-001');
    }

    public static function invalidCoordinates(): array
    {
        return [
            [['latitude' => 91, 'longitude' => 0], 'latitude'],
            [['latitude' => -91, 'longitude' => 0], 'latitude'],
            [['latitude' => 0, 'longitude' => 181], 'longitude'],
            [['latitude' => 0, 'longitude' => -181], 'longitude'],
            [['latitude' => -17.4], 'longitude'],
            [['longitude' => -66.04], 'latitude'],
        ];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_school_coordinates_validate_pairs_and_bounds(array $coordinates, string $field): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/unidades-educativas', $coordinates + ['name' => 'Inválida', 'active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('unidades_educativas', 0);
    }

    public function test_coordinate_limits_and_zero_are_accepted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([[90, 180], [-90, -180], [0, 0]] as [$lat, $lon]) {
            $this->actingAs($admin)->post('/unidades-educativas', ['name' => 'Límite', 'active' => true,
                'latitude' => $lat, 'longitude' => $lon])->assertRedirect('/unidades-educativas')->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('unidades_educativas', 3);
    }

    public function test_report_filters_and_csv_use_the_same_authorized_records(): void
    {
        $school = School::create(['name' => 'Unidad de reportes']);
        $other = School::create(['name' => 'Unidad ajena']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $match = $this->ticket($school, $director, 'SI-COINCIDE');
        $this->ticket($other, $director, 'OTRA-UNIDAD');
        $old = $this->ticket($school, $director, 'OTRA-FECHA');
        $old->created_at = '2026-09-01 10:00:00';
        $old->save();
        $this->ticket($school, $director, 'OTRO-ESTADO')->update(['status' => 'closed']);
        $this->ticket($school, $director, 'OTRA-CATEGORIA')->update(['category' => 'software']);
        $filters = ['from' => '2026-10-01', 'to' => '2026-10-07', 'category' => 'hardware', 'status' => 'pending'];
        $this->actingAs($director)->get(route('reports.index', $filters))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->modelKeys() === [$match->id]);
        $response = $this->get(route('reports.export', $filters))->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('SI-COINCIDE', $csv);
        foreach (['OTRA-UNIDAD', 'OTRA-FECHA', 'OTRO-ESTADO', 'OTRA-CATEGORIA'] as $code) {
            $this->assertStringNotContainsString($code, $csv);
        }
    }

    public function test_technician_report_currently_has_general_scope_as_documented(): void
    {
        $school = School::create(['name' => 'Unidad']);
        $tech = User::factory()->create(['role' => 'technician']);
        $other = User::factory()->create(['role' => 'technician']);
        $ticket = $this->ticket($school, $other, 'REPORTE-GENERAL');
        $ticket->update(['assigned_to' => $other->id]);
        $this->actingAs($tech)->get('/reportes')->assertOk()->assertSee('REPORTE-GENERAL');
        $this->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_notification_reading_and_read_all_are_scoped_to_the_recipient(): void
    {
        $school = School::create(['name' => 'Unidad']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $other = User::factory()->create(['role' => 'admin']);
        $ticket = $this->ticket($school, $director, 'AVISO-PROPIO');
        $director->notify(new TicketEventNotification($ticket, 'status'));
        $other->notify(new TicketEventNotification($ticket, 'new'));
        $ownNotice = $director->notifications()->firstOrFail();
        $otherNotice = $other->notifications()->firstOrFail();
        $this->actingAs($director)->get('/notificaciones')->assertOk()->assertSee('AVISO-PROPIO');
        $this->get(route('notifications.read', $otherNotice->id))->assertNotFound();
        $this->get(route('notifications.read', $ownNotice->id))->assertRedirect(route('tickets.show', $ticket));
        $this->assertNotNull($ownNotice->fresh()->read_at);
        $director->notify(new TicketEventNotification($ticket, 'status'));
        $this->patch(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $director->unreadNotifications()->count());
        $this->assertNull($otherNotice->fresh()->read_at);
    }

    public function test_submission_uses_selected_school_and_notifies_only_active_staff_in_database(): void
    {
        $school = School::create(['name' => 'Propia']);
        $otherSchool = School::create(['name' => 'Ajena']);
        $director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $technician = User::factory()->create(['role' => 'technician']);
        $inactive = User::factory()->create(['role' => 'technician', 'active' => false]);
        $equipment = Equipment::create(['school_id' => $otherSchool->id, 'asset_code' => 'PROP-01',
            'type' => 'computer', 'status' => 'operational']);
        $this->actingAs($director)->post('/solicitudes', ['school_id' => $otherSchool->id,
            'equipment_id' => $equipment->id, 'category' => 'hardware', 'priority' => 'medium',
            'subject' => 'Falla nueva', 'description' => 'Descripción'])->assertRedirect()->assertSessionHasNoErrors();
        $ticket = Ticket::firstOrFail();
        $this->assertSame($otherSchool->id, $ticket->school_id);
        $this->assertSame('under_repair', $equipment->fresh()->status);
        $this->assertSame(1, $technician->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        $this->assertSame(['database'], (new TicketEventNotification($ticket, 'new'))->via($technician));
    }

    private function ticket(School $school, User $user, string $code): Ticket
    {
        return Ticket::create(['school_id' => $school->id, 'requester_id' => $user->id, 'code' => $code,
            'category' => 'hardware', 'priority' => 'medium', 'status' => 'pending',
            'subject' => 'Solicitud de prueba', 'description' => 'Escenario controlado.']);
    }
}
