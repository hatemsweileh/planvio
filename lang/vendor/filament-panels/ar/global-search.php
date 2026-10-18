<?php

declare(strict_types=1);

/*
 | The packaged Arabic global-search file predates the instructions, the
 | results label and the failure copy added in Filament 5.10. Merged over
 | the package file, so the keys that already exist upstream are not repeated.
 */
return [
    'field' => [
        'instructions' => 'اضغط سهم الأسفل لاستعراض النتائج. استخدم سهمي الأعلى والأسفل للتنقل بين النتائج، وTab للوصول إلى إجراءات النتيجة. اضغط Escape لإغلاق النتائج.',
    ],

    'results_label' => 'نتائج البحث العام',

    'results_count' => '{1} تُعرض نتيجة واحدة.|{2} تُعرض نتيجتان.|[3,10] تُعرض :count نتائج.|[11,*] تُعرض :count نتيجة.',

    'loading_message' => 'جارٍ البحث...',

    'failure_message' => 'لم يكتمل البحث. أعد تحميل الصفحة للمحاولة مرة أخرى.',
];
