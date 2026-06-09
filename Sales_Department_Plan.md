# خطة تنفيذ متطلبات "قسم المبيعات" (Sales Department Modifications)

> ملف المتطلبات المصدر: `تعديلات قسم المبيعات.md` + العرض التقديمي (9 شرائح + صورة العرض الفعلي Electrotech).
> هذه الوثيقة هي **خطة** للمراجعة والاعتماد قبل أي كتابة كود. عند الموافقة بكلمة **"ابدأ التنفيذ"** يبدأ التنفيذ مرحلة بمرحلة.
> كل كلام الكود (أسماء الحقول/الكلاسات/الصلاحيات) بالإنجليزية، والشرح بالعربية — مطابقةً لـ `Inventory_Department_Plan.md` و `Financial_Department_Plan.md`.

---

## 0. منهجية التحليل

1. فُكّ ترميز ملف المتطلبات (كان mojibake) وقُرئت الـ 9 شرائح من الصور مباشرة (نص عربي واضح + صورة العرض الفعلي).
2. حُلّل الكود الفعلي للأجزاء المرتبطة بقسم المبيعات: `Project`, `ProjectOffer`, `ProjectResource`, `TenderProjectResource`, `InHandProjectResource`, `ActiveProjectResource`, `SalesPipelineService`, `OperationLifecycleService`, `OperationActivated` + listener، نظام `spatie/activitylog` و `ActivityResource`، الصلاحيات (`RoleAndPermissionSeeder` + `ProjectPolicy`/`ProjectOfferPolicy`)، الترجمة (`lang/{en,ar}`)، الـ theme (`AdminPanelProvider` + `theme.css`)، والاختبارات (`tests/Feature/Sales`).
3. رُبط كل متطلب بمكانه الفعلي في الكود وصُنّف (✅ موجود / 🟡 يحتاج تعديل / ❌ جديد).
4. اعتُمد مبدأ "اختيار الخيار المنطقي الأقرب للمتطلب دون سؤال" في نقاط القرار (مع توثيق الافتراض).

---

## 1. تفكيك المتطلبات (ماذا يطلب الملف بالضبط)

| # | الشريحة | الطلب |
|---|---------|-------|
| R1.1 | 1 | تنسيق الأرقام المالية: فاصلة `,` بعد كل 3 خانات + علامة عشرية `.` (في حقول الإدخال نفسها). |
| R1.2 | 1 | **تاريخ النهاية** في قسم المبيعات يُضبط **تلقائياً** أول لحظة تحويل العملية إلى "العمليات النشطة". |
| R2.1 | 2 | توضيح معنى **"المبلغ الفني"** (`technical_amount`) — المستخدم لا يفهمه. |
| R2.2 | 2،7،8 | شكل العرض الحالي مبسّط جداً؛ العرض الحقيقي يحوي تفاصيل كثيرة (مرفق نموذجهم). |
| R3.1 | 3 | "تم رفع ملف ولا يفتح" — مرفق `BusBar(19).rar` (9.5MB) لا يُفتح من الواجهة. |
| R3.2 | 3 | قسم المبيعات يرفع الملفات، وبقية الإدارات تحتاج رؤية/تنزيل بعضها كلٌّ حسب تخصصه. |
| R3.3 | 3 | كل الملفات تأتي بصيغة `RAR` — هل يؤثر؟ أم نرفعها بطريقة مختلفة؟ |
| R4.1 | 4 | إعادة تسمية **"نوع الفصل"** → **"اسم الموصل"**، وجعله اختياراً: نحاس / ألومنيوم / باي ميتال. |
| R4.2 | 4 | **كود العملية** يكون بالسنة فقط: `2026-1` (وليس بالشهور). |
| R5.1 | 5 | لم يجد المستخدم "موافقة المدير" ولا "إيميل القبول" في الواجهة ليُكمل التجربة. |
| R5.2 | 5 | **تنبيه تلقائي** من السيستم إذا أُضيفت عملية ناقصة مستندات (مثلاً بلا عرض مالي أو فني). |
| R6.1 | 6 | ما معنى **"مسودة"** وأين مكانها في مسار المبيعات؟ العملية الجديدة نزلت "مسودة" والمفروض تنزل في **"المناقصات"**. |
| R7.1 | 7 | جدول العرض: (البيان / وحدة القياس / الكمية / سعر الوحدة / الإجمالي) + الإجمالي النهائي + إمكانية إظهار **ضريبة القيمة المضافة**. |
| R7.2 | 7 | **طباعة** العرض من السيستم لإرفاقه في دورة المستندات الورقية. |
| R7.3 | 7 | إمكانية **عدة جداول** في العرض الواحد (عدة مقاطع/موصلات — مثل Bi-Metal + Copper). |
| R7.4 | 7 | إضافة **ملاحظات/نقاط تفصيلية** بعد الجدول (شروط الدفع، مواعيد التسليم، نسبة التركيبات...). |
| R7.5 | 7 | بعد مسار المبيعات يكون فيه **أمر توريد أو عقد**. |
| R7.6 | 7 | وجود **HEADER** للعرض في الطباعة (لوجو + بيانات الشركة كما في الصورة). |
| R9.1 | 9 | وجود **HISTORY** لمتابعة العملية. |

