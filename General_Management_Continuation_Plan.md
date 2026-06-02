# خطة تكملة "الإدارة العامة" (General Management — Phase 7)

> وثيقة تحليل وتخطيط فقط — **لا يوجد تنفيذ كود في هذه المرحلة**.
> تكملة لـ `General_Management_Plan.md` (نُفّذت منه المراحل 0–6 + التشطيب). هذه الخطة تغطّي **المتطلبات المتبقّية فقط** من ملف `General_Management.md` — البنود التي أُجّلت عمداً (مرحلة 7).
> **مبدأ حاكم (طلب صريح من المستخدم): كل بند هنا مربوط مباشرةً بسطر في ملف المتطلبات — لا توجد أي ميزة مُخترَعة خارج المتطلبات.**

---

## 0. منهجية التحليل والمرجع

1. **المرجع**: ملف `General_Management.md` (سلايد 1 + سلايد 2). أُعيد فكّ ترميزه سطر-بسطر في الخطة الأصلية.
2. **قراءة الكود الفعلي بعد تنفيذ المراحل 0–6**: تم التحقق من:
   - شجرة الحسابات `ChartOfAccountsSeeder` تحتوي بالفعل: `1010 الخزينة`، `1011 خزينة أجنبي`، البنوك، `1200 العملاء`، `2070 تسهيلات أبو ظبي`، `5020 مصروفات تركيب`، `5010 مصروفات تشغيل`، `5030 عمومية`، `5040 تمويلية`، `5050 تصدير`.
   - بُعد مركز التكلفة `journal_entry_lines.project_id` و`account_entries.project_id` **مُنفّذ** (المرحلة 1)، و`OperationCostService` يجمّع مصروفات الـ GL المُوسَّمة تلقائياً.
   - `DocumentType` enum: `payment_order` (أمر صرف/نقد خارج/أسود)، `supply_receipt` (إيصال توريد/نقد داخل/أحمر)، `settlement` (قيد تسوية/أخضر) — جاهز لترميز الدفعات.
   - `FinancialClaim` (المطالبة المالية) مُنفّذ — لكن **لا يوجد تسجيل للمبالغ المُحصَّلة فعلاً (المقبوضات)**.
   - `DeliveryVoucher.supply_order_number` موجود (رقم طلب التوريد)، و`PurchaseOrder` (أوامر الشراء/التوريد) مربوطة بالعملية.
   - `AttachmentCategory`: `upload, vendor_list, drowing, speces, boq` — **لا توجد فئة "مقاسات الموقع"**.
   - لا يوجد أي موديل `payment` / `facility` / `installation` (بناء جديد بالكامل).

### الخلاصة المفاهيمية
المراحل 0–6 بَنَت **العملية كمركز تكلفة** و**دورة حياتها** و**محضر التسليم** و**المطالبة المالية**. المتبقّي هو الجانب **المالي-التشغيلي للتحصيل والتمويل والتركيب**:

> `استلام المالي (المقبوضات) → رصده في ملف أوامر التوريد → مراقبة التسهيلات الائتمانية على العمليات → مرحلة التركيب وتحميل مصاريفها على مركز التكلفة → دراسة المشروع ورفع مقاسات الموقع والرسومات`.

كل ده مربوط بحسابات موجودة فعلاً في شجرة الحسابات (الخزينة/العملاء/تسهيلات/مصروفات تركيب)، فالتكامل المالي مباشر.

---

## 1. المتطلبات المتبقّية (مربوطة بسطور الملف) + جدول الحالة

> الرموز: ✅ مُنفّذ (مراحل 0–6) — 🟡 جزئي/أساس موجود — ❌ غير موجود (هذه الخطة).

