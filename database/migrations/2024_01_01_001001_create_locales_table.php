<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The languages this installation offers.
 *
 * `users.locale` and `workspaces.locale` already store a code; this table is what says
 * which codes are real. Nothing outside it may be selected, which is why the resolution in
 * App\Http\Middleware\SetLocale never trusts a stored value on its own — a
 * language removed by an administrator must stop being served, not keep rendering from a
 * column nobody thought to clear.
 *
 * `direction` is stored rather than derived. A hard-coded list of RTL codes is wrong the
 * first time somebody adds a language that is not on it, and the layout needs the answer on
 * every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locales', function (Blueprint $table): void {
            $table->id();

            // BCP-47: `en`, `ar`, `pt-BR`. Twelve characters covers language-script-region.
            $table->string('code', 12)->unique();

            $table->string('name');
            $table->string('native_name');
            $table->string('direction', 3)->default('ltr');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('position')->default(0);

            // Both null mean "inherit the workspace's own formatting". A locale only
            // overrides them when its conventions differ from what the workspace chose.
            $table->string('date_format')->nullable();
            $table->tinyInteger('first_day_of_week')->nullable();

            $table->timestamps();

            $table->index(['is_enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locales');
    }
};
