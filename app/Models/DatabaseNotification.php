<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\DatabaseNotification as BaseDatabaseNotification;

class DatabaseNotification extends BaseDatabaseNotification
{
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function title(): string
    {
        return (string) (($this->data['title'] ?? null) ?: '—');
    }

    public function body(): string
    {
        return (string) ($this->data['body'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? 'info');
    }

    public function entityType(): ?string
    {
        $type = $this->data['entity_type'] ?? $this->data['type'] ?? null;

        return $type ? (string) $type : null;
    }

    public function notifiableName(): string
    {
        $n = $this->notifiable;
        if ($n instanceof User) {
            return trim(($n->name ?: '').' · '.($n->phone ?: '')) ?: ('#'.$n->id);
        }

        return class_basename((string) $this->notifiable_type).' #'.$this->notifiable_id;
    }
}
