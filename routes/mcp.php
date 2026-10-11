<?php

use App\Http\Controllers\Api\Ai\AiMcpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Remote MCP (Streamable HTTP)
|--------------------------------------------------------------------------
| URL:  https://mg-plastic.com/mcp
| Auth: Authorization: Bearer <AI_GATEWAY_TOKEN>
|
| Claude.ai: choose "No sign-in" → Request headers → Authorization
|            value: Bearer YOUR_TOKEN  (include the word Bearer + space)
*/

// CORS preflight + discovery (no auth) — avoids Claude auto-forcing OAuth
Route::match(['get', 'options'], '/mcp', [AiMcpController::class, 'handle'])
    ->middleware(['throttle:ai-gateway'])
    ->name('mcp.discover');

// Tool calls require Bearer token
Route::post('/mcp', [AiMcpController::class, 'handle'])
    ->middleware(['ai.gateway', 'throttle:ai-gateway'])
    ->name('mcp');
