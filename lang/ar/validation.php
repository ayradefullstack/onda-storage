<?php

return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون حقل :attribute نصًا.',
    'integer' => 'يجب أن يكون حقل :attribute عددًا صحيحًا.',
    'email' => 'يجب أن يكون حقل :attribute عنوان بريد إلكتروني صالحًا.',
    'unique' => 'قيمة حقل :attribute مستخدمة بالفعل.',
    'confirmed' => 'تأكيد حقل :attribute غير مطابق.',
    'regex' => 'صيغة حقل :attribute غير صالحة.',
    'exists' => 'القيمة المحددة في حقل :attribute غير صالحة.',
    'max' => [
        'string' => 'يجب ألا يزيد عدد أحرف :attribute على :max.',
    ],
    'min' => [
        'string' => 'يجب ألا يقل عدد أحرف :attribute عن :min.',
    ],
    'password' => [
        'letters' => 'يجب أن يحتوي حقل :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي حقل :attribute على حرف كبير وحرف صغير على الأقل (لاتينيين).',
        'numbers' => 'يجب أن يحتوي حقل :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي حقل :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت قيمة :attribute المدخلة في تسريب للبيانات. يرجى اختيار قيمة أخرى.',
    ],
    'custom' => [
        'first_name_ar' => [
            'regex' => 'يجب أن يحتوي حقل :attribute على أحرف عربية فقط.',
        ],
        'last_name_ar' => [
            'regex' => 'يجب أن يحتوي حقل :attribute على أحرف عربية فقط.',
        ],
        'country_id' => [
            'integer' => 'يرجى اختيار بلد صالح.',
            'exists' => 'البلد المحدد غير صالح.',
        ],
        'wilaya_id' => [
            'integer' => 'يرجى اختيار ولاية صالحة.',
            'required' => 'يرجى اختيار الولاية.',
            'exists' => 'الولاية المحددة غير متاحة للبلد المختار.',
        ],
        'commune_id' => [
            'integer' => 'يرجى اختيار بلدية صالحة.',
            'required' => 'يرجى اختيار البلدية.',
            'exists' => 'البلدية المحددة غير متاحة للولاية المختارة.',
        ],
        'email' => [
            'unique' => 'البريد الإلكتروني مستخدم بالفعل.',
        ],
    ],
    'attributes' => [
        'first_name' => 'الاسم',
        'last_name' => 'اللقب',
        'first_name_ar' => 'الاسم بالعربية',
        'last_name_ar' => 'اللقب بالعربية',
        'country_id' => 'البلد',
        'wilaya_id' => 'الولاية',
        'commune_id' => 'البلدية',
        'city' => 'المدينة',
        'phone' => 'رقم الهاتف',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
    ],
];
