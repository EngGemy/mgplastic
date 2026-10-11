<?php

namespace App\Ai\Mcp;

use App\Ai\AdminToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Stateless Streamable HTTP MCP server (JSON responses).
 *
 * Compatible with ChatGPT custom MCP plugins / remote clients that POST
 * JSON-RPC to a single HTTPS endpoint and authenticate with Bearer.
 *
 * Spec focus: initialize, notifications/*, tools/list, tools/call, ping.
 */
class StreamableMcpServer
{
    public const PROTOCOL_VERSION = '2025-03-26';

    public const SERVER_NAME = 'mg-plastic-admin-mcp';

    public const SERVER_VERSION = '2.0.0';

    public function __construct(
        protected AdminToolRegistry $registry,
    ) {}

    public function handle(Request $request): SymfonyResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors(response()->noContent(204));
        }

        if ($request->isMethod('GET')) {
            // Discovery / health for humans and simple probes.
            return $this->cors(response()->json([
                'ok' => true,
                'transport' => 'streamable-http',
                'protocolVersion' => self::PROTOCOL_VERSION,
                'serverInfo' => [
                    'name' => self::SERVER_NAME,
                    'version' => self::SERVER_VERSION,
                ],
                'endpoint' => url('/mcp'),
                'auth' => 'Authorization: Bearer <AI_GATEWAY_TOKEN>',
                'methods' => ['initialize', 'tools/list', 'tools/call', 'ping'],
            ]));
        }

        if (! $request->isMethod('POST')) {
            return $this->cors(response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32600, 'message' => 'Only POST (and GET discovery) are supported'],
            ], 405));
        }

        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = $request->all();
        }

        // Batch (rare): process first request only for simplicity.
        if (array_is_list($payload) && isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        $method = (string) ($payload['method'] ?? '');
        $id = array_key_exists('id', $payload) ? $payload['id'] : null;
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $isNotification = ! array_key_exists('id', $payload);

        if ($method === '') {
            return $this->cors($this->rpcError($id, -32600, 'Missing JSON-RPC method'));
        }

        // Client notifications → 202 Accepted, no body.
        if ($isNotification || str_starts_with($method, 'notifications/')) {
            return $this->cors(response()->noContent(202));
        }

        return $this->cors(match ($method) {
            'initialize' => $this->rpc($id, $this->initializeResult()),
            'ping' => $this->rpc($id, new \stdClass),
            'tools/list' => $this->rpc($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($request, $id, $params),
            'resources/list' => $this->rpc($id, ['resources' => []]),
            'prompts/list' => $this->rpc($id, ['prompts' => []]),
            default => $this->rpcError($id, -32601, 'Method not found: '.$method, 404),
        });
    }

    /** @return array<string, mixed> */
    private function initializeResult(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => 'MG Plastic admin MCP. Use tools for dashboard, users, invoices, distributions, approvals, and reports. Write tools require AI_TOOLS_ALLOW_WRITE=true.',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function toolDefinitions(): array
    {
        return collect($this->registry->listDefinitions())
            ->map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'] ?? [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ])
            ->values()
            ->all();
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
        $text = json_encode(
            $result['ok'] ? ($result['data'] ?? []) : $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $this->rpc($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'structuredContent' => $result['ok'] ? ($result['data'] ?? new \stdClass) : $result,
            'isError' => ! ($result['ok'] ?? false),
        ]);
    }

    /** @param array<string, mixed>|\stdClass $result */
    private function rpc(mixed $id, array|\stdClass $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function rpcError(mixed $id, int $code, string $message, int $http = 400): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $http);
    }

    private function cors(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name, X-AI-Gateway-Token');
        $response->headers->set('Access-Control-Expose-Headers', 'MCP-Protocol-Version');
        $response->headers->set('MCP-Protocol-Version', self::PROTOCOL_VERSION);

        return $response;
    }
}
