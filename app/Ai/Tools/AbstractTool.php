<?php

namespace App\Ai\Tools;

use App\Ai\Contracts\AdminTool;

abstract class AbstractTool implements AdminTool
{
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function schema(array $properties = [], array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
