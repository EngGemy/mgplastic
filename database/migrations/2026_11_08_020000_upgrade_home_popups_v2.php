<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_popups')) {
            return;
        }

        Schema::table('home_popups', function (Blueprint $table) {
            if (! Schema::hasColumn('home_popups', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('id');
            }
            if (! Schema::hasColumn('home_popups', 'title_ar')) {
                $table->string('title_ar', 60)->nullable()->after('version');
            }
            if (! Schema::hasColumn('home_popups', 'title_en')) {
                $table->string('title_en', 60)->nullable()->after('title_ar');
            }
            if (! Schema::hasColumn('home_popups', 'body_ar')) {
                $table->string('body_ar', 200)->nullable()->after('title_en');
            }
            if (! Schema::hasColumn('home_popups', 'body_en')) {
                $table->string('body_en', 200)->nullable()->after('body_ar');
            }
            if (! Schema::hasColumn('home_popups', 'image_ratio')) {
                $table->decimal('image_ratio', 4, 2)->default(0.80)->after('image_path');
            }
            if (! Schema::hasColumn('home_popups', 'buttons')) {
                $table->json('buttons')->nullable()->after('image_ratio');
            }
            if (! Schema::hasColumn('home_popups', 'dismiss_text_ar')) {
                $table->string('dismiss_text_ar', 40)->nullable()->after('dismissible');
            }
            if (! Schema::hasColumn('home_popups', 'dismiss_text_en')) {
                $table->string('dismiss_text_en', 40)->nullable()->after('dismiss_text_ar');
            }
            if (! Schema::hasColumn('home_popups', 'show')) {
                $table->string('show', 16)->default('once')->after('dismiss_text_en');
            }
            if (! Schema::hasColumn('home_popups', 'test_user_ids')) {
                $table->json('test_user_ids')->nullable()->after('target_roles');
            }
        });

        $hasLegacyTitle = Schema::hasColumn('home_popups', 'title');
        $hasLegacyCta = Schema::hasColumn('home_popups', 'cta_text');

        if ($hasLegacyTitle || $hasLegacyCta) {
            $rows = DB::table('home_popups')->orderBy('id')->get();
            foreach ($rows as $row) {
                $show = match ((string) ($row->frequency ?? 'once')) {
                    'once_per_day', 'daily' => 'daily',
                    'every_open', 'always' => 'always',
                    default => 'once',
                };

                $kind = ((string) ($row->kind ?? '')) === 'poll' ? 'poll' : 'announcement';

                $buttons = [];
                if ($hasLegacyCta && filled($row->cta_text ?? null)) {
                    $buttons[] = [
                        'id' => 'cta',
                        'text_ar' => (string) $row->cta_text,
                        'text_en' => (string) $row->cta_text,
                        'link_type' => (string) ($row->link_type ?? 'home'),
                        'link_id' => $row->link_id ?? null,
                        'link_path' => $row->link_path ?? null,
                        'link_url' => $row->link_url ?? null,
                        'style' => 'primary',
                    ];
                }

                $payload = [
                    'kind' => $kind,
                    'show' => $show,
                ];

                if ($hasLegacyTitle) {
                    $payload['title_ar'] = $row->title_ar ?: ($row->title ?? null);
                    $payload['body_ar'] = $row->body_ar ?: ($row->subtitle ?? null);
                }

                if ($buttons !== []) {
                    $payload['buttons'] = json_encode($buttons, JSON_UNESCAPED_UNICODE);
                }

                DB::table('home_popups')->where('id', $row->id)->update($payload);
            }
        }

        // Legacy columns become optional after v2 (buttons JSON is source of truth).
        Schema::table('home_popups', function (Blueprint $table) {
            if (Schema::hasColumn('home_popups', 'cta_text')) {
                $table->string('cta_text')->nullable()->change();
            }
            if (Schema::hasColumn('home_popups', 'image_path')) {
                $table->string('image_path')->nullable()->change();
            }
            if (Schema::hasColumn('home_popups', 'title')) {
                $table->string('title')->nullable()->change();
            }
        });

        if (! Schema::hasTable('home_popup_events')) {
            Schema::create('home_popup_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('home_popup_id')->constrained('home_popups')->cascadeOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->string('event', 16); // view|click|dismiss
                $table->string('button_id', 64)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('role', 32)->nullable();
                $table->string('device_id', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['home_popup_id', 'event']);
                $table->index(['home_popup_id', 'button_id']);
                $table->index(['created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('home_popup_events');
    }
};
