<?php

namespace App\Services;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class SecurityAuditService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, ?User $actor, ?Model $subject = null, array $meta = []): SecurityAuditLog
    {
        $log = SecurityAuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500) ?: null,
        ]);

        Log::info('security_audit', [
            'action' => $action,
            'actor_id' => $actor?->id,
            'subject' => $subject ? class_basename($subject).'#'.$subject->getKey() : null,
            'meta' => $meta,
        ]);

        return $log;
    }
}
