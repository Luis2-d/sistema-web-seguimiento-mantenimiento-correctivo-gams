<?php

namespace Database\Seeders;

use App\Models\{Equipment,School,SparePart,Ticket,TicketStatusHistory,User};
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $school = School::create(['name'=>'U.E. Simón Bolívar','district'=>'Distrito 1','address'=>'Sacaba','phone'=>'4440000']);
        $admin = User::create(['name'=>'Administrador del sistema','email'=>'admin@educacion.test','password'=>'password','role'=>'admin']);
        $technician = User::create(['name'=>'Carlos Técnico','email'=>'tecnico@educacion.test','password'=>'password','role'=>'technician']);
        $director = User::create(['name'=>'María Directora','email'=>'director@educacion.test','password'=>'password','role'=>'director','school_id'=>$school->id]);
        $equipment = Equipment::create(['school_id'=>$school->id,'asset_code'=>'EQ-0001','type'=>'computer','brand'=>'Lenovo','model'=>'ThinkCentre','serial_number'=>'DEMO-001','location'=>'Laboratorio','status'=>'under_repair']);
        $ticket = Ticket::create(['code'=>'SOL-DEMO-001','school_id'=>$school->id,'equipment_id'=>$equipment->id,'requester_id'=>$director->id,'assigned_to'=>$technician->id,'category'=>'hardware','priority'=>'high','status'=>'diagnosis','subject'=>'El equipo no enciende','description'=>'El computador dejó de encender después de una variación de energía.']);
        TicketStatusHistory::create(['ticket_id'=>$ticket->id,'changed_by'=>$director->id,'to_status'=>'pending','comment'=>'Solicitud registrada']);
        TicketStatusHistory::create(['ticket_id'=>$ticket->id,'changed_by'=>$technician->id,'from_status'=>'pending','to_status'=>'diagnosis','comment'=>'Equipo recibido para diagnóstico']);
        SparePart::insert([['nombre'=>'Memoria RAM DDR4 8 GB','codigo'=>'RAM-DDR4-8','stock'=>5,'created_at'=>now(),'updated_at'=>now()],['nombre'=>'Fuente de poder ATX','codigo'=>'PSU-ATX','stock'=>2,'created_at'=>now(),'updated_at'=>now()],['nombre'=>'Disco SSD 480 GB','codigo'=>'SSD-480','stock'=>4,'created_at'=>now(),'updated_at'=>now()]]);
    }
}
