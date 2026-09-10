<?php

declare(strict_types=1);

/*
 | Filament reads the panel's text direction from this one translation key
 | (vendor/filament/filament/resources/views/components/layout/base.blade.php),
 | not from the html element Planvio's own layouts set. Without it, /admin renders
 | LTR for an Arabic administrator — who is very likely the person translating
 | Planvio into Arabic in the first place.
 */
return [
    'direction' => 'rtl',
];
