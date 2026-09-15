<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TechnicalVisit extends Model
{
    protected $table = 'visitas_tecnicas';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime', 'sincronizado_en' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $visit) {
            // Crea una sola vez el identificador que se usará para este evento en Google.
            $visit->google_event_id ??= str_replace('-', '', (string) Str::uuid());
        });
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'solicitud_id');
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'tecnico_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isDirector()) {
            $query->whereHas('ticket', fn ($q) => $q->where('unidad_educativa_id', $user->school_id));
        } elseif ($user->isTechnician()) {
            $query->where('tecnico_id', $user->id)->whereHas('ticket', fn ($q) => $q
                ->where(fn ($q) => $q->where('tecnico_asignado_id', $user->id)->orWhereNull('tecnico_asignado_id')));
        } elseif (! $user->isAdmin()) {
            $query->whereRaw('1 = 0');
        }
    }
}
