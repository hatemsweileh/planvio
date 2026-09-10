<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->index()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('mode', 32);
            $table->string('scope', 32)->default('workspace');
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'user_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
