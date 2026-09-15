<?php
namespace App\Http\Controllers;
use App\Models\SparePart;
use Illuminate\Http\Request;
class SparePartController extends Controller { public function index(){return view('spare-parts.index',['parts'=>SparePart::orderBy('nombre')->paginate(15)]);} public function store(Request $r){SparePart::create($r->validate(['name'=>'required|max:150','sku'=>'nullable|max:60|unique:repuestos,codigo','stock'=>'required|integer|min:0','description'=>'nullable|max:1000']));return back()->with('success','Repuesto registrado.');} public function update(Request $r,SparePart $sparePart){$sparePart->update($r->validate(['name'=>'required|max:150','sku'=>'nullable|max:60|unique:repuestos,codigo,'.$sparePart->id,'stock'=>'required|integer|min:0','description'=>'nullable|max:1000']));return back()->with('success','Repuesto actualizado.');} }
