# خطة تنفيذ متطلبات "قسم المخازن" (Inventory Department Work Flow)

> وثيقة تحليل وتخطيط فقط — **لا يوجد تنفيذ كود في هذه المرحلة**.
> المرجع: ملف المتطلبات `Inventory_Department.md` (12 سلايد) + تحليل المشروع الفعلي (Laravel 12 + Filament 3.3).

---

## 0. منهجية التحليل

تم فك ترميز ملف المتطلبات (كان UTF-8 معروض كـ mojibake) وقراءته سلايد بسلايد، ثم قراءة الكود الفعلي للمشروع في الطبقات التالية:
- **Domain**: `Item`, `Inventory`, `InventoryTransaction`, `WorkOrder`, `Bom`, `BomItem`, `PurchaseOrder`, `Project`.
- **Services**: `InventoryService`, `WorkOrderService`, `PurchaseOrderService`, `ProcessWorkOrderMaterialsJob`.
- **Enums**: `TransactionType`, `ItemType`, `WorkOrderStatus`, `PurchaseOrderStatus`, `BomStatus`, `UnitOfMeasure`.
- **RBAC**: `RoleAndPermissionSeeder`, `*Policy`, `AppServiceProvider`.
- **i18n / Theme**: `lang/en|ar/*`, `lang/ar.json`, `SetLocale`, `AdminPanelProvider` (Filament language-switch + dark mode + RTL).
- **Tests**: `tests/Feature/Sales/*`, `phpunit.xml`.

التحليل القديم في `system_gap_analysis.md` **يؤكد نفس النتائج** (نقص موديول المالية، نظام الأذون، اعتماد التسليم المزدوج، كشوف حسابات الموردين/العملاء).

---

## 1. تفكيك المتطلبات (ماذا يطلب الملف بالضبط)

| السلايد | المتطلب |
|---|---|
| 2 | الصنف موجود في "مركز صنف" وله **سعر + مخزن + تكلفة**؛ السعر يدخل في قيمة المخزن. |
| 3 | شاشات/أزرار: أمر تشغيل، **مخزن الخامات**، المشتريات، **مخزن تحت التشغيل**، **المنتج التام**، **العملاء**، **إذن إضافة**، **إذن صرف**، **زر إجراء**، **إذن تسليم**، الإدارة المالية، طلب تصنيع، **عمل مقارنة واستخراج الفاقد**. |
| 4 | **إذن إضافة (رؤية المخزن)**: المشتريات → مخزن الخامات. حقول: التاريخ، رقم الفاتورة، بيان الأصناف الواردة، العملية، تأكيد "استلمت الكمية الخزنية". |
| 5 | **إذن إضافة (رؤية المالية)**: + قيمة الفاتورة، السعر يدخل في قيمة المخزن، **ترحيل لحساب المورد** (اسم المورد + قيمة الإذن). |
| 6 | **كشف حساب المورد** بشكلين: (1) بالفواتير الإلكترونية، (2) بأذون الإضافة. |
| 7 | **إذن صرف (رؤية المخزن)**: بداية تصنيع → نقل الخامات من مخزن الخامات إلى **مخزن تحت التشغيل**. حقول: التاريخ، **طلب تصنيع رقم**، العملية، التوقيع. |
| 8 | **إذن صرف (رؤية المالية)**: تحميل قيمة إذن الصرف على **العملية (مركز التكلفة)**. |
| 9 | **زر إجراء (إتمام التصنيع)**: نقل البضاعة من تحت التشغيل → **المنتجات التامة**، و**المقارنة بين الكمية الفعلية المنتجة وأمر التشغيل واستخراج الفاقد**. |
| 10 | **إذن تسليم (رؤية المخزن)**: المنتجات التامة → العميل. حقول: التاريخ، طلب التوريد رقم، اسم العميل، العملية، **عدد الأطباق**، **درجة الحماية**، **جهد العزل** + **توقيع الإدارة المالية + توقيع الإدارة الفنية**. لا يصبح الإذن "نشط ويؤثر على الحسابات" إلا بعد **الاعتماد المزدوج**. |
| 11 | **إذن تسليم (رؤية المالية)**: **ترحيل قيمة إذن التسليم على حساب العميل** باسم العملية. |
| 12 | **كشف حساب العميل** بشكلين: (1) بالفواتير الإلكترونية، (2) بأذون التسليم. |

**الخلاصة المفاهيمية**: النظام المطلوب دورة مستندية محكمة:
`مشتريات → (إذن إضافة) → مخزن خامات → (إذن صرف) → تحت التشغيل → (إجراء + فاقد) → منتجات تامة → (إذن تسليم باعتماد مزدوج) → العميل`، مع **ترحيل مالي** لكل إذن (مورد / مركز تكلفة العملية / عميل) و**كشوف حسابات**.

---

## 2. الوضع الحالي في المشروع (الحقائق الفعلية من الكود)

