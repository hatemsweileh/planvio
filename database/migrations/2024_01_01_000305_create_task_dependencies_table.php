<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type', 32)->default('finish_to_start');
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
            $table->index('workspace_id');
            $table->index('depends_on_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
