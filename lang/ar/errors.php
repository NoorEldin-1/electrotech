<?php

declare(strict_types=1);

return [
    'inventory' => [
        'insufficient_stock' => "المخزون غير كافٍ للصنف ':item' في :warehouse. المتاح: :available، المطلوب: :requested.",
        'insufficient_transfer' => "المخزون غير كافٍ للصنف ':item' للتحويل من :warehouse. المتاح: :available، المطلوب: :requested.",
        'insufficient_hold' => "المخزون المتاح غير كافٍ لحجز الصنف ':item' في :warehouse. المتاح: :available، المطلوب: :requested.",
        'cannot_release' => "لا يمكن الإفراج عن :requested من الصنف ':item' في :warehouse. المحجوز حالياً: :held.",
        'same_warehouse' => 'يجب أن يختلف المخزن المصدر عن المخزن الوجهة.',
        'positive_quantity' => 'يجب أن تكون الكمية أكبر من صفر.',
    ],

    'voucher' => [
        'already_posted' => 'الإذن :number مُرحَّل بالفعل.',
        'no_lines' => 'لا توجد أصناف في الإذن :number للترحيل.',
    ],

    'journal' => [
        'already_posted' => 'القيد :number مُرحَّل بالفعل.',
        'no_lines' => 'يجب أن يحتوي القيد :number على سطرين على الأقل.',
        'unbalanced' => 'القيد :number غير متوازن. المدين: :debit، الدائن: :credit.',
    ],

    'issue' => [
        'no_bom' => 'أمر التشغيل :number ليس له قائمة مواد مرتبطة، لذا لا يمكن إنشاء إذن صرف.',
    ],

    'delivery' => [
        'already_active' => 'إذن التسليم :number نشط بالفعل.',
        'cancelled' => 'إذن التسليم :number ملغي.',
        'no_lines' => 'لا توجد أصناف في إذن التسليم :number للتسليم.',
        'cannot_cancel_active' => 'إذن التسليم :number نشط ولا يمكن إلغاؤه.',
    ],

    'work_order' => [
        'cannot_start' => 'لا يمكن بدء أمر التشغيل :number. الحالة الحالية: :status.',
        'no_bom' => 'أمر التشغيل :number ليس له قائمة مواد مرتبطة. لا يمكن صرف الخامات.',
        'not_in_progress' => 'يجب أن يكون أمر التشغيل :number قيد التنفيذ للإرسال لضمان الجودة.',
        'not_pending_qa' => 'أمر التشغيل :number ليس في انتظار مراجعة الجودة.',
        'qa_gate' => 'لا يمكن إتمام أمر التشغيل :number بدون اعتماد الجودة. بوابة الجودة إلزامية.',
        'not_in_qa_review' => 'يجب أن يكون أمر التشغيل :number في مراجعة الجودة للإتمام.',
        'cannot_finish_manufacturing' => 'لا يمكن إنهاء تصنيع أمر التشغيل :number. يجب بدؤه أولاً. الحالة الحالية: :status.',
    ],

    'quality_sheet' => [
        'already_approved' => 'ورقة الجودة :number معتمدة بالفعل ولا يمكن تعديلها.',
        'not_filled' => 'يجب ملء ورقة الجودة :number من قسم الجودة قبل اعتمادها.',
    ],

    'purchase_order' => [
        'cancelled' => 'لا يمكن استلام أصناف لأمر شراء ملغي.',
        'exceeds_ordered' => "لا يمكن استلام :quantity من الصنف ':item'. سيتجاوز الكمية المطلوبة :ordered (المستلَم بالفعل: :received).",
    ],

    'operations' => [
        'illegal_transition' => "انتقال غير مسموح: حالة العملية الحالية ':current'، المتوقع إحدى [:allowed].",
        'claim_before_completion' => 'لا يمكن رفع مطالبة مالية إلا بعد إتمام التوريد والتركيب.',
        'reserve_exceeds_available' => "لا يمكن حجز :quantity من الصنف ':item'. المتاح فقط :available في :warehouse.",
        'facility_exceeds_available' => "لا يمكن تخصيص :amount من التسهيل ':facility'. المتاح فقط :available.",
    ],
];