### 2.1 المخزون (Inventory)
- `items` table: `name, sku, type, unit, unit_cost, description, minimum_stock` — الصنف له تكلفة ونوع ووحدة. ✅
- `app/Enums/ItemType.php`: `raw_material, finished_good, semi_finished, consumable`. ✅
- `inventories` table: **`item_id` فريد (unique)** + `warehouse_type` (default `raw_materials`) + `on_hand_quantity` + `on_hold_quantity`.
  - **القيد الحاسم**: صف مخزون **واحد لكل صنف**، و`warehouse_type` **مشتق من نوع الصنف** (في `InventoryService::getOrCreateInventoryWithLock`: `finished_good→finished_goods`, `semi_finished→work_in_progress`, الباقي `raw_materials`).
  - أي: **لا يوجد رصيد لكل (صنف + مخزن)**، ولا **تحويل بين المخازن**، ولا حركة فعلية للصنف من مخزن لآخر. المخازن مجرد تصنيف ثابت.
- `inventory_transactions` table: `item_id, type, quantity, reference (morph), notes, performed_by`.
  - `app/Enums/TransactionType.php`: **`in, out, hold, release` فقط**. لا يوجد تمييز بين إذن إضافة/صرف/تحويل/تسليم.
  - **لا يوجد `unit_cost` على الحركة** → لا يوجد تقييم/طبقات تكلفة لحظية.
- `InventoryService`: `addStock / deductStock / holdStock / releaseStock` فقط. لا `transfer`، لا أذون مستندية، لا تقييم.
- `InventoryTransactionResource`: عرض فقط (`canCreate=false`)، مجموعة تنقل `warehouse`.

### 2.2 المشتريات (Procurement)
- `purchase_orders`: `supplier_name, supplier_contact` كـ **نصوص فقط** — **لا يوجد كيان Supplier**.
- `PurchaseOrderService::receiveItems`: يحدّث `received_quantity` + `addStock` (حركة `in`) ويربطها بالـ PO. → هذا **أقرب شيء لإذن الإضافة لكنه ليس مستنداً** (لا رقم/قيمة فاتورة، لا اعتماد، لا ترحيل لمورد).
- `PurchaseOrderStatus`: `draft, submitted, partially_received, received, cancelled`.

### 2.3 التصنيع (Work Orders)
- `work_orders`: يحتوي بالفعل `wo_number, planned_quantity, produced_quantity, waste_quantity, qa_approved_by/at, qa_notes, actual_start/end_date, project_id, bom_id`.
- `WorkOrderService`: `start, issueMaterials (→ Job يخصم BOM), submitForQa(produced, waste), approveQa, complete`.
  - `issueMaterials` يخصم خامات الـ BOM (حركة `out`) ويربطها بالـ WO → **أقرب شيء لإذن الصرف لكنه ليس مستنداً** (لا توقيع، لا نقل إلى مخزن "تحت التشغيل"، لا تحميل تكلفة على العملية).
  - **`complete()` لا يضيف منتجاً تاماً للمخزون** ولا ينفّذ نقل WIP→FG؛ يغيّر الحالة فقط.
  - الفاقد: `waste_quantity` يُلتقط في `submitForQa`؛ توجد خصائص محسوبة `getWastePercentageAttribute / getEfficiencyAttribute`. → **التقاط بيانات فقط، بدون "إجراء" مستندي ينقل للمخزن التام أو يستخرج الفاقد رسمياً**.
- `WorkOrderObserver`: مجرد إبطال cache للوحة التحكم.

### 2.4 المشاريع/العملاء
- `projects`: `client_name` نص فقط — **لا يوجد كيان Customer**، ولا حساب عميل، ولا كشف حساب.
- يوجد حقول فنية على المشروع (`electric_current, model, section_type, poles_count`) لكنها على مستوى المشروع لا على إذن التسليم.

### 2.5 RBAC (المرجع للنمط)
- الصلاحيات بصيغة منقّطة `resource.action`، **المصدر الوحيد** هو `RoleAndPermissionSeeder::getPermissions()` (آمن للتشغيل في كل deploy: يضيف الجديد ويحذف اليتيم ويمنح Admin كل الصلاحيات).
- Policies تربط الدالة بالصلاحية: `viewAny → can('items.view')` ... إلخ.
- الأدوار الحالية: `Admin, Sales, Sales_Manager, Technical_Office, Procurement, Factory_Manager, Warehouse_Manager`.
  - **لا يوجد دور Finance / إدارة مالية** رغم أن الملف يعتمد عليه بشدة (ترحيل + توقيع مالي).
  - مبدأ مطبّق بالفعل: `inventory.view_pricing` مستثنى من `Warehouse_Manager` (إخفاء الأسعار عن أمين المخزن) — نفس مبدأ الملف.

