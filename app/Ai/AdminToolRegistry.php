<?php

namespace App\Ai;

use App\Ai\Contracts\AdminTool;
use App\Ai\Tools\ApproveUserOrStoreTool;
use App\Ai\Tools\DashboardSummaryTool;
use App\Ai\Tools\ExplainDomainTool;
use App\Ai\Tools\FaqSearchTool;
use App\Ai\Tools\GetAdminPermissionsTool;
use App\Ai\Tools\GetDistributionTool;
use App\Ai\Tools\GetInvoiceTool;
use App\Ai\Tools\GetUserTool;
use App\Ai\Tools\ListPendingApprovalsTool;
use App\Ai\Tools\ListWithdrawalRequestsTool;
use App\Ai\Tools\ProductCatalogSummaryTool;
use App\Ai\Tools\ReportInvoicesTool;
use App\Ai\Tools\ReportNetworkTool;
use App\Ai\Tools\ReportPointsTool;
use App\Ai\Tools\ReportUsersTool;
use App\Ai\Tools\SearchDistributionsTool;
use App\Ai\Tools\SearchInvoicesTool;
use App\Ai\Tools\SearchUsersTool;
use App\Ai\Tools\SendAdminNotificationTool;
use App\Ai\Tools\UpdateWebsiteSettingFieldTool;
use App\Models\AiToolCall;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class AdminToolRegistry
{
    /** @var array<string, AdminTool>|null */
    private ?array $tools = null;

    /** @return array<string, AdminTool> */
    public function all(): array
    {
        if ($this->tools === null) {
            $instances = [
                new ExplainDomainTool,
                new FaqSearchTool,
                new GetAdminPermissionsTool,
                new DashboardSummaryTool,
                new SearchUsersTool,
                new GetUserTool,
                new SearchInvoicesTool,
                new GetInvoiceTool,
                new SearchDistributionsTool,
                new GetDistributionTool,
                new ListPendingApprovalsTool,
                new ListWithdrawalRequestsTool,
                new ProductCatalogSummaryTool,
                new ReportUsersTool,
                new ReportPointsTool,
                new ReportInvoicesTool,
                new ReportNetworkTool,
                new ApproveUserOrStoreTool,
                new SendAdminNotificationTool,
                new UpdateWebsiteSettingFieldTool,
            ];

            $this->tools = [];
            foreach ($instances as $tool) {
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function get(string $name): AdminTool
    {
        $tools = $this->all();

        if (! isset($tools[$name])) {
            throw new InvalidArgumentException("Unknown tool: {$name}");
        }

        return $tools[$name];
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, is_write: bool}>
     */
    public function listDefinitions(bool $includeWrite = true): array
    {
        $allowWrite = (bool) config('ai.allow_write');

        return collect($this->all())
            ->filter(fn (AdminTool $tool) => $includeWrite || ! $tool->isWrite())
            ->filter(fn (AdminTool $tool) => $allowWrite || ! $tool->isWrite())
            ->map(fn (AdminTool $tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
                'is_write' => $tool->isWrite(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, tool: string, data?: mixed, error?: string}
     */
    public function call(string $name, array $arguments = [], ?Request $request = null): array
    {
        $started = microtime(true);
        $ok = false;
        $error = null;
        $data = null;
        $httpStatus = 200;

        try {
            $tool = $this->get($name);

            if ($tool->isWrite() && ! config('ai.allow_write')) {
                throw new InvalidArgumentException('Write tools are disabled. Set AI_TOOLS_ALLOW_WRITE=true on the server.');
            }

            $data = $tool->handle($arguments);
            $ok = true;
        } catch (ValidationException $e) {
            $ok = false;
            $httpStatus = 422;
            $error = collect($e->errors())->flatten()->implode(' ');
        } catch (InvalidArgumentException $e) {
            $ok = false;
            $httpStatus = 400;
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $ok = false;
            $httpStatus = 500;
            $error = config('app.debug') ? $e->getMessage() : 'Tool execution failed.';
            report($e);
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        try {
            AiToolCall::query()->create([
                'tool' => $name,
                'arguments' => Arr::except($arguments, ['password', 'token']),
                'ok' => $ok,
                'http_status' => $httpStatus,
                'error' => $error,
                'result_meta' => $ok ? [
                    'keys' => is_array($data) ? array_keys($data) : [],
                ] : null,
                'ip' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 500) ?: null,
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $ok) {
            return [
                'ok' => false,
                'tool' => $name,
                'error' => $error ?? 'Unknown error',
                'http_status' => $httpStatus,
            ];
        }

        return [
            'ok' => true,
            'tool' => $name,
            'data' => $data,
            'duration_ms' => $durationMs,
        ];
    }
}
