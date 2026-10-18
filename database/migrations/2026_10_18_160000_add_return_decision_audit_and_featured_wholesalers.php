<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_returns')) {
            Schema::table('invoice_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('invoice_returns', 'decided_by')) {
                    $table->foreignId('decided_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('invoice_returns', 'decided_at')) {
                    $table->timestamp('decided_at')->nullable()->after('decided_by');
                }
                if (! Schema::hasColumn('invoice_returns', 'decision_audit')) {
                    $table->json('decision_audit')->nullable()->after('decided_at');
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_featured_wholesaler')) {
                $table->boolean('is_featured_wholesaler')->default(false)->after('is_central_warehouse');
            }
            if (! Schema::hasColumn('users', 'featured_sort')) {
                $table->unsignedInteger('featured_sort')->default(0)->after('is_featured_wholesaler');
            }
        });

        if (! Schema::hasTable('security_audit_logs')) {
            Schema::create('security_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 80);
                $table->string('subject_type', 120)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->json('meta')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['action', 'created_at']);
            });
        }

        if (! Schema::hasTable('product_promotions')) {
            Schema::create('product_promotions', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('subtitle')->nullable();
                $table->text('body')->nullable();
                $table->string('media_type', 32)->default('image'); // image|video|comparison
                $table->string('image_path')->nullable();
                $table->string('comparison_image_path')->nullable();
                $table->string('video_url')->nullable();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('product_url')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_promotions');
        Schema::dropIfExists('security_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'featured_sort')) {
                $table->dropColumn('featured_sort');
            }
            if (Schema::hasColumn('users', 'is_featured_wholesaler')) {
                $table->dropColumn('is_featured_wholesaler');
            }
        });

        if (Schema::hasTable('invoice_returns')) {
            Schema::table('invoice_returns', function (Blueprint $table) {
                if (Schema::hasColumn('invoice_returns', 'decision_audit')) {
                    $table->dropColumn('decision_audit');
                }
                if (Schema::hasColumn('invoice_returns', 'decided_at')) {
                    $table->dropColumn('decided_at');
                }
                if (Schema::hasColumn('invoice_returns', 'decided_by')) {
                    $table->dropConstrainedForeignId('decided_by');
                }
            });
        }
    }
};