### 2.6 i18n / RTL / Theme
- لغتان `en, ar` عبر `bezhansalleh/filament-language-switch` (مُهيأ في `AppServiceProvider::boot`).
- مفاتيح الترجمة منظمة: `lang/{en,ar}/navigation.php` (مجموعات التنقل)، `lang/{en,ar}/resources.php` (تسميات الموارد + الحقول + الأعمدة + الأقسام + `enums`)، `lang/ar.json` (جمل عامة).
- مجموعات التنقل المعرّفة في `AdminPanelProvider`: `sales_crm, technical_office, warehouse, procurement, manufacturing, system`.
- **RTL تلقائي** من Filament حسب اتجاه اللغة (ar = rtl)، خط `Cairo`.
- **Dark/Light**: Filament يدعمهما افتراضياً (غير معطّل) → الموارد الجديدة ترث الوضعين تلقائياً ما دامت تستخدم `__()` ولا تكسر التباين بألوان ثابتة.

### 2.7 الاختبارات
- `phpunit.xml`: sqlite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.
- النمط (`tests/Feature/Sales/RbacTest.php`): `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` + `User::factory()` + `assignRole()` + `assertTrue/False($user->can(...))`.
- **لا توجد حالياً اختبارات** لـ `InventoryService / WorkOrderService / PurchaseOrderService` — فجوة يجب سدّها مع الميزات الجديدة.

### 2.8 Sync (offline-first)
- موديلات كثيرة تستخدم `Syncable` + `SyncScopeRegistry`. أي موديل جديد يُراد مزامنته أوفلاين يحتاج تسجيل scope في `SyncServiceProvider` + `config/sync.php`. (سنبقي الأذون الجديدة **server-authored أولاً** لتقليل المخاطرة، مع توثيق خطوات المزامنة كخيار لاحق.)

---

## 3. جدول المقارنة النهائي (متطلب → الحالة → الإجراء)

الرموز: ✅ موجود ومطابق — 🟡 موجود لكن يحتاج تعديل/إضافة — ❌ غير موجود (جديد).

| # | المتطلب في الملف | الحالة | التفصيل والإجراء المطلوب |
|---|---|---|---|
| 1 | الصنف له سعر/تكلفة/وحدة/نوع | ✅ | `Item` كامل. لا تغيير جوهري. |
| 2 | تقسيم 3 مخازن (خامات/تحت التشغيل/تام) كأرصدة حقيقية | 🟡 | يوجد `warehouse_type` كعمود مشتق من النوع + `item_id` فريد. يحتاج تحويل إلى **رصيد لكل (صنف+مخزن)** لدعم الحركة الفعلية والتحويل. |
| 3 | تحويل بين المخازن | ❌ | لا يوجد `transfer`. يضاف لـ `InventoryService` + نوع حركة `transfer`. |
| 4 | كيان المورد (Supplier) | ❌ | يوجد `supplier_name` نص فقط. يُنشأ موديل `Supplier`. |
| 5 | كيان العميل (Customer) | ❌ | يوجد `client_name` نص فقط. يُنشأ موديل `Customer` (وربط `Project.customer_id`, `DeliveryVoucher.customer_id`). |
| 6 | إذن إضافة (مستند: تاريخ/رقم فاتورة/بيان/تأكيد استلام) | 🟡 | يوجد منطق استلام PO يضيف مخزون. يُرفع إلى **مستند `AdditionVoucher`** برقم/تاريخ/أصناف/تأكيد، مولّد من استلام PO. |
| 7 | إذن إضافة (رؤية مالية: قيمة فاتورة + ترحيل لحساب المورد) | ❌ | لا ترحيل ولا قيمة. يضاف **قيد حساب مورد** عند اعتماد/ترحيل الإذن. |
| 8 | كشف حساب المورد (بالأذون) | ❌ | يضاف **AccountStatement** (دفتر أستاذ للمورد) + شاشة كشف. الشكل بالفواتير الإلكترونية → خارج النطاق/مؤجّل. |
| 9 | إذن صرف (مستند: تاريخ/طلب تصنيع رقم/توقيع) ينقل خامات → تحت التشغيل | 🟡 | يوجد خصم BOM. يُرفع إلى **مستند `IssueVoucher`** يربط بالـ WorkOrder وينفّذ **تحويل raw→WIP** فعلي + توقيع. |
| 10 | إذن صرف (رؤية مالية: تحميل القيمة على العملية/مركز التكلفة) | 🟡 | `Project.actual_cost` موجود لكنه غير مدفوع بالأذون. يُحدّث **تكلفة العملية** بقيمة إذن الصرف. |
| 11 | زر إجراء: نقل WIP→تام + مقارنة الكمية الفعلية بأمر التشغيل واستخراج الفاقد | 🟡 | `produced/waste_quantity` ملتقطة لكن `complete()` لا ينتج مخزوناً تاماً. يضاف **إجراء إنتاج (Production Entry)**: استهلاك WIP + إنتاج صنف تام في مخزن التام + احتساب/تسجيل **الفاقد**. |
| 12 | استخراج الفاقد كتقرير/مستند | 🟡 | يوجد فقط خصائص محسوبة. يضاف **تقرير/سجل فاقد** (مقارنة فعلي مقابل BOM/الخطة). |
| 13 | إذن تسليم (مستند بحقول فنية: عدد الأطباق/درجة الحماية/جهد العزل/طلب توريد/عميل) | ❌ | غير موجود إطلاقاً. يُنشأ موديل `DeliveryVoucher`. |
| 14 | اعتماد مزدوج لإذن التسليم (مالي + فني) قبل التأثير على الحسابات والمخزون | ❌ | غير موجود. يضاف **gate اعتماد مزدوج** يفعّل الخصم من التام + الترحيل. |
| 15 | إذن تسليم (رؤية مالية: ترحيل القيمة لحساب العميل) | ❌ | يضاف **قيد حساب عميل** عند تفعيل الإذن. |
| 16 | كشف حساب العميل (بالأذون) | ❌ | يضاف كشف العميل من قيود الأذون. |
| 17 | إخفاء الأسعار عن أمين المخزن | ✅ | مطبّق عبر `inventory.view_pricing`. نلتزم بنفس النمط في الأذون. |
| 18 | طلب تصنيع رقم (مرجع لإذن الصرف) | ✅/🟡 | = `WorkOrder.wo_number` موجود. نربط إذن الصرف به. |

