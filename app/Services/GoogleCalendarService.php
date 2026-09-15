<?php

namespace App\Services;

use App\Models\CalendarConnection;
use App\Models\TechnicalVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GoogleCalendarService
{
    // Permiso que permite crear, modificar y eliminar eventos en Google.
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    public function configured(): bool
    {
        return filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.calendar_id'));
    }

    public function redirectUri(): string
    {
        return config('services.google_calendar.redirect_uri') ?: route('calendar.callback');
    }

    public function authorizationUrl(string $state): string
    {
        // Prepara el enlace donde Google pide permiso para usar el calendario.
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code', 'scope' => self::SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    public function connect(string $code): void
    {
        // Cambia el código recibido de Google por las claves de acceso (tokens).
        $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code', 'code' => $code,
        ]);
        if (! $response->successful() || ! $response->json('access_token') || ! $response->json('refresh_token')
            || ! in_array(self::SCOPE, explode(' ', $response->json('scope', '')), true)) {
            throw new CalendarException('No se pudo autorizar el calendario. Conecta de nuevo y permite administrar los eventos.');
        }
        $calendarId = config('services.google_calendar.calendar_id');
        // Comprueba que la cuenta autorizada pueda acceder al calendario indicado.
        $check = Http::withToken($response->json('access_token'))->connectTimeout(5)->timeout(15)
            ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events', ['maxResults' => 1]);
        if (! $check->successful()) {
            throw new CalendarException('La cuenta no puede acceder al calendario indicado. Revisa su identificador y los permisos.');
        }
        // Guarda los tokens y su vencimiento para las siguientes consultas a Google.
        CalendarConnection::updateOrCreate(['id' => 1], [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expira_en' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'calendario_id' => $calendarId,
        ]);
    }

    private function accessToken(CalendarConnection $connection): string
    {
        // Usa el token vigente; si está por vencer, pide uno nuevo con el refresh_token.
        if ($connection->access_token && $connection->expira_en?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }
        $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new CalendarException('Google no renovó el acceso. El administrador debe volver a conectar la cuenta.');
        }
        $connection->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?: $connection->refresh_token,
            'expira_en' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $connection->access_token;
    }

    public function sync(TechnicalVisit $visit): void
    {
        // Guarda el calendario antes de llamar a Google para usar el mismo al reintentar.
        DB::transaction(function () use ($visit) {
            $connection = CalendarConnection::whereKey(1)->lockForUpdate()->first();
            $current = TechnicalVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            if ($this->configured() && $connection?->refresh_token && ! $current->google_calendar_id) {
                $current->update(['google_calendar_id' => $connection->calendario_id]);
            }
        });
        // Evita que dos sincronizaciones o renovaciones de acceso se crucen al mismo tiempo.
        DB::transaction(function () use ($visit) {
            $connection = CalendarConnection::whereKey(1)->lockForUpdate()->first();
            $current = TechnicalVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
            if (! $connection?->refresh_token || ! $this->configured()) {
                throw new CalendarException('La visita está guardada. Falta conectar Google Calendar desde el calendario.');
            }
            if ($current->google_calendar_id && $current->google_calendar_id !== $connection->calendario_id) {
                throw new CalendarException('Esta visita pertenece a otro calendario. Reconecta el calendario original para sincronizarla.');
            }
            // Envía el token para autorizar las peticiones y limita el tiempo de espera.
            $client = Http::withToken($this->accessToken($connection))->connectTimeout(5)->timeout(15);
            $base = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($connection->calendario_id).'/events';
            $url = $base.'/'.$current->google_event_id;
            if ($current->estado === 'cancelada') {
                // DELETE elimina en Google el evento de la visita cancelada.
                $response = $client->delete($url.'?sendUpdates=none');
                $ok = $response->successful() || in_array($response->status(), [404, 410]);
            } else {
                $current->load(['ticket.school', 'technician']);
                $timezone = config('services.google_calendar.timezone');
                // Reúne el título, lugar, técnico, horario y recordatorio que recibirá Google.
                $payload = [
                    'summary' => $current->ticket->code.' · Visita técnica · '.$current->ticket->school->name,
                    'location' => $current->ticket->school->address ?: $current->ticket->school->name,
                    'description' => 'Técnico: '.$current->technician->name."\nMotivo: ".$current->ticket->subject
                        ."\n".($current->observaciones ?? '')."\n".route('tickets.show', $current->ticket),
                    'start' => ['dateTime' => $current->inicio->copy()->timezone($timezone)->toRfc3339String(), 'timeZone' => $timezone],
                    'end' => ['dateTime' => $current->fin->copy()->timezone($timezone)->toRfc3339String(), 'timeZone' => $timezone],
                    'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 30]]],
                ];
                // Conserva el mismo identificador para evitar eventos duplicados al reintentar.
                // PUT actualiza el evento con los datos guardados en el sistema.
                $response = $client->put($url.'?sendUpdates=none', $payload);
                // Si Google no encuentra el evento (404), POST lo crea.
                if ($response->status() === 404) {
                    $response = $client->post($base.'?sendUpdates=none', ['id' => $current->google_event_id] + $payload);
                    // Si el evento ya existe (409), vuelve a intentar actualizarlo.
                    if ($response->status() === 409) {
                        $response = $client->put($url.'?sendUpdates=none', $payload);
                    }
                }
                $ok = $response->successful();
            }
            // Convierte los errores de Google en mensajes que pueda mostrar el panel.
            if (! $ok) {
                throw new CalendarException(match ($response->status()) {
                    401, 403 => 'Google rechazó el acceso. Revisa los permisos o vuelve a conectar la cuenta.',
                    410 => 'El evento fue eliminado en Google. Cancela esta visita y programa una nueva.',
                    429 => 'Google recibió demasiadas solicitudes. Intenta sincronizar más tarde.',
                    default => 'No se pudo sincronizar con Google. La visita sigue guardada; puedes reintentar.',
                });
            }
            // Marca la visita como sincronizada solo cuando Google confirma la operación.
            $current->update(['google_calendar_id' => $connection->calendario_id, 'sincronizado_en' => now(), 'error_sincronizacion' => null]);
        });
    }
}
