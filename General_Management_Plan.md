# خطة تنفيذ متطلبات "الإدارة العامة" (General Management Process)

> وثيقة تحليل وتخطيط فقط — **لا يوجد تنفيذ كود في هذه المرحلة**.
> المرجع: ملف المتطلبات `General_Management.md` (سلايدان: 1، 2) + تحليل المشروع الفعلي (Laravel 12 + Filament 3.3).
> هذه الخطة هي **طبقة التنسيق/الإشراف العليا** التي تربط الأقسام المنفّذة سابقاً (المبيعات `Sales_Department_Plan`، المخزون `Inventory_Department_Plan`، المالية `Financial_Department_Plan`) في **دورة حياة العملية (Operation Lifecycle)** الواحدة، حيث **العملية = مركز تكلفة (Cost Center)**.

---

## 0. منهجية التحليل

1. **فك ترميز ملف المتطلبات**: النص وصل للمحادثة كـ mojibake؛ أُعيد فكّه سلايد بسلايد. (الملف على القرص غير موجود كـ `General_Management.md` في الجذر — وُجد فقط `PDFs/General_Management.md`، والمحتوى مطابق.) السلايدان يصفان **مسار العملية الكامل** عبر كل الأقسام، لا قسماً منفرداً.
2. **قراءة الكود الفعلي** عبر 5 محاور متوازية:
   - **العملية/المبيعات**: `Project` (الكيان المركزي)، `ProjectStatus` (enum 10 حالات، 5 منها غير مُستخدمة بعد)، `ProjectOffer`، `SalesPipelineService` (Draft→Tender→InHand→InProgress→Lost)، الموارد الأربعة (Tender/InHand/Active/Lost).
   - **المخزون/الأذون**: `Item/Inventory/InventoryTransaction` (3 مخازن عبر `WarehouseType`)، `IssueVoucher` (إذن صرف)، `AdditionVoucher` (إذن إضافة)، `DeliveryVoucher` (إذن تسليم باعتماد مزدوج)، `InventoryService` (مع `holdStock/releaseStock` **غير مربوطة بالعملية**).
   - **التصنيع/المشتريات**: `WorkOrder` (= أمر التشغيل)، `ProductionEntry` (استخراج الفاقد)، `Bom/BomItem`، `PurchaseOrder`.
   - **المالية/الأستاذ**: `Account/JournalEntry/JournalEntryLine`، `AccountEntry` (دفتر الأطراف)، خدمات GL/الميزان. **لا يوجد بُعد مركز تكلفة في المالية إطلاقاً.**
   - **RBAC/i18n/Theme/Tests**: `RoleAndPermissionSeeder` (8 أدوار)، 19 Policy، بنية `lang/{en,ar}/{resources,navigation,errors}.php` + `ar.json`، `AdminPanelProvider` (7 مجموعات تنقل + Cairo + dark/light + RTL تلقائي)، نمط `tests/Feature/<Domain>/`.
3. **مقارنة دقيقة** بين ما يطلبه الملف وما هو موجود فعلاً.

### الخلاصة المفاهيمية المبكّرة (مهمة جداً)
معظم **اللبنات** موجودة بالفعل ومُنفّذة جيداً (العملية، الأذون، أوامر التشغيل، المشتريات، الـ GL). ما يطلبه ملف "الإدارة العامة" هو **الطبقة الغائبة التي تجعلها تعمل كمنظومة واحدة**:

> `العملية (Project) كملف واحد ومركز تكلفة` ← يُجمَّع فيه **كل المصاريف من كل الأقسام** (خامات مصروفة + مشتريات + تصنيع + تركيب + قيود GL) ← **مقارنة التكلفة الفعلية بأمر التشغيل (التقديري) واحتساب الربحية** ← **ترحيل الملف لكل الأقسام عند التنشيط** ← **محضر تسليم** يُوزّع على الأقسام ← **مطالبة مالية** بعد إتمام التوريد والتركيب.

اليوم: العملية تُراكم `actual_cost` من **إذن الصرف فقط** (مصدر واحد للتكلفة)، والـ GL **غير مربوط بأي عملية**، و**محضر التسليم والمطالبة المالية غير موجودين إطلاقاً**، و**تنشيط العملية لا يُرحِّل شيئاً لأي قسم** (يقلب الحالة فقط).

---

## 1. تفكيك المتطلبات (ماذا يطلب الملف بالضبط)

### السلايد 1 — بدء العملية وتوزيعها على الأقسام

| # | المتطلب (مفكوك من العربي) | المعنى التشغيلي |
|---|---|---|
| 1.1 | إنشاء العملية عن طريق المبيعات | العملية تُنشأ من قسم المبيعات. |
| 1.2 | إدراجها في **TENDER PROJECTS** | تظهر في قائمة المناقصات. |
| 1.3 | دراسة الكميات ومراجعة المخزون | عند بدء العملية: حصر الكميات المطلوبة ومراجعة توفّرها في المخزون. |
| 1.4 | **بعد تنشيط العملية يتم ترحيلها إلى كل الأقسام** | التنشيط يجب أن **يفتح/يوزّع** العملية على كل الأقسام (فني، مخزون، مشتريات، تصنيع، مالية). |
| 1.5 | ترحيلها إلى **IN HAND** | الانتقال لمرحلة "في اليد". |
| 1.6 | **حجز الكمية** وخروجها بـ **إذن صرف** من المخازن | حجز المخزون للعملية ثم صرفه بإذن صرف. |
| 1.7 | طلب طلبية للعملية وحجزها وخروجها بإذن صرف | طلب شراء/تصنيع مرتبط بالعملية ثم صرف. |
| 1.8 | على **طلبات التصنيع** | إنشاء أوامر تصنيع للعملية. |
| 1.9 | دراسة المشروع ورفع مقاسات الموقع وعمل **رسومات** للعملية | مرحلة هندسية: مقاسات موقع + رسومات (مرفقات). |
| 1.10 | **إدارة المشتريات** | إصدار أوامر شراء للعملية. |
| 1.11 | **الإدارة العامة** | طبقة الإشراف التي تتابع العملية عبر الأقسام (الميزة الجديدة). |
| 1.12 | **أمر التشغيل** | أمر التشغيل/الإنتاج = WorkOrder. |
| 1.13 | **الإدارة المالية** | الربط مع المالية. |
| 1.14 | استلام المالي ورصده في **ملف أوامر التوريد** | تتبّع المبالغ المستلمة/الأوامر التوريدية للعملية. |
| 1.15 | إجراءات الدفعات النقدية + **فتح ملف للعملية = مركز تكلفة لكل مصاريفها** | كل مصروف يخص العملية يُحمَّل على ملفها (مركز التكلفة). |
| 1.16 | فتح ملف باسم العملية لمراقبة **التسهيلات** وتحليلها على العمليات | متابعة التسهيلات الائتمانية الخاصة بالعملية. |

### السلايد 2 — التصنيع والتسليم والمطالبة المالية

