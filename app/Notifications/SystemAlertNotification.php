<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SystemAlertNotification extends Notification
{
    use Queueable;

    public function __construct(public string $title, public string $message, public array $payload = []) {}

    public function via(object $n): array
    {
        return ['database'];
    }

    public function toArray(object $n): array
    {
        return ['title' => $this->title, 'message' => $this->message] + $this->payload;
    }
}
