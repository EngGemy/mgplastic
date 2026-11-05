<?php

namespace App\Console\Commands;

use App\Models\OtaUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PublishTestOtaCommand extends Command
{
    protected $signature = 'ota:publish-test
                            {--ios-build=10 : iOS native_build (CFBundleVersion)}
                            {--android-build=13 : Android native_build (versionCode)}
                            {--activate : Mark the new rows active (default true)}
                            {--inactive : Create inactive rows (for deactivation testing)}';

    protected $description = 'Create tiny OTA zip bundles + DB rows for mobile download_url / deactivate tests';

    public function handle(): int
    {
        $iosBuild = (string) $this->option('ios-build');
        $androidBuild = (string) $this->option('android-build');
        $active = ! $this->option('inactive');
        if ($this->option('activate')) {
            $active = true;
        }

        $created = [];
        foreach ([
            ['platform' => OtaUpdate::PLATFORM_IOS, 'build' => $iosBuild],
            ['platform' => OtaUpdate::PLATFORM_ANDROID, 'build' => $androidBuild],
        ] as $target) {
            $row = $this->publishOne($target['platform'], $target['build'], $active);
            $created[] = $row;
            $this->line(sprintf(
                '%s build %s → v%d (%s) %s',
                $row->platform,
                $row->native_build,
                $row->version,
                $row->file_path,
                $row->is_active ? 'active' : 'inactive',
            ));
            if ($url = $row->downloadUrl()) {
                $this->line('  download_url: '.$url);
            } else {
                $this->warn('  download_url: null (check storage:link / MEDIA_URL)');
            }
        }

        $this->info('Published '.count($created).' OTA test bundle(s). Toggle is_active in Filament to test deactivation.');

        return self::SUCCESS;
    }

    protected function publishOne(string $platform, string $nativeBuild, bool $active): OtaUpdate
    {
        $version = OtaUpdate::nextVersionFor($platform, $nativeBuild);
        $fileName = OtaUpdate::suggestedFileName($platform, $nativeBuild, $version);
        $relative = 'ota/'.$fileName;

        $this->writeTinyZip($relative, $platform, $nativeBuild, $version);

        return OtaUpdate::query()->create([
            'platform' => $platform,
            'native_build' => $nativeBuild,
            'version' => $version,
            'file_path' => $relative,
            'release_note' => 'Test OTA bundle for download_url / deactivate checks',
            'is_active' => $active,
            'activated_at' => $active ? now() : null,
        ]);
    }

    protected function writeTinyZip(string $relative, string $platform, string $nativeBuild, int $version): void
    {
        $absolute = Storage::disk('public')->path($relative);
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Unable to create zip at {$absolute}");
        }

        $manifest = json_encode([
            'platform' => $platform,
            'native_build' => $nativeBuild,
            'version' => $version,
            'note' => 'MG Plastic test OTA bundle — safe to deactivate',
            'created_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $zip->addFromString('manifest.json', (string) $manifest);
        $zip->addFromString('README.txt', "MG Plastic test OTA\nplatform={$platform}\nbuild={$nativeBuild}\nversion={$version}\n");
        $zip->close();

        // Mirror into public/storage when the symlink is missing (cPanel / Windows).
        $webPath = public_path('storage/'.$relative);
        $webDir = dirname($webPath);
        if (! is_dir($webDir)) {
            mkdir($webDir, 0755, true);
        }
        if (! is_file($webPath) || ! is_link(public_path('storage'))) {
            copy($absolute, $webPath);
        }
    }
}