| # | المتطلب | المعنى التشغيلي |
|---|---|---|
| 2.1 | دخول العملية **مرحلة التصنيع** | حالة "قيد التصنيع" للعملية. |
| 2.2 | يتم على **إذن صرف بالخامات** | صرف الخامات للتصنيع بإذن صرف. |
| 2.3 | **زر إجرائي وانتهاء التصنيع** | زر "إنهاء التصنيع". |
| 2.4 | وجودها في **مخزن البضاعة التامة** وانتظار الاعتمادات وتسليمها للعميل | الانتقال لمخزن التام ثم الاعتماد المزدوج ثم التسليم. |
| 2.5 | عند وجود **تركيبات**: تسليم البضاعة وبدء التركيب و**تحميل كل المصاريف على مركز التكلفة** | مرحلة تركيب + تحميل مصاريفها على العملية. |
| 2.6 | **المقارنة بين التكلفة الفعلية وأمر التشغيل واحتساب المالي** | تقرير: الفعلي مقابل التقديري + الربحية. |
| 2.7 | **عند التسليم** | حدث التسليم. |
| 2.8 | **محاضر التسليم** وإرسالها لجميع الأقسام | مستند "محضر تسليم" يُوزّع على الأقسام. |
| 2.9 | **المطالبة المالية** | مستند مطالبة مالية للعميل. |
| 2.10 | بعد إتمام **التوريد والتركيب** | توقيت رفع المطالبة. |

---

## 2. الوضع الحالي في المشروع (الحقائق الفعلية من الكود)

### 2.1 العملية (الكيان المركزي) — موجود وقوي
- **`app/Models/Project.php`**: الكيان المركزي. حقول مالية: `estimated_budget` و`actual_cost` (decimal 15,2). علاقات جاهزة: `boms()`, `purchaseOrders()`, `workOrders()`, `offers()`, `attachments()`, `customer()`. كود تلقائي `PRJ-YYYYMM-XXXX`. يطبّق `LogsActivity` + `SoftDeletes` + `Syncable` (لكن `syncWritableFields()=[]` → للقراءة فقط عبر المزامنة).
- **`app/Enums/ProjectStatus.php`**: 10 حالات. المستخدَمة فعلياً عبر `SalesPipelineService`: `Draft, Tender, InHand, InProgress, Lost`. **غير المستخدمة (متاحة لدورة حياة العملية): `PendingReview, Approved, OnHold, Completed, Cancelled`.** كل حالة لها label/color/icon.
- **`SalesPipelineService`**: يدير `Draft→Tender→InHand→InProgress` + `→Lost`. الدالة `moveToActive()` **تقلب الحالة إلى `InProgress` وتحدّد العرض الفائز فقط** — لا تُنشئ BOM/WO/PO، **ولا تُرحّل العملية لأي قسم، ولا تُشعِر أحداً**.
- الموارد: `ProjectResource` (CRUD كامل، مجموعة `sales_crm`) + 4 موارد للحالات (Tender/InHand/Active/Lost) للقراءة وأكشن الانتقال.
- ما يرتبط بالعملية بالفعل عبر `project_id`: `boms`, `work_orders`, `purchase_orders`, `delivery_vouchers` (nullable), `attachments`, `project_offers`. وبشكل **غير مباشر**: `issue_vouchers` (عبر `work_order.project`)، `addition_vouchers` (عبر `purchase_order.project`). **الـ GL (`journal_entries`/`lines`) غير مربوط بالعملية إطلاقاً.**

### 2.2 المخزون والأذون
- 3 مخازن عبر `WarehouseType`: `RawMaterials`, `WorkInProgress`, `FinishedGoods` (مخزن التام موجود). لا يوجد جدول/موديل مخزن مستقل — الرصيد لكل `(item, warehouse)`.
- `IssueVoucher` = **إذن صرف**؛ مربوط بـ `work_order_id` (العملية عبر WO). `post()` يحوّل Raw→WIP و**يُراكم `project->actual_cost`** (هذا **المصدر الوحيد** لتكلفة العملية اليوم).
- `AdditionVoucher` = إذن إضافة؛ `post()` يضيف للمخزون + يكتب `AccountEntry` دائن للمورد. `DeliveryVoucher` = إذن تسليم باعتماد مزدوج (فني+مالي) ثم يخصم من التام + يكتب `AccountEntry` مدين للعميل.
- **الحجز (حجز الكمية)**: البنية التحتية موجودة (`on_hold_quantity` + `holdStock/releaseStock` + `TransactionType::Hold/Release`) **لكنها غير مستخدمة في أي تدفق عملية** — المستدعي الوحيد هو محلِّل المزامنة. لا يوجد ربط بعملية أو UI.

### 2.3 التصنيع والمشتريات
- **`WorkOrder` = أمر التشغيل**؛ مربوط بـ `project_id` + `bom_id` + `output_item_id`. دورة حياة: `pending→in_progress→qa_review→completed` (+`cancelled`). الأكشن `complete()` = "زر إنهاء التصنيع" (يضيف للتام + يستهلك WIP + ينشئ `ProductionEntry`).
- **حقيقة حرجة**: **لا يوجد أي حقل تكلفة تقديرية على `WorkOrder` أو `Bom` أو `BomItem`** — فقط كميات. التكلفة التقديرية **قابلة للاحتساب فقط** من `Bom::getTotalCostAttribute()` (= Σ كمية×سعر الصنف)، وغير مخزّنة. الزوج الوحيد المخزَّن "تقديري مقابل فعلي" هو على **العملية**: `estimated_budget` vs `actual_cost`.
- `PurchaseOrder`: مربوط بـ `project_id` + `supplier_id`، له `total_amount` مخزَّن. **استلام أمر الشراء لا يُحمّل تكلفة على العملية** (`actual_cost` يُراكم من إذن الصرف فقط).
- `ProductionEntry`: تقرير استخراج الفاقد (read-only)، مجموعة `manufacturing`.

### 2.4 المالية / الأستاذ العام
- `Account` (شجرة حسابات) + `JournalEntry`/`JournalEntryLine` (قيد مزدوج، draft/posted) + `AccountEntry` (دفتر الأطراف) + خدمات `GeneralLedgerService`/`TrialBalanceService`/`AccountStatementService`.
- **لا يوجد بُعد مركز تكلفة في المالية**: لا `cost_center_id` ولا `project_id` على القيود أو سطورها. أقصى ربط هو `AccountEntry.operation_name` (**نص حر** = اسم العملية، ليس FK).
- **لا يوجد `config/accounting.php`** ولا جسر يربط الأذون بقيود GL.

