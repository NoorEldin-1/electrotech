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
    ],

    'purchase_order' => [
        'cancelled' => 'لا يمكن استلام أصناف لأمر شراء ملغي.',
        'exceeds_ordered' => "لا يمكن استلام :quantity من الصنف ':item'. سيتجاوز الكمية المطلوبة :ordered (المستلَم بالفعل: :received).",
    ],
];
