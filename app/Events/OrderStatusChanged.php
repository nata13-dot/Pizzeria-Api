<?php
namespace App\Events;
use App\Models\Order;use Illuminate\Broadcasting\PrivateChannel;use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;use Illuminate\Foundation\Events\Dispatchable;use Illuminate\Queue\SerializesModels;
class OrderStatusChanged implements ShouldBroadcastNow{use Dispatchable,SerializesModels;public function __construct(public Order $order){}public function broadcastOn():array{return [new PrivateChannel('branch.'.$this->order->branch_id.'.orders')];}public function broadcastWith():array{return ['id'=>$this->order->id,'daily_number'=>$this->order->daily_number,'status'=>$this->order->status,'type'=>$this->order->type,'scheduled_at'=>$this->order->scheduled_at];}}
