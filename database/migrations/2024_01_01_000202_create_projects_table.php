<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 12);
            $table->string('slug');
            $table->longText('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('color')->default('#3F66B0');
            $table->string('type', 32);
            $table->foreignId('status_id')->nullable()->constrained('project_statuses')->nullOnDelete();
            $table->string('health', 32)->default('on_track');
            $table->string('health_note')->nullable();
            $table->boolean('health_set_manually')->default(false);
            $table->string('priority', 32)->default('medium');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_name')->nullable();
            $table->string('department')->nullable();
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('task_number_seq')->default(0);
            $table->json('settings')->nullable();
            $table->json('ai_settings')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'key']);
            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'is_archived']);
            $table->index(['workspace_id', 'status_id']);
            $table->index('status_id');
            $table->index('owner_id');
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