---

## 2. الوضع الحالي في المشروع (الحقائق الفعلية من الكود)

### 2.1 كيان المشروع/العملية
- `app/Models/Project.php`: الحقول تشمل `code`, `section_type`, `estimated_budget`/`actual_cost` (decimal:2)، `start_date`/`end_date` (date)، حقول المبيعات (`alarm_at`, `smb_status`, `acceptance_email_at`, `manager_approved_at/by`, `lost_reason`, `winning_competitor`)، علاقات `offers()` و `latestOffer()` (`->latestOfMany('version')`) و `attachments()`.
- توليد الكود `Project::generateCode()` (سطر 231): الصيغة الحالية `PRJ-YYYYMM-XXXX` (مثال `PRJ-202606-0001`) عبر قفل `Cache::lock`. **مستخدمة في مكان واحد فقط** (`ProjectResource.php:61`). لا يوجد أي كود آخر يعتمد على بادئة `PRJ-` (تحقّقنا).
- الحالة الافتراضية للمشروع الجديد = `Draft` (في الميجريشن `...create_projects_table.php:20` `->default('draft')` + في الفورم `ProjectResource.php` `->default(ProjectStatus::Draft)`).

### 2.2 الفورم (`app/Filament/Resources/ProjectResource.php`)
- **Technical Specs**: `section_type` حالياً `TextInput` حر، Label = "نوع الفصل" (`lang/*/resources.php:36`).
- **Financial & timeline**: `estimated_budget` و `actual_cost` و offers amounts كلها `TextInput->numeric()->prefix('EGP')` — **بدون فاصلة آلاف**. `end_date` فقط `->after('start_date')`.
- **Offers**: `Repeater::make('offers')->relationship()` بـ 4 أعمدة مسطّحة (`financial_amount`, `technical_amount`, `submitted_at`, `notes`) — لا جداول بنود ولا ضريبة ولا شروط. + `Placeholder` يعرض آخر عرض بـ `number_format(...,2)`.
- **Attachments**: `FileUpload` لكل `AttachmentCategory` (`upload/vendor_list/drowing/speces/boq/site_measurement`)، `->multiple()->preserveFilenames()->disk('public')`، **بدون `acceptedFileTypes`** وبدون زر تنزيل صريح؛ المعاينة الافتراضية لا تفتح ملفات `.rar`.
- جداول العرض تستخدم `->money('EGP')` (وهي تُظهر فواصل الآلاف تلقائياً) — إذن **مشكلة الفواصل في حقول الإدخال فقط** لا في جداول العرض.

### 2.3 مسار المبيعات (`app/Services/SalesPipelineService.php`)
- `moveToTender()` يشترط وجود **عرض واحد على الأقل** ثم يحوّل من `Draft`→`Tender`.
- `moveToInHand()`: `Tender`→`InHand`، يضبط `smb_status='pending'`.
- `moveToActive()`: يشترط `acceptance_email_at` و `manager_approved_at`، يحوّل إلى `InProgress`، يعلّم آخر عرض `is_winning=true`، ثم `OperationActivated::dispatch()`. **لا يضبط `end_date`** ← فجوة R1.2.
- `managerApprove()`: يضبط `manager_approved_at=now()` و`by=auth id`.

### 2.4 واجهات المناقصات/تحت اليد
- `TenderProjectResource`: أعمدة "آخر عرض - المالي/الفني" (من `latestOffer`) + "منبه المتابعة" + أزرار (إجراء→InHand / إلغاء→Lost / ضبط منبه / إزالة منبه).
- `InHandProjectResource`: أعمدة `smb_status` (badge)، `acceptance_email_at` (date)، `manager_approved_at` (icon boolean)، `alarm_at`. زر "نقل إلى العمليات النشطة" يفتح modal فيه `acceptance_email_at` (DatePicker) + Toggle `manager_approve_now` **مرئي فقط لمن يملك `projects.manager_approve`**. ← لهذا "لم يجده" المختبِر (دور Sales عادي بلا صلاحية الموافقة) = فجوة وضوح R5.1، وليست فجوة وظيفة.

### 2.5 التاريخ/الـ History
- `spatie/laravel-activitylog` مفعّل: `Project` و `ProjectOffer` يسجّلان التغييرات (status, amounts, approval, lost...) عبر `LogsActivity` (كتابة عبر `QueuedActivityLogger`). يوجد `ActivityResource` **عام** (شاشة مستقلة) لكن **لا توجد History مدمجة داخل العملية نفسها** ← فجوة R9.1 (يلزم Relation Manager/Action على المشروع).

### 2.6 التنبيهات
- بنية جاهزة للاقتباس: `OperationActivated` event → `NotifyDepartmentsOfActivation` listener يرسل `Notification::...->sendToDatabase($recipients)` لأدوار من `config/operations.php`. **لا يوجد** فحص استباقي لعملية بلا عرض ← فجوة R5.2.

