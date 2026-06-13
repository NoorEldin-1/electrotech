# خطة تنفيذ تعديلات قسم المبيعات (Sales Department — Modifications Round)

> مصدر المتطلبات: `تعديلات قسم المبيعات.pptx` (11 شريحة) + `تعديلات_قسم_المبيعات_1.md`.
> هذه جولة **مراجعة/تغذية راجعة** على نظام عروض الأسعار الذي بُني بالفعل في الكوميت
> `aeab9f3` (BOQ offers, PDF, lifecycle). ليست بناءً من الصفر.

---

## 1. ملخّص المعمارية الحالية (ما الذي نعدّله)

نظام العرض يتكوّن من ثلاث طبقات:

| الطبقة | الملف | الدور |
|--------|-------|-------|
| نموذج العرض | `app/Models/ProjectOffer.php` | عرض واحد لكل (مشروع، إصدار). يحمل `quotation_number, currency, technical_amount, vat_percentage, show_vat, subtotal, tax_amount, grand_total, terms, notes` |
| جدول العرض | `app/Models/OfferGroup.php` | "Bi-Metal Offer" / "Copper Offer" — عدة جداول لكل عرض. `label, conductor_type, subtotal, sort_order` |
| بند العرض | `app/Models/OfferItem.php` | سطر BOQ: `description, unit, quantity, unit_price, line_total` |
| حساب الإجماليات | `app/Services/OfferTotalsService.php` | بنود → إجمالي الجدول → إجمالي العرض → ضريبة → الإجمالي النهائي |
| الفورم (Filament) | `app/Filament/.../OffersRelationManager.php` | Repeater متداخل (جداول ← بنود) + قسم الرأس + معاينة الإجماليات + الشروط |
| الطباعة (PDF) | `app/Http/Controllers/OfferPdfController.php` + `resources/views/pdf/offer.blade.php` | mpdf، يدعم RTL/LTR، يقرأ اللغة من `app()->getLocale()` |
| السجل (History) | `app/Filament/.../ActivitiesRelationManager.php` | تايملاين spatie/activitylog لتغييرات المشروع فقط |
| التنبيهات | `app/Services/SalesAlertService.php` + `app/Console/Commands/NotifyIncompleteOperations.php` | تنبيه العمليات بلا عرض مُسعّر (يومي 08:00) |
| المرفقات | `app/Models/Attachment.php` + `app/Enums/AttachmentCategory.php` | رفع ملفات لكل فئة داخل `ProjectResource` |
| الترجمة | `lang/en/resources.php` + `lang/ar/resources.php` (مفتاح `project_offers`) | AR/EN، تبديل عبر `bezhansalleh/filament-language-switch` + `SetLocale` middleware |
| الثيم | `app/Providers/Filament/AdminPanelProvider.php` + `resources/css/filament/admin/theme.css` | فاتح/داكن class-based (`.dark`) |

---

## 2. خريطة الشرائح → المتطلبات (تحليل كامل)

