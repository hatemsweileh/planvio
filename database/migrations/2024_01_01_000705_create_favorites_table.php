<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('favoritable');
            $table->integer('position');
            $table->timestamps();

            $table->unique(['user_id', 'favoritable_id', 'favoritable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