### 2.7 RBAC / i18n / Theme / Tests / PDF
- **RBAC**: `RoleAndPermissionSeeder` (idempotent، Admin يأخذ كل الصلاحيات، **لا يعدّل الأدوار الموجودة مسبقاً**). صلاحيات المبيعات موجودة: `projects.*`, `project_offers.{view,create,edit,delete}`, `attachments.{upload,download,delete}`. السياسات بنمط `$user->can('resource.action')`.
- **i18n**: `lang/{en,ar}/resources.php` بنمط `resources.RESOURCE.{label,sections,fields,columns,actions,notifications}` + `resources.enums.*` + كتلتا `roles.groups` و `roles.permissions` لواجهة إدارة الأدوار. + `navigation.php` + `errors.php`.
- **Theme**: `AdminPanelProvider` primary = `#D9723B`، ألوان دلالية (`Color::Rose/Sky/Emerald/Amber/Slate`)، خط `Cairo`، dark mode + RTL تلقائي عبر `bezhansalleh/filament-language-switch`. CSS في `resources/css/filament/admin/theme.css` (متغيرات CSS، لا ألوان ثابتة).
- **Tests**: `tests/Feature/Sales/*` (RbacTest, I18nTest, StateTransitionTest, OfferHistoryTest, AlarmTest, AttachmentCategoryTest, LostFlowTest, ProjectFormTest) — `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` + factories (`ProjectFactory`, `ProjectOfferFactory`). DB = SQLite memory، queue=sync.
- **PDF**: **لا توجد أي مكتبة PDF** في `composer.json` ولا أي Blade طباعة ولا أكشن تنزيل ← R7.2/R7.6 إضافة كاملة جديدة.

---

## 3. جدول المقارنة النهائي (متطلب → الحالة → الإجراء)

| المتطلب | الحالة | الإجراء المقترح |
|---|:---:|---|
| R1.1 فواصل الأرقام في الإدخال | 🟡 | إضافة `->mask(RawJs $money)` + `->stripCharacters(',')` على حقول المبالغ. |
| R1.2 ضبط `end_date` آلياً عند التفعيل | ❌ | تعديل `moveToActive()` ليضبط `end_date = today()` إن كان فارغاً (idempotent). |
| R2.1 توضيح "المبلغ الفني" | 🟡 | `helperText` + إعادة تسمية واضحة في الترجمة + توثيقه في نموذج العرض الجديد. |
| R2.2 شكل العرض المفصّل | ❌ | إعادة تصميم العرض (BOQ متعدد الجداول) — المرحلة 1. |
| R3.1 الملف لا يفتح | 🟡 | `acceptedFileTypes` للأرشيفات + زر **Download** صريح + `downloadable()`. |
| R3.2 رؤية الإدارات للملفات | 🟡 | منح `attachments.download` للأدوار المعنية + (اختياري) فلترة العرض/التنزيل حسب الفئة. |
| R3.3 صيغة RAR | 🟡 | السماح بـ `application/zip,application/x-rar-compressed,...` + التنزيل بدل المعاينة (لا تأثير على التخزين). |
| R4.1 "نوع الفصل" → "اسم الموصل" Select | 🟡 | إنشاء `ConductorType` enum (copper/aluminum/bi_metal) + تحويل الحقل إلى Select + cast + relabel. |
| R4.2 كود `2026-1` | 🟡 | تعديل `generateCode()` إلى `{Y}-{seq}` بلا أصفار. |
| R5.1 وضوح موافقة المدير/إيميل القبول | 🟡 | إضافة أكشن مستقل "موافقة المدير" + إبراز الأعمدة + إتاحة تعديل `acceptance_email_at`. |
| R5.2 تنبيه تلقائي للعمليات الناقصة | ❌ | `SalesAlertService` + Command مجدول + Notification + مؤشر بصري في الجداول. |
| R6.1 العملية الجديدة تنزل "مناقصات" | 🟡 | تغيير الحالة الافتراضية للجديد إلى `Tender` + توضيح معنى Draft + ضبط حارس العرض. |
| R7.1 جدول البنود + الضريبة | ❌ | جدولا `offer_groups` + `offer_items` + حقول VAT/subtotal/total — المرحلة 1. |
| R7.2 طباعة العرض | ❌ | مكتبة PDF (mpdf) + Blade template + أكشن Print — المرحلة 2. |
| R7.3 عدة جداول في العرض | ❌ | `offer_groups` متعددة لكل عرض — المرحلة 1. |
| R7.4 ملاحظات/شروط بعد الجدول | ❌ | حقل `terms` (نقاط متعددة) على العرض — المرحلة 1. |
| R7.5 أمر توريد/عقد بعد المسار | ❌ | كيان `SupplyOrder`/Contract (مرحلة 5 — قابلة للتأجيل). |
| R7.6 HEADER للطباعة | ❌ | ترويسة + لوجو `electrotech-logo.jpg` + فوتر بيانات الشركة في الـ PDF — المرحلة 2. |
| R9.1 HISTORY للعملية | 🟡 | Relation Manager للنشاط (Activity) على المشروع + أكشن "السجل" في واجهات المبيعات. |

**الملخص:** ✅ 0 — 🟡 9 — ❌ 8.

