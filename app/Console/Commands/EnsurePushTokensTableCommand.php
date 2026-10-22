<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EnsurePushTokensTableCommand extends Command
{
    protected $signature = 'fcm:ensure-table';

    protected $description = 'Create or repair the push_tokens table used for FCM device registration';

    public function handle(): int
    {
        if (! Schema::hasTable('push_tokens')) {
            Schema::create('push_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('device_id', 191);
                $table->string('token', 512);
                $table->string('platform', 16);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique('device_id');
                $table->index('token');
                $table->index(['user_id', 'platform']);
                $table->index(['user_id', 'is_active']);
            });

            $this->info('Created push_tokens table.');

            return self::SUCCESS;
        }

        $added = [];

        Schema::table('push_tokens', function (Blueprint $table) use (&$added) {
            if (! Schema::hasColumn('push_tokens', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('platform');
                $added[] = 'is_active';
            }
            if (! Schema::hasColumn('push_tokens', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable();
                $added[] = 'last_seen_at';
            }
            if (! Schema::hasColumn('push_tokens', 'device_id')) {
                $table->string('device_id', 191)->after('user_id');
                $added[] = 'device_id';
            }
            if (! Schema::hasColumn('push_tokens', 'token')) {
                $table->string('token', 512)->after('device_id');
                $added[] = 'token';
            }
            if (! Schema::hasColumn('push_tokens', 'platform')) {
                $table->string('platform', 16)->after('token');
                $added[] = 'platform';
            }
        });

        $cols = implode(', ', Schema::getColumnListing('push_tokens'));
        $this->info('push_tokens OK — columns: '.$cols);
        if ($added !== []) {
            $this->info('Added columns: '.implode(', ', $added));
        }

        return self::SUCCESS;
    }
}