| # | سطر المتطلب في `General_Management.md` | المعنى | الحالة الآن | الإجراء في مرحلة 7 |
|---|---|---|---|---|
| **1.9** | "دراسة المشروع والذهاب ورفع مقاسات الموقع وعمل رسومات للعملية" | مرحلة هندسية: مقاسات الموقع + رسومات | 🟡 (الرسومات = فئة مرفق `drowing` موجودة) | **7D**: فئة مرفق `site_measurement` + موديل `SiteSurvey` (تاريخ المعاينة + المقاسات + المسؤول). |
| **1.14** | "يتم استلام المالي ورصدها في ملف الأوامر التوريد" | تسجيل المبالغ المستلمة ضمن ملف أوامر التوريد للعملية | ❌ | **7A**: موديل `OperationPayment` (المقبوضات) + شاشة **ملف أوامر التوريد** (POs + التوريد + المقبوضات + المتبقّي). |
| **1.15** | "القيام بإجراءات الدفعات النقدية وفتح ملف للعملية ويعتبر مركز تكلفة لكل مصاريف تخص العملية" | الدفعات النقدية (داخل/خارج) + العملية = مركز تكلفة | 🟡 (مركز التكلفة ✅ المرحلة 1؛ الدفعات ❌) | **7A**: `OperationPayment` (وارد/صادر) + توليد قيد GL اختياري على الخزينة/العميل. |
| **1.16** | "فتح ملف للعميل باسم العملية ومراقبة التسهيلات وتحليلها على العمليات" | متابعة التسهيلات الائتمانية وتحليلها على العمليات | ❌ | **7B**: موديل `CreditFacility` + `FacilityAllocation` (توزيع التسهيل على العمليات) + تقرير الاستغلال. |
| **2.5** | "عند وجود تركيبات يتم تسليم البضاعة وبداية التركيب وتحميل جميع المصاريف على مركز التكلفة للعملية" | مرحلة التركيب + تحميل مصاريفها على العملية | 🟡 (تحميل المصاريف على مركز التكلفة عبر GL مدعوم؛ تتبّع التركيب ❌) | **7C**: موديل `Installation` (بدء/إنهاء) + إبراز "مصاريف التركيب" (حساب `5020`) في ملف التكلفة. |

> **ملاحظة**: بقية بنود السلايدين (إنشاء العملية، المناقصات، أمر التشغيل، الصرف، التسليم، الاعتماد المزدوج، المقارنة، المحضر، المطالبة، التنشيط لكل الأقسام) **مُنفّذة بالكامل** في المراحل 0–6 ولا تُعاد هنا.

---

## 2. القرارات التصميمية (الافتراض الموصى به — تُعتمد بدون سؤال)

1. **المقبوضات/الدفعات = موديل تشغيلي `OperationPayment` مع جسر اختياري للـ GL**.
   - *موصى به*: نعم. كل دفعة تُسجَّل كسجل تشغيلي مربوط بالعملية (وبالعميل/المطالبة اختيارياً)، **ويمكن** أن تولّد قيد يومية متوازن عبر `JournalEntryService` الموجود:
     - **مقبوض من عميل (وارد)**: `مدين 1010 الخزينة (أو بنك) / دائن 1200 العملاء` — نوع المستند `supply_receipt` (نقد داخل).
     - **مدفوع (صادر)**: `مدين (مصروف/مورد) / دائن 1010 الخزينة` — نوع المستند `payment_order` (نقد خارج).
   - علم `config('operations.auto_journal_payments')` لتشغيل/إيقاف التوليد التلقائي (تفادي الازدواج لو أُدخل القيد يدوياً) — نفس فلسفة جسر الأذون.
   - **عدم الازدواج في التكلفة**: المقبوضات **ليست تكلفة** (تدفّق نقدي/تحصيل)، فلا تتداخل مع `OperationCostService.total_cost`. تُعرض كـ "محصَّل" مقابل "المطالبات/التسليمات".