### 2.5 RBAC / i18n / Theme / Tests (البنية الجاهزة لإعادة الاستخدام)
- **8 أدوار**: `Admin, Sales, Sales_Manager, Technical_Office, Procurement, Factory_Manager, Warehouse_Manager, Finance`. لا يوجد دور "إدارة عامة/مدير عام".
- **GOTCHA مؤكَّد**: `ensureInitialRolesExist()` **لا يلمس الأدوار الموجودة** عند إعادة البذر؛ `Admin` فقط يُمنح كل الصلاحيات تلقائياً. أي صلاحية جديدة لدور قائم تُمنح يدوياً.
- 19 Policy تُكتشف تلقائياً (نمط CRUD: view/create/edit/delete + حُرّاس حالة مثل `&& $entry->isDraft()`).
- **7 مجموعات تنقل**: `sales_crm, technical_office, warehouse, procurement, manufacturing, finance, system`. **لا توجد مجموعة `general_management`.**
- i18n عبر `lang/{en,ar}/{resources,navigation,errors}.php` + `ar.json`. كل enum في كتلة `enums.<name>`. شاشة الأدوار تحتاج `roles.groups.<slug>` + `roles.permissions.<slug>.<action>` في اللغتين.
- `AdminPanelProvider`: ألوان سيمانتية (primary=برتقالي العلامة)، خط Cairo، RTL تلقائي مع `ar`، `discoverPages/Resources/Widgets` كلها مفعّلة. أول Filament Page مخصّصة = `TrialBalance` (نمط جاهز للنسخ مع `canAccess()`).
- Tests: `tests/Feature/{Sales,Inventory,Finance}/`؛ نمط `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` + Factories + `assignRole`. النمط القياسي لكل قسم: `<Domain>RbacTest` + `<Domain>I18nTest` + اختبارات سلوكية.

### 2.6 ما هو غير موجود إطلاقاً (الفجوة الكبرى)
- ❌ **مجموعة تنقل/دور "الإدارة العامة"** ولوحة إشراف على العمليات عبر الأقسام.
- ❌ **ترحيل العملية لكل الأقسام عند التنشيط** (cascade/إشعار) — `moveToActive` يقلب الحالة فقط.
- ❌ **مركز تكلفة موحّد** يجمع كل المصاريف (الموجود يجمع إذن الصرف فقط)؛ والـ GL غير مربوط بعملية.
- ❌ **حقل/لقطة تكلفة تقديرية على أمر التشغيل** ومقارنة فعلي↔تقديري + احتساب الربحية.
- ❌ **حجز المخزون للعملية** (البنية موجودة، غير مربوطة).
- ❌ **محضر التسليم (محضر تسليم)** كمستند يُوزّع على الأقسام (DeliveryVoucher هو إذن البضاعة وليس المحضر).
- ❌ **المطالبة المالية (المطالبة المالية)**.
- ❌ **ملف أوامر التوريد + الدفعات النقدية المستلمة + مراقبة التسهيلات** للعملية.
- ❌ **دورة حياة العملية بعد التنشيط** (مرحلة تصنيع/تركيب/إتمام) — حالات `OnHold/Completed` موجودة في الـ enum لكن لا يقودها شيء.

---

## 3. جدول المقارنة النهائي (متطلب → الحالة → الإجراء)

الرموز: ✅ موجود ومطابق — 🟡 موجود لكن يحتاج تعديل/توسيع — ❌ غير موجود (بناء جديد).

| # | المتطلب في الملف | الحالة | التفصيل والإجراء المطلوب |
|---|---|---|---|
| 1 | إنشاء العملية من المبيعات (1.1) | ✅ | `ProjectResource` + قسم المبيعات. لا تغيير. |
| 2 | TENDER PROJECTS / IN HAND (1.2, 1.5) | ✅ | `TenderProjectResource`/`InHandProjectResource` + حالات الـ enum. لا تغيير. |
| 3 | أمر التشغيل (1.12) | ✅ | `WorkOrder` + دورته. يُعاد استخدامه ويُضاف له تكلفة تقديرية (بند 9). |
| 4 | الانتقال لمرحلة التصنيع + إذن صرف بالخامات + زر إنهاء التصنيع (2.1–2.3) | ✅ | `WorkOrderService::start/issueMaterials/complete` + `IssueVoucher`. يُعاد استخدامه. |
| 5 | مخزن البضاعة التامة + الاعتماد المزدوج + التسليم (2.4, 2.7) | ✅ | `WarehouseType::FinishedGoods` + `DeliveryVoucher` (تقني+مالي). يُعاد استخدامه. |
| 6 | إدارة المشتريات (1.10) | ✅ | `PurchaseOrder` مربوط بالعملية. يُعاد استخدامه (+ تحميل التكلفة، بند 8). |
| 7 | رفع مقاسات الموقع والرسومات (1.9) | 🟡 | فئات المرفقات موجودة (DRAWING). **يُضاف**: فئة/حقول مقاسات الموقع + مرحلة هندسية اختيارية في ملف العملية. |
| 8 | **العملية = مركز تكلفة لكل المصاريف** (1.15) | 🟡 | اليوم `actual_cost` من إذن الصرف فقط. **يُعمَّم**: `OperationCostService` يجمع كل المكوّنات (خامات + مشتريات + قيود GL مُوسَّمة + تركيب) + ربط الـ GL بالعملية (`project_id` على `journal_entry_lines`). |
| 9 | **المقارنة: التكلفة الفعلية ↔ أمر التشغيل + احتساب المالي** (2.6) | 🟡/❌ | لا تكلفة على WO. **يُضاف**: لقطة `estimated_cost` على `WorkOrder` (من `Bom::totalCost` عند الإنشاء) + تقرير مقارنة (فعلي/تقديري/انحراف/ربحية) على مستوى WO والعملية. |
| 10 | **ترحيل الملف لكل الأقسام عند التنشيط** (1.4) | ❌ | `moveToActive` يقلب الحالة فقط. **يُضاف**: `OperationActivationService` (Event `OperationActivated` + إشعارات للأدوار + إنشاء عناصر افتراضية اختيارية BOM/WO placeholder) + لوحة إشراف عبر الأقسام. |
| 11 | **الإدارة العامة** كطبقة إشراف (1.11) | ❌ | **يُضاف**: مجموعة تنقل `general_management` + لوحة/مورد "ملف العملية" + دور `General_Manager` + Dashboard للعمليات النشطة وحالتها عبر الأقسام. |
| 12 | **حجز الكمية** للعملية (1.6, 1.7) | 🟡 | `holdStock/releaseStock` موجودة وغير مربوطة. **يُضاف**: ربطها بالعملية (`StockReservation` أو reference morph للعملية) + أكشن حجز/تحرير + إطلاق تلقائي عند الصرف. |
| 13 | دراسة الكميات ومراجعة المخزون (1.3) | 🟡 | البيانات موجودة (BOM + Inventory). **يُضاف**: عرض "احتياج العملية مقابل المتاح" (يقرأ BOM للعملية ومخزون الأصناف). |
| 14 | استلام المالي + **ملف أوامر التوريد** + الدفعات النقدية (1.14, 1.15) | ❌ | **يُضاف (مرحلة لاحقة)**: تتبّع الدفعات المستلمة/الأوامر التوريدية للعملية (أو قيود GL مُوسَّمة بالعملية على حساب الخزينة). |
| 15 | مراقبة **التسهيلات** على العملية (1.16) | ❌ | **يُضاف (مرحلة لاحقة)**: متابعة تسهيلات ائتمانية للعميل/العملية. |
| 16 | **محضر التسليم** وتوزيعه على الأقسام (2.8) | ❌ | **يُضاف**: موديل `DeliveryMinute` (مرتبط بالعملية + إذن التسليم) + توليد + توزيع (إشعار/مرفق) + مورد. |
| 17 | **المطالبة المالية** (2.9, 2.10) | ❌ | **يُضاف**: موديل `FinancialClaim` (عملية + عميل + مبلغ + حالة draft/submitted/collected) + ربط مالي اختياري. |
| 18 | مرحلة التركيب وتحميل مصاريفها (2.5) | 🟡/❌ | لا تتبّع تركيب. **يُضاف (مرحلة لاحقة)**: حالة/مرحلة تركيب + تحميل مصاريف التركيب على مركز التكلفة (عبر GL مُوسَّم). |
| 19 | دورة حياة العملية بعد التنشيط (تصنيع→تام→تسليم→إتمام) | 🟡 | حالات الـ enum موجودة (`InProgress/OnHold/Completed`). **يُضاف**: `OperationLifecycleService` يقود الانتقالات ويعكس حالة الأقسام تلقائياً. |

