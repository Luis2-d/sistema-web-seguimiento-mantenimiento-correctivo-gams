<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class NotificationController extends Controller { public function index(Request $r){return view('notifications.index',['notifications'=>$r->user()->notifications()->paginate(20)]);} public function read(Request $r,string $id){$n=$r->user()->notifications()->findOrFail($id);$n->markAsRead();return redirect($n->data['url']??route('notifications.index'));} public function readAll(Request $r){$r->user()->unreadNotifications->markAsRead();return back()->with('success','Notificaciones marcadas como leídas.');} }
