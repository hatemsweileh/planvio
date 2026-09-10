<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('wiki_pages')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->string('excerpt')->nullable();
            $table->integer('position')->default(0);
            $table->string('visibility', 32)->default('project');
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('author_id');
            $table->index('last_edited_by');
            $table->index(['workspace_id', 'project_id']);
            $table->unique(['project_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
    }
};
