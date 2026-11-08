<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiBenchCommand extends Command
{
    protected $signature = 'api:bench
                            {--iterations=5 : Times to hit each path}
                            {--user= : User id for authenticated routes}
                            {--token= : Sanctum bearer token (optional)}
                            {--path=* : Extra API paths to bench}';

    protected $description = 'Measure hot mobile API response times (no Redis required)';

    public function handle(): int
    {
        $iterations = max(1, (int) $this->option('iterations'));
        $paths = array_values(array_filter(array_merge([
            '/api/v1/mobile/settings',
            '/api/v1/mobile/home/layout',
            '/api/v1/mobile/home/popup',
        ], (array) $this->option('path'))));

        $user = null;
        $token = $this->option('token');
        if ($this->option('user')) {
            $user = User::query()->find((int) $this->option('user'));
            if (! $user) {
                $this->error('User not found: '.$this->option('user'));

                return self::FAILURE;
            }
        }

        $this->info('API bench — '.$iterations.' iteration(s) each');
        $this->newLine();

        $rows = [];
        foreach ($paths as $path) {
            $samples = [];
            $lastStatus = 0;
            for ($i = 0; $i < $iterations; $i++) {
                [$ms, $status] = $this->hit($path, $user, is_string($token) ? $token : null);
                $samples[] = $ms;
                $lastStatus = $status;
            }
            sort($samples);
            $avg = array_sum($samples) / max(1, count($samples));
            $p95 = $samples[(int) max(0, (int) floor(count($samples) * 0.95) - 1)] ?? end($samples);

            $rows[] = [
                $path,
                $lastStatus,
                (int) round(min($samples)),
                (int) round($avg),
                (int) round($p95),
                (int) round(max($samples)),
            ];
        }

        $this->table(['path', 'status', 'min_ms', 'avg_ms', 'p95_ms', 'max_ms'], $rows);
        $this->comment('Tip: pass --user=ID for authenticated catalog/POS paths.');

        return self::SUCCESS;
    }

    /** @return array{0: float, 1: int} */
    protected function hit(string $path, ?User $user, ?string $token): array
    {
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'ar',
        ];
        if (filled($token)) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $request = Request::create($path, 'GET', [], [], [], $server);
        if ($user) {
            $request->setUserResolver(fn () => $user);
            Auth::guard('sanctum')->setUser($user);
            Auth::setUser($user);
        }

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $started = microtime(true);
        $response = $kernel->handle($request);
        $ms = (microtime(true) - $started) * 1000;
        $kernel->terminate($request, $response);
        Auth::forgetGuards();

        return [$ms, $response->getStatusCode()];
    }
}
