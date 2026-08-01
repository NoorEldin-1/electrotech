# خطة معالجة تقرير الاختبار الشامل (E2E Test Report — 1 August 2026)

مرجع التقرير: `E2E Test Report - ElectroTech Orwa.pdf`
النطاق: 2 خطأ وظيفي + 4 مشاكل واجهة + 4 مشاكل تجربة استخدام + ملاحظة جودة بيانات.

---

## 0. التحليل — ربط كل ملاحظة بمكانها الفعلي في النظام

| # | التقرير | السبب الجذري في الكود | الحالة |
|---|---------|------------------------|--------|
| 3.1 | أمر تصنيع "مكتمل" بكمية مخططة = 0 وتواريخ فارغة | `WorkOrderResource::form()` — `planned_quantity` بـ `->default(0)` بدون `required`، والتواريخ اختيارية. و`WorkOrderService::approveOrder()/start()` لا يتحققان من اكتمال الخطة قبل الإفراج للتصنيع | مؤكَّد |
| 3.2 | لا تظهر رسالة نصية عند فشل التحقق | `vendor/filament/forms/.../text-input.blade.php:93` يضع خاصية `required` الأصلية على `<input>`، فيوقف المتصفح الإرسال **قبل** وصوله للسيرفر ⇒ لا تُنفَّذ رسالة Filament (`fi-fo-field-wrp-error-message`) إطلاقاً | مؤكَّد |
| 4.1 | قائمة البحث العام: تباين ضعيف، تتداخل، وتبقى بعد التنقل | `theme.css` يصمّم `.fi-global-search-results-ctn` في الوضع الداكن فقط؛ و`.fi-topbar > nav` يُنشئ سياق تكديس بـ `z-index: 0` فتُحبس القائمة تحت المحتوى؛ ولا يوجد أي تنظيف على `livewire:navigated` | مؤكَّد |
| 4.2 | عناصر قائمة الإجراءات رمادية باهتة | `theme.css:1059` يلوّن `.fi-dropdown-list-item` فقط (الزر)، بينما النص داخل `.fi-dropdown-list-item-label` يظل على `text-gray-*` الافتراضي؛ ولا توجد قاعدة للوضع الفاتح | مؤكَّد |
| 4.3 | صفحة 404 غير منسّقة وغير معرَّبة | لا يوجد `resources/views/errors/` إطلاقاً ⇒ Laravel يعرض صفحته الافتراضية الإنجليزية | مؤكَّد |
| 4.4 | إشعار النجاح يغطي شريط التنقل العائم | `notifications.blade.php` يستخدم `fixed inset-4` أي `top: 1rem`، بينما الشريط العائم يبدأ عند `0.875rem` وارتفاعه `3.5rem` ⇒ تصادم حتمي | مؤكَّد |
| 5.1 | بطاقات لوحة المعلومات فارغة قبل التحميل | `StatsOverview::$isLazy = true` وLivewire يعرض `placeholder()` الافتراضي (`<div></div>`) لعدم تعريفه | مؤكَّد |
| 5.2 | التحقق يحتاج جولة كاملة للسيرفر | نفس سبب 3.2 — لا توجد طبقة تحقق فورية على العميل | مؤكَّد |
| 5.3 | مسار ما بعد الحفظ غير موحّد | 5 موارد فقط تُعرِّف `getRedirectUrl()` (Item, Project, Bom, PurchaseOrder, WorkOrder) و19 مورداً يستخدم الافتراضي (صفحة التعديل) | مؤكَّد |
| 5.4 | قائمة العميل لا تُرشِّح بالكتابة + نطاقات تواريخ فارغة | `ProjectResource:82` يجمع `searchable()` مع `preload()` ⇒ الترشيح يصبح بحثاً ضبابياً في المتصفح على القائمة كاملة بدل استعلام `LIKE` على السيرفر. و`JournalDaybook::mount()` / `GeneralLedgerReport::mount()` يثبتان الشهر الحالي بغض النظر عن وجود بيانات | مؤكَّد |
| 8 | تكرار البريد/الهاتف | لا `unique()` على `email`/`phone` في `CustomerResource` ولا `SupplierResource` | مؤكَّد |
| 8 | «إعفاء ضريبة الأرباح 1%» مفعّل افتراضياً | **غير صحيح** — الافتراضي في قاعدة البيانات `false` (`2026_06_17_000002`) والحقل بلا `->default()`. الملاحظة على الأرجح مورّد قائم مضبوط يدوياً | مُفنَّد (سنُصرِّح بالافتراضي كتوثيق دفاعي) |

---

## حالة التنفيذ — كل المراحل منفَّذة ✅

