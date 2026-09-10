<?php

declare(strict_types=1);

return [

    'workspace_role' => [
        'owner' => 'المالك',
        'admin' => 'مسؤول',
        'manager' => 'مدير',
        'member' => 'عضو',
        'guest' => 'ضيف',
    ],

    'project_role' => [
        'manager' => 'مدير المشروع',
        'member' => 'عضو',
        'guest' => 'ضيف',
    ],

    'permission' => [
        'workspace' => [
            'view' => 'عرض مساحة العمل',
            'manage' => 'إدارة مساحة العمل',
            'delete' => 'حذف مساحة العمل',
        ],
        'project' => [
            'view' => 'عرض المشاريع',
            'create' => 'إنشاء المشاريع',
            'update' => 'تعديل المشاريع',
            'delete' => 'حذف المشاريع',
            'archive' => 'أرشفة المشاريع',
            'manage_members' => 'إدارة أعضاء المشروع',
        ],
        'task' => [
            'view' => 'عرض المهام',
            'create' => 'إنشاء المهام',
            'update' => 'تعديل المهام',
            'delete' => 'حذف المهام',
            'assign' => 'إسناد المهام',
            'comment' => 'التعليق على المهام',
        ],
        'milestone' => [
            'view' => 'عرض المعالم الرئيسية',
            'manage' => 'إدارة المعالم الرئيسية',
        ],
        'time' => [
            'log' => 'تسجيل الوقت',
            'view_all' => 'عرض الوقت الذي سجّله الجميع',
        ],
        'budget' => [
            'view' => 'عرض الميزانيات والمصروفات',
            'manage' => 'إدارة الميزانيات والمصروفات',
        ],
        'wiki' => [
            'view' => 'عرض صفحات الويكي',
            'manage' => 'إدارة صفحات الويكي',
        ],
        'attachment' => [
            'upload' => 'رفع المرفقات',
            'delete' => 'حذف المرفقات',
        ],
        'reports' => [
            'view' => 'عرض التقارير',
        ],
        'settings' => [
            'manage' => 'إدارة إعدادات مساحة العمل',
        ],
        'users' => [
            'manage' => 'إدارة الأعضاء والدعوات',
        ],
        'webhooks' => [
            'manage' => 'إدارة Webhooks',
        ],
        'templates' => [
            'manage' => 'إدارة قوالب المشاريع',
        ],
        'ai' => [
            'use' => 'استخدام مساعد الذكاء الاصطناعي',
            'manage' => 'إدارة إعدادات الذكاء الاصطناعي',
            'autonomous' => 'تشغيل الذكاء الاصطناعي في الوضع المستقل',
            'manage_policies' => 'إدارة سياسات الذكاء الاصطناعي',
            'view_logs' => 'عرض سجلات نشاط الذكاء الاصطناعي',
            'approve' => 'اعتماد إجراءات الذكاء الاصطناعي',
        ],
    ],

    'permission_group' => [
        'workspace' => 'مساحة العمل',
        'project' => 'المشاريع',
        'task' => 'المهام',
        'milestone' => 'المعالم الرئيسية',
        'time' => 'تتبّع الوقت',
        'budget' => 'الميزانية',
        'wiki' => 'الويكي',
        'attachment' => 'المرفقات',
        'reports' => 'التقارير',
        'settings' => 'الإعدادات',
        'users' => 'المستخدمون',
        'webhooks' => 'خطّافات الويب',
        'templates' => 'القوالب',
        'ai' => 'الذكاء الاصطناعي',
    ],

    'priority' => [
        'none' => 'بلا أولوية',
        'low' => 'منخفضة',
        'medium' => 'متوسطة',
        'high' => 'عالية',
        'urgent' => 'عاجلة',
    ],

    'status_category' => [
        'backlog' => 'الأعمال المتراكمة',
        'todo' => 'للتنفيذ',
        'in_progress' => 'قيد التنفيذ',
        'review' => 'قيد المراجعة',
        'blocked' => 'متوقّفة',
        'done' => 'مكتملة',
        'cancelled' => 'ملغاة',
    ],

    'project_type' => [
        'general' => 'عام',
        'software' => 'برمجيات',
        'marketing' => 'تسويق',
        'operations' => 'عمليات',
        'construction' => 'إنشاءات',
        'event' => 'فعالية',
        'product_launch' => 'إطلاق منتج',
        'hr' => 'موارد بشرية',
        'sales' => 'مبيعات',
        'finance' => 'مالية',
        'research' => 'أبحاث',
        'creative' => 'أعمال إبداعية',
        'client' => 'أعمال العملاء',
    ],

    'project_health' => [
        'on_track' => 'على المسار الصحيح',
        'at_risk' => 'معرّض للخطر',
        'off_track' => 'خارج المسار',
    ],

    'milestone_status' => [
        'planned' => 'مخطّط له',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'مكتمل',
        'delayed' => 'متأخّر',
        'cancelled' => 'ملغى',
    ],

    'dependency_type' => [
        'finish_to_start' => 'من الانتهاء إلى البدء',
        'blocks' => 'يُعطّل',
        'relates_to' => 'مرتبط بـ',
    ],

    'recurrence_frequency' => [
        'daily' => 'يومي',
        'weekly' => 'أسبوعي',
        'monthly' => 'شهري',
        'yearly' => 'سنوي',
        'custom' => 'مخصّص',
    ],

    'author_type' => [
        'user' => 'شخص',
        'ai' => 'ذكاء اصطناعي',
    ],

    'wiki_visibility' => [
        'project' => 'أعضاء المشروع',
        'workspace' => 'جميع من في مساحة العمل',
        'private' => 'أنا فقط',
    ],

    'view_type' => [
        'list' => 'قائمة',
        'board' => 'لوحة',
        'calendar' => 'تقويم',
        'timeline' => 'مخطّط زمني',
    ],

    'custom_field_type' => [
        'text' => 'نص',
        'number' => 'رقم',
        'date' => 'تاريخ',
        'select' => 'قائمة منسدلة',
        'multi_select' => 'اختيار متعدّد',
        'checkbox' => 'مربّع اختيار',
        'url' => 'رابط',
    ],

    'ai_driver' => [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'openai_compatible' => 'نقطة نهاية متوافقة مع OpenAI',
        'custom_http' => 'نقطة نهاية HTTP مخصّصة',
    ],

    /*
     | One English term, one Arabic term. `lang/ar.json` names these modes مساعد, مُرافِق and
     | ذاتي in every sentence that explains them — the onboarding cards, the installer and the
     | help text under the autonomy checkbox — so the labels beside that copy have to be the
     | same words. They were مساعد منفّذ and مستقل, which read as two further modes.
     */
    'ai_mode' => [
        'assistant' => 'مساعد',
        'copilot' => 'مُرافِق',
        'autonomous' => 'ذاتي',
    ],

    'ai_mode_description' => [
        'assistant' => 'يجيب عن الأسئلة ويصوغ المحتوى، ولا يغيّر بياناتك إطلاقًا.',
        'copilot' => 'يقترح التغييرات ثم ينفّذها بعد أن توافق عليها.',
        'autonomous' => 'ينفّذ الإجراءات المسموح بها من تلقاء نفسه، ضمن حدود السياسة التي تضعها.',
    ],

    'ai_scope' => [
        'workspace' => 'مساحة العمل',
        'project' => 'مشروع',
        'task' => 'مهمة',
    ],

    'ai_message_role' => [
        'system' => 'النظام',
        'user' => 'المستخدم',
        'assistant' => 'المساعد',
        'tool' => 'أداة',
    ],

    'ai_trigger' => [
        'chat' => 'محادثة',
        'automation' => 'أتمتة',
        'api' => 'API',
        'system' => 'النظام',
    ],

    'ai_run_status' => [
        'queued' => 'في قائمة الانتظار',
        'running' => 'قيد التشغيل',
        'awaiting_approval' => 'بانتظار الموافقة',
        'succeeded' => 'اكتملت بنجاح',
        'partial' => 'اكتملت جزئيًا',
        'failed' => 'أخفقت',
        'cancelled' => 'أُلغيت',
        'limit_reached' => 'بلغت الحد الأقصى',
    ],

    'tool_run_status' => [
        'pending_approval' => 'بانتظار الموافقة',
        'approved' => 'معتمَد',
        'rejected' => 'مرفوض',
        'succeeded' => 'ناجح',
        'failed' => 'مخفق',
        'skipped' => 'متخطّى',
    ],

    'ai_tool_risk' => [
        'read' => 'قراءة فقط',
        'low' => 'مخاطرة منخفضة',
        'medium' => 'مخاطرة متوسطة',
        'high' => 'مخاطرة عالية',
        'destructive' => 'مخاطرة إتلافية',
    ],

    'ai_tool_risk_description' => [
        'read' => 'يقرأ البيانات فقط، ولا يغيّر شيئًا.',
        'low' => 'يضيف محتوى محدود الأثر مثل التعليقات وقوائم التحقّق والعروض المحفوظة.',
        'medium' => 'ينشئ المشاريع والمهام والمعالم الرئيسية أو يعدّلها.',
        'high' => 'يغيّر إعدادات المشروع أو عضويته أو حالة أرشفته.',
        'destructive' => 'يحذف السجلات أو يزيل أشخاصًا من مساحة العمل.',
    ],

    'ai_memory_scope' => [
        'workspace' => 'مساحة العمل',
        'project' => 'مشروع',
        'user' => 'مستخدم',
        'run' => 'عملية تشغيل واحدة',
    ],

    'ai_memory_source' => [
        'ai' => 'تعلّمه الذكاء الاصطناعي',
        'user' => 'حدّده مستخدم',
        'system' => 'النظام',
    ],

    'automation_trigger' => [
        'schedule' => 'وفق جدول زمني',
        'event' => 'عند وقوع حدث',
    ],

    /*
     * مفردات لوحة الألوان. ليست تعدادًا، لكنها من النوع نفسه من القيم.
     */
    'color' => [
        'brand' => 'لون العلامة',
        'blue' => 'أزرق',
        'indigo' => 'نيلي',
        'teal' => 'فيروزي',
        'green' => 'أخضر',
        'amber' => 'كهرماني',
        'orange' => 'برتقالي',
        'red' => 'أحمر',
        'purple' => 'بنفسجي',
        'pink' => 'وردي',
        'gray' => 'رمادي',
        'muted' => 'باهت',
        'accent' => 'اللون المميّز',
    ],

];
