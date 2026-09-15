<?php

namespace App\Models;

use App\Models\Concerns\HasSpanishDatabase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    use HasFactory, HasSpanishDatabase;

    protected $table = 'unidades_educativas';

    protected $fillable = [
        'name',
        'district',
        'address',
        'latitude',
        'longitude',
        'phone',
        'active',
    ];

    protected array $attributeMap = [
        'name' => 'nombre',
        'district' => 'distrito',
        'address' => 'direccion',
        'latitude' => 'latitud',
        'longitude' => 'longitud',
        'phone' => 'telefono',
        'active' => 'activo',
    ];

    protected $casts = [
        'latitud' => 'decimal:7',
        'longitud' => 'decimal:7',
        'activo' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'unidad_educativa_id');
    }

    public function equipment()
    {
        return $this->hasMany(Equipment::class, 'unidad_educativa_id');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'unidad_educativa_id');
    }
}
