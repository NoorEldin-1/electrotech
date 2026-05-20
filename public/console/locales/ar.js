/*
 * القاموس العربي. مرآة لـ en.js بنفس المفاتيح المنقَّطة.
 *
 * المحافظة على نفس البنية مهمة: أي مفتاح مفقود هنا سيظهر للمستخدم
 * بنصِّه الإنجليزي (سلوك tradeoff المتعمَّد للكشف عن النصوص غير
 * المترجمة في QA).
 */
export default {
    language: {
        name: 'العربية',
        switch: 'English',
    },

    title: 'وحدة تحكم المُشغّل',

    enroll: {
        heading: 'تسجيل هذا الجهاز',
        help: 'يحتاج هذا الجهاز إلى إعداد لمرة واحدة ليعمل دون اتصال. تأكّد من تسجيل دخولك إلى لوحة الإدارة في تبويب آخر، ثم اضغط "تسجيل".',
        device_name_label: 'اسم الجهاز (اختياري)',
        device_name_placeholder: 'لوح أرضية المصنع 03',
        submit: 'تسجيل',
        login_hint: 'تأكّد من تسجيل دخولك إلى /admin في تبويب آخر.',
    },

    topbar: {
        connectivity_title: 'حالة الاتصال',
        sync_state_title: 'حالة المزامنة',
        outbox_title: 'العمليات المعلّقة',
        sync_now: 'مزامنة الآن',
        sign_out: 'تسجيل الخروج من هذا الجهاز',
        language_switch: 'تبديل اللغة',
    },

    tabs: {
        work_orders: 'أوامر التشغيل',
        inventory: 'المخزون',
        conflicts: 'التعارضات',
        diagnostics: 'التشخيص',
    },

    connectivity: {
        online: 'متصل',
        weak: 'اتصال ضعيف',
        offline: 'غير متصل',
        unknown: '…',
    },

    sync_state: {
        idle: 'خامل',
        syncing: 'يُزامِن',
        error: 'خطأ',
        unenrolled: 'غير مُسجَّل',
    },

    outbox_count: 'في الانتظار: {n}',

    footer: {
        prefix: 'إصدار يعمل دون اتصال · كل العمليات تُحفظ محلياً · آخر مزامنة: ',
        never: 'لم تتم بعد',
    },

    confirms: {
        sign_out: 'تسجيل الخروج من هذا الجهاز؟ سيتم فقد أي تغييرات لم تتم مزامنتها.',
    },

    toasts: {
        sync_requested: 'تم طلب المزامنة.',
        enrolled: 'تم تسجيل الجهاز. تجري المزامنة الأولية…',
        sync_failed: 'فشلت المزامنة: {error}',
    },

    boot: {
        failed_title: 'فشل تشغيل وحدة تحكم المُشغّل',
    },

    work_orders: {
        statuses: {
            pending: 'قيد الانتظار',
            in_progress: 'قيد التنفيذ',
            qa_review: 'مراجعة الجودة',
            completed: 'مكتمل',
            cancelled: 'ملغى',
        },
        group_header: '{label} ({count})',
        empty_title: 'لا توجد أوامر تشغيل',
        empty_body: 'عند إسناد عمل لك من قِبَل المشرف، سيظهر هنا — سواء متصلاً أو غير متصل.',
        meta: {
            number: '#{n}',
            planned: 'المخطَّط: {n}',
            produced: 'المُنتَج: {n}',
            pending_sync: '⏳ بانتظار المزامنة',
        },
        actions: {
            start: 'بدء',
            submit_qa: 'تسليم لمراجعة الجودة',
            complete: 'اعتماد الجودة وإتمام',
            syncing: 'يُزامِن…',
        },
        prompts: {
            start_confirm: 'بدء العمل على "{title}"؟',
            produced_quantity: 'الكمية المُنتَجة لأمر التشغيل {wo_number} (المخطَّط {planned}):',
            waste_quantity: 'كمية الهدر:',
            qa_notes: 'ملاحظات مراجعة الجودة لأمر التشغيل {wo_number} (اختياري):',
            quantity_invalid: 'يجب أن تكون الكميات أرقاماً غير سالبة.',
        },
        toasts: {
            started: 'تم بدء أمر التشغيل {wo_number} (في انتظار المزامنة).',
            submitted: 'تم التسليم لمراجعة الجودة (في انتظار المزامنة).',
            completed: 'تم اعتماد الجودة وإتمام الأمر (في انتظار المزامنة).',
            queue_failed: 'تعذّر وضع العملية في الطابور: {error}',
        },
    },

    inventory: {
        search_placeholder: 'ابحث بالاسم أو رمز SKU…',
        empty_title: 'لا توجد أصناف بعد',
        empty_body: 'سيظهر سجل الأصناف هنا بعد المزامنة التالية.',
        no_matches: 'لا توجد نتائج.',
        sku_label: 'رمز SKU: {sku}',
        available: 'المتاح: {n}',
        on_hand: 'الموجود: {n}',
        on_hold: 'المحجوز: {n}',
        min: 'الحد الأدنى: {n}',
        units_fallback: 'وحدة',
        actions: {
            out: 'استهلاك',
            in: 'استلام',
            hold: 'حجز',
            release: 'تحرير',
        },
        prompts: {
            quantity: '{label} كم من {name}؟ (بوحدة {unit})',
            notes: 'ملاحظات (اختياري):',
            quantity_invalid: 'يجب أن تكون الكمية رقماً موجباً.',
        },
        toasts: {
            queued: '{label} {qty} {unit} من {name} (في انتظار المزامنة).',
        },
    },

    conflicts: {
        empty_title: 'لا توجد تعارضات',
        empty_body: 'تمت مزامنة كل ما قمت به حتى الآن بنجاح.',
        intro: 'رفض الخادم العمليات أدناه. راجعها وأكّد معالجتها بعد إعادة المحاولة (بالبيانات الحالية) أو تصعيد المسألة للمشرف.',
        detected_at: 'في {date}',
        details: 'التفاصيل',
        acknowledge: 'تأكيد المعالجة',
        acknowledged_toast: 'تم تأكيد معالجة التعارض.',
        labels: {
            you_tried: 'ما حاولتَ',
            base_version: 'الإصدار الأساسي',
            server_version: 'إصدار الخادم',
            server_state: 'حالة الخادم',
            error: 'الخطأ',
        },
        reasons: {
            version_stale: 'قام مستخدم آخر بتحديث هذا السجل أثناء انقطاع اتصالك. تم تجاهل تعديلك المحلي لصالح آخر حالة على الخادم.',
            illegal_transition: 'رفض الخادم تغيير الحالة — عادةً لأن أمر التشغيل تجاوز هذه الخطوة بالفعل.',
            insufficient_stock: 'لا يحتوي المستودع على مخزون كافٍ لإتمام هذا الاستهلاك.',
            validation_failed: 'رفض الخادم البيانات لكونها غير صالحة.',
            fk_missing: 'سجلٌ مرجعي (صنف، أمر تشغيل، …) لم يعد موجوداً.',
            push_rejected: 'أعاد الخادم خطأ HTTP أثناء الإرسال.',
            tombstoned: 'تم حذف السجل على الخادم.',
        },
    },

    diagnostics: {
        title: 'تشخيص المزامنة',
        actions: {
            sync_now: 'مزامنة الآن',
            force_snapshot: 'إعادة مزامنة كاملة',
            sign_out: 'مسح وتسجيل خروج',
        },
        confirms: {
            force_snapshot: 'سيؤدي هذا إلى حذف البيانات المحلية وإعادة تحميل كل شيء من الخادم. هل تريد المتابعة؟',
            sign_out: 'سيؤدي هذا إلى مسح رمز الجهاز وجميع البيانات المحلية. سيحتاج الجهاز إلى إعادة تسجيل. هل تريد المتابعة؟',
        },
        toasts: {
            sync_done: 'تمت المزامنة بنجاح.',
            sync_failed: 'فشلت المزامنة: {error}',
            snapshot_done: 'اكتملت المزامنة الكاملة.',
            snapshot_failed: 'فشلت المزامنة الكاملة: {error}',
        },
    },
};
