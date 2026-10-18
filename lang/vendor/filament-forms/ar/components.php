<?php

declare(strict_types=1);

/*
 | Filament 5.10 added reorder, upload and select copy that the packaged Arabic
 | file does not have yet. Laravel merges this file over the package one, so
 | only the missing keys live here.
 */
return [
    'builder' => [
        'reordering' => [
            'item' => 'كتلة',
            'position' => ':label، الموضع :position من :count',
            'moved' => 'نُقل :label إلى الموضع :position من :count',
        ],
    ],

    'file_upload' => [
        'placeholder' => 'لم يتم رفع أي ملفات',
    ],

    'key_value' => [
        'actions' => [
            'move_down' => [
                'label' => 'نقل للأسفل',
            ],
            'move_up' => [
                'label' => 'نقل للأعلى',
            ],
        ],
        'reordering' => [
            'row' => 'صف',
            'position' => ':label، الموضع :position من :count',
            'moved' => 'نُقل :label إلى الموضع :position من :count',
        ],
    ],

    'repeater' => [
        'reordering' => [
            'item' => 'عنصر',
            'position' => ':label، الموضع :position من :count',
            'moved' => 'نُقل :label إلى الموضع :position من :count',
        ],
    ],

    'select' => [
        'error_message' => 'تعذر تحميل الخيارات. حاول مرة أخرى.',
        'required_description' => 'اختر خيارًا.',
    ],
];
