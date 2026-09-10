<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_automations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('trigger_type', 32);
            $table->string('schedule_cron', 64)->nullable();
            $table->string('event', 64)->nullable();
            $table->text('objective');
            $table->string('mode', 32)->default('copilot');
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_status')->nullable();
            $table->timestamp('next_run_at')->nullable();
            // Overlap prevention: a tick claims the automation by writing lock_token + locked_until.
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_automations');
    }
};
