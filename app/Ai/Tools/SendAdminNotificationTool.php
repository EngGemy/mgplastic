<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\AdminNotificationService;
use InvalidArgumentException;

class SendAdminNotificationTool extends AbstractTool
{
    public function isWrite(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'send_admin_notification';
    }

    public function description(): string
    {
        return 'Send an in-app Filament database notification to a user, a role, or all users. Requires AI_TOOLS_ALLOW_WRITE=true.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'title' => ['type' => 'string'],
            'body' => ['type' => 'string'],
            'status' => [
                'type' => 'string',
                'enum' => ['info', 'success', 'warning', 'danger'],
            ],
            'user_id' => ['type' => 'integer', 'description' => 'Send to one user'],
            'role' => ['type' => 'string', 'description' => 'Send to all users with this role'],
            'all_users' => ['type' => 'boolean', 'description' => 'Send to everyone (use carefully)'],
        ], ['title']);
    }

    public function handle(array $arguments): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('title is required.');
        }

        $body = isset($arguments['body']) ? (string) $arguments['body'] : null;
        $status = (string) ($arguments['status'] ?? 'info');

        if (! empty($arguments['all_users'])) {
            $count = AdminNotificationService::sendToAll($title, $body, $status);

            return ['sent' => $count, 'scope' => 'all'];
        }

        if (! empty($arguments['role'])) {
            $count = AdminNotificationService::sendToRole((string) $arguments['role'], $title, $body, $status);

            return ['sent' => $count, 'scope' => 'role', 'role' => $arguments['role']];
        }

        if (! empty($arguments['user_id'])) {
            $user = User::query()->find((int) $arguments['user_id']);
            if (! $user) {
                throw new InvalidArgumentException('User not found.');
            }
            AdminNotificationService::send($user, $title, $body, $status);

            return ['sent' => 1, 'scope' => 'user', 'user_id' => $user->id];
        }

        throw new InvalidArgumentException('Provide user_id, role, or all_users=true.');
    }
}