---

## 4. القرارات التصميمية الرئيسية (الافتراض الموصى به مذكور — لن يُسأل عنها، تُعتمد كما هي)

1. **العملية الحالية (`Project`) هي نفسها "ملف العملية" و"مركز التكلفة"** — لا يُنشأ كيان `CostCenter`/`Operation` موازٍ.
   - *موصى به*: نعم. `Project` يربط كل شيء ويُراكم `actual_cost` بالفعل؛ نبني فوقه بدل تكرار.
2. **بُعد مركز التكلفة في المالية على مستوى السطر**: يُضاف `project_id` (nullable) على **`journal_entry_lines`** (وليس الرأس فقط) لأن القيد قد يلمس أكثر من عملية، والتكلفة تُحمَّل لكل سطر. مع تعبئة افتراضية من الرأس في الـ UI لتسهيل الإدخال.
   - *موصى به*: نعم. + ترقية `AccountEntry.operation_name` بإضافة `project_id` (nullable FK) مع الإبقاء على النص للتوافق الخلفي.
3. **مركز التكلفة = طبقة تجميع (View) عبر `OperationCostService`** يقرأ المصادر الموجودة (إذن الصرف عبر WO، المشتريات، إذن الإضافة، التسليم، قيود GL المُوسَّمة) — **لا جدول تجميع مخزَّن**، مصدر حقيقة واحد (نفس فلسفة `GeneralLedgerService`). يبقى `Project.actual_cost` كـ "تكلفة الخامات السريعة" cache، والتجميع الشامل يُحسب لحظياً.
   - *موصى به*: نعم.
4. **التكلفة التقديرية لأمر التشغيل = لقطة مخزَّنة عند الإنشاء**: يُضاف `estimated_cost` على `work_orders` يُملأ من `Bom::getTotalCostAttribute()` لحظة ربط الـ BOM. (اللقطة تثبّت المقارنة حتى لو تغيّرت أسعار الأصناف لاحقاً.)
   - *البديل المرفوض*: احتسابها لحظياً دائماً (تتغيّر بتغيّر أسعار الأصناف فتفسد المقارنة التاريخية).
5. **"ترحيل الملف لكل الأقسام" = حدث + إشعارات + إتاحة، لا إنشاء قسري ثقيل**: عند التنشيط يُطلق `OperationActivated` event يُشعر أدوار الأقسام (فني/مخزون/مشتريات/مصنع/مالية) ويُظهر العملية في "لوحة الإدارة العامة". إنشاء BOM/WO افتراضي = **اختياري عبر علم config** (تفادي الازدواج).
   - *موصى به*: نعم — التزام بنمط `SalesPipelineService` (transitions صريحة، DomainException).
6. **دورة حياة العملية تُدار عبر `OperationLifecycleService` منفصل** (لا حشو `SalesPipelineService`): يقود `InProgress → (تصنيع جارٍ) → Completed` + `OnHold`، ويشتق "مرحلة العملية" من حالة أوامر التشغيل/التسليم بدل عمود إضافي حيثما أمكن.
   - *موصى به*: نعم.
7. **محضر التسليم والمطالبة المالية = موديلات server-authored** (بدون `Syncable`) تُدار من لوحة الأدمن. المحضر يُولَّد من إذن تسليم نشِط؛ المطالبة تُرفع بعد إتمام التوريد/التركيب.
8. **دور جديد `General_Manager`** يملك صلاحيات الإشراف عبر الأقسام (عرض شامل + اعتمادات عليا) — يُضاف لـ `getDefaultRoleDefinitions` فيُنشأ تلقائياً في البيئات الجديدة، ويُمنح يدوياً في القائمة.
9. **مجموعة التنقل `general_management` تُوضع أولاً** (قبل `sales_crm`) كمركز قيادة تنفيذي.

---

## 5. خطة التنفيذ المفصّلة بالمراحل

كل مرحلة **قابلة للشحن والاختبار منفصلة**، وتراعي التسلسل: DB → Model → Enum → Service → Filament → RBAC → i18n → Theme/RTL → Tests → **الربط مع الأجزاء الأخرى**. وبعد كل مرحلة: **مسح الكاش المحلي (القسم 9)**.

---

### المرحلة 0 — البنية التأسيسية: مجموعة التنقل + دور الإدارة العامة + لوحة العمليات (المتطلبات 1.11, 1.4 جزئياً)

**Navigation/i18n**:
- إضافة مفتاح `navigation.groups.general_management` (en: "General Management" / ar: "الإدارة العامة") في `lang/{en,ar}/navigation.php`.
- إدراج `__('navigation.groups.general_management')` **أولاً** في مصفوفة `navigationGroups()` بـ `AdminPanelProvider`.

**Filament Page** `app/Filament/Pages/OperationsOverview.php` (لوحة الإشراف — نمط `TrialBalance`):
- جدول العمليات النشطة (`ProjectStatus::InProgress`) مع أعمدة حالة كل قسم: BOM (نعم/لا/معتمد)، أوامر تشغيل (عدد/حالة)، أوامر شراء، أذونات صرف، تسليم، الميزانية مقابل الفعلي (شريط/نسبة). فلاتر: عميل، تاريخ، حالة.
- `canAccess(): bool => auth()->user()?->can('operations.overview')`.

**RBAC**: `operations.overview`, ودور `General_Manager` (انظر القسم 6).
**i18n**: كتلة `resources.operations_overview.*` + `roles.groups.operations` + `roles.permissions.operations.*` (en+ar).
**Theme/RTL**: ألوان سيمانتية للحالات؛ محاذاة أرقام.
**Tests** `tests/Feature/GeneralManagement/OperationsOverviewTest.php`: الوصول بالصلاحية فقط؛ ظهور العمليات النشطة فقط.

