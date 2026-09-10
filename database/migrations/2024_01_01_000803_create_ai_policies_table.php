<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_policies', function (Blueprint $table): void {
            $table->id();
            // NULL workspace_id = platform-wide policy; NULL project_id = whole-workspace policy.
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('mode', 32)->nullable();
            $table->json('allowed_tools')->nullable();
            $table->json('denied_tools')->nullable();
            $table->json('approval_required_tools')->nullable();
            $table->string('max_risk', 32)->default('medium');
            $table->json('allowed_roles')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_policies');
    }
};