2. **ملف أوامر التوريد = شاشة تجميعية (View)** لكل عملية: أوامر الشراء (`PurchaseOrder`) + أذون الإضافة (`AdditionVoucher`) + المقبوضات (`OperationPayment`) + الرصيد المتبقّي. لا جدول تجميع مخزَّن — نفس فلسفة `OperationCostService`.
3. **ربط الدفعة بالمطالبة**: `OperationPayment.financial_claim_id` (اختياري). عند اكتمال تحصيل قيمة المطالبة، يجوز إقفالها تلقائياً (`collect`) — يربط المرحلة 6 بالمرحلة 7A.
4. **التسهيلات = `CreditFacility` (سقف) + `FacilityAllocation` (تخصيص لكل عملية)**.
   - يُربَط التسهيل بحساب التزام من شجرة الحسابات (مثل `2070 تسهيلات أبو ظبي`) عبر `account_id`، وبالعميل اختيارياً.
   - المتاح = `limit_amount − Σ التخصيصات النشطة`. "تحليلها على العمليات" = عرض التخصيصات لكل عملية.
5. **التركيب = `Installation` خفيف** (سجل لكل عملية): `pending → in_progress → completed`، يبدأ **بعد التسليم** (مربوط بإذن تسليم اختيارياً). **مصاريف التركيب تُحمَّل عبر GL على حساب `5020 مصروفات تركيب` مُوسَّمة بالعملية** — وتظهر تلقائياً في `OperationCostService` (يُضاف فقط مؤشّر فرعي "مصاريف تركيب").
6. **مقاسات الموقع والرسومات = `SiteSurvey` + فئة مرفق `site_measurement`** (الرسومات موجودة كـ `drowing`).
7. **server-authored** لكل الموديلات الجديدة (بدون `Syncable`) — تُدار من لوحة الأدمن.
8. **الترقيم التلقائي** للدفعات (`PMT-YYYYMM-XXXX`) بنفس النمط المحمول المستخدم في `DeliveryMinute`/`FinancialClaim` (ترتيب lexical بلا `SUBSTRING_INDEX`).

---

## 3. خطة التنفيذ المفصّلة بالمراحل

كل مرحلة: DB → Model/Enum → Service → Filament → RBAC → i18n (en/ar) → Theme/RTL → Tests → **الربط**. وبعد كل مرحلة: **مسح الكاش المحلي (القسم 7)**.

---

### المرحلة 7A — المقبوضات والدفعات + ملف أوامر التوريد (المتطلبات 1.14, 1.15)

**DB / Migration** `create_operation_payments_table`:
- `id, payment_number (string unique — PMT-YYYYMM-XXXX), project_id (FK cascade), customer_id (nullable FK nullOnDelete), financial_claim_id (nullable FK nullOnDelete), direction (string → PaymentDirection: incoming/outgoing), method (string → PaymentMethod: cash/cheque/bank_transfer), account_id (nullable FK → accounts — الخزينة/البنك), amount (decimal 15,2), currency (string default EGP), payment_date (date), reference (string nullable — رقم طلب التوريد/الشيك), journal_entry_id (nullable FK → journal_entries — القيد المُولَّد), notes (text nullable), created_by (nullable FK), timestamps, softDeletes`.
- فهارس: `unique(payment_number)`, `index(['project_id','direction'])`, `index('payment_date')`.

**Enums**:
- `app/Enums/PaymentDirection.php` (`incoming` وارد/مقبوض، `outgoing` صادر/مدفوع) — `HasLabel, HasColor` (incoming=success، outgoing=danger).
- `app/Enums/PaymentMethod.php` (`cash` نقدي، `cheque` شيك، `bank_transfer` تحويل بنكي) — `HasLabel`.

**Model** `OperationPayment`: fillable/casts (direction→enum, method→enum, amount→decimal:2, payment_date→date)، علاقات `project()/customer()/financialClaim()/account()/journalEntry()/createdBy()`، `LogsActivity`، توليد `payment_number` في `creating` (نمط محمول).

**Service** `app/Services/OperationPaymentService.php`:
- `record(array $data): OperationPayment` — ينشئ الدفعة داخل `DB::transaction`؛ ولو `config('operations.auto_journal_payments')` مفعّل **و** `account_id` محدّد:
  - وارد: قيد `Dr account_id (خزينة/بنك) / Cr 1200 العملاء` بنوع `supply_receipt`، السطر المدين/الدائن مُوسَّم بـ `project_id`.
  - صادر: قيد `Dr (الحساب المقابل) / Cr account_id` بنوع `payment_order`.
  - يربط `journal_entry_id` بالدفعة.