| الشريحة | الطلب (مختصر) | الجزء المتأثر | المرحلة |
|---------|----------------|----------------|---------|
| **1** | استفهام عن "المبلغ الفني" + إضافة زرار **إظهار التركيب** كنسبة مثل الضريبة (يختلف بين العملاء) | فورم العرض + الإجماليات + PDF + Schema | المرحلة 3 |
| **2** | إضافة **Water Mark** للشركة على الـ PDF | `OfferPdfController` (mpdf watermark) | المرحلة 1 |
| **3** | الكتابة في الشروط تظهر مُلتصقة رغم كتابتها أسطُر؛ + فصل **شروط خاصة** (خلف الجداول) عن **شروط عامة** (ثابتة) | PDF (أسطر) + فورم + Schema | جزء عاجل في م1، الفصل في م5 |
| **4** | **البيان** غير واضح في الفورم (يجب مراجعته قبل الطباعة) + **الإجمالي يظهر خطأ** (فرق 3p/4p مهم) | فورم البنود + `OfferTotalsService` + PDF | البيان في م1، الإجمالي في م4 |
| **5** | عند تعديل عدد الجداول رُفِض العرض؛ + **جملة مهمة قبل الجداول** (موجودة في العرض المرفق) | فورم (مرونة الجداول) + رأس العرض (header note) | المرحلة 2 |
| **6** | السجل ممتاز لكن نريد **تفاصيل أكثر**: متى رُفع العرض المالي / عرض ثانٍ / تنبيه / خروج من المبيعات / سداد الدفعة المقدمة / التركيب والتسليم | activity log عبر النماذج المرتبطة | المرحلة 6 |
| **7** | في رأس العرض: **اسم الشركة + عناية السيد المهندس** (البيانات موجودة في العملية) قبل أي عرض مالي | PDF header + i18n | المرحلة 1 |
| **8** | أكثر من جدول لاختلاف العمارات (مدعوم) + الإجمالي يُضاف كنسبة: **ضرايب 14% + تركيب 10%** + شروط خاصة خلف الجداول | الإجماليات + التركيب + الشروط الخاصة | م3 + م5 |
| **9** | الشروط ثابتة في كل العروض مع اختلافات بسيطة؛ تُنشأ وتُعدّل (إضافة/حذف نقاط) من قسم المبيعات + **طباعة عربي/إنجليزي** (من 2026 إنجليزي فقط) | قالب شروط افتراضي + طباعة ثنائية اللغة | م5 + م4 |
| **10** | (مرجع) شكل العرض النهائي: Bi-Metal + Copper، شروط مرقّمة بالإنجليزية | مرجع قبول للـ PDF | شاملة |
| **11** | **تنبيه تلقائي** عند وجود عملية في المناقصات + عدد العروض المالية المرفقة؛ + **Submittal** (ملف يُرفع — أين مكانه؟)؛ + جملة **"manufactured under license from DKC- Italy"** قبل العرض | تنبيهات + فئة مرفق Submittal + header note | م1 (Submittal) + م2 (الجملة) + م7 (التنبيهات) |

---

## 3. قيود شاملة على كل المراحل (إلزامية)

1. **AR/EN**: أي نص جديد يُضاف كمفتاح في **both** `lang/en/resources.php` و `lang/ar/resources.php` تحت `project_offers` (نفس البنية: `fields/sections/columns/actions/pdf`). ممنوع نص مكتوب مباشرة (hard-coded) في الفورم أو الـ PDF.
2. **الثيم (فاتح/داكن)**: كل عناصر الفورم من مكوّنات Filament → تتبع الثيم تلقائيًا. أي Blade داخل اللوحة يستخدم `dark:` variants وليس ألوان hex ثابتة. الـ PDF خاص بالطباعة (لا علاقة له بالثيم) لكن يجب أن يحترم اتجاه RTL/LTR.
3. **الكاش المحلي / المزامنة**: جداول `project_offers / offer_groups / offer_items / attachments` **ليست** ضمن سجل المزامنة (`config/sync.php` يسجّل: project, item, inventory, bom, bom_item, work_order, inventory_transaction فقط). إضافة أعمدة لها **لا تؤثر** على المزامنة. `Project` قابل للمزامنة لكنه read-only (`syncWritableFields()` ترجع `[]`). الخلاصة:
   - إضافة أعمدة للعروض/المرفقات: آمنة، لا تتطلب تعديل مزامنة.
   - أي **مقياس Dashboard جديد** (مثلاً عدّاد عروض) يجب أن يُلفّ بـ `Cache::remember(...)` ويُبطَل عبر `Cache::forget(...)` داخل Observer (نمط `StatsOverview` + `ProjectObserver`).
   - عند إضافة عمود لـ `projects` لاحقًا: التزم بنمط `Syncable` (الـ Observer يرفع `record_version` و `synced_at` تلقائيًا).
4. **التوافق الخلفي**: `OfferTotalsService.recalculate()` يعكس `grand_total` على `financial_amount` — أعمدة Tender/Active "آخر عرض" تقرأ هذا الرقم؛ أي تغيير في الحساب يجب أن يبقي `financial_amount` صحيحًا.
5. **الاختبارات**: لكل مرحلة اختبارات في `tests/Feature/Sales/` على نمط الموجود (`SalesAlertTest`, `AttachmentCategoryTest`).

---

## 4. المراحل (مرتّبة من الأسهل إلى الأصعب)

### المرحلة 1 — مكاسب سريعة في الطباعة والمرفقات (الأسهل، بلا migration معقّد)
**الشرائح: 2، 3 (الأسطر فقط)، 4 (البيان فقط)، 7، 11 (Submittal فقط)**

1. **Water mark (شريحة 2)** — في `OfferPdfController::show()` بعد إنشاء `new Mpdf(...)`:
   - `$mpdf->SetWatermarkImage(base_path('electrotech-logo.jpg'), 0.08, 'P');` ثم `$mpdf->showWatermarkImage = true;`
   - يُفضّل توفير صورة باهتة مخصّصة `public/images/offer-watermark.png` بدل اللوجو الأساسي لتجنّب طغيانها على النص.
   - لا تأثير على AR/EN ولا الكاش.