---

## 4. القرارات التصميمية الرئيسية (يجب اعتمادها قبل البدء)

> هذه نقاط تحتاج قرارك. الافتراض الموصى به مذكور.

1. **نموذج المخازن**: تحويل `inventories` (صف/صنف) إلى **أرصدة لكل (صنف + مخزن)** عبر جدول `inventory_balances`.
   - *موصى به*: نعم — لأن الملف يصف نقلاً فعلياً للصنف بين المخازن (raw→WIP→FG). مع migration لترحيل بيانات `inventories` الحالية.
   - *البديل الأبسط*: الإبقاء على ربط النوع بالمخزن وجعل WIP "افتراضياً" مرتبطاً بأمر التشغيل فقط (أقل مطابقة للملف).
2. **نطاق المالية**: بناء **كشوف حسابات موردين/عملاء مدفوعة بالأذون فقط** (الشكل الثاني في السلايدات 6 و12)، **وليس** شجرة حسابات/GL كاملة. الفاتورة الإلكترونية (الشكل الأول) **خارج النطاق** (تعتمد على منصة خارجية) وتُوثّق كمرحلة لاحقة.
3. **مركز التكلفة** = `Project` (العملية). قيمة إذن الصرف تُجمّع في تكلفة المشروع.
4. **دور مالي جديد** `Finance` للتوقيع/الترحيل المالي؛ والتوقيع الفني عبر `Technical_Office`/`Factory_Manager`.
5. **التقييم (Valuation)**: إضافة `unit_cost` على حركة المخزون لاحتساب قيمة الأذون (نبدأ بتكلفة الصنف الثابتة `Item.unit_cost`؛ FIFO/المتوسط المرجّح خارج النطاق الآن).

---

## 5. خطة التنفيذ المفصّلة بالمراحل

كل مرحلة **قابلة للشحن والاختبار منفصلة**، وتُراعي: DB → Model → Enum → Service → Filament Resource → RBAC → i18n → Theme/RTL → Tests → الربط مع الأجزاء الأخرى.