**الربط**: يقرأ `Project` + علاقاته (BOMs/WOs/POs/vouchers). لا يكتب شيئاً (لوحة قراءة).

---

### المرحلة 1 — مركز التكلفة الموحّد (Operation Cost Center) (المتطلبات 1.15, 8، الأهم)

**DB / Migrations**:
- `add_project_id_to_journal_entry_lines`: `project_id` (nullable, FK→projects, `nullOnDelete`) + index `(project_id)`. (بُعد مركز التكلفة في الـ GL.)
- `add_project_id_to_account_entries`: `project_id` (nullable, FK→projects, `nullOnDelete`) + index. (ترقية `operation_name` النصّي.)

**Model**:
- `JournalEntryLine`: إضافة `project_id` لـ `$fillable` + علاقة `project()`.
- `AccountEntry`: إضافة `project_id` + علاقة `project()` (الإبقاء على `operation_name`).
- `Project`: إضافة علاقات قراءة `journalLines()` (hasMany عبر `JournalEntryLine`) و`accountEntries()`.

**Service** `app/Services/OperationCostService.php` (طبقة تجميع — القرار 3):
- `breakdown(Project $project): array` يُرجع مكوّنات التكلفة:
  - **خامات مصروفة** = Σ `issue_vouchers` المرحّلة عبر `workOrders` (أو قراءة `actual_cost` السريعة).
  - **مشتريات** = Σ `purchase_orders.total_amount` (المستلمة) أو سطور الاستلام.
  - **قيود GL مُوسَّمة** = Σ `journal_entry_lines` (مدين) للعملية من قيود posted (مصاريف تشغيل/تركيب/عمومية).
  - **تسليمات (إيراد)** = Σ `delivery_vouchers` النشطة للعملية.
- `totalCost(Project)`, `revenue(Project)`, `profit(Project)` = إيراد − تكلفة، `marginPercent(Project)`.
- محسوب في PHP/SQL محمول (نمط `TrialBalanceService`).

**Filament** — "ملف العملية / Operation Cost File":
- **خيار موصى به**: `RelationManager`s + Infolist داخل `ProjectResource` (تبويب "التكلفة/Cost Center"): بطاقات (الميزانية، الفعلي، الإيراد، الربح، الهامش%) + جدول تفصيل المكوّنات. **أو** صفحة `OperationCostFile` مستقلة باختيار العملية.
- يقرأ من `OperationCostService`. إخفاء الأرقام عمّن لا يملك `operations.view_cost`.

**RBAC**: `operations.view_cost`.
**i18n**: `resources.operations_cost.*` (cost components, budget, actual, revenue, profit, margin).
**Theme/RTL**: بطاقات بألوان سيمانتية (ربح=success/خسارة=danger)؛ أرقام مالية محاذاة RTL.
**Tests** `tests/Feature/GeneralManagement/OperationCostTest.php`: تجميع صحيح لكل مكوّن؛ الربح = إيراد−تكلفة؛ قيود GL المُوسَّمة تدخل التجميع؛ RBAC.

**الربط (مهم)**: يقرأ من المخزون/المشتريات/التسليم/الـ GL. لا يُعدّل تدفقاتها. تعديل `JournalEntryResource` (المرحلة المالية) لإظهار حقل `project_id` على سطور القيد (Select بحث من العمليات النشطة) — تعديل غير كاسر.

---

### المرحلة 2 — مقارنة التكلفة الفعلية بأمر التشغيل + الربحية (المتطلب 2.6, 9)

**DB / Migration** `add_estimated_cost_to_work_orders`: `estimated_cost` (decimal 15,2, default 0) + (اختياري) `actual_material_cost` (decimal 15,2, default 0) — لقطة تُحدَّث عند ترحيل أذون الصرف.

**Model/Service**:
- `WorkOrder`: `estimated_cost` في `$fillable` + cast `decimal:2` + accessor `getCostVarianceAttribute()` و`getActualCostAttribute()` (Σ أذون الصرف المرحّلة).
- عند إنشاء/ربط BOM في `WorkOrderService` (أو موديل): تعبئة `estimated_cost = $bom->total_cost` (لقطة).
- `IssueVoucherService::post()`: بالإضافة لـ `project->increment('actual_cost')` الحالي، تحديث `work_order.actual_material_cost` (للمقارنة على مستوى WO). **تعديل دقيق غير كاسر** — تُراجَع اختبارات `IssueVoucherTest`.

**Filament**: عمود/تبويب في `WorkOrderResource`: التقديري | الفعلي | الانحراف | نسبة الانحراف%. وفي ملف العملية: ملخص فعلي↔تقديري على مستوى العملية (يجمع كل WOs).
**RBAC**: إعادة استخدام `work_orders.view` + `operations.view_cost`.
**i18n**: `resources.work_orders.columns.{estimated_cost,actual_cost,variance}` + كتلة في `operations_cost`.
**Theme/RTL**: انحراف موجب=danger/سالب=success (تجاوز التكلفة أحمر).
**Tests** `tests/Feature/GeneralManagement/CostComparisonTest.php`: اللقطة تُملأ من BOM؛ الفعلي يُراكم من أذون الصرف؛ الانحراف صحيح؛ ثبات اللقطة عند تغيّر أسعار الأصناف؛ **اختبار انحدار** لـ `IssueVoucherTest`.

**الربط**: يلمس `IssueVoucherService` و`WorkOrder` — منطقة حساسة، اختبارات الانحدار إلزامية.

---

### المرحلة 3 — ترحيل العملية لكل الأقسام عند التنشيط + دورة الحياة (المتطلبات 1.4, 19)

**Service** `app/Services/OperationActivationService.php` و`OperationLifecycleService.php`:
- توسيع `SalesPipelineService::moveToActive` (أو استدعاؤه): بعد ضبط `InProgress`، إطلاق `App\Events\OperationActivated($project)`.
- `OperationLifecycleService`: انتقالات `markCompleted()` (عند اكتمال التسليم) / `putOnHold()` / `resume()` مع `DomainException` على الانتقالات غير المسموحة (نمط `assertStatus`).
- اشتقاق "مرحلة العملية" (دراسة/تصنيع/تام/تسليم) من حالات WOs/التسليم بدل عمود جديد حيثما أمكن.

**Listener/Notifications**:
- `NotifyDepartmentsOfActivation` listener: إشعار Filament Database Notifications لأصحاب أدوار الأقسام (فني/مخزون/مشتريات/مصنع/مالية + الإدارة العامة) بأن العملية أصبحت نشطة.
- (اختياري عبر `config/operations.php` علم `auto_seed_bom`/`auto_seed_work_order`): إنشاء BOM مسودة + أمر تشغيل placeholder عند التنشيط.

**Filament**: ظهور العملية النشطة تلقائياً في `OperationsOverview` (المرحلة 0) + شارة إشعار. أكشن "إنهاء العملية" (`markCompleted`) في `ActiveProjectResource`/ملف العملية.

