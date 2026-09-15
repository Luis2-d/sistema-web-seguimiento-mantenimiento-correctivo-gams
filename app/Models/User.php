<?php

namespace App\Models;

use App\Models\Concerns\HasSpanishDatabase;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasSpanishDatabase;

    protected array $attributeMap = ['school_id' => 'unidad_educativa_id'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'school_id',
        'phone',
        'role',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function school() { return $this->belongsTo(School::class, 'unidad_educativa_id'); }
    public function requestedTickets() { return $this->hasMany(Ticket::class, 'solicitante_id'); }
    public function assignedTickets() { return $this->hasMany(Ticket::class, 'tecnico_asignado_id'); }
    public function isAdmin(): bool { return $this->role === 'admin'; }
    public function isTechnician(): bool { return $this->role === 'technician'; }
    public function isDirector(): bool { return $this->role === 'director'; }
    public function roleLabel(): string { return match($this->role) { 'admin' => 'Administrador', 'technician' => 'Técnico de soporte', 'director' => 'Director de unidad educativa', default => 'Usuario' }; }
    public function roleDescription(): string { return match($this->role) { 'admin' => 'Supervisa usuarios, equipos, solicitudes y reportes', 'technician' => 'Diagnostica, repara y finaliza solicitudes', 'director' => 'Registra fallas y verifica las soluciones', default => '' }; }
    public function adminlte_desc(): string { return $this->roleLabel(); }
}
