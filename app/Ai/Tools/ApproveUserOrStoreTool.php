<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\StoreApprovalService;
use InvalidArgumentException;

class ApproveUserOrStoreTool extends AbstractTool
{
    public function isWrite(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'approve_user_or_store';
    }

    public function description(): string
    {
        return 'Approve, reject, activate, or deactivate a user/store account. Requires AI_TOOLS_ALLOW_WRITE=true.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'user_id' => ['type' => 'integer'],
            'action' => [
                'type' => 'string',
                'enum' => ['approve', 'reject', 'activate', 'deactivate'],
            ],
            'reason' => [
                'type' => 'string',
                'description' => 'Optional reason for reject/deactivate',
            ],
        ], ['user_id', 'action']);
    }

    public function handle(array $arguments): array
    {
        $user = User::query()->find((int) ($arguments['user_id'] ?? 0));
        if (! $user) {
            throw new InvalidArgumentException('User not found.');
        }

        $action = (string) ($arguments['action'] ?? '');
        $reason = isset($arguments['reason']) ? (string) $arguments['reason'] : null;
        $service = app(StoreApprovalService::class);

        $user = match ($action) {
            'approve' => $service->approve($user),
            'reject' => $service->reject($user, $reason),
            'activate' => $service->activate($user),
            'deactivate' => $service->deactivate($user, $reason),
            default => throw new InvalidArgumentException('Invalid action.'),
        };

        return [
            'action' => $action,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'is_approved' => (bool) $user->is_approved,
                'is_active' => (bool) $user->is_active,
            ],
        ];
    }
}
