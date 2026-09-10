<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('tool', 64);
            $table->string('risk', 32);
            // Redacted by App\Ai\Support\Redactor before persisting; never holds secrets.
            $table->json('arguments')->nullable();
            $table->text('result_summary')->nullable();
            $table->string('status', 32);
            $table->boolean('approval_required')->default(false);
            $table->foreignId('approved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->nullableMorphs('subject');
            $table->string('idempotency_key', 80)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['ai_run_id', 'sequence']);
            // Replay guard: a repeated idempotency_key within one run returns the prior result.
            // NULL keys stay distinct under both MySQL and SQLite, so read-only tools are unaffected.
            $table->unique(['ai_run_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_runs');
    }
};
