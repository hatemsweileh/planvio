<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // project_id / user_id NULL mean "not scoped to a project / user", so these cascade
            // rather than null out: nulling would silently widen a memory's scope.
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->string('scope', 32);
            $table->string('key', 120);
            $table->text('content');
            $table->tinyInteger('importance')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->string('source', 32)->default('ai');
            $table->timestamps();

            $table->unique(['workspace_id', 'project_id', 'user_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
    }
};
