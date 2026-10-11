<?php

namespace App\Ai\Tools;

class ExplainDomainTool extends AbstractTool
{
    public function name(): string
    {
        return 'explain_domain';
    }

    public function description(): string
    {
        return 'Explain an MG Plastic admin domain topic in Arabic (roles, points flow, invoices, withdrawals, panels, OTP).';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'topic' => [
                'type' => 'string',
                'description' => 'Topic key or free text. Known keys: roles, points, invoices, distributions, withdrawals, panels, otp, permissions',
            ],
        ], ['topic']);
    }

    public function handle(array $arguments): array
    {
        $topic = strtolower(trim((string) ($arguments['topic'] ?? '')));
        $map = [
            'roles' => 'roles-permissions.md',
            'permissions' => 'roles-permissions.md',
            'points' => 'points-inventory.md',
            'inventory' => 'points-inventory.md',
            'invoices' => 'invoices-distributions.md',
            'distributions' => 'invoices-distributions.md',
            'returns' => 'invoices-distributions.md',
            'withdrawals' => 'wallet-withdrawals.md',
            'wallet' => 'wallet-withdrawals.md',
            'panels' => 'panels.md',
            'admin' => 'panels.md',
            'otp' => 'otp-marsol.md',
            'marsol' => 'otp-marsol.md',
        ];

        $file = $map[$topic] ?? null;
        if (! $file) {
            foreach ($map as $key => $candidate) {
                if (str_contains($topic, $key)) {
                    $file = $candidate;
                    break;
                }
            }
        }

        $file ??= 'roles-permissions.md';
        $path = rtrim((string) config('ai.knowledge_path'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            return ['topic' => $topic, 'content' => 'Knowledge file missing: '.$file];
        }

        return [
            'topic' => $topic,
            'file' => $file,
            'content' => file_get_contents($path),
        ];
    }
}