2. **أسطر الشروط (شريحة 3 — الجزء العاجل)** — في `resources/views/pdf/offer.blade.php` السطر 140:
   - تغيير `{{ $offer->terms }}` إلى `{!! nl2br(e($offer->terms)) !!}` لضمان ظهور كل سطر تحت الآخر (mpdf أحيانًا يطوي `white-space: pre-line`). إبقاء `e()` للحماية من HTML.

3. **رأس العرض: اسم الشركة + عناية المهندس (شريحة 7)** — في `offer.blade.php` صف "TO" (السطور 68–73):
   - استبدال `$project->engineer_name ?: $project->client_name` بكتلة:
     - السادة / **{{ client_name }}** (أو `customer->name`)
     - عناية السيد المهندس / **{{ engineer_name }}**
     - سطر تحية: "تحية طيبة وبعد,," (مفتاح ترجمة جديد `pdf.greeting`).
   - إضافة مفاتيح: `pdf.attention` ("عناية السيد المهندس" / "Attn. Eng."), `pdf.messrs` ("السادة" / "Messrs."), `pdf.greeting`.

4. **وضوح البيان في الفورم (شريحة 4 — الجزء الأول)** — في `OffersRelationManager.php` السطور 104–107:
   - تحويل `description` من `TextInput` إلى `Textarea` بـ `->autosize()->rows(2)` و `->columnSpan(5)`، حتى يرى مندوب المبيعات النص كاملًا ويراجعه قبل الطباعة (الفرق 3p/4p حسّاس).
   - إضافة `->helperText(__('...description_helper'))` يذكّر بمراجعة الإملاء/الأرقام قبل الطباعة.

5. **فئة مرفق Submittal (شريحة 11 — الجزء الأول)** — في `app/Enums/AttachmentCategory.php`:
   - إضافة `case Submittal = 'submittal';` + أيقونة `heroicon-o-document-check`.
   - إضافة المفتاح `enums.attachment_category.submittal` في AR (`Submittal`/`الساب مِتال`) و EN (`SUBMITTAL`).
   - يظهر تلقائيًا في قسم مرفقات `ProjectResource` (الفورم يولّد FileUpload لكل فئة) → يحلّ سؤال "أين مكان رفع الـ submittal؟".

**i18n**: مفاتيح `pdf.attention/messrs/greeting`, `fields.description_helper`, `enums.attachment_category.submittal`.
**ثيم**: تغييرات الفورم مكوّنات Filament → تلقائي. **كاش**: لا شيء.
**اختبار**: snapshot للـ PDF يحتوي عناية المهندس؛ اختبار أن Submittal يُحفظ ويُسترجع بفئته.

---

### المرحلة 2 — جملة الرأس قبل الجداول + جملة رخصة DKC (سهلة، migration بسيط)
**الشرائح: 5 (الجملة)، 11 (جملة DKC)**

1. **migration** `..._add_header_note_to_project_offers.php`:
   - `$table->text('header_note')->nullable()->after('terms');`
2. **النموذج** `ProjectOffer.php`: إضافة `header_note` إلى `$fillable`.
3. **الفورم** `OffersRelationManager.php`: إضافة `Textarea::make('header_note')` في قسم الرأس بـ:
   - `->default(__('resources.project_offers.defaults.header_note'))` — القيمة الافتراضية تتضمّن جملة **"The busway system is manufactured under license from DKC – Italy."** + سطر المقدمة القياسي (شريحة 11 + 5).
   - `->helperText(...)` يوضّح أنها تُطبع قبل الجداول.
4. **الـ PDF** `offer.blade.php`: طباعة `header_note` **قبل** `@foreach ($offer->groups...)` (بعد جدول meta مباشرة) مع `{!! nl2br(e()) !!}`.
5. **مرونة عدد الجداول (شريحة 5)**: التأكد أن إنشاء عرض ثانٍ بعدد جداول مختلف لا يُرفَض — مراجعة `defaultItems`/`required`/قيد `(project_id, version)`. غالبًا الرفض كان بسبب حقل مطلوب فارغ؛ نضمن أن `groups` تقبل أي عدد ≥ 1 وأن `version` يُحسب تلقائيًا (موجود في `booted()`).