**RBAC**: `operations.activate` (قد تُدمج مع `projects.move_to_active` الموجودة)، `operations.complete`, `operations.hold`.
**i18n**: `resources.operations.actions.{complete,hold,resume}` + رسائل `errors.operations.*` (انتقال غير مسموح).
**Tests** `tests/Feature/GeneralManagement/OperationLifecycleTest.php`: التنشيط يطلق الحدث/الإشعارات؛ انتقالات الحالة الصحيحة وممنوعة؛ علم config للبذر الافتراضي.

**الربط (حسّاس)**: يلمس `SalesPipelineService::moveToActive` — **اختبار انحدار** لـ `tests/Feature/Sales/StateTransitionTest`. الإشعارات تتطلب جدول `notifications` (Laravel) — يُضاف إن لم يكن موجوداً.

---

### المرحلة 4 — حجز المخزون للعملية + دراسة الكميات (المتطلبات 1.6, 1.7, 1.3, 12, 13)

**DB / Model**:
- ربط الحجز بالعملية: إما (أ) موديل `StockReservation` (`project_id, item_id, warehouse_type, quantity, status, created_by`) يستدعي `holdStock/releaseStock`، أو (ب) إضافة `project_id` لمرجع حركة الحجز. **موصى به (أ)** لوضوح التتبّع.
- إطلاق تلقائي عند الصرف: عند ترحيل إذن الصرف، تحرير الحجز المقابل للعملية.

**Service**: توسيع `InventoryService` أو `ReservationService`: `reserveForProject($project, $item, $qty, $warehouse)` و`releaseForProject(...)` تستدعي `holdStock/releaseStock` الموجودتين (التي تتحقق من المتاح).

**Filament**: 
- في ملف العملية: تبويب "دراسة الكميات/مراجعة المخزون" يقرأ BOM المعتمد للعملية مقابل المتاح (`Item::availableIn`) ويعرض النقص.
- أكشن "حجز الكميات للعملية" (يحجز احتياج BOM) + "تحرير".

**RBAC**: إعادة استخدام `inventory.hold` / `inventory.release` الموجودتين + `operations.reserve` للربط بالعملية.
**i18n**: `resources.stock_reservations.*` + كتلة "دراسة الكميات".
**Tests** `tests/Feature/GeneralManagement/StockReservationTest.php`: الحجز يخفض المتاح بلا تغيير المخزون الفعلي؛ التحرير عند الصرف؛ رفض الحجز فوق المتاح؛ ربط الحجز بالعملية الصحيحة.

**الربط**: يبني فوق `holdStock/releaseStock` غير المستخدمتين حالياً + BOM. **اختبار انحدار** لـ `InventoryServiceTest`.

---

### المرحلة 5 — محضر التسليم (Delivery Minute) وتوزيعه (المتطلبات 2.7, 2.8)

**DB / Migration** `create_delivery_minutes_table`: `id, minute_number (string unique — DM-YYYYMM-XXXX), project_id (FK), delivery_voucher_id (nullable FK), customer_id, minute_date, content/notes (text), created_by, distributed_at (nullable), timestamps, softDeletes`.

**Model/Service** `DeliveryMinute` + `DeliveryMinuteService`:
- `generateFromDelivery(DeliveryVoucher)`: يُنشئ محضراً مرتبطاً بالعملية والتسليم.
- `distribute(DeliveryMinute)`: إشعار/مرفق لكل الأقسام (نمط إشعارات المرحلة 3) + ختم `distributed_at`.
- ترقيم تلقائي (نمط `generateVoucherNumber`).

**Filament** `DeliveryMinuteResource` (مجموعة `general_management`): جدول + إنشاء من تسليم + أكشن "توزيع". تصدير PDF لاحقاً (مجلد `PDFs/` موجود).
**RBAC**: `delivery_minutes.view/create/distribute`.
**i18n**: `resources.delivery_minutes.*` + `roles.groups`/`roles.permissions`.
**Theme/RTL**: حالة التوزيع badge سيمانتي.
**Tests** `tests/Feature/GeneralManagement/DeliveryMinuteTest.php`: التوليد من تسليم نشِط؛ الترقيم الفريد؛ التوزيع يُشعر الأقسام ويختم التاريخ؛ RBAC.

**الربط**: يقرأ `DeliveryVoucher` النشِط (المخزون) + `Project`. لا يُعدّل التسليم.

---

### المرحلة 6 — المطالبة المالية (Financial Claim) (المتطلبات 2.9, 2.10)

**DB / Migration** `create_financial_claims_table`: `id, claim_number (string unique — FC-YYYYMM-XXXX), project_id (FK), customer_id (FK), claim_date, amount (decimal 15,2), status (string → ClaimStatus: draft/submitted/collected/cancelled), description, submitted_at, collected_at, created_by, timestamps, softDeletes`.

**Enum** `app/Enums/ClaimStatus.php` (`draft/submitted/collected/cancelled`, `HasLabel`+`HasColor`).

**Model/Service** `FinancialClaim` + `FinancialClaimService`:
- `submit()/collect()` انتقالات حالة؛ شرط: لا تُرفع المطالبة إلا بعد إتمام التوريد/التركيب (تحقق من حالة العملية/التسليم).
- (اختياري) عند التحصيل: توليد قيد GL/AccountEntry مدين للخزينة دائن للعميل (ربط بالمرحلة المالية).

**Filament** `FinancialClaimResource` (مجموعة `general_management` أو `finance`): CRUD + أكشن submit/collect.
**RBAC**: `financial_claims.view/create/submit/collect`.
**i18n**: `resources.financial_claims.*` + `enums.claim_status.*` + `roles.*`.
**Theme/RTL**: حالة badge؛ مبالغ محاذاة.
**Tests** `tests/Feature/GeneralManagement/FinancialClaimTest.php`: منع الرفع قبل إتمام التوريد؛ انتقالات الحالة؛ (اختياري) توليد القيد عند التحصيل؛ RBAC.

**الربط**: يقرأ `Project`/`Customer`/`DeliveryVoucher`. ربط مالي اختياري عبر `JournalEntryService`/`AccountEntry`.

---

### المرحلة 7 — (لاحقة/اختيارية) ملف أوامر التوريد + الدفعات + التسهيلات + التركيب (المتطلبات 1.14, 1.16, 2.5, 18)

> تُنفَّذ بعد استقرار المراحل 0–6 (نطاق مفاهيمي أوسع، يحتاج توضيح محاسبي).
- **ملف أوامر التوريد + الدفعات النقدية المستلمة**: تتبّع المبالغ المستلمة للعملية (الأفضل: قيود GL مُوسَّمة بالعملية على حساب الخزينة + تقرير "مقبوضات العملية").
- **مراقبة التسهيلات (تسهيلات)**: متابعة تسهيلات ائتمانية للعميل/العملية + سقوف.
- **مرحلة التركيب**: حالة/مرحلة تركيب + تحميل مصاريف التركيب على مركز التكلفة عبر GL مُوسَّم (يستفيد من المرحلة 1).

---