### المرحلة 0 — الأساس: المخازن متعددة الأرصدة + أنواع الحركات + التقييم
**DB / Migrations**
- `inventory_balances` جديد: `id, item_id, warehouse (enum string), on_hand_quantity, on_hold_quantity, timestamps` + **unique(item_id, warehouse)**.
- migration ترحيل: نسخ كل صف من `inventories` إلى `inventory_balances` بمخزنه المشتق الحالي. (الإبقاء على `inventories` مؤقتاً للتوافق ثم إزالته في migration لاحق، أو إعادة تسميته.)
- `inventory_transactions`: إضافة `unit_cost` (decimal 12,2 nullable), `warehouse_from` (nullable), `warehouse_to` (nullable), `voucher_type`+`voucher_id` (nullable morph) لربط الحركة بالمستند.
**Enum**
- توسيع `TransactionType`: إضافة `Transfer`. (الإبقاء على in/out/hold/release.) أو إضافة enum مستقل `WarehouseType: raw_materials, work_in_progress, finished_goods` (موصى به لاستعماله في كل الأذون).
**Service (`InventoryService`)**
- جعل الدوال **warehouse-aware**: `addStock/deductStock/holdStock/releaseStock($item, $qty, $warehouse=null, ...)` مع افتراض مخزن الصنف الأصلي للتوافق الخلفي.
- دالة جديدة `transferStock($item, $qty, $from, $to, $reference, $unitCost)` تنفّذ خصماً من `from` وإضافة إلى `to` بحركتين مرتبطتين، داخل نفس قفل Redis + DB transaction.
- تمرير `unit_cost` (افتراضي `item->unit_cost`) لكل حركة.
**الربط**: `Item::available_quantity` و`inventory()` يتغيّران لقراءة الرصيد من `inventory_balances` (مع الحفاظ على الواجهة العامة). `ItemResource` يعرض الأرصدة لكل مخزن. `InventoryTransactionResource` يعرض `unit_cost`/`warehouse` (مع `view_pricing`).
**RBAC**: `inventory.transfer`.
**Tests**: `tests/Feature/Inventory/InventoryServiceTest.php` — add/deduct/hold/release/**transfer**، منع الرصيد السالب، صحة القفل، احتساب القيمة.

### المرحلة 1 — كيانات المورد والعميل
**DB**: `suppliers` (`name, contact, phone, email, tax_number, notes, timestamps, softDeletes`)، `customers` (مثلها + ربط بالمشروع). إضافة `supplier_id` (nullable, FK) إلى `purchase_orders` (مع إبقاء `supplier_name` للتوافق/الترحيل). إضافة `customer_id` (nullable) إلى `projects`.
**Models**: `Supplier`, `Customer` (+ `LogsActivity`، علاقات: `purchaseOrders`, `accountEntries`). ترحيل بيانات: إنشاء موردين/عملاء من القيم النصية الحالية (اختياري عبر command).
**Filament**: `SupplierResource` (مجموعة `procurement`), `CustomerResource` (مجموعة `sales_crm` أو جديدة). نماذج + جداول + بحث.
**RBAC**: `suppliers.view/create/edit/delete`, `customers.view/create/edit/delete`.
**i18n**: مفاتيح `resources.suppliers.*` و`resources.customers.*` (en+ar) + `navigation`.
**Tests**: RBAC + CRUD + ربط PO/Project.

### المرحلة 2 — إذن الإضافة (Addition Voucher / GRN) — سلايدات 4،5،6
**DB**: `addition_vouchers` (`id, voucher_number, supplier_id, purchase_order_id (nullable), invoice_number, invoice_value (decimal), voucher_date, status (draft/posted), notes, received_by, posted_by, posted_at, timestamps`). `addition_voucher_lines` (`addition_voucher_id, item_id, quantity, unit_cost`).
**Enum**: `VoucherStatus: draft, posted` (مشترك للأذون) + `getLabel/getColor`.
**Model + Service (`AdditionVoucherService`)**:
- إنشاء الإذن (من استلام PO أو يدوياً) → عند **post**: لكل سطر `InventoryService::addStock(item, qty, warehouse=raw_materials, unit_cost)` + إنشاء **قيد حساب مورد** (دائن) بقيمة الفاتورة + تحديث حالة PO (إعادة استخدام منطق `PurchaseOrderService::updatePurchaseOrderStatus`).
- رقم الإذن: نمط `AV-YYYYMM-XXXX` (نسخ نمط `generatePoNumber` بقفل Redis).
**Filament `AdditionVoucherResource`** (مجموعة `warehouse`):
- نموذج: مورد، PO، رقم/قيمة الفاتورة (القيمة **مخفية عن أمين المخزن** عبر `inventory.view_pricing`)، التاريخ، Repeater للأصناف، تأكيد "استلمت الكمية".
- Action **Post** (صلاحية `addition_vouchers.post`) ينفّذ الترحيل.
**الربط**: استلام PO (`PurchaseOrderResource` receive action) يولّد إذن إضافة بدل الاستدعاء المباشر لـ addStock؛ يظهر في كشف المورد.
**RBAC**: `addition_vouchers.view/create/post`.
**i18n + Tests**: مفاتيح + اختبارات (post يضيف للمخزون + يرحّل للمورد؛ الأمين لا يرى القيمة؛ منع post مزدوج).

### المرحلة 3 — إذن الصرف (Issue Voucher) + تحويل raw→WIP — سلايدات 7،8
**DB**: `issue_vouchers` (`id, voucher_number, work_order_id, voucher_date, status, total_value, issued_by, signed_by, signed_at, timestamps`). `issue_voucher_lines` (`issue_voucher_id, item_id, quantity, unit_cost`).
**Model + Service (`IssueVoucherService`)**:
- يُولَّد من `WorkOrderService::issueMaterials` (بدلاً من الخصم المباشر): لكل بند BOM → سطر إذن صرف.
- عند **post/sign**: `transferStock(item, qty, from=raw_materials, to=work_in_progress, reference=workOrder, unit_cost)` + تحميل `total_value` على **تكلفة العملية** (`Project.actual_cost += total_value`).
- يربط بـ `طلب تصنيع رقم` = `work_order.wo_number`.
- تعديل `ProcessWorkOrderMaterialsJob` ليُنشئ/يرحّل إذن الصرف بدل `deductStock` المباشر.
**Filament `IssueVoucherResource`** (مجموعة `warehouse` أو `manufacturing`): عرض + توقيع (`work_orders` أو صلاحية مخصصة).
**RBAC**: `issue_vouchers.view/create/post`.
**الربط**: WorkOrder ↔ IssueVoucher؛ Project.actual_cost؛ أرصدة WIP.
**i18n + Tests**: اختبار التحويل raw→WIP، تحميل التكلفة على العملية، نقص الخامات.

### المرحلة 4 — إجراء الإنتاج (WIP→تام) + استخراج الفاقد — سلايد 9
**DB**: `production_entries` (`id, work_order_id, entry_number, produced_quantity, scrap_quantity, voucher_date, status, performed_by, timestamps`) — أو إعادة استخدام حقول WO الحالية مع جدول فاقد.
**Service (`WorkOrderService::complete` upgrade)**:
- عند الإتمام (بعد QA gate الموجود): 
  1. `addStock(finishedItem, produced_quantity, warehouse=finished_goods, reference=workOrder)` — إدخال المنتج التام.
  2. خصم/تصفية أرصدة WIP المرتبطة بالـ WO.
  3. **المقارنة**: `expected = BOM/planned`, `actual = produced`, `scrap/الفاقد = issued_to_wip - consumed_in_product` → تسجيل سجل فاقد.
- نحتاج تحديد **الصنف التام الناتج** عن WO (إضافة `output_item_id` إلى `work_orders` — حالياً غير موجود؛ ربط بالـ project/BOM).
**Filament**: Action "إجراء الإتمام" على `WorkOrderResource` + شاشة/Widget **تحليل الفاقد** (مقارنة فعلي مقابل مخطط).
**RBAC**: `work_orders.complete` (موجود) + `scrap.view`.
**الربط**: المخزون التام، أمر التشغيل، الفاقد، لوحة التحكم (`StatsOverview`).
**Tests**: إتمام WO ينتج مخزوناً تاماً + يحسب الفاقد + يحترم بوابة QA.

### المرحلة 5 — إذن التسليم + الاعتماد المزدوج — سلايدات 10،11،12
**DB**: `delivery_vouchers` (`id, voucher_number, customer_id, project_id, supply_order_number, voucher_date, plates_count, protection_degree, insulation_voltage, status (draft/pending/active/cancelled), technical_approved_by, technical_approved_at, financial_approved_by, financial_approved_at, total_value, created_by, timestamps`). `delivery_voucher_lines` (`delivery_voucher_id, item_id, quantity, unit_cost`).
**Enum**: `DeliveryVoucherStatus: draft, pending_approval, active, cancelled`.
**Service (`DeliveryVoucherService`)**:
- اعتماد فني + اعتماد مالي → **gate**: لا يصبح `active` إلا بوجود **كلا التوقيعين**. عند التفعيل:
  1. `deductStock(item, qty, warehouse=finished_goods, reference=deliveryVoucher)`.
  2. **قيد حساب عميل** (مدين) بقيمة الإذن باسم العملية.
**Filament `DeliveryVoucherResource`** (مجموعة جديدة مثلاً `delivery`/`logistics` أو `warehouse`):
- نموذج بالحقول الفنية (عدد الأطباق/درجة الحماية/جهد العزل/طلب التوريد/العميل/العملية).
- Action **اعتماد فني** (صلاحية `delivery_vouchers.approve_technical`) و**اعتماد مالي** (`delivery_vouchers.approve_financial`) — كل منهما يسجّل توقيعه؛ الاكتمال يفعّل الإذن.
**RBAC**: `delivery_vouchers.view/create/approve_technical/approve_financial/cancel`.
**الربط**: العميل، المشروع، مخزون التام، كشف العميل.
**Tests**: لا خصم/ترحيل قبل الاعتماد المزدوج؛ كل توقيع على حدة؛ التفعيل يخصم ويرحّل؛ منع التفعيل بنقص مخزون.

### المرحلة 6 — كشوف الحسابات (موردين/عملاء) — سلايدات 6،12
**DB**: `account_entries` (`id, party_type+party_id (morph: Supplier/Customer), entry_date, direction (debit/credit), amount, reference_type+reference_id (morph: AdditionVoucher/DeliveryVoucher), operation_name (project), notes, timestamps`).
**Service (`AccountStatementService`)**: توليد كشف لطرف مع **رصيد متحرك (running balance)**.
**Filament**: صفحات كشف حساب (Filament Page / Infolist) داخل `SupplierResource`/`CustomerResource` أو موارد مستقلة `SupplierStatement`/`CustomerStatement`. تصفية بالتاريخ + تصدير PDF (يوجد مجلد `PDFs/` ونمط مرفقات).
**RBAC**: `supplier_statements.view`, `customer_statements.view` (أو `view_pricing` gating).
**ملاحظة الشكل الأول (الفواتير الإلكترونية)**: خارج النطاق — يوثّق كنقطة تكامل مستقبلية (منصة ETA).
**Tests**: قيود الأذون تظهر في الكشف + صحة الرصيد المتحرك.

### المرحلة 7 — التشطيب: لوحة التحكم + التنقل + الترجمة + التوثيق
- تحديث `StatsOverview` / widgets: أرصدة المخازن الثلاثة، الأذون المعلّقة، تحليل الفاقد.
- مجموعات تنقل جديدة عند اللزوم (`finance`) في `AdminPanelProvider` + `navigation.php` (en+ar).
- مراجعة شاملة لمفاتيح `ar.json`/`resources.php` لضمان عدم وجود نص إنجليزي ثابت.

---

## 6. RBAC — التفصيل الكامل

### صلاحيات تُضاف إلى `RoleAndPermissionSeeder::getPermissions()`
```
// Warehouses / vouchers
'inventory.transfer',
'addition_vouchers.view', 'addition_vouchers.create', 'addition_vouchers.post',
'issue_vouchers.view',    'issue_vouchers.create',    'issue_vouchers.post',
'delivery_vouchers.view', 'delivery_vouchers.create',
'delivery_vouchers.approve_technical', 'delivery_vouchers.approve_financial', 'delivery_vouchers.cancel',
'production_entries.view','production_entries.create',
'scrap.view',
// Parties + statements
'suppliers.view','suppliers.create','suppliers.edit','suppliers.delete',
'customers.view','customers.create','customers.edit','customers.delete',
'supplier_statements.view','customer_statements.view',
```

### الأدوار
| الدور | الإضافات |
|---|---|
| **Warehouse_Manager** | `inventory.transfer`, `addition_vouchers.view/create`, `issue_vouchers.view/create`, `delivery_vouchers.view/create`, `production_entries.view/create`, `scrap.view` — **بدون** `*.post`/`approve_financial`/`view_pricing`. |
| **Procurement** | `suppliers.*`, `addition_vouchers.view/post`, `supplier_statements.view`. |
| **Factory_Manager** | `issue_vouchers.post`, `production_entries.create`, `delivery_vouchers.approve_technical`, `scrap.view`. |
| **Technical_Office** | `delivery_vouchers.approve_technical` (بديل/إضافي). |
| **Finance (جديد)** | `addition_vouchers.post`, `delivery_vouchers.approve_financial`, `inventory.view_pricing`, `supplier_statements.view`, `customer_statements.view`, `customers.view`. |
| **Sales / Sales_Manager** | `customers.view` (+ create للمدير). |
| **Admin** | الكل تلقائياً عبر `grantAdminAllPermissions()`. |

> **Policies**: لكل موديل جديد Policy على نمط `ItemPolicy` (method → `can('resource.action')`)، وتسجَّل تلقائياً عبر Laravel auto-discovery (الموديل `App\Models\X` → `App\Policies\XPolicy`). الموارد ذات الأفعال المخصصة (post/approve) تستخدم `->visible(fn () => auth()->user()?->can(...))` على الـ Actions.

---

## 7. i18n / RTL / Theme — قائمة الالتزام

لكل مورد/enum جديد:
- إضافة كتلة في `lang/en/resources.php` و`lang/ar/resources.php`: `label, plural_label, navigation_label, sections, fields, columns, actions` + قسم `enums.<enum_name>`.
- إضافة مجموعات التنقل الجديدة في `lang/en/navigation.php` و`lang/ar/navigation.php` + تسجيلها في `AdminPanelProvider::navigationGroups()`.
- كل `->label()` و`getNavigationGroup()` و`getLabel()` تستخدم `__()` — **ممنوع نص ثابت**.
- enums الجديدة تطبّق `HasLabel` بـ `__('resources.enums.<x>.'.$this->value)` و`HasColor` (نفس نمط `TransactionType`).
- **RTL**: تلقائي؛ التحقق فقط من المحاذاة في الجداول/الـ Repeaters بالعربية.
- **Dark/Light**: لا ألوان مكتوبة يدوياً في Blade؛ الاعتماد على ألوان Filament السيمانتية (`primary/success/danger/warning/info/gray`) كما في الـ enums الحالية حتى يعمل الوضعان.
- إضافة الجمل العامة الجديدة (تأكيدات، رسائل) إلى `lang/ar.json`.

---

## 8. خطة الاختبارات (تتبع نمط `tests/Feature/Sales`)

| الملف | يغطّي |
|---|---|
| `tests/Feature/Inventory/InventoryServiceTest.php` | add/deduct/hold/release/transfer، منع السالب، أرصدة لكل مخزن، التقييم. |
| `tests/Feature/Inventory/AdditionVoucherTest.php` | post يضيف مخزون raw + يرحّل للمورد؛ منع post مزدوج؛ إخفاء القيمة عن الأمين. |
| `tests/Feature/Inventory/IssueVoucherTest.php` | تحويل raw→WIP؛ تحميل التكلفة على العملية؛ ربط wo_number؛ نقص خامات. |
| `tests/Feature/Inventory/ProductionEntryTest.php` | الإتمام ينتج تاماً + يحسب الفاقد + بوابة QA. |
| `tests/Feature/Inventory/DeliveryVoucherApprovalTest.php` | لا أثر قبل الاعتماد المزدوج؛ توقيع فني/مالي؛ التفعيل يخصم تام ويرحّل للعميل. |
| `tests/Feature/Inventory/AccountStatementTest.php` | ظهور قيود الأذون في كشوف المورد/العميل + الرصيد المتحرك. |
| `tests/Feature/Inventory/InventoryRbacTest.php` | مصفوفة الصلاحيات لكل دور (نمط `RbacTest`). |
| `tests/Feature/Inventory/I18nInventoryTest.php` | وجود مفاتيح ar لكل مورد/enum جديد. |

ملاحظات تقنية:
- `RefreshDatabase` + `seed(RoleAndPermissionSeeder)` في `setUp`.
- البيئة `CACHE_STORE=array` و`QUEUE_CONNECTION=sync` → `Cache::lock` و`ProcessWorkOrderMaterialsJob` يعملان تزامنياً داخل الاختبار (يجب التحقق من سلوك القفل على array-store؛ إن لزم، حقن خدمة قفل no-op في بيئة الاختبار).
- إضافة Factories: `SupplierFactory, CustomerFactory, AdditionVoucherFactory, IssueVoucherFactory, DeliveryVoucherFactory` (نمط Factories الحالية).

---

## 9. ⭐ ضبط الكاش المحلي لرؤية التعديلات (محلي — مختلف عن `commands.txt` الخاص بالبرودكشن)

> **مهم**: لا تستخدم أوامر `config:cache`/`route:cache`/`view:cache` محلياً — هي للبرودكشن وتُخفي التعديلات المحلية. التسلسل المحلي:

```powershell
# 1) شغّل الميجريشن والـ seeder (للصلاحيات الجديدة)
php artisan migrate
php artisan db:seed --class=RoleAndPermissionSeeder

# 2) امسح كل الكاشات (الأهم محلياً)
php artisan optimize:clear        # config + route + view + event + compiled
php artisan filament:clear-cached-components
php artisan icons:clear
php artisan permission:cache-reset  # كاش صلاحيات Spatie (يُعاد ضبطه بالـ seeder أيضاً)

# 3) أعد بناء أصول الواجهة (Tailwind/Filament theme)
npm run build      # أو: npm run dev  (watch مستمر أثناء التطوير)

# 4) لو في Queue worker شغّال (للـ Jobs مثل إصدار الخامات)
php artisan queue:restart

# 5) كاش المتصفح / Service Worker:
#    محلياً APP_ENV=local → طبقة الـ resilience معطّلة وتلغّي تسجيل أي SW قديم تلقائياً.
#    لو ظهرت واجهة قديمة: Hard reload (Ctrl+F5) أو امسح كاش الموقع من DevTools.
```

أمر مختصر محلي (copy/paste):
```powershell
php artisan migrate; php artisan db:seed --class=RoleAndPermissionSeeder; php artisan optimize:clear; php artisan filament:clear-cached-components; php artisan icons:clear; php artisan permission:cache-reset; npm run build; php artisan queue:restart
```

---

## 10. المخاطر والاعتبارات (الربط مع الأجزاء الأخرى)

1. **migration `inventories` → `inventory_balances`**: أخطر خطوة. يجب ترحيل البيانات الحالية بأمان، وتحديث كل قارئ للرصيد (`Item::available_quantity`, `ItemResource`, `StatsOverview`, `InventoryTransactionResource`, أي تقرير) دفعة واحدة لتفادي الكسر.
2. **Sync/Offline-first**: الموديلات الجديدة افتراضياً **server-authored** (بدون `Syncable`). لو طُلب إنشاء أذون أوفلاين من الـ Operator Console → تسجيل scopes في `SyncServiceProvider` + `config/sync.php` + إضافة resolvers (نمط `WorkOrderStateMachineResolver`). يوثّق كمرحلة لاحقة.
3. **Activity Log**: كل موديل جديد يطبّق `LogsActivity` بنفس نمط الموجود (logOnly + dontSubmitEmptyLogs).
4. **التقييم المالي**: البدء بتكلفة الصنف الثابتة. FIFO/متوسط مرجّح وفروق التقييم = خارج النطاق الحالي (يوثّق).
5. **الفاتورة الإلكترونية (ETA)**: الشكل الأول لكشوف الحسابات يعتمد منصة خارجية = خارج النطاق، نقطة تكامل مستقبلية.
6. **الترقيم المتسلسل للأذون**: إعادة استخدام نمط `generatePoNumber/generateWoNumber` (قفل Redis + MAX(CAST)) لكل نوع إذن لتفادي تعارض الأرقام.
7. **عدم وجود اختبارات حالية للخدمات**: المرحلة 0 تسد الفجوة قبل البناء فوقها.

---

## 11. ملخص التصنيف النهائي

- **موجود ومطابق (✅)**: كيان الصنف وتكلفته؛ مبدأ إخفاء الأسعار؛ رقم أمر التشغيل (طلب تصنيع)؛ بوابة QA؛ التقاط produced/waste.
- **موجود ويحتاج تعديل/رفع (🟡)**: نموذج المخازن (إلى أرصدة لكل صنف+مخزن)؛ استلام PO → إذن إضافة مستندي؛ صرف BOM → إذن صرف + تحويل WIP؛ إتمام WO → إنتاج تام + فاقد؛ تكلفة العملية مدفوعة بالأذون.
- **غير موجود (❌ — بناء جديد)**: كيانا المورد والعميل؛ إذن التسليم بالحقول الفنية؛ الاعتماد المزدوج (فني+مالي)؛ الترحيل المالي وكشوف الحسابات؛ دور Finance؛ التحويل بين المخازن؛ تقرير الفاقد المستندي.

> **الترتيب الموصى به للتنفيذ**: 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 (كل مرحلة مع اختباراتها وترجمتها وصلاحياتها قبل الانتقال للتالية).
