<?php
namespace App\Models;
use App\Models\Concerns\HasSpanishDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Equipment extends Model { use HasFactory,HasSpanishDatabase; protected $table='equipos'; protected $fillable=['school_id','asset_code','type','brand','model','serial_number','location','status','notes']; protected array $attributeMap=['school_id'=>'unidad_educativa_id','asset_code'=>'codigo_patrimonial','type'=>'tipo','brand'=>'marca','model'=>'modelo','serial_number'=>'numero_serie','location'=>'ubicacion','status'=>'estado','notes'=>'observaciones']; protected array $valueMap=['type'=>['computer'=>'computadora','printer'=>'impresora','monitor'=>'monitor','router'=>'router','network'=>'red','other'=>'otro'],'status'=>['operational'=>'operativo','under_repair'=>'en_reparacion','out_of_service'=>'fuera_servicio']]; public function school(){return $this->belongsTo(School::class,'unidad_educativa_id');} public function tickets(){return $this->hasMany(Ticket::class,'equipo_id');} }
