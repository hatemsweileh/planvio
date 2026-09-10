<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->string('category', 64)->nullable();
            $table->string('description')->nullable();
            $table->date('incurred_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index('workspace_id');
            $table->index('user_id');
            $table->index(['project_id', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
