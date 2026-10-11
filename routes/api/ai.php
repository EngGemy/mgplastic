<?php

use App\Http\Controllers\Api\Ai\AiGatewayController;
use App\Http\Controllers\Api\Ai\AiMcpController;
use Illuminate\Support\Facades\Route;

Route::middleware(['ai.gateway', 'throttle:ai-gateway'])
    ->prefix('v1/ai')
    ->group(function () {
        Route::get('health', [AiGatewayController::class, 'health']);
        Route::get('tools', [AiGatewayController::class, 'tools']);
        Route::post('call', [AiGatewayController::class, 'call']);
        Route::post('tools/{tool}', [AiGatewayController::class, 'callNamed']);

        // MCP JSON-RPC adapter
        Route::post('mcp', [AiMcpController::class, 'handle']);
    });
