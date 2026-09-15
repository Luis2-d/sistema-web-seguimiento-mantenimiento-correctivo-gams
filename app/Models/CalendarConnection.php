<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarConnection extends Model
{
    protected $table = 'conexiones_calendario';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        // Guarda los tokens de Google cifrados en la base de datos.
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expira_en' => 'datetime'];
    }
}
