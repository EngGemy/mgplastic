<?php

namespace App\Models;

use App\Notifications\Catalog\NotificationCatalog;
use App\Notifications\Catalog\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'push',
        'in_app',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'push' => 'boolean',
            'in_app' => 'boolean',
            'email' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve preference for a user/type, falling back to catalog defaults.
     *
     * @return array{push: bool, in_app: bool, email: bool}
     */
    public static function resolveFor(User $user, NotificationType $type): array
    {
        $row = static::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->first();

        if ($row) {
            return [
                'push' => (bool) $row->push,
                'in_app' => (bool) $row->in_app,
                'email' => (bool) $row->email,
            ];
        }

        $def = NotificationCatalog::get($type);

        return [
            'push' => $def->defaultPush,
            'in_app' => $def->defaultInApp,
            'email' => $def->defaultEmail,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForUser(User $user): array
    {
        $stored = static::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('type');

        $out = [];
        foreach (NotificationCatalog::all() as $type => $def) {
            $row = $stored->get($type);
            $out[] = [
                'type' => $type,
                'push' => $row ? (bool) $row->push : $def->defaultPush,
                'in_app' => $row ? (bool) $row->in_app : $def->defaultInApp,
                'email' => $row ? (bool) $row->email : $def->defaultEmail,
                'channels' => $def->channels,
                'priority' => $def->priority,
                'screen' => $def->screen,
            ];
        }

        return $out;
    }
}