### المرحلة 8 — التشطيب: الترتيب + الويدجِت + الترجمة الشاملة
- مراجعة `navigationSort` لموارد `general_management` (لوحة العمليات → ملف العملية → محاضر التسليم → المطالبات).
- (اختياري) Widgets لوحة تحكم: عدد العمليات النشطة، إجمالي الربح/التكلفة، مطالبات غير محصّلة.
- مراجعة شاملة لـ `ar.json`/`resources.php` لمنع أي نص إنجليزي ثابت.

---

## 6. RBAC — التفصيل الكامل

### صلاحيات تُضاف إلى `RoleAndPermissionSeeder::getPermissions()`
```
// General Management — Operations
'operations.overview', 'operations.view_cost', 'operations.activate',
'operations.complete', 'operations.hold', 'operations.reserve',
// Delivery Minutes
'delivery_minutes.view', 'delivery_minutes.create', 'delivery_minutes.distribute',
// Financial Claims
'financial_claims.view', 'financial_claims.create',
'financial_claims.submit', 'financial_claims.collect',
```

### توزيع الأدوار (تعديل `getDefaultRoleDefinitions`)
| الدور | الإضافات |
|---|---|
| **General_Manager** (جديد) | كل صلاحيات `operations.*` + `delivery_minutes.*` + `financial_claims.*` + عرض شامل (projects/work_orders/purchase_orders/inventory/accounts.view, trial_balance.view, dashboard.view). الدور المالك لطبقة الإشراف. |
| **Finance** | `operations.view_cost`, `financial_claims.view/create/submit/collect` (الربط المالي). |
| **Sales_Manager** | `operations.overview`, `operations.activate`, `delivery_minutes.view`. |
| **Factory_Manager** | `operations.overview`, `operations.complete`, `operations.reserve`. |
| **Admin** | الكل تلقائياً عبر `grantAdminAllPermissions()` (لا تعديل). |

> **ملاحظة حرجة (GOTCHA مؤكَّد)**: `ensureInitialRolesExist()` **لا يلمس الأدوار الموجودة**. لذا:
> - الصلاحيات الجديدة لأدوار قائمة (Finance/Sales_Manager/Factory_Manager) **لن تُضاف تلقائياً** في بيئة فيها الأدوار موجودة — تُمنح يدوياً من شاشة الأدوار أو بأمر صريح.
> - الدور الجديد `General_Manager` **سيُنشأ تلقائياً** (غير موجود) بصلاحياته الافتراضية.
> - `Admin` يحصل على كل الجديد تلقائياً.

### Policies (نمط `ItemPolicy`/`JournalEntryPolicy`)
- `DeliveryMinutePolicy`: view→`delivery_minutes.view`، create→`delivery_minutes.create`، distribute (أكشن) عبر `->visible(can('delivery_minutes.distribute'))`.
- `FinancialClaimPolicy`: CRUD قياسي + حُرّاس حالة (`submit`/`collect` عبر visible + شرط الحالة، نمط `isDraft()`).
- `StockReservationPolicy` (إن أُنشئ موديل): view/create→`operations.reserve`.
- لوحة العمليات/ملف التكلفة: حماية `canAccess()`/Infolist بالصلاحيات أعلاه.
- أكشن دورة الحياة (`complete/hold/activate`) عبر `->visible(fn () => auth()->user()?->can('operations.*'))`.

---

## 7. i18n / RTL / Theme — قائمة الالتزام

- **`navigation.php` (en+ar)**: مفتاح `groups.general_management` + تسجيله في `navigationGroups()`.
- **`resources.php` (en+ar)**: كتل `operations_overview`, `operations_cost`, `stock_reservations`, `delivery_minutes`, `financial_claims` (label, plural_label, navigation_label, sections, fields, columns, filters, actions, notifications) + إضافات على `work_orders.columns` (estimated_cost/actual_cost/variance).
- **`enums.*` (en+ar)**: `enums.claim_status.*`. (حالات العملية موجودة في `enums.project_status` — تُعاد استخدامها للحالات غير المستخدمة سابقاً Completed/OnHold بإضافة مفاتيحها إن نقصت.)
- **`errors.php` (en+ar)**: `operations.*` (انتقال غير مسموح، رفع مطالبة قبل الإتمام، حجز فوق المتاح).
- **شاشة الأدوار**: `roles.groups.{operations,delivery_minutes,financial_claims}` + `roles.permissions.<slug>.<action>` لكل صلاحية جديدة، في **اللغتين** (يفرضها نمط `<Domain>I18nTest`).
- **`ar.json`**: أي جمل عامة/ويدجِت جديدة.
- **`__()` إلزامي** في كل label؛ **ممنوع نص ثابت**.
- **enums**: `HasLabel` عبر `__('resources.enums.<x>.'.$value)` + `HasColor` سيمانتي.
- **RTL**: تلقائي؛ التحقق من محاذاة الأرقام في بطاقات/جداول التكلفة والمقارنة.
- **Dark/Light**: ألوان سيمانتية فقط (ربح=success، تجاوز=danger، حالة محايدة=gray)؛ لا ألوان ثابتة في Blade.
- **العملة**: استخدام عملة الحساب/العملية ديناميكياً حيث يلزم (لا تثبيت `->money('EGP')` للأجنبي).

---

## 8. خطة الاختبارات (تتبع نمط `tests/Feature`)

مجلد جديد `tests/Feature/GeneralManagement/`:

| الملف | يغطّي |
|---|---|
| `OperationsOverviewTest.php` | الوصول بالصلاحية؛ العمليات النشطة فقط؛ حالة الأقسام المعروضة. |
| `OperationCostTest.php` | تجميع كل مكوّنات التكلفة؛ الربح=إيراد−تكلفة؛ قيود GL المُوسَّمة؛ RBAC. |
| `CostComparisonTest.php` | لقطة `estimated_cost` من BOM؛ تراكم الفعلي من أذون الصرف؛ الانحراف؛ ثبات اللقطة. |
| `OperationLifecycleTest.php` | التنشيط يطلق الحدث/الإشعارات؛ انتقالات صحيحة/ممنوعة؛ علم البذر. |
| `StockReservationTest.php` | الحجز يخفض المتاح؛ التحرير عند الصرف؛ رفض فوق المتاح؛ الربط بالعملية. |
| `DeliveryMinuteTest.php` | التوليد من تسليم نشِط؛ الترقيم الفريد؛ التوزيع والإشعارات. |
| `FinancialClaimTest.php` | منع الرفع قبل الإتمام؛ انتقالات الحالة؛ الربط المالي الاختياري. |
| `GeneralManagementRbacTest.php` | مصفوفة صلاحيات الإدارة العامة لكل دور (نمط `FinanceRbacTest`). |
| `GeneralManagementI18nTest.php` | مفاتيح ar/en لكل مورد/enum/مجموعة + `roles.groups`/`roles.permissions` (نمط `FinanceI18nTest`). |

**Factories جديدة**: `DeliveryMinuteFactory`, `FinancialClaimFactory`, (اختياري) `StockReservationFactory`. + توسيع `WorkOrderFactory` بـ `estimated_cost`.

