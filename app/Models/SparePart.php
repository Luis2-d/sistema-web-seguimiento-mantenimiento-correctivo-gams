<?php
namespace App\Models;
use App\Models\Concerns\HasSpanishDatabase;
use Illuminate\Database\Eloquent\Model;
class SparePart extends Model { use HasSpanishDatabase; protected $table='repuestos'; protected $fillable=['name','sku','stock','description']; protected array $attributeMap=['name'=>'nombre','sku'=>'codigo','description'=>'descripcion']; public function interventions(){return $this->belongsToMany(Intervention::class,'intervencion_repuesto','repuesto_id','intervencion_id')->withPivot(['cantidad','observaciones'])->withTimestamps();} }
