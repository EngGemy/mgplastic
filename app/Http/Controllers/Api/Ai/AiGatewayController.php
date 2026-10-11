<?php

namespace App\Http\Controllers\Api\Ai;

use App\Ai\AdminToolRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiGatewayController extends Controller
{
    public function __construct(
        protected AdminToolRegistry $registry,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'service' => 'mg-admin-ai-gateway',
            'allow_write' => (bool) config('ai.allow_write'),
            'tools_count' => count($this->registry->listDefinitions()),
        ]);
    }

    public function tools(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'tools' => $this->registry->listDefinitions(),
        ]);
    }

    public function call(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tool' => ['required', 'string', 'max:120'],
            'arguments' => ['nullable', 'array'],
        ]);

        $result = $this->registry->call(
            $data['tool'],
            $data['arguments'] ?? [],
            $request,
        );

        $status = $result['ok'] ? 200 : (int) ($result['http_status'] ?? 400);

        return response()->json($result, $status);
    }

    /** Convenience: call a tool by path segment for OpenAPI Actions. */
    public function callNamed(Request $request, string $tool): JsonResponse
    {
        $arguments = $request->all();
        unset($arguments['tool']);

        $result = $this->registry->call($tool, $arguments, $request);
        $status = $result['ok'] ? 200 : (int) ($result['http_status'] ?? 400);

        return response()->json($result, $status);
    }
}
