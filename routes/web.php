<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\{DashboardController,EquipmentController,InterventionController,NotificationController,ReportController,SchoolController,SchoolMapController,SparePartController,TicketController,UserController};

Route::redirect('/', '/login')->name('welcome');

Auth::routes(['register' => false]);

Route::middleware('auth')->group(function () {
    Route::get('/calendario', [CalendarController::class, 'index'])->name('calendar.index');
    // Solo el administrador puede conectar o desconectar la cuenta de Google Calendar.
    Route::middleware('role:admin')->group(function () {
        Route::post('/calendario/google/conectar', [CalendarController::class, 'connect'])->name('calendar.connect');
        // Google vuelve a esta dirección después de que el usuario acepta los permisos.
        Route::get('/calendario/google/callback', [CalendarController::class, 'callback'])->name('calendar.callback');
        Route::delete('/calendario/google/desconectar', [CalendarController::class, 'disconnect'])->name('calendar.disconnect');
    });
    // Recibe las acciones de visitas que luego se sincronizan con Google.
    Route::middleware('role:admin,technician')->group(function () {
        Route::post('/solicitudes/{ticket}/visitas', [CalendarController::class, 'store'])->name('visits.store');
        Route::put('/visitas/{visit}', [CalendarController::class, 'update'])->name('visits.update');
        Route::delete('/visitas/{visit}', [CalendarController::class, 'cancel'])->name('visits.cancel');
        Route::post('/visitas/{visit}/sincronizar', [CalendarController::class, 'sync'])->name('visits.sync');
    });
    Route::get('/inicio', DashboardController::class)->name('home');
    Route::get('/panel', DashboardController::class)->name('dashboard');
    // Abre la pantalla que muestra las unidades sobre el mapa de TomTom.
    Route::get('/mapa', SchoolMapController::class)->name('map.index');
    Route::redirect('/home', '/inicio');
    Route::get('/solicitudes', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/solicitudes/crear', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/solicitudes', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/solicitudes/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/solicitudes/{ticket}/acta', [TicketController::class, 'act'])->name('tickets.act');
    Route::patch('/solicitudes/{ticket}/asignar', [TicketController::class, 'assign'])->name('tickets.assign');
    Route::patch('/solicitudes/{ticket}/estado', [TicketController::class, 'updateStatus'])->name('tickets.status');
    Route::post('/solicitudes/{ticket}/intervenciones', [InterventionController::class, 'store'])->name('interventions.store');
    Route::get('/equipos', [EquipmentController::class, 'index'])->name('equipment.index');
    Route::middleware('role:admin,technician')->group(function () {
        Route::get('/equipos/crear', [EquipmentController::class, 'create'])->name('equipment.create');
        Route::post('/equipos', [EquipmentController::class, 'store'])->name('equipment.store');
        Route::get('/equipos/{equipment}/editar', [EquipmentController::class, 'edit'])->name('equipment.edit');
        Route::put('/equipos/{equipment}', [EquipmentController::class, 'update'])->name('equipment.update');
    });
    Route::middleware('role:admin,technician')->group(function () {
        Route::get('/repuestos', [SparePartController::class, 'index'])->name('spare-parts.index');
        Route::post('/repuestos', [SparePartController::class, 'store'])->name('spare-parts.store');
        Route::put('/repuestos/{sparePart}', [SparePartController::class, 'update'])->name('spare-parts.update');
    });
    Route::get('/unidades-educativas', [SchoolController::class, 'index'])
        ->middleware('role:admin,technician')->name('schools.index');
    Route::middleware('role:admin')->group(function () {
        Route::get('/unidades-educativas/crear', [SchoolController::class, 'create'])->name('schools.create');
        Route::post('/unidades-educativas', [SchoolController::class, 'store'])->name('schools.store');
        Route::get('/unidades-educativas/{school}/editar', [SchoolController::class, 'edit'])->name('schools.edit');
        Route::put('/unidades-educativas/{school}', [SchoolController::class, 'update'])->name('schools.update');
        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::get('/usuarios/crear', [UserController::class, 'create'])->name('users.create');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
        Route::get('/usuarios/{user}/editar', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('users.update');
    });
    Route::get('/notificaciones', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notificaciones/leer-todas', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notificaciones/{id}', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reportes/exportar', [ReportController::class, 'export'])->name('reports.export');
});
