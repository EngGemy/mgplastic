<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Filament-compatible database notification delivered synchronously
 * (Filament's built-in DatabaseNotification always queues).
 */
class SyncFilamentDatabaseNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }
}
