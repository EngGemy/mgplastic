<?php

use App\Http\Controllers\Api\Ai\AiMcpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Remote MCP (Streamable HTTP) — paste this URL in ChatGPT / Cursor
|--------------------------------------------------------------------------
| https://mg-plastic.com/mcp
| Auth header: Authorization: Bearer <AI_GATEWAY_TOKEN>
*/

Route::middleware(['ai.gateway', 'throttle:ai-gateway'])
    ->match(['get', 'post', 'options'], '/mcp', [AiMcpController::class, 'handle'])
    ->name('mcp');
