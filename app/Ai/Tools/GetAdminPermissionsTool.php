<?php

namespace App\Ai\Tools;

use App\Support\AdminPermissions;

class GetAdminPermissionsTool extends AbstractTool
{
    public function name(): string
    {
        return 'get_admin_permissions';
    }

    public function description(): string
    {
        return 'List admin permission keys and Arabic labels used in the control panel.';
    }

    public function inputSchema(): array
    {
        return $this->schema();
    }

    public function handle(array $arguments): array
    {
        return [
            'permissions' => collect(AdminPermissions::labels())
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'note' => 'super_admin bypasses permission checks; admin may have a subset in users.permissions JSON.',
        ];
    }
}