---

## 4. القرارات التصميمية الرئيسية (مُعتمدة مبدئياً — يُرجى تأكيدها)

1. **R1.2 — قيمة `end_date` الآلية:** المتطلب نصّه "تاريخ النهاية... يكون تلقائي أول لما نحوّل للعمليات النشطة". التفسير المعتمد: عند `moveToActive()`، إن كان `end_date` فارغاً يُضبط = **تاريخ اليوم** (لحظة انتهاء دورة المبيعات وبدء التنفيذ)، ولا يُلمس إن كان مضبوطاً يدوياً. (بديل لو رغبت: ضبط `start_date` بدلاً منه — أُبقي الحرفي = `end_date`).
2. **R4.2 — صيغة الكود:** `{year}-{seq}` بلا padding (`2026-1`, `2026-2`...). المشاريع القديمة بصيغة `PRJ-...` تبقى كما هي؛ الجديدة فقط بالصيغة الجديدة. منطق التسلسل يبقى عبر `SUBSTRING_INDEX(code,'-',-1)` مع فلتر `like '2026-%'`.
3. **R6.1 — الحالة الافتراضية:** العملية الجديدة (المُنشأة من شاشة المشاريع/المبيعات) تبدأ مباشرة في **`Tender`** بدل `Draft`، لأن نقطة دخول مسار المبيعات هي "الفرص البيعية/المناقصات". تبقى `Draft` حالة صالحة (تُشرح في `helperText`) للمسوّدات الحقيقية، لكنها ليست الافتراضي. ويُغطّى احتمال "مناقصة بلا عرض" عبر تنبيه R5.2 بدل المنع الصلب.
4. **R2.2/R7 — بنية العرض:** هرمية `ProjectOffer (header) → OfferGroup (جدول/مقطع) → OfferItem (بند)`. تبقى `financial_amount`/`technical_amount` على الهيدر كـ"أرقام رأسية" (يستخدمها عرض المناقصات `آخر عرض`)، ويُعاد حساب `financial_amount` آلياً = Grand Total من البنود حتى لا تنكسر أعمدة المناقصات الحالية.
5. **R7.2 — مكتبة الطباعة:** **`mpdf/mpdf`** (دعم عربي/RTL وتشكيل ممتاز، يعمل بـ PHP خالص بلا Chromium — مناسب للنشر عبر `deploy.sh`). البديل `barryvdh/laravel-dompdf` مرفوض لضعف تشكيل العربية.
6. **R3.2 — رؤية الملفات بين الإدارات:** تُمنح `attachments.download` للأدوار التي تحتاج (Technical_Office, Procurement, Factory_Manager, Warehouse_Manager, Finance) بدل بناء نظام صلاحيات لكل فئة على حدة (يكفي حالياً ويطابق نمط المشروع).
7. **R7.5 — أمر التوريد/العقد:** مرحلة منفصلة (5) قابلة للتأجيل؛ لا تُعطّل بقية المراحل.

---

## 5. خطة التنفيذ المفصّلة بالمراحل

> كل مرحلة تُغطّي إلزامياً: **DB/Model → Service/Logic → Filament UI → RBAC → i18n (en/ar) → Theme/RTL → Tests**. وتنتهي كل المراحل بـ **وصفة الكاش** (قسم 9).

### المرحلة 0 — مكاسب سريعة بلا جداول جديدة (R1.1, R1.2, R4.1, R4.2, R6.1, R3.1/R3.3, R2.1)

**0.1 تنسيق الأرقام (R1.1):**
- في `ProjectResource.php` على `estimated_budget`, `actual_cost`, وحقول العرض المالية: إضافة
  ```php
  ->mask(\Filament\Support\RawJs::make("\$money(\$input, '.', ',', 2)"))
  ->stripCharacters(',')
  ->numeric()
  ```
- إنشاء helper مشترك `App\Filament\Support\MoneyInput::make($name)` لتوحيد النمط وإعادة استخدامه في العرض الجديد لاحقاً.

**0.2 `ConductorType` enum (R4.1):**
- ملف جديد `app/Enums/ConductorType.php` implements `HasLabel`: `Copper='copper'`, `Aluminum='aluminum'`, `BiMetal='bi_metal'` (label عبر `__('resources.enums.conductor_type.*')`).
- `Project::casts()` إضافة `'section_type' => ConductorType::class`.
- في الفورم: تحويل `TextInput::make('section_type')` → `Select::make('section_type')->options(ConductorType::class)`، Label = "اسم الموصل".
- ميجريشن **اختياري** لتطبيع القيم النصية القديمة (إن وُجدت بيانات) — أو ترك القديمة كما هي (nullable). نعتمد: لا تحويل قسري، الحقل nullable.

**0.3 صيغة الكود (R4.2):** تعديل `Project::generateCode()`:
```php
$prefix = now()->format('Y') . '-';           // "2026-"
// ... like $prefix.'%' ; seq = MAX(SUBSTRING_INDEX(code,'-',-1))+1
return $prefix . $seq;                          // "2026-1"
```

