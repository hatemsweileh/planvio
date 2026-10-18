<?php

declare(strict_types=1);

/*
 | Filament 5.10 added column-manager and reorder strings that the packaged
 | Arabic file does not have yet. Laravel merges this file over the package
 | one, so only the missing keys live here.
 */
return [
    'column_manager' => [
        'position' => 'الموضع :position من :count.',
        'position_in_group' => 'الموضع :position من :count في :group.',
        'reordered' => 'نُقل :column إلى الموضع :position من :count.',
        'reordered_deferred' => 'نُقل :column إلى الموضع :position من :count. طبّق الأعمدة لحفظ التغييرات.',
        'reordered_in_group' => 'نُقل :column إلى الموضع :position من :count في :group.',
        'reordered_in_group_deferred' => 'نُقل :column إلى الموضع :position من :count في :group. طبّق الأعمدة لحفظ التغييرات.',
        'actions' => [
            'move_up' => [
                'label' => 'نقل :column للأعلى',
            ],
            'move_down' => [
                'label' => 'نقل :column للأسفل',
            ],
        ],
    ],

    'columns' => [
        'editable' => [
            'saving' => 'جارٍ حفظ التغيير...',
            'saved' => 'تم حفظ التغيير.',
            'failure' => 'تعذر تأكيد الحفظ. حدّث الصفحة قبل المحاولة مرة أخرى.',
            'unavailable' => 'لم يعد بالإمكان تعديل هذا العنصر.',
        ],
    ],

    'actions' => [
        'move_record_up' => [
            'label' => 'نقل :label للأعلى',
        ],
        'move_record_down' => [
            'label' => 'نقل :label للأسفل',
        ],
    ],

    'reordering' => [
        'position' => ':label، الموضع :position من :count.',
        'moved' => 'نُقل :label إلى الموضع :position من :count.',
    ],
];
