<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('driver', 32);
            $table->string('base_url')->nullable();
            // Encrypted at the model layer (encrypted cast); text() leaves room for the ciphertext.
            $table->text('api_key')->nullable();
            $table->string('model', 191);
            $table->string('fallback_model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(60);
            $table->json('headers')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
