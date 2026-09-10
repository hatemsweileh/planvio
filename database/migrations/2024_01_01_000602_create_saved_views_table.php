<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            // A null user_id marks the view as shared with the whole workspace/project.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('type', 32);
            $table->json('filters');
            $table->json('sorts')->nullable();
            $table->json('columns')->nullable();
            $table->string('group_by')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('project_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