- `allocateToClaim(OperationPayment, FinancialClaim)` — يربط الدفعة بمطالبة؛ ولو إجمالي المقبوض ≥ قيمة المطالبة يستدعي `FinancialClaimService::collect` (ربط 7A↔6).
- `totalsForProject(Project): array{received, paid, net}` — يُستهلك في ملف أوامر التوريد + `OperationCostService`.

**توسيع `OperationCostService`**: إضافة `received(Project)` (Σ المقبوضات الواردة) لعرض "المحصَّل" مقابل "الإيراد/المطالبات" في ملف التكلفة (قراءة فقط، لا يدخل في `total_cost`).

**Filament**:
- `OperationPaymentResource` (مجموعة `general_management`، `navigationSort` ~7): جدول (payment_number, project, customer, direction badge, method, amount, payment_date) + فلاتر (direction, نطاق تاريخ) + إنشاء عبر `OperationPaymentService::record`.
- **صفحة `SupplyOrdersFile`** (`app/Filament/Pages/SupplyOrdersFile.php`، نمط `OperationCostFile`): اختيار عملية → يعرض:
  - أوامر الشراء/التوريد (`project->purchaseOrders`) + قيمها.
  - أذون الإضافة (التوريد للمخزون) المرتبطة.
  - المقبوضات/الدفعات (`OperationPayment`) + الإجماليات (محصَّل/مدفوع/صافٍ).
  - الرصيد: (قيمة التسليمات/المطالبات − المحصَّل).
  - `canAccess => operation_payments.view`.

**RBAC**: `operation_payments.view`, `operation_payments.record`, `supply_orders_file.view`.
**i18n**: `resources.operation_payments.*` + `resources.supply_orders_file.*` + `enums.payment_direction.*` + `enums.payment_method.*` + `roles.groups.operation_payments` + `roles.permissions.operation_payments.*` (en+ar).
**Theme/RTL**: incoming=success/outgoing=danger؛ محاذاة أرقام.
**Tests** `tests/Feature/GeneralManagement/OperationPaymentTest.php`: تسجيل دفعة وارد/صادر؛ توليد قيد GL متوازن صحيح عند تفعيل العلم (والإيقاف يمنعه)؛ ربط الدفعة بمطالبة وإقفالها عند اكتمال التحصيل؛ `totalsForProject`؛ الترقيم الفريد؛ RBAC.

**الربط**: يستهلك `Account` (شجرة الحسابات) + `JournalEntryService` + `FinancialClaim`. **لا يلمس** تدفّقات الأذون. اختبار انحدار لـ `FinancialClaimTest`.

---

### المرحلة 7B — مراقبة التسهيلات الائتمانية (المتطلب 1.16)

**DB / Migrations**:
- `create_credit_facilities_table`: `id, name (string), account_id (nullable FK → accounts — مثل 2070 تسهيلات), customer_id (nullable FK), limit_amount (decimal 15,2), currency (string default EGP), start_date (nullable), end_date (nullable), status (string → FacilityStatus: active/expired/closed), notes, created_by, timestamps, softDeletes`.
- `create_facility_allocations_table`: `id, credit_facility_id (FK cascade), project_id (FK cascade), allocated_amount (decimal 15,2), allocated_at (date), status (string: active/released), notes, created_by, timestamps`. (تحليل التسهيل **على العمليات**.)

**Enum** `app/Enums/FacilityStatus.php` (`active/expired/closed`, `HasLabel, HasColor`).

**Models** `CreditFacility` (+ `allocations()` hasMany، `account()`, `customer()`، accessor `available_amount = limit − Σ allocations.active`) و`FacilityAllocation` (+ `facility()`, `project()`).