**0.4 الحالة الافتراضية (R6.1):**
- ميجريشن `change` لعمود الحالة → `->default('tender')` (أو ضبطها في `creating` observer/`mutateFormDataBeforeCreate`). نعتمد الـ observer/mutate لتفادي تعارض الميجريشن مع سجلات قديمة.
- في الفورم: `->default(ProjectStatus::Tender)` + `helperText` يشرح حالات المسار، وإضافة شرح "مسودة" في `resources.enums.project_status` + helper.
- مراجعة `moveToTender()`: يبقى كحارس انتقال يدوي اختياري؛ لكن بما أن الإنشاء يبدأ Tender، يصبح الحارس "اختياري". (المنع الصلب لغياب العرض يُستبدل بتنبيه R5.2 في المرحلة 3).

**0.5 `end_date` آلياً (R1.2):** في `SalesPipelineService::moveToActive()` داخل الـ transaction:
```php
if ($project->end_date === null) { $project->end_date = now()->toDateString(); }
```

**0.6 المرفقات تفتح/تُنزّل (R3.1/R3.3):** على كل `FileUpload` للمرفقات:
```php
->acceptedFileTypes(['application/zip','application/x-rar-compressed','application/octet-stream','application/pdf','image/*'])
->downloadable()->openable(false)
```
+ (في عرض المرفقات/View) إضافة رابط/أكشن تنزيل صريح يستخدم `Storage::disk('public')->download()`.

**0.7 توضيح "المبلغ الفني" (R2.1):** `helperText` على `technical_amount` يشرح أنه قيمة العرض الفني/الهندسي المنفصلة عن العرض المالي، وتوثيقه في الترجمة. (التوضيح الكامل يأتي ضمن نموذج العرض الجديد.)

**RBAC:** لا جديد. **i18n:** `enums.conductor_type.*`، تحديث `projects.fields.section_type` → "اسم الموصل/Conductor Name"، `technical_amount` helper، `project_status.draft` + helper. **Tests:** `ConductorTypeTest` (cast/select)، `CodeFormatTest` (`2026-1` + تسلسل + تفرّد)، `DefaultStatusTest` (الجديد = Tender)، `EndDateOnActivationTest`، تحديث `ProjectFormTest`.

---

### المرحلة 1 — إعادة تصميم العرض: BOQ متعدد الجداول + الضريبة + الشروط (R2.2, R7.1, R7.3, R7.4)

**1.1 قاعدة البيانات (ميجريشنات جديدة):**
- `offer_groups`: `id`, `project_offer_id` (cascade)، `label` (مثل "Bi-Metal Offer")، `conductor_type` (nullable enum)، `sort_order`، `timestamps`.
- `offer_items`: `id`, `offer_group_id` (cascade)، `description` (البيان)، `unit` (وحدة القياس)، `quantity` decimal(15,3)، `unit_price` decimal(15,2)، `line_total` decimal(15,2)، `sort_order`، `timestamps`.
- إضافات على `project_offers` (ميجريشن `add_*`): `quotation_number` (مثل `Q-165A/2026`, nullable)، `currency` (default 'EGP')، `vat_percentage` decimal(5,2) default 14، `show_vat` boolean default true، `terms` text nullable، `subtotal`/`tax_amount`/`grand_total` decimal(15,2) (محسوبة ومخزّنة).

**1.2 الموديلات:** `OfferGroup`, `OfferItem` (+ علاقات على `ProjectOffer`: `groups()`، وعلى `OfferGroup`: `items()`). توسيع `getActivitylogOptions` على `ProjectOffer` ليشمل `grand_total`, `vat_percentage`.

**1.3 منطق الحساب:** خدمة/أوبزرفر `OfferTotalsService` (أو `OfferItem::saved`/`OfferGroup::saved`) يعيد حساب: `line_total = qty*price` → `group subtotal` → `offer.subtotal` → `tax = subtotal*vat%` → `grand_total` → ومزامنة `project_offers.financial_amount = grand_total` (حتى تبقى أعمدة المناقصات "آخر عرض" سليمة).

**1.4 واجهة العرض:** تحويل الـ Repeater المسطّح الحالي إلى **`ProjectOffersRelationManager`** على `ProjectResource` (أنظف من repeater داخل repeater)، بفورم:
- هيدر: `quotation_number`, `submitted_at`, `vat_percentage`, `show_vat`, `technical_amount` (+helper).
- `Repeater('groups')` (label/conductor_type) يحوي `Repeater('items')` (description/unit/quantity/unit_price + عرض `line_total` محسوب live).
- `Placeholder` لإجماليات live (subtotal/tax/grand_total).
- `Textarea('terms')` للشروط/الملاحظات (نقاط متعددة).
- `MoneyInput` من المرحلة 0 لكل المبالغ.
- إبقاء `latestOffer` + `is_winning` كما هما (لا كسر للمناقصات/التفعيل).

**RBAC:** إعادة استخدام `project_offers.{view,create,edit,delete}` (تشمل المجموعات/البنود ضمناً). **i18n:** كتلة `project_offers.*` كاملة (groups/items/units/vat/terms/totals/quotation_number) en+ar. **Theme/RTL:** `__()` everywhere، أعمدة الجدول RTL تلقائياً. **Tests:** `OfferBoqTest` (إنشاء عرض بمجموعتين وبنود → التحقق من subtotal/tax/grand_total + مزامنة `financial_amount`)، تحديث `OfferHistoryTest`.

