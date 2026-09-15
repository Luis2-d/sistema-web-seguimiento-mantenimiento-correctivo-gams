<?php

namespace Tests\Feature;

use App\Models\CalendarConnection;
use App\Models\School;
use App\Models\TechnicalVisit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private User $director;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 11)->startOfDay());
        config()->set('services.google_calendar', [
            'client_id' => null, 'client_secret' => null, 'calendar_id' => null,
            'redirect_uri' => null, 'timezone' => 'America/La_Paz',
        ]);
        $school = School::create(['name' => 'Unidad Calendario', 'address' => 'Sacaba']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'technician']);
        $this->director = User::factory()->create(['role' => 'director', 'school_id' => $school->id]);
        $this->ticket = Ticket::create([
            'code' => 'SOL-CAL-001', 'school_id' => $school->id, 'requester_id' => $this->director->id,
            'assigned_to' => $this->technician->id, 'category' => 'hardware', 'priority' => 'medium',
            'status' => 'pending', 'subject' => 'Revisión de computadora', 'description' => 'No enciende.',
        ]);
    }

    private function data(array $overrides = []): array
    {
        return $overrides + ['tecnico_id' => $this->technician->id, 'inicio' => '2026-09-15T09:00', 'observaciones' => 'Llevar herramientas'];
    }

    private function visit(): TechnicalVisit
    {
        return TechnicalVisit::create([
            'solicitud_id' => $this->ticket->id, 'tecnico_id' => $this->technician->id,
            'inicio' => '2026-09-15 13:00:00', 'fin' => '2026-09-15 14:00:00',
        ]);
    }

    private function connected(bool $expired = false): CalendarConnection
    {
        config()->set('services.google_calendar.client_id', 'test-client');
        config()->set('services.google_calendar.client_secret', 'test-secret');
        config()->set('services.google_calendar.calendar_id', 'test@group.calendar.google.com');

        return CalendarConnection::create([
            'id' => 1, 'access_token' => 'access-test', 'refresh_token' => 'refresh-test',
            'expira_en' => $expired ? now()->subMinute() : now()->addHour(),
            'calendario_id' => 'test@group.calendar.google.com',
        ]);
    }

    public function test_schedule_without_end_is_saved_without_google_and_uses_bolivia_time(): void
    {
        $this->actingAs($this->admin)->from(route('tickets.show', $this->ticket))
            ->post(route('visits.store', $this->ticket), $this->data())->assertRedirect()->assertSessionHas('warning');
        $this->assertDatabaseHas('visitas_tecnicas', ['inicio' => '2026-09-15 13:00:00', 'fin' => '2026-09-15 14:00:00', 'estado' => 'programada']);
        Http::assertNothingSent();
        $this->get(route('tickets.show', $this->ticket))->assertOk()->assertSee('15/09/2026 09:00')->assertSee('Programar visita')
            ->assertSee('name="inicio"', false)->assertDontSee('name="fin"', false)->assertDontSee('15/09/2026 10:00');
        $this->get(route('calendar.index'))->assertOk()->assertSee('Unidad Calendario')->assertSee('09:00');
    }

    public function test_invalid_past_and_overlapping_times_are_rejected_but_adjacent_visit_is_allowed(): void
    {
        $this->visit();
        $this->actingAs($this->admin);
        foreach ([
            ['inicio' => 'invalid-date'],
            ['inicio' => ''],
            ['inicio' => '2026-09-10T09:00'],
            ['inicio' => '2026-09-15T09:30'],
            ['tecnico_id' => $this->director->id],
        ] as $bad) {
            $this->post(route('visits.store', $this->ticket), $this->data($bad))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('visitas_tecnicas', 1);
        $this->post(route('visits.store', $this->ticket), $this->data(['inicio' => '2026-09-15T10:00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('visitas_tecnicas', 2);
    }

    public function test_schedule_without_end_syncs_a_one_hour_event_with_google(): void
    {
        $this->connected();
        Http::fake(['www.googleapis.com/*' => Http::sequence()->push([], 404)->push([])]);

        $this->actingAs($this->admin)->post(route('visits.store', $this->ticket), $this->data())
            ->assertSessionHasNoErrors()->assertSessionHas('success')->assertSessionMissing('warning');

        $visit = TechnicalVisit::firstOrFail();
        $this->assertNotNull($visit->sincronizado_en);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/events?')
            && $r['id'] === $visit->google_event_id
            && $r['start']['dateTime'] === '2026-09-15T09:00:00-04:00'
            && $r['end']['dateTime'] === '2026-09-15T10:00:00-04:00');
        Http::assertSentCount(2);
    }

    public function test_rescheduling_without_end_preserves_duration_for_conflicts_and_google(): void
    {
        $this->connected();
        $visit = $this->visit();
        $visit->update(['fin' => '2026-09-15 14:30:00']);
        TechnicalVisit::create([
            'solicitud_id' => $this->ticket->id, 'tecnico_id' => $this->technician->id,
            'inicio' => '2026-09-16 15:00:00', 'fin' => '2026-09-16 16:00:00',
        ]);
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => $visit->google_event_id])]);

        $this->actingAs($this->admin)->put(route('visits.update', $visit), $this->data(['inicio' => '2026-09-16T10:00']))
            ->assertSessionHasErrors('inicio');
        $this->assertSame('2026-09-15 13:00:00', $visit->fresh()->inicio->format('Y-m-d H:i:s'));
        Http::assertNothingSent();

        $this->put(route('visits.update', $visit), $this->data(['inicio' => '2026-09-16T09:30']))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('visitas_tecnicas', [
            'id' => $visit->id, 'inicio' => '2026-09-16 13:30:00', 'fin' => '2026-09-16 15:00:00',
        ]);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), $visit->google_event_id)
            && $r['start']['dateTime'] === '2026-09-16T09:30:00-04:00'
            && $r['end']['dateTime'] === '2026-09-16T11:00:00-04:00');
        Http::assertSentCount(1);
    }

    public function test_submitted_end_does_not_change_the_automatic_visit_duration(): void
    {
        $this->actingAs($this->admin)->post(route('visits.store', $this->ticket), $this->data(['fin' => 'invalid-date']))
            ->assertSessionHasNoErrors();
        $visit = TechnicalVisit::firstOrFail();
        $this->assertSame('2026-09-15 14:00:00', $visit->fin->format('Y-m-d H:i:s'));

        $this->put(route('visits.update', $visit), $this->data(['inicio' => '2026-09-16T09:00', 'fin' => '2026-09-16T18:00']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('visitas_tecnicas', [
            'id' => $visit->id, 'inicio' => '2026-09-16 13:00:00', 'fin' => '2026-09-16 14:00:00',
        ]);
        Http::assertNothingSent();
    }

    public function test_director_cannot_modify_visits_or_connect_google(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->director);
        $this->post(route('visits.store', $this->ticket), $this->data())->assertForbidden();
        $this->put(route('visits.update', $visit), $this->data())->assertForbidden();
        $this->delete(route('visits.cancel', $visit))->assertForbidden();
        $this->post(route('visits.sync', $visit))->assertForbidden();
        $this->post(route('calendar.connect'))->assertForbidden();
        $this->get(route('calendar.callback'))->assertForbidden();
        $this->get(route('calendar.index'))->assertOk()->assertSee('SOL-CAL-001')->assertDontSee('Configuración de la conexión');
    }

    public function test_other_directors_and_technicians_do_not_see_or_edit_visits(): void
    {
        $visit = $this->visit();
        $otherSchool = School::create(['name' => 'Otra unidad']);
        $otherDirector = User::factory()->create(['role' => 'director', 'school_id' => $otherSchool->id]);
        $otherTechnician = User::factory()->create(['role' => 'technician']);
        foreach ([$otherDirector, $otherTechnician] as $user) {
            $this->actingAs($user)->get(route('calendar.index'))->assertOk()->assertDontSee('SOL-CAL-001');
        }
        $this->actingAs($otherTechnician)->put(route('visits.update', $visit), $this->data())->assertForbidden();
        $this->post(route('visits.store', $this->ticket), $this->data(['tecnico_id' => $otherTechnician->id]))->assertForbidden();
        $this->actingAs($this->technician)->post(route('visits.store', $this->ticket), $this->data(['tecnico_id' => $otherTechnician->id]))->assertForbidden();
        $this->actingAs($this->admin)->post(route('visits.store', $this->ticket), $this->data(['tecnico_id' => $otherTechnician->id]))->assertSessionHasErrors('tecnico_id');
    }

    public function test_guest_and_invalid_month_are_handled(): void
    {
        $this->get(route('calendar.index'))->assertRedirect('/login');
        $this->actingAs($this->admin)->get(route('calendar.index', ['mes' => 'bad']))->assertSessionHasErrors('mes');
        $this->get(route('calendar.index', ['mes' => '2026-12']))->assertOk()->assertSee('diciembre 2026');
    }

    public function test_oauth_uses_state_offline_access_and_admin_only(): void
    {
        $this->connected();
        CalendarConnection::query()->delete();
        $response = $this->actingAs($this->admin)->post(route('calendar.connect'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame(GoogleCalendarService::SCOPE, $query['scope']);
        $this->assertSame(session('calendar_oauth.state'), $query['state']);
        $this->get(route('calendar.callback', ['code' => 'test', 'state' => 'wrong']))->assertSessionHasErrors('calendar');
        Http::assertNothingSent();
        $this->assertDatabaseCount('conexiones_calendario', 0);
    }

    public function test_successful_callback_stores_encrypted_tokens_and_cannot_be_replayed(): void
    {
        $this->connected();
        CalendarConnection::query()->delete();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'private-access', 'refresh_token' => 'private-refresh', 'expires_in' => 3600, 'scope' => GoogleCalendarService::SCOPE]),
            'www.googleapis.com/*' => Http::response(['items' => []]),
        ]);
        $this->actingAs($this->admin)->post(route('calendar.connect'));
        $url = route('calendar.callback', ['code' => 'code-test', 'state' => session('calendar_oauth.state')]);
        $this->get($url)->assertRedirect(route('calendar.index'))->assertSessionHasNoErrors();
        $this->assertSame('private-refresh', CalendarConnection::find(1)->refresh_token);
        $this->assertNotSame('private-refresh', DB::table('conexiones_calendario')->value('refresh_token'));
        $this->assertArrayNotHasKey('access_token', CalendarConnection::find(1)->toArray());
        $this->get($url)->assertSessionHasErrors('calendar');
        Http::assertSentCount(2);
    }

    public function test_expired_oauth_state_and_refused_consent_do_not_send_requests(): void
    {
        $this->actingAs($this->admin)->withSession(['calendar_oauth' => ['state' => 'test', 'user' => $this->admin->id, 'expires' => time() - 1]])
            ->get(route('calendar.callback', ['state' => 'test', 'code' => 'test']))->assertSessionHasErrors('calendar');
        $this->withSession(['calendar_oauth' => ['state' => 'test', 'user' => $this->admin->id, 'expires' => time() + 60]])
            ->get(route('calendar.callback', ['state' => 'test', 'error' => 'access_denied']))->assertSessionHasErrors('calendar');
        Http::assertNothingSent();
    }

    public function test_google_create_refresh_retry_and_reprogram_use_same_event_id(): void
    {
        $this->connected(true);
        $visit = $this->visit();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed-token', 'expires_in' => 3600]),
            'www.googleapis.com/*' => Http::sequence()->push([], 404)->push(['id' => $visit->google_event_id])->push(['id' => $visit->google_event_id]),
        ]);
        $this->actingAs($this->admin)->post(route('visits.sync', $visit))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertNotNull($visit->fresh()->sincronizado_en);
        $this->put(route('visits.update', $visit), $this->data(['inicio' => '2026-09-16T10:00']))->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/events?')
            && $r['id'] === $visit->google_event_id && $r['start']['dateTime'] === '2026-09-15T09:00:00-04:00'
            && $r['end']['dateTime'] === '2026-09-15T10:00:00-04:00' && ! isset($r['attendees']));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), $visit->google_event_id)
            && $r['start']['dateTime'] === '2026-09-16T10:00:00-04:00'
            && $r['end']['dateTime'] === '2026-09-16T11:00:00-04:00');
        $this->assertSame('renewed-token', CalendarConnection::find(1)->access_token);
        $this->assertDatabaseCount('visitas_tecnicas', 1);
    }

    public function test_event_conflict_retries_update_instead_of_creating_another_id(): void
    {
        $this->connected();
        $visit = $this->visit();
        Http::fake(['www.googleapis.com/*' => Http::sequence()->push([], 404)->push([], 409)->push(['id' => $visit->google_event_id])]);
        $this->actingAs($this->admin)->post(route('visits.sync', $visit))->assertSessionHasNoErrors();
        Http::assertSentCount(3);
        $this->assertNotNull($visit->fresh()->sincronizado_en);
    }

    public function test_google_failure_keeps_local_changes_and_safe_error_without_secrets(): void
    {
        $this->connected();
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => 'private-access secret'], 403)]);
        $this->actingAs($this->admin)->post(route('visits.store', $this->ticket), $this->data())->assertSessionHas('warning');
        $visit = TechnicalVisit::firstOrFail();
        $this->assertNull($visit->sincronizado_en);
        $this->assertStringNotContainsString('private-access', $visit->error_sincronizacion);
        $this->assertSame('2026-09-15 13:00:00', $visit->inicio->format('Y-m-d H:i:s'));
    }

    public function test_network_failure_keeps_local_visit(): void
    {
        $this->connected();
        Http::fake(['www.googleapis.com/*' => Http::failedConnection()]);
        $this->actingAs($this->admin)->post(route('visits.store', $this->ticket), $this->data())->assertSessionHas('warning');
        $this->assertDatabaseCount('visitas_tecnicas', 1);
        $this->assertNull(TechnicalVisit::first()->sincronizado_en);
        $this->assertSame('test@group.calendar.google.com', TechnicalVisit::first()->google_calendar_id);
    }

    public function test_cancel_is_idempotent_and_does_not_allow_rescheduling_cancelled_visit(): void
    {
        $this->connected();
        $visit = $this->visit();
        Http::fake(['www.googleapis.com/*' => Http::response([], 410)]);
        $this->actingAs($this->admin)->delete(route('visits.cancel', $visit))->assertSessionHasNoErrors();
        $this->delete(route('visits.cancel', $visit))->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $visit->fresh()->estado);
        $this->assertNotNull($visit->fresh()->sincronizado_en);
        $this->put(route('visits.update', $visit), $this->data())->assertStatus(409);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), $visit->google_event_id));
    }

    public function test_closed_ticket_cannot_be_scheduled_and_disconnect_keeps_visits(): void
    {
        $connection = $this->connected();
        $this->visit();
        $this->ticket->update(['status' => 'closed']);
        $this->actingAs($this->admin)->post(route('visits.store', $this->ticket), $this->data())->assertSessionHasErrors('visita');
        $this->delete(route('calendar.disconnect'))->assertSessionHas('success');
        $this->assertNull($connection->fresh()->refresh_token);
        $this->assertDatabaseCount('visitas_tecnicas', 1);
        Http::assertNothingSent();
    }

    public function test_changing_calendar_does_not_copy_existing_events_to_another_calendar(): void
    {
        $this->connected();
        $visit = $this->visit();
        $visit->update(['google_calendar_id' => 'old-calendar']);
        $this->actingAs($this->admin)->post(route('visits.sync', $visit))->assertSessionHas('warning');
        Http::assertNothingSent();
        $this->assertNull($visit->fresh()->sincronizado_en);
    }

    public function test_failed_refresh_keeps_visit_pending_and_missing_settings_do_not_start_oauth(): void
    {
        $this->actingAs($this->admin)->post(route('calendar.connect'))->assertSessionHasErrors('calendar');
        Http::assertNothingSent();
        $this->connected(true);
        $visit = $this->visit();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->post(route('visits.sync', $visit))->assertSessionHas('warning');
        $this->assertNull($visit->fresh()->sincronizado_en);
        $this->assertStringContainsString('volver a conectar', $visit->fresh()->error_sincronizacion);
        Http::assertSentCount(1);
    }
}
