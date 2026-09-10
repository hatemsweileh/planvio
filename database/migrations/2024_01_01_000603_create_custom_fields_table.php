<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // A null project_id makes the field available across the whole workspace.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('entity', 32)->default('task');
            $table->string('name');
            $table->string('key', 64);
            $table->string('type', 32);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('project_id');
            $table->unique(['workspace_id', 'project_id', 'entity', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
    }
};
