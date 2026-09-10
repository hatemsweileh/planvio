<?php

declare(strict_types=1);

namespace App\Support;

/**
 * White-label identity for the whole product surface.
 *
 * Resolution order per value: the stored setting an administrator has changed, then the
 * shipped default in `config('planvio.brand.*')`, then a hard-coded last resort.
 *
 * The middle rung used to read `config('branding.*')`, and there is no `config/branding.php`
 * — so every value fell through to the constants below, and the `brand` block in
 * `config/planvio.php` (which says in as many words that Branding falls back to it) did
 * nothing at all. The visible cost was the installation name: the wizard asks for it, writes
 * it to `APP_NAME`, the admin panel shows it, and the product answered "Planvio" on every
 * page. The asset constants were worse than unused — they pointed into `public/design/`,
 * which is not shipped.
 */
final class Branding
{
    public const KEY_NAME = 'branding.name';

    public const KEY_LOGO_LIGHT = 'branding.logo_light';

    public const KEY_LOGO_DARK = 'branding.logo_dark';

    public const KEY_ICON = 'branding.icon';

    public const KEY_FAVICON = 'branding.favicon';

    public const KEY_PRIMARY_COLOR = 'branding.primary_color';

    private const DEFAULT_NAME = 'Planvio';

    private const DEFAULT_LOGO_LIGHT = '/img/brand/planvio-logo-h.svg';

    private const DEFAULT_LOGO_DARK = '/img/brand/planvio-logo-h-inverse.svg';

    private const DEFAULT_ICON = '/img/brand/planvio-mark.svg';

    private const DEFAULT_FAVICON = '/favicon.svg';

    private const DEFAULT_PRIMARY_COLOR = '#3F66B0';

    public function __construct(private readonly Settings $settings) {}

    public function name(): string
    {
        return $this->resolve(self::KEY_NAME, 'planvio.brand.name', self::DEFAULT_NAME);
    }

    /**
     * Logo for light surfaces.
     */
    public function logoLight(): string
    {
        return $this->resolve(self::KEY_LOGO_LIGHT, 'planvio.brand.logo', self::DEFAULT_LOGO_LIGHT);
    }

    /**
     * Logo for dark surfaces.
     */
    public function logoDark(): string
    {
        return $this->resolve(self::KEY_LOGO_DARK, 'planvio.brand.logo_inverse', self::DEFAULT_LOGO_DARK);
    }

    /**
     * Square mark, used where the full lockup does not fit.
     */
    public function icon(): string
    {
        return $this->resolve(self::KEY_ICON, 'planvio.brand.mark', self::DEFAULT_ICON);
    }

    public function favicon(): string
    {
        return $this->resolve(self::KEY_FAVICON, 'planvio.brand.favicon', self::DEFAULT_FAVICON);
    }

    /**
     * Always a `#rgb` or `#rrggbb` literal: the value is inlined into CSS custom properties,
     * so anything else is discarded rather than emitted.
     */
    public function primaryColor(): string
    {
        $color = $this->resolve(self::KEY_PRIMARY_COLOR, 'planvio.brand.primary_color', self::DEFAULT_PRIMARY_COLOR);

        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
            return self::DEFAULT_PRIMARY_COLOR;
        }

        return $color;
    }

    /**
     * @return array{
     *     name: string,
     *     logo_light: string,
     *     logo_dark: string,
     *     icon: string,
     *     favicon: string,
     *     primary_color: string,
     * }
     */
    public function asArray(): array
    {
        return [
            'name' => $this->name(),
            'logo_light' => $this->logoLight(),
            'logo_dark' => $this->logoDark(),
            'icon' => $this->icon(),
            'favicon' => $this->favicon(),
            'primary_color' => $this->primaryColor(),
        ];
    }

    private function resolve(string $settingKey, string $configKey, string $default): string
    {
        $stored = $this->settings->get($settingKey);

        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        $configured = config($configKey);

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $default;
    }
}