---

### المرحلة 2 — طباعة العرض (PDF) بترويسة (R7.2, R7.6) — مطابقة صورة الشريحة 8

**2.1 المكتبة:** `composer require mpdf/mpdf` + (اختياري) `->isolation worktree` غير مطلوب. ضبط خط عربي (Cairo/Amiri) في إعداد mpdf.

**2.2 القالب:** `resources/views/pdf/offer.blade.php`:
- **HEADER:** لوجو `electrotech-logo.jpg` + "Electrotech for Electrical Industries" + `Quotation No` + `Date` + `Project/TO/Conductor type/Nom of poles/IP`.
- **لكل `OfferGroup`** جدول: `ITEM NO | DESCRIPTION | UNIT | QTY | UNIT PRICE | T.PRICE` ثم `Subtotal / Taxes (vat%) / Grand total`.
- ملاحظة "In case installation is required..." + كتلة `terms` (شروط مرقّمة).
- **FOOTER:** كود المستند + العنوان/الهاتف/الفاكس/الموبايل/الإيميل + توقيع (Sales Manager / Proposal studies engineer).
- يدعم اللغة الحالية (locale) — والنموذج الفعلي إنجليزي الأساس.

**2.3 الأكشن:** `Action('print')` على الـ Relation Manager (وعلى صفوف المناقصات) ينشئ PDF عبر controller/route خفيف `GET /offers/{offer}/pdf` يرجع `application/pdf` (inline/download).

**RBAC:** صلاحية جديدة `project_offers.print` (Sales, Sales_Manager, Admin). **i18n:** `project_offers.actions.print` + ثوابت القالب. **Theme:** CSS داخلي في الـ Blade (مستقل عن theme.css). **Tests:** `OfferPrintTest` (الراوت يرجع PDF لمن يملك الصلاحية، و403 لغيره).

---

### المرحلة 3 — تنبيهات المبيعات + وضوح موافقة المدير (R5.1, R5.2, R3.2)

**3.1 التنبيه التلقائي (R5.2):**
- `App\Services\SalesAlertService::operationsMissingOffers()`: المشاريع في `Tender`/`InHand` التي ليس لها عرض **مالي** أو **فني** (أو تنقصها فئة مرفق إلزامية محددة — قابل للضبط).
- `App\Console\Commands\NotifyIncompleteOperations` (Schedule يومي في `routes/console.php`/`bootstrap`) يرسل `Notification::...->sendToDatabase()` لأدوار Sales/Sales_Manager (نمط `NotifyDepartmentsOfActivation`).
- مؤشر بصري فوري: عمود/أيقونة "ناقص عرض" (Color::Danger) في `TenderProjectResource`/`InHandProjectResource`.

**3.2 وضوح الموافقة (R5.1):**
- أكشن مستقل `manager_approve` على `InHandProjectResource` (أيقونة + Color::Success، مرئي لمن يملك `projects.manager_approve`، يستدعي `managerApprove()`).
- جعل `acceptance_email_at` قابلاً للتعديل عبر أكشن مستقل أيضاً (لا فقط داخل modal النقل).
- إبراز الأعمدة (badge "بانتظار موافقة المدير" / "تم القبول").

**3.3 رؤية الملفات (R3.2):** منح `attachments.download` للأدوار: Technical_Office, Procurement, Factory_Manager, Warehouse_Manager, Finance (عبر تعديل تعريف الأدوار في الـ seeder — مع التنبيه أن الأدوار الموجودة لا تُحدّث تلقائياً، انظر قسم 6).

**RBAC:** `projects.manager_approve` (موجود) + توسيع `attachments.download`. **i18n:** `in_hand_projects.actions.manager_approve`، نصوص التنبيهات `*.notifications.incomplete_*`، عمود "ناقص عرض". **Tests:** `SalesAlertTest` (عملية بلا عرض → تظهر في القائمة/يُرسل تنبيه)، `ManagerApproveActionTest`.

---

### المرحلة 4 — HISTORY للعملية (R9.1)

- `App\Filament\Resources\ProjectResource\RelationManagers\ActivitiesRelationManager` يعرض `Spatie\Activitylog\Models\Activity` المرتبط بالمشروع (و offers التابعة) بترتيب زمني: الوقت/الحدث/الوصف/المنفّذ/التغييرات (old→new مع ترجمة قيم enum مثل `project_status` كما في `ActivityResource`).
- إضافته إلى `ProjectResource::getRelations()` + أكشن "السجل/History" على صفوف `TenderProjectResource`/`InHandProjectResource`/`ActiveProjectResource` يفتح المشروع على تبويب السجل.
- إعادة استخدام منطق الترجمة من `ActivityResource` (subject labels + enum maps).

