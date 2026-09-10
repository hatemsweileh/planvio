<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            // NULL workspace_id / user_id mark platform-wide buckets, so a deleted workspace or
            // user takes its rollup rows with it instead of merging into the platform totals.
            $table->foreignId('workspace_id')->nullable()->index()->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->index()->constrained('ai_providers')->nullOnDelete();
            $table->string('model', 191)->nullable();
            $table->unsignedInteger('runs')->default(0);
            $table->unsignedInteger('tool_calls')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->timestamps();

            // Named explicitly: the generated name would exceed the 64-character MySQL limit.
            $table->unique(
                ['date', 'workspace_id', 'user_id', 'ai_provider_id', 'model'],
                'ai_usage_daily_bucket_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
    }
};
