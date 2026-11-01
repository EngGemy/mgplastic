<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text city/country for exhibitions. city_id becomes optional (legacy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'location_city')) {
                $table->string('location_city', 120)->nullable()->after('city_id');
            }
            if (! Schema::hasColumn('events', 'location_country')) {
                $table->string('location_country', 120)->nullable()->after('location_city');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("
                UPDATE events e
                INNER JOIN cities c ON c.id = e.city_id
                LEFT JOIN countries co ON co.id = c.country_id
                SET
                    e.location_city = COALESCE(NULLIF(e.location_city, ''), COALESCE(c.name_ar, c.name_en)),
                    e.location_country = COALESCE(NULLIF(e.location_country, ''), COALESCE(co.name_ar, co.name_en))
                WHERE e.city_id IS NOT NULL
            ");

            // Drop FK if present, then allow NULL.
            try {
                Schema::table('events', function (Blueprint $table) {
                    $table->dropForeign(['city_id']);
                });
            } catch (\Throwable) {
            }

            DB::statement('ALTER TABLE events MODIFY city_id BIGINT UNSIGNED NULL');

            try {
                Schema::table('events', function (Blueprint $table) {
                    $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        } else {
            // SQLite / fresh installs: create migration already has city_id nullable.
            $rows = DB::table('events')->whereNotNull('city_id')->get(['id', 'city_id', 'location_city', 'location_country']);
            foreach ($rows as $row) {
                if (filled($row->location_city) && filled($row->location_country)) {
                    continue;
                }
                $city = DB::table('cities')->where('id', $row->city_id)->first();
                if (! $city) {
                    continue;
                }
                $country = $city->country_id
                    ? DB::table('countries')->where('id', $city->country_id)->first()
                    : null;
                DB::table('events')->where('id', $row->id)->update([
                    'location_city' => $row->location_city ?: ($city->name_ar ?: $city->name_en),
                    'location_country' => $row->location_country ?: ($country->name_ar ?? $country->name_en ?? null),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'location_country')) {
                $table->dropColumn('location_country');
            }
            if (Schema::hasColumn('events', 'location_city')) {
                $table->dropColumn('location_city');
            }
        });
    }
};
