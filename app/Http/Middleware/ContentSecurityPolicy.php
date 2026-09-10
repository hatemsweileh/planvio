<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\AttachmentController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The application's Content-Security-Policy.
 *
 * ## What this is, said plainly
 *
 * It does **not** prevent cross-site scripting. `script-src` here keeps both
 * `'unsafe-eval'` and `'unsafe-inline'`, so an attacker who gets script into a page still
 * gets it executed. Planvio's XSS controls are elsewhere and are the ones that matter:
 * Blade escapes every interpolation, HTMLPurifier rewrites comment and wiki bodies before
 * they are stored, and uploaded SVGs are parsed and stripped.
 *
 * What this header does is bound the damage. Injected script cannot pull a payload from
 * another origin, cannot post a stolen form or a stolen token anywhere but back to this
 * host, cannot rewrite the document's base URL to make every relative link resolve
 * somewhere else, and cannot instantiate a plugin. The page cannot be framed by a site the
 * administrator does not control. That is a smaller claim than "CSP stops XSS" and it is
 * the true one.
 *
 * ## Why the policy is not nonce-based
 *
 * A nonce only helps if `'unsafe-inline'` can be dropped, and dropping it means every
 * inline script *and* every Alpine expression has to go. Alpine evaluates the contents of
 * `x-on:click`, `x-show`, `x-data` and `:class` at runtime, which requires `'unsafe-eval'`
 * whatever else is done; Livewire's own bootstrap is inline. Rewriting every handler in the
 * product into external modules is a different piece of work from adding a header, and
 * shipping a nonce alongside `'unsafe-inline'` would be theatre — browsers ignore the
 * nonce entirely once the keyword is present.
 *
 * ## Two profiles, the same directives
 *
 * The middleware is applied twice, with a profile argument selecting which block of
 * `planvio.security.csp` it reads:
 *
 *   - `app`   — appended to the `web` group in bootstrap/app.php; the product's own pages.
 *   - `panel` — named in App\Providers\Filament\AdminPanelProvider. A Filament panel does
 *     not run through the `web` group, so without this the surface that edits AI provider
 *     credentials would be the only one in Planvio with no policy at all.
 *
 * The directives are the same for both, deliberately. Filament is Alpine and Livewire too,
 * so a stricter `script-src` there would break the panel rather than harden it, and the
 * panel's own assets are served from `/js/filament` and `/css/filament` on this origin,
 * which `'self'` already covers. What the second profile buys is configuration: the panel
 * can be tightened, put in report-only or switched off independently of the product,
 * because the two surfaces are reviewed by different people at different times. With
 * nothing configured — the shipped state — the panel follows the application's settings.
 *
 * ## Ordering, and the one policy that outranks both
 *
 * The header is written on the way out, and only when the response does not already carry
 * one. {@see AttachmentController} sets `default-src 'none'; sandbox`
 * on every attachment it streams — the strictest policy in the application, on the one
 * response made of bytes a user supplied. That route sits inside the `web` group, so
 * without this check the loose application policy would replace the tight one on exactly
 * the response that needs it most. The panel profile obeys the same rule, so an
 * administrator who opens an attachment from a session established in `/admin` still gets
 * the sandbox rather than the panel's policy.
 *
 * Configured under `planvio.security.csp`.
 */
final class ContentSecurityPolicy
{
    /**
     * The product's own pages: everything the `web` group serves.
     */
    public const APPLICATION = 'app';

    /**
     * The Filament administration panel at `/admin`.
     */
    public const PANEL = 'panel';

    private const HEADER = 'Content-Security-Policy';

    private const REPORT_ONLY_HEADER = 'Content-Security-Policy-Report-Only';

    /**
     * The shipped policy, in the order it is emitted.
     *
     * `img-src` allows `data:` and `blob:` because Livewire renders an upload preview from
     * a blob URL and several icons are inlined as data URIs. `style-src` allows inline
     * styles because the layout writes the workspace accent into a `<style>` block before
     * first paint and Alpine binds inline styles as it toggles state.
     *
     * @var array<string, string>
     */
    private const DIRECTIVES = [
        'default-src' => "'self'",
        'img-src' => "'self' data: blob:",
        'font-src' => "'self'",
        'style-src' => "'self' 'unsafe-inline'",
        'script-src' => "'self' 'unsafe-eval' 'unsafe-inline'",
        'connect-src' => "'self'",
        'frame-ancestors' => "'self'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'object-src' => "'none'",
    ];

