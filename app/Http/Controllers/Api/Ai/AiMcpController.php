<?php

namespace App\Http\Controllers\Api\Ai;

use App\Ai\AdminToolRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal HTTP JSON-RPC style MCP adapter.
 * Supports tools/list and tools/call (and initialize/ping helpers).
 */
class AiMcpController extends Controller
{
    public function __construct(
        protected AdminToolRegistry $registry,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $method = (string) ($payload['method'] ?? $request->query('method', ''));
        $id = $payload['id'] ?? 1;
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        return match ($method) {
            'initialize', 'ping' => $this->rpc($id, [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => [
                    'name' => 'mg-admin-ai-gateway',
                    'version' => '1.0.0',
                ],
                'capabilities' => [
                    'tools' => new \stdClass,
                ],
            ]),
            'tools/list' => $this->rpc($id, [
                'tools' => collect($this->registry->listDefinitions())
                    ->map(fn (array $tool) => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'inputSchema' => $tool['inputSchema'],
                    ])
                    ->values()
                    ->all(),
            ]),
            'tools/call' => $this->callTool($request, $id, $params),
            default => $this->rpcError($id, -32601, 'Method not found: '.$method),
        };
    }

    /** @param array<string, mixed> $params */
    private function callTool(Request $request, mixed $id, array $params): JsonResponse
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '') {
            return $this->rpcError($id, -32602, 'Missing tool name');
        }

        $result = $this->registry->call($name, $arguments, $request);

        if (! $result['ok']) {
            return $this->rpc($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ],
                ],
                'isError' => true,
            ]);
        }

        return $this->rpc($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result['data'], JSON_UNESCAPED_UNICODE),
                ],
            ],
            'structuredContent' => $result['data'],
            'isError' => false,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function rpc(mixed $id, array $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function rpcError(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 400);
    }
}