| المرحلة | الملفات الرئيسية | الاختبار |
|---------|------------------|----------|
| 1 — سلامة خطة أمر التصنيع | `WorkOrderService::assertPlanIsComplete()`، `WorkOrderResource` (form)، `lang/*/errors.php` | `WorkOrderPlanIntegrityTest` (5) |
| 2 — رسائل تحقق فورية | `public/js/inline-validation.js`، `AdminPanelProvider` (render hook)، `lang/*/validation.php → client` | `UxRegressionTest` (2) |
| 3 — قائمة البحث العام | `theme.css`، مستمع `livewire:navigated` في `AdminPanelProvider` | — (تنسيق/سلوك متصفح) |
| 4 — تباين قائمة الإجراءات | `theme.css` | — |
| 5 — صفحات الأخطاء | `resources/views/errors/*`، `lang/*/errors.php → pages` | `ErrorPagesTest` (9) |
| 6 — موضع الإشعارات | `theme.css` | — |
| 7 — هيكل تحميل اللوحة | `StatsOverview::placeholder()`، `stats-overview-placeholder.blade.php`، `theme.css` | — |
| 8 — توحيد مسار الحفظ | 38 صفحة Create/Edit | `PostSaveRedirectConsistencyTest` (2) |
| 9 — القوائم ونطاقات التقارير | `ProjectResource`، `DefaultsToPeriodWithLedgerData`، `JournalDaybook`، `GeneralLedgerReport` | `UxRegressionTest` (4) |
| 10 — جودة البيانات | `PhoneInput::unique()`، `CustomerResource`، `SupplierResource`، `ProjectResource` | `DuplicateContactGuardTest` (8) |

**الإجمالي: 30 اختباراً جديداً، كلها ناجحة.**

> ثلاثة اختبارات فاشلة في المشروع (`ActivityLogTest::subject_type_is_translated`، واختباران في `NetworkResilienceTest`) كانت فاشلة قبل هذا العمل — مسجَّلة في `.phpunit.result.cache` ولا علاقة لها بهذه التعديلات.

---

## المراحل

### المرحلة 1 — سلامة بيانات أمر التصنيع (3.1، خطورة عالية)
1. `WorkOrderResource`: `planned_quantity` تصبح `required` مع `gt:0`، وتاريخا التخطيط `required` مع `afterOrEqual`.
2. `WorkOrderService`: حارس `assertPlanIsComplete()` يُستدعى في `approveOrder()` و`start()` — البوابتان الوحيدتان اللتان يمرّ منهما الأمر نحو التصنيع، فيستحيل بعدها وصول أمر ناقص الخطة إلى «مكتمل».
3. مفاتيح لغة `errors.work_order.incomplete_plan` (ar/en).
4. اختبار `WorkOrderPlanIntegrityTest`.

### المرحلة 2 — رسائل التحقق الفورية (3.2 + 5.2)
- `public/js/inline-validation.js`: يعترض حدث `invalid`، يلغي فقاعة المتصفح، ويحقن رسالة معرَّبة أسفل الحقل بنفس أسلوب Filament؛ ويعيد التحقق على `input`/`blur` ⇒ تغذية راجعة فورية بلا أي جولة سيرفر.
- تُحقن عبر `PanelsRenderHook::SCRIPTS_AFTER` مع قاموس رسائل من `lang/*/validation.php` تحت مفتاح `client`.

### المرحلة 3 — قائمة البحث العام (4.1)
- تنسيق صريح للوضع الفاتح + رفع `z-index` + `max-height`/تمرير.
- إغلاق البحث وتفريغه على `livewire:navigated`.

### المرحلة 4 — تباين قائمة الإجراءات (4.2)
- تلوين `.fi-dropdown-list-item-label` في الوضعين من رموز النص الكاملة.

### المرحلة 5 — صفحات الأخطاء (4.3)
- تخطيط مشترك `errors/layout` + صفحات 403/404/419/500/503 معرَّبة RTL بهوية المنصة وروابط للوحة والدليل.
- مفاتيح `errors.pages.*` + اختبار.

### المرحلة 6 — موضع الإشعارات (4.4)
- إنزال حاوية `.fi-no` أسفل الشريط العائم في كل المقاسات.

### المرحلة 7 — هيكل تحميل لوحة المعلومات (5.1)
- `StatsOverview::placeholder()` + قالب هيكل عظمي نابض بنفس هندسة البطاقات، وحجز مساحة الجرس لمنع الارتجاج.

### المرحلة 8 — توحيد مسار ما بعد الحفظ (5.3)
- كل صفحات الإنشاء والتعديل ترجع إلى القائمة بعد الحفظ (القاعدة التي تتبعها الموارد الخمسة القائمة أصلاً) + اختبار معماري يمنع التراجع.

### المرحلة 9 — القوائم ونطاقات التقارير (5.4)
- `ProjectResource`: بحث سيرفري حقيقي عبر الاسم/الهاتف/البريد/الرقم الضريبي بدل التحميل المسبق.
- `JournalDaybook` و`GeneralLedgerReport`: النطاق الافتراضي يتبع البيانات الفعلية (آخر شهر به حركة، وإلا الشهر الحالي).

### المرحلة 10 — جودة البيانات (§8)
- تفرُّد البريد والهاتف على مستوى النموذج في العملاء والموردين.
- تثبيت `->default(false)` صراحةً على إعفاء ضريبة الأرباح.
