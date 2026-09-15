<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conexiones_calendario', function (Blueprint $table) {
            $table->id();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expira_en')->nullable();
            $table->string('calendario_id')->nullable();
            $table->timestamps();
        });
        Schema::create('visitas_tecnicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes');
            $table->foreignId('tecnico_id')->constrained('users');
            $table->dateTime('inicio');
            $table->dateTime('fin');
            $table->string('estado')->default('programada');
            $table->text('observaciones')->nullable();
            $table->string('google_event_id')->unique();
            $table->string('google_calendar_id')->nullable();
            $table->timestamp('sincronizado_en')->nullable();
            $table->string('error_sincronizacion')->nullable();
            $table->timestamps();
            $table->index(['tecnico_id', 'inicio', 'fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas_tecnicas');
        Schema::dropIfExists('conexiones_calendario');
    }
};
