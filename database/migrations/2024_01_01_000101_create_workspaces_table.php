<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('accent_color')->default('#3F66B0');
            $table->string('timezone')->default('UTC');
            $table->string('locale')->default('en');
            $table->char('currency', 3)->default('USD');
            $table->string('date_format')->default('Y-m-d');
            $table->tinyInteger('week_starts_on')->default(1);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->json('settings')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
