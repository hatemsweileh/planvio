<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('minutes');
            $table->string('description')->nullable();
            $table->date('spent_on');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('is_running')->default(false);
            $table->boolean('is_billable')->default(true);
            $table->timestamps();

            $table->index('task_id');
            $table->index('user_id');
            $table->index(['workspace_id', 'user_id', 'spent_on']);
            $table->index(['project_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
