<?php
namespace App\Notifications;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
class TicketEventNotification extends Notification { use Queueable; public function __construct(public Ticket $ticket,public string $event){} public function via(object $notifiable):array{return ['database'];} public function toArray(object $notifiable):array{return ['ticket_id'=>$this->ticket->id,'code'=>$this->ticket->code,'message'=>$this->event==='new'?'Nueva solicitud: '.$this->ticket->subject:'La solicitud '.$this->ticket->code.' cambió a '.$this->ticket->status,'url'=>route('tickets.show',$this->ticket)];} }
