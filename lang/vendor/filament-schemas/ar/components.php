<?php

declare(strict_types=1);

/*
 | Filament 5.10 added the overflow label for tabs after the packaged Arabic
 | file. Laravel merges this file over the package one, so only the missing
 | key lives here.
 */
return [
    'tabs' => [
        'actions' => [
            'more' => [
                'label' => 'مزيد من علامات التبويب',
            ],
        ],
    ],
];
