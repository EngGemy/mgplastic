<?php

namespace App\Http\Controllers\Api\Ai;

use App\Ai\Mcp\StreamableMcpServer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Streamable HTTP MCP endpoint.
 *
 * Production URL (preferred): https://mg-plastic.com/mcp
 * Legacy alias:              https://mg-plastic.com/api/v1/ai/mcp
 *
 * Auth: Authorization: Bearer <AI_GATEWAY_TOKEN>
 */
class AiMcpController extends Controller
{
    public function __construct(
        protected StreamableMcpServer $server,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->server->handle($request);
    }
}