**i18n**: `defaults.header_note` (AR + EN — النص الإنجليزي يحوي جملة DKC)، `fields.header_note`, `fields.header_note_helper`.
**ثيم/كاش**: لا تأثير. **سجل**: إضافة `header_note` إلى `logOnly` اختياري.
**اختبار**: PDF يطبع header_note قبل أول جدول؛ الافتراضي يحوي جملة DKC.

---

### المرحلة 3 — نسبة التركيب (Installation %) كنسبة مثل الضريبة
**الشرائح: 1، 8**

1. **migration** `..._add_installation_to_project_offers.php`:
   - `decimal('installation_percentage', 5, 2)->default(10)->after('show_vat');`
   - `boolean('show_installation')->default(false)->after('installation_percentage');`
   - `decimal('installation_amount', 15, 2)->default(0)->after('grand_total');`
2. **النموذج** `ProjectOffer.php`: إضافة الثلاثة إلى `$fillable` + `casts` (decimal/boolean) + إلى `logOnly`.
3. **الفورم** `OffersRelationManager.php` (قسم الرأس): إضافة — على نمط `vat_percentage`/`show_vat` تمامًا:
   - `TextInput::make('installation_percentage')->numeric()->default(10)->suffix('%')->live(onBlur:true)`
   - `Toggle::make('show_installation')->live()` + helperText.
   - تحديث `totals_preview` Placeholder ليحسب التركيب: `installation = show_installation ? subtotal * pct/100 : 0` ويضيفه قبل/بعد الضريبة حسب قاعدة العمل (المقترح: الإجمالي النهائي = subtotal + ضريبة + تركيب).
4. **الخدمة** `OfferTotalsService.php::recalculate()`:
   - حساب `installation = $offer->show_installation ? round($subtotal * pct/100, 2) : 0;`
   - `grand_total = subtotal + tax + installation;` وتخزين `installation_amount`.
   - إبقاء `financial_amount = grand_total` (توافق خلفي).
5. **الـ PDF** `offer.blade.php`: في جدول `totals` إضافة صف "التركيب (10%)" عندما `show_installation`، وضبط `groupGrand`/الإجمالي وفقًا لذلك.
6. **توضيح "المبلغ الفني" (شريحة 1)**: تحسين `technical_amount_helper` ليشرح بوضوح أنه سعر العرض الفني/الهندسي المنفصل (موجود لكن سؤال المستخدم يدل أنه غير واضح) — ربما إضافة tooltip أو إعادة تسمية العنوان إلى "سعر العرض الفني (منفصل)".

**i18n**: `fields.installation_percentage`, `fields.show_installation`, `fields.show_installation_helper`, `pdf.installation`.
**ثيم**: تلقائي. **كاش**: لا تأثير (ليست syncable). **توافق**: `financial_amount` يبقى = grand_total.
**اختبار**: عرض بـ تركيب 10% + ضريبة 14% يعطي grand_total صحيحًا؛ إيقاف التركيب يصفّره.

---

### المرحلة 4 — صحّة الإجماليات + إجمالي العرض المُجمَّع
**الشرائح: 4 (الإجمالي)، 10 (مرجع)**

1. **تشخيص "الإجمالي خطأ"**: حاليًا الـ PDF يطبع لكل جدول subtotal+ضريبة+grand منفصلًا، **ولا يوجد إجمالي كلّي** للعرض عند تعدد الجداول → هذا مصدر الالتباس. كذلك `technical_amount` يُطبع كسطر منفصل.
2. **إضافة كتلة إجمالي العرض الكلي** في `offer.blade.php` بعد آخر `@foreach`:
   - subtotal العرض (مجموع كل الجداول) + الضريبة + التركيب + **الإجمالي النهائي للعرض** — متطابقة مع `totals_preview` في الفورم ومع `OfferTotalsService`.
3. **توحيد مصدر الحساب**: التأكد أن المعاينة الحيّة في الفورم (`totals_preview`) و الـ PDF و `OfferTotalsService` تستخدم **نفس** منطق التقريب (`round(...,2)` على كل مستوى) حتى لا يختلف رقم الطباعة عن الفورم.
4. **مراجعة قبل الطباعة (شريحة 4)**: تأكيد أن `print` action متاح فقط بعد الحفظ (موجود) وأن البيان كامل ظاهر — مكمّل للمرحلة 1.