**Service** `app/Services/CreditFacilityService.php`:
- `allocate(CreditFacility, Project, float): FacilityAllocation` — يتحقّق أن `amount ≤ available_amount` (وإلا `errors.operations.facility_exceeds_available`)؛ ينشئ تخصيصاً.
- `release(FacilityAllocation)` — يحرّر التخصيص (يعيد المتاح).
- `utilization(CreditFacility): array{limit, used, available, percent}`.

**Filament**:
- `CreditFacilityResource` (مجموعة `general_management`): CRUD + عمود "المتاح" + `RelationManager` للتخصيصات (allocate/release).
- (اختياري) صفحة/Widget "تحليل التسهيلات على العمليات": جدول التسهيلات مع نسبة الاستغلال والعمليات المخصّصة (تحقيق "وتحليلها على العمليات").

**RBAC**: `credit_facilities.view`, `credit_facilities.manage`.
**i18n**: `resources.credit_facilities.*` + `resources.facility_allocations.*` + `enums.facility_status.*` + `roles.*` (en+ar).
**Theme/RTL**: نسبة الاستغلال badge (≥90% danger، ≥70% warning، غير ذلك success).
**Tests** `tests/Feature/GeneralManagement/CreditFacilityTest.php`: المتاح = السقف − التخصيصات النشطة؛ رفض تخصيص يتجاوز المتاح؛ التحرير يعيد المتاح؛ الاستغلال؛ RBAC.

**الربط**: يقرأ `Account` (حساب التسهيل) و`Project`. مستقل — لا يكسر شيئاً.

---

### المرحلة 7C — مرحلة التركيب وتحميل مصاريفها (المتطلب 2.5)

**DB / Migration** `create_installations_table`: `id, project_id (FK cascade), delivery_voucher_id (nullable FK nullOnDelete — التسليم الذي بدأ بعده التركيب), status (string → InstallationStatus: pending/in_progress/completed), started_at (nullable), completed_at (nullable), notes (text nullable), created_by, timestamps, softDeletes`. فهرس `index(['project_id','status'])`.

**Enum** `app/Enums/InstallationStatus.php` (`pending/in_progress/completed`, `HasLabel, HasColor, HasIcon`).

**Model** `Installation` (+ `project()`, `deliveryVoucher()`, `createdBy()`، `isInProgress()/isCompleted()`، `LogsActivity`).

**Service** `app/Services/InstallationService.php` (انتقالات بنمط `OperationLifecycleService` مع `DomainException`):
- `start(Installation)` — `pending → in_progress` + `started_at = now()` (يُفضّل وجود تسليم نشِط للعملية — تحقّق اختياري بنمط `FinancialClaimService::canRaiseFor`).
- `complete(Installation)` — `in_progress → completed` + `completed_at`.

**تحميل المصاريف على مركز التكلفة (جوهر المتطلب 2.5)**:
- **لا حاجة لجدول جديد للمصاريف** — مصاريف التركيب تُدخَل كقيود GL مدينة على حساب **`5020 مصروفات تركيب`** مُوسَّمة بـ `project_id` (مدعوم منذ المرحلة 1)، فتدخل تلقائياً في `OperationCostService.ledgerExpenses`.
- **توسيع `OperationCostService`**: إضافة `installationExpenses(Project)` = Σ سطور GL المدينة المُوسَّمة بالعملية على حساب التركيب (يُحدَّد عبر `config('operations.installation_account_code', '5020')`). يُعرض كبند فرعي في ملف التكلفة (تحقيق "تحميل جميع المصاريف على مركز التكلفة").

**Filament**:
- `InstallationResource` (مجموعة `general_management`): جدول (project, status badge, started_at, completed_at) + أكشن `start`/`complete` + إنشاء (اختيار العملية + التسليم).
- إبراز "مصاريف التركيب" كبطاقة في `OperationCostFile` (المرحلة 1).

