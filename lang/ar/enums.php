<?php

declare(strict_types=1);

// Labels of the App\Enums\** cases: enums.<model>.<attribute>.<key>.
// Kept in step with resources/js/locales/ar.json where the UI shows the same status.

return [
    'oeuvre' => [
        'status' => [
            'draft' => 'مسودة',
            'submitted' => 'قيد الانتظار',
            'under_review' => 'قيد المراجعة',
            'registered' => 'مسجَّل',
            'rejected' => 'مرفوض',
        ],
    ],
    'media_file' => [
        'status' => [
            'uploading' => 'جارٍ الرفع',
            'assembling' => 'جارٍ الإنهاء',
            'scanning' => 'جارٍ الفحص',
            'processing' => 'جارٍ التحضير',
            'ready' => 'تم الإيداع',
            'failed' => 'فشل',
            'quarantined' => 'قيد المراجعة',
        ],
        'review_status' => [
            'pending' => 'قيد الانتظار',
            'accepted' => 'مقبول',
            'rejected' => 'مرفوض',
        ],
    ],
    'upload_session' => [
        'status' => [
            'pending' => 'في الانتظار',
            'uploading' => 'جارٍ الرفع',
            'aborted' => 'أُلغي',
            'completed' => 'تم الرفع',
        ],
    ],
    'media_variant' => [
        'kind' => [
            'poster' => 'ملصق',
            'preview' => 'مقتطف',
            'waveform' => 'شكل الموجة',
            'thumbnail' => 'صورة مصغّرة',
        ],
    ],
    'file_access_log' => [
        'action' => [
            'stream' => 'تشغيل',
            'download' => 'تنزيل',
            'preview' => 'معاينة',
            'delete' => 'حذف',
            'purge' => 'إزالة نهائية',
            'deposit' => 'إيداع',
            'inspect' => 'فحص',
            'admin_consult' => 'اطّلاع الموظف',
            'bytes_released' => 'تحرير البيانات',
            'bytes_kept' => 'الاحتفاظ بالبيانات',
            'bytes_missing' => 'بيانات مفقودة',
            'consult_original' => 'الاطّلاع على الأصل',
        ],
    ],
    'media_consultation' => [
        'status' => [
            'pending' => 'قيد التحضير',
            'ready' => 'جاهز',
            'failed' => 'فشل',
            'unsupported' => 'غير متاح',
        ],
    ],
    'language' => [
        'direction' => [
            'ltr' => 'من اليسار إلى اليمين',
            'rtl' => 'من اليمين إلى اليسار',
        ],
    ],
    'type_gestion' => [
        'type_gestion' => [
            'collective' => 'تسيير جماعي',
            'individual' => 'تسيير فردي',
            'simple' => 'حماية بسيطة',
        ],
    ],
    'referentiel' => [
        'status' => [
            'active' => 'نشط',
            'inactive' => 'غير نشط',
        ],
    ],
];
