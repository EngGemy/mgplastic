<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Gateway token (Bearer)
    |--------------------------------------------------------------------------
    | Used by ChatGPT Custom GPT Actions and MCP clients.
    | Generate a long random string and keep it only in server .env.
    */
    'gateway_token' => env('AI_GATEWAY_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Allow write / create tools
    |--------------------------------------------------------------------------
    */
    'allow_write' => (bool) env('AI_TOOLS_ALLOW_WRITE', false),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    */
    'rate_limit_per_minute' => (int) env('AI_GATEWAY_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Knowledge base path (Markdown)
    |--------------------------------------------------------------------------
    */
    'knowledge_path' => base_path('docs/ai/knowledge'),
];
