<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $table): void {
            $table->id();

            // A null workspace_id marks a system template shipped with Planvio.
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->string('type', 32);
            $table->json('definition');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_templates');
    }
};
