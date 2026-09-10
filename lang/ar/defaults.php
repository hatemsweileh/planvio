<?php

declare(strict_types=1);

/*
 | Starter board columns, project stages and tags for a workspace created in Arabic.
 |
 | "Blocked" is متوقّفة — halted, waiting on something — and deliberately NOT مرفوضة
 | (rejected), which is what the same English word means in the CSV import report. That
 | collision is the reason this group exists.
 */
return [
    // أعمدة لوحة المهام
    'backlog' => 'الأعمال المتراكمة',
    'to_do' => 'للتنفيذ',
    'in_progress' => 'قيد التنفيذ',
    'review' => 'قيد المراجعة',
    'blocked' => 'متوقّفة',
    'completed' => 'مكتملة',
    'cancelled' => 'ملغاة',

    // مراحل المشروع
    'planning' => 'التخطيط',
    'active' => 'نشط',
    'on_hold' => 'معلّق',

    // الوسوم الافتراضية
    'urgent' => 'عاجل',
    'client' => 'عميل',
    'internal' => 'داخلي',
    'design' => 'تصميم',
    'marketing' => 'تسويق',
    'finance' => 'مالية',
    'operations' => 'عمليات',
];
