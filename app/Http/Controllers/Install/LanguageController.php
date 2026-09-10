<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which language the installation wizard is read in.
 *
 * The wizard runs before `migrate`, so there is no `locales` table to choose from and no
 * account to hold a preference. What it offers instead is {@see Locale::SHIPPED} — the
 * catalogues inside the release, which are also exactly what the first seed will write —
 * and what it stores is a session value {@see SetLocale} reads back on the next request.
 *
 * A POST rather than a `?lang=` link, for the reason {@see SetLocale} gives: a language is a
 * stored preference, and a link that changed it would let one URL decide what the form on
 * the other side of it says. It also means the switch cannot be triggered by a prefetcher.
 *
 * An unknown code is ignored rather than refused. This is the control somebody reaches for
 * when they cannot read the screen; answering it with a validation error in the language
 * they just said they do not read would be the wrong end of the trade.
 */
final class LanguageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $code = $request->input('locale');

        if (is_string($code) && Locale::shipped()->has($code)) {
            $request->session()->put(SetLocale::INSTALLER_SESSION_KEY, $code);
        }

        return back(status: Response::HTTP_SEE_OTHER, fallback: route('install.welcome'));
    }
}
