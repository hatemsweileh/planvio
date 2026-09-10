<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما تكون قيمة :other هي :value.',
    'active_url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخًا بعد :date أو مساويًا له.',
    'alpha' => 'يجب ألّا يحتوي :attribute إلا على حروف.',
    'alpha_dash' => 'يجب ألّا يحتوي :attribute إلا على حروف وأرقام وشرطات وشرطات سفلية.',
    'alpha_num' => 'يجب ألّا يحتوي :attribute إلا على حروف وأرقام.',
    'any_of' => 'قيمة :attribute غير صالحة.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'array_keys' => 'يجب ألّا يحتوي :attribute إلا على المفاتيح التالية: :values.',
    'ascii' => 'يجب ألّا يحتوي :attribute إلا على حروف وأرقام ورموز أحادية البايت.',
    'base64' => 'يجب أن يكون :attribute نصًّا صحيحًا بترميز Base64.',
    'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخًا قبل :date أو مساويًا له.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و:max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و:max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و:max حرفًا.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صوابًا أو خطأً.',
    'can' => 'يحتوي :attribute على قيمة غير مصرّح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'يجب أن يكون :attribute تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون :attribute تاريخًا مساويًا لـ :date.',
    'date_format' => 'يجب أن يطابق :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal من المنازل العشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما تكون قيمة :other هي :value.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يتكوّن :attribute من :digits من الأرقام.',
    'digits_between' => 'يجب أن يتكوّن :attribute من عدد أرقام بين :min و:max.',
    'dimensions' => 'أبعاد الصورة في :attribute غير صالحة.',
    'distinct' => 'يحتوي :attribute على قيمة مكرّرة.',
    'doesnt_contain' => 'يجب ألّا يحتوي :attribute على أيٍّ مما يلي: :values.',
    'doesnt_end_with' => 'يجب ألّا ينتهي :attribute بأيٍّ مما يلي: :values.',
    'doesnt_start_with' => 'يجب ألّا يبدأ :attribute بأيٍّ مما يلي: :values.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًّا صحيحًا.',
    'encoding' => 'يجب أن يكون ترميز :attribute هو :encoding.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد ما يلي: :values.',
    'enum' => 'القيمة المختارة في :attribute غير صالحة.',
    'exists' => 'القيمة المختارة في :attribute غير صالحة.',
    'extensions' => 'يجب أن يكون امتداد :attribute أحد ما يلي: :values.',
    'file' => 'يجب أن يكون :attribute ملفًّا.',
    'filled' => 'يجب ألّا يكون :attribute فارغًا.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value من العناصر.',
        'file' => 'يجب أن يزيد حجم :attribute عن :value كيلوبايت.',
        'numeric' => 'يجب أن تزيد قيمة :attribute عن :value.',
        'string' => 'يجب أن يزيد طول :attribute عن :value حرفًا.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value من العناصر أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكثر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكثر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أكثر.',
    ],
    'hex_color' => 'يجب أن يكون :attribute لونًا سداسيًّا عشريًّا صحيحًا.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة في :attribute غير صالحة.',
    'in_array' => 'يجب أن يكون :attribute موجودًا ضمن :other.',
    'in_array_keys' => 'يجب أن يحتوي :attribute على مفتاح واحد على الأقل مما يلي: :values.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون :attribute نصَّ JSON صحيحًا.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بأحرف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value من العناصر.',
        'file' => 'يجب أن يقلّ حجم :attribute عن :value كيلوبايت.',
        'numeric' => 'يجب أن تقلّ قيمة :attribute عن :value.',
        'string' => 'يجب أن يقلّ طول :attribute عن :value حرفًا.',
    ],
    'lte' => [
        'array' => 'يجب ألّا يحتوي :attribute على أكثر من :value من العناصر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أقل.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أقل.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أقل.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحًا.',
    'max' => [
        'array' => 'يجب ألّا يحتوي :attribute على أكثر من :max من العناصر.',
        'file' => 'يجب ألّا يزيد حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألّا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألّا يزيد طول :attribute عن :max حرفًا.',
    ],
    'max_digits' => 'يجب ألّا يزيد عدد أرقام :attribute عن :max.',
    'mimes' => 'يجب أن يكون :attribute ملفًّا من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفًّا من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min من العناصر على الأقل.',
        'file' => 'يجب ألّا يقلّ حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألّا تقلّ قيمة :attribute عن :min.',
        'string' => 'يجب ألّا يقلّ طول :attribute عن :min حرفًا.',
    ],
    'min_digits' => 'يجب ألّا يقلّ عدد أرقام :attribute عن :min.',
    'missing' => 'يجب ألّا يُرسَل :attribute.',
    'missing_if' => 'يجب ألّا يُرسَل :attribute عندما تكون قيمة :other هي :value.',
    'missing_unless' => 'يجب ألّا يُرسَل :attribute ما لم تكن قيمة :other هي :value.',
    'missing_with' => 'يجب ألّا يُرسَل :attribute عند وجود :values.',
    'missing_with_all' => 'يجب ألّا يُرسَل :attribute عند وجود :values جميعًا.',
    'multiple_of' => 'يجب أن يكون :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'password' => [
        'letters' => 'يجب أن يحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهر :attribute الذي أدخلته في تسريب بيانات. اختر :attribute مختلفًا.',
    ],
    'present' => 'يجب إرسال :attribute.',
    'present_if' => 'يجب إرسال :attribute عندما تكون قيمة :other هي :value.',
    'present_unless' => 'يجب إرسال :attribute ما لم تكن قيمة :other هي :value.',
    'present_with' => 'يجب إرسال :attribute عند وجود :values.',
    'present_with_all' => 'يجب إرسال :attribute عند وجود :values جميعًا.',
    'prohibited' => ':attribute غير مسموح به.',
    'prohibited_if' => ':attribute غير مسموح به عندما تكون قيمة :other هي :value.',
    'prohibited_if_accepted' => ':attribute غير مسموح به عند قبول :other.',
    'prohibited_if_declined' => ':attribute غير مسموح به عند رفض :other.',
    'prohibited_unless' => ':attribute غير مسموح به ما لم تكن قيمة :other ضمن :values.',
    'prohibits' => 'وجود :attribute يمنع إرسال :other.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => ':attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على مدخلات لـ: :values.',
    'required_if' => ':attribute مطلوب عندما تكون قيمة :other هي :value.',
    'required_if_accepted' => ':attribute مطلوب عند قبول :other.',
    'required_if_declined' => ':attribute مطلوب عند رفض :other.',
    'required_unless' => ':attribute مطلوب ما لم تكن قيمة :other ضمن :values.',
    'required_with' => ':attribute مطلوب عند وجود :values.',
    'required_with_all' => ':attribute مطلوب عند وجود :values جميعًا.',
    'required_without' => ':attribute مطلوب عند غياب :values.',
    'required_without_all' => ':attribute مطلوب عند غياب :values جميعًا.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size من العناصر.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفًا.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد ما يلي: :values.',
    'string' => 'يجب أن يكون :attribute نصًّا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => ':attribute مستخدَم من قبل.',
    'uploaded' => 'أخفق رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بأحرف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحًا.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحًا.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