**RBAC:** صلاحية `projects.view_history` تُمنح Sales/Sales_Manager/Admin (إعادة استخدام `activity_log.view` بديل، لكن نفضّل صلاحية مخصّصة لإبقاء سجل النشاط العام منفصلاً). **i18n:** `projects.relations.activities.*` + أعمدة. **Tests:** `ProjectHistoryTest` (تغيير حالة → يظهر في علاقة النشاط، وRBAC).

---

### المرحلة 5 — أمر التوريد / العقد بعد المسار (R7.5) — قابلة للتأجيل

- خيار خفيف: نوع مستند جديد + كيان `SupplyOrder`/`Contract` (project_id، نوع، رقم، تاريخ، قيمة، مرفق موقّع) يُنشأ بعد التفعيل، مع صلاحيات `supply_orders.*`.
- يُنفّذ بعد اعتماد المراحل 0–4، أو يُؤجَّل حسب الأولوية. (مذكور للاكتمال؛ ليس على المسار الحرج.)

---

### المرحلة 6 — التشطيب الشامل

- مراجعة كل النصوص الجديدة en+ar (لا مفتاح ناقص)، التنقّل، فحص light/dark + RTL يدوياً، تحديث `guide.md` (شرح Draft/Tender، صيغة الكود الجديدة، نموذج العرض، الطباعة، التنبيهات، History).
- تشغيل كل اختبارات `tests/Feature/Sales` + الاختبارات المرتبطة (GeneralManagement/Operations) للتأكد من عدم الكسر.
- تنفيذ **وصفة الكاش** (قسم 9).

---

## 6. RBAC — التفصيل الكامل

### صلاحيات تُضاف إلى `RoleAndPermissionSeeder::getPermissions()`
```
project_offers.print
projects.view_history
supply_orders.view        # المرحلة 5 (مؤجّلة)
supply_orders.create
supply_orders.edit
supply_orders.delete
```
(باقي المتطلبات تُغطّى بصلاحيات قائمة: `projects.*`, `project_offers.{view,create,edit,delete}`, `projects.manager_approve`, `attachments.{upload,download}`.)

### الأدوار
- **Sales**: + `project_offers.print`, `projects.view_history`.
- **Sales_Manager**: + `project_offers.print`, `projects.view_history` (لديه `projects.manager_approve` بالفعل).
- **Technical_Office / Procurement / Factory_Manager / Warehouse_Manager / Finance**: + `attachments.download` (R3.2) لمن لا يملكها.
- **Admin**: يأخذ كل الجديد تلقائياً.

> ⚠️ **Gotcha (من نمط المشروع):** `RoleAndPermissionSeeder` **لا يعدّل الأدوار الموجودة مسبقاً**. لذلك الصلاحيات الجديدة لأدوار قائمة (Sales/Sales_Manager/Finance...) يجب منحها يدوياً بعد الـ seed، مثلاً:
> ```php
> php artisan tinker --execute="
>   app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
>   \Spatie\Permission\Models\Role::findByName('Sales')->givePermissionTo(['project_offers.print','projects.view_history']);
>   \Spatie\Permission\Models\Role::findByName('Sales_Manager')->givePermissionTo(['project_offers.print','projects.view_history']);
>   foreach(['Technical_Office','Procurement','Factory_Manager','Warehouse_Manager','Finance'] as \$r){ \Spatie\Permission\Models\Role::findByName(\$r)->givePermissionTo('attachments.download'); }
> "
> ```
> (Admin لا يحتاج منحاً يدوياً.)

السياسات: إضافة `print(User, ProjectOffer)` إلى `ProjectOfferPolicy`، و`viewHistory(User, Project)` إلى `ProjectPolicy`.

---

## 7. i18n / RTL / Theme — قائمة الالتزام

- كل نص جديد عبر `__()` في **`lang/en/resources.php` و `lang/ar/resources.php`** بنفس المفاتيح، ومرآة في `roles.groups`/`roles.permissions` للصلاحيات الجديدة.
- مفاتيح جديدة: `enums.conductor_type.{copper,aluminum,bi_metal}`، `project_offers.{sections,fields,columns,actions,totals,units}.*`، `projects.fields.section_type` (قيمة محدّثة)، `projects.relations.activities.*`، `in_hand_projects.actions.manager_approve`، نصوص التنبيهات، helper نصوص (Draft / technical_amount / end_date auto).
- ألوان دلالية فقط (`Color::Danger/Warning/Success/Info/Primary/Gray`) للـ badges والمؤشرات. لا ألوان ثابتة في كود PHP/Blade الخاص بالواجهة (قالب الـ PDF مستثنى — CSS داخلي خاص به).
- فحص RTL + dark mode يدوياً للواجهات الجديدة (Relation Managers/الأكشنات/الجداول).

---

## 8. خطة الاختبارات (تتبع نمط `tests/Feature/Sales`)

