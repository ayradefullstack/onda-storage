<?php

return [
    // Shared corporate mail layout (resources/views/mail/corporate.blade.php).
    'republic' => 'الجمهورية الجزائرية الديمقراطية الشعبية',
    'office_name' => 'الديوان الوطني لحقوق المؤلف والحقوق المجاورة',
    'address' => 'Rue du Grand Séminaire, Bologhine, Alger.',
    'copyright' => '© :year ONDA الجزائر. جميع الحقوق محفوظة.',
    'features_title' => 'ضمانات وخدمات الديوان الرسمية',
    'feature1_title' => 'حماية قانونية فورية',
    'feature1_desc' => 'ختم زمني سيادي وتسجيل قانوني لإبداعاتكم الفكرية.',
    'feature2_title' => 'شهادات وتصريحات رقمية',
    'feature2_desc' => 'اطلاع فوري على شهادات الإيداع ومتابعة حقوقكم المالية.',
    'feature3_title' => 'استضافة سيادية ومشفّرة',
    'feature3_desc' => 'بياناتكم وملفاتكم مستضافة على خوادم آمنة في الجزائر.',
    'action_default' => 'تأكيد',
    'warning_title' => 'تنبيه أمني',
    'warning_text' => 'إذا لم تكونوا أصحاب هذا الطلب، فلا يلزمكم أي إجراء. لا تشاركوا أبدًا بيانات الدخول أو روابط الأمان.',

    // MediaFileFailedNotification (to the uploader).
    'file_failed' => [
        'subject' => 'فشلت معالجة إيداعكم — ONDA',
        'title' => 'فشلت معالجة إيداعكم',
        'greeting' => 'مرحبًا،',
        'line_failed' => 'فشلت معالجة ملفكم «:file».',
        'line_team' => 'تم إشعار فريقنا التقني. لا حاجة إلى إعادة إيداع هذا الملف في الوقت الحالي.',
        'line_contact' => 'سنتواصل معكم إذا لزم اتخاذ أي إجراء من جانبكم.',
    ],

    // MediaFileQuarantinedNotification (to admins).
    'file_quarantined' => [
        'subject' => 'ملف في الحجر الصحي — ONDA',
        'title' => 'ملف في الحجر الصحي',
        'greeting' => 'تنبيه للمشرف',
        'line_quarantined' => 'تم وضع الملف «:file» (uuid‎ :uuid‎) في الحجر الصحي بواسطة فحص مضادات الفيروسات.',
        'line_review' => 'تم نقل البيانات ولم تُحذف أبدًا — يلزم مراجعة يدوية قبل أي قرار.',
    ],
];
