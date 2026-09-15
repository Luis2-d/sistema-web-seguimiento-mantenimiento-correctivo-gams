<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Models\TechnicalVisit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CalendarException;
use App\Services\GoogleCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    public function index(Request $request, GoogleCalendarService $google)
    {
        $data = $request->validate(['mes' => 'nullable|date_format:Y-m']);
        $timezone = config('services.google_calendar.timezone');
        $month = CarbonImmutable::createFromFormat('!Y-m', $data['mes'] ?? now($timezone)->format('Y-m'), $timezone);
        $start = $month->startOfWeek();
        $end = $month->endOfMonth()->endOfWeek();
        // El panel lee las visitas de la base de datos local, no las descarga de Google.
        $visits = TechnicalVisit::visibleTo($request->user())->with(['ticket.school', 'technician'])
            ->where('inicio', '<=', $end->utc())->where('fin', '>=', $start->utc())->orderBy('inicio')->get();

        return view('calendar.index', compact('month', 'start', 'end', 'visits', 'timezone') + [
            'connection' => $request->user()->isAdmin() ? CalendarConnection::find(1) : null,
            'configured' => $google->configured(), 'redirectUri' => $google->redirectUri(),
        ]);
    }

    public function connect(Request $request, GoogleCalendarService $google)
    {
        if (! $google->configured()) {
            return back()->withErrors(['calendar' => 'Primero configura las credenciales de Google y el identificador del calendario.']);
        }
        // Este código temporal permite reconocer la respuesta de Google al volver.
        $state = Str::random(64);
        $request->session()->put('calendar_oauth', ['state' => $state, 'user' => $request->user()->id, 'expires' => time() + 600]);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendarService $google)
    {
        // Al volver de Google, verifica el código temporal, el usuario y el plazo de autorización.
        $saved = $request->session()->pull('calendar_oauth');
        if (! $saved || ! is_string($request->query('state')) || ! hash_equals($saved['state'], $request->query('state'))
            || $saved['user'] !== $request->user()->id || $saved['expires'] < time()) {
            return redirect()->route('calendar.index')->withErrors(['calendar' => 'La autorización venció o no es válida. Inicia la conexión nuevamente.']);
        }
        if ($request->has('error') || ! is_string($request->query('code')) || ! $google->configured()) {
            return redirect()->route('calendar.index')->withErrors(['calendar' => 'No se autorizó la conexión con Google Calendar.']);
        }
        try {
            $google->connect($request->query('code'));
        } catch (ConnectionException) {
            return redirect()->route('calendar.index')->withErrors(['calendar' => 'No se pudo contactar con Google. Revisa tu conexión e intenta de nuevo.']);
        } catch (CalendarException $e) {
            return redirect()->route('calendar.index')->withErrors(['calendar' => $e->getMessage()]);
        }

        return redirect()->route('calendar.index')->with('success', 'Google Calendar conectado. Ya puedes sincronizar las visitas.');
    }

    public function disconnect()
    {
        // Borra los tokens locales y conserva las visitas y los eventos que ya están en Google.
        CalendarConnection::whereKey(1)->update(['access_token' => null, 'refresh_token' => null, 'expira_en' => null]);

        return back()->with('success', 'Conexión desactivada en este sistema. Los eventos existentes se conservan en Google.');
    }

    public function store(Request $request, Ticket $ticket, GoogleCalendarService $google)
    {
        $this->authorizeTicket($request, $ticket);
        // Primero guarda la visita en el sistema y después intenta enviarla a Google.
        $visit = $this->saveVisit($request, $ticket);

        return $this->syncResponse($visit, $google, 'Visita programada.');
    }

    public function update(Request $request, TechnicalVisit $visit, GoogleCalendarService $google)
    {
        $this->authorizeVisit($request, $visit);
        abort_if($visit->estado === 'cancelada', 409, 'Programa una nueva visita; esta fue cancelada.');
        $visit = $this->saveVisit($request, $visit->ticket, $visit);

        return $this->syncResponse($visit, $google, 'Visita reprogramada.');
    }

    private function saveVisit(Request $request, Ticket $ticket, ?TechnicalVisit $visit = null): TechnicalVisit
    {
        if (in_array($ticket->status, ['resolved', 'closed', 'cancelled'])) {
            throw ValidationException::withMessages(['visita' => 'Solo puedes programar visitas para solicitudes abiertas.']);
        }
        $data = $request->validate([
            'tecnico_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'technician')->where('active', true)],
            'inicio' => 'required|date_format:Y-m-d\TH:i',
            'observaciones' => 'nullable|string|max:1000',
        ]);
        if ($request->user()->isTechnician() && (int) $data['tecnico_id'] !== $request->user()->id) {
            abort(403);
        }
        if ($ticket->assigned_to !== null && (int) $data['tecnico_id'] !== $ticket->assigned_to) {
            throw ValidationException::withMessages(['tecnico_id' => 'La visita debe corresponder al técnico responsable de la solicitud. Para elegir otro, cambia primero la asignación.']);
        }
        $timezone = config('services.google_calendar.timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['inicio'], $timezone)->utc();
        if ($start->isPast()) {
            throw ValidationException::withMessages(['inicio' => 'La visita debe comenzar en una fecha y hora futura.']);
        }

        return DB::transaction(function () use ($ticket, $visit, $data, $start) {
            // Serialize scheduling per technician so concurrent requests cannot double-book.
            User::whereKey($data['tecnico_id'])->lockForUpdate()->firstOrFail();
            if ($visit) {
                $visit = TechnicalVisit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
                abort_if($visit->estado === 'cancelada', 409);
            }
            // Google y el control de horarios usan un fin calculado: una hora para visitas nuevas.
            // Al reprogramar se conserva la duración que tenía la visita.
            $end = $visit
                ? $start->addSeconds((int) $visit->inicio->diffInSeconds($visit->fin))
                : $start->addHour();
            $overlap = TechnicalVisit::where('tecnico_id', $data['tecnico_id'])->where('estado', 'programada')
                ->where('inicio', '<', $end)->where('fin', '>', $start)
                ->when($visit, fn ($q) => $q->where('id', '!=', $visit->id))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['inicio' => 'El técnico ya tiene una visita en ese horario. Selecciona otro horario.']);
            }
            $visit ??= new TechnicalVisit(['solicitud_id' => $ticket->id]);
            $visit->fill([
                'tecnico_id' => $data['tecnico_id'], 'inicio' => $start, 'fin' => $end,
                'observaciones' => $data['observaciones'] ?? null,
                'sincronizado_en' => null, 'error_sincronizacion' => null,
            ])->save();

            return $visit;
        });
    }

    public function cancel(Request $request, TechnicalVisit $visit, GoogleCalendarService $google)
    {
        $this->authorizeVisit($request, $visit);
        $visit->update(['estado' => 'cancelada', 'sincronizado_en' => null]);

        return $this->syncResponse($visit, $google, 'Visita cancelada.');
    }

    public function sync(Request $request, TechnicalVisit $visit, GoogleCalendarService $google)
    {
        $this->authorizeVisit($request, $visit);

        return $this->syncResponse($visit, $google, 'Visita guardada.');
    }

    private function syncResponse(TechnicalVisit $visit, GoogleCalendarService $google, string $message)
    {
        try {
            $google->sync($visit);

            return back()->with('success', $message.' Sincronizada con Google Calendar.');
        } catch (ConnectionException) {
            $error = 'No hay conexión con Google. La visita está guardada; puedes reintentar la sincronización.';
        } catch (CalendarException $e) {
            $error = $e->getMessage();
        }
        // Si Google falla, conserva la visita y deja el error para poder reintentar.
        $visit->update(['sincronizado_en' => null, 'error_sincronizacion' => $error]);

        return back()->with('success', $message)->with('warning', $error);
    }

    private function authorizeTicket(Request $request, Ticket $ticket): void
    {
        abort_unless($request->user()->isAdmin() || ($request->user()->isTechnician()
            && ($ticket->assigned_to === null || $ticket->assigned_to === $request->user()->id)), 403);
    }

    private function authorizeVisit(Request $request, TechnicalVisit $visit): void
    {
        $this->authorizeTicket($request, $visit->ticket);
        abort_unless($request->user()->isAdmin() || $visit->tecnico_id === $request->user()->id, 403);
    }
}
