<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            // Nullable + unique: exactly one row per workspace, plus exactly one global-default
            // row identified by workspace_id IS NULL. Both MySQL/MariaDB and SQLite treat NULL as
            // distinct in a unique index, so the NULL row is never rejected as a duplicate; the
            // application enforces that only one such global row is created.
            // cascadeOnDelete (not nullOnDelete) because nulling a deleted workspace's row would
            // silently promote its configuration to the global default.
            $table->foreignId('workspace_id')->nullable()->unique()->constrained('workspaces')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('ai_provider_id')->nullable()->index()->constrained('ai_providers')->nullOnDelete();
            $table->string('default_mode', 32)->default('assistant');
            $table->text('system_instructions')->nullable();
            $table->string('communication_style', 32)->nullable();
            $table->string('language', 8)->nullable();
            $table->boolean('autonomous_enabled')->default(false);
            $table->unsignedSmallInteger('max_tool_calls_per_run')->default(25);
            $table->unsignedSmallInteger('max_run_seconds')->default(180);
            $table->unsignedInteger('max_runs_per_day')->default(500);
            $table->unsignedTinyInteger('error_threshold')->default(3);
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->boolean('notify_on_action')->default(true);
            $table->boolean('kill_switch_engaged')->default(false);
            $table->string('kill_switch_reason')->nullable();
            $table->timestamp('kill_switch_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
