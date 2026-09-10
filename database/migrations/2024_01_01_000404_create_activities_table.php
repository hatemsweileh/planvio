<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('subject');
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('causer_type', 32)->default('user');

            // Forward reference: `ai_runs` lives in band 000800. The column is declared here
            // and the foreign key is attached by 000901_add_deferred_foreign_keys.
            $table->unsignedBigInteger('ai_run_id')->nullable();

            $table->string('event', 64);
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index('causer_id');
            $table->index('ai_run_id');
            $table->index(['workspace_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
