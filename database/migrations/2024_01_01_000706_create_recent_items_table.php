<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->morphs('viewable');
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index('workspace_id');
            $table->unique(['user_id', 'viewable_type', 'viewable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_items');
    }
};
