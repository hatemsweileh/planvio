<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Translates the English names Planvio ships in `config('planvio.defaults')` — board
 * columns, project stages and starter tags — at the moment a workspace is created.
 *
 * ## Why not simply __($name)
 *
 * Because a bare `__('Blocked')` resolves against `lang/*.json`, where "Blocked" already
 * belongs to the CSV import wizard and means a row that was refused. Arabic renders that
 * as مرفوضة — "rejected" — which is right for an import report and wrong for a task that
 * is waiting on something. One English word, two meanings, and the shared JSON catalogue
 * cannot hold both.
 *
 * So these names get their own namespace. A key that has no translation falls back to the
 * shipped English rather than printing the key, which means adding a name to the config
 * never breaks a language that has not caught up with it yet.
 *
 * ## Why only at creation
 *
 * These become ordinary editable rows the moment they are written. Re-translating them
 * later, when somebody switches language, would overwrite whatever that workspace renamed
 * them to. A workspace created in Arabic starts with an Arabic board; one created in
 * English is byte-identical to before this class existed.
 */
final class DefaultNames
{
    private const GROUP = 'defaults';

    public static function translate(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return $name;
        }

        $key = self::GROUP.'.'.Str::snake(Str::ascii($name));
        $translated = __($key);

        // Laravel returns the key itself when nothing is defined for it.
        return is_string($translated) && $translated !== $key ? $translated : $name;
    }
}
