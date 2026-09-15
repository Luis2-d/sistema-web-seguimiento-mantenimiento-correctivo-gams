<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('unidades_educativas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('distrito')->nullable();
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('unidad_educativa_id')->references('id')->on('unidades_educativas')->nullOnDelete();
        });

        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidad_educativa_id')->constrained('unidades_educativas')->cascadeOnDelete();
            $table->string('codigo_patrimonial')->unique();
            $table->enum('tipo', ['computadora', 'impresora', 'monitor', 'router', 'red', 'otro']);
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('numero_serie')->nullable()->index();
            $table->string('ubicacion')->nullable();
            $table->enum('estado', ['operativo', 'en_reparacion', 'fuera_servicio'])->default('operativo');
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('solicitudes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('unidad_educativa_id')->constrained('unidades_educativas')->restrictOnDelete();
            $table->foreignId('equipo_id')->nullable()->constrained('equipos')->nullOnDelete();
            $table->foreignId('solicitante_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tecnico_asignado_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('categoria', ['hardware', 'software', 'conectividad']);
            $table->enum('prioridad', ['baja', 'media', 'alta', 'critica'])->default('media');
            $table->enum('estado', ['pendiente', 'diagnostico', 'espera_repuestos', 'en_progreso', 'solucionado', 'cerrado', 'cancelado'])->default('pendiente')->index();
            $table->string('asunto');
            $table->text('descripcion');
            $table->timestamp('resuelto_en')->nullable();
            $table->timestamps();
        });

        Schema::create('historial_estados_solicitud', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->cascadeOnDelete();
            $table->foreignId('modificado_por')->constrained('users')->restrictOnDelete();
            $table->string('estado_anterior')->nullable();
            $table->string('estado_nuevo');
            $table->text('comentario')->nullable();
            $table->timestamps();
        });

        Schema::create('intervenciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->cascadeOnDelete();
            $table->foreignId('tecnico_id')->constrained('users')->restrictOnDelete();
            $table->enum('tipo_servicio', ['hardware', 'software', 'conectividad']);
            $table->text('diagnostico');
            $table->text('solucion');
            $table->text('observaciones_conformidad')->nullable();
            $table->timestamp('realizado_en');
            $table->timestamps();
        });

        Schema::create('repuestos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo')->nullable()->unique();
            $table->unsignedInteger('stock')->default(0);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('intervencion_repuesto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencion_id')->constrained('intervenciones')->cascadeOnDelete();
            $table->foreignId('repuesto_id')->constrained('repuestos')->restrictOnDelete();
            $table->unsignedInteger('cantidad')->default(1);
            $table->string('observaciones')->nullable();
            $table->timestamps();
            $table->unique(['intervencion_id', 'repuesto_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('intervencion_repuesto');
        Schema::dropIfExists('repuestos');
        Schema::dropIfExists('intervenciones');
        Schema::dropIfExists('historial_estados_solicitud');
        Schema::dropIfExists('solicitudes');
        Schema::dropIfExists('equipos');
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['unidad_educativa_id']));
        Schema::dropIfExists('unidades_educativas');
    }
};