**RBAC**: `installations.view`, `installations.manage`.
**i18n**: `resources.installations.*` + `enums.installation_status.*` + `errors.operations.installation_*` + `roles.*` (en+ar).
**Theme/RTL**: حالة badge؛ بطاقة مصاريف التركيب.
**Tests** `tests/Feature/GeneralManagement/InstallationTest.php`: انتقالات `start/complete` الصحيحة وممنوعة؛ `installationExpenses` يجمع سطور حساب 5020 المُوسَّمة فقط؛ RBAC.

**الربط**: يبني فوق وسم GL (المرحلة 1) + التسليم (موجود). **اختبار انحدار** لـ `OperationCostTest` (التأكد أن إضافة `installationExpenses` لا تغيّر `total_cost`، فهي مجموعة فرعية من `ledger_expenses`).

---

### المرحلة 7D — مقاسات الموقع والرسومات (المتطلب 1.9)

**Enum**: إضافة حالة `SiteMeasurement = 'site_measurement'` إلى `AttachmentCategory` (الرسومات موجودة كـ `drowing`) + أيقونة + مفتاح i18n `enums.attachment_category.site_measurement`.

**DB / Migration** `create_site_surveys_table`: `id, project_id (FK cascade), survey_date (date), measurements (text nullable — المقاسات), surveyed_by (nullable FK users), notes (text nullable), timestamps, softDeletes`. فهرس `index('project_id')`.

**Model** `SiteSurvey` (+ `project()`, `surveyedBy()`، `LogsActivity`).

**Filament**:
- إتاحة رفع مرفقات فئة `site_measurement` في `ProjectResource` (نمط فئات المرفقات الحالي).
- `SiteSurveyResource` **أو** `RelationManager` داخل `ProjectResource` (موصى به: RelationManager) لتسجيل المعاينة (تاريخ + مقاسات + المسؤول).

**RBAC**: إعادة استخدام `attachments.upload/download` + `site_surveys.view/manage` (جديدة).
**i18n**: `resources.site_surveys.*` + `enums.attachment_category.site_measurement` + `roles.*` (en+ar).
**Theme/RTL**: نص المقاسات بمحاذاة صحيحة.
**Tests** `tests/Feature/GeneralManagement/SiteSurveyTest.php`: إنشاء معاينة مرتبطة بالعملية؛ فئة المرفق الجديدة تُحفظ وتُرشَّح؛ RBAC + i18n للفئة الجديدة.

**الربط**: يبني فوق `Attachment` + `Project` الموجودين. **اختبار انحدار** لـ `Sales/AttachmentCategoryTest` (التأكد أن الفئة الجديدة لا تكسر الفئات الحالية).

---

### المرحلة 7E — التشطيب والتكامل
- ترتيب `navigationSort` داخل `general_management`: لوحة العمليات (1) → ملف التكلفة (2) → ملف أوامر التوريد (3) → الحجوزات (4) → محاضر التسليم (5) → المطالبات (6) → المقبوضات (7) → التسهيلات (8) → التركيب (9).
- (اختياري، تحقيق "وتحليلها") Widgets لوحة تحكم: المحصَّل مقابل المطالبات، نسبة استغلال التسهيلات، عمليات بمرحلة تركيب جارية.
- مراجعة شاملة `ar.json`/`resources.php` لمنع أي نص ثابت.
- تحديث `config/operations.php`: `auto_journal_payments` + `installation_account_code`.

---

## 4. RBAC — التفصيل الكامل

### صلاحيات تُضاف إلى `RoleAndPermissionSeeder::getPermissions()`
```
// General Management — Payments & Supply Orders File
'operation_payments.view', 'operation_payments.record', 'supply_orders_file.view',
// General Management — Credit Facilities
'credit_facilities.view', 'credit_facilities.manage',
// General Management — Installations
'installations.view', 'installations.manage',
// General Management — Site Surveys
'site_surveys.view', 'site_surveys.manage',
```

