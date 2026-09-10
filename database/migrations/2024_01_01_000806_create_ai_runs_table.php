<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->cascadeOnDelete();
            // Optional references are nulled rather than cascaded so the audit trail outlives
            // the conversation, the acting user account and the provider configuration.
            $table->foreignId('ai_conversation_id')->nullable()->index()->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('trigger', 32);
            $table->string('mode', 32);
            $table->text('objective')->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedSmallInteger('steps')->default(0);
            $table->unsignedSmallInteger('tool_call_count')->default(0);
            $table->unsignedTinyInteger('error_count')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->string('model', 191)->nullable();
            $table->foreignId('ai_provider_id')->nullable()->index()->constrained('ai_providers')->nullOnDelete();
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('ai_automation_id')->nullable()->index()->constrained('ai_automations')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