**i18n**: `pdf.offer_subtotal`, `pdf.offer_grand_total` (إجمالي العرض الكلي).
**ثيم/كاش**: لا تأثير.
**اختبار**: عرض بجدولين — الإجمالي الكلي = مجموع الجدولين + ضريبة + تركيب؛ رقم الـ PDF = رقم الفورم بالضبط.

---

### المرحلة 5 — فصل الشروط (خاصة/عامة) + قالب افتراضي + طباعة ثنائية اللغة
**الشرائح: 3، 8، 9**

1. **فصل الشروط (شرائح 3، 8)**:
   - الحقل الحالي `terms` → يصبح **الشروط الخاصة** (تُطبع خلف الجداول مباشرة، per-offer).
   - **migration**: إضافة `text('general_terms')->nullable()` للشروط العامة الثابتة.
   - الفورم: حقلان منفصلان — "شروط خاصة" و "شروط عامة".
2. **قالب الشروط الافتراضي (شريحة 9)**:
   - مصدر افتراضي قابل للتعديل: مفاتيح ترجمة `defaults.general_terms_ar` / `defaults.general_terms_en` (أو جدول `offer_setting` خفيف لو احتاج قسم المبيعات تعديلها من الواجهة دون نشر كود).
   - عند إنشاء عرض جديد: `general_terms` يُملأ تلقائيًا بالقالب (`->default(...)`) ويظل **قابلًا للإضافة/الحذف** يدويًا (شريحة 9).
   - الطباعة كقائمة **مرقّمة** مطابقة لمرجع شريحة 10 (الشروط 1..12 بالإنجليزية).
3. **طباعة ثنائية اللغة (شريحة 9)**:
   - تعديل المسار `routes/web.php` (`offers.pdf`) ليقبل `?lang=ar|en` (تحقّق من القيمة، fallback `en` — لأن من 2026 العروض إنجليزي افتراضيًا).
   - في `OfferPdfController`: `app()->setLocale($validatedLang)` قبل render، بدل الاعتماد على لغة الواجهة فقط → يتيح للمستخدم بواجهة عربية طباعة عرض إنجليزي.
   - في `OffersRelationManager` action `print`: استبداله بـ زرّين (طباعة عربي / طباعة إنجليزي) أو قائمة منسدلة تمرّر `lang`.
   - قالب الشروط يحتاج نسختين (AR + EN).

**i18n**: `defaults.general_terms_*`, `fields.general_terms`, `fields.special_terms` (إعادة تسمية terms)، `actions.print_ar`, `actions.print_en`, `pdf.terms_special_title`, `pdf.terms_general_title`.
**ثيم**: تلقائي. **كاش**: لا تأثير على المزامنة.
**اختبار**: `?lang=en` يطبع إنجليزي بصرف النظر عن لغة الجلسة؛ الشروط العامة تُملأ افتراضيًا وتبقى قابلة للتعديل؛ الشروط الخاصة تُطبع خلف الجداول والعامة بعدها مرقّمة.

---

### المرحلة 6 — إثراء سجل العملية (History) بأحداث دورة الحياة
**الشريحة: 6**

الوضع الحالي: `ActivitiesRelationManager` يعرض **فقط** أنشطة نموذج `Project` نفسه. المطلوب أحداث من نماذج مرتبطة.

1. **إضافة الحقول الناقصة إلى `logOnly`** في النماذج:
   - `ProjectOffer`: إضافة `submitted_at` (متى رُفع العرض المالي + العرض الثاني). 
   - `OperationPayment`: إضافة `payment_date`, `method` (متى سُدّدت الدفعة المقدمة).
   - `DeliveryMinute`: إضافة `minute_date`؛ `Project`: إضافة `smb_received_at`, `end_date`.
   - `Installation` (`started_at/completed_at`) و `DeliveryVoucher` (`activated_at`) مُسجَّلة بالفعل ✅.
2. **تايملاين مُجمَّع** في `ActivitiesRelationManager`:
   - بدل قراءة `activities` الخاصة بالمشروع فقط، الاستعلام من جدول `activity_log` عن كل النشاطات التي `subject` لها هو المشروع **أو** نموذج مرتبط به (offers, payments, installations, delivery vouchers/minutes لنفس `project_id`).
   - عمود "النوع/المصدر" لتمييز (عرض / دفعة / تركيب / تسليم / حالة)، مع ترجمة `subject_type`.
   - الحفاظ على read-only.
3. **اشتقاق أحداث "معنوية"**: "خرج من المبيعات لبقية الإدارات" = انتقال الحالة إلى `InProgress` (مسجّل ✅ عبر `status`). "تنبيه" = `alarm_at` (مسجّل ✅).
4. **الأداء**: `QueuedActivityLogger` يؤجّل الكتابة بعد الاستجابة — لا تأثير على زمن الحفظ.