### توزيع الأدوار (`getDefaultRoleDefinitions`)
| الدور | الإضافات |
|---|---|
| **General_Manager** | كل ما سبق (الدور المالك). |
| **Finance** | `operation_payments.view/record`, `supply_orders_file.view`, `credit_facilities.view/manage` (الجانب المالي/التحصيل/التمويل). |
| **Technical_Office** | `site_surveys.view/manage`, `installations.view` (المعاينة والرسومات). |
| **Factory_Manager** | `installations.view/manage` (تنفيذ التركيب). |
| **Admin** | الكل تلقائياً (`grantAdminAllPermissions`). |

> **GOTCHA مؤكَّد (يتكرّر)**: `ensureInitialRolesExist` لا يلمس الأدوار الموجودة → الصلاحيات الجديدة لأدوار قائمة (Finance/Technical_Office/Factory_Manager/General_Manager إن كان موجوداً) تُمنح **يدوياً** من شاشة الأدوار أو أمر صريح؛ Admin يأخذها تلقائياً.

### Policies (نمط الموجود)
- `OperationPaymentPolicy`: view→`operation_payments.view`, create→`operation_payments.record`, update/delete→record + (اختياري) حارس "غير مُرحَّلة للـ GL".
- `CreditFacilityPolicy` + `FacilityAllocationPolicy`: view→`credit_facilities.view`, create/update/delete→`credit_facilities.manage`.
- `InstallationPolicy`: view→`installations.view`, create/update→`installations.manage`؛ `start/complete` عبر `->visible(can('installations.manage'))`.
- `SiteSurveyPolicy`: view→`site_surveys.view`, create/update/delete→`site_surveys.manage`.

---

## 5. i18n / RTL / Theme — قائمة الالتزام
- **`resources.php` (en+ar)**: كتل `operation_payments`, `supply_orders_file`, `credit_facilities`, `facility_allocations`, `installations`, `site_surveys`.
- **`enums.*` (en+ar)**: `payment_direction`, `payment_method`, `facility_status`, `installation_status`, + إضافة `attachment_category.site_measurement`.
- **`errors.php` (en+ar)**: `operations.facility_exceeds_available`, `operations.installation_illegal_transition` (أو إعادة استخدام `illegal_transition`).
- **شاشة الأدوار**: `roles.groups.{operation_payments, credit_facilities, installations, site_surveys}` + `roles.permissions.<slug>.<action>` في **اللغتين** (يفرضها `GeneralManagementI18nTest`).
- **`__()` إلزامي**؛ ألوان سيمانتية فقط (incoming=success/outgoing=danger، استغلال التسهيل warning/danger، حالات التركيب).
- **العملة**: استخدام `currency` ديناميكياً (المقبوضات/التسهيلات قد تكون بعملات أجنبية — الخزينة الأجنبية `1011` موجودة).

---

## 6. خطة الاختبارات (نمط `tests/Feature/GeneralManagement`)

| الملف | يغطّي |
|---|---|
| `OperationPaymentTest.php` | تسجيل وارد/صادر؛ توليد قيد GL متوازن (وإيقافه بالعلم)؛ ربط بمطالبة + إقفالها؛ الإجماليات؛ الترقيم؛ RBAC. |
| `SupplyOrdersFileTest.php` | تجميع POs + أذون الإضافة + المقبوضات + الرصيد لعملية؛ الوصول بالصلاحية. |
| `CreditFacilityTest.php` | المتاح = السقف − التخصيصات؛ رفض تجاوز السقف؛ التحرير؛ الاستغلال؛ RBAC. |
| `InstallationTest.php` | انتقالات start/complete؛ `installationExpenses` يجمع حساب 5020 المُوسَّم فقط؛ RBAC. |
| `SiteSurveyTest.php` | إنشاء معاينة؛ فئة المرفق الجديدة؛ RBAC + i18n. |
| توسيع `GeneralManagementI18nTest` / `GeneralManagementRbacTest` | المفاتيح والصلاحيات الجديدة في اللغتين/الأدوار. |

