<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->longText('description')->nullable();
            $table->foreignId('status_id')->constrained('task_statuses')->cascadeOnDelete();
            $table->string('priority', 32)->default('medium');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('milestones')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('estimate_minutes')->nullable();
            $table->decimal('position', 20, 10)->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->foreignId('recurring_task_id')->nullable()->constrained('recurring_tasks')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'number']);
            $table->index(['workspace_id', 'assignee_id', 'due_date']);
            $table->index(['project_id', 'status_id', 'position']);
            $table->index(['workspace_id', 'due_date']);
            $table->index('milestone_id');
            $table->index('status_id');
            $table->index('assignee_id');
            $table->index('reporter_id');
            $table->index('parent_id');
            $table->index('recurring_task_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