| ملف | يغطّي |
|---|---|
| `ConductorTypeTest` | cast `section_type`→enum + خيارات Select. |
| `CodeFormatTest` | صيغة `2026-1` + تسلسل + تفرّد + عدم تأثّر الأكواد القديمة. |
| `DefaultStatusTest` | المشروع الجديد يبدأ `Tender`. |
| `EndDateOnActivationTest` | `moveToActive` يضبط `end_date` إن كان فارغاً، ولا يلمسه إن كان مضبوطاً. |
| `OfferBoqTest` | مجموعتان + بنود → subtotal/tax(vat)/grand_total + مزامنة `financial_amount`. |
| `OfferPrintTest` | راوت PDF يرجع `application/pdf` لمن يملك `project_offers.print`، و403 لغيره. |
| `SalesAlertTest` | عملية في Tender/InHand بلا عرض مالي/فني → تُكتشف/يُرسل تنبيه DB. |
| `ManagerApproveActionTest` | أكشن الموافقة المستقل + RBAC. |
| `ProjectHistoryTest` | تغيّر الحالة يظهر في علاقة النشاط + RBAC `projects.view_history`. |
| `AttachmentArchiveTest` | قبول `.rar/.zip` + التنزيل + رؤية الأدوار. |
| تحديث `RbacTest` | الصلاحيات الجديدة (`project_offers.print`, `projects.view_history`). |
| تحديث `I18nTest` | كل المفاتيح الجديدة تُحلّ في en+ar (لا ترجع المفتاح نفسه). |
| تحديث `ProjectFormTest` | Select الموصل + الحالة الافتراضية + masks. |

تشغيل: `php artisan test --testsuite=Feature` (DB SQLite memory، queue sync) — مع التأكد من عدم كسر `tests/Feature/GeneralManagement`.

---

## 9. ⭐ وصفة ضبط الكاش المحلي لرؤية التعديلات (محلي)

بعد كل مرحلة (وبالأخص بعد الميجريشن/الـ seeder/الترجمة/الـ theme):
```bash
# 1) ميجريشن + seeder للصلاحيات الجديدة
php artisan migrate
php artisan db:seed --class=RoleAndPermissionSeeder
#    ثم منح الصلاحيات الجديدة للأدوار القائمة يدوياً (انظر قسم 6) — Admin تلقائي.

# 2) مسح كل الكاشات (الأهم محلياً)
php artisan optimize:clear
php artisan filament:clear-cached-components
php artisan icons:clear
php artisan permission:cache-reset

# 3) إعادة بناء أصول الواجهة (Tailwind/Filament theme)
npm run build

# 4) إعادة تشغيل عمّال الطابور (تنبيهات/سجل النشاط المُصطفّ)
php artisan queue:restart

# 5) كاش المتصفح / Service Worker:
#    محلياً APP_ENV=local → طبقة الـ resilience معطّلة. لو ظهرت واجهة قديمة: Hard reload (Ctrl+F5).
```

---

## 10. المخاطر والربط مع الأجزاء الأخرى

- **الحالة الافتراضية Tender:** تؤثر على عدّادات لوحة التحكم (`dashboard:tender_count`...) — مُبطَّلة الكاش تلقائياً عبر `ProjectObserver::saved()`. تحقّق من `OperationsOverview` بعد التغيير.
- **`financial_amount` rollup:** يجب أن تبقى أعمدة "آخر عرض" في `TenderProjectResource`/`ActiveProjectResource` سليمة → الاختبار `OfferBoqTest` يثبّتها.
- **صيغة الكود:** معزولة (`generateCode` فقط)؛ اختبار `GeneralManagement/OperationsOverviewTest` يستخدم كوداً ثابتاً من الـ factory ولا يعتمد على الصيغة — لا كسر متوقع.
- **mpdf والعربية:** يلزم خط مضمّن يدعم العربية؛ يُختبر يدوياً على عرض عربي + إنجليزي.
- **منع غياب العرض:** بتغيير الافتراضي إلى Tender نزيل حارس `moveToTender` الصلب؛ نعوّضه بتنبيه R5.2 حتى لا تمرّ مناقصة بلا عرض دون ملاحظة.
- **سجل النشاط مُصطفّ (queued):** History قد لا يظهر فوراً بلا worker — وُثّق في الوصفة (queue:restart) وفي الاختبارات (queue sync).

---

## 11. ملخص التصنيف النهائي والترتيب المقترح

1. **المرحلة 0** (مكاسب سريعة: أرقام/موصل/كود/افتراضي/end_date/مرفقات) — أعلى قيمة/أقل مخاطرة.
2. **المرحلة 1** (إعادة تصميم العرض BOQ) — جوهر طلب الشرائح 2/7/8.
3. **المرحلة 2** (طباعة PDF بترويسة).
4. **المرحلة 3** (تنبيهات + وضوح موافقة المدير + رؤية الملفات).
5. **المرحلة 4** (History للعملية).
6. **المرحلة 5** (أمر توريد/عقد) — قابلة للتأجيل.
7. **المرحلة 6** (تشطيب + اختبارات + كاش + تحديث `guide.md`).

> **للموافقة:** راجع القرارات في قسم 4 (خصوصاً R1.2 end_date، صيغة الكود، الافتراضي Tender، mpdf). عند الرد بـ **"ابدأ التنفيذ"** أبدأ بالمرحلة 0 وأتبع كل مرحلة بوصفة الكاش، مع وقفة مراجعة بين المراحل عند الطلب.