**Factories جديدة**: `OperationPaymentFactory`, `CreditFacilityFactory`, `FacilityAllocationFactory`, `InstallationFactory`, `SiteSurveyFactory`.
**اختبارات انحدار إلزامية**: `OperationCostTest`, `FinancialClaimTest`, `Sales/AttachmentCategoryTest` تبقى خضراء.
**ملاحظة تقنية**: ترقيم `PMT-` بنمط محمول (lexical order، بلا `SUBSTRING_INDEX`) ليعمل على SQLite — كما في `DeliveryMinute`/`FinancialClaim`.

---

## 7. ⭐ ضبط الكاش المحلي بعد كل مرحلة (طلب صريح متكرّر)
```powershell
php artisan migrate; php artisan db:seed --class=RoleAndPermissionSeeder; php artisan optimize:clear; php artisan filament:clear-cached-components; php artisan icons:clear; php artisan permission:cache-reset; npm run build; php artisan queue:restart
```
> بعد إضافة الصلاحيات الجديدة: امنحها للأدوار القائمة من شاشة الأدوار (Admin/General_Manager تلقائياً)، ثم `permission:cache-reset`. محلياً **لا** تستخدم `config:cache`/`route:cache`/`view:cache`.

---

## 8. المخاطر والاعتبارات (الربط)
1. **ازدواج المالي (الأخطر)**: توليد قيد GL تلقائي للدفعات **و** إدخال نفس القيد يدوياً → ازدواج. الحل: علم `auto_journal_payments` + سياسة واضحة (المقبوضات تلقائي، اليدوي للتسويات). والمقبوضات لا تدخل `total_cost` (تدفّق نقدي لا تكلفة).
2. **مصاريف التركيب**: تُحمَّل على حساب `5020` مُوسَّمة بالعملية فقط — `installationExpenses` **مجموعة فرعية** من `ledger_expenses` (تُعرض، لا تُجمَع مرتين).
3. **العملات**: المقبوضات/التسهيلات بعملات مختلفة — تُعرض وتُجمَّع لكل عملة (لا تحويل FX، تأجيل كما في الخطة الأصلية).
4. **GOTCHA الأدوار**: صلاحيات جديدة لأدوار قائمة تُمنح يدوياً (موثّق القسم 4 و7).
5. **server-authored**: الموديلات الجديدة بدون `Syncable`.
6. **الأداء**: ملف أوامر التوريد والتسهيلات Views؛ تُضاف فهارس (`project_id`, `payment_date`) — مذكورة.
7. **التكامل مع المراحل المنفّذة**: 7A↔6 (الدفعة تُقفل المطالبة)، 7C↔1 (مصاريف التركيب عبر GL المُوسَّم)، 7A/7C↔1 (`OperationCostService` يُوسَّع بـ `received`/`installationExpenses` بدون كسر `total_cost`).

---

## 9. ملخص التصنيف النهائي (التكملة)
- **🟡 أساس موجود يُبنى عليه**: مركز التكلفة + وسم GL (المرحلة 1) → مصاريف التركيب والمقبوضات؛ المطالبة المالية (المرحلة 6) → ربط الدفعات؛ المرفقات/الرسومات (`drowing`) → مقاسات الموقع؛ حسابات الخزينة/العملاء/التسهيلات/التركيب موجودة في شجرة الحسابات.
- **❌ بناء جديد**: `OperationPayment` + ملف أوامر التوريد (1.14, 1.15)؛ `CreditFacility` + `FacilityAllocation` (1.16)؛ `Installation` + `installationExpenses` (2.5)؛ `SiteSurvey` + فئة `site_measurement` (1.9).

> **الترتيب الموصى به**: 7A (المقبوضات + ملف التوريد) → 7B (التسهيلات) → 7C (التركيب) → 7D (مقاسات/رسومات) → 7E (تشطيب). كل مرحلة مع **اختباراتها + ترجمتها + صلاحياتها + مسح الكاش** قبل التالية.
>
> **بعد اعتماد هذه الخطة، قُل "ابدأ التنفيذ" لأبدأ من المرحلة 7A.**