    public function handle(Request $request, Closure $next, string $profile = self::APPLICATION): Response
    {
        $response = $next($request);

        $profile = self::profile($profile);

        if (! self::enabled($profile)) {
            return $response;
        }

        // A response that already declares a policy has declared a deliberate one.
        if ($response->headers->has(self::HEADER) || $response->headers->has(self::REPORT_ONLY_HEADER)) {
            return $response;
        }

        $policy = self::policy($profile);

        if ($policy === '') {
            return $response;
        }

        $response->headers->set(self::header($profile), $policy);

        return $response;
    }

    /**
     * The assembled header value, so a test and an administrator can read the same string
     * the browser gets.
     */
    public static function policy(string $profile = self::APPLICATION): string
    {
        $directives = self::DIRECTIVES;

        foreach (self::extraDirectives(self::profile($profile)) as $name => $value) {
            if ($value === '') {
                unset($directives[$name]);

                continue;
            }

            $directives[$name] = $value;
        }

        $parts = [];

        foreach ($directives as $name => $value) {
            $parts[] = $name.' '.$value;
        }

        return implode('; ', $parts);
    }

    public static function header(string $profile = self::APPLICATION): string
    {
        return self::reportOnly(self::profile($profile)) ? self::REPORT_ONLY_HEADER : self::HEADER;
    }

    /**
     * A profile name that is neither of the two falls back to the application policy.
     *
     * Refusing the request, or emitting nothing, would turn a typo in a middleware string
     * into a surface with no policy at all — the exact failure this class exists to close.
     */
    private static function profile(string $profile): string
    {
        return $profile === self::PANEL ? self::PANEL : self::APPLICATION;
    }

    private static function enabled(string $profile): bool
    {
        return filter_var(self::setting($profile, 'enabled') ?? true, FILTER_VALIDATE_BOOL);
    }

    private static function reportOnly(string $profile): bool
    {
        return filter_var(self::setting($profile, 'report_only') ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * One setting, read for one profile.
     *
     * The panel block is an override, not a replacement: a key left null there — which is
     * how it ships — means "whatever the application does", so an installation that turns
     * the policy off, or moves it to report-only, does so on both surfaces without having
     * to know that `/admin` assembles its own stack.
     */
    private static function setting(string $profile, string $key): mixed
    {
        if ($profile === self::PANEL) {
            $override = config('planvio.security.csp.'.self::PANEL.'.'.$key);

            if ($override !== null) {
                return $override;
            }
        }

        return config('planvio.security.csp.'.$key);
    }

    /**
     * Administrator overrides, reduced to the ones that are actually usable.
     *
     * A directive name has to look like a directive name and a value has to be a string:
     * a malformed entry in a hand-edited config file must not be able to produce a header
     * the browser rejects wholesale, which would leave the page with no policy at all.
     *
     * @return array<string, string>
     */
    private static function extraDirectives(string $profile): array
    {
        $configured = self::setting($profile, 'extra_directives') ?? [];

        if (! is_array($configured)) {
            return [];
        }

        $extra = [];

        foreach ($configured as $name => $value) {
            // Directive names are case-insensitive to a browser, so `Style-Src` is honoured
            // rather than silently ignored — an override that does nothing is worse than one
            // that is refused.
            $name = is_string($name) ? mb_strtolower(trim($name)) : '';

            if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
                continue;
            }

            if ($value === null || $value === '') {
                $extra[$name] = '';

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            // Semicolons and control characters would end the directive early and let one
            // value smuggle in another; newlines would split the header. Runs of whitespace
            // collapse afterwards so the emitted header is one a person can read.
            $clean = (string) preg_replace('/[;\x00-\x1F\x7F]+/', ' ', $value);
            $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

            if ($clean !== '') {
                $extra[$name] = $clean;
            }
        }

        return $extra;
    }
}
