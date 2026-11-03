<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class OcrCacheClearCommand extends Command
{
    protected $signature = 'ocr:cache-clear';

    protected $description = 'Clear OCR draft cache keys only (v2/v3/v4 prefixes), not the whole cache';

    public function handle(): int
    {
        $cleared = 0;
        $store = Cache::getStore();

        // Redis: delete by prefix.
        if (method_exists($store, 'getRedis')) {
            try {
                /** @var \Illuminate\Redis\Connections\Connection $redis */
                $redis = $store->getRedis();
                $prefix = (string) config('cache.prefix', '');
                foreach (['ocr:draft:v2:*', 'ocr:draft:v3:*', 'ocr:draft:v4:*', 'ocr:pos:job:*'] as $pattern) {
                    $keys = $redis->keys($prefix.$pattern);
                    if (is_array($keys) && $keys !== []) {
                        $redis->del(...$keys);
                        $cleared += count($keys);
                    }
                }
                $this->info("Cleared {$cleared} OCR redis keys.");

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->warn('Redis prefix clear failed: '.$e->getMessage());
            }
        }

        // File/database/array stores: best-effort — bump by writing a tombstone generation note.
        Cache::forever('ocr:cache:generation', (string) now()->timestamp);
        $this->info('OCR cache generation bumped (non-redis store). New reads use v3 keys; old v2 entries expire naturally.');
        $this->comment('Tip: QUEUE/cache redis recommended for precise OCR key deletion.');

        return self::SUCCESS;
    }
}
