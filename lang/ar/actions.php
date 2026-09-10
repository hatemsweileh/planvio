<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Domain invariant failures
    |--------------------------------------------------------------------------
    |
    | Actions never authorize — they only refuse work that would leave the data
    | in a state the product cannot represent. These are the messages that come
    | back when one of those invariants is broken, so they are written for the
    | person who tripped it rather than for a log file.
    |
    */

    'tasks' => [
        'status_not_in_project' => 'هذه الحالة تخصّ مشروعًا آخر.',
        'no_status_available' => 'لا يحتوي المشروع :project على أي عمود في اللوحة لوضع المهمة فيه.',
        'assignee_not_in_workspace' => ':name ليس عضوًا في مساحة العمل هذه.',
        'watcher_not_in_workspace' => ':name ليس عضوًا في مساحة العمل هذه.',
        'milestone_not_in_project' => 'هذا المعلم الرئيسي يخصّ مشروعًا آخر.',
        'parent_not_in_project' => 'يجب أن تكون المهمة الفرعية في المشروع نفسه الذي تنتمي إليه المهمة الأصلية.',
        'subtask_cycle' => 'لا يمكن أن تصبح المهمة مهمةً فرعية من إحدى مهامها الفرعية.',
        'checklist_item_not_on_task' => 'هذا البند يخصّ قائمة تحقّق في مهمة أخرى.',
        'due_before_start' => 'لا يمكن أن يسبق تاريخ الاستحقاق تاريخ البدء.',
        'progress_out_of_range' => 'يجب أن يكون التقدّم عددًا صحيحًا بين 0 و100.',
        'estimate_negative' => 'لا يمكن أن يكون التقدير بالسالب.',
        'copy_of' => 'نسخة من :title',
    ],

    'dependencies' => [
        'self_dependency' => 'لا يمكن أن تعتمد المهمة على نفسها.',
        'across_workspaces' => 'يجب أن تنتمي المهمتان إلى مساحة العمل نفسها.',
        'cycle' => 'سيُنشئ هذا الاعتماد حلقة مغلقة: :path.',
    ],

    'tags' => [
        'not_in_workspace' => 'هذا الوسم يخصّ مساحة عمل أخرى.',
        'slug_taken' => 'يوجد بالفعل وسم باسم ":name" في مساحة العمل هذه.',
        'taggable_not_in_workspace' => 'هذا السجل يخصّ مساحة عمل أخرى.',
    ],

    'recurring' => [
        'interval_too_small' => 'يجب ألّا تقلّ فترة التكرار عن 1.',
        'ends_before_start' => 'لا يمكن أن يسبق تاريخ الانتهاء تاريخ البدء.',
        'invalid_weekday' => 'أيام الأسبوع مرقّمة من 1 (الاثنين) إلى 7 (الأحد).',
        'invalid_monthday' => 'يجب أن تكون أيام الشهر بين 1 و31.',
        'max_occurrences_too_small' => 'يجب ألّا يقلّ الحد الأقصى لعدد التكرارات عن 1.',
        'no_occurrence' => 'هذا الجدول لا يُنتج أي تكرار على الإطلاق.',
        'title_required' => 'المهمة المتكرّرة تحتاج إلى عنوان.',
    ],

    'comments' => [
        'empty_body' => 'لا يمكن إرسال تعليق فارغ.',
        'not_commentable' => 'لا يمكن التعليق على هذا السجل.',
        'parent_on_another_subject' => 'يجب أن يبقى الردّ ضمن النقاش الذي يجيب عنه.',
        'parent_is_a_reply' => 'تُكتب الردود على التعليق الأصلي، لا على ردٍّ آخر.',
        'invalid_reaction' => 'هذا التفاعل غير صالح.',
    ],

    'attachments' => [
        'upload_failed' => 'لم يصل هذا الملف سليمًا. حاول مرة أخرى.',
        'unreadable' => 'تعذّرت قراءة هذا الملف.',
        'no_extension' => 'هذا الملف بلا امتداد، لذا تعذّر التحقّق من نوعه.',
        'blocked_extension' => 'الملفات ذات الامتداد :extension غير مقبولة إطلاقًا.',
        'extension_not_allowed' => 'الملفات ذات الامتداد :extension غير مسموح بها هنا.',
        'mime_not_allowed' => 'هذا الملف من نوع :mime، وهو نوع غير مقبول.',
        'mime_mismatch' => 'محتوى هذا الملف من نوع :mime، وهو لا يطابق امتداده :extension.',
        'too_large' => 'حجم هذا الملف :size، وهو يتجاوز الحد المسموح به :limit.',
        'empty_file' => 'هذا الملف فارغ.',
        'store_failed' => 'تعذّر حفظ هذا الملف. تحقّق من مجلد التخزين.',
        'not_attachable' => 'لا يمكن إرفاق ملفات بهذا السجل.',
        'infected' => 'رفض هذا الملفَ ماسحُ البرمجيات الخبيثة على هذا الخادم.',
        'scanner_unavailable' => 'تعذّر فحص هذا الملف بحثًا عن البرمجيات الخبيثة، فلم يُحفَظ. أبلغ المسؤول بأن ماسح الملفات المرفوعة لا يستجيب.',
    ],

    'time' => [
        'minutes_out_of_range' => 'يجب أن يكون الوقت المسجَّل بين دقيقة واحدة و24 ساعة.',
        'not_running' => 'هذا المؤقّت لا يعمل.',
        'is_running' => 'أوقف المؤقّت قبل تعديل السجل.',
        'task_in_another_project' => 'هذه المهمة تخصّ مشروعًا آخر.',
        'future_date' => 'لا يمكن تسجيل الوقت على تاريخ في المستقبل.',
    ],

    'expenses' => [
        'amount_not_positive' => 'يجب أن يكون المصروف أكبر من صفر.',
        'amount_too_large' => 'هذا المبلغ أكبر مما يستطيع Planvio تخزينه.',
        'invalid_currency' => 'العملة رمز من ثلاثة أحرف مثل USD.',
        'future_date' => 'لا يمكن تأريخ المصروف في المستقبل.',
    ],

    'wiki' => [
        'title_required' => 'الصفحة تحتاج إلى عنوان.',
        'self_parent' => 'لا يمكن إدراج الصفحة تحت نفسها.',
        'parent_cycle' => 'لا يمكن إدراج الصفحة تحت إحدى صفحاتها الفرعية.',
        'parent_in_another_project' => 'يجب أن تنتمي الصفحة وصفحتها الأصلية إلى المشروع نفسه.',
        'reorder_mixed_parents' => 'هذه الصفحات ليست كلّها مُدرجة تحت الصفحة الأصلية نفسها.',
    ],

    'views' => [
        'name_required' => 'العرض يحتاج إلى اسم.',
        'project_in_another_workspace' => 'هذا المشروع يخصّ مساحة عمل أخرى.',
        'shared_view_has_no_owner' => 'العرض المشترك يخصّ مساحة العمل، لا شخصًا بعينه.',
    ],

    'custom_fields' => [
        'name_required' => 'الحقل يحتاج إلى اسم.',
        'key_required' => 'الحقل يحتاج إلى مُعرّف مكوّن من حروف أو أرقام أو شرطات سفلية.',
        'key_taken' => 'يوجد هنا بالفعل حقل بالمُعرّف ":key".',
        'invalid_entity' => 'لا يمكن إضافة الحقول المخصّصة إلا إلى المهام والمشاريع.',
        'options_required' => 'حقل :type يحتاج إلى خيار واحد على الأقل.',
        'type_change_with_values' => 'هذا الحقل يحتوي على إجابات بالفعل، لذا لا يمكن تغيير نوعه.',
        'project_in_another_workspace' => 'هذا المشروع يخصّ مساحة عمل أخرى.',
        'field_inactive' => 'لم يعد :field مستخدَمًا.',
        'entity_mismatch' => 'لا ينطبق :field على هذا السجل.',
        'entity_out_of_scope' => ':field غير متاح على هذا السجل.',
        'value_required' => ':field مطلوب.',
        'value_not_an_option' => '":value" ليس من خيارات :field.',
        'value_not_text' => 'يتوقّع :field نصًّا.',
        'value_not_a_number' => 'يتوقّع :field رقمًا.',
        'value_not_a_date' => 'يتوقّع :field تاريخًا.',
        'value_not_a_url' => 'يتوقّع :field عنوان ويب يبدأ بـ http:// أو https://.',
        'value_too_long' => 'الإجابة المُدخلة في :field أطول من المسموح.',
    ],

];
