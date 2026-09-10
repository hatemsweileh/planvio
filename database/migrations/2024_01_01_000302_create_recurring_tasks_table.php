<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->json('template');
            $table->string('frequency', 32);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->json('by_weekday')->nullable();
            $table->json('by_monthday')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_run_on')->nullable();
            $table->date('last_run_on')->nullable();
            $table->unsignedInteger('occurrences_generated')->default(0);
            $table->unsignedInteger('max_occurrences')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active', 'next_run_on']);
            $table->index('project_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_tasks');
    }
};
