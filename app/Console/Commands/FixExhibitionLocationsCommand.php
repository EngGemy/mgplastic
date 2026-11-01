<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Country;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-point exhibition events that mention Morocco/Senegal but are pinned on Libyan cities.
 */
class FixExhibitionLocationsCommand extends Command
{
    protected $signature = 'events:fix-exhibition-locations {--dry-run : Show changes without saving}';

    protected $description = 'Fix event city/map for Morocco & Senegal exhibitions mistakenly set to Libya';

    /** @var array<string, array{lat: float, lng: float}> */
    protected array $cityCoords = [
        'El Jadida' => ['lat' => 33.2316, 'lng' => -8.5007],
        'Casablanca' => ['lat' => 33.5731, 'lng' => -7.5898],
        'Rabat' => ['lat' => 34.0209, 'lng' => -6.8416],
        'Marrakech' => ['lat' => 31.6295, 'lng' => -7.9811],
        'Dakar' => ['lat' => 14.7167, 'lng' => -17.4677],
    ];

    public function handle(): int
    {
        (new \Database\Seeders\ExhibitionCountriesSeeder)->run();

        $dry = (bool) $this->option('dry-run');
        $fixed = 0;

        Event::query()->with(['city.country', 'translations'])->orderBy('id')->chunkById(50, function ($events) use ($dry, &$fixed) {
            foreach ($events as $event) {
                $blob = mb_strtolower(implode(' ', array_filter([
                    (string) $event->address,
                    optional($event->translate('ar'))->title,
                    optional($event->translate('ar'))->description,
                    optional($event->translate('en'))->title,
                    optional($event->translate('en'))->description,
                ])));

                $targetCityEn = null;
                if (str_contains($blob, 'الجديدة') || str_contains($blob, 'el jadida') || str_contains($blob, 'jadida')) {
                    $targetCityEn = 'El Jadida';
                } elseif (str_contains($blob, 'المغرب') || str_contains($blob, 'morocco') || str_contains($blob, 'maroc')) {
                    $targetCityEn = 'El Jadida';
                } elseif (str_contains($blob, 'داكار') || str_contains($blob, 'dakar') || str_contains($blob, 'السنغال') || str_contains($blob, 'senegal')) {
                    $targetCityEn = 'Dakar';
                }

                if ($targetCityEn === null) {
                    continue;
                }

                $city = City::query()->where('name_en', $targetCityEn)->first();
                if (! $city) {
                    $this->warn("City {$targetCityEn} missing — skip event #{$event->id}");

                    continue;
                }

                $country = $city->country;
                $currentCountry = $event->city?->country?->name_en;
                $coords = $this->cityCoords[$targetCityEn] ?? null;
                $needsCity = (int) $event->city_id !== (int) $city->id;
                $needsMap = $coords && (
                    abs((float) $event->latitude - $coords['lat']) > 0.5
                    || abs((float) $event->longitude - $coords['lng']) > 0.5
                );

                if (! $needsCity && ! $needsMap) {
                    continue;
                }

                $this->line(sprintf(
                    'Event #%d: city %s (%s) → %s (%s)%s',
                    $event->id,
                    $event->city?->name_en ?? 'null',
                    $currentCountry ?? '?',
                    $city->name_en,
                    $country?->name_en ?? '?',
                    $needsMap ? ' + map coords' : ''
                ));

                if ($dry) {
                    $fixed++;

                    continue;
                }

                DB::transaction(function () use ($event, $city, $coords, $needsMap) {
                    $event->city_id = $city->id;
                    if ($needsMap && $coords) {
                        $event->latitude = $coords['lat'];
                        $event->longitude = $coords['lng'];
                    }
                    $event->save();
                });

                $fixed++;
            }
        });

        $this->info(($dry ? 'Would fix' : 'Fixed')." {$fixed} event(s).");

        return self::SUCCESS;
    }
}
