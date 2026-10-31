<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReliabilityArchitectureTest extends TestCase
{
    #[Test]
    public function env_helper_is_not_used_outside_config(): void
    {
        $root = base_path('app');
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $contents = file_get_contents($path) ?: '';
            if (preg_match('/(?<!config\()(?<!->)\benv\s*\(/', $contents)) {
                // Allow comments mentioning env(
                $lines = explode("\n", $contents);
                foreach ($lines as $i => $line) {
                    $trim = ltrim($line);
                    if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '#')) {
                        continue;
                    }
                    if (preg_match('/\benv\s*\(/', $line)) {
                        $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.($i + 1);
                    }
                }
            }
        }

        $this->assertSame([], $hits, "env() used outside config:\n".implode("\n", $hits));
    }

    #[Test]
    public function http_controllers_do_not_reference_whats_app_notifier_directly(): void
    {
        $root = base_path('app/Http/Controllers');
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname()) ?: '';
            if (str_contains($contents, 'WhatsAppNotifier')) {
                $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $hits, "Controllers must not call WhatsAppNotifier directly:\n".implode("\n", $hits));
    }
}
