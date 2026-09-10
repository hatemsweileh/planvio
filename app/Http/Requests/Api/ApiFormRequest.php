<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The base for every API write request.
 *
 * ## Why `authorize()` always returns true here
 *
 * It is not a gap. A form request is resolved before the controller runs, which is before
 * the record the write targets has been looked up — and looking it up here would mean doing
 * the workspace-scoped lookup twice, in two places, with two chances to get it wrong. So
 * authorization happens in the controller, on the record, through the model's own policy,
 * exactly the way the Livewire components do it (ARCHITECTURE.md §4.3). These classes decide
 * only what the *shape* of a request is.
 *
 * The framework already converts a validated value into a `Carbon`, a backed enum or a bool
 * (`$this->date()`, `$this->enum()`, `$this->boolean()`), so nothing here re-implements that.
 * What it does add is the distinction a PATCH lives on: a key sent as `null` clears a column,
 * and a key that is absent leaves it standing. `integer()` returns 0 for both, which is why
 * {@see self::nullableInt()} exists.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether the caller mentioned this key at all — the question a PATCH asks before it
     * touches anything.
     */
    protected function mentions(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * A trimmed string, or null for absent, non-string and blank alike. A title of three
     * spaces is not a title.
     */
    protected function trimmed(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * An integer, or null when the key is absent or explicitly null — as distinct from
     * `integer()`, which folds both onto 0.
     */
    protected function nullableInt(string $key): ?int
    {
        $value = $this->input($key);

        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (int) $value;
    }
}
