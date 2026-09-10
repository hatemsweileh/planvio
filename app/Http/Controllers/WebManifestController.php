<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Locale;
use App\Support\Branding;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, in the language of the person installing it.
 *
 * It used to be `public/site.webmanifest`, a static file — which meant the name under the
 * icon on an Arabic reader's home screen was English, the description was English, and the
 * document carried neither `lang` nor `dir`, so a right-to-left name would have been laid
 * out left to right by the shell that renders it.
 *
 * A file in `public/` cannot fix any of that: with the document root pointed at `public/`
 * the web server answers it before PHP is reached. So the file is gone and this route
 * answers on the same path, which is why `EnsureInstalled` and `MaintenanceMode` have
 * always listed `site.webmanifest` among the paths they let through — a request that never
 * reached the framework would not have needed an exemption.
 *
 * Planvio is not a progressive web app (LIMITATIONS.md): there is no service worker and no
 * offline capability. The manifest is what makes "add to home screen" produce something
 * with the right name and colours, and that much should be in the reader's language.
 */
final class WebManifestController extends Controller
{
    public function __invoke(Branding $branding): JsonResponse
    {
        $locale = app()->getLocale();
        $name = $branding->name();

        /*
         | The shipped tagline goes through the catalogue. One an installation wrote for
         | itself is already in the language that installation chose, and is used as given —
         | a config value cannot be a translation key, because no scanner can find it.
         */
        $tagline = trim((string) config('planvio.brand.tagline', ''));
        $isShipped = $tagline === '' || $tagline === 'Plan the work. Let AI run it.';

        return response()->json([
            'name' => $name,
            'short_name' => $name,
            'description' => $isShipped ? __('Plan the work. Let AI run it.') : $tagline,
            'lang' => str_replace('_', '-', $locale),
            'dir' => Locale::directionFor($locale),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f3f4f7',
            'theme_color' => $branding->primaryColor(),
            'icons' => [
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => '/img/brand/planvio-mark-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json');
    }
}