**اختبارات انحدار إلزامية** (لأن المراحل 1–4 تلمس خدمات قائمة): `IssueVoucherTest`, `Sales/StateTransitionTest`, `InventoryServiceTest` يجب أن تبقى خضراء.

**ملاحظات تقنية**:
- `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` في `setUp`.
- الترقيم (`DM-`/`FC-`) يعتمد `Cache::lock`؛ على `CACHE_STORE=array` تأكّد من سلوك القفل.
- اختبارات التكلفة تُنشئ سلسلة كاملة (Project→BOM→WO→IssueVoucher posted→DeliveryVoucher active→JournalEntry posted مُوسَّم) للتحقق من التجميع.

---

## 9. ⭐ ضبط الكاش المحلي لرؤية التعديلات (محلي — مختلف عن البرودكشن)

> **مهم جداً (طلب صريح متكرّر من المستخدم)**: بعد كل مرحلة تنفيذ، شغّل التسلسل التالي محلياً. **لا تستخدم** `config:cache`/`route:cache`/`view:cache` محلياً (للبرودكشن — تُخفي التعديلات).

```powershell
# 1) الميجريشن + بذر الصلاحيات
php artisan migrate
php artisan db:seed --class=RoleAndPermissionSeeder

# 2) مسح كل الكاشات (الأهم محلياً)
php artisan optimize:clear            # config + route + view + event + compiled
php artisan filament:clear-cached-components
php artisan icons:clear
php artisan permission:cache-reset    # كاش صلاحيات Spatie

# 3) إعادة بناء أصول الواجهة
npm run build                         # أو: npm run dev (watch أثناء التطوير)

# 4) لو في Queue worker شغّال (مهم — المرحلة 3 تستخدم إشعارات/أحداث)
php artisan queue:restart

# 5) كاش المتصفح: محلياً Hard reload (Ctrl+F5) لو ظهرت واجهة قديمة.
```

أمر مختصر (copy/paste):
```powershell
php artisan migrate; php artisan db:seed --class=RoleAndPermissionSeeder; php artisan optimize:clear; php artisan filament:clear-cached-components; php artisan icons:clear; php artisan permission:cache-reset; npm run build; php artisan queue:restart
```

> بما أن أدوار **Finance/Sales_Manager/Factory_Manager موجودة مسبقاً**: بعد إضافة صلاحيات الإدارة العامة، امنحها لها من شاشة الأدوار (Admin والدور الجديد `General_Manager` يأخذانها تلقائياً)، ثم `php artisan permission:cache-reset`.

---

## 10. المخاطر والاعتبارات (الربط مع الأجزاء الأخرى)

1. **ازدواج التكلفة (الأخطر)**: مركز التكلفة يجمع من عدة مصادر (إذن صرف + مشتريات + GL مُوسَّم). لو سُجِّل نفس المصروف في GL **و** عبر إذن صرف → ازدواج. الحل: سياسة واضحة لما يُحمَّل من أين، وعدم احتساب نفس المكوّن مرتين في `OperationCostService` (المشتريات vs الخامات المصروفة قد تتداخل — يُحسم: الخامات المصروفة هي التكلفة الفعلية، المشتريات مرجعية).
2. **لمس خدمات قائمة (حسّاس)**: المراحل 1–4 تعدّل `IssueVoucherService` و`SalesPipelineService` و`InventoryService` — اختبارات الانحدار إلزامية قبل أي شحن.
3. **دور Finance القائم وأخواته**: `ensureInitialRolesExist` لا يحدّث الأدوار الموجودة → الصلاحيات الجديدة تُمنح يدوياً (موثّق القسم 9).
4. **`Project.actual_cost` (cache مزدوج)**: يبقى يُراكم من إذن الصرف للسرعة، لكن `OperationCostService` هو مصدر الحقيقة الشامل — توثيق العلاقة لتفادي التضارب.
5. **الإشعارات/الأحداث**: المرحلة 3 تحتاج جدول `notifications` (Laravel) + قد تعمل عبر Queue → `queue:restart` ضروري محلياً.
6. **Sync/Offline-first**: موديلات الإدارة العامة الجديدة **server-authored** (بدون `Syncable`) — تُدار من لوحة الأدمن لا Operator Console.
7. **Activity Log**: الموديلات الجديدة (`DeliveryMinute`, `FinancialClaim`, `StockReservation`) تطبّق `LogsActivity` بنمط الموجود.
8. **الأداء**: مركز التكلفة محسوب كـ View عبر مصادر متعددة؛ مع النمو تُضاف فهارس (`journal_entry_lines.project_id`, `account_entries.project_id` — مذكورة) وإمكانية تجميع SQL.
9. **التركيب والتسهيلات (نطاق مفاهيمي)**: المرحلة 7 تحتاج توضيحاً محاسبياً قبل التنفيذ — تُؤجَّل عمداً.

---

## 11. ملخص التصنيف النهائي

- **موجود ومطابق (✅) — يُعاد استخدامه**: العملية (`Project`) ودورتها حتى `InProgress`؛ TENDER/IN HAND؛ أمر التشغيل (`WorkOrder`) ودورة التصنيع + زر الإنهاء؛ إذن الصرف/الإضافة/التسليم؛ مخزن التام + الاعتماد المزدوج؛ المشتريات؛ الـ GL؛ بنية RBAC/i18n/Theme/Tests؛ خط Cairo/RTL/dark-light؛ حالات الـ enum غير المستخدمة (Completed/OnHold).
- **موجود لكن يحتاج تعميم/تعديل (🟡)**: `actual_cost` (مصدر واحد → مركز تكلفة شامل)؛ الـ GL (إضافة بُعد `project_id`)؛ `WorkOrder` (إضافة تكلفة تقديرية للمقارنة)؛ الحجز (`holdStock` موجود غير مربوط → ربطه بالعملية)؛ المرفقات (رسومات موجودة → + مقاسات الموقع).
- **غير موجود (❌ — بناء جديد)**: مجموعة/دور الإدارة العامة + لوحة الإشراف؛ ترحيل العملية لكل الأقسام عند التنشيط + الإشعارات؛ مركز التكلفة الموحّد (`OperationCostService`) + ربط GL؛ مقارنة الفعلي↔التقديري + الربحية؛ حجز المخزون للعملية؛ **محضر التسليم**؛ **المطالبة المالية**؛ ملف أوامر التوريد/الدفعات/التسهيلات (لاحقة).

> **الترتيب الموصى به للتنفيذ**: 0 (البنية+اللوحة) → 1 (مركز التكلفة) → 2 (المقارنة) → 3 (التنشيط ودورة الحياة) → 4 (الحجز) → 5 (محضر التسليم) → 6 (المطالبة المالية) → 7 (التوريد/التسهيلات/التركيب، لاحقة) → 8 (التشطيب). كل مرحلة مع **اختباراتها + ترجمتها + صلاحياتها + مسح الكاش** قبل الانتقال للتالية.
