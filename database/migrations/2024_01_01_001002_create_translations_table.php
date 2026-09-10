<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator-editable translations, merged over the shipped files at load time by
 * App\Services\Translation\DatabaseTranslationLoader.
 *
 * ## Why `key` is text and `key_hash` exists
 *
 * Almost every key in Planvio is the English sentence itself — `__('Create project')`,
 * `__('This task already depends on that one.')`. Those run to a few hundred characters,
 * and MySQL cannot put a column that wide into an index: the 3072-byte limit is reached
 * long before the longest string in the catalogue. So the readable key is stored in `text`
 * for people, and `key_hash` — sha256 of `group|key` — carries the uniqueness and the
 * lookups. The group is inside the hash so the same item name under two groups cannot
 * collide.
 *
 * ## `group` is null for the JSON namespace
 *
 * Laravel has two catalogues: `lang/<locale>/<group>.php`, addressed as `group.item`, and
 * `lang/<locale>.json`, addressed by the literal string. A null `group` is the JSON one.
 *
 * That has one consequence worth stating plainly: MySQL treats NULLs as distinct in a
 * unique index, so the constraint below does not stop two rows sharing a locale and a
 * key_hash while both have a null group. Writes therefore go through
 * App\Services\Translation\TranslationRepository::put(), which resolves the existing
 * row first rather than relying on the index. The index still earns its place — it holds
 * for every grouped row and it is the lookup path for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 12);
            $table->string('group', 64)->nullable();
            $table->text('key');
            $table->char('key_hash', 64);

            // Null is "known but not translated yet", which is what `lang:sync` writes and
            // what the loader skips. An empty string would instead blank the shipped text.
            $table->longText('value')->nullable();

            $table->boolean('is_reviewed')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['locale', 'group', 'key_hash']);
            $table->index(['locale', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