**i18n**: مفاتيح `subject_type` المترجمة + عمود المصدر في `projects.relations.activities`.
**ثيم/كاش**: لا تأثير. **سجل**: تغييرات `logOnly` فقط (آلية spatie تتكفّل بالباقي).
**اختبار**: رفع عرضين + تسجيل دفعة + بدء تركيب → كلها تظهر في History المشروع بترتيب زمني.

---

### المرحلة 7 — التنبيهات التلقائية للمناقصات والعروض والـ Submittal (الأصعب)
**الشريحة: 11**

1. **تنبيه وجود عملية في المناقصات + عدد العروض المرفقة**:
   - توسيع `SalesAlertService` بدالة `tenderOperationsWithOfferCounts()` ترجع عمليات Tender مع `offers_count`.
   - **آلية الإطلاق**: عند إنشاء/إضافة عرض (`OffersRelationManager` after-create) → `Notification::sendToDatabase` لأدوار Sales/Sales_Manager تتضمّن اسم العملية وعدد العروض الحالي.
   - أو widget لوحة "عمليات مناقصات بانتظار عرض/بعروض" (مع `Cache::remember` + `Cache::forget` في Observer — احترام الكاش).
2. **تنبيه الـ Submittal**:
   - عند رفع مرفق من فئة `Submittal` (في `AttachmentPersistence::sync`) → إطلاق تنبيه للإدارات المعنية (نمط `NotifyDepartmentsOfActivation`).
3. **التحقق من المكان**: شريحة 11 تسأل "أين يُرفع الـ submittal" — حُلّ في المرحلة 1 (فئة المرفق). هذه المرحلة تضيف التنبيه فوقه.

**i18n**: `sales_alerts.tender_offers_title/body`, `sales_alerts.submittal_title/body`.
**ثيم**: إشعارات Filament تتبع الثيم. **كاش**: أي widget/عدّاد يُلفّ بـ `Cache::remember` ويُبطَل عبر Observer (إلزامي).
**اختبار**: إضافة عرض لعملية Tender يولّد إشعارًا بعدد العروض؛ رفع Submittal يولّد إشعارًا.

---

## 5. ملخّص الترتيب والاعتماديات

```
م1 (طباعة + Submittal)         ← الأسهل، بلا اعتماديات
م2 (header note + DKC)          ← migration بسيط
م3 (نسبة التركيب)               ← migration + خدمة الإجماليات
م4 (صحّة الإجماليات)            ← يبني على م3 (التركيب جزء من الإجمالي)
م5 (الشروط خاصة/عامة + ثنائي اللغة) ← migration + توسّع PDF
م6 (إثراء السجل)               ← تعديلات logOnly + تايملاين مُجمَّع
م7 (التنبيهات)                 ← الأصعب: تتطلب م3 (عرض) و م1 (Submittal)
```

**اعتماديات مهمّة**: م4 بعد م3 (التركيب يدخل الإجمالي). م7 بعد م1 (فئة Submittal) و م3 (العروض). الباقي مستقلّ نسبيًا.

## 6. قائمة Migrations المطلوبة (مجمّعة)

| المرحلة | الجدول | الأعمدة الجديدة |
|---------|--------|------------------|
| م2 | `project_offers` | `header_note` (text, null) |
| م3 | `project_offers` | `installation_percentage` (dec 5,2 = 10), `show_installation` (bool=false), `installation_amount` (dec 15,2=0) |
| م5 | `project_offers` | `general_terms` (text, null) — والحقل `terms` يصبح "خاص" |

لا migration لـ م1/م4/م6/م7 (م1 enum-only، م6 logOnly-only، م7 منطق فقط).

## 7. معايير القبول النهائية (مرجع شريحة 10)

عرض مطبوع يحتوي: رأس باسم الشركة + عناية المهندس + تحية، Water mark، جملة DKC + مقدمة قبل الجداول، جدول/جداول BOQ ببيان واضح، إجمالي لكل جدول + **إجمالي كلي** (subtotal + ضريبة 14% + تركيب 10% عند التفعيل)، شروط خاصة خلف الجداول ثم شروط عامة مرقّمة، إمكانية الطباعة عربي/إنجليزي (افتراضي إنجليزي 2026)، وسجل عملية يوضّح كل محطة زمنية.
