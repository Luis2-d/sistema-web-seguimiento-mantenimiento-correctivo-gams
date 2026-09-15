<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unidades_educativas', function (Blueprint $table) {
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unidades_educativas', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud']);
        });
    }
};
