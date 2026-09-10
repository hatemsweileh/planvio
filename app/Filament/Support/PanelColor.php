<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Translates a domain colour name into one the admin panel has actually registered.
 *
 * Planvio's enums name colours from the product's palette — `brand`, `amber`, `purple`,
 * `teal` — because that is what `resources/css/app.css` and `<x-ui.badge>` understand. The
 * Filament panel registers six: primary, gray, info, success, warning, danger. Handing
 * Filament a name it does not know is not an error; it silently renders an uncoloured badge,
 * which is the worst outcome available — a status column that looks deliberate and conveys
 * nothing.
 *
 * So every enum colour is mapped here, once, and the mapping preserves meaning rather than
 * hue: the question a badge answers in an admin table is "is this fine, worth a look, or
 * wrong", and the six registered colours cover it.
 */
final class PanelColor
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'brand' => 'primary',
        'blue' => 'info',
        'teal' => 'info',
        'cyan' => 'info',
        'indigo' => 'info',
        'purple' => 'primary',
        'violet' => 'primary',
        'pink' => 'primary',
        'green' => 'success',
        'emerald' => 'success',
        'lime' => 'success',
        'amber' => 'warning',
        'orange' => 'warning',
        'yellow' => 'warning',
        'red' => 'danger',
        'rose' => 'danger',
        'gray' => 'gray',
        'grey' => 'gray',
        'slate' => 'gray',
        'zinc' => 'gray',
        'stone' => 'gray',
    ];

    private function __construct() {}

    /**
     * Anything unrecognised becomes gray: a neutral badge is honest, an invisible one is not.
     */
    public static function for(?string $color): string
    {
        if ($color === null) {
            return 'gray';
        }

        return self::MAP[mb_strtolower(trim($color))] ?? 'gray';
    }
}
