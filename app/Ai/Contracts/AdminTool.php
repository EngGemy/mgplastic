<?php

namespace App\Ai\Contracts;

interface AdminTool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON-schema-like properties for GPT / MCP.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** Whether this tool mutates data. */
    public function isWrite(): bool;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;
}
