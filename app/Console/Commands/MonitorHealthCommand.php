<?php

namespace App\Console\Commands;

use App\Services\HealthAlertService;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;

class MonitorHealthCommand extends Command
{
    protected $signature = 'monitor:health {--json : Output checks as JSON}';

    protected $description = 'Run system health checks and alert on state changes';

    public function handle(SystemHealthService $health, HealthAlertService $alerts): int
    {
        $result = $alerts->evaluateAndAlert();
        $checks = $health->checksAsArrays(liveWahaSession: true);

        if ($this->option('json')) {
            $this->line(json_encode([
                'checks' => $checks,
                'alerts' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($checks as $check) {
            $line = sprintf(
                '[%s] %s — %s',
                strtoupper((string) $check['status']),
                $check['label'],
                $check['details'] ?? ''
            );
            match ($check['status']) {
                'critical' => $this->error($line),
                'warning' => $this->warn($line),
                default => $this->info($line),
            };
        }

        if ($result['alerted'] !== []) {
            $this->warn('New alerts: '.implode(', ', $result['alerted']));
        }
        if ($result['repeated'] !== []) {
            $this->warn('Repeated alerts: '.implode(', ', $result['repeated']));
        }
        if ($result['recovered'] !== []) {
            $this->info('Recovered: '.implode(', ', $result['recovered']));
        }

        $hasCritical = collect($checks)->contains(fn ($c) => ($c['status'] ?? '') === 'critical');

        return $hasCritical ? self::FAILURE : self::SUCCESS;
    }
}
